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
 * It is a scanner, not a full parser: one character pass replaces comments
 * and every quotable region (string literals, quoted identifiers, PostgreSQL
 * dollar-quoted bodies) with a space; what remains must be a single statement
 * starting with SELECT (or a WITH ... SELECT CTE chain) containing no
 * write/DDL/session keyword.
 *
 * The pass is dialect-aware because the same bytes mean different things on
 * different servers:
 *   - `#` starts a comment on MySQL/MariaDB only; on PostgreSQL it is part of
 *     the `#>` / `#>>` / `#-` operators, so treating it as a comment would
 *     truncate a legal query before the check.
 *   - `--` starts a comment everywhere, but MySQL only when the second dash is
 *     followed by whitespace/control (or ends the text). `SELECT 1 --x; DROP
 *     TABLE t` is NOT a comment on MySQL — it is `1 - (-x)` and then a second
 *     statement, and the scanner must see that semicolon.
 *   - Backslash escapes inside '...' are MySQL/MariaDB behaviour; on
 *     PostgreSQL `standard_conforming_strings` is on, so a backslash is a
 *     literal backslash and the string can end right after it.
 *   - Backtick identifiers are MySQL's, `[...]` identifiers SQL Server's, and
 *     `$tag$...$tag$` bodies PostgreSQL's.
 *
 * MySQL/MariaDB *executable* comments — a block comment whose first character
 * is `!` on MySQL, or `M!` on MariaDB — are rejected outright, not stripped:
 * the server executes what they contain, so stripping one would turn
 * `SELECT 1` + an executable comment holding `INTO OUTFILE` into a plain
 * `SELECT 1` for the scanner while the server still writes the file.
 * No read-only query needs them.
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

    /** Dialect names the gateway uses (connections.db_type) and their aliases. */
    private const DIALECTS = [
        'mysql' => 'mysql',
        'mariadb' => 'mysql',
        'pgsql' => 'pgsql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlserver' => 'sqlserver',
        'sqlsrv' => 'sqlserver',
    ];

    /**
     * @param string $dialect target server the SQL will run on: mysql,
     *        pgsql or sqlserver (connections.db_type; aliases accepted).
     *        Defaults to mysql — the metadata DB dialect, and the only one
     *        the gateway can assume when a caller cannot know.
     * @throws \RuntimeException when the SQL is not provably read-only
     */
    public static function assertReadOnly(string $sql, string $dialect = 'mysql'): void
    {
        $dialect = self::normalizeDialect($dialect);
        $stripped = self::strip($sql, $dialect);
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
            self::assertExplainBody($body, $dialect);
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
    private static function assertExplainBody(string $body, string $dialect): void
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
        self::assertReadOnly((string)$inner, $dialect);
    }

    private static function assertNoWriteKeyword(string $sql): void
    {
        if (preg_match(self::BLOCKED, $sql, $m) === 1) {
            throw new SqlRejectedException("Disallowed keyword in read-only SQL: {$m[0]}");
        }
    }

    private static function normalizeDialect(string $dialect): string
    {
        return self::DIALECTS[strtolower(trim($dialect))] ?? 'mysql';
    }

    /**
     * The single character pass: walk the SQL once and return a "skeleton" in
     * which every comment and every quoted region is replaced by one space.
     *
     * One pass is the point: the previous two-regex version stripped comments
     * BEFORE strings, so a quoted literal holding a comment marker lost its
     * content and a `--` inside a string could hide the rest of the statement.
     * Here the first thing the scan meets decides how the bytes are consumed.
     *
     * @throws SqlRejectedException on an executable MySQL/MariaDB comment
     */
    private static function strip(string $sql, string $dialect): string
    {
        $len = strlen($sql);
        $out = '';
        $i = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($ch === '/' && $next === '*') {
                $third = $i + 2 < $len ? $sql[$i + 2] : '';
                $fourth = $i + 3 < $len ? $sql[$i + 3] : '';
                // /*! ... */ is executed by MySQL, /*M! ... */ by MariaDB.
                // There is no read-only use for either: refuse, do not guess.
                if ($third === '!' || (($third === 'M' || $third === 'm') && $fourth === '!')) {
                    throw new SqlRejectedException(
                        'MySQL/MariaDB executable comments (/*! ... */ or /*M! ... */) are not allowed in read-only SQL.'
                    );
                }
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $out .= ' ';
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $third = $i + 2 < $len ? $sql[$i + 2] : '';
                // MySQL needs whitespace/control (or the end of the text) after
                // the second dash; otherwise "--" is two minus operators and
                // what follows is really executed SQL.
                $isComment = $dialect !== 'mysql'
                    || $third === ''
                    || ord($third) <= 0x20
                    || ord($third) === 0x7F;
                if ($isComment) {
                    $nl = strpos($sql, "\n", $i + 2);
                    $i = $nl === false ? $len : $nl + 1;
                    $out .= ' ';
                    continue;
                }
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === '#' && $dialect === 'mysql') {
                // MySQL/MariaDB line comment. PostgreSQL's #> / #>> / #- must
                // survive untouched, so this rule is MySQL-only.
                $nl = strpos($sql, "\n", $i + 1);
                $i = $nl === false ? $len : $nl + 1;
                $out .= ' ';
                continue;
            }

            if ($ch === "'") {
                $out .= ' ';
                // Backslash escapes are MySQL/MariaDB's; PostgreSQL treats a
                // backslash literally (standard_conforming_strings), and SQL
                // Server has no backslash escape at all.
                $i = self::skipQuoted($sql, $i, "'", $dialect === 'mysql');
                continue;
            }

            if ($ch === '"') {
                $out .= ' ';
                $i = self::skipQuoted($sql, $i, '"', false);
                continue;
            }

            if ($ch === '`' && $dialect !== 'pgsql') {
                $out .= ' ';
                $i = self::skipQuoted($sql, $i, '`', false);
                continue;
            }

            if ($ch === '[' && $dialect !== 'pgsql') {
                // SQL Server identifier quoting. Left alone for PostgreSQL,
                // where [ ] is an array subscript, not a quote.
                $out .= ' ';
                $i = self::skipBracketed($sql, $i);
                continue;
            }

            if ($ch === '$' && $dialect === 'pgsql') {
                $tagged = self::dollarQuoteAt($sql, $i);
                if ($tagged !== null) {
                    $close = strpos($sql, $tagged, $i + strlen($tagged));
                    $i = $close === false ? $len : $close + strlen($tagged);
                    $out .= ' ';
                    continue;
                }
            }

            $out .= $ch;
            $i++;
        }
        return $out;
    }

    /**
     * Advance past one quoted region starting at $start. A doubled closing
     * quote ('', "", ``) is an escaped quote, not the end.
     *
     * @return int index just past the closing quote, or the end of the text
     *         when the quote is never closed (an unterminated literal is a
     *         syntax error on every server, so the remainder is inert)
     */
    private static function skipQuoted(string $sql, int $start, string $quote, bool $backslashEscapes): int
    {
        $len = strlen($sql);
        $i = $start + 1;
        while ($i < $len) {
            $ch = $sql[$i];
            if ($backslashEscapes && $ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $i += 2;
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }
        return $len;
    }

    /**
     * Advance past a SQL Server [identifier]. A doubled ]] is an escaped
     * closing bracket, not the end.
     */
    private static function skipBracketed(string $sql, int $start): int
    {
        $len = strlen($sql);
        $i = $start + 1;
        while ($i < $len) {
            if ($sql[$i] === ']') {
                if ($i + 1 < $len && $sql[$i + 1] === ']') {
                    $i += 2;
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }
        return $len;
    }

    /**
     * The PostgreSQL dollar-quote opener ($$ or $tag$) at $pos, or null when
     * that `$` is an ordinary character (a positional parameter, say).
     */
    private static function dollarQuoteAt(string $sql, int $pos): ?string
    {
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $pos), $m) !== 1) {
            return null;
        }
        return $m[0];
    }
}
