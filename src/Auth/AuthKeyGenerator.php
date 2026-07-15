<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Auth;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Core\DataCenter;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Exceptions\SecurityException;

/**
 * Authorization Key Generator.
 *
 * Implements the Diffie-Hellman key exchange to generate
 * a shared authorization key with Telegram servers.
 *
 * @see https://core.telegram.org/mtproto/auth_key
 */
class AuthKeyGenerator
{
    /**
     * Connection instance.
     */
    private ConnectionInterface $connection;

    /**
     * Transport instance.
     */
    private TransportInterface $transport;

    /**
     * Crypto instance.
     */
    private CryptoInterface $crypto;

    /**
     * DC ID.
     */
    private int $dcId;

    /**
     * Test mode.
     */
    private bool $testMode;

    /**
     * Maximum retries for key generation.
     */
    private int $maxRetries = 5;

    /**
     * Last generated message ID (for monotonic uniqueness).
     */
    private int $lastMsgId = 0;

    /**
     * Create a new auth key generator.
     */
    public function __construct(
        ConnectionInterface $connection,
        TransportInterface $transport,
        CryptoInterface $crypto,
        int $dcId = 2,
        bool $testMode = false
    ) {
        $this->connection = $connection;
        $this->transport = $transport;
        $this->crypto = $crypto;
        $this->dcId = $dcId;
        $this->testMode = $testMode;
    }

    /**
     * Send unencrypted message and receive response.
     */
    private function sendUnencrypted(string $data): string
    {
        // Build unencrypted message
        // auth_key_id (8 bytes, 0) + message_id (8 bytes) + message_length (4 bytes) + data
        $authKeyId = str_repeat("\x00", 8);
        $messageId = $this->generateMsgId();
        $messageLength = pack('V', strlen($data));

        $payload = $authKeyId . pack('P', $messageId) . $messageLength . $data;

        // Wrap with transport and send
        $wrapped = $this->transport->wrap($payload);
        $this->connection->send($wrapped);

        // Read response
        $length = $this->transport->readLength($this->connection);

        if ($length === 0) {
            throw new MTProtoException('Got zero-length response');
        }

        $response = $this->connection->receive($length);

        if ($response === null) {
            throw new MTProtoException('Timeout waiting for response');
        }

        // The response from Abridged transport should NOT need unwrapping
        // because readLength already gave us the raw message length
        // So $response IS the MTProto message directly

        // Parse unencrypted message structure
        // auth_key_id (8 bytes) + message_id (8 bytes) + message_length (4 bytes) + data
        $responseAuthKeyId = substr($response, 0, 8);

        if ($responseAuthKeyId !== str_repeat("\x00", 8)) {
            throw new MTProtoException('Expected unencrypted response, got auth_key_id: ' . bin2hex($responseAuthKeyId) . ', response length: ' . strlen($response) . ', first 40 bytes: ' . bin2hex(substr($response, 0, 40)));
        }

        // Skip message_id and length, return data
        return substr($response, 20);
    }

    /**
     * Generate message ID.
     */
    private function generateMsgId(): int
    {
        $msgId = (int) (microtime(true) * (1 << 32));
        $msgId = ($msgId >> 2) << 2;

        if ($msgId <= $this->lastMsgId) {
            $msgId = $this->lastMsgId + 4;
        }

        return $this->lastMsgId = $msgId;
    }

    /**
     * Generate a new authorization key.
     *
     * @return array{auth_key: string, server_salt: string, time_delta: int}
     * @throws MTProtoException
     */
    public function generate(): array
    {
        $retries = 0;

        while ($retries < $this->maxRetries) {
            try {
                return $this->doGenerate();
            } catch (\Exception $e) {
                $retries++;
                if ($retries >= $this->maxRetries) {
                    throw new MTProtoException(
                        "Auth key generation failed after {$this->maxRetries} retries: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        throw new MTProtoException('Auth key generation failed');
    }

    /**
     * Perform the actual key generation.
     *
     * @return array{auth_key: string, server_salt: string, time_delta: int}
     */
    private function doGenerate(): array
    {
        // Step 1: Request PQ
        $nonce = $this->crypto->randomBytes(16);

        $reqPq = $this->serializeReqPqMulti($nonce);
        $response = $this->sendUnencrypted($reqPq);

        $resPq = $this->deserializeResPq($response);

        // Verify nonce
        if ($resPq['nonce'] !== $nonce) {
            throw SecurityException::checkFailed('nonce mismatch in req_pq');
        }

        $serverNonce = $resPq['server_nonce'];
        $pq = $resPq['pq'];
        $serverPublicKeyFingerprints = $resPq['server_public_key_fingerprints'];

        // Step 2: Factorize PQ into P and Q
        $pqInt = gmp_import($pq, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        [$p, $q] = $this->factorizePQ($pqInt);

        // Ensure p < q
        if (gmp_cmp($p, $q) > 0) {
            [$p, $q] = [$q, $p];
        }

        // Step 3: Find a matching RSA public key
        $publicKey = $this->findPublicKey($serverPublicKeyFingerprints);
        if ($publicKey === null) {
            throw new MTProtoException(
                'No matching RSA public key found. Server sent fingerprints: ' . implode(', ', $serverPublicKeyFingerprints)
            );
        }

        // Step 4: Generate new_nonce
        $newNonce = $this->crypto->randomBytes(32);

        // Build p_q_inner_data
        $pBytes = gmp_export($p, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $qBytes = gmp_export($q, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        $innerData = $this->serializePQInnerData($pq, $pBytes, $qBytes, $nonce, $serverNonce, $newNonce);

        // Encrypt with RSA using new padding scheme
        // https://core.telegram.org/mtproto/auth_key (step 4.1)
        $encryptedData = $this->encryptPqInnerData($innerData, $publicKey);

        // Step 5: Send req_DH_params
        $reqDhParams = $this->serializeReqDhParams(
            $nonce,
            $serverNonce,
            $pBytes,
            $qBytes,
            $publicKey['fingerprint'],
            $encryptedData
        );

        $response = $this->sendUnencrypted($reqDhParams);
        $serverDhParams = $this->deserializeServerDhParams($response);

        // Verify nonces
        if ($serverDhParams['nonce'] !== $nonce) {
            throw SecurityException::checkFailed('nonce mismatch in server_DH_params');
        }
        if ($serverDhParams['server_nonce'] !== $serverNonce) {
            throw SecurityException::checkFailed('server_nonce mismatch');
        }

        // Step 6: Decrypt server_DH_inner_data
        $encryptedAnswer = $serverDhParams['encrypted_answer'];

        // Compute tmp_aes_key and tmp_aes_iv
        $tmpAesKey = $this->crypto->sha1($newNonce . $serverNonce)
            . substr($this->crypto->sha1($serverNonce . $newNonce), 0, 12);

        $tmpAesIv = substr($this->crypto->sha1($serverNonce . $newNonce), 12)
            . $this->crypto->sha1($newNonce . $newNonce)
            . substr($newNonce, 0, 4);

        $answerWithHash = $this->crypto->aesIgeDecrypt($encryptedAnswer, $tmpAesKey, $tmpAesIv);

        // Parse decrypted answer
        $answerHash = substr($answerWithHash, 0, 20);
        $answer = substr($answerWithHash, 20);

        $serverDhInner = $this->deserializeServerDhInnerData($answer);

        // Verify hash
        $calculatedHash = $this->crypto->sha1(substr($answer, 0, $serverDhInner['_length']));
        if ($answerHash !== $calculatedHash) {
            throw SecurityException::checkFailed('server_DH_inner_data hash mismatch');
        }

        // Verify nonces again
        if ($serverDhInner['nonce'] !== $nonce) {
            throw SecurityException::checkFailed('nonce mismatch in server_DH_inner_data');
        }
        if ($serverDhInner['server_nonce'] !== $serverNonce) {
            throw SecurityException::checkFailed('server_nonce mismatch in server_DH_inner_data');
        }

        // Calculate time delta
        $timeDelta = $serverDhInner['server_time'] - time();

        // Step 7: Generate client DH params
        $dhPrime = gmp_import($serverDhInner['dh_prime'], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $g = gmp_init($serverDhInner['g']);
        $gA = gmp_import($serverDhInner['g_a'], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        // Security checks on DH params
        $this->verifyDhParams($dhPrime, $g, $gA);

        // Generate b (256 random bytes)
        $b = gmp_import($this->crypto->randomBytes(256), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        // Calculate g_b = g^b mod dh_prime
        $gB = gmp_powm($g, $b, $dhPrime);

        // Security check on g_b
        $this->verifyGab($gB, $dhPrime);

        // Build client_DH_inner_data
        $gBBytes = str_pad(
            gmp_export($gB, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN),
            256,
            "\x00",
            STR_PAD_LEFT
        );

        $clientDhInner = $this->serializeClientDhInnerData($nonce, $serverNonce, 0, $gBBytes);

        // Encrypt client_DH_inner_data
        $dataWithHash = $this->crypto->sha1($clientDhInner) . $clientDhInner;
        $paddingLength = 16 - (strlen($dataWithHash) % 16);
        if ($paddingLength < 12) {
            $paddingLength += 16;
        }
        $dataWithHash .= $this->crypto->randomBytes($paddingLength);

        $encryptedData = $this->crypto->aesIgeEncrypt($dataWithHash, $tmpAesKey, $tmpAesIv);

        // Step 8: Send set_client_DH_params
        $setClientDhParams = $this->serializeSetClientDhParams($nonce, $serverNonce, $encryptedData);

        $response = $this->sendUnencrypted($setClientDhParams);
        $dhGenResult = $this->deserializeDhGenResult($response);

        // Verify nonces
        if ($dhGenResult['nonce'] !== $nonce) {
            throw SecurityException::checkFailed('nonce mismatch in dh_gen result');
        }
        if ($dhGenResult['server_nonce'] !== $serverNonce) {
            throw SecurityException::checkFailed('server_nonce mismatch in dh_gen result');
        }

        // Step 9: Calculate auth_key
        $authKey = gmp_powm($gA, $b, $dhPrime);
        $authKeyBytes = str_pad(
            gmp_export($authKey, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN),
            256,
            "\x00",
            STR_PAD_LEFT
        );

        // Verify new_nonce_hash
        $newNonceHash = $this->calculateNewNonceHash($newNonce, $authKeyBytes, $dhGenResult['type']);

        if ($dhGenResult['new_nonce_hash'] !== $newNonceHash) {
            throw SecurityException::checkFailed('new_nonce_hash mismatch');
        }

        // Calculate server salt
        $serverSalt = substr($newNonce, 0, 8) ^ substr($serverNonce, 0, 8);

        return [
            'auth_key' => $authKeyBytes,
            'server_salt' => $serverSalt,
            'time_delta' => (int)$timeDelta,
        ];
    }

    /**
     * Factorize PQ into P and Q using Pollard's rho algorithm.
     *
     * @param \GMP $pq
     * @return array{\GMP, \GMP}
     */
    private function factorizePQ(\GMP $pq): array
    {
        // Try small primes first
        $smallPrimes = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53];

        foreach ($smallPrimes as $prime) {
            if (gmp_cmp(gmp_mod($pq, $prime), 0) === 0) {
                return [gmp_init($prime), gmp_div_q($pq, $prime)];
            }
        }

        // Pollard's rho algorithm
        $x = gmp_init(2);
        $y = gmp_init(2);
        $d = gmp_init(1);

        $f = function(\GMP $x) use ($pq): \GMP {
            return gmp_mod(gmp_add(gmp_mul($x, $x), 1), $pq);
        };

        while (gmp_cmp($d, 1) === 0) {
            $x = $f($x);
            $y = $f($f($y));
            $d = gmp_gcd(gmp_abs(gmp_sub($x, $y)), $pq);
        }

        if (gmp_cmp($d, $pq) === 0) {
            throw new MTProtoException('PQ factorization failed');
        }

        return [$d, gmp_div_q($pq, $d)];
    }

    /**
     * Find a matching RSA public key.
     *
     * @param array $fingerprints Server's key fingerprints (hex strings from GMP)
     * @return array|null Key data or null if not found
     */
    private function findPublicKey(array $fingerprints): ?array
    {
        return DataCenter::findRsaKeyByFingerprints($fingerprints, $this->testMode);
    }

    /**
     * Encrypt p_q_inner_data using the new padding scheme.
     *
     * See: https://core.telegram.org/mtproto/auth_key (step 4.1)
     *
     * @param string $innerData Serialized p_q_inner_data
     * @param array $key RSA public key with n and e as GMP objects
     * @return string Encrypted data (256 bytes)
     */
    private function encryptPqInnerData(string $innerData, array $key): string
    {
        if (strlen($innerData) > 144) {
            throw new MTProtoException('p_q_inner_data is too long!');
        }

        // Pad to 192 bytes
        $dataWithPadding = $innerData . $this->crypto->randomBytes(192 - strlen($innerData));

        // Reverse the padded data
        $dataPadReversed = strrev($dataWithPadding);

        // Try up to 10 times to find a valid encryption
        for ($tryInner = 0; $tryInner < 10; $tryInner++) {
            // Generate a random 32-byte temp_key
            $tempKey = $this->crypto->randomBytes(32);

            // data_with_hash = data_pad_reversed + SHA256(temp_key + data_with_padding)
            $dataWithHash = $dataPadReversed . hash('sha256', $tempKey . $dataWithPadding, true);

            // aes_encrypted = AES256_IGE(data_with_hash, temp_key, iv=0)
            $aesEncrypted = $this->crypto->aesIgeEncrypt($dataWithHash, $tempKey, str_repeat("\0", 32));

            // temp_key_xor = temp_key XOR SHA256(aes_encrypted)
            $tempKeyXor = $tempKey ^ hash('sha256', $aesEncrypted, true);

            // key_aes_encrypted = temp_key_xor + aes_encrypted (256 bytes total)
            $keyAesEncrypted = $tempKeyXor . $aesEncrypted;

            // Convert to BigInteger and check if < n
            $keyAesEncryptedBigint = gmp_import($keyAesEncrypted, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

            if (gmp_cmp($keyAesEncryptedBigint, $key['n']) < 0) {
                // RSA encrypt
                return $this->rsaEncrypt($keyAesEncrypted, $key);
            }
        }

        throw new MTProtoException('Failed to generate a valid payload within 10 attempts.');
    }

    /**
     * RSA encrypt using modular exponentiation.
     *
     * @param string $data Data to encrypt (256 bytes)
     * @param array $key Key data with n and e as GMP objects
     * @return string Encrypted data (256 bytes)
     */
    private function rsaEncrypt(string $data, array $key): string
    {
        $m = gmp_import($data, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $n = $key['n']; // Already GMP
        $e = $key['e']; // Already GMP

        $c = gmp_powm($m, $e, $n);

        return str_pad(
            gmp_export($c, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN),
            256,
            "\x00",
            STR_PAD_LEFT
        );
    }

    /**
     * Cache of dh_prime hashes that already passed the full primality check.
     * The prime never changes in practice, so the expensive Miller-Rabin pass
     * runs once per process (Madeline approach).
     *
     * @var array<string, true>
     */
    private static array $verifiedPrimes = [];

    /**
     * Verify DH parameters.
     *
     * Full check per the MTProto spec: dh_prime must be a 2048-bit safe prime,
     * g must be a valid generator for the chosen value, and g_a must sit in the
     * secure range. Failing to validate dh_prime would let a MITM that broke the
     * RSA-step assumptions supply a smooth prime and recover the shared secret.
     *
     * @see https://core.telegram.org/mtproto/auth_key (step 6)
     */
    private function verifyDhParams(\GMP $dhPrime, \GMP $g, \GMP $gA): void
    {
        // g must be one of the small, spec-allowed generators.
        $gInt = gmp_intval($g);
        if (gmp_cmp($g, 2) < 0 || gmp_cmp($g, 7) > 0) {
            throw SecurityException::checkFailed('g out of allowed range [2, 7]');
        }

        $this->verifyDhPrime($dhPrime);

        // g-specific quadratic-residue condition: ensures g generates the full
        // prime-order subgroup so g^x cannot fall into a small subgroup.
        $valid = match ($gInt) {
            2 => gmp_intval(gmp_mod($dhPrime, 8)) === 7,
            3 => gmp_intval(gmp_mod($dhPrime, 3)) === 2,
            4 => true,
            5 => in_array(gmp_intval(gmp_mod($dhPrime, 5)), [1, 4], true),
            6 => in_array(gmp_intval(gmp_mod($dhPrime, 24)), [19, 23], true),
            7 => in_array(gmp_intval(gmp_mod($dhPrime, 7)), [3, 5, 6], true),
            default => false,
        };
        if (!$valid) {
            throw SecurityException::checkFailed("g={$gInt} is not a valid generator for dh_prime");
        }

        // Check that 1 < g_a < dh_prime - 1
        if (gmp_cmp($gA, 1) <= 0 || gmp_cmp($gA, gmp_sub($dhPrime, 1)) >= 0) {
            throw SecurityException::checkFailed('Invalid g_a parameter');
        }

        // Check that 2^{2048-64} < g_a < dh_prime - 2^{2048-64}
        $lowerBound = gmp_pow(2, 2048 - 64);
        $upperBound = gmp_sub($dhPrime, $lowerBound);

        if (gmp_cmp($gA, $lowerBound) <= 0 || gmp_cmp($gA, $upperBound) >= 0) {
            throw SecurityException::checkFailed('g_a out of secure range');
        }
    }

    /**
     * Verify dh_prime is a 2048-bit safe prime, caching the result.
     */
    private function verifyDhPrime(\GMP $dhPrime): void
    {
        // dh_prime must be exactly 2048 bits: 2^2047 <= p < 2^2048.
        $bits = strlen(gmp_strval($dhPrime, 2));
        if ($bits !== 2048) {
            throw SecurityException::checkFailed("dh_prime is not 2048-bit (got {$bits} bits)");
        }

        $cacheKey = hash('sha256', gmp_export($dhPrime, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN));
        if (isset(self::$verifiedPrimes[$cacheKey])) {
            return;
        }

        // dh_prime must be prime, and (dh_prime - 1) / 2 must also be prime
        // (safe prime). gmp_prob_prime: 0 = composite, 1 = probably prime,
        // 2 = definitely prime. 25 Miller-Rabin rounds is the Madeline default.
        if (gmp_prob_prime($dhPrime, 25) === 0) {
            throw SecurityException::checkFailed('dh_prime is not prime');
        }

        $halfPrime = gmp_div_q(gmp_sub($dhPrime, 1), 2);
        if (gmp_prob_prime($halfPrime, 25) === 0) {
            throw SecurityException::checkFailed('(dh_prime - 1) / 2 is not prime');
        }

        self::$verifiedPrimes[$cacheKey] = true;
    }

    /**
     * Verify g_ab value.
     */
    private function verifyGab(\GMP $gAb, \GMP $dhPrime): void
    {
        // Check that 2^{2048-64} < g_ab < dh_prime - 2^{2048-64}
        $lowerBound = gmp_pow(2, 2048 - 64);
        $upperBound = gmp_sub($dhPrime, $lowerBound);

        if (gmp_cmp($gAb, $lowerBound) <= 0 || gmp_cmp($gAb, $upperBound) >= 0) {
            throw SecurityException::checkFailed('g_ab out of secure range');
        }
    }

    /**
     * Calculate new_nonce_hash for verification.
     */
    private function calculateNewNonceHash(string $newNonce, string $authKey, int $type): string
    {
        $authKeyAuxHash = substr($this->crypto->sha1($authKey), 0, 8);

        // type: 1 = ok, 2 = retry, 3 = fail
        $data = $newNonce . chr($type) . $authKeyAuxHash;

        return substr($this->crypto->sha1($data), 4, 16);
    }

    // TL Serialization methods (simplified - will be replaced by TL system later)

    private function serializeReqPqMulti(string $nonce): string
    {
        // req_pq_multi#be7e8ef1 nonce:int128 = ResPQ
        return pack('V', 0xbe7e8ef1) . $nonce;
    }

    private function deserializeResPq(string $data): array
    {
        $offset = 0;
        $constructor = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        if ($constructor !== 0x05162463) { // resPQ
            throw new MTProtoException('Unexpected constructor: ' . dechex($constructor));
        }

        $nonce = substr($data, $offset, 16);
        $offset += 16;

        $serverNonce = substr($data, $offset, 16);
        $offset += 16;

        // TL string for pq
        $pqLen = ord($data[$offset]);
        $offset += 1;
        if ($pqLen >= 254) {
            $pqLen = unpack('V', substr($data, $offset, 3) . "\x00")[1];
            $offset += 3;
        }
        $pq = substr($data, $offset, $pqLen);
        $offset += $pqLen;
        $offset += (4 - (($pqLen + ($pqLen < 254 ? 1 : 4)) % 4)) % 4; // Padding

        // Vector of fingerprints - skip vector constructor ID
        $offset += 4;
        $count = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        $fingerprints = [];
        for ($i = 0; $i < $count; $i++) {
            // Read as unsigned 64-bit integer using GMP to avoid float overflow
            $bytes = substr($data, $offset, 8);
            $fp = gmp_import($bytes, 1, GMP_LSW_FIRST | GMP_LITTLE_ENDIAN);

            // Convert to signed int64 if needed (for comparison)
            $fpStr = gmp_strval($fp, 16);
            $fingerprints[] = $fpStr;
            $offset += 8;
        }

        return [
            'nonce' => $nonce,
            'server_nonce' => $serverNonce,
            'pq' => $pq,
            'server_public_key_fingerprints' => $fingerprints,
        ];
    }

    private function serializePQInnerData(string $pq, string $p, string $q, string $nonce, string $serverNonce, string $newNonce): string
    {
        // p_q_inner_data#83c95aec pq:string p:string q:string nonce:int128 server_nonce:int128 new_nonce:int256 = P_Q_inner_data
        $result = pack('V', 0x83c95aec);

        // TL string serialization
        $result .= $this->serializeTLString($pq);
        $result .= $this->serializeTLString($p);
        $result .= $this->serializeTLString($q);
        $result .= $nonce;
        $result .= $serverNonce;
        $result .= $newNonce;

        return $result;
    }

    private function serializeTLString(string $str): string
    {
        $len = strlen($str);

        if ($len < 254) {
            $result = chr($len) . $str;
            $padding = (4 - ((1 + $len) % 4)) % 4;
        } else {
            // 0xfe + 3 bytes length (little-endian)
            $result = "\xfe" . substr(pack('V', $len), 0, 3) . $str;
            $padding = (4 - ((4 + $len) % 4)) % 4;
        }

        return $result . str_repeat("\x00", $padding);
    }

    private function serializeReqDhParams(string $nonce, string $serverNonce, string $p, string $q, string $fingerprint, string $encryptedData): string
    {
        // req_DH_params#d712e4be nonce:int128 server_nonce:int128 p:string q:string public_key_fingerprint:long encrypted_data:string = Server_DH_Params
        $result = pack('V', 0xd712e4be);
        $result .= $nonce;
        $result .= $serverNonce;
        $result .= $this->serializeTLString($p);
        $result .= $this->serializeTLString($q);

        // Convert hex fingerprint to 8 bytes (little-endian)
        $fpBytes = hex2bin(str_pad($fingerprint, 16, '0', STR_PAD_LEFT));
        $result .= strrev($fpBytes); // Reverse for little-endian

        $result .= $this->serializeTLString($encryptedData);

        return $result;
    }

    private function deserializeServerDhParams(string $data): array
    {
        $offset = 0;
        $constructor = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        if ($constructor === 0x79cb045d) { // server_DH_params_fail
            throw new MTProtoException('Server DH params failed');
        }

        if ($constructor !== 0xd0e8075c) { // server_DH_params_ok
            throw new MTProtoException('Unexpected constructor: ' . dechex($constructor));
        }

        $nonce = substr($data, $offset, 16);
        $offset += 16;

        $serverNonce = substr($data, $offset, 16);
        $offset += 16;

        // TL string for encrypted_answer
        [$encryptedAnswer] = $this->deserializeTLString($data, $offset);

        return [
            'nonce' => $nonce,
            'server_nonce' => $serverNonce,
            'encrypted_answer' => $encryptedAnswer,
        ];
    }

    private function deserializeTLString(string $data, int $offset): array
    {
        $len = ord($data[$offset]);
        $offset += 1;

        if ($len >= 254) {
            $len = unpack('V', substr($data, $offset, 3) . "\x00")[1];
            $offset += 3;
        }

        $str = substr($data, $offset, $len);
        $offset += $len;

        $totalLen = ($len < 254 ? 1 : 4) + $len;
        $padding = (4 - ($totalLen % 4)) % 4;
        $offset += $padding;

        return [$str, $offset];
    }

    private function deserializeServerDhInnerData(string $data): array
    {
        $offset = 0;
        $constructor = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        if ($constructor !== 0xb5890dba) { // server_DH_inner_data
            throw new MTProtoException('Unexpected constructor: ' . dechex($constructor));
        }

        $nonce = substr($data, $offset, 16);
        $offset += 16;

        $serverNonce = substr($data, $offset, 16);
        $offset += 16;

        $g = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        [$dhPrime, $offset] = $this->deserializeTLString($data, $offset);
        [$gA, $offset] = $this->deserializeTLString($data, $offset);

        $serverTime = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        return [
            'nonce' => $nonce,
            'server_nonce' => $serverNonce,
            'g' => $g,
            'dh_prime' => $dhPrime,
            'g_a' => $gA,
            'server_time' => $serverTime,
            '_length' => $offset,
        ];
    }

    private function serializeClientDhInnerData(string $nonce, string $serverNonce, int $retryId, string $gB): string
    {
        // client_DH_inner_data#6643b654 nonce:int128 server_nonce:int128 retry_id:long g_b:string = Client_DH_Inner_Data
        $result = pack('V', 0x6643b654);
        $result .= $nonce;
        $result .= $serverNonce;
        $result .= pack('q', $retryId);
        $result .= $this->serializeTLString($gB);

        return $result;
    }

    private function serializeSetClientDhParams(string $nonce, string $serverNonce, string $encryptedData): string
    {
        // set_client_DH_params#f5045f1f nonce:int128 server_nonce:int128 encrypted_data:string = Set_client_DH_params_answer
        $result = pack('V', 0xf5045f1f);
        $result .= $nonce;
        $result .= $serverNonce;
        $result .= $this->serializeTLString($encryptedData);

        return $result;
    }

    private function deserializeDhGenResult(string $data): array
    {
        $offset = 0;
        $constructor = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        $type = match ($constructor) {
            0x3bcbf734 => 1, // dh_gen_ok
            0x46dc1fb9 => 2, // dh_gen_retry
            0xa69dae02 => 3, // dh_gen_fail
            default => throw new MTProtoException('Unexpected DH gen result: ' . dechex($constructor)),
        };

        $nonce = substr($data, $offset, 16);
        $offset += 16;

        $serverNonce = substr($data, $offset, 16);
        $offset += 16;

        $newNonceHash = substr($data, $offset, 16);

        return [
            'type' => $type,
            'nonce' => $nonce,
            'server_nonce' => $serverNonce,
            'new_nonce_hash' => $newNonceHash,
        ];
    }
}
