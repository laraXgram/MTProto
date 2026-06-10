<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Container\Container;
use LaraGram\Contracts\Events\Dispatcher;
use LaraGram\Listening\Listen;
use LaraGram\Listening\ListenCollection;
use LaraGram\Listening\Listener;
use LaraGram\Listening\Pipeline;
use LaraGram\Listening\Events\ListenMatched;
use LaraGram\Listening\Events\Listening;
use LaraGram\Listening\Events\PreparingResponse;
use LaraGram\Listening\Events\ResponsePrepared;
use LaraGram\Listening\Exceptions\ListenNotFoundException;
use LaraGram\Listening\HandlerTrait;
use LaraGram\MTProto\Foundation\ClientHandlerTrait;
use LaraGram\MTProto\Foundation\ClientRequest;
use LaraGram\MTProto\Listening\Matching\ClientPatternValidator;
use LaraGram\Request\Response;
use LaraGram\Support\Str;
use ReflectionMethod;

/**
 * Client Listener — dedicated routing/dispatch for MTProto updates.
 */
class ClientListener extends Listener
{
    use ClientHandlerTrait;

    /**
     * All of the verbs supported by the client listener.
     *
     * Auto-populated from ClientType enum cases.
     *
     * @var string[]
     */
    public static $verbs = [
        // Messages
        'NEW_MESSAGE', 'EDIT_MESSAGE', 'DELETED_MESSAGES',
        'MESSAGE_ID', 'PINNED_MESSAGES', 'READ_CONTENTS',
        'MESSAGE_VIEWS', 'MESSAGE_FORWARDS', 'MESSAGE_EXTENDED_MEDIA',
        'TRANSCRIBED_AUDIO', 'GEO_LIVE_VIEWED',
        'SCHEDULED_MESSAGE', 'DELETE_SCHEDULED',

        // Media-filtered messages
        'PHOTO', 'VIDEO', 'ANIMATION', 'STICKER', 'DOCUMENT',
        'AUDIO', 'VOICE', 'VIDEO_NOTE', 'CONTACT_MEDIA',
        'LOCATION', 'VENUE', 'GAME', 'DICE',

        // Read history
        'READ_HISTORY', 'READ_DISCUSSION',

        // Callback / Inline
        'CALLBACK_QUERY', 'INLINE_QUERY', 'CHOSEN_INLINE_RESULT',

        // Typing
        'TYPING',

        // Reactions
        'REACTIONS',

        // Users
        'USER_STATUS', 'USER_NAME', 'USER_PHONE',
        'USER_EMOJI_STATUS', 'USER_UPDATE',

        // Chats / Channels
        'CHAT_PARTICIPANT', 'CHAT_PARTICIPANTS',
        'CHAT_PARTICIPANT_ADD', 'CHAT_PARTICIPANT_DELETE',
        'CHAT_PARTICIPANT_ADMIN', 'CHAT_DEFAULT_BANNED',
        'CHAT_UPDATE', 'CHANNEL_UPDATE', 'CHANNEL_TOO_LONG',
        'CHANNEL_AVAILABLE_MESSAGES', 'CHANNEL_VIEW_FORUM_AS_MESSAGES',

        // Polls
        'POLL', 'POLL_VOTE',

        // Web pages
        'WEB_PAGE',

        // Payments
        'PRE_CHECKOUT', 'SHIPPING',

        // Phone calls
        'PHONE_CALL', 'PHONE_CALL_SIGNALING',

        // Group calls
        'GROUP_CALL', 'GROUP_CALL_PARTICIPANTS', 'GROUP_CALL_CONNECTION',

        // Stories
        'STORY', 'READ_STORIES', 'STORY_ID',
        'STORIES_STEALTH_MODE', 'STORY_REACTION',

        // Encrypted (Secret chats)
        'ENCRYPTED_MESSAGE', 'ENCRYPTED_CHAT_TYPING',
        'ENCRYPTION', 'ENCRYPTED_READ',

        // Drafts
        'DRAFT',

        // Notifications & Settings
        'NOTIFY_SETTINGS', 'SERVICE_NOTIFICATION', 'PRIVACY',

        // Dialogs & Folders
        'DIALOG_PINNED', 'PINNED_DIALOGS', 'DIALOG_UNREAD_MARK',
        'DIALOG_FILTER', 'DIALOG_FILTER_ORDER', 'DIALOG_FILTERS',
        'FOLDER_PEERS', 'SAVED_DIALOG_PINNED', 'PINNED_SAVED_DIALOGS',

        // Bots
        'BOT_STOPPED', 'BOT_COMMANDS', 'BOT_MENU',
        'CHAT_JOIN_REQUEST', 'CHAT_BOOST',
        'BOT_REACTION', 'BOT_REACTIONS', 'BOT_PURCHASED_PAID',
        'BOT_WEBHOOK', 'BOT_WEBHOOK_QUERY',
        'WEBVIEW_RESULT_SENT', 'ATTACH_MENU_BOTS',

        // Bot Business
        'BOT_BUSINESS_CONNECT', 'BOT_BUSINESS_MESSAGE',
        'BOT_BUSINESS_EDIT', 'BOT_BUSINESS_DELETE',
        'BUSINESS_CALLBACK',

        // Stickers & Emoji
        'NEW_STICKER_SET', 'STICKER_SETS_ORDER', 'STICKER_SETS',
        'SAVED_GIFS', 'FAVED_STICKERS', 'RECENT_STICKERS',

        // Forum Topics
        'PINNED_FORUM_TOPIC', 'PINNED_FORUM_TOPICS',

        // Peers
        'PEER_SETTINGS', 'PEER_LOCATED', 'PEER_BLOCKED',
        'PEER_HISTORY_TTL', 'PEER_WALLPAPER',
        'CONTACTS_RESET', 'PENDING_JOIN_REQUESTS',

        // Auth & Security
        'NEW_AUTHORIZATION', 'LOGIN_TOKEN',

        // Stars & Payments
        'STARS_BALANCE', 'STARS_REVENUE', 'PAID_REACTION_PRIVACY',

        // Quick Replies
        'QUICK_REPLIES', 'NEW_QUICK_REPLY', 'DELETE_QUICK_REPLY',
        'QUICK_REPLY_MESSAGE', 'DELETE_QUICK_REPLY_MESSAGES',

        // Config & System
        'CONFIG', 'DC_OPTIONS', 'PTS_CHANGED',
        'LANG_PACK', 'LANG_PACK_TOO_LONG', 'AUTO_SAVE_SETTINGS',

        // Themes
        'THEME',

        // Catch-all
        'UPDATE',
    ];

    /**
     * Overridden validators for the client listener.
     *
     * @var array|null
     */
    protected static $clientValidators = null;

    /**
     * Create a new ClientListener instance.
     */
    public function __construct(Dispatcher $events, ?Container $container = null)
    {
        $this->events = $events;
        $this->listens = new ListenCollection;
        $this->container = $container ?: new Container;
    }

    /**
     * Register a fallback listen for client updates.
     */
    public function fallback($action)
    {
        $placeholder = 'clientFallbackPlaceholder';

        return $this->addListen(
            static::$verbs, "{{$placeholder}}", $action
        )->where($placeholder, '.*')->fallback();
    }

    /**
     * Dispatch a ClientRequest to the listener.
     *
     * @param  ClientRequest  $request
     * @return Response
     */
    public function dispatch($request)
    {
        $this->currentRequest = $request;

        return $this->dispatchToListen($request);
    }

    /**
     * Dispatch the request to a listen and return the response.
     *
     * @param  ClientRequest  $request
     * @return Response
     */
    public function dispatchToListen($request)
    {
        return $this->runListen($request, $this->findListen($request));
    }

    /**
     * Find the listen matching a given client request.
     *
     * @param  ClientRequest  $request
     * @return Listen
     */
    protected function findListen($request)
    {
        $this->events->dispatch(new Listening($request));

        $this->current = $listen = $this->matchClientListen($request);

        $listen->setContainer($this->container);

        $this->container->instance(Listen::class, $listen);

        return $listen;
    }

    /**
     * Match a client request against registered listens.
     *
     * Uses ClientListenParameterBinder instead of the default
     * ListenParameterBinder (which calls the global text() function
     * designed for Bot API and would crash in MTProto context).
     *
     * @param  ClientRequest  $request
     * @return Listen
     */
    protected function matchClientListen(ClientRequest $request): Listen
    {
        $verb = $request->method();
        $listens = $this->listens->get($verb);

        if (!empty($listens)) {
            $listen = $this->matchAgainstClientListens($listens, $request);
            if ($listen !== null) {
                return $this->bindClientListen($listen, $request);
            }
        }

        // Try UPDATE verb (catch-all)
        if ($verb !== 'UPDATE') {
            $updateListens = $this->listens->get('UPDATE');
            if (!empty($updateListens)) {
                $listen = $this->matchAgainstClientListens($updateListens, $request, skipMethodCheck: true);
                if ($listen !== null) {
                    return $this->bindClientListen($listen, $request);
                }
            }
        }

        // Check all verbs for a fallback
        $allListens = $this->listens->getListens();
        foreach ($allListens as $listen) {
            if ($listen->isFallback) {
                return $this->bindClientListen($listen, $request);
            }
        }

        throw new ListenNotFoundException(sprintf(
            'The client listen for "%s" could not be found.',
            $request->type()
        ));
    }

    /**
     * Bind a listen to a ClientRequest using the MTProto-aware parameter binder.
     *
     * This replaces Listen::bind($request) which internally uses
     * ListenParameterBinder → text() global helper (Bot API only).
     *
     * @param  Listen         $listen
     * @param  ClientRequest  $request
     * @return Listen
     */
    protected function bindClientListen(Listen $listen, ClientRequest $request): Listen
    {
        // Compile the listen regex (same as Listen::bind does internally)
        $refMethod = new ReflectionMethod($listen, 'compileListen');
        $refMethod->setAccessible(true);
        $refMethod->invoke($listen);

        // Use our custom binder that reads from ClientRequest
        $binder = new ClientListenParameterBinder($listen);
        $listen->parameters = $binder->parameters($request);

        return $listen;
    }

    /**
     * Match against an array of listens.
     *
     * @param  array  $listens
     * @param  ClientRequest  $request
     * @return Listen|null
     */
    protected function matchAgainstClientListens(array $listens, ClientRequest $request, bool $skipMethodCheck = false): ?Listen
    {
        $validator = new ClientPatternValidator;

        $fallbacks = [];
        $specific = [];
        $catchAll = [];

        foreach ($listens as $listen) {
            if ($listen->isFallback) {
                $fallbacks[] = $listen;
            } elseif ($this->isCatchAllPattern($listen)) {
                $catchAll[] = $listen;
            } else {
                $specific[] = $listen;
            }
        }

        // Try specific patterns first, then catch-all, then fallbacks
        foreach (array_merge($specific, $catchAll, $fallbacks) as $listen) {
            // Check method (verb) match — skipped for UPDATE catch-all
            if (!$skipMethodCheck && !in_array($request->method(), $listen->methods())) {
                continue;
            }

            // Check pattern match
            if ($validator->matchesClient($listen, $request)) {
                return $listen;
            }
        }

        return null;
    }

    /**
     * Determine if a listen has a catch-all pattern (matches everything).
     *
     * A pattern is catch-all if it's a single placeholder with a `.*` constraint,
     * e.g. {clientMessagePlaceholder} with ->where('clientMessagePlaceholder', '.*')
     */
    protected function isCatchAllPattern(Listen $listen): bool
    {
        $pattern = $listen->pattern();

        // Check if pattern is just a placeholder like {foo}
        if (preg_match('/^\{(\w+)\}$/', $pattern, $m)) {
            $wheres = $listen->wheres;
            return isset($wheres[$m[1]]) && $wheres[$m[1]] === '.*';
        }

        return false;
    }

    /**
     * Return the response for the given listen.
     *
     * @param  ClientRequest  $request
     * @param  Listen  $listen
     * @return Response
     */
    protected function runListen($request, Listen $listen)
    {
        $request->setListenResolver(fn () => $listen);

        $this->events->dispatch(new ListenMatched($listen, $request));

        return $this->prepareResponse($request,
            $this->runListenWithinStack($listen, $request)
        );
    }

    /**
     * Run the given listen within a Stack "onion" instance.
     *
     * @param  Listen  $listen
     * @param  ClientRequest  $request
     * @return mixed
     */
    protected function runListenWithinStack(Listen $listen, $request)
    {
        $shouldSkipMiddleware = $this->container->bound('middleware.disable') &&
            $this->container->make('middleware.disable') === true;

        $middleware = $shouldSkipMiddleware ? [] : $this->gatherListenMiddleware($listen);

        // Remove 'connection' from action — it triggers app('request') in
        // Listen::run(), which doesn't exist in MTProto context.
        unset($listen->action['connection']);

        return (new Pipeline($this->container))
            ->send($request)
            ->through($middleware)
            ->then(fn ($request) => $this->prepareResponse(
                $request, $listen->run()
            ));
    }

    /**
     * Create a response instance from the given value.
     *
     * @param  mixed  $request
     * @param  mixed  $response
     * @return Response
     */
    public function prepareResponse($request, $response)
    {
        $this->events->dispatch(new PreparingResponse($request, $response));

        return tap(static::toResponse($request, $response), function ($response) use ($request) {
            $this->events->dispatch(new ResponsePrepared($request, $response));
        });
    }

    /**
     * Dynamically handle calls into the listener instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if ($method === 'middleware') {
            return (new ClientListenRegistrar($this))->attribute($method, is_array($parameters[0]) ? $parameters[0] : $parameters);
        }

        if ($method !== 'where' && Str::startsWith($method, 'where')) {
            return (new ClientListenRegistrar($this))->{$method}(...$parameters);
        }

        return (new ClientListenRegistrar($this))->attribute($method, array_key_exists(0, $parameters) ? $parameters[0] : true);
    }
}
