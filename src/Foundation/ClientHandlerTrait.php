<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;

/**
 * Convenience methods for registering MTProto client listens.
 *
 * These methods map to ClientType verbs, not Bot API verbs.
 */
trait ClientHandlerTrait
{
    /**
     * Listen for new messages (user, group, channel).
     */
    public function onMessage(Closure|array|string $action)
    {
        return $this->addListen('NEW_MESSAGE', '{clientMessagePlaceholder}', $action)
            ->where('clientMessagePlaceholder', '.*');
    }

    /**
     * Listen for new messages matching a text pattern.
     */
    public function onText(string $pattern, Closure|array|string $action)
    {
        return $this->addListen('NEW_MESSAGE', $pattern, $action);
    }

    /**
     * Listen for edited messages.
     */
    public function onEditedMessage(Closure|array|string $action)
    {
        return $this->addListen('EDIT_MESSAGE', '{clientEditPlaceholder}', $action)
            ->where('clientEditPlaceholder', '.*');
    }

    /**
     * Listen for deleted messages.
     */
    public function onDeletedMessages(Closure|array|string $action)
    {
        return $this->addListen('DELETED_MESSAGES', '{clientDeletePlaceholder}', $action)
            ->where('clientDeletePlaceholder', '.*');
    }

    /**
     * Listen for callback queries (all).
     */
    public function onCallbackQuery(Closure|array|string $action)
    {
        return $this->addListen('CALLBACK_QUERY', '{clientCbPlaceholder}', $action)
            ->where('clientCbPlaceholder', '.*');
    }

    /**
     * Listen for callback queries matching a data pattern.
     */
    public function onCallbackQueryData(string $pattern, Closure|array|string $action)
    {
        return $this->addListen('CALLBACK_QUERY', $pattern, $action);
    }

    /**
     * Listen for inline bot queries.
     */
    public function onInlineQuery(Closure|array|string $action)
    {
        return $this->addListen('INLINE_QUERY', '{clientInlinePlaceholder}', $action)
            ->where('clientInlinePlaceholder', '.*');
    }

    /**
     * Listen for typing indicators.
     */
    public function onTyping(Closure|array|string $action)
    {
        return $this->addListen('TYPING', '{clientTypingPlaceholder}', $action)
            ->where('clientTypingPlaceholder', '.*');
    }

    /**
     * Listen for read history events.
     */
    public function onReadHistory(Closure|array|string $action)
    {
        return $this->addListen('READ_HISTORY', '{clientReadPlaceholder}', $action)
            ->where('clientReadPlaceholder', '.*');
    }

    /**
     * Listen for message reaction changes.
     */
    public function onReactions(Closure|array|string $action)
    {
        return $this->addListen('REACTIONS', '{clientReactPlaceholder}', $action)
            ->where('clientReactPlaceholder', '.*');
    }

    /**
     * Listen for user online/offline status changes.
     */
    public function onUserStatus(Closure|array|string $action)
    {
        return $this->addListen('USER_STATUS', '{clientStatusPlaceholder}', $action)
            ->where('clientStatusPlaceholder', '.*');
    }

    /**
     * Listen for chat/channel participant changes.
     */
    public function onChatParticipant(Closure|array|string $action)
    {
        return $this->addListen('CHAT_PARTICIPANT', '{clientParticipantPlaceholder}', $action)
            ->where('clientParticipantPlaceholder', '.*');
    }

    /**
     * Listen for pre-checkout queries.
     */
    public function onPreCheckoutQuery(Closure|array|string $action)
    {
        return $this->addListen('PRE_CHECKOUT', '{clientPreCheckoutPlaceholder}', $action)
            ->where('clientPreCheckoutPlaceholder', '.*');
    }

    /**
     * Listen for shipping queries.
     */
    public function onShippingQuery(Closure|array|string $action)
    {
        return $this->addListen('SHIPPING', '{clientShippingPlaceholder}', $action)
            ->where('clientShippingPlaceholder', '.*');
    }

    /**
     * Listen for any update (catch-all).
     */
    public function onUpdate(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', '{clientUpdatePlaceholder}', $action)
            ->where('clientUpdatePlaceholder', '.*');
    }
}
