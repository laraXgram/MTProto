<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening\Matching;

use LaraGram\Listening\Listen;
use LaraGram\MTProto\Foundation\ClientRequest;
use LaraGram\MTProto\Foundation\ClientType;

/**
 * Pattern matching for MTProto Client updates.
 *
 * Handles text-based matching for messages, callback data, inline queries,
 * and media-type discrimination within NEW_MESSAGE updates.
 */
class ClientPatternValidator
{
    /**
     * Validate a listen's pattern against a ClientRequest.
     *
     * @param Listen $listen
     * @param ClientRequest $request
     * @return bool
     */
    public function matchesClient(Listen $listen, ClientRequest $request): bool
    {
        $verb = $request->method();
        $pattern = $listen->pattern();

        // Compile the listen to get the regex
        $compiled = $listen->getCompiled();
        if ($compiled === null) {
            try {
                $refMethod = new \ReflectionMethod($listen, 'compileListen');
                $refMethod->setAccessible(true);
                $refMethod->invoke($listen);
                $compiled = $listen->getCompiled();
            } catch (\Throwable) {
                return false;
            }
        }

        $regex = $compiled?->getRegex();

        return match ($verb) {
            // Text-based matching
            'NEW_MESSAGE'        => $this->matchText($request, $regex, $pattern),
            'EDIT_MESSAGE'       => $this->matchText($request, $regex, $pattern),

            // Data-based matching
            'CALLBACK_QUERY'     => $this->matchCallbackData($request, $regex),
            'INLINE_QUERY'       => $this->matchInlineQuery($request, $regex),

            // Dice — special emoji/value matching
            'DICE'               => $this->matchDice($request, $pattern),

            // Media-filtered message types — check media type within the message
            'PHOTO'              => $this->matchMediaType($request, 'photo'),
            'VIDEO'              => $this->matchMediaType($request, 'video'),
            'ANIMATION'          => $this->matchMediaType($request, 'animation'),
            'STICKER'            => $this->matchMediaType($request, 'sticker'),
            'DOCUMENT'           => $this->matchMediaType($request, 'document'),
            'AUDIO'              => $this->matchMediaType($request, 'audio'),
            'VOICE'              => $this->matchMediaType($request, 'voice'),
            'VIDEO_NOTE'         => $this->matchMediaType($request, 'video_note'),
            'CONTACT_MEDIA'      => $this->matchMediaType($request, 'contact_media'),
            'LOCATION'           => $this->matchMediaType($request, 'location'),
            'VENUE'              => $this->matchMediaType($request, 'venue'),
            'GAME'               => $this->matchMediaType($request, 'game'),

            // All other verbs — no pattern matching needed, just verb match
            default              => true,
        };
    }

    /**
     * Match against message text (for NEW_MESSAGE and EDIT_MESSAGE).
     */
    protected function matchText(ClientRequest $request, ?string $regex, string $pattern): bool
    {
        if ($regex === null) {
            return false;
        }

        $text = $request->text() ?? '';

        return (bool) preg_match($regex, $text);
    }

    /**
     * Match against callback query data.
     */
    protected function matchCallbackData(ClientRequest $request, ?string $regex): bool
    {
        if ($regex === null) {
            return false;
        }

        $data = $request->callbackData() ?? '';

        return (bool) preg_match($regex, $data);
    }

    /**
     * Match against inline query string.
     */
    protected function matchInlineQuery(ClientRequest $request, ?string $regex): bool
    {
        if ($regex === null) {
            return false;
        }

        $query = $request->inlineQuery() ?? '';

        return (bool) preg_match($regex, $query);
    }

    /**
     * Match against media type within a message.
     *
     * This enables media-filtered handlers like onPhoto(), onSticker(), etc.
     * The request must be a NEW_MESSAGE (or EDIT_MESSAGE) with a matching media type.
     */
    protected function matchMediaType(ClientRequest $request, string $expectedType): bool
    {
        $data = $request->toArray();

        // Must have a message with media
        $message = $data['message'] ?? $data;
        if (!is_array($message) || !isset($message['media'])) {
            return false;
        }

        $actualType = ClientType::mediaTypeFromMessage($message);

        return $actualType === $expectedType;
    }

    protected function matchDice(ClientRequest $request, string $pattern): bool
    {
        $data = $request->toArray();

        // Must have a message with dice media
        $message = $data['message'] ?? $data;
        if (!is_array($message)) {
            return false;
        }

        $media = $message['media'] ?? null;
        if (!is_array($media) || ($media['_'] ?? '') !== 'messageMediaDice') {
            return false;
        }

        // Parse pattern
        $parts = explode(',', $pattern, 2);
        $pEmoji = $parts[0] ?? 'any';
        $pValue = $parts[1] ?? '0';

        $emoji = $media['emoticon'] ?? '';
        $value = (string) ($media['value'] ?? 0);

        // Emoji match
        $emojiMatch = $pEmoji === 'any'
            || $pEmoji === $emoji
            || in_array($emoji, explode('|', $pEmoji), true);

        // Value match
        $valueMatch = $pValue === '0'
            || $pValue === $value
            || in_array($value, explode('|', $pValue), true);

        return $emojiMatch && $valueMatch;
    }
}
