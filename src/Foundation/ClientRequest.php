<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;
use LaraGram\Listening\Contracts\ProvidesListenContext;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Generated\Types;
use LaraGram\MTProto\TL\TLObject;
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
 * @property-read int[]|null $messages                     Array of message IDs (updateDeleteMessages)
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
 * @property-read string|null $phone                       Phone number (updateUserPhone)
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
 *
 * @mixin \LaraGram\MTProto\Generated\ClientIdeHelper
 */
class ClientRequest implements ProvidesListenContext
{
    use Conditionable, Macroable;

    /**
     * Verbs whose matchable value is the message text (`message.message`).
     */
    private const TEXT_VERBS = ['NEW_MESSAGE', 'EDIT_MESSAGE', 'SCHEDULED_MESSAGE'];

    /**
     * The raw MTProto update data.
     */
    protected array $data;

    /**
     * The MTProto constructor name (e.g. 'updateNewMessage').
     */
    protected string $type;

    /**
     * The resolved verb (e.g. 'NEW_MESSAGE').
     */
    protected ?string $verb = null;

    /**
     * Override verb for media-filtered dispatch (e.g. 'PHOTO', 'VIDEO').
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
     * Get a FileDecoder instance for downloading files.
     */
    public function file(): FileDecoder
    {
        return new FileDecoder($this->client());
    }

    /**
     * Download the media attached to this update's message.
     *
     * @param string|null $thumbSize Photo size type (e.g. 'x', 'y', 'w'). Defaults to largest.
     * @return string The file bytes
     */
    public function downloadMedia(?string $thumbSize = null): string
    {
        $media = $this->data['message']['media'] ?? $this->data['media'] ?? null;
        if ($media === null) {
            throw new \RuntimeException('No media found in this update');
        }

        return $this->file()->downloadMedia($media, $thumbSize);
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
        $media = $this->data['message']['media'] ?? $this->data['media'] ?? null;
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
        $media = $this->data['message']['media'] ?? $this->data['media'] ?? null;
        if ($media === null) {
            return null;
        }

        return $this->file()->getFileInfo($media, $thumbSize);
    }

    /**
     * Get the verb for the Listening system.
     */
    public function method(): string
    {
        if ($this->mediaVerb !== null) {
            return $this->mediaVerb;
        }

        if ($this->verb !== null) {
            return $this->verb;
        }

        $this->verb = ClientType::verbFromConstructor($this->type);

        return $this->verb;
    }

    /**
     * Set an override verb for media-filtered dispatch.
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
        if (in_array($verb, self::TEXT_VERBS, true)) {
            return $this->data['message']['message'] ?? null;
        }

        return match ($verb) {
            'CALLBACK_QUERY' => $this->data['data'] ?? null,
            'INLINE_QUERY' => $this->data['query'] ?? null,
            'CHOSEN_INLINE_RESULT' => $this->data['query'] ?? null,
            default => null,
        };
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
            'updateEditMessage', 'updateEditChannelMessage' => !empty($this->data['message']['out']),
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
        if (!array_key_exists($name, $this->data)) {
            return null;
        }

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
