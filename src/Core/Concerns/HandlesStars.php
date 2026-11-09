<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use Generator;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesStars
{
    public function getStarsStatus(string|int|array $peer = 'me', bool $ton = false): mixed
    {
        $params = ['peer' => $peer];
        if ($ton) {
            $params['ton'] = true;
        }

        return $this->invoke('payments.getStarsStatus', $params);
    }

    /**
     * Lazily yield Stars (or, with `$ton`, TON) transactions, newest first.
     * `$direction` is "in", "out" or null for both.
     *
     * @return Generator<int, array>
     */
    public function iterateStarsTransactions(
        string|int|array $peer = 'me',
        ?int $limit = null,
        ?string $direction = null,
        int $pageSize = 50,
        bool $ton = false,
    ): Generator {
        $params = ['peer' => $peer];

        match ($direction) {
            null => null,
            'in' => $params['inbound'] = true,
            'out' => $params['outbound'] = true,
            default => throw new MTProtoException("Unknown Stars transaction direction: {$direction}"),
        };
        if ($ton) {
            $params['ton'] = true;
        }

        return $this->pageByNextOffset('payments.getStarsTransactions', $params, 'history', $limit, $pageSize);
    }

    /**
     * Lazily yield the gifts saved on a profile or channel. `$params` takes the
     * `payments.getSavedStarGifts` filters (exclude_unique, sort_by_value, ...).
     *
     * @return Generator<int, array>
     */
    public function iterateSavedStarGifts(string|int|array $peer = 'me', ?int $limit = null, int $pageSize = 50, array $params = []): Generator
    {
        return $this->pageByNextOffset('payments.getSavedStarGifts', array_merge($params, ['peer' => $peer]), 'gifts', $limit, $pageSize);
    }

    public function getStarGifts(int $hash = 0): mixed
    {
        return $this->invoke('payments.getStarGifts', ['hash' => $hash]);
    }

    public function saveStarGift(int|string|array $gift, bool $unsave = false): mixed
    {
        $params = ['stargift' => $this->savedGift($gift)];
        if ($unsave) {
            $params['unsave'] = true;
        }

        return $this->invoke('payments.saveStarGift', $params);
    }

    public function convertStarGift(int|string|array $gift): mixed
    {
        return $this->invoke('payments.convertStarGift', ['stargift' => $this->savedGift($gift)]);
    }

    public function transferStarGift(int|string|array $gift, string|int|array $to): mixed
    {
        return $this->invoke('payments.transferStarGift', [
            'stargift' => $this->savedGift($gift),
            'to_id' => $to,
        ]);
    }

    public function getUniqueStarGift(string $slug): mixed
    {
        return $this->invoke('payments.getUniqueStarGift', ['slug' => $slug]);
    }

    /**
     * Withdraw a collectible gift to the TON blockchain (needs the 2FA password).
     * `$keepOnProfile` keeps showing it on the profile after the withdrawal.
     */
    public function withdrawStarGift(int|string|array $gift, string $password, bool $keepOnProfile = false): mixed
    {
        $params = [
            'stargift' => $this->savedGift($gift),
            'tmp_password' => $this->tmpPassword($password),
        ];
        if ($keepOnProfile) {
            $params['keep_on_profile'] = true;
        }

        return $this->invoke('payments.withdrawStarGift', $params);
    }

    /**
     * Get the Fragment URL to withdraw a collectible gift (needs the 2FA password).
     */
    public function getStarGiftWithdrawalUrl(int|string|array $gift, string $password): mixed
    {
        return $this->invoke('payments.getStarGiftWithdrawalUrl', [
            'stargift' => $this->savedGift($gift),
            'tmp_password' => $this->tmpPassword($password),
        ]);
    }

    public function deleteStarGift(int|string|array $gift): mixed
    {
        return $this->invoke('payments.deleteStarGift', ['stargift' => $this->savedGift($gift)]);
    }

    /**
     * Exchange rates used for Stars and TON, keyed by currency code.
     *
     * @return array<string, float>
     */
    public function getCurrencyRates(): array
    {
        $result = $this->invoke('payments.getCurrencyRates', []);

        $rates = [];
        foreach ((array) ($result['rates'] ?? []) as $rate) {
            $rates[(string) $rate['currency']] = (float) $rate['rate'];
        }

        return $rates;
    }

    public function sendPaidReaction(string|int|array $peer, int $msgId, int $count = 1): mixed
    {
        return $this->invoke('messages.sendPaidReaction', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'count' => $count,
        ]);
    }

    /**
     * `$mediaList` is a list of prebuilt `InputMedia` items
     * (use {@see \LaraGram\MTProto\Foundation\InputMedia}).
     *
     * @param list<array> $mediaList
     */
    public function sendPaidMedia(
        string|int|array $peer,
        int $starsAmount,
        array $mediaList,
        ?string $message = null,
        array $params = [],
    ): mixed {
        $media = [
            '_' => 'inputMediaPaidMedia',
            'stars_amount' => $starsAmount,
            'extended_media' => array_values($mediaList),
        ];

        return $this->sendMedia($peer, $media, $message, $params);
    }

    /**
     * @return Generator<int, array>
     */
    private function pageByNextOffset(string $method, array $params, string $key, ?int $limit, int $pageSize): Generator
    {
        $offset = '';
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $page = $this->invoke($method, array_merge($params, ['offset' => $offset, 'limit' => $take]));

            foreach ($page[$key] ?? [] as $item) {
                yield $item;
                if (++$yielded === $limit) {
                    return;
                }
            }

            $offset = (string) ($page['next_offset'] ?? '');
        } while ($offset !== '');
    }

    private function savedGift(int|string|array $gift): array
    {
        if (is_array($gift)) {
            return $gift;
        }
        if (is_int($gift)) {
            return ['_' => 'inputSavedStarGiftUser', 'msg_id' => $gift];
        }

        return ['_' => 'inputSavedStarGiftSlug', 'slug' => $gift];
    }
}
