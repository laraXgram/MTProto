<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait PremiumFeatures
{
    public function getBoostsStatus(string|int|array $peer): mixed
    {
        return $this->invoke('premium.getBoostsStatus', ['peer' => $peer]);
    }

    public function getMyBoosts(): mixed
    {
        return $this->invoke('premium.getMyBoosts', []);
    }

    public function getUserBoosts(string|int|array $peer, string|int|array $user): mixed
    {
        return $this->invoke('premium.getUserBoosts', ['peer' => $peer, 'user_id' => $user]);
    }
}
