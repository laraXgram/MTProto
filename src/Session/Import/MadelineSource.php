<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

final class MadelineSource extends AbstractSource
{
    /** Candidate filenames inside a `*.madeline` session folder, best first. */
    private const FOLDER_CANDIDATES = ['safe.php', 'session.madeline', 'lightState.php'];

    private ?int $preferredDc = null;

    public function name(): string
    {
        return 'madeline';
    }

    /**
     * Disambiguate multi-DC sessions (from the `--dc` option).
     */
    public function preferDc(?int $dc): self
    {
        $this->preferredDc = $dc;

        return $this;
    }

    public function supports(string $input): bool
    {
        if (str_ends_with(rtrim($input, '/'), '.madeline')) {
            return true;
        }

        if (@is_dir($input)) {
            foreach (self::FOLDER_CANDIDATES as $candidate) {
                if (is_file(rtrim($input, '/') . '/' . $candidate)) {
                    return true;
                }
            }
        }

        return $this->isFile($input) && str_contains($this->fileMagic($input, 64), 'halt_compiler');
    }

    public function parse(string $input): ForeignSession
    {
        $file = $this->resolvePayloadFile($input);
        $payload = $this->readPayload($file);
        $graph = $this->deserialize($payload);

        [$dcId, $authKey] = $this->extractHomeKey($graph);

        if ($authKey === null) {
            throw new \RuntimeException(
                'Could not locate a permanent auth key in the MadelineProto session. '
                . 'This usually means an unsupported MadelineProto version or an encrypted session. '
                . 'Export a Telethon/Pyrogram string from the account instead.'
            );
        }

        if ($dcId === null) {
            throw new \RuntimeException(
                'Found a MadelineProto auth key but could not determine its datacenter. '
                . 'Re-run with --dc=<id> (the account\'s home DC, usually 1-5).'
            );
        }

        return new ForeignSession(
            dcId: $dcId,
            authKey: $authKey,
            testMode: false,
            source: $this->name(),
        );
    }

    private function resolvePayloadFile(string $input): string
    {
        if ($this->isFile($input)) {
            return $input;
        }

        if (@is_dir($input)) {
            $dir = rtrim($input, '/');
            foreach (self::FOLDER_CANDIDATES as $candidate) {
                $path = $dir . '/' . $candidate;
                if (is_file($path)) {
                    return $path;
                }
            }

            // Fall back to the largest file in the folder.
            $largest = null;
            $largestSize = -1;
            foreach ((array) glob($dir . '/*') as $path) {
                if (is_file($path) && filesize($path) > $largestSize) {
                    $largest = $path;
                    $largestSize = filesize($path);
                }
            }

            if ($largest !== null) {
                return $largest;
            }
        }

        throw new \RuntimeException("No MadelineProto session payload found at: {$input}");
    }

    /**
     * Read the file and strip the `__halt_compiler()` PHP guard, returning the
     * raw serialized bytes.
     */
    private function readPayload(string $file): string
    {
        $data = (string) @file_get_contents($file);
        if ($data === '') {
            throw new \RuntimeException("MadelineProto session file is empty or unreadable: {$file}");
        }

        $marker = strpos($data, '__halt_compiler();');
        if ($marker !== false) {
            $data = substr($data, $marker + strlen('__halt_compiler();'));
            // Skip an optional closing tag / whitespace before the payload.
            $data = ltrim($data, " \t\n\r\0");
            if (str_starts_with($data, '?>')) {
                $data = ltrim(substr($data, 2), " \t\n\r\0");
            }
        }

        return $data;
    }

    /**
     * Deserialize the payload, tolerating both native PHP serialize and
     * igbinary, without instantiating foreign classes.
     *
     * @return mixed
     */
    private function deserialize(string $payload)
    {
        // igbinary payloads start with a 4-byte header: 0x00 0x00 0x00 <version>.
        $isIgbinary = strlen($payload) >= 4
            && $payload[0] === "\x00" && $payload[1] === "\x00" && $payload[2] === "\x00";

        if ($isIgbinary) {
            if (!function_exists('igbinary_unserialize')) {
                throw new \RuntimeException(
                    'This MadelineProto session is igbinary-encoded but the igbinary extension is not installed. '
                    . 'Install ext-igbinary, or export a Telethon/Pyrogram string instead.'
                );
            }

            $graph = @igbinary_unserialize($payload);
            if ($graph === null) {
                throw new \RuntimeException('Failed to igbinary-unserialize the MadelineProto session.');
            }

            return $graph;
        }

        $graph = @unserialize($payload, ['allowed_classes' => false]);
        if ($graph === false && $payload !== 'b:0;') {
            throw new \RuntimeException(
                'Failed to unserialize the MadelineProto session payload (unsupported format or corrupt file).'
            );
        }

        return $graph;
    }

    /**
     * Walk the object graph and return [homeDcId, authKey].
     *
     * @return array{0:?int,1:?string}
     */
    private function extractHomeKey(mixed $graph): array
    {
        $keysByDc = [];   // dc => 256-byte auth key
        $looseKeys = [];  // auth keys found with no adjacent dc context
        $currentDc = $this->preferredDc;

        $this->walk($graph, $keysByDc, $looseKeys, $currentDc, 0);

        // 1. Explicit / detected home DC with a matching key.
        if ($currentDc !== null && isset($keysByDc[$currentDc])) {
            return [$currentDc, $keysByDc[$currentDc]];
        }

        // 2. Exactly one DC has a permanent key.
        if (count($keysByDc) === 1) {
            $dc = array_key_first($keysByDc);
            return [$dc, $keysByDc[$dc]];
        }

        // 3. Preferred DC given but only loose keys were found.
        if ($this->preferredDc !== null && count($looseKeys) === 1) {
            return [$this->preferredDc, $looseKeys[0]];
        }

        // 4. Multiple DCs, no home hint → cannot disambiguate here.
        if (count($keysByDc) > 1) {
            return [null, $keysByDc[array_key_first($keysByDc)]];
        }

        // 5. Only loose keys and no dc - surface a key so the caller can prompt for --dc.
        return [$this->preferredDc, $looseKeys[0] ?? null];
    }

    /**
     * Recursive graph walk. Correlates DataCenterConnection objects (which carry
     * both a `datacenter` id and a permanent auth key) and harvests any stray
     * 256-byte strings as loose candidates.
     *
     * @param array<int,string> $keysByDc
     * @param list<string>      $looseKeys
     */
    private function walk(mixed $node, array &$keysByDc, array &$looseKeys, ?int &$currentDc, int $depth): void
    {
        if ($depth > 40) {
            return; // Guard against pathological / cyclic graphs.
        }

        if (is_string($node)) {
            if (strlen($node) === 256) {
                $looseKeys[] = $node;
            }
            return;
        }

        if (!is_array($node) && !is_object($node)) {
            return;
        }

        $entries = $this->toEntries($node);

        // Detect a DataCenterConnection-like record: a datacenter id sitting
        // alongside a permanent auth key in the same object.
        $localDc = null;
        foreach ($entries as $key => $value) {
            $k = strtolower((string) $key);
            if (($k === 'datacenter' || $k === 'dc' || $k === 'dc_id' || $k === 'curdc'
                    || $k === 'authorized_dc' || $k === 'main_dc_id')
                && (is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-'))))
            ) {
                $dc = (int) preg_replace('/\D.*$/', '', (string) $value); // "2_media" → 2
                if ($dc >= 1 && $dc <= 10) {
                    $localDc = $dc;
                    if (($k === 'curdc' || $k === 'authorized_dc' || $k === 'main_dc_id') && $currentDc === null) {
                        $currentDc = $dc;
                    }
                }
            }
        }

        if ($localDc !== null) {
            $permKey = $this->findPermKey($entries);
            if ($permKey !== null && !isset($keysByDc[$localDc])) {
                $keysByDc[$localDc] = $permKey;
            }
        }

        foreach ($entries as $value) {
            $this->walk($value, $keysByDc, $looseKeys, $currentDc, $depth + 1);
        }
    }

    /**
     * Look for a permanent 256-byte auth key directly under an object's
     * (possibly nested one level) auth-key property.
     *
     * @param array<string,mixed> $entries
     */
    private function findPermKey(array $entries): ?string
    {
        foreach ($entries as $key => $value) {
            $k = strtolower((string) $key);

            if (is_string($value) && strlen($value) === 256
                && (str_contains($k, 'authkey') || str_contains($k, 'auth_key') || $k === 'key')
            ) {
                return $value;
            }

            // MadelineProto wraps the key in a PermAuthKey object: recurse one
            // level into anything that mentions "perm" or "authkey".
            if ((is_object($value) || is_array($value))
                && (str_contains($k, 'perm') || str_contains($k, 'authkey'))
            ) {
                foreach ($this->toEntries($value) as $ik => $iv) {
                    if (is_string($iv) && strlen($iv) === 256
                        && (str_contains(strtolower($ik), 'authkey')
                            || str_contains(strtolower($ik), 'auth_key')
                            || strtolower($ik) === 'key')
                    ) {
                        return $iv;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Normalise an array or (incomplete) object to a demangled key => value map.
     *
     * @return array<string,mixed>
     */
    private function toEntries(mixed $node): array
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) {
                $out[(string) $k] = $v;
            }
            return $out;
        }

        $out = [];
        foreach ((array) $node as $mangled => $value) {
            $out[$this->demangle((string) $mangled)] = $value;
        }

        return $out;
    }

    /**
     * Strip the "\0Class\0" / "\0*\0" visibility prefix from a cast object key.
     */
    private function demangle(string $key): string
    {
        $pos = strrpos($key, "\0");

        return $pos === false ? $key : substr($key, $pos + 1);
    }
}
