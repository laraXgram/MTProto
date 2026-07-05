<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesDrafts
{
    public function saveDraft(string|int|array $peer, string $message = '', array $params = []): mixed
    {
        return $this->invoke('messages.saveDraft', array_merge([
            'peer' => $peer,
            'message' => $message,
        ], $params));
    }

    public function getAllDrafts(): mixed
    {
        return $this->invoke('messages.getAllDrafts', []);
    }

    public function clearAllDrafts(): mixed
    {
        return $this->invoke('messages.clearAllDrafts', []);
    }

    /**
     * @param list<string> $items
     */
    public function sendChecklist(string|int|array $peer, string $title, array $items, array $params = []): mixed
    {
        $todo = $this->buildTodoList(
            $title,
            $items,
            (bool) ($params['others_can_append'] ?? false),
            (bool) ($params['others_can_complete'] ?? false),
        );
        $message = $params['message'] ?? null;
        unset($params['others_can_append'], $params['others_can_complete'], $params['message']);

        return $this->sendMedia($peer, ['_' => 'inputMediaTodo', 'todo' => $todo], $message, $params);
    }

    /**
     * @param list<int> $completed
     * @param list<int> $incompleted
     */
    public function toggleChecklistItems(string|int|array $peer, int $msgId, array $completed = [], array $incompleted = []): mixed
    {
        return $this->invoke('messages.toggleTodoCompleted', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'completed' => array_values($completed),
            'incompleted' => array_values($incompleted),
        ]);
    }

    /**
     * @param list<string> $items
     */
    public function appendChecklistItems(string|int|array $peer, int $msgId, array $items, int $startId = 1): mixed
    {
        return $this->invoke('messages.appendTodoList', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'list' => $this->buildTodoItems($items, $startId),
        ]);
    }

    public function toggleSuggestedPost(string|int|array $peer, int $msgId, bool $accept = true, array $params = []): mixed
    {
        $call = ['peer' => $peer, 'msg_id' => $msgId];
        if (!$accept) {
            $call['reject'] = true;
        }
        foreach (['schedule_date', 'reject_comment'] as $k) {
            if (isset($params[$k])) {
                $call[$k] = $params[$k];
            }
        }

        return $this->invoke('messages.toggleSuggestedPostApproval', $call);
    }

    /**
     * @param list<string> $items
     */
    private function buildTodoList(string $title, array $items, bool $othersAppend, bool $othersComplete): array
    {
        $todo = [
            '_' => 'todoList',
            'title' => ['_' => 'textWithEntities', 'text' => $title, 'entities' => []],
            'list' => $this->buildTodoItems($items, 1),
        ];
        if ($othersAppend) {
            $todo['others_can_append'] = true;
        }
        if ($othersComplete) {
            $todo['others_can_complete'] = true;
        }

        return $todo;
    }

    /**
     * @param list<string> $items
     * @return list<array>
     */
    private function buildTodoItems(array $items, int $startId): array
    {
        $out = [];
        $id = $startId;
        foreach ($items as $item) {
            $out[] = [
                '_' => 'todoItem',
                'id' => $id++,
                'title' => ['_' => 'textWithEntities', 'text' => (string) $item, 'entities' => []],
            ];
        }

        return $out;
    }
}
