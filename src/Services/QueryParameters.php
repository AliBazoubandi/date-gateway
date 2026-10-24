<?php
declare(strict_types=1);
namespace Services;

use Core\Time;
use DateTimeImmutable;

/**
 * Handles named SQL query parameters (:param_name).
 *
 * Responsibilities:
 *  1. Extract parameter names from SQL text (ignoring comments, strings, and ::type casts).
 *  2. Parse, normalize, and validate parameter schemas.
 *  3. Validate and resolve client-provided parameters against the schema (enforcing required,
 *     type casting, and resolving dynamic defaults like 'today', 'now', 'start_of_month').
 *  4. Prepare dialect-specific parameterized SQL and positional values for drivers:
 *     - MySQL & SQL Server: transforms :param to ? and aligns positional value list.
 *     - PostgreSQL: transforms :param to $1, $2, ... and aligns positional value list.
 *  5. Compute canonical parameter hash for cache and execution isolation.
 */
final class QueryParameters
{
    /** Supported types */
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_BOOL = 'bool';

    public const TYPES = [
        self::TYPE_STRING,
        self::TYPE_INT,
        self::TYPE_FLOAT,
        self::TYPE_DATE,
        self::TYPE_DATETIME,
        self::TYPE_BOOL,
    ];

    /**
     * Extracts named parameter placeholders (:param_name) from SQL.
     * Skips single-quoted strings, PostgreSQL dollar quotes, line comments (-- and #),
     * block comments, and PostgreSQL ::cast operators.
     *
     * @return list<string> unique list of parameter names in order of appearance
     */
    public static function extract(string $sql): array
    {
        $len = strlen($sql);
        $params = [];
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Line comment --
            if ($ch === '-' && $next === '-') {
                $i += 2;
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                continue;
            }

            // Line comment # (MySQL)
            if ($ch === '#') {
                $i++;
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                continue;
            }

            // Block comment /* ... */
            if ($ch === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            // Quoted string '...' or "..."
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    if ($sql[$i] === '\\' && $i + 1 < $len) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                }
                continue;
            }

            // PostgreSQL dollar quotes $$...$$ or $tag$...$tag$
            if ($ch === '$' && preg_match('/^\$([a-zA-Z0-9_]*)\$/', substr($sql, $i), $m)) {
                $tag = $m[0];
                $tagLen = strlen($tag);
                $pos = strpos($sql, $tag, $i + $tagLen);
                if ($pos !== false) {
                    $i = $pos + $tagLen;
                    continue;
                }
            }

            // Check for :: (PostgreSQL type cast - do not treat as param)
            if ($ch === ':' && $next === ':') {
                $i += 2;
                continue;
            }

            // Named parameter :param_name
            if ($ch === ':' && preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)/', substr($sql, $i), $m)) {
                $paramName = $m[1];
                if (!in_array($paramName, $params, true)) {
                    $params[] = $paramName;
                }
                $i += strlen($m[0]);
                continue;
            }

            $i++;
        }

        return $params;
    }

    /**
     * Normalizes parameter definitions array from JSON or auto-extracts from SQL.
     *
     * @return array<string, array{name:string,type:string,required:bool,default:?string,description:?string}>
     */
    public static function normalizeSchema(?string $json, string $sql = ''): array
    {
        $schema = [];
        if ($json !== null && trim($json) !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = !empty($item['name']) && is_string($item['name'])
                        ? trim($item['name'])
                        : (is_string($k) ? trim($k) : '');
                    if ($name === '') {
                        continue;
                    }
                    $type = in_array($item['type'] ?? '', self::TYPES, true) ? $item['type'] : self::TYPE_STRING;
                    $required = !empty($item['required']);
                    $default = isset($item['default']) && $item['default'] !== '' ? (string)$item['default'] : null;
                    $description = isset($item['description']) && $item['description'] !== '' ? (string)$item['description'] : null;
                    $schema[$name] = [
                        'name' => $name,
                        'type' => $type,
                        'required' => $required,
                        'default' => $default,
                        'description' => $description,
                    ];
                }
            }
        }

        // Merge any detected placeholders from SQL that weren't explicitly defined in JSON
        if ($sql !== '') {
            $extracted = self::extract($sql);
            // If JSON was provided, keep only parameters that actually appear in SQL
            if ($schema !== []) {
                $schema = array_intersect_key($schema, array_flip($extracted));
            }
            foreach ($extracted as $name) {
                if (!isset($schema[$name])) {
                    $schema[$name] = [
                        'name' => $name,
                        'type' => self::TYPE_STRING,
                        'required' => true,
                        'default' => null,
                        'description' => null,
                    ];
                }
            }
        }

        return $schema;
    }

    /**
     * Validates client-supplied inputs against parameter schemas and resolves defaults.
     *
     * @param array<string, array{name:string,type:string,required:bool,default:?string,description:?string}> $schema
     * @param array<string, mixed> $input
     * @return array<string, mixed> resolved associative parameters
     * @throws QueryParameterException on validation error
     */
    public static function resolveClientParams(array $schema, array $input): array
    {
        $resolved = [];
        // Flatten params if passed under 'params' key
        $flatInput = isset($input['params']) && is_array($input['params']) ? array_merge($input, $input['params']) : $input;

        foreach ($schema as $name => $def) {
            $hasVal = array_key_exists($name, $flatInput);
            $raw = $hasVal ? $flatInput[$name] : null;

            // Handle missing / null / empty string
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                if ($def['required']) {
                    throw new QueryParameterException('missing_parameter', "Parameter '{$name}' is required.");
                }

                // Resolve default value if configured
                $resolved[$name] = self::resolveDefault($def['default'], $def['type']);
                continue;
            }

            // Validate and cast type
            $resolved[$name] = self::castAndValidate($name, $raw, $def['type']);
        }

        return $resolved;
    }

    /**
     * Resolves dynamic or static default value.
     */
    public static function resolveDefault(?string $default, string $type): mixed
    {
        if ($default === null || $default === '') {
            return null;
        }

        $lower = strtolower(trim($default));
        if ($type === self::TYPE_DATE || $type === self::TYPE_DATETIME) {
            if ($lower === 'today') {
                return Time::nowDt()->format($type === self::TYPE_DATE ? 'Y-m-d' : 'Y-m-d 00:00:00');
            }
            if ($lower === 'now') {
                return Time::nowDt()->format($type === self::TYPE_DATE ? 'Y-m-d' : 'Y-m-d H:i:s');
            }
            if ($lower === 'start_of_month') {
                return Time::nowDt()->format($type === self::TYPE_DATE ? 'Y-m-01' : 'Y-m-01 00:00:00');
            }
        }

        return self::castAndValidate('default', $default, $type);
    }

    /**
     * Validates and casts a parameter value.
     */
    private static function castAndValidate(string $name, mixed $val, string $type): mixed
    {
        return match ($type) {
            self::TYPE_INT => (static function () use ($name, $val): int {
                if (!is_int($val) && filter_var($val, FILTER_VALIDATE_INT) === false) {
                    throw new QueryParameterException('invalid_parameter', "Parameter '{$name}' must be an integer.");
                }
                return (int)$val;
            })(),
            self::TYPE_FLOAT => (static function () use ($name, $val): float {
                if (!is_numeric($val)) {
                    throw new QueryParameterException('invalid_parameter', "Parameter '{$name}' must be a number.");
                }
                return (float)$val;
            })(),
            self::TYPE_DATE => (static function () use ($name, $val): string {
                $str = trim((string)$val);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) !== 1) {
                    throw new QueryParameterException('invalid_parameter', "Parameter '{$name}' must be a valid date in YYYY-MM-DD format.");
                }
                [$y, $m, $d] = explode('-', $str);
                if (!checkdate((int)$m, (int)$d, (int)$y)) {
                    throw new QueryParameterException('invalid_parameter', "Parameter '{$name}' contains an invalid calendar date.");
                }
                return $str;
            })(),
            self::TYPE_DATETIME => (static function () use ($name, $val): string {
                $str = trim((string)$val);
                try {
                    $dt = new DateTimeImmutable($str, Time::timezone());
                    return $dt->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    throw new QueryParameterException('invalid_parameter', "Parameter '{$name}' must be a valid ISO 8601 or YYYY-MM-DD HH:MM:SS date/time string.");
                }
            })(),
            self::TYPE_BOOL => (static function () use ($name, $val): int {
                if (is_bool($val)) {
                    return $val ? 1 : 0;
                }
                $str = strtolower(trim((string)$val));
                if (in_array($str, ['1', 'true', 'yes', 'on'], true)) {
                    return 1;
                }
                if (in_array($str, ['0', 'false', 'no', 'off'], true)) {
                    return 0;
                }
                throw new QueryParameterException('invalid_parameter', "Parameter '{$name}' must be a boolean (true/false).");
            })(),
            default => (string)$val,
        };
    }

    /**
     * Computes a canonical SHA-256 hash for an array of resolved parameters.
     * Returns null if no parameters are present.
     */
    public static function hash(array $params): ?string
    {
        if ($params === []) {
            return null;
        }
        ksort($params);
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', (string)$json);
    }

    /**
     * Converts named parameters (:param) in SQL into dialect-specific prepared statement placeholders:
     *  - mysql / sqlserver: replaces with ? and constructs positional value list
     *  - pgsql: replaces with $1, $2, ... and constructs positional value list
     *
     * @param array<string, mixed> $namedValues
     * @return array{sql:string, params:list<mixed>}
     */
    public static function prepare(string $sql, array $namedValues, string $dbType): array
    {
        $dbType = strtolower($dbType);
        $len = strlen($sql);
        $outSql = '';
        $boundValues = [];
        $pgParamMap = [];
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Line comment --
            if ($ch === '-' && $next === '-') {
                $start = $i;
                $i += 2;
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                $outSql .= substr($sql, $start, $i - $start);
                continue;
            }

            // Line comment #
            if ($ch === '#') {
                $start = $i;
                $i++;
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                $outSql .= substr($sql, $start, $i - $start);
                continue;
            }

            // Block comment /* ... */
            if ($ch === '/' && $next === '*') {
                $start = $i;
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                $outSql .= substr($sql, $start, $i - $start);
                continue;
            }

            // Quoted string '...' or "..."
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    if ($sql[$i] === '\\' && $i + 1 < $len) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                }
                $outSql .= substr($sql, $start, $i - $start);
                continue;
            }

            // PostgreSQL dollar quotes $$...$$ or $tag$...$tag$
            if ($ch === '$' && preg_match('/^\$([a-zA-Z0-9_]*)\$/', substr($sql, $i), $m)) {
                $tag = $m[0];
                $tagLen = strlen($tag);
                $pos = strpos($sql, $tag, $i + $tagLen);
                if ($pos !== false) {
                    $end = $pos + $tagLen;
                    $outSql .= substr($sql, $i, $end - $i);
                    $i = $end;
                    continue;
                }
            }

            // Check for :: (PostgreSQL type cast - do not treat as param)
            if ($ch === ':' && $next === ':') {
                $outSql .= '::';
                $i += 2;
                continue;
            }

            // Named parameter :param_name
            if ($ch === ':' && preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)/', substr($sql, $i), $m)) {
                $paramName = $m[1];
                $paramVal = array_key_exists($paramName, $namedValues) ? $namedValues[$paramName] : null;

                if ($dbType === 'pgsql' || $dbType === 'postgres' || $dbType === 'postgresql') {
                    if (!isset($pgParamMap[$paramName])) {
                        $boundValues[] = $paramVal;
                        $pgParamMap[$paramName] = count($boundValues);
                    }
                    $outSql .= '$' . $pgParamMap[$paramName];
                } else {
                    // MySQL and SQL Server use ?
                    $outSql .= '?';
                    $boundValues[] = $paramVal;
                }

                $i += strlen($m[0]);
                continue;
            }

            $outSql .= $ch;
            $i++;
        }

        return ['sql' => $outSql, 'params' => $boundValues];
    }
}
