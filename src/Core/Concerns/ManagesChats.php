<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Core\ChatRights;
use LaraGram\MTProto\Core\PeerResolver;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait ManagesChats
{
    public function banChatMember(string|int|array $peer, string|int|array $user, int $until = 0): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.editBanned', [
                'channel' => $peer,
                'participant' => $user,
                'banned_rights' => ChatRights::banAll($until),
            ]);
        }

        return $this->invoke('messages.deleteChatUser', [
            'chat_id' => $input['chat_id'],
            'user_id' => $user,
        ]);
    }

    public function unbanChatMember(string|int|array $peer, string|int|array $user): mixed
    {
        $this->assertChannel($peer, 'unbanChatMember');

        return $this->invoke('channels.editBanned', [
            'channel' => $peer,
            'participant' => $user,
            'banned_rights' => ChatRights::unban(),
        ]);
    }

    public function kickChatMember(string|int|array $peer, string|int|array $user): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            $this->invoke('channels.editBanned', [
                'channel' => $peer,
                'participant' => $user,
                'banned_rights' => ChatRights::banAll(0),
            ]);

            return $this->invoke('channels.editBanned', [
                'channel' => $peer,
                'participant' => $user,
                'banned_rights' => ChatRights::unban(),
            ]);
        }

        return $this->invoke('messages.deleteChatUser', [
            'chat_id' => $input['chat_id'],
            'user_id' => $user,
        ]);
    }

    /**
     * @param list<string>|array<string,bool>|string $restrictions
     */
    public function restrictChatMember(string|int|array $peer, string|int|array $user, array|string $restrictions, int $until = 0): mixed
    {
        $this->assertChannel($peer, 'restrictChatMember');

        return $this->invoke('channels.editBanned', [
            'channel' => $peer,
            'participant' => $user,
            'banned_rights' => ChatRights::banned($restrictions, $until),
        ]);
    }

    /**
     * @see ChatRights::ADMIN_FLAGS
     *
     * @param list<string>|array<string,bool>|string $rights
     */
    public function promoteChatMember(string|int|array $peer, string|int|array $user, array|string $rights = [], string $rank = ''): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.editAdmin', [
                'channel' => $peer,
                'user_id' => $user,
                'admin_rights' => ChatRights::admin($rights),
                'rank' => $rank,
            ]);
        }

        return $this->invoke('messages.editChatAdmin', [
            'chat_id' => $input['chat_id'],
            'user_id' => $user,
            'is_admin' => true,
        ]);
    }

    public function demoteChatMember(string|int|array $peer, string|int|array $user): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.editAdmin', [
                'channel' => $peer,
                'user_id' => $user,
                'admin_rights' => ChatRights::demote(),
                'rank' => '',
            ]);
        }

        return $this->invoke('messages.editChatAdmin', [
            'chat_id' => $input['chat_id'],
            'user_id' => $user,
            'is_admin' => false,
        ]);
    }

    /**
     * @param string|int|array|list<string|int|array> $users
     */
    public function addChatMembers(string|int|array $peer, string|int|array $users, int $fwdLimit = 100): mixed
    {
        $input = $this->inputPeerFor($peer);
        $list = $this->userList($users);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.inviteToChannel', [
                'channel' => $peer,
                'users' => $list,
            ]);
        }

        $result = null;
        foreach ($list as $user) {
            $result = $this->invoke('messages.addChatUser', [
                'chat_id' => $input['chat_id'],
                'user_id' => $user,
                'fwd_limit' => $fwdLimit,
            ]);
        }

        return $result;
    }

    public function joinChat(string|int|array $peer): mixed
    {
        if (is_string($peer)) {
            $ref = $this->requireResolver()->parseReference($peer);
            if ($ref['kind'] === 'invite') {
                return $this->invoke('messages.importChatInvite', ['hash' => $ref['value']]);
            }
        }

        return $this->invoke('channels.joinChannel', ['channel' => $peer]);
    }

    public function leaveChat(string|int|array $peer): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.leaveChannel', ['channel' => $peer]);
        }

        return $this->invoke('messages.deleteChatUser', [
            'chat_id' => $input['chat_id'],
            'user_id' => 'me',
        ]);
    }

    /**
     * @param list<string|int|array> $users
     */
    public function createGroup(string $title, array $users = []): mixed
    {
        return $this->invoke('messages.createChat', [
            'users' => $this->userList($users),
            'title' => $title,
        ]);
    }

    public function createSupergroup(string $title, string $about = ''): mixed
    {
        return $this->invoke('channels.createChannel', [
            'megagroup' => true,
            'title' => $title,
            'about' => $about,
        ]);
    }

    public function createChannel(string $title, string $about = ''): mixed
    {
        return $this->invoke('channels.createChannel', [
            'broadcast' => true,
            'title' => $title,
            'about' => $about,
        ]);
    }

    public function deleteChat(string|int|array $peer): mixed
    {
        $this->assertChannel($peer, 'deleteChat');

        return $this->invoke('channels.deleteChannel', ['channel' => $peer]);
    }

    public function setChatTitle(string|int|array $peer, string $title): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.editTitle', ['channel' => $peer, 'title' => $title]);
        }

        return $this->invoke('messages.editChatTitle', ['chat_id' => $input['chat_id'], 'title' => $title]);
    }

    public function setChatDescription(string|int|array $peer, string $about): mixed
    {
        return $this->invoke('messages.editChatAbout', ['peer' => $peer, 'about' => $about]);
    }

    public function setChatPhoto(string|int|array $peer, string $path): mixed
    {
        $photo = ['_' => 'inputChatUploadedPhoto', 'file' => $this->uploadFile($path)];

        return $this->applyChatPhoto($peer, $photo);
    }

    public function deleteChatPhoto(string|int|array $peer): mixed
    {
        return $this->applyChatPhoto($peer, ['_' => 'inputChatPhotoEmpty']);
    }

    public function pinMessage(string|int|array $peer, int $id, bool $silent = false, bool $oneSide = false): mixed
    {
        $params = ['peer' => $peer, 'id' => $id];
        if ($silent) {
            $params['silent'] = true;
        }
        if ($oneSide) {
            $params['pm_oneside'] = true;
        }

        return $this->invoke('messages.updatePinnedMessage', $params);
    }

    public function unpinMessage(string|int|array $peer, int $id): mixed
    {
        return $this->invoke('messages.updatePinnedMessage', [
            'peer' => $peer,
            'id' => $id,
            'unpin' => true,
        ]);
    }

    public function unpinAllMessages(string|int|array $peer): mixed
    {
        return $this->invoke('messages.unpinAllMessages', ['peer' => $peer]);
    }

    /**
     * @param list<int> $ids
     */
    public function deleteChatMessages(string|int|array $peer, array $ids, bool $revoke = true): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.deleteMessages', ['channel' => $peer, 'id' => $ids]);
        }

        return $this->invoke('messages.deleteMessages', ['id' => $ids, 'revoke' => $revoke]);
    }

    public function exportInviteLink(string|int|array $peer, array $params = []): mixed
    {
        return $this->invoke('messages.exportChatInvite', array_merge(['peer' => $peer], $params));
    }

    public function getInviteLinks(string|int|array $peer, array $params = []): mixed
    {
        return $this->invoke('messages.getExportedChatInvites', array_merge([
            'peer' => $peer,
            'admin_id' => $params['admin_id'] ?? 'me',
            'limit' => $params['limit'] ?? 100,
        ], array_diff_key($params, ['admin_id' => 0, 'limit' => 0])));
    }

    public function editInviteLink(string|int|array $peer, string $link, array $params = []): mixed
    {
        return $this->invoke('messages.editExportedChatInvite', array_merge([
            'peer' => $peer,
            'link' => $link,
        ], $params));
    }

    public function revokeInviteLink(string|int|array $peer, string $link): mixed
    {
        return $this->invoke('messages.editExportedChatInvite', [
            'peer' => $peer,
            'link' => $link,
            'revoked' => true,
        ]);
    }

    public function deleteInviteLink(string|int|array $peer, string $link): mixed
    {
        return $this->invoke('messages.deleteExportedChatInvite', ['peer' => $peer, 'link' => $link]);
    }

    private function applyChatPhoto(string|int|array $peer, array $photo): mixed
    {
        $input = $this->inputPeerFor($peer);

        if ($this->isChannelInput($input)) {
            return $this->invoke('channels.editPhoto', ['channel' => $peer, 'photo' => $photo]);
        }

        return $this->invoke('messages.editChatPhoto', ['chat_id' => $input['chat_id'], 'photo' => $photo]);
    }

    private function isChannelInput(array $input): bool
    {
        return ($input['_'] ?? '') === 'inputPeerChannel';
    }

    /**
     * @return array{_: string, ...}
     */
    private function inputPeerFor(string|int|array $peer): array
    {
        return $this->requireResolver()->resolveInputPeer($peer);
    }

    private function assertChannel(string|int|array $peer, string $method): void
    {
        if (!$this->isChannelInput($this->inputPeerFor($peer))) {
            throw new MTProtoException("{$method}() requires a supergroup or channel peer.");
        }
    }

    private function requireResolver(): PeerResolver
    {
        $resolver = $this->getResolver();
        if ($resolver === null) {
            throw new MTProtoException('Peer resolver unavailable - no peer database is configured.');
        }

        return $resolver;
    }

    /**
     * @param string|int|array|list<string|int|array> $users
     * @return list<string|int|array>
     */
    private function userList(string|int|array $users): array
    {
        if (is_array($users) && !isset($users['_']) && array_is_list($users)) {
            return $users;
        }

        return [$users];
    }
}
