<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

/**
 * Maps raw MTProto constructor names to simplified verbs
 * for the Listening/Matching system.
 *
 * This is the MTProto equivalent of LaraGram\Listening\Type,
 * but works with MTProto constructor names instead of Bot API update types.
 */
enum ClientType: string
{
    case NEW_MESSAGE      = 'new_message';
    case EDIT_MESSAGE     = 'edit_message';
    case DELETED_MESSAGES = 'deleted_messages';
    case CALLBACK_QUERY   = 'callback_query';
    case INLINE_QUERY     = 'inline_query';
    case TYPING           = 'typing';
    case READ_HISTORY     = 'read_history';
    case REACTIONS        = 'reactions';
    case USER_STATUS      = 'user_status';
    case CHAT_PARTICIPANT = 'chat_participant';
    case PRE_CHECKOUT     = 'pre_checkout';
    case SHIPPING         = 'shipping';
    case UPDATE           = 'update';

    /**
     * All MTProto constructor → verb mappings.
     */
    const CONSTRUCTORS = [
        // Messages
        'updateNewMessage'            => 'new_message',
        'updateNewChannelMessage'     => 'new_message',

        // Edited messages
        'updateEditMessage'           => 'edit_message',
        'updateEditChannelMessage'    => 'edit_message',

        // Deleted messages
        'updateDeleteMessages'        => 'deleted_messages',
        'updateDeleteChannelMessages' => 'deleted_messages',

        // Callback queries
        'updateBotCallbackQuery'      => 'callback_query',
        'updateInlineBotCallbackQuery' => 'callback_query',

        // Inline queries
        'updateBotInlineQuery'        => 'inline_query',

        // Typing
        'updateUserTyping'            => 'typing',
        'updateChatUserTyping'        => 'typing',
        'updateChannelUserTyping'     => 'typing',

        // Read history
        'updateReadHistoryInbox'      => 'read_history',
        'updateReadHistoryOutbox'     => 'read_history',
        'updateReadChannelInbox'      => 'read_history',
        'updateReadChannelOutbox'     => 'read_history',

        // Reactions
        'updateMessageReactions'      => 'reactions',

        // User status
        'updateUserStatus'            => 'user_status',

        // Chat participants
        'updateChatParticipant'       => 'chat_participant',
        'updateChannelParticipant'    => 'chat_participant',

        // Payments
        'updateBotPrecheckoutQuery'   => 'pre_checkout',
        'updateBotShippingQuery'      => 'shipping',
    ];

    /**
     * All supported verbs (used by the Listener as $verbs array).
     */
    const VERBS = [
        'NEW_MESSAGE', 'EDIT_MESSAGE', 'DELETED_MESSAGES',
        'CALLBACK_QUERY', 'INLINE_QUERY',
        'TYPING', 'READ_HISTORY', 'REACTIONS',
        'USER_STATUS', 'CHAT_PARTICIPANT',
        'PRE_CHECKOUT', 'SHIPPING',
        'UPDATE',
    ];

    /**
     * Map a raw constructor name to its verb.
     */
    public static function verbFromConstructor(string $constructor): string
    {
        $mapped = self::CONSTRUCTORS[$constructor] ?? null;

        if ($mapped !== null) {
            return strtoupper($mapped);
        }

        // Unknown constructor — fallback to generic UPDATE
        return 'UPDATE';
    }

    /**
     * Find the ClientType case from a verb value.
     */
    public static function fromVerb(string $verb): ?self
    {
        $lower = strtolower($verb);
        return self::tryFrom($lower);
    }
}
