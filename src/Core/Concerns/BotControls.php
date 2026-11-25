<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Core\ChatRights;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait BotControls
{
    public function answerCallback(int $queryId, string $text = '', bool $alert = false, ?string $url = null, int $cacheTime = 0): mixed
    {
        $params = ['query_id' => $queryId, 'cache_time' => $cacheTime];
        if ($text !== '') {
            $params['message'] = $text;
        }
        if ($alert) {
            $params['alert'] = true;
        }
        if ($url !== null) {
            $params['url'] = $url;
        }

        return $this->invoke('messages.setBotCallbackAnswer', $params);
    }

    /**
     * @param list<array> $results
     */
    public function answerInlineQuery(int $queryId, array $results, array $params = []): mixed
    {
        return $this->invoke('messages.setInlineBotResults', array_merge([
            'query_id' => $queryId,
            'results' => array_values($results),
            'cache_time' => $params['cache_time'] ?? 300,
        ], array_diff_key($params, ['cache_time' => 0])));
    }

    /**
     * Confirm a pre-checkout query, or reject it by passing `$error`.
     */
    public function answerPreCheckout(int $queryId, ?string $error = null): mixed
    {
        return $this->invoke('messages.setBotPrecheckoutResults', $error === null
            ? ['query_id' => $queryId, 'success' => true]
            : ['query_id' => $queryId, 'error' => $error]);
    }

    /**
     * Answer a shipping query with `shippingOption` objects, or reject it by
     * passing `$error`.
     *
     * @param list<array> $options
     */
    public function answerShipping(int $queryId, array $options = [], ?string $error = null): mixed
    {
        return $this->invoke('messages.setBotShippingResults', $error === null
            ? ['query_id' => $queryId, 'shipping_options' => array_values($options)]
            : ['query_id' => $queryId, 'error' => $error]);
    }

    /**
     * @param array<int|string,mixed> $commands
     */
    public function setBotCommands(array $commands, string|array $scope = 'default', string $langCode = ''): mixed
    {
        return $this->invoke('bots.setBotCommands', [
            'scope' => $this->botCommandScope($scope),
            'lang_code' => $langCode,
            'commands' => $this->botCommands($commands),
        ]);
    }

    /**
     * Clear the bot's command list for a scope.
     */
    public function resetBotCommands(string|array $scope = 'default', string $langCode = ''): mixed
    {
        return $this->invoke('bots.resetBotCommands', [
            'scope' => $this->botCommandScope($scope),
            'lang_code' => $langCode,
        ]);
    }

    /**
     * Fetch the bot's command list for a scope.
     */
    public function getBotCommands(string|array $scope = 'default', string $langCode = ''): mixed
    {
        return $this->invoke('bots.getBotCommands', [
            'scope' => $this->botCommandScope($scope),
            'lang_code' => $langCode,
        ]);
    }

    public function setBotMenuButton(string|int|array $user, string|array $button = 'commands'): mixed
    {
        return $this->invoke('bots.setBotMenuButton', [
            'user_id' => $user,
            'button' => $this->botMenuButton($button),
        ]);
    }

    public function setBotInfo(array $params = []): mixed
    {
        return $this->invoke('bots.setBotInfo', array_merge(['lang_code' => ''], $params));
    }

    /**
     * Bot API names accepted by {@see setBotSettings()}, mapped to the
     * `bots.setBotSettings` flag each one sets.
     */
    private const BOT_SETTINGS = [
        'supports_inline_queries' => 'inline_mode',
        'inline_query_placeholder' => 'inline_placeholder',
        'needs_inline_query_location' => 'inline_geo',
        'inline_feedback_share_denominator' => 'inline_feedback',
        'can_join_groups' => 'nochats',
        'can_read_all_group_messages' => 'chat_history',
        'can_connect_to_business' => 'business',
        'can_manage_bots' => 'can_manage_bots',
        'supports_guest_queries' => 'guestchat',
        'supports_join_request_queries' => 'guard',
        'has_topics_enabled' => 'forum_view',
        'allows_users_to_create_topics' => 'forum_can_manage_topics',
        'has_topic_sidebar' => 'forum_sidebar',
        'can_communicate_with_bots' => 'bot_to_bot_communication_mode',
        'callback_query_mode' => 'callback_mode',
    ];

    /**
     * Change the settings BotFather manages: inline mode, group privacy,
     * topics, business connections and so on. Keys are the Bot API names
     * (`supports_inline_queries`, `can_join_groups`, `has_topic_sidebar`,
     * `callback_query_mode` = default|auto|inline, ...) or the raw
     * `bots.setBotSettings` flags; only the keys passed are changed.
     * `$bot` names a bot you own; leave it null in a bot session.
     *
     * @param array<string, bool|int|string> $settings
     */
    public function setBotSettings(array $settings, string|int|array|null $bot = null): mixed
    {
        $params = [];
        foreach ($settings as $key => $value) {
            $flag = self::BOT_SETTINGS[$key] ?? $key;
            $params[$flag] = match ($flag) {
                // The flag stores "cannot join groups".
                'nochats' => !$value,
                'inline_placeholder' => (string) $value,
                'inline_feedback' => (int) $value,
                'callback_mode' => $value === 'default' ? '' : (string) $value,
                default => (bool) $value,
            };
        }
        if ($bot !== null) {
            $params['bot'] = $bot;
        }

        return $this->invoke('bots.setBotSettings', $params);
    }

    /**
     * Invite 1-10 users to the private chat with a bot you own. Each invitee
     * gets a `messageActionBotInvite` naming you, and the bot can then message
     * them. Succeeds if at least one user was invited.
     *
     * @param string|int|array|list<string|int|array> $users
     */
    public function inviteUsersToBot(string|int|array $bot, string|int|array $users): mixed
    {
        $users = is_array($users) && array_is_list($users) ? $users : [$users];

        return $this->invoke('messages.inviteUsersToBot', ['bot' => $bot, 'users' => $users]);
    }

    /**
     * Set the bot's default admin rights suggested when added to groups (or
     * channels when `$forChannels` is true). `$rights` is a
     * {@see ChatRights::ADMIN_FLAGS} list / "all".
     *
     * @param list<string>|array<string,bool>|string $rights
     */
    public function setDefaultAdminRights(array|string $rights, bool $forChannels = false): mixed
    {
        $method = $forChannels ? 'bots.setBotBroadcastDefaultAdminRights' : 'bots.setBotGroupDefaultAdminRights';

        return $this->invoke($method, ['admin_rights' => ChatRights::admin($rights)]);
    }

    /**
     * Give a bot you own an additional (collectible) username.
     */
    public function addBotUsername(string|int|array $bot, string $username): mixed
    {
        return $this->invoke('bots.addUsername', ['bot' => $bot, 'username' => ltrim($username, '@')]);
    }

    public function removeBotUsername(string|int|array $bot, string $username): mixed
    {
        return $this->invoke('bots.removeUsername', ['bot' => $bot, 'username' => ltrim($username, '@')]);
    }

    /**
     * Check whether a username is free for a new bot, or for an additional
     * username of an existing bot when `$additional` is true.
     */
    public function checkBotUsername(string $username, bool $additional = false): bool
    {
        $params = ['username' => ltrim($username, '@')];
        if ($additional) {
            $params['additional'] = true;
        }

        return (bool) $this->invoke('bots.checkUsername', $params);
    }

    /**
     * Verify (or, with `$enabled` false, unverify) a peer as a verifier bot.
     * `$bot` names the verifier when a user account manages it.
     */
    public function setCustomVerification(
        string|int|array $peer,
        bool $enabled = true,
        ?string $description = null,
        string|int|array|null $bot = null,
    ): mixed {
        $params = ['peer' => $peer];
        if ($enabled) {
            $params['enabled'] = true;
        }
        if ($description !== null && $description !== '') {
            $params['custom_description'] = ['_' => 'textWithEntities', 'text' => $description, 'entities' => []];
        }
        if ($bot !== null) {
            $params['bot'] = $bot;
        }

        return $this->invoke('bots.setCustomVerification', $params);
    }

    private function botCommandScope(string|array $scope): array
    {
        if (is_array($scope)) {
            return $scope;
        }

        return match (strtolower($scope)) {
            'default' => ['_' => 'botCommandScopeDefault'],
            'users' => ['_' => 'botCommandScopeUsers'],
            'chats' => ['_' => 'botCommandScopeChats'],
            'chat_admins', 'chatadmins', 'admins' => ['_' => 'botCommandScopeChatAdmins'],
            default => throw new MTProtoException("Unknown bot command scope: {$scope}"),
        };
    }

    private function botMenuButton(string|array $button): array
    {
        if (is_array($button) && !isset($button['_'])) {
            return ['_' => 'botMenuButton', 'text' => $button['text'] ?? '', 'url' => $button['url'] ?? ''];
        }
        if (is_array($button)) {
            return $button;
        }

        return match (strtolower($button)) {
            'default' => ['_' => 'botMenuButtonDefault'],
            'commands' => ['_' => 'botMenuButtonCommands'],
            default => throw new MTProtoException("Unknown menu button: {$button}"),
        };
    }

    /**
     * @param array<int|string,mixed> $commands
     * @return list<array>
     */
    private function botCommands(array $commands): array
    {
        $out = [];
        foreach ($commands as $key => $value) {
            if (is_array($value)) {
                $out[] = [
                    '_' => 'botCommand',
                    'command' => ltrim((string) ($value['command'] ?? ''), '/'),
                    'description' => (string) ($value['description'] ?? ''),
                ];
            } else {
                // assoc form: ['start' => 'Begin']
                $out[] = [
                    '_' => 'botCommand',
                    'command' => ltrim((string) $key, '/'),
                    'description' => (string) $value,
                ];
            }
        }

        return $out;
    }
}
