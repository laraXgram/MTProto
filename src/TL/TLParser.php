<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * TL Schema Parser
 * 
 * Parses TL schema files (.tl) into structured PHP objects
 * 
 * TL Schema Format:
 * - Comments: // single line or /* multi line
 * - Constructors: name#id param:Type = ResultType;
 * - Methods: name#id param:Type = ResultType; (after ---functions--- marker)
 * - Flags: param:flags.N?Type
 * - Vectors: Vector<Type>
 * - Bare types: %Type
 */
final class TLParser
{
    /** @var array<string, TLConstructor> Parsed constructors by name */
    private array $constructors = [];

    /** @var array<int, TLConstructor> Parsed constructors by ID */
    private array $constructorsById = [];

    /** @var array<string, TLMethod> Parsed methods by name */
    private array $methods = [];

    /** @var array<int, TLMethod> Parsed methods by ID */
    private array $methodsById = [];

    /** @var array<string, TLType> Types */
    private array $types = [];

    private \LaraGram\Filesystem\Filesystem $files;

    public function __construct(?\LaraGram\Filesystem\Filesystem $files = null)
    {
        $this->files = $files ?? new \LaraGram\Filesystem\Filesystem();
    }

    /**
     * Parse a TL schema file
     */
    public function parseFile(string $filePath): void
    {
        if (!$this->files->exists($filePath)) {
            throw new MTProtoException("TL schema file not found: {$filePath}");
        }

        $this->parse($this->files->get($filePath));
    }

    /**
     * Parse TL schema content
     */
    public function parse(string $content): void
    {
        // Remove multi-line comments
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);

        // Split into lines
        $lines = explode("\n", $content);

        $inFunctions = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and single-line comments
            if (empty($line) || str_starts_with($line, '//')) {
                continue;
            }

            // Check for section markers
            if ($line === '---functions---') {
                $inFunctions = true;
                continue;
            }

            if ($line === '---types---') {
                $inFunctions = false;
                continue;
            }

            // Skip layer declarations and other markers
            if (str_starts_with($line, '---') || !str_contains($line, '=')) {
                continue;
            }

            // Parse the definition
            $this->parseDefinition($line, $inFunctions);
        }

        // Build type mappings
        $this->buildTypes();
    }

    /**
     * Parse a single definition line
     */
    private function parseDefinition(string $line, bool $isFunction): void
    {
        // Remove trailing semicolon
        $line = rtrim($line, ';');

        // Remove generic type annotations like {X:Type}
        $line = preg_replace('/\{[^}]+\}/', '', $line);
        $line = trim($line);

        // Split into left and right parts (around '=')
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            return; // Invalid line
        }

        $leftPart = trim($parts[0]);
        $resultType = trim($parts[1]);

        // Parse left part: namespace.name#id params...
        if (!preg_match('/^([a-zA-Z0-9_.]+)(?:#([0-9a-f]+))?\s*(.*)?$/i', $leftPart, $matches)) {
            return;
        }

        $fullName = $matches[1];
        $hexId = $matches[2] ?? '';
        $paramsStr = $matches[3] ?? '';

        // Extract namespace and name
        $namespace = '';
        $name = $fullName;
        if (str_contains($fullName, '.')) {
            $nameParts = explode('.', $fullName);
            $name = array_pop($nameParts);
            $namespace = implode('.', $nameParts);
        }

        // Calculate or parse ID
        $id = $hexId ? hexdec($hexId) : $this->calculateCRC32($line);

        // Parse parameters
        $params = $this->parseParameters($paramsStr);

        if ($isFunction) {
            $method = new TLMethod(
                name: $name,
                id: (int) $id,
                type: $resultType,
                params: $params,
                namespace: $namespace,
            );
            $this->methods[$method->getFullName()] = $method;
            $this->methodsById[(int) $id] = $method;
        } else {
            $constructor = new TLConstructor(
                name: $name,
                id: (int) $id,
                type: $resultType,
                params: $params,
                namespace: $namespace,
            );
            $this->constructors[$constructor->getFullName()] = $constructor;
            $this->constructorsById[(int) $id] = $constructor;
        }
    }

    /**
     * Parse parameter string into array of TLParameter objects
     */
    private function parseParameters(string $paramsStr): array
    {
        $params = [];
        $paramsStr = trim($paramsStr);

        if (empty($paramsStr)) {
            return $params;
        }

        // Split by whitespace
        $parts = preg_split('/\s+/', $paramsStr);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || !str_contains($part, ':')) {
                continue;
            }

            // Parse name:type
            [$name, $type] = explode(':', $part, 2);

            $isOptional = false;
            $flagIndex = null;
            $flagField = null;
            $isVector = false;
            $isBare = false;

            // Check for flag (e.g., flags.0?true)
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.(\d+)\?(.+)$/', $type, $flagMatch)) {
                $flagField = $flagMatch[1];
                $flagIndex = (int) $flagMatch[2];
                $type = $flagMatch[3];
                $isOptional = true;
            }

            // Check for bare type
            if (str_starts_with($type, '%')) {
                $isBare = true;
                $type = substr($type, 1);
            }

            // Check for boxed generic type (e.g., !X means boxed object)
            if (str_starts_with($type, '!')) {
                // Boxed is the default, so just remove the prefix
                $type = substr($type, 1);
            }

            // Check for vector (boxed Vector<T> or bare vector<T>)
            if (preg_match('/^[Vv]ector<%?(.+)>$/', $type, $vecMatch)) {
                $isVector = true;
                $type = $vecMatch[1];
                // Bare vector uses lowercase 'v'
                if (str_starts_with($type, '%')) {
                    $type = substr($type, 1);
                }
            }

            $params[] = new TLParameter(
                name: $name,
                type: $type,
                isOptional: $isOptional,
                flagIndex: $flagIndex,
                flagField: $flagField,
                isVector: $isVector,
                isBare: $isBare,
            );
        }

        return $params;
    }

    /**
     * Build type mappings from constructors
     */
    private function buildTypes(): void
    {
        $typeConstructors = [];

        foreach ($this->constructors as $constructor) {
            $typeName = $constructor->getType();
            if (!isset($typeConstructors[$typeName])) {
                $typeConstructors[$typeName] = [];
            }
            $typeConstructors[$typeName][] = $constructor;
        }

        foreach ($typeConstructors as $typeName => $constructors) {
            $namespace = '';
            $name = $typeName;
            if (str_contains($typeName, '.')) {
                $parts = explode('.', $typeName);
                $name = array_pop($parts);
                $namespace = implode('.', $parts);
            }

            $this->types[$typeName] = new TLType(
                name: $name,
                namespace: $namespace,
                constructors: $constructors,
            );
        }
    }

    /**
     * Calculate CRC32 for constructor ID
     */
    private function calculateCRC32(string $line): int
    {
        // Normalize the line for CRC calculation
        $normalized = preg_replace('/\s+/', ' ', trim($line));
        $normalized = rtrim($normalized, ';');
        
        return crc32($normalized) & 0xFFFFFFFF;
    }

    /**
     * Get all constructors
     * 
     * @return array<string, TLConstructor>
     */
    public function getConstructors(): array
    {
        return $this->constructors;
    }

    /**
     * Get constructor by name
     */
    public function getConstructor(string $name): ?TLConstructor
    {
        return $this->constructors[$name] ?? null;
    }

    /**
     * Get constructor by ID
     */
    public function getConstructorById(int $id): ?TLConstructor
    {
        return $this->constructorsById[$id] ?? null;
    }

    /**
     * Get all methods
     * 
     * @return array<string, TLMethod>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Get method by name
     */
    public function getMethod(string $name): ?TLMethod
    {
        return $this->methods[$name] ?? null;
    }

    /**
     * Get method by ID
     */
    public function getMethodById(int $id): ?TLMethod
    {
        return $this->methodsById[$id] ?? null;
    }

    /**
     * Get all types
     * 
     * @return array<string, TLType>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Get type by name
     */
    public function getType(string $name): ?TLType
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Export parsed schema to array
     */
    public function toArray(): array
    {
        return [
            'constructors' => array_map(fn($c) => [
                'name' => $c->getFullName(),
                'id' => $c->id,
                'type' => $c->type,
                'params' => array_map(fn($p) => [
                    'name' => $p->name,
                    'type' => $p->type,
                    'optional' => $p->isOptional,
                    'flag_index' => $p->flagIndex,
                    'flag_field' => $p->flagField,
                    'vector' => $p->isVector,
                    'bare' => $p->isBare,
                ], $c->params),
            ], $this->constructors),
            'methods' => array_map(fn($m) => [
                'name' => $m->getFullName(),
                'id' => $m->id,
                'type' => $m->type,
                'params' => array_map(fn($p) => [
                    'name' => $p->name,
                    'type' => $p->type,
                    'optional' => $p->isOptional,
                    'flag_index' => $p->flagIndex,
                    'flag_field' => $p->flagField,
                    'vector' => $p->isVector,
                    'bare' => $p->isBare,
                ], $m->params),
            ], $this->methods),
        ];
    }

    /**
     * Export to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Load from cached JSON
     */
    public function loadFromJson(string $json): void
    {
        $data = json_decode($json, true);

        foreach ($data['constructors'] ?? [] as $c) {
            $params = array_map(fn($p) => new TLParameter(
                name: $p['name'],
                type: $p['type'],
                isOptional: $p['optional'] ?? false,
                flagIndex: $p['flag_index'] ?? null,
                flagField: $p['flag_field'] ?? null,
                isVector: $p['vector'] ?? false,
                isBare: $p['bare'] ?? false,
            ), $c['params'] ?? []);

            $namespace = '';
            $name = $c['name'];
            if (str_contains($c['name'], '.')) {
                $parts = explode('.', $c['name']);
                $name = array_pop($parts);
                $namespace = implode('.', $parts);
            }

            $constructor = new TLConstructor(
                name: $name,
                id: $c['id'],
                type: $c['type'],
                params: $params,
                namespace: $namespace,
            );
            $this->constructors[$constructor->getFullName()] = $constructor;
            $this->constructorsById[$c['id']] = $constructor;
        }

        foreach ($data['methods'] ?? [] as $m) {
            $params = array_map(fn($p) => new TLParameter(
                name: $p['name'],
                type: $p['type'],
                isOptional: $p['optional'] ?? false,
                flagIndex: $p['flag_index'] ?? null,
                flagField: $p['flag_field'] ?? null,
                isVector: $p['vector'] ?? false,
                isBare: $p['bare'] ?? false,
            ), $m['params'] ?? []);

            $namespace = '';
            $name = $m['name'];
            if (str_contains($m['name'], '.')) {
                $parts = explode('.', $m['name']);
                $name = array_pop($parts);
                $namespace = implode('.', $parts);
            }

            $method = new TLMethod(
                name: $name,
                id: $m['id'],
                type: $m['type'],
                params: $params,
                namespace: $namespace,
            );
            $this->methods[$method->getFullName()] = $method;
            $this->methodsById[$m['id']] = $method;
        }

        $this->buildTypes();
    }
}
