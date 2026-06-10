<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session;

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
     * Create a new file session instance.
     *
     * @param string $name Session name
     * @param string $directory Directory to store session files
     */
    public function __construct(string $name = 'default', string $directory = '')
    {
        $this->name = $name;
        $this->directory = $directory ?: sys_get_temp_dir() . '/laragram';
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
        if (!file_exists($this->filePath)) {
            return false;
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
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
        // Ensure directory exists
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0700, true)) {
                return false;
            }
        }

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

        // The session file holds the auth key — secret material. Write to a temp
        // file then atomically rename so a crash can't leave a torn file, and
        // restrict perms to the owner (default umask would leave it 0644).
        $tmpPath = $this->filePath . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            return false;
        }

        @chmod($tmpPath, 0600);

        if (!rename($tmpPath, $this->filePath)) {
            @unlink($tmpPath);
            return false;
        }

        @chmod($this->filePath, 0600);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(): bool
    {
        if (file_exists($this->filePath)) {
            return unlink($this->filePath);
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
