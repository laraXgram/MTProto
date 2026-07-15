<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Secret;

use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Crypto\NativeCrypto;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * Orchestrates Telegram secret chats (E2E) on top of {@see SecretChatCrypto}
 * and {@see SecretMessageSerializer}.
 */
final class SecretChatManager
{
    private const KEY_PREFIX = 'secret:';
    private const SECRET_LAYER = 143;

    private NativeCrypto $crypto;

    public function __construct(
        private Client $client,
        private Store $store,
        private SecretChatCrypto $secret = new SecretChatCrypto(),
        private SecretMessageSerializer $serializer = new SecretMessageSerializer(),
    ) {
        $this->crypto = new NativeCrypto();
    }

    /**
     * Initiate a secret chat with a user. Returns the pending EncryptedChat.
     */
    public function request(int $userId): array
    {
        [$g, $p] = $this->dhConfig();

        $a = $this->crypto->randomBytes(256);
        $gA = gmp_powm(gmp_init($g), gmp_import($a), gmp_import($p));
        $this->assertGValueInRange($gA, gmp_import($p));

        $randomId = $this->randomInt31();
        $result = $this->client->invoke('messages.requestEncryption', [
            'user_id' => $this->inputUser($userId),
            'random_id' => $randomId,
            'g_a' => $this->pad256(gmp_export($gA)),
        ]);

        $chatId = (int) ($result['id'] ?? 0);
        if ($chatId === 0) {
            throw new MTProtoException('requestEncryption returned no chat id.');
        }

        $this->save($chatId, [
            'id' => $chatId,
            'access_hash' => (int) ($result['access_hash'] ?? 0),
            'user_id' => $userId,
            'originator' => true,
            'a' => base64_encode($a),
            'key' => null,
            'in_count' => 0,
            'out_count' => 0,
        ]);

        return $result;
    }

    /**
     * Accept an incoming secret-chat request (from updateEncryption →
     * encryptedChatRequested).
     */
    public function accept(array $requested): array
    {
        $chatId = (int) ($requested['id'] ?? 0);
        $accessHash = (int) ($requested['access_hash'] ?? 0);
        $gA = $this->bytesFrom($requested['g_a'] ?? '');

        [$g, $p] = $this->dhConfig();
        $pInt = gmp_import($p);

        $gAInt = gmp_import($gA);
        $this->assertGValueInRange($gAInt, $pInt);

        $b = $this->crypto->randomBytes(256);
        $gB = gmp_powm(gmp_init($g), gmp_import($b), $pInt);
        $this->assertGValueInRange($gB, $pInt);

        $key = $this->secret->computeSharedKey($gA, $b, $p);
        $fingerprint = $this->fingerprintInt($key);

        $result = $this->client->invoke('messages.acceptEncryption', [
            'peer' => ['_' => 'inputEncryptedChat', 'chat_id' => $chatId, 'access_hash' => $accessHash],
            'g_b' => $this->pad256(gmp_export($gB)),
            'key_fingerprint' => $fingerprint,
        ]);

        $this->save($chatId, [
            'id' => $chatId,
            'access_hash' => $accessHash,
            'user_id' => (int) ($requested['admin_id'] ?? 0),
            'originator' => false,
            'a' => null,
            'key' => base64_encode($key),
            'in_count' => 0,
            'out_count' => 0,
        ]);

        $this->sendNotifyLayer($chatId);

        return $result;
    }

    /**
     * Finalize our own request once the peer accepted (updateEncryption →
     * encryptedChat with g_a_or_b + key_fingerprint).
     */
    public function finalize(array $encryptedChat): void
    {
        $chatId = (int) ($encryptedChat['id'] ?? 0);
        $state = $this->load($chatId);
        if ($state === null || empty($state['a'])) {
            throw new MTProtoException("No pending secret chat {$chatId} to finalize.");
        }

        [, $p] = $this->dhConfig();
        $gB = $this->bytesFrom($encryptedChat['g_a_or_b'] ?? '');
        $key = $this->secret->computeSharedKey($gB, base64_decode($state['a']), $p);

        $expected = $this->fingerprintInt($key);
        if ($expected !== (int) ($encryptedChat['key_fingerprint'] ?? 0)) {
            throw new MTProtoException('Secret chat key fingerprint mismatch - aborting (possible MITM).');
        }

        $state['key'] = base64_encode($key);
        $state['access_hash'] = (int) ($encryptedChat['access_hash'] ?? $state['access_hash']);
        $state['a'] = null;
        $this->save($chatId, $state);

        $this->sendNotifyLayer($chatId);
    }

    /**
     * Send a text message into an established secret chat.
     */
    public function sendMessage(int $chatId, string $text, int $ttl = 0): array
    {
        $state = $this->requireEstablished($chatId);
        $body = $this->serializer->serializeText($this->randomInt64(), $text, $ttl);

        return $this->sendBody($chatId, $state, $body);
    }

    /**
     * Decrypt + parse an incoming encrypted message
     * (updateNewEncryptedMessage → message.data).
     *
     * @return array{chat_id:int,message:array}|null null if the chat is unknown.
     */
    public function receive(array $encryptedMessage): ?array
    {
        $chatId = (int) ($encryptedMessage['chat_id'] ?? 0);
        $state = $this->load($chatId);
        if ($state === null || empty($state['key'])) {
            return null;
        }

        // The author is the *other* party, so x is flipped from our own role.
        $x = $state['originator'] ? 8 : 0;
        $body = $this->secret->decrypt(base64_decode($state['key']), $this->bytesFrom($encryptedMessage['bytes'] ?? $encryptedMessage['data'] ?? ''), $x);

        $parsed = $this->serializer->parseLayer($body);

        $state['in_count'] = (int) $state['in_count'] + 1;
        $this->save($chatId, $state);

        return ['chat_id' => $chatId, 'message' => $parsed['message']];
    }

    /**
     * Route an updateEncryption to accept()/finalize() as appropriate.
     */
    public function handleUpdateEncryption(array $chat): ?array
    {
        return match ($chat['_'] ?? '') {
            'encryptedChatRequested' => $this->accept($chat),
            'encryptedChat' => (function () use ($chat) {
                $this->finalize($chat);
                return null;
            })(),
            default => null,
        };
    }

    private function sendNotifyLayer(int $chatId): void
    {
        $state = $this->requireEstablished($chatId);
        $body = $this->serializer->serializeNotifyLayer($this->randomInt64(), self::SECRET_LAYER);
        $this->sendBody($chatId, $state, $body);
    }

    private function sendBody(int $chatId, array $state, string $body): array
    {
        $originator = (bool) $state['originator'];
        $x = $originator ? 0 : 1;
        $outCount = (int) $state['out_count'];
        $inCount = (int) $state['in_count'];

        $layer = $this->serializer->serializeLayer(
            $body,
            self::SECRET_LAYER,
            inSeq: $inCount * 2 + (1 - $x),
            outSeq: $outCount * 2 + $x,
            randomPad: $this->crypto->randomBytes(16),
        );

        $wire = $this->secret->encrypt(base64_decode($state['key']), $layer, $originator ? 0 : 8);

        $result = $this->client->invoke('messages.sendEncrypted', [
            'peer' => ['_' => 'inputEncryptedChat', 'chat_id' => $chatId, 'access_hash' => (int) $state['access_hash']],
            'random_id' => $this->randomInt64(),
            'data' => $wire,
        ]);

        $state['out_count'] = $outCount + 1;
        $this->save($chatId, $state);

        return is_array($result) ? $result : [];
    }

    /**
     * @return array{0:int,1:string} [g, prime bytes]
     */
    private function dhConfig(): array
    {
        $config = $this->client->invoke('messages.getDhConfig', ['version' => 0, 'random_length' => 0]);

        if (($config['_'] ?? '') !== 'messages.dhConfig') {
            throw new MTProtoException('Unexpected messages.getDhConfig response.');
        }

        $g = (int) $config['g'];
        $p = $this->bytesFrom($config['p']);

        $this->validatePrime(gmp_import($p), $g);

        return [$g, $p];
    }

    /**
     * Validate the DH prime is a 2048-bit safe prime and g is a permitted
     * generator (mirrors Telegram's client-side requirement).
     */
    private function validatePrime(\GMP $p, int $g): void
    {
        if (gmp_cmp($p, 0) <= 0 || strlen(gmp_export($p)) !== 256) {
            throw new MTProtoException('Secret chat DH prime is not 2048-bit.');
        }
        if (!in_array($g, [2, 3, 4, 5, 6, 7], true)) {
            throw new MTProtoException("Secret chat DH generator g={$g} is not permitted.");
        }
        if (gmp_prob_prime($p, 30) === 0) {
            throw new MTProtoException('Secret chat DH prime failed primality test.');
        }
        $half = gmp_div_q(gmp_sub($p, 1), 2);
        if (gmp_prob_prime($half, 30) === 0) {
            throw new MTProtoException('Secret chat DH prime is not a safe prime.');
        }
    }

    /**
     * Ensure a g-value lies in [2^{2048-64}, p - 2^{2048-64}] to avoid weak keys.
     */
    private function assertGValueInRange(\GMP $value, \GMP $p): void
    {
        $min = gmp_pow(2, 2048 - 64);
        $max = gmp_sub($p, $min);

        if (gmp_cmp($value, $min) < 0 || gmp_cmp($value, $max) > 0) {
            throw new MTProtoException('Secret chat g-value out of safe range.');
        }
    }

    private function requireEstablished(int $chatId): array
    {
        $state = $this->load($chatId);
        if ($state === null || empty($state['key'])) {
            throw new MTProtoException("Secret chat {$chatId} is not established.");
        }

        return $state;
    }

    private function fingerprintInt(string $key): int
    {
        return unpack('P', $this->secret->keyFingerprint($key))[1];
    }

    private function inputUser(int $userId): array
    {
        $peer = $this->client->getResolver()?->resolveInputPeer($userId) ?? [];

        return [
            '_' => 'inputUser',
            'user_id' => $userId,
            'access_hash' => (int) ($peer['access_hash'] ?? 0),
        ];
    }

    private function bytesFrom(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function pad256(string $bytes): string
    {
        return str_pad($bytes, 256, "\x00", STR_PAD_LEFT);
    }

    private function randomInt31(): int
    {
        return random_int(1, 0x7FFFFFFF);
    }

    private function randomInt64(): int
    {
        return random_int(PHP_INT_MIN, PHP_INT_MAX);
    }

    private function load(int $chatId): ?array
    {
        $json = $this->store->get(self::KEY_PREFIX . $chatId);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function save(int $chatId, array $state): void
    {
        $this->store->put(self::KEY_PREFIX . $chatId, json_encode($state));
    }
}
