<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

/**
 * Locates the `.tl` schema files the compiler and runtime work from.
 *
 * The package ships the official schemas in `src/TL/schemas`. An application
 * can publish them (`client:schema:publish`) to edit them - e.g. to pin its
 * own layer - and the compiler then prefers the published copy.
 */
final class SchemaSource
{
    /**
     * Schema files in load order. Later definitions override earlier ones.
     */
    public const FILES = ['mtproto_api.tl', 'telegram_api.tl', 'mtproto_ext.tl'];

    /**
     * The schema the typed Method/Type classes are generated from.
     */
    public const API_FILE = 'telegram_api.tl';

    /**
     * Where applications publish their editable copy, relative to the base path.
     */
    public const PUBLISH_PATH = 'resources/mtproto/schemas';

    /**
     * The layer of the bundled schema - the fallback when a schema has no
     * `// LAYER N` marker and nothing was compiled yet.
     */
    public const DEFAULT_LAYER = 228;

    /**
     * The schemas bundled with the package.
     */
    public static function packagePath(): string
    {
        return __DIR__ . '/schemas';
    }

    /**
     * Pick the schema directory: $preferred when it holds an API schema,
     * otherwise the package copy.
     */
    public static function resolve(?string $preferred = null): string
    {
        if ($preferred !== null && $preferred !== '' && is_file(rtrim($preferred, '/') . '/' . self::API_FILE)) {
            return rtrim($preferred, '/');
        }

        return self::packagePath();
    }

    /**
     * Whether $directory is the schema copy bundled with the package.
     */
    public static function isPackage(string $directory): bool
    {
        $real = realpath($directory);

        return $real !== false && $real === realpath(self::packagePath());
    }

    /**
     * The existing schema files in $directory, in load order.
     *
     * @return array<string, string> file name => absolute path
     */
    public static function files(string $directory): array
    {
        $files = [];
        foreach (self::FILES as $file) {
            $path = rtrim($directory, '/') . '/' . $file;
            if (is_file($path)) {
                $files[$file] = $path;
            }
        }

        return $files;
    }

    /**
     * Read the `// LAYER N` marker from the API schema, or null when absent.
     */
    public static function detectLayer(string $directory): ?int
    {
        $path = rtrim($directory, '/') . '/' . self::API_FILE;
        if (!is_file($path)) {
            return null;
        }

        $content = (string) file_get_contents($path);

        return self::layerFromContent($content);
    }

    /**
     * Extract the `// LAYER N` marker from schema text (the last one wins).
     */
    public static function layerFromContent(string $content): ?int
    {
        if (preg_match_all('~^\s*//\s*LAYER\s+(\d+)\s*$~mi', $content, $matches) && $matches[1] !== []) {
            return (int) end($matches[1]);
        }

        return null;
    }

    /**
     * A content hash of every schema file in $directory.
     */
    public static function hash(string $directory): string
    {
        $context = hash_init('sha256');
        foreach (self::files($directory) as $name => $path) {
            hash_update($context, $name . "\0");
            hash_update_file($context, $path);
        }

        return hash_final($context);
    }

    /**
     * Why the Generated classes are out of date, or null when they are current:
     * a published schema that was edited (or never compiled) since the last
     * `client:compile`, or Generated classes older than the compiler metadata.
     */
    public static function staleReason(?string $publishedPath = null): ?string
    {
        $layerClass = 'LaraGram\\MTProto\\Generated\\Layer';

        if (!class_exists($layerClass)) {
            return 'The MTProto Generated classes predate the schema compiler metadata. Run: php laragram client:compile';
        }

        if ($publishedPath === null || !is_file(rtrim($publishedPath, '/') . '/' . self::API_FILE)) {
            return null;
        }

        if (self::hash($publishedPath) !== constant($layerClass . '::SCHEMA_HASH')) {
            return "The published TL schema in [{$publishedPath}] differs from the compiled one. Run: php laragram client:compile";
        }

        return null;
    }
}
