<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use Generator;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * The built-in TON wallet (layer 232): balance, transactions, NFTs, TON
 * Connect sessions and the toncenter API proxy.
 *
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesWallet
{
    /**
     * The account's wallet: `walletState` (address, public key, balance in
     * nanotons) or `walletStateEmpty` when there is none yet.
     */
    public function getWallet(): mixed
    {
        return $this->invoke('wallet.getState', []);
    }

    /**
     * Wallet addresses of users, or the users behind addresses.
     *
     * @param list<string|int|array> $users
     * @param list<string> $addresses
     */
    public function getWalletAddresses(array $users = [], array $addresses = [], bool $force = false): mixed
    {
        $params = ['id' => array_values($users), 'addresses' => array_values($addresses)];
        if ($force) {
            $params['force'] = true;
        }

        return $this->invoke('wallet.getUserAddresses', $params);
    }

    /**
     * One page of wallet transactions; `$direction` is "in", "out" or null for both.
     */
    public function getWalletTransactions(string $offset = '', int $limit = 50, ?string $direction = null): mixed
    {
        $params = ['offset' => $offset, 'limit' => $limit];

        match ($direction) {
            null => null,
            'in' => $params['inbound'] = true,
            'out' => $params['outbound'] = true,
            default => throw new MTProtoException("Unknown wallet transaction direction: {$direction}"),
        };

        return $this->invoke('wallet.getTransactions', $params);
    }

    /**
     * Lazily yield every wallet transaction, newest first.
     *
     * @return Generator<int, array>
     */
    public function iterateWalletTransactions(?int $limit = null, ?string $direction = null, int $pageSize = 50): Generator
    {
        $offset = '';
        $yielded = 0;

        do {
            $page = $this->getWalletTransactions($offset, $pageSize, $direction);

            foreach ($page['transactions'] ?? [] as $transaction) {
                yield $transaction;

                if ($limit !== null && ++$yielded >= $limit) {
                    return;
                }
            }

            $offset = (string) ($page['next_offset'] ?? '');
        } while ($offset !== '');
    }

    /**
     * One page of the NFTs held by the wallet.
     */
    public function getWalletNfts(string $offset = '', int $limit = 50): mixed
    {
        return $this->invoke('wallet.getNfts', ['offset' => $offset, 'limit' => $limit]);
    }

    /**
     * Export the wallet's secret phrase, split across data centers (needs the
     * 2FA password when the account has one).
     */
    public function exportWalletSecretPhrase(?string $password = null): mixed
    {
        $params = [];
        if ($password !== null) {
            $params['password'] = $this->passwordCheck($password);
        }

        return $this->invoke('wallet.exportSecretPhrase', $params);
    }

    /**
     * The TON Connect sessions of the wallet (connected dapps).
     *
     * @return list<array>
     */
    public function getTonConnectSessions(): array
    {
        return (array) ($this->invoke('wallet.tonConnectGetSessions', [])['sessions'] ?? []);
    }

    /**
     * Disconnect a TON Connect session.
     */
    public function closeTonConnectSession(int $sessionId): mixed
    {
        return $this->invoke('wallet.tonConnectCloseSession', ['session_id' => $sessionId]);
    }

    /**
     * Call the toncenter HTTP API through Telegram and get its decoded JSON
     * response. `$query` and `$payload` may be arrays (query string / JSON body).
     */
    public function toncenter(string $endpoint, array|string|null $query = null, array|string|null $payload = null, bool $post = false): mixed
    {
        $params = ['endpoint' => $endpoint];
        if ($post || $payload !== null) {
            $params['post'] = true;
        }
        if ($query !== null) {
            $params['query'] = is_array($query) ? http_build_query($query) : $query;
        }
        if ($payload !== null) {
            $params['payload'] = is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload;
        }

        $result = $this->invoke('toncenter.performApiRequest', $params);

        return json_decode((string) ($result['response']['data'] ?? 'null'), true);
    }
}
