<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Auth\AuthKeyGenerator;
use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\RateLimiterInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Crypto\NativeCrypto;
use LaraGram\MTProto\Driver\Sync\SyncConnection;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\RPC\RPCHandler;
use LaraGram\MTProto\RPC\MessagePump;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\Runtime\SwooleRuntime;
use LaraGram\Filesystem\Filesystem;
use LaraGram\Filesystem\Mime\MimeTypes;
use LaraGram\MTProto\Foundation\FileUploader;
use LaraGram\MTProto\Foundation\InputMedia;
use LaraGram\Log\LoggerInterface;
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
    public const LAYER   = 227;

    // ── components ─────────────────────────────────────────────────────
    private ConnectionInterface  $connection;
    private TransportInterface   $transport;
    private CryptoInterface      $crypto;
    private SessionInterface     $session;
    private ?EventLoopInterface  $eventLoop         = null;
    private ?RPCHandler          $rpc               = null;
    private ?MessagePump         $pump              = null;
    private ?TLParser            $tlParser          = null;
    private ?PeerDatabase        $peerDb            = null;
    private ?PeerResolver        $peerResolver      = null;
    private ?ParamPreprocessor   $paramPreprocessor = null;

    // ── config ─────────────────────────────────────────────────────────
    private int    $apiId;
    private string $apiHash;
    private ?DeviceProfile $deviceProfile = null;
    private ?MimeTypes $mimeTypes = null;
    private ?RateLimiterInterface $rateLimiter = null;
    private bool  $rateLimitEnabled = false;
    /** @var array<string, array{rate?: float, capacity?: float}> */
    private array $rateLimits = [];
    private int    $dcId            = 2;
    private bool   $testMode        = false;
    private bool   $ipv6            = false;
    private float  $timeout         = 10.0;
    private string $sessionDir      = './sessions';
    private int    $layer           = self::LAYER;
    private bool   $floodSleep      = true;
    private int    $floodSleepLimit = 60;
    private int    $maxRetries      = 5;
    private bool   $usePump         = false;
    private ?LoggerInterface $logger;
    private Filesystem $files;
    private ?Runtime $runtime;

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
     *     @type int    $layer       MTProto API layer (default Client::LAYER; must match compiled schema)
     *     @type bool   $flood_sleep       Auto-sleep & retry on FLOOD_WAIT (default true)
     *     @type int    $flood_sleep_limit Max FLOOD_WAIT seconds to wait before rethrowing (default 60)
     *     @type int    $max_retries       Max retries for transient/migrate errors (default 5)
     *     @type ConnectionInterface  $connection  Custom connection driver
     *     @type TransportInterface   $transport   Custom transport
     *     @type CryptoInterface      $crypto      Custom crypto
     *     @type EventLoopInterface   $event_loop  Event loop (auto-selects connection type)
     * }
     */
    public function __construct(int $apiId, string $apiHash, array $options = [])
    {
        $this->apiId           = $apiId;
        $this->apiHash         = $apiHash;
        $this->deviceProfile   = ($options['device'] ?? null) instanceof DeviceProfile
            ? $options['device']
            : DeviceProfile::resolve(is_array($options['device'] ?? null) ? $options['device'] : []);
        $this->rateLimiter     = $options['rate_limiter'] ?? null;
        $this->rateLimits      = (array) ($options['rate_limits'] ?? []);
        $this->rateLimitEnabled = (bool) ($this->rateLimits['enabled'] ?? ($this->rateLimiter !== null));
        $this->dcId            = $options['dc_id']             ?? 2;
        $this->testMode        = $options['test_mode']         ?? false;
        $this->ipv6            = $options['ipv6']              ?? false;
        $this->timeout         = $options['timeout']           ?? 10.0;
        $this->sessionDir      = $options['session_dir']       ?? './sessions';
        $this->layer           = (int) ($options['layer']      ?? self::LAYER);
        $this->floodSleep      = (bool) ($options['flood_sleep']       ?? true);
        $this->floodSleepLimit = (int) ($options['flood_sleep_limit']  ?? 60);
        $this->maxRetries      = (int) ($options['max_retries']        ?? 5);
        $this->usePump         = (bool) ($options['use_pump']          ?? false);
        $this->logger          = $options['logger'] ?? $this->resolveDefaultLogger();
        $this->files           = $options['files'] ?? new Filesystem();
        $this->runtime         = $options['runtime'] ?? null;

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
        $this->files->ensureDirectoryExists($this->sessionDir, 0700);
        $this->session = new FileSession($sessionName, $this->sessionDir, $this->files);

        // Read DC from session (the DC where we last authenticated)
        $storedDc = $this->session->getDcId();
        if ($storedDc >= 1 && $storedDc <= 5) {
            $this->dcId = $storedDc;
        }

        // Initialise peer database (lives alongside the session file)
        $this->peerDb = new PeerDatabase($this->sessionDir, $sessionName, $this->files);

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
        try { $this->pump?->stop(); } catch (\Throwable) {}
        try { $this->connection->disconnect(); } catch (\Throwable) {}

        $this->rpc              = null;
        $this->pump             = null;
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
        $this->pump             = null;
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
        try { $this->pump?->stop(); } catch (\Throwable) {}
        try { $this->connection->disconnect(); } catch (\Throwable) {}

        $this->rpc              = null;
        $this->pump             = null;
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
        $this->session = new FileSession($newName, $this->sessionDir, $this->files);

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
    /**
     * Upload a local file and return the `inputFile`/`inputFileBig` constructor
     * to hand to an `InputMedia*` builder. Splits into 512 KiB parts; small
     * files (≤10 MiB) carry an md5 checksum, big files use the big-file route.
     *
     * @param  callable|null  $progress  fn(int $partsDone, int $partsTotal): void
     */
    public function uploadFile(string $path, ?string $fileName = null, ?callable $progress = null): array
    {
        return (new FileUploader($this, $this->files))->fromPath($path, $fileName, $progress);
    }

    /**
     * Upload raw bytes (same return shape as {@see uploadFile}).
     *
     * @param  callable|null  $progress  fn(int $partsDone, int $partsTotal): void
     */
    public function uploadBytes(string $contents, string $fileName, ?callable $progress = null): array
    {
        return (new FileUploader($this, $this->files))->fromString($contents, $fileName, $progress);
    }

    /**
     * Send an uploaded photo. Extra `messages.sendMedia` params (parse_mode,
     * reply_to_msg_id, reply_markup, silent, …) pass through $params.
     */
    public function sendPhoto(string|int $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        return $this->sendUploadedMedia(
            $peer,
            InputMedia::uploadedPhoto($this->uploadFile($path)),
            $message,
            $params,
        );
    }

    /**
     * Send an uploaded file as a document. Pass `file_name` in $params to
     * override the on-wire name.
     */
    public function sendDocument(string|int $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        $name = $params['file_name'] ?? $this->files->basename($path);
        unset($params['file_name']);

        $media = InputMedia::uploadedDocument(
            $this->uploadFile($path, $name),
            $this->guessMimeType($path),
            [InputMedia::attrFilename($name)],
            forceFile: true,
        );

        return $this->sendUploadedMedia($peer, $media, $message, $params);
    }

    /**
     * Send an uploaded video. Recognised $params: duration, w, h.
     */
    public function sendVideo(string|int $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        $name = $params['file_name'] ?? $this->files->basename($path);
        $duration = (int) ($params['duration'] ?? 0);
        $w = (int) ($params['w'] ?? 0);
        $h = (int) ($params['h'] ?? 0);
        unset($params['file_name'], $params['duration'], $params['w'], $params['h']);

        $media = InputMedia::uploadedDocument(
            $this->uploadFile($path, $name),
            $this->guessMimeType($path) ?: 'video/mp4',
            [
                InputMedia::attrVideo($duration, $w, $h, supportsStreaming: true),
                InputMedia::attrFilename($name),
            ],
        );

        return $this->sendUploadedMedia($peer, $media, $message, $params);
    }

    /**
     * Send an uploaded audio track. Recognised $params: duration, title, performer.
     */
    public function sendAudio(string|int $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        $name = $params['file_name'] ?? $this->files->basename($path);
        $attr = InputMedia::attrAudio(
            (int) ($params['duration'] ?? 0),
            $params['title'] ?? null,
            $params['performer'] ?? null,
        );
        unset($params['file_name'], $params['duration'], $params['title'], $params['performer']);

        $media = InputMedia::uploadedDocument(
            $this->uploadFile($path, $name),
            $this->guessMimeType($path) ?: 'audio/mpeg',
            [$attr, InputMedia::attrFilename($name)],
        );

        return $this->sendUploadedMedia($peer, $media, $message, $params);
    }

    /**
     * Send an uploaded voice note (single audio attribute, voice flag set).
     */
    public function sendVoice(string|int $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        $duration = (int) ($params['duration'] ?? 0);
        unset($params['duration'], $params['file_name']);

        $media = InputMedia::uploadedDocument(
            $this->uploadFile($path),
            $this->guessMimeType($path) ?: 'audio/ogg',
            [InputMedia::attrAudio($duration, voice: true)],
        );

        return $this->sendUploadedMedia($peer, $media, $message, $params);
    }

    /**
     * Build and dispatch a messages.sendMedia for an already-built InputMedia.
     */
    private function sendUploadedMedia(string|int $peer, array $media, ?string $message, array $params): mixed
    {
        return $this->invoke('messages.sendMedia', array_merge([
            'peer'    => $peer,
            'media'   => $media,
            'message' => $message ?? '',
        ], $params));
    }

    /**
     * Resolve a file's MIME type via LaraGram's MimeTypes service.
     *
     * The filename extension is authoritative for an upload (it's the user's
     * stated intent and what Telegram clients key off), so it wins. For
     * extensionless files we fall back to content sniffing, ignoring an
     * inconclusive "application/octet-stream" sniff.
     */
    private function guessMimeType(string $path): string
    {
        $mimeTypes = $this->mimeTypes ??= new MimeTypes();

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && ($byExt = $mimeTypes->getMimeTypes($ext)[0] ?? null) !== null) {
            return $byExt;
        }

        if ($this->files->exists($path) && $mimeTypes->isGuesserSupported()) {
            $guessed = $mimeTypes->guessMimeType($path);
            if (is_string($guessed) && $guessed !== '' && $guessed !== 'application/octet-stream') {
                return $guessed;
            }
        }

        return 'application/octet-stream';
    }

    public function invokeRaw(string $method, array $params = []): mixed
    {
        $this->ensureConnected();

        // Proactively pace the send before it leaves the process (B2). Done once
        // here, outside the retry loop, so retries don't double-charge buckets.
        $this->throttle($method, $params);

        // Bounded retry loop. Recoverable conditions (DC migration, FLOOD_WAIT,
        // transient server/network errors, AUTH_KEY_DUPLICATED) loop back and
        // re-issue the call; everything else propagates to the caller. Counters
        // are bounded so a persistently-failing server can never spin forever.
        $migrations = 0;
        $retries    = 0;

        while (true) {
            try {
                $result = ($this->pump !== null && $this->pump->isRunning())
                    ? $this->pump->invoke($method, $params)
                    : $this->getRpc()->invoke($method, $params);
                break;
            } catch (MTProtoException $e) {
                $message = $e->getMessage();

                // ── Auto DC-migration (PHONE/USER/FILE/NETWORK_MIGRATE_x) ──
                if (preg_match('/(PHONE|USER|FILE|NETWORK)_MIGRATE_(\d+)/', $message, $m)) {
                    if (++$migrations > 5) {
                        throw $e;
                    }
                    $this->switchDc((int) $m[2]);
                    continue;
                }

                // ── FLOOD_WAIT / SLOWMODE_WAIT auto-sleep ──────────────────
                // Telegram tells us exactly how long to back off. If it is within
                // the configured limit, coroutine-sleep (yields under Swoole's
                // SWOOLE_HOOK_ALL) and retry; otherwise let the caller decide.
                $wait = $this->parseWaitSeconds($message);
                if ($wait !== null) {
                    if ($this->floodSleep && $wait <= $this->floodSleepLimit) {
                        // Add sub-second jitter so resumes don't land on a fixed
                        // cadence (B4) — many accounts resuming at the exact same
                        // offset is itself a detectable pattern.
                        $jitter = mt_rand(0, 1000) / 1000.0;
                        $this->logger?->info("{$method}: flood wait {$wait}s — sleeping then retrying");
                        $this->backoffSleep($wait + $jitter);
                        continue;
                    }
                    throw $e; // above flood_sleep_limit — surface to caller
                }

                // ── AUTH_KEY_DUPLICATED — auth key reused on another conn ──
                // The session id must be dropped and the connection re-handshaked.
                if (str_contains($message, 'AUTH_KEY_DUPLICATED')) {
                    if (++$retries > $this->maxRetries) {
                        throw $e;
                    }
                    $this->logger?->warning("{$method}: AUTH_KEY_DUPLICATED — regenerating session");
                    $this->session->regenerateSessionId();
                    $this->reconnect();
                    continue;
                }

                // ── Transient server/network errors — exponential backoff ──
                if ($this->isTransientError($e)) {
                    if (++$retries > $this->maxRetries) {
                        throw $e;
                    }
                    // Equal-jitter exponential backoff (B4): half the window is
                    // fixed, half random, so retries avoid a fixed cadence.
                    $base  = min(2 ** ($retries - 1), 8);
                    $delay = $base / 2 + (mt_rand(0, 1000) / 1000.0) * ($base / 2);
                    $this->logger?->warning("{$method}: transient error '{$message}' — retry {$retries}/{$this->maxRetries} in " . round($delay, 2) . 's');
                    $this->backoffSleep($delay);
                    continue;
                }

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
    public function getLogger():       ?LoggerInterface    { return $this->logger;       }
    public function getFiles():        Filesystem          { return $this->files;        }
    public function getRuntime():      Runtime             { return $this->runtime ??= new SwooleRuntime(); }

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
     * Resolve the framework Log channel from the container, or null when the
     * package runs outside a booted application (bare CLI / unit tests).
     */
    private function resolveDefaultLogger(): ?LoggerInterface
    {
        if (function_exists('app')) {
            try {
                return app('mtproto.logger');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * Extract the wait seconds from a FLOOD_WAIT-style RPC error message.
     *
     * Covers FLOOD_WAIT_x, SLOWMODE_WAIT_x and FLOOD_PREMIUM_WAIT_x — all of
     * which encode the back-off duration (seconds) in the suffix.
     *
     * @return int|null  Seconds to wait, or null if the message is not a wait error.
     */
    private function parseWaitSeconds(string $message): ?int
    {
        if (preg_match('/(?:FLOOD_WAIT|SLOWMODE_WAIT|FLOOD_PREMIUM_WAIT)_(\d+)/', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Proactively pace an outgoing call (B2): draw from a global bucket and,
     * for peer-addressed methods, a per-peer bucket. Sleeps the larger wait.
     */
    private function throttle(string $method, array $params): void
    {
        if ($this->rateLimiter === null || ! $this->rateLimitEnabled) {
            return;
        }

        $g = $this->rateLimits['global'] ?? [];
        $wait = $this->rateLimiter->reserve('global', $g['rate'] ?? null, $g['capacity'] ?? null);

        if (($peer = $this->peerKey($params)) !== null) {
            $p = $this->rateLimits['per_peer'] ?? [];
            $wait = max($wait, $this->rateLimiter->reserve(
                'peer:' . $peer,
                $p['rate'] ?? 1.0,
                $p['capacity'] ?? 5.0,
            ));
        }

        if ($wait > 0.0) {
            $this->logger?->debug("{$method}: rate-limit pacing " . round($wait, 2) . 's');
            $this->backoffSleep($wait);
        }
    }

    /**
     * Derive a stable per-peer bucket key from a method's `peer` param, or null
     * when the call is not peer-addressed.
     */
    private function peerKey(array $params): ?string
    {
        $peer = $params['peer'] ?? null;
        if ($peer === null) {
            return null;
        }

        if (is_array($peer)) {
            $id = $peer['user_id'] ?? $peer['channel_id'] ?? $peer['chat_id'] ?? null;

            return $id !== null ? (string) $id : md5((string) json_encode($peer));
        }

        return (string) $peer;
    }

    /**
     * Coroutine-friendly back-off sleep. Yields under the Runtime when present
     * (Swoole hook), else falls back to a blocking usleep. Accepts fractional
     * seconds so jittered delays are honoured exactly.
     */
    private function backoffSleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->runtime !== null) {
            $this->runtime->sleep($seconds);
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * Whether an RPC error is a transient server/network failure that is safe
     * to retry after a short back-off (Telegram returns these under load).
     */
    private function isTransientError(MTProtoException $e): bool
    {
        // -500 (internal) and -503 (timeout/overload) are server-side transients.
        $code = $e->getCode();
        if ($code === -500 || $code === -503) {
            return true;
        }

        $message = $e->getMessage();
        foreach ([
            'AUTH_RESTART',
            'RPC_CALL_FAIL',
            'RPC_MCGET_FAIL',
            'WORKER_BUSY_TOO_LONG_RETRY',
            'INTERNAL_SERVER_ERROR',
            'Timeout waiting for response',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lazily build the ParamPreprocessor (and its PeerResolver dependency).
     */
    private function getPreprocessor(): ParamPreprocessor
    {
        if ($this->paramPreprocessor !== null) {
            return $this->paramPreprocessor;
        }

        // Only the TL parser is needed here. Do NOT call getRpc(): under the pump
        // it would build a second RPCHandler that reads the same socket as the
        // pump's reader (two readers → stream corruption).
        $this->ensureTlParser();

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

        $this->ensureTlParser();

        $this->rpc = new RPCHandler(
            $this->connection,
            $this->transport,
            $this->crypto,
            $this->session,
            $this->tlParser,
            $this->apiId,
            $this->apiHash,
            $this->logger,
        );

        // Keep the RPC layer in sync with the configured layer (default
        // Client::LAYER). Single source of truth — drives config('mtproto.layer').
        $this->rpc->setLayer($this->layer);
        if ($this->deviceProfile !== null) {
            $this->rpc->setDeviceProfile($this->deviceProfile);
        }

        $this->rpc->initializeConnection();

        return $this->rpc;
    }

    /**
     * Parse the MTProto + API TL schemas once into the shared TLParser.
     */
    private function ensureTlParser(): void
    {
        if ($this->tlParser !== null) {
            return;
        }

        $this->tlParser = new TLParser($this->files);
        $schemasDir = __DIR__ . '/../TL/schemas';
        foreach (['mtproto.tl', 'auth_key.tl', 'sys_msgs.tl', 'main_api.tl'] as $file) {
            $path = $schemasDir . '/' . $file;
            if (file_exists($path)) {
                $this->tlParser->parseFile($path);
            }
        }
    }

    /**
     * Build (once) the single-reader {@see MessagePump} for this client.
     *
     * Does NOT start the reader — call {@see startPump()} from inside a Swoole
     * coroutine context for that. The pump is the Phase 1 replacement for the
     * RPCHandler poll loop + UpdateLoop dup reader; while it is being validated
     * the old paths remain the default (use_pump=false).
     */
    public function getPump(): MessagePump
    {
        if ($this->pump !== null) {
            return $this->pump;
        }

        $this->ensureTlParser();

        // Runtime injected by ClientManager from the container (RULE 1); fall back
        // to a Swoole runtime when used outside a booted app.
        $this->runtime ??= new SwooleRuntime();

        $this->pump = new MessagePump(
            $this->connection,
            $this->transport,
            $this->crypto,
            $this->session,
            $this->tlParser,
            $this->runtime,
            $this->apiId,
            $this->apiHash,
            $this->logger,
        );
        $this->pump->setLayer($this->layer);
        if ($this->deviceProfile !== null) {
            $this->pump->setDeviceProfile($this->deviceProfile);
        }
        $this->pump->setReconnector(fn (): ConnectionInterface => $this->reconnectForPump());

        return $this->pump;
    }

    /**
     * Start the pump's reader/keep-alive coroutines and initialise the
     * connection through it. MUST run inside Swoole\Coroutine\run.
     *
     * After this, invoke() automatically routes through the pump.
     */
    public function startPump(): MessagePump
    {
        $this->ensureConnected();

        $pump = $this->getPump();
        $pump->start();
        $pump->initializeConnection();

        return $pump;
    }

    /**
     * Reconnect strategy handed to the pump: open a fresh TCP socket to the
     * current DC (reusing the existing auth key), reset the session id so the
     * seqno restarts, and return the new connection for the reader to adopt.
     */
    private function reconnectForPump(): ConnectionInterface
    {
        $this->connection = new SyncConnection();
        $this->connectTcp();
        $this->session->regenerateSessionId();

        return $this->connection;
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
        // Persist the server time offset so msg_id time-window validation and
        // msg_id generation stay correct even on a clock-skewed host.
        $this->session->setTimeDelta($result['time_delta']);
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
