<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

/**
 * Builders for `InputMedia*` constructors that wrap an uploaded file
 * ({@see FileUploader} output) for `messages.sendMedia`.
 *
 * Pure array builders — no I/O — so they are trivially testable and reusable
 * by the higher-level `Client::send*` helpers.
 */
final class InputMedia
{
    /**
     * inputMediaUploadedPhoto — a freshly uploaded photo.
     *
     * @param array $file  An `inputFile`/`inputFileBig` from FileUploader.
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
     * inputMediaUploadedDocument — any uploaded file (document/video/audio/…).
     *
     * @param array                     $file        An `inputFile`/`inputFileBig`.
     * @param string                    $mimeType    e.g. "image/png", "video/mp4".
     * @param array<int, array<mixed>>  $attributes  DocumentAttribute constructors.
     * @param array|null                $thumb       Optional uploaded thumbnail file.
     */
    public static function uploadedDocument(
        array $file,
        string $mimeType,
        array $attributes = [],
        ?array $thumb = null,
        bool $forceFile = false,
        bool $spoiler = false,
        ?int $ttlSeconds = null,
    ): array {
        $media = [
            '_'          => 'inputMediaUploadedDocument',
            'file'       => $file,
            'mime_type'  => $mimeType,
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

    // ── DocumentAttribute builders ──────────────────────────────────────

    public static function attrFilename(string $fileName): array
    {
        return ['_' => 'documentAttributeFilename', 'file_name' => $fileName];
    }

    public static function attrImageSize(int $w, int $h): array
    {
        return ['_' => 'documentAttributeImageSize', 'w' => $w, 'h' => $h];
    }

    public static function attrVideo(
        int $duration = 0,
        int $w = 0,
        int $h = 0,
        bool $supportsStreaming = false,
        bool $roundMessage = false,
    ): array {
        $attr = [
            '_'        => 'documentAttributeVideo',
            'duration' => $duration,
            'w'        => $w,
            'h'        => $h,
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
        int $duration = 0,
        ?string $title = null,
        ?string $performer = null,
        bool $voice = false,
    ): array {
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
}
