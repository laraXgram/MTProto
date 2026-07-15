<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesEphemeral
{
    /**
     * Send an ephemeral message to `$receiver` in the context of `$peer`.
     *
     * @param array<string, mixed> $params Extra TL fields: query_id, entities,
     *        media, reply_markup, rich_message, reply_to, parse_mode.
     */
    public function sendEphemeral(
        string|int|array $peer,
        string|int|array $receiver,
        string $message,
        array $params = [],
    ): mixed {
        return $this->invoke('ephemeral.sendMessage', array_merge([
            'peer' => $peer,
            'receiver_id' => $receiver,
            'message' => $message,
        ], $params));
    }

    /**
     * Edit a previously sent ephemeral message.
     *
     * @param array<string, mixed> $params Extra TL fields: message, media,
     *        entities, reply_markup, parse_mode.
     */
    public function editEphemeral(
        string|int|array $peer,
        string|int|array $receiver,
        int $id,
        array $params = [],
    ): mixed {
        return $this->invoke('ephemeral.editMessage', array_merge([
            'peer' => $peer,
            'receiver_id' => $receiver,
            'id' => $id,
        ], $params));
    }

    /**
     * Delete an ephemeral message.
     */
    public function deleteEphemeral(
        string|int|array $peer,
        string|int|array $receiver,
        int $id,
    ): mixed {
        return $this->invoke('ephemeral.deleteMessage', [
            'peer' => $peer,
            'receiver_id' => $receiver,
            'id' => $id,
        ]);
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
