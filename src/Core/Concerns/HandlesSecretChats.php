<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Secret\SecretChatManager;
use LaraGram\MTProto\Store\FileStore;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesSecretChats
{
    private ?SecretChatManager $secretChats = null;

    /**
     * The lazily-built secret-chat manager for this client.
     */
    public function secretChats(): SecretChatManager
    {
        if ($this->secretChats === null) {
            $store = new FileStore($this->getSessionDir(), '.secret', $this->getFiles());
            $this->secretChats = new SecretChatManager($this, $store);
        }

        return $this->secretChats;
    }

    /**
     * Start a secret chat with a user (returns the pending EncryptedChat).
     */
    public function startSecretChat(int $userId): array
    {
        return $this->secretChats()->request($userId);
    }

    /**
     * Send a text message into an established secret chat.
     */
    public function sendSecretMessage(int $chatId, string $text, int $ttl = 0): array
    {
        return $this->secretChats()->sendMessage($chatId, $text, $ttl);
    }

    /**
     * Handle an `updateEncryption` chat object: auto-accept an incoming request
     * or finalize our own once the peer accepts.
     */
    public function handleSecretEncryption(array $chat): ?array
    {
        return $this->secretChats()->handleUpdateEncryption($chat);
    }

    /**
     * Decrypt + parse an incoming `encryptedMessage`.
     *
     * @return array{chat_id:int,message:array}|null
     */
    public function decryptSecret(array $encryptedMessage): ?array
    {
        return $this->secretChats()->receive($encryptedMessage);
    }
}
