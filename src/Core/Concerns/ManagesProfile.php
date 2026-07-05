<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait ManagesProfile
{
    public function setProfilePhoto(string $path): mixed
    {
        return $this->invoke('photos.uploadProfilePhoto', ['file' => $this->uploadFile($path)]);
    }

    /**
     * @param array|list<array> $photos an InputPhoto or list of them
     */
    public function deleteProfilePhotos(array $photos): mixed
    {
        $list = array_is_list($photos) ? $photos : [$photos];

        return $this->invoke('photos.deletePhotos', ['id' => $list]);
    }

    public function getUserPhotos(string|int|array $user = 'me', int $offset = 0, int $limit = 100): mixed
    {
        return $this->invoke('photos.getUserPhotos', [
            'user_id' => $user,
            'offset' => $offset,
            'max_id' => 0,
            'limit' => $limit,
        ]);
    }

    public function updateProfile(array $params): mixed
    {
        return $this->invoke('account.updateProfile', $params);
    }

    public function setUsername(string $username): mixed
    {
        return $this->invoke('account.updateUsername', ['username' => $username]);
    }

    public function setOnline(bool $online = true): mixed
    {
        return $this->invoke('account.updateStatus', ['offline' => !$online]);
    }

    public function getContacts(): mixed
    {
        return $this->invoke('contacts.getContacts', ['hash' => 0]);
    }

    public function addContact(string|int|array $user, string $firstName, string $lastName = '', string $phone = ''): mixed
    {
        return $this->invoke('contacts.addContact', [
            'id' => $user,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
        ]);
    }

    /**
     * @param string|int|array|list<string|int|array> $users
     */
    public function deleteContacts(string|int|array $users): mixed
    {
        $list = is_array($users) && array_is_list($users) && !isset($users['_']) ? $users : [$users];

        return $this->invoke('contacts.deleteContacts', ['id' => $list]);
    }

    public function setEmojiStatus(int|array|null $emoji, int $until = 0): mixed
    {
        if ($emoji === null) {
            $status = ['_' => 'emojiStatusEmpty'];
        } elseif (is_array($emoji)) {
            $status = $emoji;
        } else {
            $status = ['_' => 'emojiStatus', 'document_id' => $emoji];
            if ($until > 0) {
                $status['until'] = $until;
            }
        }

        return $this->invoke('account.updateEmojiStatus', ['emoji_status' => $status]);
    }
}
