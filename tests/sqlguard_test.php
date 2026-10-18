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
$allowed = static function (string $label, string $sql) use ($check): void {
    try {
        \Services\SqlGuard::assertReadOnly($sql);
        $check($label, true);
    } catch (\RuntimeException $e) {
        $check($label, false, 'unexpectedly rejected: ' . $e->getMessage());
    }
};
$blocked = static function (string $label, string $sql) use ($check): void {
    try {
        \Services\SqlGuard::assertReadOnly($sql);
        $check($label, false, 'unexpectedly allowed');
    } catch (\RuntimeException $e) {
        $check($label, true, $e->getMessage());
    }
};

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

// ---- blocked: anything that is not a single read-only select ----
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

echo $failures === 0 ? '=== SQLGUARD TEST PASS ===' : "=== SQLGUARD TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
