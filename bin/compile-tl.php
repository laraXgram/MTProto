#!/usr/bin/env php
<?php
/**
 * TL Schema Compiler (composer hook entry point).
 *
 * Inside an application prefer `php laragram client:compile`, which shows the
 * same compilation with Commander output. This script exists for composer
 * scripts that run before the application can boot.
 *
 * Usage:
 *   php bin/compile-tl.php [--schema=<dir>] [--layer=<n>] [--dry-run] [-v]
 *
 * Without --schema, the published copy in `<cwd>/resources/mtproto/schemas`
 * (or $CLIENT_SCHEMA_PATH) is used when it exists, otherwise the package copy.
 */

declare(strict_types=1);

foreach ([
    getcwd() . '/vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists(\LaraGram\Filesystem\Filesystem::class)) {
            break;
        }
    }
}

if (!class_exists(\LaraGram\MTProto\TL\Compiler\SchemaCompiler::class)) {
    fwrite(STDERR, "Framework autoload not found (LaraGram\\MTProto missing). Run inside an app.\n");
    exit(1);
}

use LaraGram\MTProto\TL\Compiler\PlainReporter;
use LaraGram\MTProto\TL\Compiler\SchemaCompiler;
use LaraGram\MTProto\TL\SchemaSource;

$options = getopt('v', ['schema:', 'layer:', 'dry-run']);

$schema = $options['schema']
    ?? (getenv('CLIENT_SCHEMA_PATH') ?: getcwd() . '/' . SchemaSource::PUBLISH_PATH);

$compiler = new SchemaCompiler(
    schemaDirectory: $schema,
    layer: isset($options['layer']) ? (int) $options['layer'] : null,
);

echo "TL Compiler: " . (SchemaSource::isPackage($compiler->schemaDirectory()) ? 'package' : 'published')
    . " schema from {$compiler->schemaDirectory()}\n";

try {
    $result = $compiler->compile(new PlainReporter(isset($options['v'])), isset($options['dry-run']));
} catch (\Throwable $e) {
    fwrite(STDERR, "TL compilation failed: {$e->getMessage()}\n");
    exit(1);
}

printf(
    "Layer %d: %d methods in %d namespaces, %d constructors, %d type classes, %d flat shortcuts (%.0fms)%s\n",
    $result->layer,
    $result->methods,
    $result->namespaces,
    $result->constructors,
    $result->typeClasses,
    $result->shortcuts,
    $result->duration * 1000,
    $result->dryRun ? ' [dry run]' : '',
);
