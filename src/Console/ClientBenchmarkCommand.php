<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Foundation\ClientManager;
use LaraGram\MTProto\Foundation\FileUploader;
use LaraGram\MTProto\Foundation\InputMedia;

class ClientBenchmarkCommand extends Command
{
    protected $signature = 'client:benchmark
        {--session=default : Session to benchmark}
        {--size=100 : File size in MB}
        {--peer=me : Chat to upload to (default: Saved Messages)}
        {--upload-window=16 : Parts uploaded concurrently}
        {--download-window=32 : Chunks downloaded concurrently}
        {--keep : Keep the uploaded message instead of deleting it}';

    protected $description = 'Measure MTProto upload and download speed with this application\'s configuration';

    public function handle(): int
    {
        /** @var ClientManager $manager */
        $manager = $this->laragram['mtproto.manager'];
        $session = (string) $this->option('session');
        $size = max(1, (int) $this->option('size')) * 1048576;

        if (file_exists($manager->rpcSocket())) {
            $this->components->error('A pump process owns the sessions (RPC socket found). Stop it first - a benchmark needs its own connections.');
            return self::FAILURE;
        }

        $client = $manager->client($session);
        if (!$client->getRuntime()->isSupported()) {
            $this->components->error('The benchmark needs the coroutine runtime (mtproto.driver=swoole).');
            return self::FAILURE;
        }

        $path = tempnam(sys_get_temp_dir(), 'mtp_bench_');
        $this->components->task('Generating ' . ($size / 1048576) . ' MB test file', function () use ($path, $size): bool {
            $handle = fopen($path, 'wb');
            for ($written = 0; $written < $size; $written += 1048576) {
                fwrite($handle, random_bytes(min(1048576, $size - $written)));
            }
            fclose($handle);

            return true;
        });

        $results = [];

        try {
            $manager->connect($session);
            $client->getConnection()->disconnect();

            $client->getRuntime()->run(function () use ($client, $path, $size, &$results): void {
                $client->reconnect();
                $client->startPump();
                $results = $this->measure($client, $path, $size);
            });
        } catch (\Throwable $e) {
            $this->components->error("Benchmark failed: {$e->getMessage()}");
            return self::FAILURE;
        } finally {
            @unlink($path);
            $manager->disconnect($session);
        }

        $this->report($results, $size);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(Client $client, string $path, int $size): array
    {
        $peer = (string) $this->option('peer');
        $results = [];

        $this->components->task('Uploading', function () use ($client, $path, $peer, &$results): bool {
            $start = microtime(true);
            $file = (new FileUploader($client))
                ->withConcurrency((int) $this->option('upload-window'))
                ->fromPath($path, 'laragram-benchmark.bin');
            $results['upload'] = microtime(true) - $start;

            $sent = $client->sendMedia($peer, InputMedia::uploadedDocument(
                $file,
                'application/octet-stream',
                [InputMedia::attrFilename('laragram-benchmark.bin')],
                forceFile: true,
            ), 'LaraGram MTProto benchmark');

            $results['message'] = $this->sentMessage(is_array($sent) ? $sent : []);

            return $results['message'] !== null;
        });

        if ($results['message'] === null) {
            throw new \RuntimeException('The uploaded file was sent, but its message could not be found in the response.');
        }

        $this->components->task('Downloading', function () use ($client, $size, &$results): bool {
            $target = tempnam(sys_get_temp_dir(), 'mtp_bench_dl_');
            $client->fileDecoder()->withConcurrency((int) $this->option('download-window'));

            try {
                $start = microtime(true);
                $written = $client->downloadMediaToFile($results['message']['media'], $target);
                $results['download'] = microtime(true) - $start;
            } finally {
                @unlink($target);
            }

            return $written === $size;
        });

        $results['stats'] = $this->collectStats($client);

        if (!$this->option('keep')) {
            try {
                $client->invoke('messages.deleteMessages', ['id' => [$results['message']['id']], 'revoke' => true]);
            } catch (\Throwable $e) {
                $this->components->warn("Could not delete the benchmark message: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * The message a sendMedia call created, from its Updates answer.
     */
    private function sentMessage(array $updates): ?array
    {
        foreach ($updates['updates'] ?? [] as $update) {
            if (in_array($update['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage'], true)
                && isset($update['message']['media'])) {
                return $update['message'];
            }
        }

        return null;
    }

    /**
     * Sum the pump statistics of every socket that carried the transfer.
     *
     * @return array<string, mixed>
     */
    private function collectStats(Client $client): array
    {
        $sockets = [spl_object_id($client) => $client];
        for ($dc = 1; $dc <= 5; $dc++) {
            $live = $client->mediaPool()->countFor($dc);
            foreach ($live > 0 ? $client->mediaSockets($dc, $live) : [] as $socket) {
                $sockets[spl_object_id($socket)] = $socket;
            }
        }

        $total = ['sockets' => count($sockets), 'rpc_errors' => 0, 'flood_waits' => 0, 'resends' => 0, 'reconnects' => 0, 'bad_msgs' => 0, 'rpc_error_types' => [], 'reconnect_reasons' => []];
        foreach ($sockets as $socket) {
            if (!$socket->supportsConcurrentInvoke()) {
                continue;
            }
            $stats = $socket->getPump()->getStats();
            foreach (['rpc_errors', 'flood_waits', 'resends', 'reconnects', 'bad_msgs'] as $key) {
                $total[$key] += $stats[$key] ?? 0;
            }
            foreach (['rpc_error_types', 'reconnect_reasons'] as $key) {
                foreach ($stats[$key] ?? [] as $type => $count) {
                    $total[$key][$type] = ($total[$key][$type] ?? 0) + $count;
                }
            }
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $results
     */
    private function report(array $results, int $size): void
    {
        $mb = $size / 1048576;
        $stats = $results['stats'];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Upload</>', sprintf('%.2f MB/s <fg=gray>(%.2fs)</>', $mb / $results['upload'], $results['upload']));
        $this->components->twoColumnDetail('<fg=green;options=bold>Download</>', sprintf('%.2f MB/s <fg=gray>(%.2fs)</>', $mb / $results['download'], $results['download']));
        $this->components->twoColumnDetail('Sockets', (string) $stats['sockets']);
        $this->components->twoColumnDetail('RPC errors', "{$stats['rpc_errors']} <fg=gray>({$stats['flood_waits']} flood waits)</>");
        $this->components->twoColumnDetail('Reconnects / resends', "{$stats['reconnects']} / {$stats['resends']}");
        $this->components->twoColumnDetail('Bad messages', (string) $stats['bad_msgs']);

        foreach (['rpc_error_types' => 'Error', 'reconnect_reasons' => 'Reconnect'] as $key => $label) {
            arsort($stats[$key]);
            foreach ($stats[$key] as $type => $count) {
                $this->components->twoColumnDetail("<fg=gray>{$label}: {$type}</>", (string) $count);
            }
        }

        $this->newLine();
    }
}
