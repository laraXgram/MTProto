<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Foundation\ClientManager;

class ClientExportCommand extends Command
{
    protected $signature = 'client:export
        {--session=default : Session to export}
        {--into= : Output directory (default: storage/app/clients/exports/<session>-<ts>)}
        {--peer= : Export only this chat (id or @username); default: all dialogs}
        {--contacts : Also export the contact list}
        {--limit= : Max messages per chat}
        {--files : Request file access in the takeout session (metadata only here)}';

    protected $description = "Export an account's messages/contacts via the Telegram takeout API";

    public function handle(): int
    {
        $session = (string) ($this->option('session') ?: 'default');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        /** @var ClientManager $manager */
        $manager = $this->laragram['mtproto.manager'];
        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();

        $into = (string) ($this->option('into') ?: storage_path(
            'app/clients/exports/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $session) . '-' . date('Ymd-His')
        ));
        $files->ensureDirectoryExists($into, 0700);
        $files->ensureDirectoryExists($into . '/messages', 0700);

        $this->components->info("Exporting session '{$session}' → {$into}");

        try {
            $manager->connect($session);
        } catch (\Throwable $e) {
            $this->components->error("Connection failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $prevHookFlags = $this->disableSwooleHooks();

        try {
            $client = $manager->client($session);

            $opts = ['message_users' => true, 'message_chats' => true, 'message_megagroups' => true, 'message_channels' => true];
            if ($this->option('contacts')) {
                $opts['contacts'] = true;
            }
            if ($this->option('files')) {
                $opts['files'] = true;
            }

            $summary = $client->takeout(function (int $takeoutId) use ($client, $files, $into, $limit) {
                $stats = ['chats' => 0, 'messages' => 0, 'contacts' => 0];

                if ($this->option('contacts')) {
                    $contacts = $client->takeoutContacts($takeoutId);
                    $files->put($into . '/contacts.json', json_encode($contacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $stats['contacts'] = count($contacts['users'] ?? []);
                    $this->components->twoColumnDetail('Contacts', (string) $stats['contacts']);
                }

                foreach ($this->targetPeers($client) as $peer) {
                    $label = $this->peerLabel($peer);
                    $file = $into . '/messages/' . $this->peerFileName($peer) . '.jsonl';
                    $handle = fopen($file, 'wb');
                    $count = 0;

                    try {
                        foreach ($client->iterateTakeoutHistory($takeoutId, $peer['input'], $limit) as $message) {
                            fwrite($handle, json_encode($message, JSON_UNESCAPED_UNICODE) . "\n");
                            $count++;
                        }
                    } finally {
                        fclose($handle);
                    }

                    $stats['chats']++;
                    $stats['messages'] += $count;
                    $this->components->twoColumnDetail($label, "{$count} messages");
                }

                return $stats;
            }, $opts);

            $this->newLine();
            $this->components->info(
                "Export complete: {$summary['chats']} chats, {$summary['messages']} messages"
                . ($this->option('contacts') ? ", {$summary['contacts']} contacts" : '')
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error("Export failed: {$e->getMessage()}");
            return self::FAILURE;
        } finally {
            $this->restoreSwooleHooks($prevHookFlags);
        }
    }

    /**
     * Resolve the chats to export: either one --peer, or every dialog.
     *
     * @return iterable<array{input:mixed,id:int,title:string}>
     */
    private function targetPeers($client): iterable
    {
        $peerOpt = (string) ($this->option('peer') ?: '');

        if ($peerOpt !== '') {
            $input = $client->getResolver()?->resolveInputPeer(
                is_numeric($peerOpt) ? (int) $peerOpt : $peerOpt
            );
            yield ['input' => $input ?? $peerOpt, 'id' => is_numeric($peerOpt) ? (int) $peerOpt : 0, 'title' => $peerOpt];
            return;
        }

        foreach ($client->iterateDialogs() as $dialog) {
            $peer = $dialog['peer'] ?? [];
            $id = (int) ($peer['user_id'] ?? $peer['channel_id'] ?? $peer['chat_id'] ?? 0);
            if ($id === 0) {
                continue;
            }
            $input = $client->getResolver()?->resolveInputPeer($id);
            if ($input === null) {
                continue;
            }
            yield ['input' => $input, 'id' => $id, 'title' => (string) $id];
        }
    }

    private function peerLabel(array $peer): string
    {
        return $peer['title'] !== '' ? $peer['title'] : ('peer ' . $peer['id']);
    }

    private function peerFileName(array $peer): string
    {
        $base = $peer['id'] !== 0 ? (string) $peer['id'] : $peer['title'];

        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $base) ?: 'peer';
    }

    /**
     * @return int|null previous swoole hook flags to restore, or null off-swoole
     */
    private function disableSwooleHooks(): ?int
    {
        if (!class_exists(\Swoole\Runtime::class)) {
            return null;
        }

        $prev = method_exists(\Swoole\Runtime::class, 'getHookFlags')
            ? \Swoole\Runtime::getHookFlags()
            : \SWOOLE_HOOK_ALL;
        \Swoole\Runtime::enableCoroutine(0);

        return $prev;
    }

    private function restoreSwooleHooks(?int $prev): void
    {
        if ($prev !== null) {
            \Swoole\Runtime::enableCoroutine($prev);
        }
    }
}
