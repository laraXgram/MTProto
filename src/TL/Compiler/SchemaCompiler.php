<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL\Compiler;

use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\TL\SchemaSource;
use LaraGram\MTProto\TL\TLConstructor;
use LaraGram\MTProto\TL\TLMethod;
use LaraGram\MTProto\TL\TLParameter;
use LaraGram\MTProto\TL\TLParser;

/**
 * Compiles the TL schemas into the `LaraGram\MTProto\Generated` classes:
 *
 *  - Methods/<Namespace>.php   one class per API namespace
 *  - Types/<Type>.php          one class per TL result type
 *  - Types/ConstructorMap.php  constructor name -> Type class
 *  - ClientMethods.php         trait with flat `$client->sendMessage()` shortcuts
 *  - _ide_helper.php           `@method` annotations for IDEs
 *  - schema.json               the compiled runtime schema (loaded by {@see TLParser::shared()})
 *  - Layer.php                 the compiled layer, schema hash and source
 *
 * The output is built in a sibling directory and swapped in only when every
 * file was written, so a failed compilation never leaves a half-empty tree.
 */
final class SchemaCompiler
{
    public const NAMESPACE = 'LaraGram\\MTProto\\Generated';

    /**
     * File name of the compiled runtime schema inside the output directory.
     */
    public const RUNTIME_SCHEMA = 'schema.json';

    /**
     * Methods whose flat shortcut must resolve to a specific namespace.
     */
    private const FLAT_OVERRIDES = [
        'sendMessage' => 'messages',
        'deleteMessages' => 'messages',
        'readHistory' => 'messages',
        'getMessages' => 'messages',
        'reportSpam' => 'messages',
        'readMessageContents' => 'messages',
        'deleteHistory' => 'messages',
        'search' => 'messages',
        'editMessage' => 'messages',
        'getDifference' => 'updates',
    ];

    /**
     * Params that are required in TL but have an obvious default. They become
     * optional in PHP. Format: param => [tlType => PHP default literal].
     */
    private const PARAM_DEFAULTS = [
        'hash' => ['int' => '0', 'long' => '0'],
        'limit' => ['int' => '100'],
        'offset_id' => ['int' => '0', 'long' => '0'],
        'offset_date' => ['int' => '0'],
        'add_offset' => ['int' => '0'],
        'max_id' => ['int' => '0', 'long' => '0'],
        'min_id' => ['int' => '0', 'long' => '0'],
        'offset' => ['int' => '0', 'long' => '0', 'string' => "''"],
        'offset_peer' => ['InputPeer' => "['_' => 'inputPeerEmpty']"],
        'offset_rate' => ['int' => '0'],
        'min_date' => ['int' => '0'],
        'max_date' => ['int' => '0'],
        'offset_topic' => ['int' => '0'],
        'filter' => ['MessagesFilter' => "['_' => 'inputMessagesFilterEmpty']"],
    ];

    /**
     * Methods where `hash` is not a cache hash and must never be defaulted.
     */
    private const HASH_EXCLUSIONS = [
        'account.resetAuthorization',
        'account.resetWebAuthorization',
        'account.changeAuthorizationSettings',
        'messages.checkChatInvite',
        'messages.importChatInvite',
        'account.sendConfirmPhoneCode',
    ];

    /**
     * Params the ParamPreprocessor fills in automatically - hidden from signatures.
     */
    private const AUTO_FILLED = [
        'random_id' => ['long', 'int', 'bytes'],
        'random_bytes' => ['bytes'],
    ];

    /**
     * Result types that never get a Type class.
     */
    private const BUILTIN_TYPES = [
        'Bool', 'True', 'Null', 'Vector t', 'Error', 'X',
        'Int', 'Long', 'Double', 'String', 'Bytes', 'Int128', 'Int256',
    ];

    private string $schemaDirectory;

    private string $outputDirectory;

    private CompilerReporter $reporter;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        ?string $schemaDirectory = null,
        ?string $outputDirectory = null,
        private ?int $layer = null,
    ) {
        $this->schemaDirectory = SchemaSource::resolve($schemaDirectory);
        $this->outputDirectory = rtrim($outputDirectory ?? self::defaultOutputPath(), '/');
        $this->reporter = new NullReporter();
    }

    /**
     * Where the package autoloads its generated classes from.
     */
    public static function defaultOutputPath(): string
    {
        return dirname(__DIR__, 2) . '/Generated';
    }

    public function schemaDirectory(): string
    {
        return $this->schemaDirectory;
    }

    public function outputDirectory(): string
    {
        return $this->outputDirectory;
    }

    /**
     * Compile the schemas. With $dryRun the output is built, measured and
     * discarded, leaving the current Generated tree untouched.
     */
    public function compile(?CompilerReporter $reporter = null, bool $dryRun = false): CompileResult
    {
        $start = microtime(true);
        $this->reporter = $reporter ?? new NullReporter();
        $this->warnings = [];

        $files = SchemaSource::files($this->schemaDirectory);
        if (!isset($files[SchemaSource::API_FILE])) {
            throw new MTProtoException("No " . SchemaSource::API_FILE . " found in {$this->schemaDirectory}");
        }

        [$api, $runtime] = $this->reporter->step('Parsing schemas', fn (): array => $this->parse($files));

        $layer = $this->resolveLayer();
        $hash = SchemaSource::hash($this->schemaDirectory);

        $methodsByNamespace = $this->groupMethods($api);
        $build = $this->outputDirectory . '.build-' . bin2hex(random_bytes(4));

        try {
            $this->makeDirectory($build . '/Methods');
            $this->makeDirectory($build . '/Types');

            $methodCount = $this->reporter->step(
                'Generating method classes',
                fn (): int => $this->writeMethods($build, $methodsByNamespace),
            );

            $typeGroups = $this->groupTypes($api);
            $this->reporter->step('Generating type classes', fn () => $this->writeTypes($build, $typeGroups));
            $this->reporter->step('Generating constructor map', fn () => $this->writeConstructorMap($build, $typeGroups));

            [$shortcuts, $collisions] = $this->reporter->step(
                'Generating flat client shortcuts',
                fn (): array => $this->writeClientMethods($build, $methodsByNamespace),
            );

            $this->reporter->step('Generating IDE helper', fn () => $this->writeIdeHelper($build, $methodsByNamespace, $shortcuts));

            $this->reporter->step('Compiling runtime schema', function () use ($build, $runtime, $layer, $hash): void {
                $this->writeRuntimeSchema($build, $runtime, $layer, $hash);
                $this->writeLayer($build, $layer, $hash);
            });

            $previous = $this->previousSchema();
            $diff = $previous !== null ? $this->diff($previous, $runtime) : null;

            if (!$dryRun) {
                $this->reporter->step('Installing generated classes', fn () => $this->swap($build));
            }
        } finally {
            if (is_dir($build)) {
                $this->deleteDirectory($build);
            }
        }

        foreach ($collisions as $name => $namespaces) {
            $this->reporter->note(sprintf(
                "Flat shortcut '%s' -> %s (shadows %s)",
                $name,
                $namespaces[0],
                implode(', ', array_slice($namespaces, 1)),
            ));
        }

        return new CompileResult(
            layer: $layer,
            previousLayer: $previous['layer'] ?? null,
            schemaDirectory: $this->schemaDirectory,
            published: !SchemaSource::isPackage($this->schemaDirectory),
            outputDirectory: $this->outputDirectory,
            hash: $hash,
            methods: $methodCount,
            constructors: count($api->getConstructors()),
            namespaces: count($methodsByNamespace),
            typeClasses: count($typeGroups),
            shortcuts: count($shortcuts),
            collisions: $collisions,
            warnings: $this->warnings,
            diff: $diff,
            dryRun: $dryRun,
            duration: microtime(true) - $start,
        );
    }

    /**
     * @param array<string, string> $files
     * @return array{0: TLParser, 1: TLParser} [API-only parser, full runtime parser]
     */
    private function parse(array $files): array
    {
        $api = new TLParser();
        $api->parseFile($files[SchemaSource::API_FILE]);

        $runtime = new TLParser();
        foreach ($files as $path) {
            $runtime->parseFile($path);
        }

        return [$api, $runtime];
    }

    private function resolveLayer(): int
    {
        if ($this->layer !== null) {
            return $this->layer;
        }

        $detected = SchemaSource::detectLayer($this->schemaDirectory);
        if ($detected !== null) {
            return $detected;
        }

        $this->warn(sprintf(
            'No "// LAYER N" marker in %s - assuming layer %d. Add the marker or pass a layer explicitly.',
            SchemaSource::API_FILE,
            SchemaSource::DEFAULT_LAYER,
        ));

        return SchemaSource::DEFAULT_LAYER;
    }

    /**
     * @return array<string, list<TLMethod>>
     */
    private function groupMethods(TLParser $parser): array
    {
        $grouped = [];
        foreach ($parser->getMethods() as $method) {
            $namespace = $method->getNamespace() ?: '';
            // Global methods (invokeWithLayer, initConnection, ...) are internal MTProto plumbing.
            if ($namespace === '') {
                continue;
            }
            $grouped[$namespace][] = $method;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array<string, list<TLConstructor>>
     */
    private function groupTypes(TLParser $parser): array
    {
        $groups = [];
        foreach ($parser->getConstructors() as $constructor) {
            $type = $constructor->getType();
            // Built-in and primitive result types have no Type class (and
            // `Int`/`String` are reserved class names in PHP).
            if (in_array($type, self::BUILTIN_TYPES, true)) {
                continue;
            }
            $groups[$type][] = $constructor;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @param array<string, list<TLMethod>> $methodsByNamespace
     */
    private function writeMethods(string $build, array $methodsByNamespace): int
    {
        $count = 0;

        foreach ($methodsByNamespace as $namespace => $methods) {
            $className = ucfirst($namespace);
            $buf = "<?php\n\n";
            $buf .= "/**\n";
            $buf .= " * Auto-generated by TL Compiler — DO NOT EDIT\n";
            $buf .= " *\n";
            $buf .= " * TL namespace: {$namespace}\n";
            $buf .= " * Methods: " . count($methods) . "\n";
            $buf .= " */\n\n";
            $buf .= "declare(strict_types=1);\n\n";
            $buf .= "namespace " . self::NAMESPACE . "\\Methods;\n\n";
            $buf .= "use LaraGram\\MTProto\\Core\\Client;\n";
            $buf .= "use LaraGram\\MTProto\\TL\\TLObject;\n\n";
            $buf .= "class {$className}\n";
            $buf .= "{\n";
            $buf .= "    public function __construct(\n";
            $buf .= "        private readonly Client \$client,\n";
            $buf .= "    ) {}\n";

            foreach ($methods as $method) {
                $buf .= $this->methodSource($method);
                $count++;
            }

            $buf .= "}\n";

            $this->put($build . '/Methods/' . $className . '.php', $buf);
        }

        return $count;
    }

    private function methodSource(TLMethod $method): string
    {
        $name = $method->getName();
        $fullName = $method->getFullName();

        $required = [];
        $optional = [];
        $docs = [];
        /** @var list<array{param: TLParameter, defaulted: bool}> $args */
        $args = [];

        foreach ($method->getParams() as $param) {
            $pName = $param->getName();
            $pType = $param->getType();

            if ($pType === '#') {
                continue;
            }
            if (isset(self::AUTO_FILLED[$pName]) && in_array($pType, self::AUTO_FILLED[$pName], true)) {
                continue;
            }

            $isOptional = $param->isOptional();

            if ($pType === 'true') {
                $phpType = 'bool';
                $docHint = 'bool';
                $isOptional = true;
            } else {
                $phpType = TypeMap::php($pType, $param->isVector());
                $docHint = TypeMap::doc($pType, $param->isVector());
            }

            if (($simplified = TypeMap::simplified($pType)) !== null) {
                $phpType = $simplified;
                $docHint = $simplified;
            }

            $smartDefault = null;
            if (!$isOptional && isset(self::PARAM_DEFAULTS[$pName][$pType])
                && !($pName === 'hash' && in_array($fullName, self::HASH_EXCLUSIONS, true))) {
                $smartDefault = self::PARAM_DEFAULTS[$pName][$pType];
                $isOptional = true;
            }

            $args[] = ['param' => $param, 'defaulted' => $smartDefault !== null];

            if (!$isOptional) {
                $required[] = "{$phpType} \${$pName}";
                $docs[] = "     * @param {$docHint} \${$pName}";
                continue;
            }

            if ($pType === 'true') {
                $optional[] = "bool \${$pName} = false";
            } elseif ($smartDefault !== null) {
                $optional[] = "{$phpType} \${$pName} = {$smartDefault}";
            } elseif ($phpType === 'mixed') {
                $optional[] = "mixed \${$pName} = null";
            } else {
                $optional[] = "{$phpType}|null \${$pName} = null";
            }

            $docs[] = match (true) {
                $smartDefault !== null => "     * @param {$docHint} \${$pName} [default: {$smartDefault}]",
                $docHint === 'mixed' => "     * @param mixed \${$pName}",
                default => "     * @param {$docHint}|null \${$pName}",
            };
        }

        $parseMode = TypeMap::supportsParseMode($method);
        if ($parseMode) {
            $optional[] = '?string $parse_mode = null';
            $docs[] = "     * @param string|null \$parse_mode  Bot-API style ('html'|'markdown') - auto-fills \$entities from the text when \$entities is omitted";
        }

        $returnType = $method->getType();
        $typeClass = TypeMap::returnClass($returnType, self::NAMESPACE);
        [$isVector] = TypeMap::unwrapVector($returnType);
        $isPrimitive = TypeMap::isPrimitive($returnType);
        $hint = TypeMap::returnHint($returnType);

        $buf = "\n";
        $buf .= "    /**\n";
        $buf .= "     * {$fullName}\n";
        $buf .= "     *\n";
        foreach ($docs as $doc) {
            $buf .= "{$doc}\n";
        }
        $buf .= "     * @return " . TypeMap::returnDoc($returnType, self::NAMESPACE) . "\n";
        $buf .= "     */\n";

        $params = array_merge($required, $optional);
        if ($params !== []) {
            $buf .= "    public function {$name}(\n        " . implode(",\n        ", $params) . ",\n    ): {$hint} {\n";
        } else {
            $buf .= "    public function {$name}(): {$hint} {\n";
        }

        if ($args === []) {
            $buf .= "        \$__result = \$this->client->invoke('{$fullName}');\n";
        } else {
            $buf .= "        \$__args = [];\n";
            foreach ($args as ['param' => $param, 'defaulted' => $defaulted]) {
                $pName = $param->getName();
                if ($defaulted || !$param->isOptional()) {
                    $buf .= "        \$__args['{$pName}'] = \${$pName};\n";
                } elseif ($param->getType() === 'true') {
                    $buf .= "        if (\${$pName}) \$__args['{$pName}'] = \${$pName};\n";
                } else {
                    $buf .= "        if (\${$pName} !== null) \$__args['{$pName}'] = \${$pName};\n";
                }
            }
            if ($parseMode) {
                $buf .= "        if (\$parse_mode !== null) \$__args['parse_mode'] = \$parse_mode;\n";
            }
            $buf .= "        \$__result = \$this->client->invoke('{$fullName}', \$__args);\n";
        }

        $buf .= match (true) {
            $isPrimitive => "        return \$__result;\n",
            $isVector && $typeClass !== null => "        return array_map(static fn(array \$item) => new \\{$typeClass}(\$item), \$__result);\n",
            $isVector => "        return \$__result;\n",
            $typeClass !== null => "        return new \\{$typeClass}(\$__result);\n",
            default => "        return new TLObject(\$__result);\n",
        };

        $buf .= "    }\n";

        return $buf;
    }

    /**
     * @param array<string, list<TLConstructor>> $typeGroups
     */
    private function writeTypes(string $build, array $typeGroups): void
    {
        foreach ($typeGroups as $resultType => $constructors) {
            $className = TypeMap::className($resultType);

            $props = [];
            $names = [];
            foreach ($constructors as $constructor) {
                $names[] = $constructor->getFullName();
                foreach ($constructor->getParams() as $param) {
                    if ($param->getType() === '#' || isset($props[$param->getName()])) {
                        continue;
                    }
                    $props[$param->getName()] = [
                        'doc' => TypeMap::property($param->getType(), $param->isVector()),
                        'optional' => $param->isOptional(),
                    ];
                }
            }

            $buf = "<?php\n\n";
            $buf .= "declare(strict_types=1);\n\n";
            $buf .= "namespace " . self::NAMESPACE . "\\Types;\n\n";
            $buf .= "use LaraGram\\MTProto\\TL\\TLObject;\n\n";
            $buf .= "/**\n";
            $buf .= " * Auto-generated by TL Compiler — DO NOT EDIT\n";
            $buf .= " *\n";
            $buf .= " * TL type: {$resultType}\n";
            $buf .= " * Constructors: " . implode(', ', $names) . "\n";
            $buf .= " *\n";
            foreach ($props as $prop => $info) {
                $buf .= " * @property-read {$info['doc']}" . ($info['optional'] ? '|null' : '') . " \${$prop}\n";
            }
            $buf .= " */\n";
            $buf .= "class {$className} extends TLObject {}\n";

            $this->put($build . '/Types/' . $className . '.php', $buf);
        }
    }

    /**
     * @param array<string, list<TLConstructor>> $typeGroups
     */
    private function writeConstructorMap(string $build, array $typeGroups): void
    {
        $buf = "<?php\n\n";
        $buf .= "declare(strict_types=1);\n\n";
        $buf .= "namespace " . self::NAMESPACE . "\\Types;\n\n";
        $buf .= "/**\n";
        $buf .= " * Auto-generated mapping: TL constructor name → Type class FQCN.\n";
        $buf .= " *\n";
        $buf .= " * Used by TLObject::fromArray() to resolve the correct subclass.\n";
        $buf .= " */\n";
        $buf .= "final class ConstructorMap\n";
        $buf .= "{\n";
        $buf .= "    /** @var array<string, class-string> */\n";
        $buf .= "    public const MAP = [\n";
        foreach ($typeGroups as $resultType => $constructors) {
            $className = TypeMap::className($resultType);
            foreach ($constructors as $constructor) {
                $buf .= "        '{$constructor->getFullName()}' => {$className}::class,\n";
            }
        }
        $buf .= "    ];\n";
        $buf .= "}\n";

        $this->put($build . '/Types/ConstructorMap.php', $buf);
    }

    /**
     * @param array<string, list<TLMethod>> $methodsByNamespace
     * @return array{0: array<string, string>, 1: array<string, list<string>>}
     *         [shortcut => namespace, shortcut => [winner, ...shadowed]]
     */
    private function writeClientMethods(string $build, array $methodsByNamespace): array
    {
        [$shortcuts, $collisions] = $this->resolveShortcuts($methodsByNamespace);
        $base = self::NAMESPACE;

        $buf = "<?php\n\n";
        $buf .= "/**\n";
        $buf .= " * Auto-generated by TL Compiler — DO NOT EDIT\n";
        $buf .= " *\n";
        $buf .= " * Provides flat method access on Client:\n";
        $buf .= " *   \$client->sendMessage(peer: '@user', message: 'hi')\n";
        $buf .= " *\n";
        $buf .= " * Namespace properties (\$client->messages, \$client->users, etc.)\n";
        $buf .= " * are documented in ClientIdeHelper via @mixin on Client.\n";
        $buf .= " */\n\n";
        $buf .= "declare(strict_types=1);\n\n";
        $buf .= "namespace {$base};\n\n";
        $buf .= "use LaraGram\\MTProto\\Core\\Client;\n";
        foreach (array_keys($methodsByNamespace) as $namespace) {
            $buf .= "use {$base}\\Methods\\" . ucfirst($namespace) . ";\n";
        }

        $buf .= "\ntrait ClientMethods\n";
        $buf .= "{\n";
        $buf .= "    /** @var list<string> All API namespace names. */\n";
        $buf .= "    public const NAMESPACES = [\n";
        foreach (array_keys($methodsByNamespace) as $namespace) {
            $buf .= "        '{$namespace}',\n";
        }
        $buf .= "    ];\n\n";
        $buf .= "    /**\n";
        $buf .= "     * All API namespace names ('messages', 'users', 'ephemeral', …).\n";
        $buf .= "     */\n";
        $buf .= "    public static function namespaceNames(): array\n";
        $buf .= "    {\n";
        $buf .= "        return self::NAMESPACES;\n";
        $buf .= "    }\n\n";
        $buf .= "    /**\n";
        $buf .= "     * Whether \$name is one of the API namespaces.\n";
        $buf .= "     */\n";
        $buf .= "    public static function isNamespace(string \$name): bool\n";
        $buf .= "    {\n";
        $buf .= "        return \\in_array(\$name, self::NAMESPACES, true);\n";
        $buf .= "    }\n\n";
        $buf .= "    /** @var array<string, object> */\n";
        $buf .= "    private array \$__namespaces = [];\n\n";
        $buf .= "    /**\n";
        $buf .= "     * Access a method namespace.\n";
        $buf .= "     */\n";
        $buf .= "    public function __get(string \$name): object\n";
        $buf .= "    {\n";
        $buf .= "        if (isset(\$this->__namespaces[\$name])) {\n";
        $buf .= "            return \$this->__namespaces[\$name];\n";
        $buf .= "        }\n\n";
        $buf .= "        \$class = match (\$name) {\n";
        foreach (array_keys($methodsByNamespace) as $namespace) {
            $buf .= "            '{$namespace}' => " . ucfirst($namespace) . "::class,\n";
        }
        $buf .= "            default => null,\n";
        $buf .= "        };\n\n";
        $buf .= "        if (\$class !== null) {\n";
        $buf .= "            /** @var Client \$this */\n";
        $buf .= "            \$this->__namespaces[\$name] = new \$class(\$this);\n";
        $buf .= "            return \$this->__namespaces[\$name];\n";
        $buf .= "        }\n\n";
        $buf .= "        throw new \\BadMethodCallException(\"Unknown namespace: {\$name}\");\n";
        $buf .= "    }\n\n";
        $buf .= "    /**\n";
        $buf .= "     * Flat method call: \$client->sendMessage(...) delegates to \$client->messages->sendMessage(...)\n";
        $buf .= "     */\n";
        $buf .= "    public function __call(string \$name, array \$arguments): mixed\n";
        $buf .= "    {\n";
        $buf .= "        static \$map = [\n";
        foreach ($shortcuts as $name => $namespace) {
            $buf .= "            '{$name}' => '{$namespace}',\n";
        }
        $buf .= "        ];\n\n";
        $buf .= "        if (isset(\$map[\$name])) {\n";
        $buf .= "            return \$this->__get(\$map[\$name])->{\$name}(...\$arguments);\n";
        $buf .= "        }\n\n";
        $buf .= "        throw new \\BadMethodCallException(\"Unknown method: {\$name}\");\n";
        $buf .= "    }\n\n";
        $buf .= "    /**\n";
        $buf .= "     * Reset cached namespace instances (e.g. on DC switch).\n";
        $buf .= "     */\n";
        $buf .= "    private function resetNamespaces(): void\n";
        $buf .= "    {\n";
        $buf .= "        \$this->__namespaces = [];\n";
        $buf .= "    }\n";
        $buf .= "}\n";

        $this->put($build . '/ClientMethods.php', $buf);

        return [$shortcuts, $collisions];
    }

    /**
     * Map every method name to the namespace its flat shortcut calls. The
     * first namespace alphabetically wins unless {@see FLAT_OVERRIDES} says otherwise.
     *
     * @param array<string, list<TLMethod>> $methodsByNamespace
     * @return array{0: array<string, string>, 1: array<string, list<string>>}
     */
    private function resolveShortcuts(array $methodsByNamespace): array
    {
        $candidates = [];
        foreach ($methodsByNamespace as $namespace => $methods) {
            foreach ($methods as $method) {
                $candidates[$method->getName()][] = $namespace;
            }
        }

        $shortcuts = [];
        foreach ($candidates as $name => $namespaces) {
            $shortcuts[$name] = $namespaces[0];
        }

        foreach (self::FLAT_OVERRIDES as $name => $namespace) {
            if (!isset($candidates[$name])) {
                $this->warn("Flat override '{$name}' does not exist in any namespace - ignored");
                continue;
            }
            if (!in_array($namespace, $candidates[$name], true)) {
                $this->warn("Flat override: namespace '{$namespace}' has no '{$name}' (available: " . implode(', ', $candidates[$name]) . ') - ignored');
                continue;
            }
            $shortcuts[$name] = $namespace;
        }

        $collisions = [];
        foreach ($candidates as $name => $namespaces) {
            if (count($namespaces) > 1) {
                $winner = $shortcuts[$name];
                $collisions[$name] = array_merge([$winner], array_values(array_diff($namespaces, [$winner])));
            }
        }

        return [$shortcuts, $collisions];
    }

    /**
     * @param array<string, list<TLMethod>> $methodsByNamespace
     * @param array<string, string> $shortcuts
     */
    private function writeIdeHelper(string $build, array $methodsByNamespace, array $shortcuts): void
    {
        $base = self::NAMESPACE;

        $buf = "<?php\n\n";
        $buf .= "/**\n";
        $buf .= " * Auto-generated IDE helper — DO NOT EDIT\n";
        $buf .= " *\n";
        $buf .= " * Provides IDE autocompletion for \$client->sendMessage() etc.\n";
        $buf .= " * This file should NOT be included in production — it's only for static analysis.\n";
        $buf .= " */\n\n";
        $buf .= "declare(strict_types=1);\n\n";
        $buf .= "namespace {$base};\n\n";
        $buf .= "/**\n";

        foreach (array_keys($methodsByNamespace) as $namespace) {
            $buf .= " * @property-read \\{$base}\\Methods\\" . ucfirst($namespace) . " \${$namespace}\n";
        }

        $buf .= " *\n";

        foreach ($methodsByNamespace as $namespace => $methods) {
            foreach ($methods as $method) {
                if (($shortcuts[$method->getName()] ?? '') !== $namespace) {
                    continue;
                }
                $buf .= " * @method " . TypeMap::returnDoc($method->getType(), $base) . " {$method->getName()}(" . $this->ideSignature($method) . ")\n";
            }
        }

        $buf .= " */\n";
        $buf .= "class ClientIdeHelper {}\n";

        $this->put($build . '/_ide_helper.php', $buf);
    }

    private function ideSignature(TLMethod $method): string
    {
        $required = [];
        $optional = [];

        foreach ($method->getParams() as $param) {
            $pName = $param->getName();
            $pType = $param->getType();

            if ($pType === '#') {
                continue;
            }
            if (isset(self::AUTO_FILLED[$pName]) && in_array($pType, self::AUTO_FILLED[$pName], true)) {
                continue;
            }

            $doc = $pType === 'true' ? 'bool' : TypeMap::doc($pType, $param->isVector());
            if (($simplified = TypeMap::simplified($pType)) !== null) {
                $doc = $simplified;
            }

            $isOptional = $param->isOptional() || $pType === 'true';

            $smartDefault = null;
            if (!$isOptional && isset(self::PARAM_DEFAULTS[$pName][$pType])
                && !($pName === 'hash' && in_array($method->getFullName(), self::HASH_EXCLUSIONS, true))) {
                $smartDefault = self::PARAM_DEFAULTS[$pName][$pType];
                $isOptional = true;
            }

            if (!$isOptional) {
                $required[] = "{$doc} \${$pName}";
            } elseif ($smartDefault !== null) {
                $optional[] = "{$doc} \${$pName} = {$smartDefault}";
            } elseif ($pType === 'true') {
                $optional[] = "bool \${$pName} = false";
            } elseif ($doc === 'mixed') {
                $optional[] = "mixed \${$pName} = null";
            } else {
                $optional[] = "{$doc}|null \${$pName} = null";
            }
        }

        if (TypeMap::supportsParseMode($method)) {
            $optional[] = 'string|null $parse_mode = null';
        }

        return implode(', ', array_merge($required, $optional));
    }

    /**
     * Write the compiled runtime schema (`schema.json`): every constructor and
     * method of all schema files, loaded by {@see TLParser::shared()} so the
     * runtime always speaks exactly the schema the classes were generated from.
     *
     * Row format: [id, fullName, type, [[name, type, optional, flagIndex, flagField, vector, bare], ...]]
     */
    private function writeRuntimeSchema(string $build, TLParser $parser, int $layer, string $hash): void
    {
        $rows = static function (array $definitions): array {
            $rows = [];
            foreach ($definitions as $definition) {
                /** @var TLConstructor|TLMethod $definition */
                $params = [];
                foreach ($definition->getParams() as $p) {
                    $params[] = [$p->name, $p->type, $p->isOptional, $p->flagIndex, $p->flagField, $p->isVector, $p->isBare];
                }
                $rows[] = [$definition->getId(), $definition->getFullName(), $definition->getType(), $params];
            }

            return $rows;
        };

        $json = json_encode([
            'layer' => $layer,
            'hash' => $hash,
            'constructors' => $rows($parser->getConstructors()),
            'methods' => $rows($parser->getMethods()),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->put($build . '/' . self::RUNTIME_SCHEMA, $json);
    }

    private function writeLayer(string $build, int $layer, string $hash): void
    {
        $source = SchemaSource::isPackage($this->schemaDirectory) ? 'package' : 'published';

        $buf = "<?php\n\n";
        $buf .= "/**\n";
        $buf .= " * Auto-generated by TL Compiler — DO NOT EDIT\n";
        $buf .= " */\n\n";
        $buf .= "declare(strict_types=1);\n\n";
        $buf .= "namespace " . self::NAMESPACE . ";\n\n";
        $buf .= "final class Layer\n";
        $buf .= "{\n";
        $buf .= "    /** The API layer the Generated classes were compiled from. */\n";
        $buf .= "    public const VERSION = {$layer};\n\n";
        $buf .= "    /** sha256 of the schema files. */\n";
        $buf .= "    public const SCHEMA_HASH = " . var_export($hash, true) . ";\n\n";
        $buf .= "    /** Where the schema came from: 'package' or 'published'. */\n";
        $buf .= "    public const SOURCE = " . var_export($source, true) . ";\n";
        $buf .= "}\n";

        $this->put($build . '/Layer.php', $buf);
    }

    /**
     * The previously compiled runtime schema, if any.
     *
     * @return array{layer: int, hash: string, constructors: list<array>, methods: list<array>}|null
     */
    private function previousSchema(): ?array
    {
        $path = $this->outputDirectory . '/' . self::RUNTIME_SCHEMA;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) && isset($data['constructors'], $data['methods']) ? $data : null;
    }

    /**
     * Definitions added, removed or changed (same name, new id) against $previous.
     *
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function diff(array $previous, TLParser $current): array
    {
        $old = [];
        foreach (['constructors', 'methods'] as $kind) {
            foreach ($previous[$kind] ?? [] as $row) {
                $old[$row[1]] = (int) $row[0];
            }
        }

        $new = [];
        foreach ([$current->getConstructors(), $current->getMethods()] as $definitions) {
            foreach ($definitions as $definition) {
                $new[$definition->getFullName()] = $definition->getId();
            }
        }

        $changed = [];
        foreach (array_intersect_key($new, $old) as $name => $id) {
            if ($old[$name] !== $id) {
                $changed[] = $name;
            }
        }

        $added = array_keys(array_diff_key($new, $old));
        $removed = array_keys(array_diff_key($old, $new));
        sort($added);
        sort($removed);
        sort($changed);

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * Replace the live output directory with the freshly built one.
     */
    private function swap(string $build): void
    {
        $old = null;
        if (is_dir($this->outputDirectory)) {
            $old = $this->outputDirectory . '.old-' . bin2hex(random_bytes(4));
            if (!@rename($this->outputDirectory, $old)) {
                throw new MTProtoException("Cannot replace {$this->outputDirectory} (is it writable?)");
            }
        }

        if (!@rename($build, $this->outputDirectory)) {
            if ($old !== null) {
                @rename($old, $this->outputDirectory);
            }
            throw new MTProtoException("Cannot install generated classes into {$this->outputDirectory}");
        }

        if ($old !== null) {
            $this->deleteDirectory($old);
        }
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        $this->reporter->warn($message);
    }

    private function put(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new MTProtoException("Cannot write {$path}");
        }
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new MTProtoException("Cannot create {$directory}");
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
