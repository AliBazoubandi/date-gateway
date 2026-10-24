<?php
// Unit and integration tests for QueryParameters service and database parameter binding.
// Usage: php tests/parameters_test.php

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

use Services\QueryParameters;
use Services\QueryParameterException;

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

echo "--- 1. QueryParameters::extract placeholder parser ---\n";

// Basic extraction
$sql = "SELECT id, name FROM users WHERE created_at >= :start_date AND created_at <= :end_date";
$params = QueryParameters::extract($sql);
$check("Extracted start_date and end_date", $params === ['start_date', 'end_date']);

// Multiple occurrences of same parameter
$sqlDup = "SELECT * FROM orders WHERE buyer_id = :user_id OR seller_id = :user_id";
$paramsDup = QueryParameters::extract($sqlDup);
$check("Duplicates deduplicated in extract", $paramsDup === ['user_id']);

// Single and double quotes ignored
$sqlQuotes = "SELECT ':ignored', \":also_ignored\", id FROM items WHERE category = :cat";
$paramsQuotes = QueryParameters::extract($sqlQuotes);
$check("Strings ignored, :cat found", $paramsQuotes === ['cat']);

// Comments ignored (-- and # and /* */)
$sqlComments = "
-- :comment1
# :comment2
/* multiline :comment3 */
SELECT * FROM sales WHERE amount > :min_amount
";
$paramsComments = QueryParameters::extract($sqlComments);
$check("Comments ignored, :min_amount found", $paramsComments === ['min_amount']);

// PostgreSQL ::type casts ignored
$sqlPg = "SELECT id::integer, date::date FROM tbl WHERE status = :status";
$paramsPg = QueryParameters::extract($sqlPg);
$check("PostgreSQL ::casts ignored, :status found", $paramsPg === ['status']);

// PostgreSQL $$ dollar quotes ignored
$sqlDollar = "SELECT $$ :ignored in dollar quote $$ FROM tbl WHERE x = :val";
$paramsDollar = QueryParameters::extract($sqlDollar);
$check("PostgreSQL dollar quotes ignored, :val found", $paramsDollar === ['val']);


echo "--- 2. QueryParameters::normalizeSchema ---\n";

// Auto-schema from SQL
$schemaAuto = QueryParameters::normalizeSchema(null, "SELECT * FROM t WHERE a = :alpha AND b = :beta");
$check("Auto-schema created both params", isset($schemaAuto['alpha']) && isset($schemaAuto['beta']));
$check("Auto-schema defaults string + required", $schemaAuto['alpha']['type'] === 'string' && $schemaAuto['alpha']['required'] === true);

// Merge custom schema
$customJson = json_encode([
    'start_time' => ['type' => 'date', 'required' => true, 'description' => 'From date'],
    'end_time' => ['type' => 'date', 'required' => false, 'default' => 'today', 'description' => 'To date'],
    'removed_param' => ['type' => 'int']
]);
$merged = QueryParameters::normalizeSchema($customJson, "SELECT * FROM logs WHERE ts >= :start_time AND ts <= :end_time");
$check("removed_param was pruned since not in SQL", count($merged) === 2);
$check("start_time preserved", $merged['start_time']['type'] === 'date' && $merged['start_time']['required'] === true);
$check("end_time default today preserved", $merged['end_time']['default'] === 'today' && $merged['end_time']['required'] === false);


echo "--- 3. QueryParameters::resolveClientParams ---\n";

// Missing required throws
$threwMissing = false;
try {
    QueryParameters::resolveClientParams($merged, ['end_time' => '2026-10-24']);
} catch (QueryParameterException $e) {
    $threwMissing = ($e->getErrorCode() === 'missing_parameter');
}
$check("Missing required throws missing_parameter", $threwMissing);

// Invalid type (date) throws
$threwInvalid = false;
try {
    QueryParameters::resolveClientParams($merged, ['start_time' => 'not-a-date']);
} catch (QueryParameterException $e) {
    $threwInvalid = ($e->getErrorCode() === 'invalid_parameter');
}
$check("Invalid date format throws invalid_parameter", $threwInvalid);

// Valid resolution with dynamic default
$resolved = QueryParameters::resolveClientParams($merged, ['start_time' => '2026-01-01']);
$check("start_time resolved", ($resolved['start_time'] ?? null) === '2026-01-01');
$check("end_time defaulted dynamically to today", ($resolved['end_time'] ?? null) === date('Y-m-d'));

// Type conversions: int, float, bool
$typedSchema = [
    'p_int' => ['type' => 'int', 'required' => true, 'default' => null],
    'p_float' => ['type' => 'float', 'required' => true, 'default' => null],
    'p_bool' => ['type' => 'bool', 'required' => true, 'default' => null],
    'p_opt' => ['type' => 'string', 'required' => false, 'default' => null],
];
$typedResolved = QueryParameters::resolveClientParams($typedSchema, [
    'p_int' => '123',
    'p_float' => '45.67',
    'p_bool' => 'true'
]);
$check("int converted to integer", $typedResolved['p_int'] === 123);
$check("float converted to float", $typedResolved['p_float'] === 45.67);
$check("bool converted to 1", $typedResolved['p_bool'] === 1);
$check("optional param resolved to null", $typedResolved['p_opt'] === null);


echo "--- 4. QueryParameters::prepare SQL placeholders ---\n";

// MySQL / SQL Server ? conversion
$prepMysql = QueryParameters::prepare(
    "SELECT * FROM items WHERE owner = :owner AND (created_at >= :since OR updated_at >= :since)",
    ['owner' => 'alice', 'since' => '2026-01-01'],
    'mysql'
);
$check("Replaced with ? in order", $prepMysql['sql'] === "SELECT * FROM items WHERE owner = ? AND (created_at >= ? OR updated_at >= ?)");
$check("Positional parameters duplicated in order of appearance", $prepMysql['params'] === ['alice', '2026-01-01', '2026-01-01']);

// PostgreSQL $1, $2 conversion
$prepPg = QueryParameters::prepare(
    "SELECT * FROM items WHERE owner = :owner AND (created_at >= :since OR updated_at >= :since)",
    ['owner' => 'alice', 'since' => '2026-01-01'],
    'pgsql'
);
$check("PostgreSQL uses indexed $1, $2", $prepPg['sql'] === "SELECT * FROM items WHERE owner = $1 AND (created_at >= $2 OR updated_at >= $2)");
$check("PostgreSQL positional params distinct by index", $prepPg['params'] === ['alice', '2026-01-01']);


echo "--- 5. QueryParameters::hash deterministic cache isolation ---\n";

$h1 = QueryParameters::hash(['a' => 1, 'b' => 'hello']);
$h2 = QueryParameters::hash(['b' => 'hello', 'a' => 1]);
$h3 = QueryParameters::hash(['a' => 2, 'b' => 'hello']);
$check("Key order independent deterministic hash", $h1 === $h2);
$check("Different values yield different hash", $h1 !== $h3);
$check("SHA-256 hash length is 64 hex chars", strlen($h1) === 64);


echo "--- 6. Target Driver execution with bound parameters ---\n";

$targetConn = [
    'db_type' => 'mysql',
    'host' => '127.0.0.1',
    'port' => null,
    'db_name' => 'mysql',
    'username' => 'root',
    'password_encrypted' => \Services\Encryption::encrypt(''),
];

$driver = \Db\ConnectionFactory::fromConnectionRow($targetConn, 5);
$stmt = $driver->query("SELECT ? AS greeting, ? AS num, ? AS null_val", ['hello world', 42, null]);
$rows = [];
while ($row = $driver->fetchRow($stmt)) {
    $rows[] = $row;
}
$driver->free($stmt);
$check("Got 1 row from parameterized MySQL driver query", count($rows) === 1);
$check("greeting bound correctly", ($rows[0]['greeting'] ?? null) === 'hello world');
$check("num bound correctly", (int)($rows[0]['num'] ?? 0) === 42);
$check("null bound correctly", array_key_exists('null_val', $rows[0]) && $rows[0]['null_val'] === null);

// QueryRunner end-to-end with named parameters
$runnerRows = [];
$runnerResult = \Services\QueryRunner::run(
    $targetConn,
    "SELECT :text_val AS msg, :calc AS calc_val",
    5,
    10,
    function (array $r) use (&$runnerRows) {
        $runnerRows[] = $r;
    },
    ['text_val' => 'antigravity', 'calc' => 100]
);
$check("QueryRunner executed ok with named params", $runnerResult['ok'] === true);
$check("QueryRunner row count 1", $runnerResult['row_count'] === 1);
$check("QueryRunner bound named parameter :text_val", ($runnerRows[0]['msg'] ?? null) === 'antigravity');
$check("QueryRunner bound named parameter :calc", (int)($runnerRows[0]['calc_val'] ?? 0) === 100);

echo $failures === 0 ? "=== PARAMETERS TEST: ALL CHECKS PASSED ===\n" : "=== PARAMETERS TEST: {$failures} FAILURES ===\n";
exit($failures === 0 ? 0 : 1);
