<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesEphemeral
{
    /**
     * Send an ephemeral message to `$receiver` in the context of `$peer`
     * (null `$peer` = the receiver's private chat with the bot).
     *
     * @param array<string, mixed> $params Extra TL fields: query_id, entities,
     *        media, reply_markup, rich_message, reply_to, parse_mode, and the
     *        flags invert_media, welcome, anchor, noforwards.
     */
    public function sendEphemeral(
        string|int|array|null $peer,
        string|int|array $receiver,
        string $message,
        array $params = [],
    ): mixed {
        return $this->invoke('ephemeral.sendMessage', array_merge(array_filter([
            'peer' => $peer,
            'receiver_id' => $receiver,
            'message' => $message,
        ], static fn ($value) => $value !== null), $params));
    }

    /**
     * Edit a previously sent ephemeral message.
     *
     * @param array<string, mixed> $params Extra TL fields: message, media,
     *        entities, reply_markup, rich_message, parse_mode, and the flags
     *        invert_media, welcome.
     */
    public function editEphemeral(
        string|int|array|null $peer,
        string|int|array $receiver,
        int $id,
        array $params = [],
    ): mixed {
        return $this->invoke('ephemeral.editMessage', array_merge(array_filter([
            'peer' => $peer,
            'receiver_id' => $receiver,
            'id' => $id,
        ], static fn ($value) => $value !== null), $params));
    }

    /**
     * Delete an ephemeral message.
     */
    public function deleteEphemeral(
        string|int|array|null $peer,
        string|int|array $receiver,
        int $id,
    ): mixed {
        return $this->invoke('ephemeral.deleteMessage', array_filter([
            'peer' => $peer,
            'receiver_id' => $receiver,
            'id' => $id,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Save a welcome message template: an ephemeral message shown to users
     * who open the chat with the bot (sets the `welcome` flag).
     *
     * @param array<string, mixed> $params Same extras as {@see sendEphemeral()}.
     */
    public function sendWelcomeMessage(
        string|int|array $peer,
        string|int|array $receiver,
        string $message,
        array $params = [],
    ): mixed {
        return $this->sendEphemeral($peer, $receiver, $message, ['welcome' => true] + $params);
    }

    /**
     * List the welcome message templates of `$peer`. Pass the previous
     * response `hash` to receive `ephemeral.welcomeMessagesNotModified`.
     */
    public function getWelcomeMessages(string|int|array $peer, int $hash = 0): mixed
    {
        return $this->invoke('ephemeral.getWelcomeMessages', ['peer' => $peer, 'hash' => $hash]);
    }

    /**
     * Delete one welcome message template.
     */
    public function deleteWelcomeMessage(string|int|array $peer, int $id): mixed
    {
        return $this->invoke('ephemeral.deleteWelcomeMessage', ['peer' => $peer, 'id' => $id]);
    }

    /**
     * Delete every welcome message template of `$peer`.
     */
    public function deleteAllWelcomeMessages(string|int|array $peer): mixed
    {
        return $this->invoke('ephemeral.deleteAllWelcomeMessages', ['peer' => $peer]);
    }

    /**
     * Report an ephemeral message.
     */
    public function reportEphemeral(
        string|int|array $peer,
        int $id,
        string $option,
        string $message = '',
    ): mixed {
        return $this->invoke('ephemeral.reportMessage', [
            'peer' => $peer,
            'id' => $id,
            'option' => $option,
            'message' => $message,
        ]);
    }

    /**
     * Fetch the callback answer for an ephemeral inline-button press.
     */
    public function getEphemeralCallbackAnswer(
        string|int|array $peer,
        int $id,
        ?string $data = null,
    ): mixed {
        $params = [
            'peer' => $peer,
            'id' => $id,
        ];
        if ($data !== null) {
            $params['data'] = $data;
        }

        return $this->invoke('ephemeral.getCallbackAnswer', $params);
    }
}
