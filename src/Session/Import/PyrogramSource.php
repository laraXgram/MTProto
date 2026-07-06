<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

final class PyrogramSource extends AbstractSource
{
    public function name(): string
    {
        return 'pyrogram';
    }

    public function supports(string $input): bool
    {
        if ($this->isFile($input)) {
            // SQLite files begin with "SQLite format 3\0".
            return str_starts_with($this->fileMagic($input), 'SQLite format 3');
        }

        $raw = $this->base64($input);

        return in_array(strlen($raw), [263, 267, 271], true);
    }

    public function parse(string $input): ForeignSession
    {
        return $this->isFile($input)
            ? $this->parseFile($input)
            : $this->parseString($input);
    }

    private function parseString(string $input): ForeignSession
    {
        $raw = $this->base64($input);
        $len = strlen($raw);

        switch ($len) {
            case 271: // v2: dc_id, api_id(4), test_mode, auth_key(256), user_id(8), is_bot
                $dcId = ord($raw[0]);
                $testMode = ord($raw[5]) === 1;
                $authKey = substr($raw, 6, 256);
                $userId = $this->uint64($raw, 262);
                $isBot = ord($raw[270]) === 1;
                break;

            case 267: // v1: dc_id, test_mode, auth_key(256), user_id(8), is_bot
                $dcId = ord($raw[0]);
                $testMode = ord($raw[1]) === 1;
                $authKey = substr($raw, 2, 256);
                $userId = $this->uint64($raw, 258);
                $isBot = ord($raw[266]) === 1;
                break;

            case 263: // v0: dc_id, test_mode, auth_key(256), user_id(4), is_bot
                $dcId = ord($raw[0]);
                $testMode = ord($raw[1]) === 1;
                $authKey = substr($raw, 2, 256);
                $userId = $this->uint32($raw, 258);
                $isBot = ord($raw[262]) === 1;
                break;

            default:
                throw new \RuntimeException(
                    "Unrecognised Pyrogram session string (decoded to {$len} bytes; expected 263, 267 or 271)."
                );
        }

        return new ForeignSession(
            dcId: $dcId,
            authKey: $authKey,
            userId: $userId,
            isBot: $isBot,
            testMode: $testMode,
            source: $this->name(),
        );
    }

    private function parseFile(string $path): ForeignSession
    {
        $pdo = $this->openSqlite($path);

        $row = $pdo->query('SELECT dc_id, test_mode, auth_key, user_id, is_bot FROM session LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Pyrogram session table is empty - nothing to import.');
        }

        $authKey = (string) $row['auth_key'];
        if (strlen($authKey) !== 256) {
            throw new \RuntimeException('Pyrogram session has no usable 256-byte auth key (not logged in?).');
        }

        $peers = $this->readPeers($pdo);

        return new ForeignSession(
            dcId: (int) $row['dc_id'],
            authKey: $authKey,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            isBot: (bool) ($row['is_bot'] ?? false),
            testMode: (bool) ($row['test_mode'] ?? false),
            source: $this->name(),
            peers: $peers,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readPeers(\PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT id, access_hash, type, username, phone_number FROM peers')
                ->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return []; // No peers table - string-only style DB.
        }

        $peers = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $peers[] = ForeignSession::makePeer(
                markedId: $id,
                accessHash: (int) ($row['access_hash'] ?? 0),
                sourceType: $row['type'] ?? null,
                username: $row['username'] ?? null,
                phone: $row['phone_number'] ?? null,
            );
        }

        return $peers;
    }

    private function uint64(string $raw, int $offset): int
    {
        // Big-endian unsigned 64-bit; PHP ints are signed 64-bit which is fine
        // for real Telegram ids.
        return (int) unpack('J', substr($raw, $offset, 8))[1];
    }

    private function uint32(string $raw, int $offset): int
    {
        return (int) unpack('N', substr($raw, $offset, 4))[1];
    }
}
