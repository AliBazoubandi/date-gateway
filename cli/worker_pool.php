<?php
// ============================================================================
// Data Gateway - worker pool   (run: php cli\worker_pool.php)
//
// The scheduled entry point for delayed executions (P1-1). One pass used to
// run every connection's work sequentially, so a 30-minute query on connection
// A blocked connection B's due executions for its whole duration and could make
// the pass outlive its own 1-minute schedule.
//
// This parent does the global housekeeping once, then spawns
// `php cli\worker.php --connection=ID` for each active target connection, up to
// worker.pool_max_children at a time, waits for all of them, and drains the
// webhook queue once. Each child holds its own per-connection lock, so children
// on different connections genuinely overlap; a slow connection only queues
// behind itself. At the end of the pass it records the worker_state heartbeat
// that GET /health reads.
//
// Exit codes: 0 = pass completed (children may individually report busy/skip),
//             1 = the pool crashed or a child exited non-zero.
// Another pool instance holding the lock is NORMAL (0, quiet) for schedulers.
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';

set_time_limit(0);
ini_set('max_execution_time', '0');

$tag = '[pool]';

// --- fence 1: file lock (same machine) ---------------------------------------
$lockFile = Core\Filesystem::storageDir() . '/worker_pool.lock';
$fh = @fopen($lockFile, 'c');
if ($fh === false) {
    fwrite(STDERR, "{$tag} cannot open lock file: {$lockFile}" . PHP_EOL);
    exit(1);
}
if (!@flock($fh, LOCK_EX | LOCK_NB)) {
    echo $tag, ' another pool pass is already running; skipping.', PHP_EOL;
    fclose($fh);
    exit(0);
}

$released = static function () use ($fh): void {
    @flock($fh, LOCK_UN);
    @fclose($fh);
};

// --- fence 2: metadata DB named lock (also across separate hosts) ------------
try {
    if (!db()->lock('gw_cli_worker_pool', 0)) {
        echo $tag, ' metadata DB pool lock is busy; skipping.', PHP_EOL;
        $released();
        exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $tag . ' cannot take the metadata DB lock: ' . $e->getMessage() . PHP_EOL);
    $released();
    exit(1);
}

$workerScript = __DIR__ . '/worker.php';
$maxChildren = max(1, (int)Core\Config::get('worker.pool_max_children', 8));
$failures = 0;
// The heartbeat GET /health reads: this pass's start, recorded on every exit
// path of the pass below. It is written by explicit calls rather than a finally
// block because PHP does NOT run finally when a try calls exit() - and this
// script ends its passes with exit. Losing the heartbeat must never change what
// the pass reports: the executions are already done, and a monitoring
// convenience is not a reason to fail the schedule.
$passStarted = Core\Time::now();
$recordHeartbeat = static function () use ($passStarted, &$failures, $tag): void {
    try {
        \Services\WorkerState::record($passStarted, $failures);
    } catch (\Throwable $e) {
        fwrite(STDERR, $tag . ' cannot record the health heartbeat: ' . $e->getMessage() . PHP_EOL);
    }
};
$ids = [];

try {
    // Global sweeps once per pass (the children skip them).
    $swept = \Worker\Worker::housekeeping();
    echo $tag, ' stale-cleaned=', $swept['cleaned_stale'],
        ' inactive-cleaned=', $swept['cleaned_inactive'], PHP_EOL;

    $ids = \Worker\Worker::activeConnectionIds();
    if ($ids === []) {
        echo $tag, ' no active connections.', PHP_EOL;
    }

    /** @var array<int,array{proc:resource,pipes:array<int,resource>,out:string,id:int}> $running */
    $running = [];
    $queue = $ids;
    $spawn = static function (int $id) use ($workerScript, $tag): ?array {
        $cmd = [PHP_BINARY, $workerScript, '--connection=' . $id];
        $pipes = [];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, []);
        if (!is_resource($proc)) {
            fwrite(STDERR, $tag . " cannot spawn worker for connection {$id}" . PHP_EOL);
            return null;
        }
        // Non-blocking reads: a child that prints more than the pipe buffer
        // must never wedge the parent's wait loop.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'id' => $id];
    };

    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < $maxChildren) {
            $id = array_shift($queue);
            $child = $spawn($id);
            if ($child === null) {
                $failures++;
                continue;
            }
            $running[$id] = $child;
        }

        usleep(100000); // 100 ms poll: cheap, and children run for seconds
        $done = [];
        foreach ($running as $id => $c) {
            $c['out'] .= (string)stream_get_contents($c['pipes'][1]);
            $c['out'] .= (string)stream_get_contents($c['pipes'][2]);
            if (proc_get_status($c['proc'])['running']) {
                $running[$id] = $c;
                continue;
            }
            // Exited: drain whatever is left in the pipes before closing them.
            $c['out'] .= (string)stream_get_contents($c['pipes'][1]);
            $c['out'] .= (string)stream_get_contents($c['pipes'][2]);
            foreach ($c['pipes'] as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            $exit = proc_close($c['proc']);
            $text = rtrim($c['out']);
            if ($text !== '') {
                echo $text, PHP_EOL;
            }
            $done[] = $id;
            if ($exit !== 0) {
                $failures++;
                fwrite(STDERR, $tag . " connection {$id} worker exited {$exit}" . PHP_EOL);
            }
        }
        foreach ($done as $id) {
            unset($running[$id]);
        }
    }

    // Drain webhooks once for the whole pass, under the shared fence
    // (mutual exclusion with cli/webhooks.php). A delivery problem must not
    // fail the pass: the executions already succeeded.
    try {
        if (!db()->lock('gw_cli_webhooks', 0)) {
            echo $tag, ' webhook delivery is busy elsewhere; skipping.', PHP_EOL;
        } else {
            $s = \Services\WebhookDispatcher::deliverDue();
            echo $tag, " webhooks sent={$s['sent']} failed={$s['failed']}",
                " exhausted={$s['exhausted']} skipped={$s['skipped']}", PHP_EOL;
            db()->unlock('gw_cli_webhooks');
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, $tag . ' webhook delivery failed (will retry next pass): '
            . $e->getMessage() . PHP_EOL);
        try {
            db()->unlock('gw_cli_webhooks');
        } catch (\Throwable) {
        }
    }

    echo $tag, ' connections=', count($ids), ' max_children=', $maxChildren, PHP_EOL;
    $recordHeartbeat();
    if ($failures > 0) {
        fwrite(STDERR, $tag . " {$failures} worker failure(s)" . PHP_EOL);
        exit(1);
    }
    exit(0);
} catch (\Throwable $e) {
    $failures = max(1, $failures);
    fwrite(STDERR, $tag . ' FAILED: ' . $e->getMessage() . PHP_EOL);
    foreach ($running ?? [] as $c) {
        foreach ($c['pipes'] as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        @proc_terminate($c['proc']);
    }
    try {
        db()->unlock('gw_cli_worker_pool');
    } catch (\Throwable) {
    }
    $recordHeartbeat();
    $released();
    exit(1);
}
