<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * Using other bots from a user account: query them inline and press their
 * inline buttons.
 *
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait InteractsWithBots
{
    /**
     * Ask a bot for inline results, as typing "@bot query" in `$peer` would.
     */
    public function inlineQuery(
        string|int|array $bot,
        string $query = '',
        string|int|array $peer = 'me',
        string $offset = '',
        ?array $location = null,
    ): mixed {
        $params = [
            'bot' => $bot,
            'peer' => $peer,
            'query' => $query,
            'offset' => $offset,
        ];
        if ($location !== null) {
            $params['geo_point'] = ['_' => 'inputGeoPoint', 'lat' => $location[0], 'long' => $location[1]];
        }

        return $this->invoke('messages.getInlineBotResults', $params);
    }

    /**
     * Send one of the results returned by {@see inlineQuery()}.
     * Extra `messages.sendInlineBotResult` params (silent, reply_to, hide_via, ...)
     * pass through $params.
     */
    public function sendInlineResult(string|int|array $peer, int $queryId, string $resultId, array $params = []): mixed
    {
        return $this->invoke('messages.sendInlineBotResult', array_merge([
            'peer' => $peer,
            'query_id' => $queryId,
            'id' => $resultId,
        ], $params));
    }

    /**
     * Query a bot inline in `$peer` and send its result at `$index`.
     */
    public function sendInlineQuery(
        string|int|array $peer,
        string|int|array $bot,
        string $query,
        int $index = 0,
        array $params = [],
    ): mixed {
        $results = $this->inlineQuery($bot, $query, $peer);
        $result = $results['results'][$index] ?? null;

        if ($result === null) {
            throw new MTProtoException("The bot returned no inline result at index {$index}.");
        }

        return $this->sendInlineResult($peer, (int) $results['query_id'], (string) $result['id'], $params);
    }

    /**
     * Press an inline callback button: `$data` is the button's callback data.
     * Buttons that ask for the 2FA password (e.g. a bot ownership transfer)
     * take it as `$password`.
     */
    public function getCallbackAnswer(
        string|int|array $peer,
        int $msgId,
        string $data,
        ?string $password = null,
        bool $game = false,
    ): mixed {
        $params = ['peer' => $peer, 'msg_id' => $msgId];
        if ($game) {
            $params['game'] = true;
        } else {
            $params['data'] = $data;
        }
        if ($password !== null) {
            $params['password'] = $this->passwordCheck($password);
        }

        return $this->invoke('messages.getBotCallbackAnswer', $params);
    }
}
