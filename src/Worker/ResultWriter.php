<?php
declare(strict_types=1);
namespace Worker;

/**
 * Incremental JSON writer for delayed execution results.
 *
 * Streams rows one by one into a temporary file next to the final
 * storage/results/{execution_id}.json as {"data":[row,row,...],"meta":{...}}
 * so hundreds of thousands of rows never exist in memory. Rows are written
 * while the query streams (comma-separated); the meta block is appended once
 * the run finishes, and only then is the temp file renamed over the final
 * name — a crashed run can never leave a half-written file visible at the
 * documented path. Anything left at {id}.json.tmp is a partial leftover and
 * cleanup reclaims it.
 */
final class ResultWriter
{
    /** @var resource|null */
    private $fh = null;
    private string $path = '';
    private string $tmpPath = '';
    private bool $firstRow = true;
    private bool $sanitized = false;

    public function open(string $absolutePath): void
    {
        $this->discard(); // defensive: never leave a half-open previous file
        $tmpPath = $absolutePath . '.tmp';
        $fh = @fopen($tmpPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open result file for writing: {$tmpPath}");
        }
        $this->fh = $fh;
        $this->path = $absolutePath;
        $this->tmpPath = $tmpPath;
        $this->firstRow = true;
        $this->sanitized = false;
        $this->write('{"data":[');
    }

    public function writeRow(array $row): void
    {
        if ($this->fh === null) {
            throw new \RuntimeException('Result file is not open.');
        }
        if (!$this->firstRow) {
            $this->write(',');
        }
        $this->firstRow = false;
        $this->write($this->encode($row));
    }

    /**
     * Close the data array, append the meta block, and publish the file by
     * renaming the temp file over the final name (publish-then-not-visible
     * until complete). Throws when the rename fails; the caller then
     * discards() the temp leftover.
     * @param array<string,mixed> $meta
     */
    public function finish(array $meta): void
    {
        if ($this->fh === null) {
            throw new \RuntimeException('Result file is not open.');
        }
        if ($this->sanitized) {
            $meta['encoding_sanitized'] = true;
        }
        try {
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $metaJson = '{}';
        }
        $this->write('],"meta":' . $metaJson . '}');
        fclose($this->fh);
        $this->fh = null;
        if (!@rename($this->tmpPath, $this->path)) {
            throw new \RuntimeException("Cannot publish result file: {$this->path}");
        }
        $this->tmpPath = '';
    }

    /** Close the file if open; unlink the partial temp file when it exists. */
    public function discard(): void
    {
        if ($this->fh !== null) {
            @fclose($this->fh);
            $this->fh = null;
        }
        if ($this->tmpPath !== '' && is_file($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        $this->tmpPath = '';
        $this->path = '';
    }

    public function touched(): bool
    {
        return $this->path !== '';
    }

    /** Any row needed invalid-UTF-8 repair (surfaced via meta.encoding_sanitized). */
    public function hasSanitized(): bool
    {
        return $this->sanitized;
    }

    private function write(string $bytes): void
    {
        $n = fwrite($this->fh, $bytes);
        if ($n === false || $n !== strlen($bytes)) {
            throw new \RuntimeException('Writing the result file failed (disk full?).');
        }
    }

    /** Strict first; on failure repair invalid UTF-8 strings and flag it. */
    private function encode(array $row): string
    {
        try {
            return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Repair BOTH values and keys: column names (aliases) can arrive
            // mojibake'd from the driver exactly like values can, and a broken
            // key fails every json_encode afterwards.
            array_walk_recursive($row, function (&$v): void {
                self::repairString($v);
                $this->sanitized = true;
            });
            $row = self::repairKeys($row);
            $this->sanitized = true;
            return json_encode(
                $row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '{}';
        }
    }

    /**
     * Rebuild a row so string keys are valid UTF-8. Unrepairable or empty keys
     * become positional col_N names; collisions get an underscore suffix (the
     * same marker SQL Server itself uses for duplicate aliases).
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function repairKeys(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (is_string($k)) {
                self::repairString($k);
                if ($k === '' || preg_match('//u', $k) !== 1) {
                    $k = 'col_' . count($out);
                }
                while (array_key_exists($k, $out)) {
                    $k .= '_';
                }
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** In-place UTF-8 repair of one string (iconv, then ASCII-collapse). */
    private static function repairString(string &$v): void
    {
        if (preg_match('//u', $v) === 1) {
            return;
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $v);
        if ($fixed === false || preg_match('//u', $fixed) !== 1) {
            $fixed = preg_replace('/[\x80-\xFF]/s', '?', $v) ?? '';
        }
        $v = $fixed;
    }
}
