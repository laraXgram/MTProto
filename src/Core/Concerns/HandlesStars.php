<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

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
