<?php
// Unit test for Services\SqlGuard read-only scanner (P1-6).

require __DIR__ . '/../src/Core/bootstrap.php';

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};
$allowed = static function (string $label, string $sql, string $dialect = 'mysql') use ($check): void {
    try {
        \Services\SqlGuard::assertReadOnly($sql, $dialect);
        $check($label, true);
    } catch (\RuntimeException $e) {
        $check($label, false, 'unexpectedly rejected: ' . $e->getMessage());
    }
};
$blocked = static function (string $label, string $sql, string $dialect = 'mysql') use ($check): void {
    try {
        \Services\SqlGuard::assertReadOnly($sql, $dialect);
        $check($label, false, 'unexpectedly allowed');
    } catch (\RuntimeException $e) {
        $check($label, true, $e->getMessage());
    }
};

// ---- blocked: MySQL/MariaDB executable comments (never a read-only need) ----
// The server EXECUTES the body of /*! ... */ and /*M! ... */, so stripping it
// as an ordinary comment let `SELECT 1 /*!50000 INTO OUTFILE ... */` pass the
// scanner as a bare SELECT. The check must reject, not interpret.
$blocked('executable comment (mysql) hides INTO OUTFILE', "SELECT 1 /*!50000 INTO OUTFILE '/tmp/x' */");
$blocked('executable comment (mysql) hides a second statement', "SELECT 1 /*!50000 ; DROP TABLE x */");
$blocked('executable comment (mariadb M!) hides a second statement', "SELECT 1 /*M!100000 ; DROP TABLE x */");
$blocked('executable comment (mariadb M!, lower case)', "SELECT 1 /*m!100000 ; DROP TABLE x */");
$blocked('executable comment inside a WHERE clause', "SELECT a FROM t WHERE b = 1 /*!50000 OR 1=1 */");
$blocked('executable comment hides a write after the SHOW allowlist',
    'SHOW TABLES /*!50000 ; DROP TABLE x */');
$blocked('executable comment behind an EXPLAIN prefix', "EXPLAIN SELECT 1 /*!50000 ; DROP TABLE x */");

// ---- dialect: the same bytes mean different things per server ----
// MySQL only starts a -- comment when the second dash is followed by
// whitespace: `SELECT 1 --x; DROP TABLE t` is 1 - (-x) and a real second
// statement, so the scanner must see it.
$blocked('mysql -- without whitespace is not a comment', 'SELECT 1 --x; DROP TABLE t');
// PostgreSQL arrays use [ ], not SQL Server identifier quoting.
$allowed('pgsql: array subscript survives the scan', "SELECT data[1] FROM t", 'pgsql');
// SQL Server: [...] is an identifier, so a blocked keyword inside is inert.
$allowed('sqlserver: bracketed identifier', 'SELECT [delete], [update] FROM t', 'sqlserver');
// Strings are opaque in every dialect, whatever the comment markers inside.
$allowed('string holding a block-comment marker', "SELECT '/* not a comment */' AS x");
$allowed('string holding a line-comment marker', "SELECT '-- not a comment' AS x");
$allowed('string holding a semicolon', "SELECT 'a; b' AS x");
$allowed('string holding both comment markers', "SELECT '/* a */ -- b' AS x");
// PostgreSQL dollar-quoted bodies are strings too.
$allowed('pgsql: dollar-quoted body with a semicolon', 'SELECT $$a;b$$ AS x', 'pgsql');
$allowed('pgsql: tagged dollar-quoted body', 'SELECT $tag$ a;b $tag$ AS x', 'pgsql');
$blocked('pgsql: dollar-quoted body cannot hide a second statement',
    'SELECT $$x$$; DROP TABLE x', 'pgsql');
// PostgreSQL's #> operator is not a comment.
$allowed('pgsql: #> operator is not a comment', "SELECT data #> '{a}' FROM t", 'pgsql');
$allowed('pgsql: #>> operator is not a comment', "SELECT data #>> '{a,b}' FROM t", 'pgsql');
// MySQL's # comment is.
$allowed('mysql: # trailing comment', 'SELECT 1 # trailing comment');
$allowed('mysql: ## and a newline', "# note\nSELECT 1 ## another\n");
// An unknown dialect falls back to the documented conservative default.
$allowed('unknown dialect falls back to the default scan', 'SELECT 1');

// ---- allowed: plain selects and their read-only decorations ----
$allowed('simple select', 'SELECT 1 AS ping');
$allowed('join + order/limit', 'SELECT a.id, b.name FROM a JOIN b ON b.aid = a.id WHERE a.x > 10 ORDER BY b.name LIMIT 50 OFFSET 10');
$allowed('union', 'SELECT 1 UNION ALL SELECT 2');
$allowed('subquery', 'SELECT * FROM (SELECT id FROM t WHERE flag = 1) s');
$allowed('with-cte', 'WITH a AS (SELECT 1 AS n) SELECT * FROM a');
$allowed('case/when', "SELECT CASE WHEN x > 1 THEN 'big' ELSE 'small' END AS s FROM t");
$allowed('trailing semicolon', 'SELECT 1;');
$allowed('comments around', "-- leading comment\nSELECT 1 /* inline */ -- trailing\n");
$allowed('hash comment (mysql)', "# note\nSELECT 1");
$allowed('keyword inside string', "SELECT 'DROP TABLE users; DELETE FROM x' AS s");
$allowed('semicolon inside string', "SELECT 'a;b' AS s");
$allowed('doubled-quote string', "SELECT 'it''s ok' AS s");
$allowed('backslash-escaped string', "SELECT 'it\\'s ok' AS s");
$allowed('quoted keyword identifiers', 'SELECT `set`, [update], "delete" FROM t');
$allowed('window function', 'SELECT id, ROW_NUMBER() OVER (PARTITION BY g ORDER BY ts DESC) AS rn FROM t');

// ---- allowed: read-only introspection statements (P1-6) ----
$allowed('show tables', 'SHOW TABLES');
$allowed('show full columns', 'SHOW FULL COLUMNS FROM t');
$allowed('show create table', 'SHOW CREATE TABLE t');
$allowed("show variables like", "SHOW VARIABLES LIKE '%timeout%'");
$allowed('explain select', 'EXPLAIN SELECT * FROM t WHERE id = 1');
$allowed('explain with cte', 'EXPLAIN WITH a AS (SELECT 1 AS n) SELECT * FROM a');
$allowed('explain format=json', 'EXPLAIN FORMAT=JSON SELECT 1');
$allowed('explain pg options', 'EXPLAIN (VERBOSE) SELECT 1');
$allowed('describe table', 'DESCRIBE t');
$allowed('desc table', 'DESC t');
$allowed('desc qualified table', 'DESC mydb.mytable');
$allowed('describe select', 'DESCRIBE SELECT 1');

// ---- blocked: anything that is not a single read-only statement ----
$blocked('drop table', 'DROP TABLE users');
$blocked('delete', 'DELETE FROM t WHERE id = 1');
$blocked('update', 'UPDATE t SET x = 1');
$blocked('insert', 'INSERT INTO t (a) VALUES (1)');
$blocked('multi-statement drop', 'SELECT 1; DROP TABLE users');
$blocked('multi-statement select', 'SELECT 1; SELECT 2');
$blocked('select into outfile', "SELECT * FROM t INTO OUTFILE '/tmp/x.txt'");
$blocked('select into var', 'SELECT 1 INTO @x');
$blocked('for update', 'SELECT * FROM t FOR UPDATE');
$blocked('cte hiding delete', 'WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x');
$blocked('grant', 'GRANT SELECT ON db.* TO u');
$blocked('execute proc', 'EXEC sp_configure');
$blocked('call proc', 'CALL do_something()');
$blocked('set session', "SET SESSION sql_mode = ''");
$blocked('create view', 'CREATE VIEW v AS SELECT 1');
$blocked('alter table', 'ALTER TABLE t ADD COLUMN x INT');
$blocked('truncate', 'TRUNCATE TABLE t');
$blocked('comment-only', '-- nothing here');
$blocked('empty', '   ');
$blocked('keyword as bare identifier', 'SELECT set FROM params');

// ---- blocked: write statements hidden behind introspection prefixes ----
$blocked('explain analyze executes', 'EXPLAIN ANALYZE SELECT 1');
$blocked('explain pg analyze option', 'EXPLAIN (ANALYZE, BUFFERS) SELECT 1');
$blocked('explain delete', 'EXPLAIN DELETE FROM t');
$blocked('explain update', 'EXPLAIN UPDATE t SET x = 1');
$blocked('explain insert', 'EXPLAIN INSERT INTO t (a) VALUES (1)');
$blocked('explain drop', 'EXPLAIN DROP TABLE t');
$blocked('describe delete', 'DESCRIBE DELETE FROM t');
$blocked('explain empty body', 'EXPLAIN');

// ---- blocked: other server-side side-effect statements ----
$blocked('vacuum', 'VACUUM ANALYZE t');
$blocked('refresh materialized view', 'REFRESH MATERIALIZED VIEW mv');
$blocked('cluster', 'CLUSTER t USING idx');
$blocked('comment on', "COMMENT ON TABLE t IS 'x'");
$blocked('copy to file', "COPY t TO '/tmp/x.csv'");
$blocked('create table as select', 'CREATE TABLE x AS SELECT 1');
$blocked('listen', 'LISTEN chan');
$blocked('discard all', 'DISCARD ALL');
$blocked('lock table', 'LOCK TABLE t IN ACCESS EXCLUSIVE MODE');
$blocked('do block', "DO \$\$ BEGIN END \$\$");

echo $failures === 0 ? '=== SQLGUARD TEST PASS ===' : "=== SQLGUARD TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
