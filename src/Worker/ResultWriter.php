<?php
declare(strict_types=1);
namespace Worker;

/**
 * Incremental JSON writer for delayed execution results.
 *
 * Streams rows one by one into storage/results/{execution_id}.json as
 *   {"data":[row,row,...],"meta":{...}}
 * so hundreds of thousands of rows never exist in memory. Rows are written
 * while the query streams (comma-separated); the meta block is appended once
 * the run finishes. The file is only valid JSON after finish() — anything else
 * is a partial leftover and the caller deletes it on failure.
 */
final class ResultWriter
{
    /** @var resource|null */
    private $fh = null;
    private string $path = '';
    private bool $firstRow = true;
    private bool $sanitized = false;

    public function open(string $absolutePath): void
    {
        $this->discard(); // defensive: never leave a half-open previous file
        $fh = @fopen($absolutePath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open result file for writing: {$absolutePath}");
        }
        $this->fh = $fh;
        $this->path = $absolutePath;
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
     * Close the data array and append the meta block.
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
    }

    /** Close the file if open; unlink the partial file when it exists. */
    public function discard(): void
    {
        if ($this->fh !== null) {
            @fclose($this->fh);
            $this->fh = null;
        }
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
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
            array_walk_recursive($row, function (&$v): void {
                if (is_string($v) && preg_match('//u', $v) !== 1) {
                    $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $v);
                    if ($fixed === false || preg_match('//u', $fixed) !== 1) {
                        // Last resort: drop every non-ASCII byte.
                        $fixed = preg_replace('/[\x80-\xFF]/s', '?', $v) ?? '';
                    }
                    $v = $fixed;
                    $this->sanitized = true;
                }
            });
            return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }
    }
}
