<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Exceptions\TransportException;

/**
 * @see https://core.telegram.org/mtproto/mtproto-transports#transport-obfuscation
 */
final class FakeTlsConnection implements ConnectionInterface
{
    /** TLS record content types. */
    private const REC_CHANGE_CIPHER = "\x14";
    private const REC_HANDSHAKE = "\x16";
    private const REC_APPLICATION = "\x17";

    /** Max TLS record payload. */
    private const MAX_RECORD = 0x4000;

    /** tdlib caps the SNI domain so the fixed-length hello can absorb it. */
    private const MAX_DOMAIN_LENGTH = 182;

    /** Application-data already unwrapped from records, awaiting receive(). */
    private string $appBuffer = '';

    /** The 32-byte client digest, kept to verify the server's response. */
    private string $helloRand = '';

    public function __construct(
        private ConnectionInterface $inner,
        private CryptoInterface     $crypto,
        private string              $secret,
        private string              $domain,
    )
    {
        if (strlen($this->secret) !== 16) {
            throw new TransportException('FakeTLS secret must be 16 bytes.');
        }
        if ($this->domain === '') {
            throw new TransportException('FakeTLS requires an SNI domain.');
        }

    }

    public function connect(string $address, int $port, float $timeout = 10.0): bool
    {
        if (!$this->inner->connect($address, $port, $timeout)) {
            return false;
        }

        $this->sendHello();
        $this->readHelloResponse($timeout);

        // A real TLS 1.3 client emits a ChangeCipherSpec record right after the
        // server flight (middlebox-compatibility mode, RFC 8446 appendix D.4)
        // before any application data. tdlib's FakeTLS client does the same, and
        // a strict MTProxy drops the connection on the first app-data record if
        // this dummy CCS never arrived. It is a no-op to a lenient peer.
        $this->inner->send(self::REC_CHANGE_CIPHER . "\x03\x03\x00\x01\x01");

        return true;
    }

    public function send(string $data): int
    {
        $sent = 0;

        // Split into ≤16 KiB application-data records, identical to a real TLS
        // implementation that respects the record-size limit.
        for ($offset = 0; $offset < strlen($data); $offset += self::MAX_RECORD) {
            $chunk = substr($data, $offset, self::MAX_RECORD);
            $this->inner->send(
                self::REC_APPLICATION . "\x03\x03" . pack('n', strlen($chunk)) . $chunk
            );
            $sent += strlen($chunk);
        }

        return $sent;
    }

    public function receive(int $length = 0, float $timeout = 30.0): ?string
    {
        // Read-all: hand back whatever is buffered, else pull exactly one record.
        if ($length === 0) {
            if ($this->appBuffer === '') {
                if (!$this->fillBuffer(1, $timeout)) {
                    return null;
                }
            }
            $out = $this->appBuffer;
            $this->appBuffer = '';
            return $out;
        }

        if (!$this->fillBuffer($length, $timeout)) {
            return null;
        }

        $out = substr($this->appBuffer, 0, $length);
        $this->appBuffer = substr($this->appBuffer, $length);

        return $out;
    }

    /**
     * Pull application-data records from the socket until the buffer holds at
     * least $need bytes. Returns false on timeout before anything arrives.
     */
    private function fillBuffer(int $need, float $timeout): bool
    {
        while (strlen($this->appBuffer) < $need) {
            $payload = $this->readRecord($timeout);
            if ($payload === null) {
                return strlen($this->appBuffer) >= $need;
            }
            $this->appBuffer .= $payload;
        }

        return true;
    }

    /**
     * Read one TLS record header + body. Non-application records (e.g. a stray
     * ChangeCipherSpec) are skipped and the next record is read. Returns the
     * application-data payload, or null on header-read timeout.
     */
    private function readRecord(float $timeout): ?string
    {
        $header = $this->readExact(5, $timeout);
        if ($header === null) {
            return null;
        }

        $type = $header[0];
        $length = (ord($header[3]) << 8) | ord($header[4]);

        $body = $length > 0 ? $this->readExact($length, $timeout) : '';
        if ($body === null) {
            throw new TransportException('FakeTLS: truncated record body.');
        }

        if ($type === self::REC_APPLICATION) {
            return $body;
        }

        if ($type === self::REC_CHANGE_CIPHER || $type === self::REC_HANDSHAKE) {
            // Post-handshake control record - ignore and read the next one.
            return $this->readRecord($timeout);
        }

        throw new TransportException('FakeTLS: unexpected record type 0x' . bin2hex($type) . '.');
    }

    /**
     * Build and send the TLS ClientHello; stash its 32-byte digest.
     */
    private function sendHello(): void
    {
        $hello = $this->buildClientHello(time());
        $this->helloRand = substr($hello, 11, 32);
        $this->inner->send($hello);
    }

    /**
     * Read the server's hello flight and verify its digest proves knowledge of
     * the secret. Mirrors tdlib's TlsInit::wait_hello_response():
     *   - record 1: `16 03 03` + len + body            (ServerHello)
     *   - record 2: `14 03 03 00 01 01 17 03 03` + len + body
     *                                                   (ChangeCipherSpec + AppData)
     * The 32 bytes at offset 11 of the whole flight are the server digest.
     */
    private function readHelloResponse(float $timeout): void
    {
        $response = '';

        foreach (["\x16\x03\x03", "\x14\x03\x03\x00\x01\x01\x17\x03\x03"] as $prefix) {
            $got = $this->readExact(strlen($prefix), $timeout);
            if ($got !== $prefix) {
                throw new TransportException('FakeTLS: invalid server hello response prefix.');
            }
            $response .= $got;

            $lenBytes = $this->readExact(2, $timeout);
            if ($lenBytes === null) {
                throw new TransportException('FakeTLS: truncated server hello length.');
            }
            $response .= $lenBytes;

            $len = (ord($lenBytes[0]) << 8) | ord($lenBytes[1]);
            $body = $len > 0 ? $this->readExact($len, $timeout) : '';
            if ($body === null) {
                throw new TransportException('FakeTLS: truncated server hello body.');
            }
            $response .= $body;
        }

        // Server digest sits at offset 11..42; zero it, recompute, compare.
        $serverDigest = substr($response, 11, 32);
        $zeroed = substr_replace($response, str_repeat("\0", 32), 11, 32);
        $expected = hash_hmac('sha256', $this->helloRand . $zeroed, $this->secret, true);

        if (!hash_equals($expected, $serverDigest)) {
            throw new TransportException('FakeTLS: server hello digest mismatch (wrong secret or not an MTProxy).');
        }
    }

    /**
     * Read exactly $length bytes, accumulating across short reads. Returns null
     * only when the very first read times out with no data.
     */
    private function readExact(int $length, float $timeout): ?string
    {
        $buf = '';
        while (strlen($buf) < $length) {
            $chunk = $this->inner->receive($length - strlen($buf), $timeout);
            if ($chunk === null || $chunk === '') {
                return $buf === '' ? null : throw new TransportException('FakeTLS: connection closed mid-read.');
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    /**
     * Produce the 517-byte TLS ClientHello with the secret-keyed digest baked in.
     */
    private function buildClientHello(int $unixTime): string
    {
        $grease = $this->generateGrease();
        $domain = substr($this->domain, 0, self::MAX_DOMAIN_LENGTH);

        $buf = '';
        $scopes = [];

        $str = function (string $s) use (&$buf): void {
            $buf .= $s;
        };
        $zero = function (int $n) use (&$buf): void {
            $buf .= str_repeat("\0", $n);
        };
        $rand = function (int $n) use (&$buf): void {
            $buf .= $this->crypto->randomBytes($n);
        };
        $gr = function (int $i) use (&$buf, $grease): void {
            $buf .= $grease[$i] . $grease[$i];
        };
        $begin = function () use (&$buf, &$scopes): void {
            $scopes[] = strlen($buf);
            $buf .= "\x00\x00";
        };
        $end = function () use (&$buf, &$scopes): void {
            $at = array_pop($scopes);
            $size = strlen($buf) - $at - 2;
            $buf[$at] = chr(($size >> 8) & 0xff);
            $buf[$at + 1] = chr($size & 0xff);
        };

        $str("\x16\x03\x01\x02\x00\x01\x00\x01\xfc\x03\x03");
        $zero(32);                       // client random - overwritten with digest
        $str("\x20");
        $rand(32);                       // session_id
        $str("\x00\x20");
        $gr(0);
        $str("\x13\x01\x13\x02\x13\x03\xc0\x2b\xc0\x2f\xc0\x2c\xc0\x30\xcc\xa9\xcc\xa8\xc0\x13\xc0\x14\x00\x9c"
            . "\x00\x9d\x00\x2f\x00\x35\x01\x00\x01\x93");
        $gr(2);
        $str("\x00\x00\x00\x00");
        $begin();                        // SNI extension data
        $begin();                    // server_name_list
        $str("\x00");            // name_type: host_name
        $begin();                // host_name
        $str($domain);
        $end();
        $end();
        $end();
        $str("\x00\x17\x00\x00\xff\x01\x00\x01\x00\x00\x0a\x00\x0a\x00\x08");
        $gr(4);
        $str("\x00\x1d\x00\x17\x00\x18\x00\x0b\x00\x02\x01\x00\x00\x23\x00\x00\x00\x10\x00\x0e\x00\x0c\x02\x68\x32\x08"
            . "\x68\x74\x74\x70\x2f\x31\x2e\x31\x00\x05\x00\x05\x01\x00\x00\x00\x00\x00\x0d\x00\x12\x00\x10\x04\x03\x08"
            . "\x04\x04\x01\x05\x03\x08\x05\x05\x01\x08\x06\x06\x01\x00\x12\x00\x00\x00\x33\x00\x2b\x00\x29");
        $gr(4);
        $str("\x00\x01\x00\x00\x1d\x00\x20");
        $buf .= $this->generateKey();    // x25519 key_share (32 bytes)
        $str("\x00\x2d\x00\x02\x01\x01\x00\x2b\x00\x0b\x0a");
        $gr(6);
        $str("\x03\x04\x03\x03\x03\x02\x03\x01\x00\x1b\x00\x03\x02\x00\x02");
        $gr(3);
        $str("\x00\x01\x00\x00\x15");

        // Padding extension: a zero-filled scope sizing the hello to exactly 517
        // bytes (5-byte record header + 512-byte body), matching the fixed record
        // and handshake lengths hardcoded above.
        $zeroPad = 515 - strlen($buf);
        if ($zeroPad < 0) {
            throw new TransportException('FakeTLS: SNI domain too long for the hello template.');
        }
        $begin();
        $zero($zeroPad);
        $end();

        // Bake the secret-keyed digest into the client random (offset 11..42),
        // then XOR its last 4 bytes with the unix time (little-endian).
        $digest = $this->hmac($this->secret, $buf);
        $digest = $this->xorTimestamp($digest, $unixTime);
        $buf = substr_replace($buf, $digest, 11, 32);

        return $buf;
    }

    /**
     * HMAC key vs message ordering for the client digest: hmac(key=secret, msg=hello).
     * PHP's hash_hmac signature is (algo, data, key) - wrap it so call sites read
     * naturally and we never transpose the two 16/n-byte strings.
     */
    private function hmac(string $key, string $message): string
    {
        return hash_hmac('sha256', $message, $key, true);
    }

    /**
     * XOR the last 4 bytes of the 32-byte digest with the unix time, little-endian
     * (tdlib: `as<int32>(hash[28..32]) ^= unix_time`).
     */
    private function xorTimestamp(string $digest, int $unixTime): string
    {
        for ($i = 0; $i < 4; $i++) {
            $digest[28 + $i] = chr(ord($digest[28 + $i]) ^ (($unixTime >> (8 * $i)) & 0xff));
        }

        return $digest;
    }

    /**
     * Seven GREASE bytes (tdlib Grease::init): each byte `(r & 0xF0) | 0x0A`; any
     * odd-index byte equal to its predecessor is flipped by 0x10 so adjacent
     * GREASE values differ, as real clients require.
     */
    private function generateGrease(): array
    {
        $bytes = array_values(unpack('C*', $this->crypto->randomBytes(7)));
        foreach ($bytes as $i => $b) {
            $bytes[$i] = ($b & 0xF0) + 0x0A;
        }
        for ($i = 1; $i < 7; $i += 2) {
            if ($bytes[$i] === $bytes[$i - 1]) {
                $bytes[$i] ^= 0x10;
            }
        }

        return array_map('chr', $bytes);
    }

    /**
     * A 32-byte x25519 key_share value. Per RFC 7748 any 32-byte string is a valid
     * u-coordinate once the high bit is cleared, and the MTProxy server validates
     * only the hello digest (never the point), so secure-random bytes are
     * indistinguishable from a real public key to a passive observer.
     */
    private function generateKey(): string
    {
        $key = $this->crypto->randomBytes(32);
        $key[31] = chr(ord($key[31]) & 0x7f);

        return $key;
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
        $this->appBuffer = '';
        $this->helloRand = '';
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function getRemoteAddress(): ?string
    {
        return $this->inner->getRemoteAddress();
    }

    public function getRemotePort(): ?int
    {
        return $this->inner->getRemotePort();
    }

    public function getInner(): ConnectionInterface
    {
        return $this->inner;
    }
}
