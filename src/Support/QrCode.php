<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Support;

final class QrCode
{
    /** ECC-L single-block spec per version: [data codewords, ecc codewords]. */
    private const SPEC = [
        1 => [19, 7],
        2 => [34, 10],
        3 => [55, 15],
        4 => [80, 20],
        5 => [108, 26],
    ];

    /** Single alignment-pattern centre per version (v1 has none). */
    private const ALIGN = [1 => null, 2 => 18, 3 => 22, 4 => 26, 5 => 30];

    /** @var int[] */
    private array $expTable = [];
    /** @var int[] */
    private array $logTable = [];

    private int $version;
    private int $size;
    /** @var array<int,array<int,int>> 0/1 module matrix; -1 = unset. */
    private array $m = [];
    /** @var array<int,array<int,bool>> true where a function pattern sits (immutable). */
    private array $reserved = [];

    public function __construct()
    {
        $this->initGaloisField();
    }

    /**
     * Render $data as a terminal string using half-block characters (two module
     * rows per text line) plus a 4-module quiet zone.
     */
    public function render(string $data, bool $invert = false): string
    {
        $matrix = $this->matrix($data);
        $n = count($matrix);
        $quiet = 4;

        // Pad with a quiet zone on all sides.
        $grid = [];
        for ($y = -$quiet; $y < $n + $quiet; $y++) {
            $row = [];
            for ($x = -$quiet; $x < $n + $quiet; $x++) {
                $row[] = ($y >= 0 && $y < $n && $x >= 0 && $x < $n) ? $matrix[$y][$x] : 0;
            }
            $grid[] = $row;
        }

        $dark = $invert ? 0 : 1; // dark module = "on"
        $out = '';
        $rows = count($grid);
        for ($y = 0; $y < $rows; $y += 2) {
            for ($x = 0, $w = count($grid[$y]); $x < $w; $x++) {
                $top = $grid[$y][$x] === $dark;
                $bottom = isset($grid[$y + 1]) && $grid[$y + 1][$x] === $dark;

                $out .= match (true) {
                    $top && $bottom => '█',
                    $top && !$bottom => '▀',
                    !$top && $bottom => '▄',
                    default => ' ',
                };
            }
            $out .= "\n";
        }

        return $out;
    }

    /**
     * Build the final 0/1 module matrix for $data.
     *
     * @return array<int,array<int,int>>
     */
    public function matrix(string $data): array
    {
        $this->version = $this->pickVersion($data);
        $this->size = 17 + 4 * $this->version;

        [$dataCw, $eccCw] = self::SPEC[$this->version];
        $codewords = $this->buildCodewords($data, $dataCw, $eccCw);

        $this->initMatrix();
        $this->placeFunctionPatterns();
        $this->placeData($codewords);

        // Choose the mask with the lowest penalty.
        $best = null;
        $bestScore = PHP_INT_MAX;
        $bestMask = 0;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $this->applyMask($mask);
            $this->writeFormat($candidate, $mask);
            $score = $this->penalty($candidate);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
                $bestMask = $mask;
            }
        }

        $this->writeFormat($best, $bestMask);

        return $best;
    }

    private function pickVersion(string $data): int
    {
        $len = strlen($data);
        foreach (self::SPEC as $v => [$dataCw]) {
            // overhead: 4-bit mode + 8-bit count + 4-bit terminator ≈ 2 codewords.
            if ($len <= $dataCw - 2) {
                return $v;
            }
        }

        throw new \InvalidArgumentException(
            "QR payload too large ({$len} bytes) for version 5 / ECC-L. Shorten the login URL."
        );
    }

    /**
     * @return int[] data codewords followed by ECC codewords
     */
    private function buildCodewords(string $data, int $dataCw, int $eccCw): array
    {
        $bits = '';
        $bits .= '0100'; // byte mode
        $bits .= str_pad(decbin(strlen($data)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = $dataCw * 8;

        // Terminator (up to 4 zero bits).
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));

        // Pad to a byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        // Pad bytes 0xEC / 0x11 alternating.
        $pads = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $pads[$i % 2];
            $i++;
        }

        $dataBytes = [];
        foreach (str_split($bits, 8) as $byte) {
            $dataBytes[] = bindec($byte);
        }

        $ecc = $this->reedSolomon($dataBytes, $eccCw);

        return array_merge($dataBytes, $ecc);
    }

    /**
     * @param int[] $data
     * @return int[]
     */
    private function reedSolomon(array $data, int $eccCw): array
    {
        $gen = $this->generatorPoly($eccCw);
        $res = array_merge($data, array_fill(0, $eccCw, 0));

        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $coef = $res[$i];
            if ($coef === 0) {
                continue;
            }
            $logCoef = $this->logTable[$coef];
            for ($j = 0, $g = count($gen); $j < $g; $j++) {
                $res[$i + $j] ^= $this->expTable[($this->logTable[$gen[$j]] + $logCoef) % 255];
            }
        }

        return array_slice($res, count($data), $eccCw);
    }

    /**
     * @return int[]
     */
    private function generatorPoly(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= $this->gfMul($coef, $this->expTable[$i]);
            }
            $poly = $next;
        }

        return $poly;
    }

    private function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $this->expTable[($this->logTable[$a] + $this->logTable[$b]) % 255];
    }

    private function initGaloisField(): void
    {
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->expTable[$i] = $x;
            $this->logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d; // primitive polynomial
            }
        }
        // Convenience: allow exp index up to 255.
        $this->expTable[255] = $this->expTable[0];
    }

    private function initMatrix(): void
    {
        $this->m = [];
        $this->reserved = [];
        for ($y = 0; $y < $this->size; $y++) {
            $this->m[$y] = array_fill(0, $this->size, -1);
            $this->reserved[$y] = array_fill(0, $this->size, false);
        }
    }

    private function placeFunctionPatterns(): void
    {
        // Three finder patterns + separators.
        foreach ([[0, 0], [$this->size - 7, 0], [0, $this->size - 7]] as [$fx, $fy]) {
            $this->placeFinder($fx, $fy);
        }

        // Timing patterns.
        for ($i = 8; $i < $this->size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            $this->setFunction(6, $i, $bit);
            $this->setFunction($i, 6, $bit);
        }

        // Alignment pattern (single, for v2–5).
        $centre = self::ALIGN[$this->version];
        if ($centre !== null) {
            $this->placeAlignment($centre, $centre);
        }

        // Dark module.
        $this->setFunction(8, 4 * $this->version + 9, 1);

        // Reserve format-info areas (written later, but must not take data bits).
        $this->reserveFormatAreas();
    }

    private function placeFinder(int $ox, int $oy): void
    {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $ox + $dx;
                $y = $oy + $dy;
                if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
                    continue;
                }
                $inRing = ($dx >= 0 && $dx <= 6 && ($dy === 0 || $dy === 6))
                    || ($dy >= 0 && $dy <= 6 && ($dx === 0 || $dx === 6));
                $inCore = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;
                $this->setFunction($x, $y, ($inRing || $inCore) ? 1 : 0);
            }
        }
    }

    private function placeAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $ring = max(abs($dx), abs($dy));
                $this->setFunction($cx + $dx, $cy + $dy, ($ring === 1) ? 0 : 1);
            }
        }
    }

    private function reserveFormatAreas(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->reserve(8, $i);
            $this->reserve($i, 8);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->reserve($this->size - 1 - $i, 8);
            $this->reserve(8, $this->size - 1 - $i);
        }
    }

    /**
     * @param int[] $codewords
     */
    private function placeData(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        $len = strlen($bits);
        $idx = 0;
        $up = true;

        for ($col = $this->size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // skip the vertical timing column
            }

            for ($row = 0; $row < $this->size; $row++) {
                $y = $up ? $this->size - 1 - $row : $row;
                foreach ([0, 1] as $c) {
                    $x = $col - $c;
                    if ($this->reserved[$y][$x] || $this->m[$y][$x] !== -1) {
                        continue;
                    }
                    $this->m[$y][$x] = ($idx < $len) ? (int) $bits[$idx] : 0;
                    $idx++;
                }
            }
            $up = !$up;
        }
    }

    /**
     * @return array<int,array<int,int>>
     */
    private function applyMask(int $mask): array
    {
        $out = $this->m;
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x] || $this->m[$y][$x] === -1) {
                    continue;
                }
                if ($this->maskBit($mask, $x, $y)) {
                    $out[$y][$x] ^= 1;
                }
            }
        }

        return $out;
    }

    private function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            7 => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            default => false,
        };
    }

    /**
     * Write the 15-bit BCH format information (ECC-L + mask) into $matrix.
     *
     * @param array<int,array<int,int>> $matrix
     */
    private function writeFormat(array &$matrix, int $mask): void
    {
        // ECC level L = 0b01; 5 data bits = (level << 3) | mask.
        $data = (0b01 << 3) | $mask;
        $bch = $data;
        for ($i = 0; $i < 10; $i++) {
            $bch = ($bch << 1);
        }
        $rem = $bch;
        for ($i = 14; $i >= 10; $i--) {
            if ($rem & (1 << $i)) {
                $rem ^= 0b10100110111 << ($i - 10);
            }
        }
        $format = (($data << 10) | ($rem & 0x3FF)) ^ 0b101010000010010;

        // 15 bits, LSB first: bit i is placed at the i-th position in the
        // placement sequences below (ISO/IEC 18004; matches nayuki reference).
        $bits = [];
        for ($i = 0; $i < 15; $i++) {
            $bits[] = ($format >> $i) & 1;
        }

        $n = $this->size;
        // Around the top-left finder.
        $posA = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        foreach ($posA as $i => [$x, $y]) {
            $matrix[$y][$x] = $bits[$i];
        }

        // Split copy near the other two finders.
        $posB = [
            [$n - 1, 8], [$n - 2, 8], [$n - 3, 8], [$n - 4, 8], [$n - 5, 8],
            [$n - 6, 8], [$n - 7, 8], [$n - 8, 8],
            [8, $n - 7], [8, $n - 6], [8, $n - 5], [8, $n - 4], [8, $n - 3],
            [8, $n - 2], [8, $n - 1],
        ];
        foreach ($posB as $i => [$x, $y]) {
            $matrix[$y][$x] = $bits[$i];
        }
    }

    /**
     * @param array<int,array<int,int>> $matrix
     */
    private function penalty(array $matrix): int
    {
        $n = $this->size;
        $score = 0;

        // Rule 1: runs of 5+ same-colour modules (rows and columns).
        foreach ([true, false] as $rows) {
            for ($a = 0; $a < $n; $a++) {
                $run = 1;
                $prev = -1;
                for ($b = 0; $b < $n; $b++) {
                    $val = $rows ? $matrix[$a][$b] : $matrix[$b][$a];
                    if ($val === $prev) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                        $prev = $val;
                    }
                }
            }
        }

        // Rule 2: 2x2 blocks of the same colour.
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $v = $matrix[$y][$x];
                if ($v === $matrix[$y][$x + 1] && $v === $matrix[$y + 1][$x] && $v === $matrix[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3: finder-like 1:1:3:1:1 patterns.
        $pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pat2 = array_reverse($pat1);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n - 10; $x++) {
                $seqRow = [];
                $seqCol = [];
                for ($k = 0; $k < 11; $k++) {
                    $seqRow[] = $matrix[$y][$x + $k];
                    $seqCol[] = $matrix[$x + $k][$y];
                }
                if ($seqRow === $pat1 || $seqRow === $pat2) {
                    $score += 40;
                }
                if ($seqCol === $pat1 || $seqCol === $pat2) {
                    $score += 40;
                }
            }
        }

        // Rule 4: dark-module ratio deviation from 50%.
        $dark = 0;
        foreach ($matrix as $row) {
            $dark += array_sum($row);
        }
        $total = $n * $n;
        $ratio = ($dark * 100) / $total;
        $score += (int) ((abs($ratio - 50) / 5)) * 10;

        return $score;
    }

    private function setFunction(int $x, int $y, int $bit): void
    {
        $this->m[$y][$x] = $bit;
        $this->reserved[$y][$x] = true;
    }

    private function reserve(int $x, int $y): void
    {
        $this->reserved[$y][$x] = true;
        if ($this->m[$y][$x] === -1) {
            $this->m[$y][$x] = 0;
        }
    }
}
