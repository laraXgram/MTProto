<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\Crypto\NativeCrypto;

/**
 * File-based session storage.
 *
 * Stores session data in a local file using JSON serialization.
 * This is the default session driver - simple and requires no external dependencies.
 */
class FileSession implements SessionInterface
{
    /**
     * Session file path.
     */
    private string $filePath;

    /**
     * Crypto instance for auth key ID calculation.
     */
    private NativeCrypto $crypto;

    /**
     * Authorization key (2048 bits = 256 bytes).
     */
    private ?string $authKey = null;

    /**
     * Auth key ID (lower 64 bits of SHA-1 of auth key).
     */
    private ?string $authKeyId = null;

    /**
     * Server salt (8 bytes).
     */
    private ?string $serverSalt = null;

    /**
     * Random session ID.
     */
    private string $sessionId;

    /**
     * Sequence number counter for content-related messages.
     */
    private int $seqNoCounter = 0;

    /**
     * Datacenter ID.
     */
    private int $dcId = 2;

    /**
     * Server time delta.
     */
    private int $timeDelta = 0;

    /**
     * Session file directory.
     */
    private string $directory;

    /**
     * Session name.
     */
    private string $name;

    /**
     * Filesystem component (LaraGram local filesystem).
     */
    private Filesystem $files;

    /**
     * Create a new file session instance.
     *
     * @param string $name Session name
     * @param string $directory Directory to store session files
     */
    public function __construct(string $name = 'default', string $directory = '', ?Filesystem $files = null)
    {
        $this->name = $name;
        $this->directory = $directory ?: sys_get_temp_dir() . '/laragram';
        $this->files = $files ?? new Filesystem();
        $this->crypto = new NativeCrypto();
        $this->filePath = $this->getFilePath($name);

        // Try to load existing session
        if ($this->loadFromFile()) {
            return;
        }

        // Generate new session ID for new sessions
        $this->sessionId = $this->crypto->randomBytes(8);
        $this->save();
    }

    /**
     * Load session data from file.
     */
    private function loadFromFile(): bool
    {
        if (!$this->files->exists($this->filePath)) {
            return false;
        }

        try {
            $content = $this->files->get($this->filePath);
        } catch (\Throwable) {
            return false;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return false;
        }

        $this->authKey = isset($data['auth_key']) ? base64_decode($data['auth_key']) : null;
        $this->authKeyId = $this->authKey ? $this->crypto->calculateAuthKeyId($this->authKey) : null;
        $this->serverSalt = isset($data['server_salt']) ? base64_decode($data['server_salt']) : null;
        $this->sessionId = isset($data['session_id']) ? base64_decode($data['session_id']) : $this->crypto->randomBytes(8);
        $this->seqNoCounter = $data['seq_no'] ?? 0;
        $this->dcId = $data['dc_id'] ?? 2;
        $this->timeDelta = $data['time_delta'] ?? 0;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $sessionId): bool
    {
        $this->name = $sessionId;
        $this->filePath = $this->getFilePath($sessionId);
        return $this->loadFromFile();
    }

    /**
     * {@inheritdoc}
     */
    public function save(): bool
    {
        $data = [
            'auth_key' => $this->authKey ? base64_encode($this->authKey) : null,
            'server_salt' => $this->serverSalt ? base64_encode($this->serverSalt) : null,
            'session_id' => base64_encode($this->sessionId),
            'seq_no' => $this->seqNoCounter,
            'dc_id' => $this->dcId,
            'time_delta' => $this->timeDelta,
            'updated_at' => time(),
        ];

        $content = json_encode($data, JSON_PRETTY_PRINT);

        // The session file holds the auth key - secret material. replace() does an
        // atomic temp-write + rename with the temp file chmod'd to 0600, so a crash
        // can't leave a torn file and the secret is never world-readable.
        try {
            $this->files->ensureDirectoryExists($this->directory, 0700);
            $this->files->replace($this->filePath, $content, 0600);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(): bool
    {
        if ($this->files->exists($this->filePath)) {
            return $this->files->delete($this->filePath);
        }

        return true;
    }

    /**
     * Destroy the session (alias for delete).
     */
    public function destroy(): bool
    {
        $result = $this->delete();

        // Reset in-memory state
        $this->authKey = null;
        $this->authKeyId = null;
        $this->serverSalt = null;
        $this->seqNoCounter = 0;

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }

    /**
     * Check if session has an auth key.
     */
    public function hasAuthKey(): bool
    {
        return $this->authKey !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function setAuthKey(string $authKey): void
    {
        $this->authKey = $authKey;
        $this->authKeyId = $this->crypto->calculateAuthKeyId($authKey);
        $this->save();
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthKeyId(): ?string
    {
        return $this->authKeyId;
    }

    /**
     * {@inheritdoc}
     */
    public function getServerSalt(): ?string
    {
        return $this->serverSalt;
    }

    /**
     * {@inheritdoc}
     */
    public function setServerSalt(string $salt): void
    {
        $this->serverSalt = $salt;
        $this->save();
    }

    /**
     * {@inheritdoc}
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * {@inheritdoc}
     */
    public function regenerateSessionId(): string
    {
        $this->sessionId = $this->crypto->randomBytes(8);
        $this->seqNoCounter = 0;
        $this->save();

        return $this->sessionId;
    }

    /**
     * {@inheritdoc}
     */
    public function getSeqNo(bool $contentRelated = true): int
    {
        $seqNo = $this->seqNoCounter * 2;

        if ($contentRelated) {
            $seqNo++;
            $this->seqNoCounter++;
        }

        return $seqNo;
    }

    /**
     * {@inheritdoc}
     */
    public function getDcId(): int
    {
        return $this->dcId;
    }

    /**
     * {@inheritdoc}
     */
    public function setDcId(int $dcId): void
    {
        $this->dcId = $dcId;
        $this->save();
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeDelta(): int
    {
        return $this->timeDelta;
    }

    /**
     * {@inheritdoc}
     */
    public function setTimeDelta(int $delta): void
    {
        $this->timeDelta = $delta;
        $this->save();
    }

    /**
     * Get the session file path for a given session ID.
     */
    private function getFilePath(string $sessionId): string
    {
        // Sanitize session ID for use in filename
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionId);

        return $this->directory . '/' . $safeId . '.session';
    }

    /**
     * Set session directory.
     */
    public function setDirectory(string $directory): void
    {
        $this->directory = $directory;
    }

    /**
     * Get current server time (adjusted by delta).
     */
    public function getServerTime(): int
    {
        return time() + $this->timeDelta;
    }

    /**
     * Last message ID for uniqueness.
     */
    private int $lastMsgId = 0;

    /**
     * Generate a message ID based on current server time.
     * {@inheritdoc}
     */
    public function generateMessageId(): int
    {
        $time = microtime(true) + $this->timeDelta;

        // msg_id = (time * 2^32) with lower 2 bits for client messages = 00
        $msgId = (int) ($time * (1 << 32));

        // Ensure lower 2 bits are 0 (client message)
        $msgId = ($msgId >> 2) << 2;

        // Ensure uniqueness - if same as last, increment by 4 (keeping lower 2 bits = 00)
        if ($msgId <= $this->lastMsgId) {
            $msgId = $this->lastMsgId + 4;
        }

        $this->lastMsgId = $msgId;

        return $msgId;
    }
}
