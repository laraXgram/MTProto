<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

/**
 * Session storage interface for MTProto sessions.
 * 
 * Handles persistent storage of:
 * - Authorization keys
 * - Server salts
 * - Session state
 * - DC information
 */
interface SessionInterface
{
    /**
     * Load session data.
     *
     * @param string $sessionId Session identifier
     * @return bool True if session exists and was loaded
     */
    public function load(string $sessionId): bool;

    /**
     * Save session data.
     *
     * @return bool True on success
     */
    public function save(): bool;

    /**
     * Delete session data.
     *
     * @return bool True on success
     */
    public function delete(): bool;

    /**
     * Get authorization key.
     *
     * @return string|null 2048-bit auth key or null if not set
     */
    public function getAuthKey(): ?string;

    /**
     * Set authorization key.
     *
     * @param string $authKey 2048-bit auth key
     */
    public function setAuthKey(string $authKey): void;

    /**
     * Get auth key ID (lower 64 bits of SHA-1 of auth key).
     *
     * @return string|null 8-byte key ID or null if auth key not set
     */
    public function getAuthKeyId(): ?string;

    /**
     * Get server salt.
     *
     * @return string|null 8-byte server salt
     */
    public function getServerSalt(): ?string;

    /**
     * Set server salt.
     *
     * @param string $salt 8-byte server salt
     */
    public function setServerSalt(string $salt): void;

    /**
     * Get session ID.
     *
     * @return string 8-byte random session ID
     */
    public function getSessionId(): string;

    /**
     * Generate new session ID.
     *
     * @return string New 8-byte session ID
     */
    public function regenerateSessionId(): string;

    /**
     * Get current sequence number for content-related messages.
     *
     * @param bool $contentRelated Is this a content-related message?
     * @return int Current seqno
     */
    public function getSeqNo(bool $contentRelated = true): int;

    /**
     * Increment and get sequence number.
     *
     * @param bool $contentRelated Is this a content-related message?
     * @return int Message sequence number
     */
    public function nextSeqNo(bool $contentRelated): int;

    /**
     * Generate a unique message ID.
     *
     * @return int Message ID based on server time
     */
    public function generateMessageId(): int;

    /**
     * Get current datacenter ID.
     *
     * @return int DC ID
     */
    public function getDcId(): int;

    /**
     * Set datacenter ID.
     *
     * @param int $dcId DC ID
     */
    public function setDcId(int $dcId): void;

    /**
     * Get server time delta.
     *
     * @return int Time difference in seconds
     */
    public function getTimeDelta(): int;

    /**
     * Set server time delta.
     *
     * @param int $delta Time difference in seconds
     */
    public function setTimeDelta(int $delta): void;
}
