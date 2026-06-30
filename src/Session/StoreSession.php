<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session;

use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Crypto\NativeCrypto;

class StoreSession implements SessionInterface
{
    private NativeCrypto $crypto;

    private ?string $authKey = null;
    private ?string $authKeyId = null;
    private ?string $serverSalt = null;
    private string $sessionId;
    private int $seqNoCounter = 0;
    private int $dcId = 2;
    private int $timeDelta = 0;
    private int $lastMsgId = 0;

    /**
     * @param string $name
     */
    public function __construct(
        private string $name,
        private Store  $store,
    )
    {
        $this->crypto = new NativeCrypto();

        if ($this->loadFromStore()) {
            return;
        }

        // Fresh session.
        $this->sessionId = $this->crypto->randomBytes(8);
        $this->save();
    }

    private function loadFromStore(): bool
    {
        $content = $this->store->get($this->name);

        if ($content === null) {
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

    public function load(string $sessionId): bool
    {
        $this->name = $sessionId;

        return $this->loadFromStore();
    }

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

        try {
            $this->store->put($this->name, json_encode($data, JSON_PRETTY_PRINT));
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function delete(): bool
    {
        return $this->store->forget($this->name);
    }

    public function destroy(): bool
    {
        $result = $this->delete();

        $this->authKey = null;
        $this->authKeyId = null;
        $this->serverSalt = null;
        $this->seqNoCounter = 0;

        return $result;
    }

    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }

    public function hasAuthKey(): bool
    {
        return $this->authKey !== null;
    }

    public function setAuthKey(string $authKey): void
    {
        $this->authKey = $authKey;
        $this->authKeyId = $this->crypto->calculateAuthKeyId($authKey);
        $this->save();
    }

    public function getAuthKeyId(): ?string
    {
        return $this->authKeyId;
    }

    public function getServerSalt(): ?string
    {
        return $this->serverSalt;
    }

    public function setServerSalt(string $salt): void
    {
        $this->serverSalt = $salt;
        $this->save();
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function regenerateSessionId(): string
    {
        $this->sessionId = $this->crypto->randomBytes(8);
        $this->seqNoCounter = 0;
        $this->save();

        return $this->sessionId;
    }

    public function getSeqNo(bool $contentRelated = true): int
    {
        $seqNo = $this->seqNoCounter * 2;

        if ($contentRelated) {
            $seqNo++;
            $this->seqNoCounter++;
        }

        return $seqNo;
    }

    public function getDcId(): int
    {
        return $this->dcId;
    }

    public function setDcId(int $dcId): void
    {
        $this->dcId = $dcId;
        $this->save();
    }

    public function getTimeDelta(): int
    {
        return $this->timeDelta;
    }

    public function setTimeDelta(int $delta): void
    {
        $this->timeDelta = $delta;
        $this->save();
    }

    public function getServerTime(): int
    {
        return time() + $this->timeDelta;
    }

    public function generateMessageId(): int
    {
        $time = microtime(true) + $this->timeDelta;

        $msgId = (int)($time * (1 << 32));
        $msgId = ($msgId >> 2) << 2;

        if ($msgId <= $this->lastMsgId) {
            $msgId = $this->lastMsgId + 4;
        }

        $this->lastMsgId = $msgId;

        return $msgId;
    }
}
