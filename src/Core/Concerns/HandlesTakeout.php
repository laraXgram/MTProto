<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use Generator;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesTakeout
{
    /**
     * Open a takeout session and return its id.
     *
     * @param array{contacts?:bool,message_users?:bool,message_chats?:bool,message_megagroups?:bool,message_channels?:bool,files?:bool,file_max_size?:int} $opts
     */
    public function initTakeout(array $opts = []): int
    {
        $params = [];
        foreach (['contacts', 'message_users', 'message_chats', 'message_megagroups', 'message_channels', 'files'] as $flag) {
            if (!empty($opts[$flag])) {
                $params[$flag] = true;
            }
        }
        if (isset($opts['file_max_size'])) {
            $params['files'] = true;
            $params['file_max_size'] = (int) $opts['file_max_size'];
        }

        $result = $this->invoke('account.initTakeoutSession', $params);
        $id = (int) ($result['id'] ?? 0);

        if ($id === 0) {
            throw new MTProtoException('account.initTakeoutSession returned no takeout id.');
        }

        return $id;
    }

    /**
     * Run any RPC inside the takeout session.
     */
    public function invokeWithTakeout(int $takeoutId, string $method, array $params = []): mixed
    {
        return $this->invoke('invokeWithTakeoutId', [
            'takeout_id' => $takeoutId,
            'query' => array_merge(['_' => $method], $params),
        ]);
    }

    /**
     * Close a takeout session. Pass $success=false to let Telegram allow the
     * export to be restarted later (used on error).
     */
    public function finishTakeout(int $takeoutId, bool $success = true): bool
    {
        $this->invokeWithTakeout($takeoutId, 'account.finishTakeoutSession', ['success' => $success]);

        return true;
    }

    /**
     * Open a takeout, run $callback($takeoutId), and always close it - finishing
     * with success only when the callback returned cleanly.
     *
     * @template T
     * @param callable(int):T $callback
     * @return T
     */
    public function takeout(callable $callback, array $opts = [])
    {
        $id = $this->initTakeout($opts);

        try {
            $result = $callback($id);
        } catch (\Throwable $e) {
            try {
                $this->finishTakeout($id, false);
            } catch (\Throwable) {
                // Closing after a failure is best-effort; the export can be restarted.
            }
            throw $e;
        }

        $this->finishTakeout($id, true);

        return $result;
    }

    /**
     * Lazily page a chat's full history through the takeout session (oldest→newest
     * ordering follows the server; Telegram returns newest-first slices).
     *
     * @return Generator<int, array>
     */
    public function iterateTakeoutHistory(
        int $takeoutId,
        string|int|array $peer,
        ?int $limit = null,
        int $pageSize = 100,
    ): Generator {
        $offsetId = 0;
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $result = $this->invokeWithTakeout($takeoutId, 'messages.getHistory', [
                'peer' => $peer,
                'offset_id' => $offsetId,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => $take,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
            ]);

            $messages = is_array($result) ? ($result['messages'] ?? []) : [];

            foreach ($messages as $message) {
                yield $message;
                $offsetId = $message['id'] ?? $offsetId;
                if (++$yielded === $limit) {
                    return;
                }
            }
        } while ($messages !== []);
    }

    /**
     * Fetch the account's saved contacts through the takeout session.
     */
    public function takeoutContacts(int $takeoutId): array
    {
        $result = $this->invokeWithTakeout($takeoutId, 'contacts.getContacts', ['hash' => 0]);

        return is_array($result) ? $result : [];
    }
}
