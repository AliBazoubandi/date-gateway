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
 * Quoted identifiers are the escape hatch for columns whose names collide
 * with a blocked keyword: SELECT `set` FROM params is fine.
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

    /** @throws \RuntimeException when the SQL is not provably read-only. */
    public static function assertReadOnly(string $sql): void
    {
        $stripped = self::strip(self::stripComments($sql));
        if (trim($stripped) === '') {
            throw new \RuntimeException('The SQL is empty once comments are removed.');
        }
        // Exactly one statement: a semicolon may only appear as the last
        // non-space character of the whole text.
        $semi = strpos($stripped, ';');
        if ($semi !== false) {
            if (trim(substr($stripped, $semi + 1)) !== '') {
                throw new \RuntimeException('Multiple statements in one request are not allowed.');
            }
            $stripped = substr($stripped, 0, $semi);
        }
        if (preg_match('/^\s*(?:SELECT|WITH)\b/i', $stripped) !== 1) {
            throw new \RuntimeException('Only a single read-only SELECT statement is allowed.');
        }
        if (preg_match(self::BLOCKED, $stripped, $m) === 1) {
            throw new \RuntimeException("Disallowed keyword in read-only SQL: {$m[0]}");
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
