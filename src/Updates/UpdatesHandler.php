<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Core\Client;

/**
 * Handles incoming updates from Telegram.
 * 
 * This class manages:
 * - Receiving and parsing updates
 * - State management (pts, qts, date, seq)
 * - Gap handling and recovery
 * - Event dispatching
 */
class UpdatesHandler
{
    /**
     * Client instance.
     */
    private Client $client;

    /**
     * Current updates state.
     */
    private array $state = [
        'pts' => 0,
        'qts' => 0,
        'date' => 0,
        'seq' => 0,
    ];

    /**
     * Registered event handlers.
     * @var array<string, callable[]>
     */
    private array $handlers = [];

    /**
     * Pending updates queue.
     */
    private array $pendingUpdates = [];

    /**
     * Whether updates loop is running.
     */
    private bool $running = false;

    /**
     * Create a new UpdatesHandler instance.
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Initialize updates state from server.
     */
    public function initialize(): void
    {
        $state = $this->client->invoke('updates.getState', []);
        
        $this->state = [
            'pts' => $state['pts'] ?? 0,
            'qts' => $state['qts'] ?? 0,
            'date' => $state['date'] ?? 0,
            'seq' => $state['seq'] ?? 0,
        ];
    }

    /**
     * Get current state.
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Register an event handler.
     *
     * @param string $event Event name (e.g., 'message', 'updateNewMessage')
     * @param callable $handler Handler function
     */
    public function on(string $event, callable $handler): void
    {
        $this->handlers[$event][] = $handler;
    }

    /**
     * Remove an event handler.
     */
    public function off(string $event, ?callable $handler = null): void
    {
        if ($handler === null) {
            unset($this->handlers[$event]);
        } else {
            $this->handlers[$event] = array_filter(
                $this->handlers[$event] ?? [],
                fn($h) => $h !== $handler
            );
        }
    }

    /**
     * Process incoming updates.
     */
    public function processUpdates(array $updates): void
    {
        $type = $updates['_'] ?? '';
        
        switch ($type) {
            case 'updates':
                $this->handleUpdates($updates);
                break;
                
            case 'updatesCombined':
                $this->handleUpdatesCombined($updates);
                break;
                
            case 'updateShort':
                $this->handleUpdateShort($updates);
                break;
                
            case 'updateShortMessage':
                $this->handleUpdateShortMessage($updates);
                break;
                
            case 'updateShortChatMessage':
                $this->handleUpdateShortChatMessage($updates);
                break;
                
            case 'updateShortSentMessage':
                $this->handleUpdateShortSentMessage($updates);
                break;
                
            case 'updatesTooLong':
                $this->handleUpdatesTooLong();
                break;
        }
    }

    /**
     * Handle full updates object.
     */
    private function handleUpdates(array $data): void
    {
        // Update state
        if (isset($data['seq']) && $data['seq'] > 0) {
            if ($data['seq'] > $this->state['seq'] + 1) {
                // Gap detected
                $this->handleGap();
                return;
            }
            $this->state['seq'] = $data['seq'];
        }
        
        if (isset($data['date'])) {
            $this->state['date'] = max($this->state['date'], $data['date']);
        }

        // Process users and chats first (for caching)
        $this->processEntities($data['users'] ?? [], $data['chats'] ?? []);

        // Process each update
        foreach ($data['updates'] ?? [] as $update) {
            $this->processSingleUpdate($update);
        }
    }

    /**
     * Handle combined updates.
     */
    private function handleUpdatesCombined(array $data): void
    {
        // Check sequence
        if (isset($data['seq_start']) && $data['seq_start'] > $this->state['seq'] + 1) {
            $this->handleGap();
            return;
        }

        $this->handleUpdates($data);
    }

    /**
     * Handle short update.
     */
    private function handleUpdateShort(array $data): void
    {
        if (isset($data['date'])) {
            $this->state['date'] = max($this->state['date'], $data['date']);
        }

        $this->processSingleUpdate($data['update']);
    }

    /**
     * Handle short message update (incoming PM).
     */
    private function handleUpdateShortMessage(array $data): void
    {
        // Convert to full message format
        $message = [
            '_' => 'message',
            'id' => $data['id'],
            'from_id' => ['_' => 'peerUser', 'user_id' => $data['user_id']],
            'peer_id' => ['_' => 'peerUser', 'user_id' => $data['user_id']],
            'message' => $data['message'],
            'date' => $data['date'],
            'out' => $data['out'] ?? false,
            'mentioned' => $data['mentioned'] ?? false,
            'media_unread' => $data['media_unread'] ?? false,
            'silent' => $data['silent'] ?? false,
            'fwd_from' => $data['fwd_from'] ?? null,
            'via_bot_id' => $data['via_bot_id'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'entities' => $data['entities'] ?? [],
            'ttl_period' => $data['ttl_period'] ?? null,
        ];

        $this->processSingleUpdate([
            '_' => 'updateNewMessage',
            'message' => $message,
            'pts' => $data['pts'],
            'pts_count' => $data['pts_count'],
        ]);
    }

    /**
     * Handle short chat message update.
     */
    private function handleUpdateShortChatMessage(array $data): void
    {
        $message = [
            '_' => 'message',
            'id' => $data['id'],
            'from_id' => ['_' => 'peerUser', 'user_id' => $data['from_id']],
            'peer_id' => ['_' => 'peerChat', 'chat_id' => $data['chat_id']],
            'message' => $data['message'],
            'date' => $data['date'],
            'out' => $data['out'] ?? false,
            'mentioned' => $data['mentioned'] ?? false,
            'media_unread' => $data['media_unread'] ?? false,
            'silent' => $data['silent'] ?? false,
            'fwd_from' => $data['fwd_from'] ?? null,
            'via_bot_id' => $data['via_bot_id'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'entities' => $data['entities'] ?? [],
            'ttl_period' => $data['ttl_period'] ?? null,
        ];

        $this->processSingleUpdate([
            '_' => 'updateNewMessage',
            'message' => $message,
            'pts' => $data['pts'],
            'pts_count' => $data['pts_count'],
        ]);
    }

    /**
     * Handle short sent message update.
     */
    private function handleUpdateShortSentMessage(array $data): void
    {
        $this->dispatch('sentMessage', $data);
    }

    /**
     * Handle updatesTooLong - need to fetch difference.
     */
    private function handleUpdatesTooLong(): void
    {
        $this->getDifference();
    }

    /**
     * Handle gap in updates sequence.
     */
    private function handleGap(): void
    {
        $this->getDifference();
    }

    /**
     * Fetch updates difference to recover from gap.
     */
    public function getDifference(): void
    {
        $result = $this->client->invoke('updates.getDifference', [
            'pts' => $this->state['pts'],
            'date' => $this->state['date'],
            'qts' => $this->state['qts'],
        ]);

        switch ($result['_'] ?? '') {
            case 'updates.differenceEmpty':
                $this->state['date'] = $result['date'];
                $this->state['seq'] = $result['seq'];
                break;

            case 'updates.difference':
                $this->processDifference($result);
                $state = $result['state'];
                $this->state = [
                    'pts' => $state['pts'],
                    'qts' => $state['qts'],
                    'date' => $state['date'],
                    'seq' => $state['seq'],
                ];
                break;

            case 'updates.differenceSlice':
                $this->processDifference($result);
                $state = $result['intermediate_state'];
                $this->state = [
                    'pts' => $state['pts'],
                    'qts' => $state['qts'],
                    'date' => $state['date'],
                    'seq' => $state['seq'],
                ];
                // More updates available, fetch again
                $this->getDifference();
                break;

            case 'updates.differenceTooLong':
                $this->state['pts'] = $result['pts'];
                break;
        }
    }

    /**
     * Process difference data.
     */
    private function processDifference(array $data): void
    {
        // Process entities
        $this->processEntities($data['users'] ?? [], $data['chats'] ?? []);

        // Process new messages
        foreach ($data['new_messages'] ?? [] as $message) {
            $this->dispatch('message', $message);
            $this->dispatch('updateNewMessage', ['message' => $message]);
        }

        // Process encrypted messages
        foreach ($data['new_encrypted_messages'] ?? [] as $message) {
            $this->dispatch('encryptedMessage', $message);
        }

        // Process other updates
        foreach ($data['other_updates'] ?? [] as $update) {
            $this->processSingleUpdate($update);
        }
    }

    /**
     * Process a single update.
     */
    private function processSingleUpdate(array $update): void
    {
        $type = $update['_'] ?? '';
        
        // Update pts/qts state
        if (isset($update['pts'])) {
            $ptsCount = $update['pts_count'] ?? 1;
            if ($update['pts'] > $this->state['pts']) {
                $this->state['pts'] = $update['pts'];
            }
        }
        if (isset($update['qts'])) {
            $this->state['qts'] = max($this->state['qts'], $update['qts']);
        }

        // Dispatch to specific handler
        $this->dispatch($type, $update);

        // Also dispatch to generic handlers
        switch ($type) {
            case 'updateNewMessage':
            case 'updateNewChannelMessage':
                $this->dispatch('message', $update['message'] ?? $update);
                break;
                
            case 'updateEditMessage':
            case 'updateEditChannelMessage':
                $this->dispatch('editedMessage', $update['message'] ?? $update);
                break;
                
            case 'updateDeleteMessages':
            case 'updateDeleteChannelMessages':
                $this->dispatch('deletedMessages', $update);
                break;
                
            case 'updateUserStatus':
                $this->dispatch('userStatus', $update);
                break;
                
            case 'updateUserTyping':
            case 'updateChatUserTyping':
            case 'updateChannelUserTyping':
                $this->dispatch('typing', $update);
                break;
                
            case 'updateReadHistoryInbox':
            case 'updateReadHistoryOutbox':
            case 'updateReadChannelInbox':
            case 'updateReadChannelOutbox':
                $this->dispatch('readHistory', $update);
                break;
                
            case 'updateMessageReactions':
                $this->dispatch('reactions', $update);
                break;
                
            case 'updateBotCallbackQuery':
            case 'updateInlineBotCallbackQuery':
                $this->dispatch('callbackQuery', $update);
                break;
                
            case 'updateBotInlineQuery':
                $this->dispatch('inlineQuery', $update);
                break;
        }
    }

    /**
     * Process and cache entities.
     */
    private function processEntities(array $users, array $chats): void
    {
        // Cache users and chats for later use
        foreach ($users as $user) {
            $this->dispatch('user', $user);
        }
        foreach ($chats as $chat) {
            $this->dispatch('chat', $chat);
        }
    }

    /**
     * Dispatch event to handlers.
     */
    private function dispatch(string $event, mixed $data): void
    {
        // Call specific handlers
        foreach ($this->handlers[$event] ?? [] as $handler) {
            try {
                $handler($data, $this->client);
            } catch (\Exception $e) {
                // Log error but continue
                $this->dispatch('error', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ]);
            }
        }

        // Call wildcard handlers
        foreach ($this->handlers['*'] ?? [] as $handler) {
            try {
                $handler($event, $data, $this->client);
            } catch (\Exception $e) {
                // Log error but continue
            }
        }
    }

    /**
     * Start the updates loop.
     */
    public function start(): void
    {
        $this->running = true;
        $this->initialize();
    }

    /**
     * Stop the updates loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check if running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
