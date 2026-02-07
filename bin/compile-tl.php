#!/usr/bin/env php
<?php
/**
 * TL Schema Compiler
 *
 * Reads the TL schema files and generates typed PHP classes:
 *  - src/Generated/Methods/<Namespace>.php   — one class per API namespace
 *  - src/Generated/Types/<Type>.php          — one class per TL constructor
 *  - src/Generated/ClientMethods.php         — trait with flat method shortcuts
 *
 * Usage:
 *   php bin/compile-tl.php
 *
 * Performance: single-pass generation, no template engine, pure string concat.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use LaraGram\MTProto\TL\TLParser;

// ════════════════════════════════════════════════════════════════════════════
//  Configuration
// ════════════════════════════════════════════════════════════════════════════

$ROOT      = dirname(__DIR__);
$SCHEMA_DIR = $ROOT . '/src/TL/schemas';
$OUTPUT_DIR = $ROOT . '/src/Generated';
$SCHEMAS    = ['main_api.tl'];

$NAMESPACE_BASE = 'LaraGram\\MTProto\\Generated';

// ════════════════════════════════════════════════════════════════════════════
//  Smart defaults — params that are technically "required" in TL schema
//  but have obvious sensible defaults. These become optional in PHP.
//  Format: paramName => [ tlType => phpDefaultLiteral ]
// ════════════════════════════════════════════════════════════════════════════

$PARAM_DEFAULTS = [
    'hash'         => ['int' => '0', 'long' => '0'],
    'limit'        => ['int' => '100'],
    'offset_id'    => ['int' => '0', 'long' => '0'],
    'offset_date'  => ['int' => '0'],
    'add_offset'   => ['int' => '0'],
    'max_id'       => ['int' => '0', 'long' => '0'],
    'min_id'       => ['int' => '0', 'long' => '0'],
    'offset'       => ['int' => '0', 'long' => '0', 'string' => "''"],
    'offset_peer'  => ['InputPeer' => "['_' => 'inputPeerEmpty']"],
    'offset_rate'  => ['int' => '0'],
    'min_date'     => ['int' => '0'],
    'max_date'     => ['int' => '0'],
    'offset_topic' => ['int' => '0'],
    'filter'       => ['MessagesFilter' => "['_' => 'inputMessagesFilterEmpty']"],
];

// Methods where 'hash' is NOT a cache hash — never auto-default
$HASH_EXCLUSIONS = [
    'account.resetAuthorization',
    'account.resetWebAuthorization',
    'account.changeAuthorizationSettings',
    'messages.checkChatInvite',
    'messages.importChatInvite',
    'account.sendConfirmPhoneCode',
];

// ════════════════════════════════════════════════════════════════════════════
//  TL Type → PHP Type mapping
// ════════════════════════════════════════════════════════════════════════════

function tlTypeToPhp(string $type, bool $isVector = false, bool $isOptional = false): string
{
    $phpType = match ($type) {
        'int', 'int53'                         => 'int',
        'long', 'int128', 'int256', 'double'   => 'int',
        'double'                               => 'float',
        'string'                               => 'string',
        'bytes'                                => 'string',
        'Bool', 'true'                         => 'bool',
        '#'                                    => 'int',
        'X', '!X', 'Object'                    => 'array',
        default                                => 'array',
    };

    if ($isVector) {
        $phpType = 'array';
    }
    if ($isOptional && $phpType !== 'array') {
        $phpType = '?' . $phpType;
    }

    return $phpType;
}

/**
 * Map a TL type to a PHPDoc type string for richer IDE hints.
 */
function tlTypeToDocType(string $type, bool $isVector = false): string
{
    $docType = match ($type) {
        'int', 'int53'                         => 'int',
        'long', 'int128', 'int256'             => 'int',
        'double'                               => 'float',
        'string'                               => 'string',
        'bytes'                                => 'string',
        'Bool', 'true'                         => 'bool',
        '#'                                    => 'int',
        'X', '!X', 'Object'                    => 'mixed',
        default                                => 'array',
    };

    if ($isVector) {
        return $docType . '[]';
    }

    return $docType;
}

/**
 * Map a TL type to a PHPDoc type for @property-read on Type classes.
 *
 * Unlike tlTypeToDocType(), this resolves complex TL types to their
 * generated Type class names for deep IDE autocompletion:
 *   Chat    → Chat          (same namespace, short name)
 *   User    → User
 *   Peer    → Peer
 *   Bool    → bool
 *   int     → int
 *   Vector<User> with isVector=true → User[]  (handled via isVector)
 */
function tlPropTypeToDoc(string $type, bool $isVector = false): string
{
    // Primitives
    $primitive = match ($type) {
        'int', 'int53'                         => 'int',
        'long', 'int128', 'int256'             => 'int',
        'double'                               => 'float',
        'string'                               => 'string',
        'bytes'                                => 'string',
        'Bool', 'true'                         => 'bool',
        '#'                                    => 'int',
        'X', '!X', 'Object'                    => 'mixed',
        default                                => null,
    };

    if ($primitive !== null) {
        return $isVector ? "{$primitive}[]" : $primitive;
    }

    // Complex TL type → Type class name (same namespace, short name)
    $parts = explode('.', $type);
    $className = implode('', array_map('ucfirst', $parts));

    return $isVector ? "{$className}[]" : $className;
}

/**
 * Convert a TL name like "messages.sendMessage" to a camelCase PHP method name.
 * sendMessage stays sendMessage, getDialogs stays getDialogs.
 */
function methodName(string $name): string
{
    return $name; // TL method names are already camelCase
}

/**
 * Convert a namespace name to PascalCase class name.
 * messages → Messages, auth → Auth, etc.
 */
function namespaceToPascal(string $ns): string
{
    return ucfirst($ns);
}

/**
 * Map a TL method return type to a PHPDoc return type referencing generated Type classes.
 *
 * e.g.  "Updates"             → "\LaraGram\MTProto\Generated\Types\Updates"
 *       "messages.Messages"   → "\LaraGram\MTProto\Generated\Types\MessagesMessages"
 *       "Vector<User>"        → "\LaraGram\MTProto\Generated\Types\User[]"
 *       "Bool"                → "bool"
 *       "X"                   → "array"
 */
function tlReturnTypeToDoc(string $type, string $nsBase): string
{
    // Primitives
    $primitive = match ($type) {
        'Bool', 'true'              => 'bool',
        'X', '!X', 'Object'        => 'array',
        'int', 'int53'              => 'int',
        'long', 'int128', 'int256'  => 'int',
        'double'                    => 'float',
        'string'                    => 'string',
        'bytes'                     => 'string',
        default                     => null,
    };
    if ($primitive !== null) return $primitive;

    // Vector<Type>
    $isVector = false;
    $inner = $type;
    if (preg_match('/^[Vv]ector<(.+)>$/', $type, $vm)) {
        $isVector = true;
        $inner = $vm[1];
    }

    // Primitive vectors
    $innerPrim = match ($inner) {
        'int', 'int53'              => 'int',
        'long', 'int128', 'int256'  => 'int',
        'double'                    => 'float',
        'string'                    => 'string',
        'Bool'                      => 'bool',
        default                     => null,
    };
    if ($innerPrim !== null) {
        return $isVector ? "{$innerPrim}[]" : $innerPrim;
    }

    // Complex type → Generated\Types\ClassName
    $parts = explode('.', $inner);
    $className = implode('', array_map('ucfirst', $parts));
    $fqcn = "\\{$nsBase}\\Types\\{$className}";

    return $isVector ? "{$fqcn}[]" : $fqcn;
}

/**
 * Get the FQCN of the Type class for a TL return type, or null if it's a primitive.
 *
 * Used to generate TLObject::wrap() calls in Method classes.
 */
function tlReturnTypeToClass(string $type, string $nsBase): ?string
{
    // Primitives have no Type class
    if (in_array($type, ['Bool', 'true', 'X', '!X', 'Object', 'int', 'int53', 'long', 'int128', 'int256', 'double', 'string', 'bytes'], true)) {
        return null;
    }

    // Vector<Type> — unwrap inner
    $inner = $type;
    if (preg_match('/^[Vv]ector<(.+)>$/', $type, $vm)) {
        $inner = $vm[1];
        // Primitive vectors
        if (in_array($inner, ['int', 'int53', 'long', 'int128', 'int256', 'double', 'string', 'Bool'], true)) {
            return null;
        }
    }

    $parts = explode('.', $inner);
    $className = implode('', array_map('ucfirst', $parts));
    return "{$nsBase}\\Types\\{$className}";
}

// ════════════════════════════════════════════════════════════════════════════
//  Parse schemas
// ════════════════════════════════════════════════════════════════════════════

echo "🔧 TL Compiler starting...\n";

$parser = new TLParser();
foreach ($SCHEMAS as $schema) {
    $path = $SCHEMA_DIR . '/' . $schema;
    if (!file_exists($path)) {
        echo "  ⚠ Schema not found: {$path}\n";
        continue;
    }
    $parser->parseFile($path);
    echo "  ✓ Parsed {$schema}\n";
}

$methods      = $parser->getMethods();
$constructors = $parser->getConstructors();
$types        = $parser->getTypes();

echo "  📊 Methods: " . count($methods) . ", Constructors: " . count($constructors) . ", Types: " . count($types) . "\n";

// ════════════════════════════════════════════════════════════════════════════
//  Group methods by namespace
// ════════════════════════════════════════════════════════════════════════════

$methodsByNs = [];
foreach ($methods as $m) {
    $ns = $m->getNamespace() ?: '';
    // Skip low-level methods
    if ($ns === '' && in_array($m->getName(), ['invokeAfterMsg', 'invokeAfterMsgs', 'invokeWithLayer', 'invokeWithoutUpdates', 'invokeWithMessagesRange', 'invokeWithTakeout', 'invokeWithBusinessConnection', 'invokeWithGooglePlayIntegrity', 'invokeWithApnsSecret', 'initConnection', 'req_pq_multi'], true)) {
        continue;
    }
    if ($ns === '') {
        continue; // Skip global methods — they're internal MTProto functions
    }
    $methodsByNs[$ns][] = $m;
}

ksort($methodsByNs);

// ════════════════════════════════════════════════════════════════════════════
//  Clean & create output directories
// ════════════════════════════════════════════════════════════════════════════

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Clean previous generation
if (is_dir($OUTPUT_DIR)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($OUTPUT_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
}

ensureDir($OUTPUT_DIR . '/Methods');
ensureDir($OUTPUT_DIR . '/Types');

// ════════════════════════════════════════════════════════════════════════════
//  Generate Method Namespace Classes
// ════════════════════════════════════════════════════════════════════════════

echo "\n📝 Generating Method classes...\n";

$allMethodsFlat = []; // For ClientMethods trait: [fullName => [ns, methodName, params]]

foreach ($methodsByNs as $ns => $nsMethods) {
    $className = namespaceToPascal($ns);
    $buf = "<?php\n\n";
    $buf .= "/**\n";
    $buf .= " * Auto-generated by TL Compiler — DO NOT EDIT\n";
    $buf .= " *\n";
    $buf .= " * TL namespace: {$ns}\n";
    $buf .= " * Methods: " . count($nsMethods) . "\n";
    $buf .= " */\n\n";
    $buf .= "declare(strict_types=1);\n\n";
    $buf .= "namespace {$NAMESPACE_BASE}\\Methods;\n\n";
    $buf .= "use LaraGram\\MTProto\\Core\\Client;\n";
    $buf .= "use LaraGram\\MTProto\\TL\\TLObject;\n\n";
    $buf .= "class {$className}\n";
    $buf .= "{\n";
    $buf .= "    public function __construct(\n";
    $buf .= "        private readonly Client \$client,\n";
    $buf .= "    ) {}\n";

    foreach ($nsMethods as $m) {
        /** @var \LaraGram\MTProto\TL\TLMethod $m */
        $mName  = methodName($m->getName());
        $fullTL = $m->getFullName(); // e.g. "messages.sendMessage"

        // Build parameter list (skip flags fields, skip auto-handled params)
        // Sort: required params first, then optional — PHP requirement
        $requiredParams = [];
        $optionalParams = [];
        $paramNames     = []; // For building the invoke array (in original TL order)
        $docParams      = []; // @param lines
        $smartDefaults  = []; // paramName => true — always include in $__args (not skippable)

        // Params that the preprocessor auto-fills — skip from user-facing signature
        $autoSkip = [
            'random_id'    => ['long'],       // auto-generated random value
            'random_bytes' => ['bytes'],      // auto-generated random bytes
        ];

        foreach ($m->getParams() as $p) {
            $pName = $p->getName();
            $pType = $p->getType();

            // Skip the flags bitvector fields
            if ($pType === '#') {
                continue;
            }

            // Skip auto-filled params
            if (isset($autoSkip[$pName]) && in_array($pType, $autoSkip[$pName], true)) {
                continue;
            }

            $isOptional = $p->isOptional();
            $isVector   = $p->isVector();

            // For 'true' flag params: they're boolean flags
            if ($pType === 'true') {
                $phpType = 'bool';
                $docType = 'bool';
                $isOptional = true; // flag booleans are always optional
            } else {
                $phpType = tlTypeToPhp($pType, $isVector, false);
                $docType = tlTypeToDocType($pType, $isVector);
            }

            // Complex TL types → accept simplified input
            $docHint = $docType;
            if (in_array($pType, ['InputPeer', 'InputUser', 'InputChannel'], true)) {
                $phpType = 'array|int|string';
                $docHint = 'array|int|string';
            } elseif ($pType === 'DataJSON') {
                $phpType = 'mixed';
                $docHint = 'mixed';
            } elseif ($pType === 'InputDialogPeer') {
                $phpType = 'array|int|string';
                $docHint = 'array|int|string';
            } elseif ($pType === 'InputMessage') {
                $phpType = 'array|int';
                $docHint = 'array|int';
            } elseif ($pType === 'TextWithEntities') {
                $phpType = 'array|string';
                $docHint = 'array|string';
            } elseif ($pType === 'InputCheckPasswordSRP') {
                $phpType = 'array|string';
                $docHint = 'array|string';
            }

            $paramNames[] = $pName;

            // ── Smart defaults: make TL-required params optional in PHP ──
            $smartDefault = null;
            if (!$isOptional && isset($PARAM_DEFAULTS[$pName][$pType])) {
                // Exclude methods where the param has different semantics
                $excluded = ($pName === 'hash' && in_array($fullTL, $HASH_EXCLUSIONS, true));
                if (!$excluded) {
                    $smartDefault = $PARAM_DEFAULTS[$pName][$pType];
                    $isOptional = true;
                    $smartDefaults[$pName] = $smartDefault;
                }
            }

            if ($isOptional) {
                $default = match (true) {
                    $smartDefault !== null  => $smartDefault,
                    $pType === 'true'       => 'false',
                    default                 => 'null',
                };
                if ($pType === 'true') {
                    $phpSig = "bool \${$pName} = {$default}";
                } elseif ($smartDefault !== null) {
                    // Smart default: keep original type (no |null), use actual default
                    $phpSig = "{$phpType} \${$pName} = {$default}";
                } elseif ($phpType === 'mixed') {
                    $phpSig = "mixed \${$pName} = null";
                } else {
                    $phpSig = "{$phpType}|null \${$pName} = null";
                }
                if ($smartDefault !== null) {
                    $docParams[] = "     * @param {$docHint} \${$pName} [default: {$default}]";
                } elseif ($docHint === 'mixed') {
                    $docParams[] = "     * @param mixed \${$pName}";
                } else {
                    $docParams[] = "     * @param {$docHint}|null \${$pName}";
                }
                $optionalParams[] = $phpSig;
            } else {
                $phpSig = "{$phpType} \${$pName}";
                $docParams[] = "     * @param {$docHint} \${$pName}";
                $requiredParams[] = $phpSig;
            }
        }

        // Required first, then optional
        $phpParams = array_merge($requiredParams, $optionalParams);

        // Return type — map TL type to PHPDoc Type class reference
        $returnType = $m->getType();
        $docReturn = tlReturnTypeToDoc($returnType, $NAMESPACE_BASE);

        $buf .= "\n";
        $buf .= "    /**\n";
        $buf .= "     * {$fullTL}\n";
        $buf .= "     *\n";

        foreach ($docParams as $dp) {
            $buf .= "{$dp}\n";
        }

        $buf .= "     * @return {$docReturn}\n";
        $buf .= "     */\n";

        // Determine PHP return type for signature
        $typeClass = tlReturnTypeToClass($returnType, $NAMESPACE_BASE);
        $isVector = (bool) preg_match('/^[Vv]ector</', $returnType);
        $isPrimitive = in_array($returnType, ['Bool', 'true', 'int', 'int53', 'long', 'int128', 'int256', 'double', 'string', 'bytes'], true);

        // PHP return type hint
        if ($isPrimitive) {
            $phpReturnType = match ($returnType) {
                'Bool', 'true'              => 'bool',
                'int', 'int53'              => 'int',
                'long', 'int128', 'int256'  => 'int',
                'double'                    => 'float',
                'string', 'bytes'           => 'string',
            };
        } elseif ($isVector && $typeClass === null) {
            $phpReturnType = 'array'; // Vector of primitives
        } elseif ($isVector) {
            $phpReturnType = 'array'; // Vector of TLObjects — still an array at runtime
        } else {
            $phpReturnType = 'TLObject'; // Single TL object
        }

        // Method signature
        $paramStr = implode(",\n        ", $phpParams);
        if (!empty($phpParams)) {
            $buf .= "    public function {$mName}(\n        {$paramStr},\n    ): {$phpReturnType} {\n";
        } else {
            $buf .= "    public function {$mName}(): {$phpReturnType} {\n";
        }

        // Build invocation body
        if (empty($paramNames)) {
            $buf .= "        \$__result = \$this->client->invoke('{$fullTL}');\n";
        } else {
            $buf .= "        \$__args = [];\n";
            foreach ($paramNames as $pn) {
                // Find original param to check if optional
                $origParam = null;
                foreach ($m->getParams() as $p) {
                    if ($p->getName() === $pn) {
                        $origParam = $p;
                        break;
                    }
                }

                // Smart-defaulted params always get included (value is never null)
                if (isset($smartDefaults[$pn])) {
                    $buf .= "        \$__args['{$pn}'] = \${$pn};\n";
                } elseif ($origParam && $origParam->isOptional()) {
                    if ($origParam->getType() === 'true') {
                        // Boolean flags: only include if true
                        $buf .= "        if (\${$pn}) \$__args['{$pn}'] = \${$pn};\n";
                    } else {
                        $buf .= "        if (\${$pn} !== null) \$__args['{$pn}'] = \${$pn};\n";
                    }
                } else {
                    $buf .= "        \$__args['{$pn}'] = \${$pn};\n";
                }
            }
            $buf .= "        \$__result = \$this->client->invoke('{$fullTL}', \$__args);\n";
        }

        // Return with wrapping
        if ($isPrimitive) {
            $buf .= "        return \$__result;\n";
        } elseif ($isVector && $typeClass !== null) {
            // Vector of TL objects — wrap each element
            $buf .= "        return array_map(static fn(array \$item) => new \\{$typeClass}(\$item), \$__result);\n";
        } elseif ($isVector) {
            // Vector of primitives — return as-is
            $buf .= "        return \$__result;\n";
        } elseif ($typeClass !== null) {
            // Single TL object — wrap
            $buf .= "        return new \\{$typeClass}(\$__result);\n";
        } else {
            // Fallback (X, !X, Object)
            $buf .= "        return new TLObject(\$__result);\n";
        }

        $buf .= "    }\n";

        // Collect for flat trait
        $allMethodsFlat[$fullTL] = ['ns' => $ns, 'name' => $mName, 'method' => $m];
    }

    $buf .= "}\n";

    $outPath = $OUTPUT_DIR . '/Methods/' . $className . '.php';
    file_put_contents($outPath, $buf);
    echo "  ✓ Methods/{$className}.php (" . count($nsMethods) . " methods)\n";
}

// ════════════════════════════════════════════════════════════════════════════
//  Generate Type classes (constructors)
// ════════════════════════════════════════════════════════════════════════════

echo "\n📝 Generating Type classes...\n";

// Group constructors by their result type
$typeGroups = []; // resultType => [constructors]
foreach ($constructors as $c) {
    $resultType = $c->getType();
    // Skip primitive types
    if (in_array($resultType, ['Bool', 'True', 'Null', 'Vector t', 'Error', 'X'], true)) {
        continue;
    }
    $typeGroups[$resultType][] = $c;
}

ksort($typeGroups);

$typeCount = 0;
foreach ($typeGroups as $resultType => $ctors) {
    // Convert type name to a valid PHP class name
    // e.g. "messages.Messages" → "MessagesMessages", "User" → "User"
    $parts = explode('.', $resultType);
    $className = implode('', array_map('ucfirst', $parts));

    // Build @property PHPDoc for all possible fields across constructors
    $allProps = []; // name => ['type' => ..., 'docType' => ..., 'optional' => ...]
    $ctorNames = [];

    foreach ($ctors as $c) {
        $ctorNames[] = $c->getFullName();
        foreach ($c->getParams() as $p) {
            if ($p->getType() === '#') continue;
            $pName = $p->getName();
            $docType = tlPropTypeToDoc($p->getType(), $p->isVector());

            if (!isset($allProps[$pName])) {
                $allProps[$pName] = [
                    'docType'  => $docType,
                    'optional' => $p->isOptional(),
                ];
            }
        }
    }

    $buf = "<?php\n\n";
    $buf .= "declare(strict_types=1);\n\n";
    $buf .= "namespace {$NAMESPACE_BASE}\\Types;\n\n";
    $buf .= "use LaraGram\\MTProto\\TL\\TLObject;\n\n";
    $buf .= "/**\n";
    $buf .= " * Auto-generated by TL Compiler — DO NOT EDIT\n";
    $buf .= " *\n";
    $buf .= " * TL type: {$resultType}\n";
    $buf .= " * Constructors: " . implode(', ', $ctorNames) . "\n";
    $buf .= " *\n";

    foreach ($allProps as $propName => $propInfo) {
        $nullSuffix = $propInfo['optional'] ? '|null' : '';
        $buf .= " * @property-read {$propInfo['docType']}{$nullSuffix} \${$propName}\n";
    }

    $buf .= " */\n";
    $buf .= "class {$className} extends TLObject {}\n";

    $outPath = $OUTPUT_DIR . '/Types/' . $className . '.php';
    file_put_contents($outPath, $buf);
    $typeCount++;
}

echo "  ✓ Generated {$typeCount} type classes\n";

// ════════════════════════════════════════════════════════════════════════════
//  Generate constructor → Type class map (for TLObject::fromArray())
// ════════════════════════════════════════════════════════════════════════════

echo "\n📝 Generating constructor map...\n";

$buf = "<?php\n\n";
$buf .= "declare(strict_types=1);\n\n";
$buf .= "namespace {$NAMESPACE_BASE}\\Types;\n\n";
$buf .= "/**\n";
$buf .= " * Auto-generated mapping: TL constructor name → Type class FQCN.\n";
$buf .= " *\n";
$buf .= " * Used by TLObject::fromArray() to resolve the correct subclass.\n";
$buf .= " */\n";
$buf .= "final class ConstructorMap\n";
$buf .= "{\n";
$buf .= "    /** @var array<string, class-string> */\n";
$buf .= "    public const MAP = [\n";

foreach ($typeGroups as $resultType => $ctors) {
    $parts = explode('.', $resultType);
    $className = implode('', array_map('ucfirst', $parts));
    foreach ($ctors as $c) {
        $ctorName = $c->getFullName();
        $buf .= "        '{$ctorName}' => {$className}::class,\n";
    }
}

$buf .= "    ];\n";
$buf .= "}\n";

file_put_contents($OUTPUT_DIR . '/Types/ConstructorMap.php', $buf);
echo "  ✓ ConstructorMap.php (" . array_sum(array_map('count', $typeGroups)) . " constructors)\n";

// ════════════════════════════════════════════════════════════════════════════
//  Generate ClientMethods trait  — flat $client->sendMessage() shortcuts
// ════════════════════════════════════════════════════════════════════════════

echo "\n📝 Generating ClientMethods trait...\n";

$buf = "<?php\n\n";
$buf .= "/**\n";
$buf .= " * Auto-generated by TL Compiler — DO NOT EDIT\n";
$buf .= " *\n";
$buf .= " * Provides flat method access on Client:\n";
$buf .= " *   \$client->sendMessage(peer: '@user', message: 'hi')\n";
$buf .= " *\n";
$buf .= " * Also provides typed namespace properties:\n";

foreach ($methodsByNs as $ns => $nsMethods) {
    $className = namespaceToPascal($ns);
    $buf .= " * @property-read \\{$NAMESPACE_BASE}\\Methods\\{$className} \${$ns}\n";
}

$buf .= " */\n\n";
$buf .= "declare(strict_types=1);\n\n";
$buf .= "namespace {$NAMESPACE_BASE};\n\n";
$buf .= "use LaraGram\\MTProto\\Core\\Client;\n";

foreach ($methodsByNs as $ns => $nsMethods) {
    $className = namespaceToPascal($ns);
    $buf .= "use {$NAMESPACE_BASE}\\Methods\\{$className};\n";
}

$buf .= "\ntrait ClientMethods\n";
$buf .= "{\n";

// Namespace instances cache
$buf .= "    /** @var array<string, object> */\n";
$buf .= "    private array \$__namespaces = [];\n\n";

// __get for namespace access: $client->messages → Messages instance
$buf .= "    /**\n";
$buf .= "     * Access a method namespace.\n";
$buf .= "     */\n";
$buf .= "    public function __get(string \$name): object\n";
$buf .= "    {\n";
$buf .= "        if (isset(\$this->__namespaces[\$name])) {\n";
$buf .= "            return \$this->__namespaces[\$name];\n";
$buf .= "        }\n\n";
$buf .= "        \$class = match (\$name) {\n";

foreach ($methodsByNs as $ns => $nsMethods) {
    $className = namespaceToPascal($ns);
    $buf .= "            '{$ns}' => {$className}::class,\n";
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

// __call for flat method access: $client->sendMessage() → $client->messages->sendMessage()
// Build a lookup map: methodName => namespace (use the first match)
$methodToNs = [];
foreach ($allMethodsFlat as $fullTL => $info) {
    $mName = $info['name'];
    if (!isset($methodToNs[$mName])) {
        $methodToNs[$mName] = $info['ns'];
    }
}

$buf .= "    /**\n";
$buf .= "     * Flat method call: \$client->sendMessage(...) delegates to \$client->messages->sendMessage(...)\n";
$buf .= "     */\n";
$buf .= "    public function __call(string \$name, array \$arguments): mixed\n";
$buf .= "    {\n";
$buf .= "        static \$map = [\n";

foreach ($methodToNs as $mName => $ns) {
    $buf .= "            '{$mName}' => '{$ns}',\n";
}

$buf .= "        ];\n\n";
$buf .= "        if (isset(\$map[\$name])) {\n";
$buf .= "            return \$this->__get(\$map[\$name])->{\$name}(...\$arguments);\n";
$buf .= "        }\n\n";
$buf .= "        throw new \\BadMethodCallException(\"Unknown method: {\$name}\");\n";
$buf .= "    }\n\n";

// Reset namespace cache (called on disconnect/switchDc)
$buf .= "    /**\n";
$buf .= "     * Reset cached namespace instances (e.g. on DC switch).\n";
$buf .= "     */\n";
$buf .= "    private function resetNamespaces(): void\n";
$buf .= "    {\n";
$buf .= "        \$this->__namespaces = [];\n";
$buf .= "    }\n";

$buf .= "}\n";

file_put_contents($OUTPUT_DIR . '/ClientMethods.php', $buf);
echo "  ✓ ClientMethods.php (" . count($methodToNs) . " flat method shortcuts)\n";

// ════════════════════════════════════════════════════════════════════════════
//  Generate IDE helper file with @method annotations
// ════════════════════════════════════════════════════════════════════════════

echo "\n📝 Generating IDE helper...\n";

$buf = "<?php\n\n";
$buf .= "/**\n";
$buf .= " * Auto-generated IDE helper — DO NOT EDIT\n";
$buf .= " *\n";
$buf .= " * Provides IDE autocompletion for \$client->sendMessage() etc.\n";
$buf .= " * This file should NOT be included in production — it's only for static analysis.\n";
$buf .= " */\n\n";
$buf .= "declare(strict_types=1);\n\n";
$buf .= "namespace {$NAMESPACE_BASE};\n\n";
$buf .= "/**\n";

foreach ($methodsByNs as $ns => $nsMethods) {
    $className = namespaceToPascal($ns);
    $buf .= " * @property-read \\{$NAMESPACE_BASE}\\Methods\\{$className} \${$ns}\n";
}

$buf .= " *\n";

// Add @method annotations for the most common flat methods
foreach ($allMethodsFlat as $fullTL => $info) {
    /** @var \LaraGram\MTProto\TL\TLMethod $m */
    $m     = $info['method'];
    $mName = $info['name'];
    $ns    = $info['ns'];

    // Only add if this is the first occurrence (no collision)
    if (($methodToNs[$mName] ?? '') !== $ns) {
        continue;
    }

    // Params that the preprocessor auto-fills — skip from IDE signature
    $autoSkipIde = [
        'random_id'    => ['long'],
        'random_bytes' => ['bytes'],
    ];

    // Build short param signature for @method — sorted: required first, then optional
    $requiredParts = [];
    $optionalParts = [];
    foreach ($m->getParams() as $p) {
        if ($p->getType() === '#') continue;
        $pn = $p->getName();
        $pt = $p->getType();

        // Skip auto-filled params
        if (isset($autoSkipIde[$pn]) && in_array($pt, $autoSkipIde[$pn], true)) {
            continue;
        }

        $docType = tlTypeToDocType($pt, $p->isVector());
        if ($pt === 'true') $docType = 'bool';

        // Simplified input types
        if (in_array($pt, ['InputPeer', 'InputUser', 'InputChannel'], true)) {
            $docType = 'array|int|string';
        } elseif ($pt === 'DataJSON') {
            $docType = 'mixed';           // auto-wrapped to dataJSON
        } elseif ($pt === 'InputDialogPeer') {
            $docType = 'array|int|string'; // auto-wrapped to inputDialogPeer
        } elseif ($pt === 'InputMessage') {
            $docType = 'array|int';        // int auto-wrapped to inputMessageID
        } elseif ($pt === 'TextWithEntities') {
            $docType = 'array|string';     // string auto-wrapped
        } elseif ($pt === 'InputCheckPasswordSRP') {
            $docType = 'array|string';     // string password auto-hashed
        }

        $isOpt = $p->isOptional() || $pt === 'true';

        // Smart defaults: make TL-required params optional
        $smartDef = null;
        if (!$isOpt && isset($PARAM_DEFAULTS[$pn][$pt])) {
            $excluded = ($pn === 'hash' && in_array($fullTL, $HASH_EXCLUSIONS, true));
            if (!$excluded) {
                $smartDef = $PARAM_DEFAULTS[$pn][$pt];
                $isOpt = true;
            }
        }

        if ($isOpt) {
            if ($smartDef !== null) {
                $optionalParts[] = "{$docType} \${$pn} = {$smartDef}";
            } elseif ($pt === 'true') {
                $optionalParts[] = "bool \${$pn} = false";
            } elseif ($docType === 'mixed') {
                $optionalParts[] = "mixed \${$pn} = null";
            } else {
                $optionalParts[] = "{$docType}|null \${$pn} = null";
            }
        } else {
            $requiredParts[] = "{$docType} \${$pn}";
        }
    }

    $allParts = array_merge($requiredParts, $optionalParts);
    $paramStr = implode(', ', $allParts);
    $ideReturn = tlReturnTypeToDoc($m->getType(), $NAMESPACE_BASE);
    $buf .= " * @method {$ideReturn} {$mName}({$paramStr})\n";
}

$buf .= " */\n";
$buf .= "class ClientIdeHelper {}\n";

file_put_contents($OUTPUT_DIR . '/_ide_helper.php', $buf);
echo "  ✓ _ide_helper.php\n";

// ════════════════════════════════════════════════════════════════════════════
//  Summary
// ════════════════════════════════════════════════════════════════════════════

echo "\n✅ TL Compilation complete!\n";
echo "   Output: {$OUTPUT_DIR}/\n";
echo "   Method classes: " . count($methodsByNs) . " namespaces\n";
echo "   Type classes: {$typeCount}\n";
echo "   Flat shortcuts: " . count($methodToNs) . "\n";
