<?php
declare(strict_types=1);
namespace Services;

/**
 * Read-only SQL scanner. The gateway's security model rests on "pre-approved
 * SQL that only reads": sql_text is saved once by an admin and later executed
 * with the target connection's credentials whenever any permitted client
 * submits the code — clients can never send their own SQL. That holds only
 * if the stored text is provably read-only, so every execution path funnels
 * through this gate (QueryRunner), and the admin save path validates too.
 *
 * It is a scanner, not a full parser: comments and all quoted material
 * (string literals and quoted identifiers, so a column named `update` or a
 * literal containing 'DROP TABLE' does not trip it) are stripped first;
 * what remains must be a single statement starting with SELECT (or a WITH
 * ... SELECT CTE chain) containing no write/DDL/session keyword.
 *
 * Read-only metadata statements are allowed too: SHOW, and EXPLAIN /
 * DESCRIBE / DESC of an inner statement. `EXPLAIN ANALYZE` is rejected
 * outright because on MySQL 8+ and PostgreSQL it really executes the wrapped
 * statement; every other EXPLAIN body is re-scanned recursively, so
 * `EXPLAIN DELETE FROM t` cannot pass.
 *
 * Quoted identifiers are the escape hatch for columns whose names collide
 * with a blocked keyword: SELECT `set` FROM params is fine.
 *
 * The scanner is the first line only: the MySQL and PostgreSQL drivers also
 * run every query inside a READ ONLY transaction, so a bypass still cannot
 * write. SQL Server has no equivalent session guard - its target login must
 * be read-only (see README).
 */
final class SqlGuard
{
    /**
     * Keywords that can mutate data, schema, or session state, or invoke
     * code. Matched with word boundaries on the stripped SQL, so
     * "start_date" or "updated" never match. Transaction words (BEGIN,
     * COMMIT, ...) are intentionally absent: they cannot start a statement
     * here and a trailing one is caught by the single-statement rule.
     */
    private const BLOCKED = '/\b(?:'
        . 'INSERT|REPLACE|UPDATE|DELETE|MERGE|UPSERT|TRUNCATE|'
        . 'DROP|ALTER|CREATE|RENAME|COMMENT|'
        . 'GRANT|REVOKE|DENY|'
        . 'EXEC|EXECUTE|CALL|DO|PREPARE|DEALLOCATE|'
        . 'SET|RESET|USE|'
        . 'LOCK|UNLOCK|HANDLER|'
        . 'LOAD|OUTFILE|DUMPFILE|INTO|'
        . 'INSTALL|UNINSTALL|SHUTDOWN'
        . ')\b/i';

    /** Statement-level read-only introspection prefixes (never write). */
    private const LEAD_RE = '/^(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i';

    /** @throws \RuntimeException when the SQL is not provably read-only. */
    public static function assertReadOnly(string $sql): void
    {
        $stripped = self::strip(self::stripComments($sql));
        if (trim($stripped) === '') {
            throw new SqlRejectedException('The SQL is empty once comments are removed.');
        }
        // Exactly one statement: a semicolon may only appear as the last
        // non-space character of the whole text.
        $semi = strpos($stripped, ';');
        if ($semi !== false) {
            if (trim(substr($stripped, $semi + 1)) !== '') {
                throw new SqlRejectedException('Multiple statements in one request are not allowed.');
            }
            $stripped = substr($stripped, 0, $semi);
        }
        $stripped = trim($stripped);
        if (preg_match(self::LEAD_RE, $stripped, $m) !== 1) {
            throw new SqlRejectedException(
                'Only a single read-only statement (SELECT, WITH, SHOW, EXPLAIN, DESCRIBE) is allowed.'
            );
        }
        $lead = strtoupper($m[1]);
        $body = trim(substr($stripped, strlen($lead)));

        if ($lead === 'SHOW') {
            // SHOW takes an object/option list, never a statement: it cannot
            // write. No inner scan needed.
            return;
        }
        if ($lead === 'EXPLAIN' || $lead === 'DESCRIBE' || $lead === 'DESC') {
            self::assertExplainBody($body);
            return;
        }
        self::assertNoWriteKeyword($stripped);
    }

    /**
     * EXPLAIN / DESCRIBE / DESC: MySQL and PostgreSQL run the wrapped
     * statement's planner, so the inner statement must itself be read-only.
     * EXPLAIN ANALYZE is the exception - it executes for real.
     *
     * @throws \RuntimeException when the inner statement is not read-only
     */
    private static function assertExplainBody(string $body): void
    {
        if ($body === '') {
            throw new SqlRejectedException('EXPLAIN/DESCRIBE needs a statement or table name.');
        }
        if (preg_match('/\bANALYZE\b/i', $body) === 1) {
            throw new SqlRejectedException(
                'EXPLAIN ANALYZE is not allowed: it executes the wrapped statement.'
            );
        }
        // DESCRIBE/DESC of a bare table name (optionally with a column).
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $body) === 1) {
            return;
        }
        // MySQL "FORMAT=JSON|TREE" and PostgreSQL "EXPLAIN (VERBOSE, ...)"
        // option prefixes, then re-scan what remains as its own statement.
        $inner = preg_replace('/^(\([^)]*\)|FORMAT\s*=\s*[A-Z]+|FORMAT\s+[A-Z]+)\s*/i', '', $body) ?? $body;
        self::assertReadOnly((string)$inner);
    }

    private static function assertNoWriteKeyword(string $sql): void
    {
        if (preg_match(self::BLOCKED, $sql, $m) === 1) {
            throw new SqlRejectedException("Disallowed keyword in read-only SQL: {$m[0]}");
        }
    }

    /** Remove line/block comments (MySQL/MariaDB, SQL Server, Postgres). */
    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql; // /* ... */
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;     // -- ...
        $sql = preg_replace('/#[^\n]*/', ' ', $sql) ?? $sql;      // # ... (MySQL)
        return $sql;
    }

    /** Remove quoted material: string literals and quoted identifiers. */
    private static function strip(string $sql): string
    {
        $patterns = [
            "/'(?:[^'\\\\]|\\\\.|'')*'/", // 'string' with '' doubling or backslash escapes
            '/"(?:[^"]|"")*"/',           // "identifier" (pgsql/sqlsrv) or "string"
            '/`[^`]*`/',                  // `identifier` (mysql)
            '/\[[^\]]*\]/',              // [identifier] (sqlsrv)
        ];
        return preg_replace($patterns, ' ', $sql) ?? $sql;
    }
}
