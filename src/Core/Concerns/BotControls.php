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
