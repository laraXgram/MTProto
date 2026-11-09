<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * Builds the `WidgetQueryType` constructors a widget sends to its bot.
 */
final class WidgetQuery
{
    /**
     * A plain data query; `$withWallet` also shares the user's TON wallet card.
     */
    public static function data(bool $withWallet = false): array
    {
        $type = ['_' => 'widgetQueryTypeData'];

        if ($withWallet) {
            $type['with_wallet'] = true;
        }

        return $type;
    }

    /**
     * A request to sign data with the wallet from {@see wallet()}.
     *
     * @param array<mixed>|string $signData The data, as JSON or an array to encode.
     */
    public static function signData(array $wallet, array|string $signData): array
    {
        return [
            '_' => 'widgetQueryTypeSignData',
            'wallet' => $wallet,
            'sign_data' => [
                '_' => 'dataJSON',
                'data' => is_string($signData) ? $signData : json_encode($signData, JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * Report a transaction the wallet from {@see wallet()} has sent.
     */
    public static function sentTransaction(array $wallet, string $msgHash): array
    {
        return ['_' => 'widgetQueryTypeSentTransaction', 'wallet' => $wallet, 'msg_hash' => $msgHash];
    }

    /**
     * A `userWallet`; the balance is in nanotons. The public key and state init
     * are only valid together.
     */
    public static function wallet(string $address, int $balance, ?string $publicKey = null, ?string $stateInit = null): array
    {
        if (($publicKey === null) !== ($stateInit === null)) {
            throw new MTProtoException('A wallet public key and state init must be passed together.');
        }

        $wallet = ['_' => 'userWallet', 'address' => $address, 'balance' => $balance];

        if ($publicKey !== null) {
            $wallet['public_key'] = $publicKey;
            $wallet['state_init'] = $stateInit;
        }

        return $wallet;
    }
}
