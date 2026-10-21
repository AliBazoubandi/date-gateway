<?php
declare(strict_types=1);
namespace Services;

use Core\Config;
use Core\Filesystem;
use Core\Time;

/**
 * Result files are KEPT, not deleted — and the evidence copy is made the
 * moment a run succeeds, not when its TTL later lapses.
 *
 * TTL expiry is an access policy, not a deletion: every download, status and
 * idempotency path refuses an execution whose expires_at has passed, so what
 * happens to the file on disk is invisible through the API. The worker
 * therefore COPIES each freshly published result to
 * storage/archive/{request_code}_{Ymd_His}_client{client_id}_e{execution_id}.json
 * as part of finishing it (copyResult — the live file stays at its
 * downloadable path), and the expiry sweep later MOVES the live twin
 * (storeResult), recognising the ready-time copy by its deterministic name
 * and simply unlinking the duplicate. A run whose ready-time copy failed
 * still gets archived at expiry; nothing ever serves from archive/.
 *
 * The name carries the pieces that identify a run without a database: the
 * request code, the expires_at stamp (the moment that result stops being
 * downloadable — predicted at ready time, actual at expiry), the client the
 * run belongs to and the execution id. The execution id is part of the name
 * and not a collision fallback: two distinct results for the same request
 * can expire in the same second, and rename() replaces an existing target,
 * so "add a suffix only when the name is taken" could silently overwrite one
 * evidence file with another. One execution -> one deterministic name; the
 * rename always lands on a fresh destination and is idempotent (a missing
 * source is simply reported as nothing moved).
 *
 * Downloads announce the same name in their Content-Disposition header
 * (Api\Download::streamResult), so whatever a client saves comes named like
 * the archived twin of what it downloaded.
 *
 * The client id in the name is the call that FIRST triggered the execution
 * (earliest calls row); cache-rule attaches that share the execution use the
 * one file and keep their own calls/tokens. A run with no call attached (a
 * direct fixture or a racing delete) is filed under client0. The download's
 * Content-Disposition name computes the same originating client, so it can
 * never disagree with the archive about whose run it was.
 */
final class ResultArchive
{
    /**
     * THE name of one run's result file, everywhere it travels: the archive
     * copy at ready time and at expiry, and the file name the download itself
     * carries in Content-Disposition — one deterministic name per execution,
     * so the copy a client saved matches the archived evidence byte-wise and
     * name-wise. Used by downloads (Api\Download) and by storeResult/copyResult.
     *
     * @param string $expiredAt the expires_at DATETIME (falls back to now)
     */
    public static function nameFor(
        int $executionId,
        string $requestCode,
        ?int $clientId,
        string $expiredAt
    ): string {
        return self::safeCode($requestCode) . '_' . self::stamp($expiredAt)
            . '_client' . max(0, $clientId ?? 0) . '_e' . max(0, $executionId) . '.json';
    }

    /**
     * Move a published result file into the archive.
     *
     * @param string $relative storage-relative path of the result, exactly the
     *     documented results|cache/name.json shape (the legacy cache/ prefix is
     *     accepted so pre-migration copies are archived like everything else)
     * @param int $executionId the execution the file belongs to
     * @param string $requestCode the requests.code behind the execution
     * @param ?int $clientId client to file the run under; null -> client0
     * @param string $expiredAt the expires_at DATETIME (falls back to now)
     * @return ?string storage-relative archive path ("archive/name.json"),
     *     or null when there was nothing to move (missing source, undocumented
     *     path shape, or a failed move: the file stays where it is and the
     *     next sweep retries)
     */
    public static function storeResult(
        string $relative,
        int $executionId,
        string $requestCode,
        ?int $clientId,
        string $expiredAt
    ): ?string {
        [$source, $dir, $name, $sub] = self::resolve($relative, $executionId, $requestCode, $clientId, $expiredAt);
        if ($source === null) {
            return null;
        }
        $dest = $dir . '/' . $name;
        if (is_file($dest)) {
            // The ready-time copy (copyResult) already holds these exact
            // bytes: the live file is its unmodified twin. Drop the twin
            // instead of renaming over the evidence copy.
            return @unlink($source) ? $sub . '/' . $name : null;
        }
        if (!@rename($source, $dest)) {
            return null;
        }
        return $sub . '/' . $name;
    }

    /**
     * Copy a freshly published result into the archive, keeping the live file
     * in place. The archive entry is made when the run succeeds, not when the
     * TTL later lapses or someone stumbles onto an expired download — the
     * evidence copy exists no matter what the client does next. The name is
     * deterministic (same as storeResult), so the expiry-time move of the
     * live twin recognises the copy and simply unlinks its source.
     *
     * @return ?string storage-relative archive path, or null when the copy
     *     failed (the live result still serves; the expiry sweep archives it)
     */
    public static function copyResult(
        string $relative,
        int $executionId,
        string $requestCode,
        ?int $clientId,
        string $expiresAt
    ): ?string {
        [$source, $dir, $name, $sub] = self::resolve($relative, $executionId, $requestCode, $clientId, $expiresAt);
        if ($source === null) {
            return null;
        }
        $dest = $dir . '/' . $name;
        if (is_file($dest)) {
            return $sub . '/' . $name; // already archived (idempotent)
        }
        if (!@copy($source, $dest)) {
            return null;
        }
        return $sub . '/' . $name;
    }

    /**
     * Validate the source path, create the archive dir when needed and
     * compute the deterministic destination name shared by store/copy.
     *
     * @return array{?string,string,string,string} [absolute source or null,
     *     absolute archive dir, file name, storage-relative subdir]
     */
    private static function resolve(
        string $relative,
        int $executionId,
        string $requestCode,
        ?int $clientId,
        string $expiredAt
    ): array {
        $relative = str_replace('\\', '/', $relative);
        // Same shape check as Cleanup::safeUnlink, minus the .tmp sibling:
        // temp partials are crashed writes, never complete results, and stay
        // deletable.
        if ($relative === ''
            || preg_match('#^(results|cache)/[A-Za-z0-9._-]+\.json$#i', $relative) !== 1) {
            return [null, '', '', ''];
        }
        $source = Filesystem::absoluteFromRelative($relative);
        if (!is_file($source)) {
            return [null, '', '', '']; // already moved (or never published): nothing to do
        }

        $sub = (string)Config::get('storage.archive_subdir', 'archive');
        $dir = Filesystem::storageDir() . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return [null, '', '', '']; // storage problem: keep the file, retry next sweep
        }

        $name = self::nameFor($executionId, $requestCode, $clientId, $expiredAt);
        return [$source, $dir, $name, $sub];
    }

    /** Request code is admin-set [A-Za-z0-9._-]{1,64}; never trust it raw. */
    private static function safeCode(string $code): string
    {
        $code = preg_replace('/[^A-Za-z0-9._-]/', '-', $code) ?? '';
        return substr($code === '' ? 'request' : $code, 0, 64);
    }

    /** The expiry moment as Ymd_His in Tehran local time. */
    private static function stamp(string $expiredAt): string
    {
        $dt = $expiredAt !== '' ? Time::fromSql($expiredAt) : null;
        return ($dt ?? new \DateTimeImmutable('now'))->format('Ymd_His');
    }
}
