<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Entities;

/**
 * Bot-API entity type ⇄ MTProto MessageEntity constructor registry.
 *
 * Covers every Bot-API entity type. Some are output-only (auto-detected by the
 * server from plain text — mention, hashtag, cashtag, bot_command, url, email,
 * phone_number, bank_card), so they appear in received messages but are never
 * something a sender marks up. `date_time` is Bot-API-only with no MTProto
 * constructor. `blockquote`/`expandable_blockquote` share one TL constructor,
 * disambiguated by its `collapsed` flag.
 */
final class EntityType
{
    /** Bot-API type string → TL constructor (for building outgoing entities). */
    private const TO_TL = [
        'mention'        => 'messageEntityMention',
        'hashtag'        => 'messageEntityHashtag',
        'cashtag'        => 'messageEntityCashtag',
        'bot_command'    => 'messageEntityBotCommand',
        'url'            => 'messageEntityUrl',
        'email'          => 'messageEntityEmail',
        'phone_number'   => 'messageEntityPhone',
        'bank_card'      => 'messageEntityBankCard',
        'bold'           => 'messageEntityBold',
        'italic'         => 'messageEntityItalic',
        'underline'      => 'messageEntityUnderline',
        'strikethrough'  => 'messageEntityStrike',
        'spoiler'        => 'messageEntitySpoiler',
        'code'           => 'messageEntityCode',
        'pre'            => 'messageEntityPre',
        'text_link'      => 'messageEntityTextUrl',
        'text_mention'   => 'messageEntityMentionName',
        'custom_emoji'   => 'messageEntityCustomEmoji',
        'blockquote'            => 'messageEntityBlockquote',
        'expandable_blockquote' => 'messageEntityBlockquote',
        'date_time'             => 'messageEntityFormattedDate',
    ];

    /** TL constructor → Bot-API type string (for mapping received entities). */
    private const TO_BOT = [
        'messageEntityMention'     => 'mention',
        'messageEntityHashtag'     => 'hashtag',
        'messageEntityCashtag'     => 'cashtag',
        'messageEntityBotCommand'  => 'bot_command',
        'messageEntityUrl'         => 'url',
        'messageEntityEmail'       => 'email',
        'messageEntityPhone'       => 'phone_number',
        'messageEntityBankCard'    => 'bank_card',
        'messageEntityBold'        => 'bold',
        'messageEntityItalic'      => 'italic',
        'messageEntityUnderline'   => 'underline',
        'messageEntityStrike'      => 'strikethrough',
        'messageEntitySpoiler'     => 'spoiler',
        'messageEntityCode'        => 'code',
        'messageEntityPre'         => 'pre',
        'messageEntityTextUrl'     => 'text_link',
        'messageEntityMentionName' => 'text_mention',
        'messageEntityCustomEmoji'   => 'custom_emoji',
        'messageEntityBlockquote'    => 'blockquote',
        'messageEntityFormattedDate' => 'date_time',
    ];

    /**
     * TL constructor for a Bot-API type, or null if it has none.
     */
    public static function toTl(string $botApiType): ?string
    {
        return self::TO_TL[$botApiType] ?? null;
    }

    /**
     * Bot-API type string for a TL MessageEntity, accounting for the
     * blockquote `collapsed` flag. Returns null for unknown constructors.
     *
     * @param array<string, mixed> $entity Deserialized TL entity.
     */
    public static function toBotApi(array $entity): ?string
    {
        $constructor = $entity['_'] ?? '';

        if ($constructor === 'messageEntityBlockquote' && !empty($entity['collapsed'])) {
            return 'expandable_blockquote';
        }

        return self::TO_BOT[$constructor] ?? null;
    }
}
