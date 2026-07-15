<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;
use LaraGram\Listening\Contracts\ProvidesListenContext;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Generated\Types;
use LaraGram\MTProto\TL\TLObject;
use LaraGram\Support\Str;
use LaraGram\Support\Traits\Conditionable;
use LaraGram\Support\Traits\Macroable;

/**
 * Request object for MTProto Client updates.
 *
 * @property-read Types\Message|null $message              Message object (updateNewMessage, updateEditMessage, etc.)
 * @property-read int|null $pts                            PTS counter
 * @property-read int|null $pts_count                      PTS count delta
 * @property-read int|null $id                             Generic ID field
 * @property-read int|null $random_id                      Random ID
 * @property-read \LaraGram\MTProto\Generated\Methods\Messages|int[]|null $messages  `messages` namespace (method call) OR message IDs (updateDeleteMessages payload)
 * @property-read int|null $user_id                        User ID
 * @property-read Types\SendMessageAction|null $action     Typing action (updateUserTyping)
 * @property-read int|null $chat_id                        Chat ID
 * @property-read Types\Peer|null $from_id                 Sender peer
 * @property-read Types\Peer|null $peer_id                 Chat/user peer
 * @property-read Types\Peer|null $peer                    Peer (for notify settings, etc.)
 * @property-read Types\ChatParticipants|null $participants Chat participants
 * @property-read Types\UserStatus|null $status            User online status (updateUserStatus)
 * @property-read string|null $first_name                  First name (updateUserName)
 * @property-read string|null $last_name                   Last name (updateUserName)
 * @property-read Types\Username[]|null $usernames         Usernames (updateUserName)
 * @property-read \LaraGram\MTProto\Generated\Methods\Phone|string|null $phone  `phone` namespace (method call) OR phone number (updateUserPhone payload)
 * @property-read int|null $date                           Date timestamp
 * @property-read Types\MessageMedia|null $media           Media (updateServiceNotification)
 * @property-read Types\MessageEntity[]|null $entities     Entities
 * @property-read string|null $type                        Type string
 * @property-read string|null $text                        Text (updateTranscribedAudio)
 * @property-read Types\WebPage|null $webpage              Web page (updateWebPage)
 * @property-read int|null $channel_id                     Channel ID
 * @property-read int|null $max_id                         Max read ID
 * @property-read int|null $views                          Message views count
 * @property-read int|null $forwards                       Message forwards count
 * @property-read int|null $folder_id                      Folder ID
 * @property-read int|null $still_unread_count             Still unread count
 * @property-read int|null $qts                            QTS counter
 * @property-read string|null $query                       Inline query string
 * @property-read int|null $query_id                       Inline query ID
 * @property-read string|null $offset                      Inline query offset
 * @property-read Types\GeoPoint|null $geo                 Geo point
 * @property-read string|null $data                        Callback query data
 * @property-read int|null $chat_instance                  Callback chat instance
 * @property-read string|null $game_short_name             Game short name
 * @property-read int|null $bot_id                         Bot ID
 * @property-read int|null $top_msg_id                     Top message ID
 * @property-read Types\DraftMessage|null $draft           Draft message
 * @property-read bool|null $pinned                        Pinned flag
 * @property-read int|null $inviter_id                     Inviter user ID
 * @property-read int|null $version                        Version
 * @property-read Types\PhoneCall|null $phone_call         Phone call
 * @property-read int|null $phone_call_id                  Phone call ID
 * @property-read Types\Poll|null $poll                    Poll
 * @property-read Types\PollResults|null $results          Poll results
 * @property-read int|null $poll_id                        Poll ID
 * @property-read Types\ChatBannedRights|null $default_banned_rights Default banned rights
 * @property-read Types\PeerSettings|null $settings        Peer settings
 * @property-read int|null $actor_id                       Actor user ID (updateChatParticipant)
 * @property-read Types\ChatParticipant|null $prev_participant Previous participant
 * @property-read Types\ChatParticipant|null $new_participant New participant
 * @property-read Types\ExportedChatInvite|null $invite    Export invite
 * @property-read bool|null $stopped                       Bot stopped flag
 * @property-read Types\BotCommand[]|null $commands        Bot commands
 * @property-read int|null $story_id                       Story ID
 * @property-read Types\StoryItem|null $story              Story item
 * @property-read Types\MessageReactions|null $reactions   Message reactions
 * @property-read Types\Reaction|null $reaction            Single reaction
 * @property-read Types\Reaction[]|null $old_reactions     Old reactions
 * @property-read Types\Reaction[]|null $new_reactions     New reactions
 * @property-read Types\InputGroupCall|null $call          Group call
 * @property-read string|null $connection_id               Business connection ID
 * @property-read Types\BotBusinessConnection|null $connection Business connection
 * @property-read Types\StarsAmount|null $balance          Stars balance
 * @property-read Types\EmojiStatus|null $emoji_status     Emoji status
 * @property-read bool|null $blocked                       Blocked flag
 * @property-read int|null $timeout                        Timeout value
 * @property-read int|null $ttl_period                     TTL period
 *
 * @method mixed uploadFile(string $path, ?string $fileName = null, ?callable $progress = null)
 * @method mixed uploadBytes(string $contents, string $fileName, ?callable $progress = null)
 * @method mixed sendPhoto(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendDocument(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendVideo(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendAudio(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendVoice(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendRichMessage(string|int $peer, string $content, string $format = 'markdown', array $params = [])
 * @method mixed forwardMessages(string|int $fromPeer, string|int $toPeer, int|array $ids, array $params = [])
 * @method mixed copyMessages(string|int $fromPeer, string|int $toPeer, int|array $ids, array $params = [])
 * @method mixed editMessage(string|int $peer, int $id, ?string $message = null, array $params = [])
 * @method mixed getMessages(string|int $peer, int|array $ids)
 * @method mixed markAsRead(string|int $peer, int $maxId = 0)
 * @method mixed sendChatAction(string|int $peer, string $action = 'typing', array $params = [])
 * @method mixed sendTyping(string|int $peer)
 * @method mixed blockUser(string|int $peer)
 * @method mixed unblockUser(string|int $peer)
 * @method mixed getFullChat(string|int $peer)
 * @method mixed banChatMember(string|int $peer, string|int $user, int $until = 0)
 * @method mixed kickChatMember(string|int $peer, string|int $user)
 * @method mixed promoteChatMember(string|int $peer, string|int $user, array|string $rights = [], string $rank = '')
 * @method mixed restrictChatMember(string|int $peer, string|int $user, array|string $restrictions, int $until = 0)
 * @method mixed sendReaction(string|int $peer, int $msgId, string|int|array|null $reaction = null, bool $big = false, bool $addToRecent = false)
 * @method mixed getParticipants(string|int $peer, string $filter = 'recent', int $offset = 0, int $limit = 200, string $q = '')
 * @method mixed downloadStory(string|int|array $peerOrStory, ?int $id = null, ?string $path = null)
 * @method mixed sendStory(string|int $peer, string|array $media, array $params = [])
 * @method mixed setProfilePhoto(string $path)
 *
 * @mixin \LaraGram\MTProto\Generated\ClientIdeHelper
 */
class ClientRequest implements ProvidesListenContext
{
    use Conditionable, Macroable;

    /**
     * The raw MTProto update data.
     */
    protected array $data;

    /**
     * The MTProto constructor name (e.g. 'updateNewMessage').
     */
    protected string $type;

    /**
     * The verb this dispatch pass runs under (one of ClientType's 8 verbs).
     */
    protected ?string $verb = null;

    /**
     * Back-compat alias kept for callers that set a media-filtered verb.
     * @deprecated use setListenVerb()
     */
    protected ?string $mediaVerb = null;

    /**
     * The listen resolver callback.
     */
    protected ?Closure $listenResolver = null;

    /**
     * The session name this update came from.
     */
    protected string $session = 'default';

    /**
     * The MTProto Client instance (injected by the start command).
     */
    protected ?Client $client = null;

    /**
     * Create a new ClientRequest.
     *
     * @param array $update The raw MTProto update array
     * @param string $type The constructor name (e.g. 'updateNewMessage')
     * @param string $session The session name
     */
    public function __construct(array $update = [], string $type = '', string $session = 'default')
    {
        $this->data = $update;
        $this->type = $type;
        $this->session = $session;
    }

    /**
     * Create a ClientRequest from an MTProto update.
     */
    public static function fromUpdate(array $update, string $type, string $session = 'default'): static
    {
        return new static($update, $type, $session);
    }

    /**
     * Get the raw constructor type name (e.g. 'updateNewMessage').
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Get the session name this update arrived on.
     */
    public function session(): string
    {
        return $this->session;
    }

    /**
     * Reply/act through a *different* session than the one this update arrived
     * on.
     */
    public function usingSession(string $session): static
    {
        $clone = clone $this;
        $clone->session = $session;
        $clone->client = null;

        return $clone;
    }

    /**
     * Get the full update as a raw array.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get the full update as a JSON string.
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->data, $options | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * Get the message text.
     */
    public function text(): ?string
    {
        return $this->data['message']['message'] ?? null;
    }

    /**
     * Get the callback query data.
     */
    public function callbackData(): ?string
    {
        return $this->data['data'] ?? null;
    }

    /**
     * Get the inline query string.
     */
    public function inlineQuery(): ?string
    {
        return $this->data['query'] ?? null;
    }

    /**
     * Get the shared FileDecoder for this session (lazy, reused).
     */
    public function file(): FileDecoder
    {
        return $this->client()->fileDecoder();
    }

    /**
     * Download whatever media this update carries - photo, video, animation,
     * document, voice, sticker, or story - no arguments needed. With `$path`
     * it streams to disk and returns bytes written; otherwise returns the raw
     * bytes.
     *
     * @return string|int bytes (no path) or bytes-written (with path)
     */
    public function download(?string $path = null, ?string $thumbSize = null): string|int
    {
        return $path === null
            ? $this->downloadMedia($thumbSize)
            : $this->downloadMediaToFile($path, $thumbSize);
    }

    /**
     * The message this update carries, as a plain array - regardless of whether
     * property access has already wrapped it into a TLObject.
     */
    private function messageArray(): ?array
    {
        $m = $this->data['message'] ?? null;
        if ($m instanceof TLObject) {
            return $m->toArray();
        }

        return is_array($m) ? $m : null;
    }

    /**
     * The id of the message this update carries (null if none).
     */
    public function messageId(): ?int
    {
        $id = $this->messageArray()['id'] ?? $this->data['id'] ?? null;

        return $id !== null ? (int)$id : null;
    }

    /**
     * A resolver-friendly id for the chat this update belongs to, taken from
     * the message's peer_id (user/chat/channel).
     */
    public function chatId(): ?int
    {
        $peer = $this->messageArray()['peer_id']
            ?? $this->data['peer_id']
            ?? $this->data['peer']
            ?? null;
        if ($peer instanceof TLObject) {
            $peer = $peer->toArray();
        }
        if (!is_array($peer)) {
            return null;
        }

        $id = $peer['user_id'] ?? $peer['channel_id'] ?? $peer['chat_id'] ?? null;

        return $id !== null ? (int)$id : null;
    }

    /**
     * Mark this chat's history read up to the current message - i.e. put a
     * "seen" tick on the user's message. Routes to channels/messages readHistory
     * as appropriate.
     */
    public function read(): mixed
    {
        $peer = $this->chatId();
        if ($peer === null) {
            throw new \RuntimeException('No chat on this update to mark as read');
        }

        return $this->client()->markAsRead($peer, $this->messageId() ?? 0);
    }

    /**
     * Alias of {@see read()} - mark the user's message as seen.
     */
    public function seen(): mixed
    {
        return $this->read();
    }

    /**
     * Download the media attached to this update's message.
     *
     * @param string|null $thumbSize Photo size type (e.g. 'x', 'y', 'w'). Defaults to largest.
     * @return string The file bytes
     */
    public function downloadMedia(?string $thumbSize = null): string
    {
        $media = $this->mediaArray();
        if ($media === null) {
            throw new \RuntimeException('No media found in this update');
        }

        return $this->file()->downloadMedia($media, $thumbSize);
    }

    /**
     * The media attached to this update, as a plain array, tolerant of TLObject
     * wrapping from prior property access.
     */
    private function mediaArray(): ?array
    {
        $media = $this->messageArray()['media'] ?? $this->data['media'] ?? null;
        if ($media instanceof TLObject) {
            return $media->toArray();
        }

        return is_array($media) ? $media : null;
    }

    /**
     * Download the media to a file path.
     *
     * @param string $path Destination file path
     * @param string|null $thumbSize Photo size type (e.g. 'x', 'y', 'w'). Defaults to largest.
     * @return int Bytes written
     */
    public function downloadMediaToFile(string $path, ?string $thumbSize = null): int
    {
        $media = $this->mediaArray();
        if ($media === null) {
            throw new \RuntimeException('No media found in this update');
        }

        return $this->file()->downloadMediaToFile($media, $path, $thumbSize);
    }

    /**
     * Get info about the media file without downloading it.
     *
     * @param string|null $thumbSize Photo size type override
     * @return array|null File info or null if no media
     */
    public function getMediaInfo(?string $thumbSize = null): ?array
    {
        $media = $this->mediaArray();
        if ($media === null) {
            return null;
        }

        return $this->file()->getFileInfo($media, $thumbSize);
    }

    /**
     * Get the verb for the Listening system
     */
    public function method(): string
    {
        if ($this->mediaVerb !== null) {
            return $this->mediaVerb;
        }

        return $this->verb ??= ClientType::UPDATE->name;
    }

    /**
     * Set the verb for this dispatch pass (UPDATE, TEXT, MESSAGE, DICE, …).
     */
    public function setListenVerb(string $verb): static
    {
        $this->verb = $verb;
        return $this;
    }

    /**
     * Back-compat: set a media-filtered verb. Prefer setListenVerb().
     * @deprecated
     */
    public function setMediaVerb(string $verb): static
    {
        $this->mediaVerb = $verb;
        return $this;
    }

    /**
     * Check if the method matches.
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === $method;
    }

    /**
     * Shared per-update dispatch state, so the several verb passes an update
     * fans out into agree on whether a primary listen has already handled it.
     * `{ done: bool }`.
     */
    protected ?object $dispatchState = null;

    /**
     * Attach the shared dispatch state (set once per incoming update).
     */
    public function setDispatchState(object $state): static
    {
        $this->dispatchState = $state;
        return $this;
    }

    /**
     * Whether a non-overlap primary listen has already handled this update in
     * an earlier verb pass.
     */
    public function dispatchDone(): bool
    {
        return (bool)($this->dispatchState->done ?? false);
    }

    /**
     * Mark this update as handled by a primary listen.
     */
    public function markDispatchDone(): void
    {
        if ($this->dispatchState !== null) {
            $this->dispatchState->done = true;
        }
    }

    /**
     * {@inheritdoc}
     *
     * The verb is the resolved MTProto match verb (native, not Bot-API-remapped).
     */
    public function listenVerb(): string
    {
        return $this->method();
    }

    /**
     * {@inheritdoc}
     *
     * Returns the regex-matchable string for the given verb. Structural verbs
     * (catch-all media/update handlers, etc.) carry no string to match and
     * return null, the verb match alone is then sufficient.
     */
    public function listenValue(string $verb): ?string
    {
        return match ($verb) {
            'UPDATE' => ClientType::eventKey($this->type),

            'MESSAGE' => $this->contentType(),

            'TEXT' => $this->text(),
            'COMMAND' => ($t = $this->text()) !== null ? Str::replaceFirst('/', '', $t) : null,
            'REFERRAL' => ($t = $this->text()) !== null ? Str::replaceFirst('/start ', '', $t) : null,

            'CALLBACK_DATA' => $this->callbackData(),

            'DICE' => $this->diceValue(),

            default => null,
        };
    }

    /**
     * The media content type of this message (photo/video/…), or null.
     */
    protected function contentType(): ?string
    {
        $message = $this->data['message'] ?? null;

        if (!is_array($message)) {
            return null;
        }

        return ClientType::mediaTypeFromMessage($message);
    }

    /**
     * The dice value as "emoji,value" for the DICE matcher.
     */
    protected function diceValue(): ?string
    {
        $media = $this->data['message']['media'] ?? null;

        if (!is_array($media) || ($media['_'] ?? '') !== 'messageMediaDice') {
            return null;
        }

        return ($media['emoticon'] ?? '') . ',' . ($media['value'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function entities(): array
    {
        return $this->data['message']['entities'] ?? [];
    }

    /**
     * Check whether this update represents a message sent by this session
     * itself (an outgoing message), as opposed to one received from someone
     * else (incoming).
     */
    public function isOutgoing(): bool
    {
        return match ($this->type) {
            'updateShortSentMessage' => true,
            'updateShortMessage', 'updateShortChatMessage' => !empty($this->data['out']),
            'updateNewMessage', 'updateNewChannelMessage',
            'updateEditMessage', 'updateEditChannelMessage',
            'updateNewEphemeralMessage', 'updateEditEphemeralMessage' => !empty($this->data['message']['out']),
            default => false,
        };
    }

    /**
     * {@inheritdoc}
     *
     * The scope for an MTProto update is the **session** it arrived on, so a
     * listen file bound with `forSessions('x')` only runs for that account.
     * Carried on the request object → coroutine/multi-session safe.
     */
    public function listenScope(): ?string
    {
        return $this->session;
    }

    /**
     * Get the MTProto Client instance for this session.
     */
    public function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        /** @var ClientManager $manager */
        $manager = app('mtproto.manager');
        return $manager->client($this->session);
    }

    /**
     * Set the MTProto Client instance.
     */
    public function setClient(Client $client): static
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Get the listen handling the request.
     */
    public function listen(string $param = null, mixed $default = null): mixed
    {
        $listen = call_user_func($this->getListenResolver());

        if (is_null($listen) || is_null($param)) {
            return $listen;
        }

        return $listen->parameter($param, $default);
    }

    /**
     * Get the listen resolver callback.
     */
    public function getListenResolver(): Closure
    {
        return $this->listenResolver ?: function () {
        };
    }

    /**
     * Set the listen resolver callback.
     */
    public function setListenResolver(Closure $callback): static
    {
        $this->listenResolver = $callback;
        return $this;
    }

    /**
     * Property-style access to update data.
     *
     * @return \LaraGram\MTProto\TL\TLObject|mixed|null
     */
    public function __get(string $name): mixed
    {
        $isNamespace = Client::isNamespace($name);
        $hasData = array_key_exists($name, $this->data);

        // Name is an API namespace only (no colliding update key) → the
        // namespace object, so `$request->messages->sendMessage(...)` works.
        if ($isNamespace && !$hasData) {
            return $this->client()->{$name};
        }

        // Name is BOTH a namespace and an update-payload key → defer the
        // meaning to how the caller uses it (method the namespace defines →
        // namespace; property/array/iteration/other method → update value).
        if ($isNamespace && $hasData) {
            return new PendingNamespaceOrUpdate(
                fn() => $this->client()->{$name},
                fn() => $this->wrapDataValue($name),
            );
        }

        // Pure update payload.
        if (!$hasData) {
            return null;
        }

        return $this->wrapDataValue($name);
    }

    /**
     * Wrap a raw update-data value into TLObject(s) where it carries a `_`
     * constructor, caching the result. Scalars and plain arrays pass through.
     */
    private function wrapDataValue(string $name): mixed
    {
        $value = $this->data[$name];

        if (is_array($value)) {
            if (isset($value['_'])) {
                $wrapped = TLObject::fromArray($value);
                $this->data[$name] = $wrapped; // cache
                return $wrapped;
            }

            if (!empty($value)) {
                $first = reset($value);
                if (is_array($first) && isset($first['_'])) {
                    $wrapped = array_map(static fn(array $item) => TLObject::fromArray($item), $value);
                    $this->data[$name] = $wrapped; // cache
                    return $wrapped;
                }
            }

            return $value;
        }

        return $value;
    }

    /**
     * Check if a key exists in the update data.
     */
    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Forward MTProto API method calls to the underlying Client.
     *
     * IDE auto-complete is provided via @mixin \LaraGram\MTProto\Generated\ClientIdeHelper.
     */
    public function __call($method, $parameters): mixed
    {
        return $this->client()->{$method}(...$parameters);
    }
}
