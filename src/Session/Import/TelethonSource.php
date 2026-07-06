<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

final class TelethonSource extends AbstractSource
{
    /** Telethon StringSession version prefixes known to use this layout. */
    private const VERSIONS = ['1'];

    public function name(): string
    {
        return 'telethon';
    }

    public function supports(string $input): bool
    {
        if ($this->isFile($input)) {
            return str_starts_with($this->fileMagic($input), 'SQLite format 3');
        }

        $input = trim($input);
        if ($input === '' || !in_array($input[0], self::VERSIONS, true)) {
            return false;
        }

        $raw = $this->base64(substr($input, 1));

        // dc_id (1) + ip (4 or 16) + port (2) + auth_key (256).
        return in_array(strlen($raw), [263, 275], true);
    }

    public function parse(string $input): ForeignSession
    {
        return $this->isFile($input)
            ? $this->parseFile($input)
            : $this->parseString($input);
    }

    private function parseString(string $input): ForeignSession
    {
        $input = trim($input);
        if ($input === '' || !in_array($input[0], self::VERSIONS, true)) {
            throw new \RuntimeException(
                "Unrecognised Telethon StringSession (missing '1' version prefix)."
            );
        }

        $raw = $this->base64(substr($input, 1));
        if (strlen($raw) < 257) {
            throw new \RuntimeException('Telethon StringSession is too short to contain an auth key.');
        }

        $dcId = ord($raw[0]);
        $authKey = substr($raw, -256);

        return new ForeignSession(
            dcId: $dcId,
            authKey: $authKey,
            source: $this->name(),
        );
    }

    private function parseFile(string $path): ForeignSession
    {
        $pdo = $this->openSqlite($path);

        $row = $pdo->query('SELECT dc_id, auth_key FROM sessions LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || empty($row['auth_key'])) {
            throw new \RuntimeException('Telethon session has no stored auth key (not logged in?).');
        }

        $authKey = (string) $row['auth_key'];
        if (strlen($authKey) !== 256) {
            throw new \RuntimeException('Telethon auth key is not 256 bytes - session is unusable.');
        }

        return new ForeignSession(
            dcId: (int) $row['dc_id'],
            authKey: $authKey,
            source: $this->name(),
            peers: $this->readEntities($pdo),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readEntities(\PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT id, hash, username, phone, name FROM entities')
                ->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }

        $peers = [];
        foreach ($rows as $row) {
            $marked = (int) ($row['id'] ?? 0);
            if ($marked === 0) {
                continue;
            }

            $peers[] = ForeignSession::makePeer(
                markedId: $marked,
                accessHash: (int) ($row['hash'] ?? 0),
                sourceType: null,
                username: $row['username'] ?? null,
                phone: $row['phone'] ?? null,
                firstName: $row['name'] ?? null,
            );
        }

        return $peers;
    }
}
