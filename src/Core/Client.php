<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Auth\AuthKeyGenerator;
use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Crypto\NativeCrypto;
use LaraGram\MTProto\Driver\Sync\SyncConnection;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\RPC\RPCHandler;
use LaraGram\MTProto\Session\FileSession;
use LaraGram\MTProto\TL\TLParser;
use LaraGram\MTProto\Generated\ClientMethods;
use LaraGram\MTProto\Transport\AbridgedTransport;

/**
 * MTProto Client — the single entry-point for Telegram API calls.
 *
 * Usage:
 *   $client = new Client(API_ID, API_HASH, ['session_dir' => './sessions']);
 *   $client->connect('user_dc4');          // loads existing session
 *   $me = $client->invoke('users.getFullUser', ['id' => ['_' => 'inputUserSelf']]);
 *   // or fluent:
 *   $me = $client->users->getFullUser(['id' => ['_' => 'inputUserSelf']]);
 *   $client->disconnect();
 *
 * @mixin \LaraGram\MTProto\Generated\ClientIdeHelper
 */
class Client
{
    use ClientMethods;

    public const VERSION = '1.0.0-dev';
    public const LAYER   = 214;

    // ── components ─────────────────────────────────────────────────────
    private ConnectionInterface  $connection;
    private TransportInterface   $transport;
    private CryptoInterface      $crypto;
    private SessionInterface     $session;
    private ?EventLoopInterface  $eventLoop         = null;
    private ?RPCHandler          $rpc               = null;
    private ?TLParser            $tlParser          = null;
    private ?PeerDatabase        $peerDb            = null;
    private ?PeerResolver        $peerResolver      = null;
    private ?ParamPreprocessor   $paramPreprocessor = null;

    // ── config ─────────────────────────────────────────────────────────
    private int    $apiId;
    private string $apiHash;
    private int    $dcId       = 2;
    private bool   $testMode   = false;
    private bool   $ipv6       = false;
    private float  $timeout    = 10.0;
    private string $sessionDir = './sessions';

    // ── state ──────────────────────────────────────────────────────────
    private bool   $connected   = false;
    private string $sessionName = '';

    // ================================================================
    //  Construction
    // ================================================================

    /**
     * @param int    $apiId   Telegram API ID
     * @param string $apiHash Telegram API Hash
     * @param array  $options {
     *     @type int    $dc_id       Initial DC (default 2)
     *     @type bool   $test_mode   Use test servers
     *     @type bool   $ipv6        Use IPv6
     *     @type float  $timeout     Connection timeout
     *     @type string $session_dir Directory for session files
     *     @type ConnectionInterface  $connection  Custom connection driver
     *     @type TransportInterface   $transport   Custom transport
     *     @type CryptoInterface      $crypto      Custom crypto
     *     @type EventLoopInterface   $event_loop  Event loop (auto-selects connection type)
     * }
     */
    public function __construct(int $apiId, string $apiHash, array $options = [])
    {
        $this->apiId      = $apiId;
        $this->apiHash    = $apiHash;
        $this->dcId       = $options['dc_id']       ?? 2;
        $this->testMode   = $options['test_mode']   ?? false;
        $this->ipv6       = $options['ipv6']         ?? false;
        $this->timeout    = $options['timeout']      ?? 10.0;
        $this->sessionDir = $options['session_dir']  ?? './sessions';

        $this->eventLoop  = $options['event_loop'] ?? null;
        $this->connection = $options['connection'] ?? new SyncConnection();
        $this->transport  = $options['transport']  ?? new AbridgedTransport();
        $this->crypto     = $options['crypto']     ?? new NativeCrypto();
    }

    // ================================================================
    //  Connection lifecycle
    // ================================================================

    /**
     * Connect to Telegram using an existing (or fresh) session.
     *
     * If the session file already contains an auth key the client is ready
     * for API calls immediately — no login required.
     *
     * @param string $sessionName  e.g. "user_dc4"
     * @return bool  true when session had an auth key (ready for API calls)
     */
    public function connect(string $sessionName = 'default'): bool
    {
        if ($this->connected) {
            $this->disconnect();
        }

        $this->sessionName = $sessionName;

        // Load / create session
        if (!is_dir($this->sessionDir)) {
            mkdir($this->sessionDir, 0700, true);
        }
        $this->session = new FileSession($sessionName, $this->sessionDir);

        // Read DC from session (the DC where we last authenticated)
        $storedDc = $this->session->getDcId();
        if ($storedDc >= 1 && $storedDc <= 5) {
            $this->dcId = $storedDc;
        }

        // Initialise peer database (lives alongside the session file)
        $this->peerDb = new PeerDatabase($this->sessionDir, $sessionName);

        // TCP connect to the right DC
        $this->connectTcp();

        // Generate auth key if this is a brand-new session
        if ($this->session->getAuthKey() === null) {
            $this->generateAuthKey();
        } else {
            // Existing session: regenerate session ID so seqno starts fresh at 0.
            // The server will respond with new_session_created + a fresh salt.
            $this->session->regenerateSessionId();
        }

        $this->connected = true;

        return $this->session->getAuthKey() !== null;
    }

    /**
     * Disconnect and persist session.
     */
    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        try { $this->session->save(); } catch (\Throwable) {}
        try { $this->peerDb?->save(); } catch (\Throwable) {}
        try { $this->connection->disconnect(); } catch (\Throwable) {}

        $this->rpc              = null;
        $this->peerResolver     = null;
        $this->paramPreprocessor = null;
        $this->resetNamespaces();
        $this->connected = false;
    }

    /**
     * Create a new TCP connection reusing the existing auth key.
     *
     * The old connection object is abandoned (not closed) so callers
     * holding a reference to the previous socket are unaffected.
     */
    public function reconnect(): void
    {
        // Create a brand-new connection (the old one is simply discarded).
        $this->connection = new SyncConnection();
        $this->connectTcp();

        // Regenerate session ID so seqno starts fresh
        $this->session->regenerateSessionId();

        // Reset RPC handler — it will be lazily re-created with
        // the new connection on the next API call
        $this->rpc              = null;
        $this->peerResolver     = null;
        $this->paramPreprocessor = null;
        $this->resetNamespaces();
    }

    /**
     * Switch to another DC (called automatically on PHONE_MIGRATE etc.).
     */
    public function switchDc(int $newDcId): void
    {
        if (!DataCenter::isValidDcId($newDcId)) {
            throw new MTProtoException("Invalid DC: {$newDcId}");
        }

        // Close current connection
        try { $this->connection->disconnect(); } catch (\Throwable) {}

        $this->rpc              = null;
        $this->peerResolver     = null;
        $this->paramPreprocessor = null;
        $this->resetNamespaces();
        $this->dcId = $newDcId;

        // Build a new session name: user_dc2 → user_dc4
        $newName = preg_replace('/dc\d+/', "dc{$newDcId}", $this->sessionName);
        if ($newName === null || $newName === $this->sessionName) {
            $newName = "user_dc{$newDcId}";
        }
        $this->sessionName = $newName;
        $this->session = new FileSession($newName, $this->sessionDir);

        // New TCP socket
        $this->connection = new SyncConnection();
        $this->connectTcp();

        if ($this->session->getAuthKey() === null) {
            $this->generateAuthKey();
        } else {
            $this->session->regenerateSessionId();
        }

        $this->session->setDcId($newDcId);
        $this->session->save();
    }

    // ================================================================
    //  Invoke — the single public API for every Telegram method
    // ================================================================

    /**
     * Call any Telegram API method.
     *
     * Handles automatically:
     *  - Parameter preprocessing (auto InputPeer, random_id, reply_to, …)
     *  - Connection initialisation (Ping + InvokeWithLayer + InitConnection)
     *  - DC migration  (PHONE_MIGRATE_X / USER_MIGRATE_X / FILE_MIGRATE_X)
     *  - Bad server salt  (auto-retry with new salt)
     *  - Peer caching from every response
     *
     * @param  string $method  e.g. "account.updateProfile"
     * @param  array  $params  Method parameters (simplified values accepted)
     * @return mixed  Deserialized TL result (array, bool, int, etc.)
     * @throws MTProtoException
     */
    public function invoke(string $method, array $params = []): mixed
    {
        $this->ensureConnected();

        // ── Preprocess parameters ──────────────────────────────────────
        $params = $this->getPreprocessor()->process($method, $params);

        // ── Send RPC ───────────────────────────────────────────────────
        $result = $this->invokeRaw($method, $params);

        // ── Cache peers from response ──────────────────────────────────
        if ($this->peerDb !== null && is_array($result)) {
            $this->peerDb->cachePeersFromResponse($result);
            $this->peerDb->save();
        }

        return $result;
    }

    /**
     * Low-level invoke — no parameter preprocessing, no peer caching.
     *
     * Used internally by PeerResolver to avoid infinite recursion when
     * it needs to call contacts.resolveUsername / users.getUsers etc.
     *
     * @internal
     */
    public function invokeRaw(string $method, array $params = []): mixed
    {
        $this->ensureConnected();

        try {
            $result = $this->getRpc()->invoke($method, $params);
        } catch (MTProtoException $e) {
            // Auto DC-migration
            if (preg_match('/(PHONE|USER|FILE|NETWORK)_MIGRATE_(\d+)/', $e->getMessage(), $m)) {
                $targetDc = (int) $m[2];
                $this->switchDc($targetDc);
                $result = $this->getRpc()->invoke($method, $params);
            } else {
                throw $e;
            }
        }

        // Auto-convert Bool results to native PHP bool
        if (is_array($result) && isset($result['_'])) {
            if ($result['_'] === 'boolTrue')  return true;
            if ($result['_'] === 'boolFalse') return false;
        }

        // Cache peers from raw invocations too (e.g. PeerResolver calls)
        if ($this->peerDb !== null && is_array($result)) {
            $this->peerDb->cachePeersFromResponse($result);
        }

        return $result;
    }

    // ================================================================
    //  Getters
    // ================================================================

    public function getApiId():        int                 { return $this->apiId;    }
    public function getApiHash():      string              { return $this->apiHash;  }
    public function getDcId():         int                 { return $this->dcId;     }
    public function getSession():      SessionInterface    { return $this->session;  }
    public function getCrypto():       CryptoInterface     { return $this->crypto;   }
    public function isReady():         bool                { return $this->connected && $this->connection->isConnected(); }
    public function getConnection():   ConnectionInterface { return $this->connection; }
    public function getTransport():    TransportInterface  { return $this->transport;  }
    public function getTlParser():     ?TLParser           { return $this->tlParser;   }
    public function getPeerDatabase(): ?PeerDatabase       { return $this->peerDb;     }
    public function getResolver():     ?PeerResolver       { return $this->peerResolver; }
    public function getSessionDir():   string              { return $this->sessionDir;   }
    public function getSessionName():  string              { return $this->sessionName;  }
    public function getRpcHandler():   ?RPCHandler         { return $this->rpc;          }
    public function getEventLoop():    ?EventLoopInterface { return $this->eventLoop;    }

    /**
     * Attach an event loop to this client.
     */
    public function setEventLoop(EventLoopInterface $eventLoop): void
    {
        $this->eventLoop = $eventLoop;
    }

    // ================================================================
    //  Internals
    // ================================================================

    private function ensureConnected(): void
    {
        if (!$this->connected) {
            throw new MTProtoException('Not connected — call connect() first');
        }
    }

    /**
     * Lazily build the ParamPreprocessor (and its PeerResolver dependency).
     */
    private function getPreprocessor(): ParamPreprocessor
    {
        if ($this->paramPreprocessor !== null) {
            return $this->paramPreprocessor;
        }

        // Ensure TL parser + RPC are ready
        $this->getRpc();

        $this->peerResolver = new PeerResolver($this->peerDb, $this);
        $this->paramPreprocessor = new ParamPreprocessor($this->peerResolver, $this->tlParser);

        return $this->paramPreprocessor;
    }

    /**
     * Lazily build + initialise the RPCHandler.
     */
    private function getRpc(): RPCHandler
    {
        if ($this->rpc !== null) {
            return $this->rpc;
        }

        // Parse TL schemas once
        if ($this->tlParser === null) {
            $this->tlParser = new TLParser();
            $schemasDir = __DIR__ . '/../TL/schemas';
            foreach (['mtproto.tl', 'auth_key.tl', 'sys_msgs.tl', 'main_api.tl'] as $file) {
                $path = $schemasDir . '/' . $file;
                if (file_exists($path)) {
                    $this->tlParser->parseFile($path);
                }
            }
        }

        $this->rpc = new RPCHandler(
            $this->connection,
            $this->transport,
            $this->crypto,
            $this->session,
            $this->tlParser,
            $this->apiId,
            $this->apiHash,
        );

        $this->rpc->initializeConnection();

        return $this->rpc;
    }

    /**
     * Open a raw TCP connection to the current DC.
     */
    private function connectTcp(): void
    {
        $addr = DataCenter::getAddress($this->dcId, $this->testMode, $this->ipv6);
        $this->connection->connect($addr, DataCenter::DEFAULT_PORT, $this->timeout);
        $init = $this->transport->getInitialBytes();
        if ($init !== '') {
            $this->connection->send($init);
        }
    }

    /**
     * Generate an authorization key for the current DC.
     */
    private function generateAuthKey(): void
    {
        $gen = new AuthKeyGenerator(
            $this->connection,
            $this->transport,
            $this->crypto,
            $this->dcId,
            $this->testMode,
        );
        $result = $gen->generate();
        $this->session->setAuthKey($result['auth_key']);
        $this->session->setServerSalt($result['server_salt']);
        $this->session->setDcId($this->dcId);
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
