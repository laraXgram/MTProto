<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;

/**
 * Converts Bot-API style reply markup (as built by LaraGram's `Keyboard`/`Make`)
 * into the layer 229+ TL constructors: `keyboardButton`/`keyboardInlineButton`
 * carrying a `ButtonType`/`InlineButtonType` and an optional `KeyboardButtonStyle`.
 */
final class ReplyMarkup
{
    /**
     * Bot-API button style -> keyboardButtonStyle flag.
     */
    private const STYLES = [
        'primary' => 'bg_primary',
        'danger' => 'bg_danger',
        'success' => 'bg_success',
    ];

    /**
     * Bot-API ChatAdministratorRights fields -> chatAdminRights flags.
     */
    private const ADMIN_RIGHTS = [
        'can_change_info' => 'change_info',
        'can_post_messages' => 'post_messages',
        'can_edit_messages' => 'edit_messages',
        'can_delete_messages' => 'delete_messages',
        'can_restrict_members' => 'ban_users',
        'can_invite_users' => 'invite_users',
        'can_pin_messages' => 'pin_messages',
        'can_promote_members' => 'add_admins',
        'is_anonymous' => 'anonymous',
        'can_manage_video_chats' => 'manage_call',
        'can_manage_chat' => 'other',
        'can_manage_topics' => 'manage_topics',
        'can_post_stories' => 'post_stories',
        'can_edit_stories' => 'edit_stories',
        'can_delete_stories' => 'delete_stories',
        'can_manage_direct_messages' => 'manage_direct_messages',
    ];

    /**
     * @param array<string, mixed> $markup Bot-API reply markup
     * @param (Closure(int|string): array)|null $inputUser resolves a user id/@username to an InputUser
     */
    public static function toTl(array $markup, ?Closure $inputUser = null): array
    {
        if (isset($markup['inline_keyboard'])) {
            return [
                '_' => 'replyInlineMarkup',
                'rows' => array_map(
                    static fn ($row): array => [
                        '_' => 'keyboardInlineButtonRow',
                        'buttons' => array_map(static fn ($b): array => self::inlineButton((array) $b, $inputUser), array_values((array) $row)),
                    ],
                    array_values($markup['inline_keyboard']),
                ),
            ];
        }

        if (isset($markup['remove_keyboard'])) {
            return self::withFlags(['_' => 'replyKeyboardHide'], ['selective' => !empty($markup['selective'])]);
        }

        if (isset($markup['force_reply'])) {
            $m = self::withFlags(['_' => 'replyKeyboardForceReply'], ['selective' => !empty($markup['selective'])]);
            if (($markup['input_field_placeholder'] ?? '') !== '') {
                $m['placeholder'] = (string) $markup['input_field_placeholder'];
            }

            return $m;
        }

        $m = self::withFlags(
            [
                '_' => 'replyKeyboardMarkup',
                'rows' => array_map(
                    static fn ($row): array => [
                        '_' => 'keyboardButtonRow',
                        'buttons' => array_map(static fn ($b): array => self::replyButton((array) $b), array_values((array) $row)),
                    ],
                    array_values($markup['keyboard'] ?? []),
                ),
            ],
            [
                'resize' => !empty($markup['resize_keyboard']),
                'single_use' => !empty($markup['one_time_keyboard']),
                'persistent' => !empty($markup['is_persistent']),
                'selective' => !empty($markup['selective']),
            ],
        );

        if (($markup['input_field_placeholder'] ?? '') !== '') {
            $m['placeholder'] = (string) $markup['input_field_placeholder'];
        }

        return $m;
    }

    /**
     * Bot-API inline button -> keyboardInlineButton.
     *
     * @param array<string, mixed> $b
     */
    private static function inlineButton(array $b, ?Closure $inputUser): array
    {
        return self::button('keyboardInlineButton', $b, self::inlineType($b, $inputUser));
    }

    /**
     * @param array<string, mixed> $b
     */
    private static function inlineType(array $b, ?Closure $inputUser): array
    {
        if (isset($b['disabled'])) {
            return ['_' => 'inlineButtonTypeDisabled'];
        }
        if (isset($b['callback_data'])) {
            return ['_' => 'inlineButtonTypeCallback', 'data' => (string) $b['callback_data']];
        }
        if (isset($b['url'])) {
            $url = (string) $b['url'];
            if ($inputUser !== null && preg_match('~^tg://user\?id=(\d+)$~', $url, $m)) {
                return ['_' => 'inputInlineButtonTypeUserProfile', 'user_id' => $inputUser((int) $m[1])];
            }

            return ['_' => 'inlineButtonTypeUrl', 'url' => $url];
        }
        if (isset($b['login_url']['url'])) {
            $login = (array) $b['login_url'];
            $type = self::withFlags(
                ['_' => 'inputInlineButtonTypeUrlAuth', 'url' => (string) $login['url']],
                ['request_write_access' => !empty($login['request_write_access'])],
            );
            if (($login['forward_text'] ?? '') !== '') {
                $type['fwd_text'] = (string) $login['forward_text'];
            }
            if (($login['bot_username'] ?? '') !== '' && $inputUser !== null) {
                $type['bot'] = $inputUser('@' . ltrim((string) $login['bot_username'], '@'));
            }

            return $type;
        }
        if (isset($b['switch_inline_query'])) {
            return ['_' => 'inlineButtonTypeSwitchInline', 'query' => (string) $b['switch_inline_query']];
        }
        if (isset($b['switch_inline_query_current_chat'])) {
            return [
                '_' => 'inlineButtonTypeSwitchInline',
                'same_peer' => true,
                'query' => (string) $b['switch_inline_query_current_chat'],
            ];
        }
        if (isset($b['switch_inline_query_chosen_chat'])) {
            return self::chosenChat((array) $b['switch_inline_query_chosen_chat']);
        }
        if (isset($b['web_app']['url'])) {
            return ['_' => 'inlineButtonTypeWebView', 'url' => (string) $b['web_app']['url']];
        }
        if (isset($b['copy_text'])) {
            $copy = is_array($b['copy_text']) ? ($b['copy_text']['text'] ?? '') : $b['copy_text'];

            return ['_' => 'inlineButtonTypeCopy', 'copy_text' => (string) $copy];
        }
        if (isset($b['callback_game'])) {
            return ['_' => 'inlineButtonTypeGame'];
        }
        if (!empty($b['pay'])) {
            return ['_' => 'inlineButtonTypeBuy'];
        }

        // An inline button with no action - pressing it does nothing.
        return ['_' => 'inlineButtonTypeDisabled'];
    }

    /**
     * Bot-API `switch_inline_query_chosen_chat` -> inlineButtonTypeSwitchInline with peer_types.
     *
     * @param array<string, mixed> $chosen
     */
    private static function chosenChat(array $chosen): array
    {
        $types = [];
        foreach ([
            'allow_user_chats' => 'inlineQueryPeerTypePM',
            'allow_bot_chats' => 'inlineQueryPeerTypeBotPM',
            'allow_group_chats' => 'inlineQueryPeerTypeChat',
            'allow_channel_chats' => 'inlineQueryPeerTypeBroadcast',
        ] as $field => $constructor) {
            if (!empty($chosen[$field])) {
                $types[] = ['_' => $constructor];
                if ($constructor === 'inlineQueryPeerTypeChat') {
                    $types[] = ['_' => 'inlineQueryPeerTypeMegagroup'];
                }
            }
        }

        $type = ['_' => 'inlineButtonTypeSwitchInline', 'query' => (string) ($chosen['query'] ?? '')];
        if ($types !== []) {
            $type['peer_types'] = $types;
        }

        return $type;
    }

    /**
     * Bot-API reply (keyboard) button -> keyboardButton.
     *
     * @param array<string, mixed> $b
     */
    private static function replyButton(array $b): array
    {
        return self::button('keyboardButton', $b, self::replyType($b));
    }

    /**
     * @param array<string, mixed> $b
     */
    private static function replyType(array $b): array
    {
        if (!empty($b['request_contact'])) {
            return ['_' => 'buttonTypeRequestPhone'];
        }
        if (!empty($b['request_location'])) {
            return ['_' => 'buttonTypeRequestGeoLocation'];
        }
        if (isset($b['request_poll'])) {
            $type = ['_' => 'buttonTypeRequestPoll'];
            $kind = ((array) $b['request_poll'])['type'] ?? '';
            if ($kind === 'quiz' || $kind === 'regular') {
                $type['quiz'] = $kind === 'quiz';
            }

            return $type;
        }
        if (isset($b['web_app']['url'])) {
            return ['_' => 'buttonTypeSimpleWebView', 'url' => (string) $b['web_app']['url']];
        }
        if (isset($b['request_users'])) {
            $r = (array) $b['request_users'];
            $peerType = ['_' => 'requestPeerTypeUser'];
            if (array_key_exists('user_is_bot', $r)) {
                $peerType['bot'] = (bool) $r['user_is_bot'];
            }
            if (array_key_exists('user_is_premium', $r)) {
                $peerType['premium'] = (bool) $r['user_is_premium'];
            }

            return self::requestPeer($r, $peerType, (int) ($r['max_quantity'] ?? 1), 'request_name');
        }
        if (isset($b['request_chat'])) {
            $r = (array) $b['request_chat'];
            $channel = !empty($r['chat_is_channel']);
            $peerType = self::withFlags(
                ['_' => $channel ? 'requestPeerTypeBroadcast' : 'requestPeerTypeChat'],
                [
                    'creator' => !empty($r['chat_is_created']),
                    'bot_participant' => !$channel && !empty($r['bot_is_member']),
                ],
            );
            if (array_key_exists('chat_has_username', $r)) {
                $peerType['has_username'] = (bool) $r['chat_has_username'];
            }
            if (!$channel && array_key_exists('chat_is_forum', $r)) {
                $peerType['forum'] = (bool) $r['chat_is_forum'];
            }
            foreach (['user_administrator_rights' => 'user_admin_rights', 'bot_administrator_rights' => 'bot_admin_rights'] as $field => $key) {
                if (isset($r[$field]) && is_array($r[$field])) {
                    $peerType[$key] = self::adminRights($r[$field]);
                }
            }

            return self::requestPeer($r, $peerType, 1, 'request_title');
        }
        if (isset($b['request_managed_bot'])) {
            $r = (array) $b['request_managed_bot'];
            $peerType = ['_' => 'requestPeerTypeCreateBot', 'bot_managed' => true];
            foreach (['suggested_name', 'suggested_username'] as $field) {
                if (($r[$field] ?? '') !== '') {
                    $peerType[$field] = (string) $r[$field];
                }
            }

            return self::requestPeer($r, $peerType, 1, null);
        }

        return ['_' => 'buttonTypeDefault'];
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function requestPeer(array $request, array $peerType, int $maxQuantity, ?string $nameField): array
    {
        return self::withFlags(
            [
                '_' => 'inputButtonTypeRequestPeer',
                'button_id' => (int) ($request['request_id'] ?? 0),
                'peer_type' => $peerType,
                'max_quantity' => max(1, $maxQuantity),
            ],
            [
                'name_requested' => $nameField !== null && !empty($request[$nameField]),
                'username_requested' => !empty($request['request_username']),
                'photo_requested' => !empty($request['request_photo']),
            ],
        );
    }

    /**
     * Bot-API ChatAdministratorRights -> chatAdminRights.
     *
     * @param array<string, mixed> $rights
     */
    private static function adminRights(array $rights): array
    {
        $flags = [];
        foreach (self::ADMIN_RIGHTS as $field => $flag) {
            $flags[$flag] = !empty($rights[$field]);
        }

        return self::withFlags(['_' => 'chatAdminRights'], $flags);
    }

    /**
     * Wrap a button type with its label and optional style.
     *
     * @param array<string, mixed> $b
     */
    private static function button(string $constructor, array $b, array $type): array
    {
        $button = ['_' => $constructor, 'text' => (string) ($b['text'] ?? ''), 'type' => $type];

        $style = self::style($b);
        if ($style !== null) {
            $button['style'] = $style;
        }

        return $button;
    }

    /**
     * Bot-API `style` + `icon_custom_emoji_id` -> keyboardButtonStyle, or null when neither is set.
     *
     * @param array<string, mixed> $b
     */
    private static function style(array $b): ?array
    {
        $flag = self::STYLES[strtolower((string) ($b['style'] ?? ''))] ?? null;
        $icon = $b['icon_custom_emoji_id'] ?? null;
        $icon = is_numeric($icon) && (int) $icon !== 0 ? (int) $icon : null;

        if ($flag === null && $icon === null) {
            return null;
        }

        $style = ['_' => 'keyboardButtonStyle'];
        if ($flag !== null) {
            $style[$flag] = true;
        }
        if ($icon !== null) {
            $style['icon'] = $icon;
        }

        return $style;
    }

    /**
     * Add only the `true` boolean flags to a constructor.
     *
     * @param array<string, bool> $flags
     */
    private static function withFlags(array $constructor, array $flags): array
    {
        foreach ($flags as $flag => $on) {
            if ($on) {
                $constructor[$flag] = true;
            }
        }

        return $constructor;
    }
}
