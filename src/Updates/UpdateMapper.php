<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Core\PeerDatabase;
use LaraGram\MTProto\Entities\EntityType;

/**
 * Maps raw MTProto TL update constructors to Bot-API-shaped arrays so MTProto
 * updates can flow through the same Listening system as Bot-API updates
 * (Phase 2.1).
 *
 * Pure transformation: peers are read from the {@see PeerDatabase} cache; nothing
 * here performs network I/O. Fields that require a fetch (full `reply_to_message`,
 * media `file_id`) are left for the higher layer — see the inline notes.
 *
 * Bot-API id conventions: user = positive id, basic group = `-id`,
 * channel/supergroup = `-100<id>` (i.e. `-(1_000_000_000_000 + id)`).
 */
final class UpdateMapper
{
    private const SUPERGROUP_BASE = 1_000_000_000_000;

    public function __construct(private readonly PeerDatabase $peers) {}

    /**
     * Convert one TL update to `[botApiKey => payload]`, or null when the
     * update has no Bot-API equivalent (handled as a client-only verb instead).
     *
     * @param array<string, mixed> $update Deserialized TL update constructor.
     * @return array<string, mixed>|null
     */
    public function toBotApi(array $update): ?array
    {
        $type = $update['_'] ?? '';

        return match ($type) {
            'updateNewMessage', 'updateNewChannelMessage'
                => $this->wrapMessage($update['message'] ?? [], edited: false),
            'updateEditMessage', 'updateEditChannelMessage'
                => $this->wrapMessage($update['message'] ?? [], edited: true),

            'updateBotCallbackQuery', 'updateInlineBotCallbackQuery'
                => ['callback_query' => $this->mapCallbackQuery($update)],
            'updateBotInlineQuery'   => ['inline_query' => $this->mapInlineQuery($update)],
            'updateBotInlineSend'    => ['chosen_inline_result' => $this->mapChosenInline($update)],

            'updateBotPrecheckoutQuery' => ['pre_checkout_query' => $this->mapPrecheckout($update)],
            'updateBotShippingQuery'    => ['shipping_query' => $this->mapShipping($update)],

            'updateChannelParticipant', 'updateChatParticipant'
                => ['chat_member' => $this->mapChatMember($update)],
            'updateBotChatInviteRequester'
                => ['chat_join_request' => $this->mapJoinRequest($update)],

            'updateMessagePoll'        => ['poll' => $this->mapPoll($update)],
            'updateMessagePollVote'    => ['poll_answer' => $this->mapPollVote($update)],

            'updateBotMessageReaction'  => ['message_reaction' => $update],
            'updateBotMessageReactions' => ['message_reaction_count' => $update],

            'updateBotBusinessConnect'     => ['business_connection' => $update],
            'updateBotNewBusinessMessage'  => ['business_message' => $this->mapMessage($update['message'] ?? [])],
            'updateBotEditBusinessMessage' => ['edited_business_message' => $this->mapMessage($update['message'] ?? [])],
            'updateBotDeleteBusinessMessage' => ['deleted_business_messages' => $update],

            default => null,
        };
    }

    /**
     * Pick message vs channel_post (broadcast) / edited_* by the peer kind.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    private function wrapMessage(array $message, bool $edited): ?array
    {
        if ($message === [] || ($message['_'] ?? '') === 'messageEmpty') {
            return null;
        }

        $mapped     = $this->mapMessage($message);
        $isBroadcast = ($mapped['chat']['type'] ?? '') === 'channel';

        $key = match (true) {
            $edited && $isBroadcast  => 'edited_channel_post',
            $edited                  => 'edited_message',
            $isBroadcast             => 'channel_post',
            default                  => 'message',
        };

        return [$key => $mapped];
    }

    /**
     * TL message → Bot-API Message (text/entities/from/chat/date subset).
     *
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    public function mapMessage(array $m): array
    {
        $out = [
            'message_id' => $m['id'] ?? 0,
            'date'       => $m['date'] ?? 0,
            'chat'       => $this->mapChat($m['peer_id'] ?? []),
        ];

        $from = $this->mapUserFromPeer($m['from_id'] ?? null);
        if ($from !== null) {
            $out['from'] = $from;
        }

        if (isset($m['message']) && $m['message'] !== '') {
            $out['text'] = $m['message'];
        }

        $entities = $this->mapEntities($m['entities'] ?? []);
        if ($entities !== []) {
            $out['entities'] = $entities;
        }

        // reply_to_message needs a messages.getMessages fetch — surface the id only;
        // the higher layer can hydrate it lazily.
        if (isset($m['reply_to']['reply_to_msg_id'])) {
            $out['reply_to_message_id'] = $m['reply_to']['reply_to_msg_id'];
        }

        if (!empty($m['out'])) {
            $out['outgoing'] = true;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    private function mapEntities(array $entities): array
    {
        $out = [];
        foreach ($entities as $e) {
            if (!is_array($e)) {
                continue;
            }
            $type = EntityType::toBotApi($e);
            if ($type === null) {
                continue;
            }

            $mapped = ['type' => $type, 'offset' => $e['offset'] ?? 0, 'length' => $e['length'] ?? 0];

            if (isset($e['url'])) {
                $mapped['url'] = $e['url'];
            }
            if (isset($e['language'])) {
                $mapped['language'] = $e['language'];
            }
            if (isset($e['document_id'])) {
                $mapped['custom_emoji_id'] = (string) $e['document_id'];
            }
            if (isset($e['user_id'])) {
                $mapped['user'] = $this->buildUser((int) $e['user_id']);
            }

            $out[] = $mapped;
        }

        return $out;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Peer / User / Chat
    // ════════════════════════════════════════════════════════════════════

    /**
     * A TL Peer (peerUser/peerChat/peerChannel) → Bot-API Chat.
     *
     * @param array<string, mixed> $peer
     * @return array<string, mixed>
     */
    public function mapChat(array $peer): array
    {
        $kind = $peer['_'] ?? '';

        if ($kind === 'peerUser') {
            $id    = (int) ($peer['user_id'] ?? 0);
            $entry = $this->peers->getPeer($id);
            return array_filter([
                'id'         => $id,
                'type'       => 'private',
                'username'   => $entry['username'] ?? null,
                'first_name' => $entry['first_name'] ?? null,
                'last_name'  => $entry['last_name'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        if ($kind === 'peerChat') {
            $id    = (int) ($peer['chat_id'] ?? 0);
            $entry = $this->peers->getPeer($id);
            return array_filter([
                'id'    => -$id,
                'type'  => 'group',
                'title' => $entry['first_name'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        if ($kind === 'peerChannel') {
            $id    = (int) ($peer['channel_id'] ?? 0);
            $entry = $this->peers->getPeer($id);
            $type  = ($entry['type'] ?? PeerDatabase::TYPE_CHANNEL) === PeerDatabase::TYPE_SUPERGROUP
                ? 'supergroup'
                : 'channel';
            return array_filter([
                'id'       => -(self::SUPERGROUP_BASE + $id),
                'type'     => $type,
                'username' => $entry['username'] ?? null,
                'title'    => $entry['first_name'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        return ['id' => 0, 'type' => 'private'];
    }

    /**
     * A from_id Peer → Bot-API User (null when absent).
     *
     * @param array<string, mixed>|null $peer
     * @return array<string, mixed>|null
     */
    private function mapUserFromPeer(?array $peer): ?array
    {
        if (!is_array($peer) || ($peer['_'] ?? '') !== 'peerUser') {
            return null;
        }
        return $this->buildUser((int) ($peer['user_id'] ?? 0));
    }

    /**
     * Bot-API User from the peer cache (id-only fallback on cache miss).
     *
     * @return array<string, mixed>
     */
    public function buildUser(int $id): array
    {
        $entry = $this->peers->getPeer($id);

        return array_filter([
            'id'         => $id,
            'is_bot'     => ($entry['type'] ?? null) === PeerDatabase::TYPE_BOT,
            'first_name' => $entry['first_name'] ?? null,
            'last_name'  => $entry['last_name'] ?? null,
            'username'   => $entry['username'] ?? null,
        ], static fn ($v) => $v !== null && $v !== false);
    }

    // ════════════════════════════════════════════════════════════════════
    //  Query / member mappers
    // ════════════════════════════════════════════════════════════════════

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapCallbackQuery(array $u): array
    {
        return array_filter([
            'id'            => $u['query_id'] ?? null,
            'from'          => isset($u['user_id']) ? $this->buildUser((int) $u['user_id']) : null,
            'message_id'    => $u['msg_id'] ?? null,
            'chat_instance' => $u['chat_instance'] ?? null,
            'data'          => isset($u['data']) ? (string) $u['data'] : null,
            'game_short_name' => $u['game_short_name'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapInlineQuery(array $u): array
    {
        return array_filter([
            'id'     => $u['query_id'] ?? null,
            'from'   => isset($u['user_id']) ? $this->buildUser((int) $u['user_id']) : null,
            'query'  => $u['query'] ?? null,
            'offset' => $u['offset'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapChosenInline(array $u): array
    {
        return array_filter([
            'result_id'         => $u['id'] ?? null,
            'from'              => isset($u['user_id']) ? $this->buildUser((int) $u['user_id']) : null,
            'query'             => $u['query'] ?? null,
            'inline_message_id' => $u['msg_id'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapPrecheckout(array $u): array
    {
        return array_filter([
            'id'                 => $u['query_id'] ?? null,
            'from'               => isset($u['user_id']) ? $this->buildUser((int) $u['user_id']) : null,
            'currency'           => $u['currency'] ?? null,
            'total_amount'       => $u['total_amount'] ?? null,
            'invoice_payload'    => isset($u['payload']) ? (string) $u['payload'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapShipping(array $u): array
    {
        return array_filter([
            'id'              => $u['query_id'] ?? null,
            'from'            => isset($u['user_id']) ? $this->buildUser((int) $u['user_id']) : null,
            'invoice_payload' => isset($u['payload']) ? (string) $u['payload'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapChatMember(array $u): array
    {
        $chatId = isset($u['channel_id'])
            ? -(self::SUPERGROUP_BASE + (int) $u['channel_id'])
            : (isset($u['chat_id']) ? -(int) $u['chat_id'] : 0);

        return array_filter([
            'chat'            => ['id' => $chatId],
            'from'            => isset($u['actor_id']) ? $this->buildUser((int) $u['actor_id']) : null,
            'date'            => $u['date'] ?? null,
            'old_chat_member' => $u['prev_participant'] ?? null,
            'new_chat_member' => $u['new_participant'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapJoinRequest(array $u): array
    {
        return array_filter([
            'chat' => isset($u['peer']) ? $this->mapChat($u['peer']) : null,
            'from' => isset($u['user_id']) ? $this->buildUser((int) $u['user_id']) : null,
            'date' => $u['date'] ?? null,
            'bio'  => $u['about'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapPoll(array $u): array
    {
        return array_filter([
            'poll_id' => $u['poll_id'] ?? null,
            'poll'    => $u['poll'] ?? null,
            'results' => $u['results'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function mapPollVote(array $u): array
    {
        return array_filter([
            'poll_id'    => $u['poll_id'] ?? null,
            'voter'      => isset($u['peer']) ? $this->mapChat($u['peer']) : null,
            'option_ids' => $u['options'] ?? null,
        ], static fn ($v) => $v !== null);
    }
}
