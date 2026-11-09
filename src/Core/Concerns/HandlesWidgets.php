<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Foundation\InputMedia;
use LaraGram\MTProto\Foundation\WidgetQuery;
use LaraGram\MTProto\TL\TLObject;

/**
 * HTML widgets in messages (layer 233). Sending, editing and answering are
 * for bots; any session can read a widget and send it queries.
 *
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesWidgets
{
    /**
     * Send an HTML widget. Recognised $params: width, height (0 lets the client
     * size it), public_data (kept in copies and forwards), chat_data (specific
     * to this chat), allow_queries, shared (make it resendable by its
     * `widget_id`). Other `messages.sendMedia` params pass through.
     */
    public function sendWidget(string|int|array $peer, string $html, ?string $caption = null, array $params = []): mixed
    {
        $media = InputMedia::widget(
            $html,
            (int)($params['width'] ?? 0),
            (int)($params['height'] ?? 0),
            isset($params['public_data']) ? (string)$params['public_data'] : null,
            isset($params['chat_data']) ? (string)$params['chat_data'] : null,
            (bool)($params['allow_queries'] ?? false),
            (bool)($params['shared'] ?? false),
        );
        unset($params['width'], $params['height'], $params['public_data'], $params['chat_data'], $params['allow_queries'], $params['shared']);

        return $this->sendUploadedMedia($peer, $media, $caption, $params);
    }

    /**
     * Send an existing shared widget by its id. Recognised $params: chat_data.
     * Other `messages.sendMedia` params pass through.
     */
    public function sendSharedWidget(string|int|array $peer, int $widgetId, ?string $caption = null, array $params = []): mixed
    {
        $media = InputMedia::sharedWidget(
            $widgetId,
            isset($params['chat_data']) ? (string)$params['chat_data'] : null,
        );
        unset($params['chat_data']);

        return $this->sendUploadedMedia($peer, $media, $caption, $params);
    }

    /**
     * Replace the public and/or chat data of a widget message the bot sent.
     */
    public function editWidget(string|int|array $peer, int $msgId, ?string $publicData = null, ?string $chatData = null): mixed
    {
        return $this->invoke('messages.editMessageWidgetData', array_merge(
            ['peer' => $peer, 'id' => $msgId],
            $this->widgetDataParams($publicData, $chatData),
        ));
    }

    /**
     * Replace the public and/or chat data of a widget sent via the bot inline.
     * `$id` is the update's `inline_message_id`.
     */
    public function editInlineWidget(array|TLObject $id, ?string $publicData = null, ?string $chatData = null): mixed
    {
        return $this->invoke('messages.editInlineBotMessageWidgetData', array_merge(
            ['id' => $id instanceof TLObject ? $id->toArray() : $id],
            $this->widgetDataParams($publicData, $chatData),
        ));
    }

    /**
     * Replace the public data of a shared widget everywhere it is shown.
     * `$cacheTime` is how long clients may cache it, in seconds.
     */
    public function editSharedWidget(int $widgetId, string $publicData, ?int $cacheTime = null): mixed
    {
        $params = ['widget_id' => $widgetId, 'public_data' => $publicData];
        if ($cacheTime !== null) {
            $params['cache_time'] = $cacheTime;
        }

        return $this->invoke('messages.editWidgetData', $params);
    }

    /**
     * Answer a query received through `Client::onWidgetQuery()`.
     */
    public function answerWidgetQuery(int $queryId, ?string $result = null): mixed
    {
        $params = ['query_id' => $queryId];
        if ($result !== null) {
            $params['result'] = $result;
        }

        return $this->invoke('messages.setBotWidgetQueryAnswer', $params);
    }

    /**
     * Send a query to the bot behind a widget message and return its answer.
     * `$queryType` comes from {@see WidgetQuery}; the default is a plain data
     * query. Throws `BOT_RESPONSE_TIMEOUT` when the bot does not answer in time.
     */
    public function queryWidget(string|int|array $peer, int $msgId, string $data = '', ?array $queryType = null): ?string
    {
        $answer = $this->invoke('messages.getBotWidgetQueryAnswer', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'query_type' => $queryType ?? WidgetQuery::data(),
            'data' => $data,
        ]);

        return $answer['result'] ?? null;
    }

    /**
     * The current public data of a widget: `messages.widgetPublicData`, or
     * `messages.widgetPublicDataNotModified` when `$hash` still matches. Its
     * `period` is how many seconds to wait before polling again.
     */
    public function getWidgetPublicData(string|int|array $peer, int $msgId, int $hash = 0): mixed
    {
        return $this->invoke('messages.getWidgetPublicData', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'hash' => $hash,
        ]);
    }

    /**
     * The HTML of a received widget, from a message, its `messageMediaWidget`
     * or a `botInlineMessageWidget`. Null when `$source` holds no valid widget.
     */
    public function widgetHtml(array|TLObject $source): ?string
    {
        $data = $source instanceof TLObject ? $source->toArray() : $source;
        $media = $data['media'] ?? $data;
        $media = $media instanceof TLObject ? $media->toArray() : $media;

        if (!is_array($media) || !in_array($media['_'] ?? '', ['messageMediaWidget', 'botInlineMessageWidget'], true)) {
            return null;
        }

        $html = is_string($media['payload'] ?? null) ? @zlib_decode($media['payload']) : false;

        return is_string($html) && mb_check_encoding($html, 'UTF-8') ? $html : null;
    }

    /**
     * @return array<string, string>
     */
    private function widgetDataParams(?string $publicData, ?string $chatData): array
    {
        if ($publicData === null && $chatData === null) {
            throw new MTProtoException('Pass public data, chat data or both to edit a widget.');
        }

        return array_filter(
            ['public_data' => $publicData, 'chat_data' => $chatData],
            static fn (?string $value): bool => $value !== null,
        );
    }
}
