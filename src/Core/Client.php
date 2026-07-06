<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Auth\AuthKeyGenerator;
use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\RateLimiterInterface;
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
use LaraGram\MTProto\Foundation\FileId;
use LaraGram\MTProto\Foundation\FileUploader;
use LaraGram\MTProto\Foundation\InputMedia;
use LaraGram\Log\LoggerInterface;
use LaraGram\MTProto\Session\FileSession;
use LaraGram\MTProto\TL\TLParser;
use LaraGram\MTProto\Generated\ClientMethods;
use LaraGram\MTProto\Core\Concerns\ManagesChats;
use LaraGram\MTProto\Core\Concerns\IteratesChats;
use LaraGram\MTProto\Core\Concerns\HandlesEngagement;
use LaraGram\MTProto\Core\Concerns\HandlesStories;
use LaraGram\MTProto\Core\Concerns\HandlesStars;
use LaraGram\MTProto\Core\Concerns\HandlesDrafts;
use LaraGram\MTProto\Core\Concerns\DownloadsMedia;
use LaraGram\MTProto\Core\Concerns\MessagingExtras;
use LaraGram\MTProto\Core\Concerns\ManagesProfile;
use LaraGram\MTProto\Core\Concerns\BotControls;
use LaraGram\MTProto\Core\Concerns\PremiumFeatures;
use LaraGram\MTProto\Core\Concerns\ManagesForum;
use LaraGram\MTProto\Core\Concerns\HandlesTakeout;
use LaraGram\MTProto\Transport\AbridgedTransport;
use LaraGram\MTProto\Transport\FakeTlsConnection;
use LaraGram\MTProto\Transport\IntermediatePaddedTransport;
use LaraGram\MTProto\Transport\ObfuscatedConnection;
use LaraGram\MTProto\Transport\ProxySettings;

/**
 * @mixin \LaraGram\MTProto\Generated\ClientIdeHelper
 */
class Client
{
    use ClientMethods;
    use ManagesChats;
    use IteratesChats;
    use HandlesEngagement;
    use HandlesStories;
    use HandlesStars;
    use HandlesDrafts;
    use DownloadsMedia;
    use MessagingExtras;
    use ManagesProfile;
    use BotControls;
    use PremiumFeatures;
    use ManagesForum;
    use HandlesTakeout;

    public const VERSION = '1.0.0-dev';
    public const LAYER = 227;

    private ConnectionInterface $connection;
    private TransportInterface $transport;
    private CryptoInterface $crypto;
    private bool $obfuscated = false;
    private string $protocolTag = '';
    private ?ProxySettings $proxy = null;
    private SessionInterface $session;
    private ?RPCHandler $rpc = null;
    private ?MessagePump $pump = null;
    private ?TLParser $tlParser = null;
    private ?PeerDatabase $peerDb = null;
    private ?\LaraGram\MTProto\Contracts\Store $peerStore = null;
    private ?\LaraGram\MTProto\Contracts\Store $sessionStore = null;
    private ?\LaraGram\MTProto\Contracts\Store $stateStore = null;
    private ?PeerResolver $peerResolver = null;
    private ?ParamPreprocessor $paramPreprocessor = null;

    private int $apiId;
    private string $apiHash;
    private ?DeviceProfile $deviceProfile = null;
    private ?MimeTypes $mimeTypes = null;
    private ?RateLimiterInterface $rateLimiter = null;
    private bool $rateLimitEnabled = false;
    private ?HumanPacer $humanPacer = null;
    /** @var array<string, array{rate?: float, capacity?: float}> */
    private array $rateLimits = [];
    private int $dcId = 2;
    private bool $testMode = false;
    private bool $ipv6 = false;
    private float $timeout = 10.0;
    private string $sessionDir = './sessions';
    private int $layer = self::LAYER;
    private bool $floodSleep = true;
    private int $floodSleepLimit = 60;
    private int $maxRetries = 5;
    private bool $usePump = false;
    private bool $autoMigrate = true;
    private ?LoggerInterface $logger;
    private Filesystem $files;
    private ?Runtime $runtime;

    private bool $connected = false;
    private string $sessionName = '';

    /** True when the current session's auth key was freshly generated this connect (vs loaded). */
    private bool $freshAuthKey = false;

    /** Raw constructor options, retained so {@see cloneForDc} can mint siblings. */
    private array $options = [];

    /** Lazily-built cross-DC connection pool (P3.3). */
    private ?ConnectionPool $pool = null;

    /**
     * @param int $apiId Telegram API ID
     * @param string $apiHash Telegram API Hash
     * @param array $options {
     * @type int $dc_id Initial DC (default 2)
     * @type bool $test_mode Use test servers
     * @type bool $ipv6 Use IPv6
     * @type float $timeout Connection timeout
     * @type string $session_dir Directory for session files
     * @type int $layer MTProto API layer (default Client::LAYER; must match compiled schema)
     * @type bool $flood_sleep Auto-sleep & retry on FLOOD_WAIT (default true)
     * @type int $flood_sleep_limit Max FLOOD_WAIT seconds to wait before rethrowing (default 60)
     * @type int $max_retries Max retries for transient/migrate errors (default 5)
     * @type ConnectionInterface $connection Custom connection driver
     * @type TransportInterface $transport Custom transport
     * @type CryptoInterface $crypto Custom crypto
     * }
     */
    public function __construct(int $apiId, string $apiHash, array $options = [])
    {
        $this->options = $options;
        $this->apiId = $apiId;
        $this->apiHash = $apiHash;
        $this->deviceProfile = ($options['device'] ?? null) instanceof DeviceProfile
            ? $options['device']
            : DeviceProfile::resolve(is_array($options['device'] ?? null) ? $options['device'] : []);
        $this->rateLimiter = $options['rate_limiter'] ?? null;
        $this->rateLimits = (array)($options['rate_limits'] ?? []);
        $this->rateLimitEnabled = (bool)($this->rateLimits['enabled'] ?? ($this->rateLimiter !== null));
        $this->humanPacer = ($options['human_pacer'] ?? null) instanceof HumanPacer
            ? $options['human_pacer']
            : new HumanPacer((array)($options['pacing'] ?? []));
        $this->dcId = $options['dc_id'] ?? 2;
        $this->testMode = $options['test_mode'] ?? false;
        $this->ipv6 = $options['ipv6'] ?? false;
        $this->timeout = $options['timeout'] ?? 10.0;
        $this->sessionDir = $options['session_dir'] ?? './sessions';
        $this->layer = (int)($options['layer'] ?? self::LAYER);
        $this->floodSleep = (bool)($options['flood_sleep'] ?? true);
        $this->floodSleepLimit = (int)($options['flood_sleep_limit'] ?? 60);
        $this->maxRetries = (int)($options['max_retries'] ?? 5);
        $this->usePump = (bool)($options['use_pump'] ?? false);
        $this->autoMigrate = (bool)($options['auto_migrate'] ?? true);
        $this->logger = $options['logger'] ?? $this->resolveDefaultLogger();
        $this->files = $options['files'] ?? new Filesystem();
        $this->runtime = $options['runtime'] ?? null;

        $this->transport = $options['transport'] ?? new AbridgedTransport();
        $this->crypto = $options['crypto'] ?? new NativeCrypto();
        $this->obfuscated = (bool)($options['obfuscated'] ?? false);
        $this->protocolTag = (string)($options['protocol_tag'] ?? ObfuscatedConnection::TAG_ABRIDGED);
        $this->proxy = ($options['proxy'] ?? null) instanceof ProxySettings ? $options['proxy'] : null;
        $this->peerStore = ($options['peer_store'] ?? null) instanceof \LaraGram\MTProto\Contracts\Store
            ? $options['peer_store']
            : null;
        $this->sessionStore = ($options['session_store'] ?? null) instanceof \LaraGram\MTProto\Contracts\Store
            ? $options['session_store']
            : null;
        $this->stateStore = ($options['state_store'] ?? null) instanceof \LaraGram\MTProto\Contracts\Store
            ? $options['state_store']
            : null;

        if ($this->proxy !== null) {
            $this->obfuscated = true;

            if ($this->proxy->isPadded() || $this->proxy->isFakeTls()) {
                $this->transport = new IntermediatePaddedTransport();
                $this->protocolTag = ObfuscatedConnection::TAG_PADDED;
            }
        }

        $this->connection = $options['connection'] ?? $this->makeConnection();
    }

    /**
     * Build a fresh transport connection, wrapping it in the obfuscated2 CTR
     * layer when enabled. Centralises connection creation so every
     * reconnect/DC-switch path gets the same obfuscation treatment.
     */
    private function makeConnection(): ConnectionInterface
    {
        $connection = new SyncConnection();

        if ($this->proxy !== null && $this->proxy->isFakeTls()) {
            $connection = new FakeTlsConnection(
                $connection,
                $this->crypto,
                $this->proxy->secret(),
                $this->proxy->domain(),
            );
        }

        if ($this->obfuscated) {
            return new ObfuscatedConnection(
                $connection,
                $this->crypto,
                $this->protocolTag,
                $this->proxy?->secret(),
                $this->proxy !== null ? $this->dcId : null,
            );
        }

        return $connection;
    }

    /**
     * Connect to Telegram using an existing (or fresh) session.
     *
     * @param string $sessionName
     * @return bool
     */
    public function connect(string $sessionName = 'default'): bool
    {
        if ($this->connected) {
            $this->disconnect();
        }

        $this->sessionName = $sessionName;
        $this->freshAuthKey = false;

        $this->files->ensureDirectoryExists($this->sessionDir, 0700);
        $this->session = $this->makeSession($sessionName);

        $storedDc = $this->session->getDcId();
        if ($this->session->getAuthKey() !== null && $storedDc >= 1 && $storedDc <= 5) {
            $this->dcId = $storedDc;
        }

        $this->peerDb = new PeerDatabase($this->sessionDir, $sessionName, $this->files, $this->peerStore);

        $this->connectTcp();

        if ($this->session->getAuthKey() === null) {
            $this->generateAuthKey();
        } else {
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

        try {
            $this->session->save();
        } catch (\Throwable) {
        }
        try {
            $this->peerDb?->save();
        } catch (\Throwable) {
        }
        try {
            $this->pump?->stop();
        } catch (\Throwable) {
        }
        try {
            $this->pool?->closeAll();
        } catch (\Throwable) {
        }
        try {
            $this->connection->disconnect();
        } catch (\Throwable) {
        }

        $this->rpc = null;
        $this->pump = null;
        $this->peerResolver = null;
        $this->paramPreprocessor = null;
        $this->resetNamespaces();
        $this->connected = false;
    }

    /**
     * Create a new TCP connection reusing the existing auth key.
     */
    public function reconnect(): void
    {
        $this->connection = $this->makeConnection();
        $this->connectTcp();

        $this->session->regenerateSessionId();

        $this->rpc = null;
        $this->pump = null;
        $this->peerResolver = null;
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

        try {
            $this->pump?->stop();
        } catch (\Throwable) {
        }
        try {
            $this->connection->disconnect();
        } catch (\Throwable) {
        }

        $this->rpc = null;
        $this->pump = null;
        $this->peerResolver = null;
        $this->paramPreprocessor = null;
        $this->resetNamespaces();
        $this->dcId = $newDcId;
        $this->freshAuthKey = false;

        $this->session = $this->makeSession($this->sessionName);

        $this->connection = $this->makeConnection();
        $this->connectTcp();

        if ($this->session->getAuthKey() === null || $this->session->getDcId() !== $newDcId) {
            $this->generateAuthKey();
        } else {
            $this->session->regenerateSessionId();
        }

        $this->session->setDcId($newDcId);
        $this->session->save();
    }

    /**
     * The cross-DC connection pool, lazily created on first use.
     */
    public function pool(): ConnectionPool
    {
        return $this->pool ??= new ConnectionPool($this, (array)($this->options['pool'] ?? []));
    }

    /**
     * True when the current session's auth key was freshly generated during the
     * last connect (rather than loaded from storage). The pool uses this to
     * decide whether a secondary DC still needs an `auth.importAuthorization`.
     */
    public function isFreshAuthKey(): bool
    {
        return $this->freshAuthKey;
    }

    /**
     * Whether RPCs can safely run concurrently on this connection. True only when
     * the multiplexing {@see MessagePump} is live on a coroutine-capable runtime,
     * the sync RPC path owns the socket per-call and must stay serial. Used by
     * {@see FileUploader} to decide between parallel and serial part uploads.
     */
    public function supportsConcurrentInvoke(): bool
    {
        return $this->pump !== null
            && $this->pump->isRunning()
            && $this->getRuntime()->isSupported();
    }

    /**
     * Mint a sibling client bound to a different DC, for the connection pool.
     */
    public function cloneForDc(int $dcId): self
    {
        $opts = $this->options;

        $opts['dc_id'] = $dcId;
        unset($opts['connection']);
        $opts['transport'] = clone $this->transport;
        $opts['crypto'] = clone $this->crypto;
        $opts['obfuscated'] = $this->obfuscated;
        $opts['protocol_tag'] = $this->protocolTag;
        $opts['proxy'] = $this->proxy;
        $opts['device'] = $this->deviceProfile;
        $opts['rate_limiter'] = $this->rateLimiter;
        $opts['human_pacer'] = $this->humanPacer;
        $opts['runtime'] = $this->runtime;
        $opts['logger'] = $this->logger;
        $opts['files'] = $this->files;
        $opts['session_dir'] = $this->sessionDir;

        $opts['session_store'] = new \LaraGram\MTProto\Store\ArrayStore();

        $opts['auto_migrate'] = false;

        return new self($this->apiId, $this->apiHash, $opts);
    }

    /**
     * Call any Telegram API method.
     *
     * @param string $method
     * @param array $params
     * @return mixed
     * @throws MTProtoException
     */
    public function invoke(string $method, array $params = []): mixed
    {
        $this->ensureConnected();

        $params = $this->getPreprocessor()->process($method, $params);

        $result = $this->invokeRaw($method, $params);

        if ($this->peerDb !== null && is_array($result)) {
            $this->peerDb->cachePeersFromResponse($result);
            $this->peerDb->save();
        }

        return $result;
    }

    /**
     * Fetch the currently authenticated user.
     *
     * @return array
     */
    public function getMe(): array
    {
        $users = $this->invoke('users.getUsers', ['id' => [['_' => 'inputUserSelf']]]);

        return $users[0] ?? [];
    }

    /**
     * Prime the peer database from the account's dialog list.
     *
     * @param int $limit
     */
    public function primePeerCache(int $limit = 100): void
    {
        if ($this->peerDb === null) {
            return;
        }

        try {
            $this->invoke('messages.getDialogs', [
                'offset_date' => 0,
                'offset_id' => 0,
                'offset_peer' => ['_' => 'inputPeerEmpty'],
                'limit' => $limit,
                'hash' => 0,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning("Peer cache prime (messages.getDialogs) failed: {$e->getMessage()}");
        }
    }

    /**
     * Low-level invoke - no parameter preprocessing, no peer caching.
     *
     * @param callable|null $progress
     * @internal
     */
    public function uploadFile(string $path, ?string $fileName = null, ?callable $progress = null): array
    {
        return (new FileUploader($this, $this->files))->fromPath($path, $fileName, $progress);
    }

    /**
     * Upload raw bytes (same return shape as {@see uploadFile}).
     *
     * @param callable|null $progress
     */
    public function uploadBytes(string $contents, string $fileName, ?callable $progress = null): array
    {
        return (new FileUploader($this, $this->files))->fromString($contents, $fileName, $progress);
    }

    /**
     * Send an uploaded photo. Extra `messages.sendMedia` params (parse_mode,
     * reply_to_msg_id, reply_markup, silent, …) pass through $params.
     */
    public function sendPhoto(string|int|array $peer, string $path, ?string $message = null, array $params = []): mixed
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
    public function sendDocument(string|int|array $peer, string $path, ?string $message = null, array $params = []): mixed
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
    public function sendVideo(string|int|array $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        $name = $params['file_name'] ?? $this->files->basename($path);
        $duration = (int)($params['duration'] ?? 0);
        $w = (int)($params['w'] ?? 0);
        $h = (int)($params['h'] ?? 0);
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
    public function sendAudio(string|int|array $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        $name = $params['file_name'] ?? $this->files->basename($path);
        $attr = InputMedia::attrAudio(
            (int)($params['duration'] ?? 0),
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
    public function sendVoice(string|int|array $peer, string $path, ?string $message = null, array $params = []): mixed
    {
        $duration = (int)($params['duration'] ?? 0);
        unset($params['duration'], $params['file_name']);

        $media = InputMedia::uploadedDocument(
            $this->uploadFile($path),
            $this->guessMimeType($path) ?: 'audio/ogg',
            [InputMedia::attrAudio($duration, voice: true)],
        );

        return $this->sendUploadedMedia($peer, $media, $message, $params);
    }

    public function sendLocation(string|int|array $peer, float $lat, float $long, array $params = []): mixed
    {
        if (isset($params['period'])) {
            $media = InputMedia::geoLive(
                $lat,
                $long,
                (int)$params['period'],
                isset($params['heading']) ? (int)$params['heading'] : null,
                isset($params['proximity_notification_radius']) ? (int)$params['proximity_notification_radius'] : null,
                (bool)($params['stopped'] ?? false),
            );
            unset($params['period'], $params['heading'], $params['proximity_notification_radius'], $params['stopped']);
        } else {
            $media = InputMedia::geoPoint(
                $lat,
                $long,
                isset($params['accuracy_radius']) ? (int)$params['accuracy_radius'] : null,
            );
            unset($params['accuracy_radius']);
        }

        return $this->sendUploadedMedia($peer, $media, null, $params);
    }

    public function sendVenue(string|int|array $peer, float $lat, float $long, string $title, string $address, array $params = []): mixed
    {
        $media = InputMedia::venue(
            $lat,
            $long,
            $title,
            $address,
            (string)($params['provider'] ?? ''),
            (string)($params['venue_id'] ?? ''),
            (string)($params['venue_type'] ?? ''),
        );
        unset($params['provider'], $params['venue_id'], $params['venue_type']);

        return $this->sendUploadedMedia($peer, $media, null, $params);
    }

    public function sendContact(string|int|array $peer, string $phoneNumber, string $firstName, array $params = []): mixed
    {
        $media = InputMedia::contact(
            $phoneNumber,
            $firstName,
            (string)($params['last_name'] ?? ''),
            (string)($params['vcard'] ?? ''),
        );
        unset($params['last_name'], $params['vcard']);

        return $this->sendUploadedMedia($peer, $media, null, $params);
    }

    public function sendDice(string|int|array $peer, string $emoticon = '🎲', array $params = []): mixed
    {
        return $this->sendUploadedMedia($peer, InputMedia::dice($emoticon), null, $params);
    }

    /**
     * @param list<string> $answers
     */
    public function sendPoll(string|int|array $peer, string $question, array $answers, array $params = []): mixed
    {
        $media = InputMedia::poll(
            $question,
            $answers,
            (bool)($params['multiple_choice'] ?? false),
            (bool)($params['public_voters'] ?? false),
            isset($params['correct_answers']) ? (array)$params['correct_answers'] : null,
            isset($params['solution']) ? (string)$params['solution'] : null,
            isset($params['close_period']) ? (int)$params['close_period'] : null,
        );
        foreach (['multiple_choice', 'public_voters', 'correct_answers', 'solution', 'close_period'] as $k) {
            unset($params[$k]);
        }

        return $this->sendUploadedMedia($peer, $media, null, $params);
    }

    /**
     * Build and dispatch a messages.sendMedia for an already-built InputMedia.
     */
    private function sendUploadedMedia(string|int|array $peer, array $media, ?string $message, array $params): mixed
    {
        return $this->invoke('messages.sendMedia', array_merge([
            'peer' => $peer,
            'media' => $media,
            'message' => $message ?? '',
        ], $params));
    }

    /**
     * Send a pre-built `InputMedia*` constructor (e.g. from {@see InputMedia}).
     */
    public function sendMedia(string|int|array $peer, array $media, ?string $message = null, array $params = []): mixed
    {
        return $this->sendUploadedMedia($peer, $media, $message, $params);
    }

    /**
     * Resend already-stored media by a portable file_id (no re-upload).
     */
    public function sendMediaById(string|int|array $peer, string $fileId, ?string $message = null, array $params = []): mixed
    {
        return $this->sendUploadedMedia($peer, FileId::toInputMedia($fileId), $message, $params);
    }

    /**
     * Mint a portable file_id from a message/media/photo/document array so it can
     * be re-sent later with {@see sendMediaById()}.
     */
    public function fileId(array $media): string
    {
        // Accept a whole message — dig out its .media for convenience.
        if (($media['_'] ?? '') === 'message' && isset($media['media']) && is_array($media['media'])) {
            $media = $media['media'];
        }

        return FileId::fromMedia($media);
    }

    /**
     * Send a grouped album (`messages.sendMultiMedia`) of 2–10 items.
     */
    public function sendAlbum(string|int|array $peer, array $items, array $params = []): mixed
    {
        if (count($items) < 2 || count($items) > 10) {
            throw new MTProtoException('An album needs between 2 and 10 items');
        }

        $inputPeer = is_array($peer) ? $peer : ($this->getResolver()?->resolveInputPeer($peer) ?? $peer);
        $multiMedia = [];

        foreach ($items as $item) {
            [$media, $caption] = $this->resolveAlbumItem($item);

            if (in_array($media['_'] ?? '', ['inputMediaUploadedPhoto', 'inputMediaUploadedDocument'], true)) {
                $uploaded = $this->invoke('messages.uploadMedia', ['peer' => $inputPeer, 'media' => $media]);
                $media = $this->toResendableMedia($uploaded);
            }

            $multiMedia[] = [
                '_' => 'inputSingleMedia',
                'media' => $media,
                'random_id' => random_int(PHP_INT_MIN, PHP_INT_MAX),
                'message' => $caption ?? '',
            ];
        }

        return $this->invoke('messages.sendMultiMedia', array_merge([
            'peer' => $inputPeer,
            'multi_media' => $multiMedia,
        ], $params));
    }

    /**
     * Normalise one album item spec into `[InputMedia, caption]`.
     *
     * @return array{0: array, 1: string|null}
     */
    private function resolveAlbumItem(array|string $item): array
    {
        if (is_string($item)) {
            return [$this->autoInputMedia($item), null];
        }

        $caption = $item['caption'] ?? $item['message'] ?? null;

        if (isset($item['media']) && is_array($item['media'])) {
            return [$item['media'], $caption];
        }
        if (isset($item['file_id'])) {
            return [FileId::toInputMedia((string)$item['file_id']), $caption];
        }
        if (isset($item['path'])) {
            return [$this->autoInputMedia((string)$item['path'], (string)($item['type'] ?? '')), $caption];
        }

        throw new MTProtoException('Album item needs one of: path, file_id, media');
    }

    /**
     * Upload a path and wrap it as a photo or document InputMedia by type/mime.
     */
    private function autoInputMedia(string $path, string $type = ''): array
    {
        $mime = $this->guessMimeType($path);
        $isPhoto = $type === 'photo' || ($type === '' && str_starts_with($mime, 'image/') && $mime !== 'image/webp');

        if ($isPhoto) {
            return InputMedia::uploadedPhoto($this->uploadFile($path));
        }

        $name = $this->files->basename($path);

        return InputMedia::uploadedDocument(
            $this->uploadFile($path, $name),
            $mime ?: 'application/octet-stream',
            [InputMedia::attrFilename($name)],
        );
    }

    /**
     * Convert a `messages.uploadMedia` result into a resendable
     * `inputMediaPhoto`/`inputMediaDocument` for `sendMultiMedia`.
     */
    private function toResendableMedia(array $uploaded): array
    {
        if (isset($uploaded['photo']) && is_array($uploaded['photo'])) {
            $p = $uploaded['photo'];
            return InputMedia::photo((int)$p['id'], (int)$p['access_hash'], (string)($p['file_reference'] ?? ''));
        }
        if (isset($uploaded['document']) && is_array($uploaded['document'])) {
            $d = $uploaded['document'];
            return InputMedia::document((int)$d['id'], (int)$d['access_hash'], (string)($d['file_reference'] ?? ''));
        }

        throw new MTProtoException('messages.uploadMedia returned no photo/document');
    }

    /**
     * Resolve a file's MIME type via LaraGram's MimeTypes service.
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

        $this->throttle($method, $params);

        $migrations = 0;
        $retries = 0;

        while (true) {
            try {
                $result = ($this->pump !== null && $this->pump->isRunning())
                    ? $this->pump->invoke($method, $params)
                    : $this->getRpc()->invoke($method, $params);
                break;
            } catch (MTProtoException $e) {
                $message = $e->getMessage();

                if (preg_match('/(PHONE|USER|FILE|NETWORK)_MIGRATE_(\d+)/', $message, $m)) {
                    if (!$this->autoMigrate) {
                        throw $e;
                    }
                    if (++$migrations > 5) {
                        throw $e;
                    }
                    $this->switchDc((int)$m[2]);
                    continue;
                }

                $wait = $this->parseWaitSeconds($message);
                if ($wait !== null) {
                    if ($this->floodSleep && $wait <= $this->floodSleepLimit) {
                        $jitter = mt_rand(0, 1000) / 1000.0;
                        $this->logger?->info("{$method}: flood wait {$wait}s — sleeping then retrying");
                        $this->backoffSleep($wait + $jitter);
                        continue;
                    }
                    throw $e;
                }

                if (str_contains($message, 'AUTH_KEY_DUPLICATED')) {
                    if (++$retries > $this->maxRetries) {
                        throw $e;
                    }
                    $this->logger?->warning("{$method}: AUTH_KEY_DUPLICATED — regenerating session");
                    $this->session->regenerateSessionId();
                    $this->reconnect();
                    continue;
                }

                if ($this->isTransientError($e)) {
                    if (++$retries > $this->maxRetries) {
                        throw $e;
                    }

                    $base = min(2 ** ($retries - 1), 8);
                    $delay = $base / 2 + (mt_rand(0, 1000) / 1000.0) * ($base / 2);
                    $this->logger?->warning("{$method}: transient error '{$message}' — retry {$retries}/{$this->maxRetries} in " . round($delay, 2) . 's');
                    $this->backoffSleep($delay);
                    continue;
                }

                throw $e;
            }
        }

        if (is_array($result) && isset($result['_'])) {
            if ($result['_'] === 'boolTrue') return true;
            if ($result['_'] === 'boolFalse') return false;
        }

        if ($this->peerDb !== null && is_array($result)) {
            $this->peerDb->cachePeersFromResponse($result);
        }

        return $result;
    }

    public function getApiId(): int
    {
        return $this->apiId;
    }

    public function getApiHash(): string
    {
        return $this->apiHash;
    }

    public function getDcId(): int
    {
        return $this->dcId;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    public function getCrypto(): CryptoInterface
    {
        return $this->crypto;
    }

    public function isReady(): bool
    {
        return $this->connected && $this->connection->isConnected();
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    public function getTlParser(): ?TLParser
    {
        return $this->tlParser;
    }

    public function getPeerDatabase(): ?PeerDatabase
    {
        return $this->peerDb;
    }

    public function getResolver(): ?PeerResolver
    {
        return $this->peerResolver;
    }

    public function getSessionDir(): string
    {
        return $this->sessionDir;
    }

    public function getSessionName(): string
    {
        return $this->sessionName;
    }

    public function getRpcHandler(): ?RPCHandler
    {
        return $this->rpc;
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function getFiles(): Filesystem
    {
        return $this->files;
    }

    public function getRuntime(): Runtime
    {
        return $this->runtime ??= new SwooleRuntime();
    }

    public function getStateStore(): ?\LaraGram\MTProto\Contracts\Store
    {
        return $this->stateStore;
    }

    private function makeSession(string $name): SessionInterface
    {
        return $this->sessionStore !== null
            ? new \LaraGram\MTProto\Session\StoreSession($name, $this->sessionStore)
            : new FileSession($name, $this->sessionDir, $this->files);
    }

    private function ensureConnected(): void
    {
        if (!$this->connected) {
            throw new MTProtoException('Not connected — call connect() first');
        }
    }

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
     * @return int|null  Seconds to wait, or null if the message is not a wait error.
     */
    private function parseWaitSeconds(string $message): ?int
    {
        if (preg_match('/(?:FLOOD_WAIT|SLOWMODE_WAIT|FLOOD_PREMIUM_WAIT)_(\d+)/', $message, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    private function throttle(string $method, array $params): void
    {
        $wait = 0.0;

        if ($this->rateLimiter !== null && $this->rateLimitEnabled) {
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
            }
        }

        if ($this->humanPacer !== null && $this->humanPacer->isEnabled()) {
            $human = $this->humanPacer->delayFor($method, $params);
            if ($human > 0.0) {
                $this->logger?->debug("{$method}: human pacing " . round($human, 2) . 's');
                $wait += $human;
            }
        }

        if ($wait > 0.0) {
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

            return $id !== null ? (string)$id : md5((string)json_encode($peer));
        }

        return (string)$peer;
    }

    /**
     * Coroutine-friendly back-off sleep.
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

        usleep((int)round($seconds * 1_000_000));
    }

    private function isTransientError(MTProtoException $e): bool
    {
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
        foreach (['mtproto_api.tl', 'telegram_api.tl', 'mtproto_ext.tl'] as $file) {
            $path = $schemasDir . '/' . $file;
            if (file_exists($path)) {
                $this->tlParser->parseFile($path);
            }
        }
    }

    /**
     * Build (once) the single-reader {@see MessagePump} for this client.
     */
    public function getPump(): MessagePump
    {
        if ($this->pump !== null) {
            return $this->pump;
        }

        $this->ensureTlParser();

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
        $this->pump->setReconnector(fn(): ConnectionInterface => $this->reconnectForPump());

        return $this->pump;
    }

    /**
     * Start the pump's reader/keep-alive coroutines and initialise the
     * connection through it. MUST run inside Swoole\Coroutine\run.
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
        $this->connection = $this->makeConnection();
        $this->connectTcp();
        $this->session->regenerateSessionId();

        return $this->connection;
    }

    /**
     * Open a raw TCP connection to the current DC.
     */
    private function connectTcp(): void
    {
        if ($this->proxy !== null) {
            $this->connection->connect($this->proxy->host(), $this->proxy->port(), $this->timeout);
        } else {
            $addr = DataCenter::getAddress($this->dcId, $this->testMode, $this->ipv6);
            $this->connection->connect($addr, DataCenter::DEFAULT_PORT, $this->timeout);
        }

        if (!$this->obfuscated) {
            $init = $this->transport->getInitialBytes();
            if ($init !== '') {
                $this->connection->send($init);
            }
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
        $this->freshAuthKey = true;
        $this->session->setAuthKey($result['auth_key']);
        $this->session->setServerSalt($result['server_salt']);
        $this->session->setDcId($this->dcId);
        $this->session->setTimeDelta($result['time_delta']);
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
