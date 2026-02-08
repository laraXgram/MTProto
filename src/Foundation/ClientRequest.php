<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;
use LaraGram\MTProto\Core\Client;
use LaraGram\Support\Traits\Conditionable;
use LaraGram\Support\Traits\Macroable;

/**
 * Request object for MTProto Client updates.
 *
 * @mixin \LaraGram\MTProto\Generated\ClientIdeHelper
 */
class ClientRequest
{
    use Conditionable, Macroable;

    /**
     * The raw MTProto update data.
     */
    protected array $data;

    /**
     * The wrapped DataObject (lazy).
     */
    protected ?DataObject $dataObject = null;

    /**
     * The MTProto constructor name (e.g. 'updateNewMessage').
     */
    protected string $type;

    /**
     * The resolved verb (e.g. 'NEW_MESSAGE').
     */
    protected ?string $verb = null;

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
     * @param array  $update  The raw MTProto update array
     * @param string $type    The constructor name (e.g. 'updateNewMessage')
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
     * Get the raw constructor type name.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Get the session name.
     */
    public function session(): string
    {
        return $this->session;
    }

    /**
     * Get the full update as a raw array.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get the wrapped DataObject for the full update.
     */
    public function update(): DataObject
    {
        if ($this->dataObject === null) {
            $this->dataObject = new DataObject($this->data);
        }

        return $this->dataObject;
    }

    /**
     * Get the verb for the Listening system.
     */
    public function method(): string
    {
        if ($this->verb !== null) {
            return $this->verb;
        }

        $this->verb = ClientType::verbFromConstructor($this->type);

        return $this->verb;
    }

    /**
     * Check if the method matches.
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === $method;
    }

    /**
     * Get the MTProto Client instance for this session.
     *
     * Usage from listen handlers:
     *   $request->client()->messages->sendMessage(
     *       peer: $request->message->peer_id->toArray(),
     *       message: 'pong!',
     *   );
     */
    public function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        // Fallback: resolve from container via ClientManager
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
     * Get the message text.
     *
     * In MTProto, the text field inside a message is called "message":
     *   updateNewMessage → message → message (the text string)
     */
    public function text(): ?string
    {
        return $this->data['message']['message'] ?? null;
    }

    /**
     * Get the sender user ID (shortcut).
     */
    public function userId(): ?int
    {
        $fromId = $this->data['message']['from_id'] ?? null;
        if (!$fromId) return null;
        return $fromId['user_id'] ?? $fromId['channel_id'] ?? $fromId['chat_id'] ?? null;
    }

    /**
     * Get the chat ID (shortcut).
     */
    public function chatId(): ?int
    {
        $peerId = $this->data['message']['peer_id'] ?? null;
        if (!$peerId) return null;

        return match ($peerId['_'] ?? '') {
            'peerUser'    => $peerId['user_id'] ?? null,
            'peerChat'    => $peerId['chat_id'] ?? null,
            'peerChannel' => $peerId['channel_id'] ?? null,
            default       => null,
        };
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
     * Check if message is a reply.
     */
    public function isReply(): bool
    {
        return isset($this->data['message']['reply_to']);
    }

    /**
     * Get the scope (peer type).
     */
    public function scope(): ?string
    {
        $peerId = $this->data['message']['peer_id']
            ?? $this->data['peer']
            ?? null;

        if (!is_array($peerId)) return null;

        return match ($peerId['_'] ?? '') {
            'peerUser'    => 'private',
            'peerChat'    => 'group',
            'peerChannel' => 'supergroup',
            default       => null,
        };
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
        return $this->listenResolver ?: function () {};
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
     * Returns DataObject for nested arrays, enabling:
     *   $request->message->text
     *   $request->message->from_id->user_id
     *   $request->message->entities[0]->type
     */
    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            return null;
        }

        return DataObject::wrap($this->data[$name]);
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
     * Enables flat-style calls directly on the request:
     *   $request->sendMessage(peer: ..., message: 'Hello!');
     *   $request->editMessage(peer: ..., id: ..., message: 'Edited');
     *   $request->getFullUser(id: ...);
     *
     * IDE auto-complete is provided via @mixin ClientIdeHelper.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->client()->{$name}(...$arguments);
    }
}
