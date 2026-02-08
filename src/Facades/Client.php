<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Facades;

use LaraGram\Support\Facades\Facade;

/**
 * Resolves to the 'client.listener' binding (ClientListener instance).
 *
 * @method static \LaraGram\Listening\Listen onMessage(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onText(string $pattern, \Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onEditedMessage(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onDeletedMessages(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onCallbackQuery(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onCallbackQueryData(string $pattern, \Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onInlineQuery(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onTyping(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onReadHistory(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onReactions(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onUserStatus(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onChatParticipant(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onPreCheckoutQuery(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onShippingQuery(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen onUpdate(\Closure|array|string $action)
 * @method static \LaraGram\Listening\Listen fallback(\Closure|array|string $action)
 * @method static \LaraGram\MTProto\Listening\ClientListenRegistrar middleware(array|string $middleware)
 * @method static \LaraGram\MTProto\Listening\ClientListener group(array $attributes, \Closure|array|string $listens)
 *
 * @see \LaraGram\MTProto\Listening\ClientListener
 */
class Client extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'client.listener';
    }
}
