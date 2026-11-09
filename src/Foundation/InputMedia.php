<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

final class InputMedia
{
    /**
     * inputMediaWidget - an HTML widget, for bots. A width or height of 0 lets
     * the client size it. Requires `$html` of at most 32768 bytes, or 4096 when
     * compressed with zlib.
     */
    public static function widget(
        string  $html,
        int     $width = 0,
        int     $height = 0,
        ?string $publicData = null,
        ?string $chatData = null,
        bool    $allowQueries = false,
        bool    $shared = false,
    ): array {
        return ['_' => 'inputMediaWidget'] + self::widgetFields($html, $width, $height, $publicData, $chatData, $allowQueries, $shared);
    }

    /**
     * inputMediaWidgetId - an existing shared widget.
     */
    public static function sharedWidget(int $widgetId, ?string $chatData = null): array
    {
        $media = ['_' => 'inputMediaWidgetId', 'widget_id' => $widgetId];

        if ($chatData !== null) {
            $media['chat_data'] = $chatData;
        }

        return $media;
    }

    /**
     * inputBotInlineMessageWidget - the `send_message` of an inline result that
     * posts a widget. `$extra` takes reply_markup, entities and invert_media.
     */
    public static function inlineWidget(
        string  $html,
        string  $message = '',
        int     $width = 0,
        int     $height = 0,
        ?string $publicData = null,
        ?string $chatData = null,
        bool    $allowQueries = false,
        bool    $shared = false,
        array   $extra = [],
    ): array {
        return ['_' => 'inputBotInlineMessageWidget', 'message' => $message]
            + self::widgetFields($html, $width, $height, $publicData, $chatData, $allowQueries, $shared)
            + $extra;
    }

    /**
     * inputBotInlineMessageWidgetId - the `send_message` of an inline result
     * that posts a shared widget.
     */
    public static function inlineSharedWidget(int $widgetId, string $message = '', ?string $chatData = null, array $extra = []): array
    {
        $media = ['_' => 'inputBotInlineMessageWidgetId', 'widget_id' => $widgetId, 'message' => $message];

        if ($chatData !== null) {
            $media['chat_data'] = $chatData;
        }

        return $media + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private static function widgetFields(
        string  $html,
        int     $width,
        int     $height,
        ?string $publicData,
        ?string $chatData,
        bool    $allowQueries,
        bool    $shared,
    ): array {
        $fields = ['width' => $width, 'height' => $height, 'html' => $html];

        if ($publicData !== null) {
            $fields['public_data'] = $publicData;
        }
        if ($chatData !== null) {
            $fields['chat_data'] = $chatData;
        }
        if ($allowQueries) {
            $fields['allow_queries'] = true;
        }
        if ($shared) {
            $fields['shared_widget'] = true;
        }

        return $fields;
    }

    /**
     * inputMediaUploadedPhoto - a freshly uploaded photo.
     *
     * @param array $file An `inputFile`/`inputFileBig` from FileUploader.
     */
    public static function uploadedPhoto(array $file, ?int $ttlSeconds = null, bool $spoiler = false): array
    {
        $media = ['_' => 'inputMediaUploadedPhoto', 'file' => $file];

        if ($spoiler) {
            $media['spoiler'] = true;
        }
        if ($ttlSeconds !== null) {
            $media['ttl_seconds'] = $ttlSeconds;
        }

        return $media;
    }

    /**
     * inputMediaUploadedDocument - any uploaded file (document/video/audio/…).
     *
     * @param array $file An `inputFile`/`inputFileBig`.
     * @param string $mimeType e.g. "image/png", "video/mp4".
     * @param array<int, array<mixed>> $attributes DocumentAttribute constructors.
     * @param array|null $thumb Optional uploaded thumbnail file.
     */
    public static function uploadedDocument(
        array  $file,
        string $mimeType,
        array  $attributes = [],
        ?array $thumb = null,
        bool   $forceFile = false,
        bool   $spoiler = false,
        ?int   $ttlSeconds = null,
    ): array
    {
        $media = [
            '_' => 'inputMediaUploadedDocument',
            'file' => $file,
            'mime_type' => $mimeType,
            'attributes' => $attributes,
        ];

        if ($thumb !== null) {
            $media['thumb'] = $thumb;
        }
        if ($forceFile) {
            $media['force_file'] = true;
        }
        if ($spoiler) {
            $media['spoiler'] = true;
        }
        if ($ttlSeconds !== null) {
            $media['ttl_seconds'] = $ttlSeconds;
        }

        return $media;
    }

    /**
     * inputMediaPhoto - resend an already-stored photo by reference (no upload).
     *
     * @param int $id Photo id.
     * @param int $accessHash Photo access_hash.
     * @param string $fileReference Volatile file_reference bytes.
     */
    public static function photo(int $id, int $accessHash, string $fileReference, ?int $ttlSeconds = null, bool $spoiler = false): array
    {
        $media = [
            '_' => 'inputMediaPhoto',
            'id' => ['_' => 'inputPhoto', 'id' => $id, 'access_hash' => $accessHash, 'file_reference' => $fileReference],
        ];

        if ($spoiler) {
            $media['spoiler'] = true;
        }
        if ($ttlSeconds !== null) {
            $media['ttl_seconds'] = $ttlSeconds;
        }

        return $media;
    }

    /**
     * inputMediaDocument - resend an already-stored document by reference.
     */
    public static function document(int $id, int $accessHash, string $fileReference, ?int $ttlSeconds = null, bool $spoiler = false): array
    {
        $media = [
            '_' => 'inputMediaDocument',
            'id' => ['_' => 'inputDocument', 'id' => $id, 'access_hash' => $accessHash, 'file_reference' => $fileReference],
        ];

        if ($spoiler) {
            $media['spoiler'] = true;
        }
        if ($ttlSeconds !== null) {
            $media['ttl_seconds'] = $ttlSeconds;
        }

        return $media;
    }

    public static function attrFilename(string $fileName): array
    {
        return ['_' => 'documentAttributeFilename', 'file_name' => $fileName];
    }

    public static function attrImageSize(int $w, int $h): array
    {
        return ['_' => 'documentAttributeImageSize', 'w' => $w, 'h' => $h];
    }

    public static function attrVideo(
        int  $duration = 0,
        int  $w = 0,
        int  $h = 0,
        bool $supportsStreaming = false,
        bool $roundMessage = false,
    ): array
    {
        $attr = [
            '_' => 'documentAttributeVideo',
            'duration' => $duration,
            'w' => $w,
            'h' => $h,
        ];

        if ($supportsStreaming) {
            $attr['supports_streaming'] = true;
        }
        if ($roundMessage) {
            $attr['round_message'] = true;
        }

        return $attr;
    }

    public static function attrAudio(
        int     $duration = 0,
        ?string $title = null,
        ?string $performer = null,
        bool    $voice = false,
    ): array
    {
        $attr = ['_' => 'documentAttributeAudio', 'duration' => $duration];

        if ($voice) {
            $attr['voice'] = true;
        }
        if ($title !== null) {
            $attr['title'] = $title;
        }
        if ($performer !== null) {
            $attr['performer'] = $performer;
        }

        return $attr;
    }

    public static function attrAnimated(): array
    {
        return ['_' => 'documentAttributeAnimated'];
    }

    public static function geoPoint(float $lat, float $long, ?int $accuracyRadius = null): array
    {
        return ['_' => 'inputMediaGeoPoint', 'geo_point' => self::inputGeoPoint($lat, $long, $accuracyRadius)];
    }

    public static function geoLive(float $lat, float $long, ?int $period = null, ?int $heading = null, ?int $proximityNotificationRadius = null, bool $stopped = false): array
    {
        $media = ['_' => 'inputMediaGeoLive', 'geo_point' => self::inputGeoPoint($lat, $long)];

        if ($stopped) {
            $media['stopped'] = true;
        }
        if ($heading !== null) {
            $media['heading'] = $heading;
        }
        if ($period !== null) {
            $media['period'] = $period;
        }
        if ($proximityNotificationRadius !== null) {
            $media['proximity_notification_radius'] = $proximityNotificationRadius;
        }

        return $media;
    }

    public static function venue(float $lat, float $long, string $title, string $address, string $provider = '', string $venueId = '', string $venueType = ''): array
    {
        return [
            '_' => 'inputMediaVenue',
            'geo_point' => self::inputGeoPoint($lat, $long),
            'title' => $title,
            'address' => $address,
            'provider' => $provider,
            'venue_id' => $venueId,
            'venue_type' => $venueType,
        ];
    }

    public static function contact(string $phoneNumber, string $firstName, string $lastName = '', string $vcard = ''): array
    {
        return [
            '_' => 'inputMediaContact',
            'phone_number' => $phoneNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'vcard' => $vcard,
        ];
    }

    public static function dice(string $emoticon = '🎲'): array
    {
        return ['_' => 'inputMediaDice', 'emoticon' => $emoticon];
    }

    public static function poll(string $question, array $answers, bool $multipleChoice = false, bool $publicVoters = false, ?array $correctAnswers = null, ?string $solution = null, ?int $closePeriod = null): array
    {
        $quiz = $correctAnswers !== null;

        $pollAnswers = [];
        foreach (array_values($answers) as $text) {
            $pollAnswers[] = [
                '_' => 'inputPollAnswer',
                'text' => self::textWithEntities((string)$text),
            ];
        }

        $poll = [
            '_' => 'poll',
            'id' => 0,
            'question' => self::textWithEntities($question),
            'answers' => $pollAnswers,
            'creator' => true,
        ];

        if ($quiz) {
            $poll['quiz'] = true;
        }
        if ($multipleChoice) {
            $poll['multiple_choice'] = true;
        }
        if ($publicVoters) {
            $poll['public_voters'] = true;
        }
        if ($closePeriod !== null) {
            $poll['close_period'] = $closePeriod;
        }

        $media = ['_' => 'inputMediaPoll', 'poll' => $poll];

        if ($quiz) {
            $media['correct_answers'] = array_map('intval', array_values($correctAnswers));
        }
        if ($solution !== null) {
            $media['solution'] = $solution;
            $media['solution_entities'] = [];
        }

        return $media;
    }

    private static function inputGeoPoint(float $lat, float $long, ?int $accuracyRadius = null): array
    {
        $geo = ['_' => 'inputGeoPoint', 'lat' => $lat, 'long' => $long];

        if ($accuracyRadius !== null) {
            $geo['accuracy_radius'] = $accuracyRadius;
        }

        return $geo;
    }

    private static function textWithEntities(string $text): array
    {
        return ['_' => 'textWithEntities', 'text' => $text, 'entities' => []];
    }
}
