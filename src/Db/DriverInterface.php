<?php
declare(strict_types=1);
namespace Db;

/**
 * Small driver interface for the three native target-DB drivers.
 * Implementations MUST:
 *  - never buffer whole result sets in memory (fetch row by row),
 *  - classify one "timeout" error kind (for HTTP 504 vs 502),
 *  - never include credentials in error messages.
 */
interface DriverInterface
{
    /**
     * @param array{db_type:string,host:string,port:int|null,db:string,
     *              user:string,password:string,timeout:int} $o
     */
    public function connect(array $o): void;

    /** Applies to subsequent queries (and is re-applied after a reconnect). */
    public function setQueryTimeout(int $seconds): void;

    /**
     * Execute a pre-approved query with optional parameters. Returns a statement/handle for fetchRow()
     * (any type: resource, object or array). Throws DbQueryException on error.
     * @param list<mixed> $params positional bound parameters
     * @return mixed
     */
    public function query(string $sql, array $params = []): mixed;

    /**
     * Fetch the next row as an associative array (streaming-safe), or null at
     * the end of the set.
     * @return array<string,mixed>|null
     */
    public function fetchRow(mixed $stmt): ?array;

    /**
     * Advance to the next result set of a multi-statement batch (scripts
     * mode). Returns true when a new result set is active for fetchRow(),
     * false when the batch has no more results. Fieldless "rows affected"
     * placeholders between results are skipped in both directions
     * (fetchRow does the same for a placeholder at the head of the batch).
     * Throws DbQueryException when the batch itself failed (a later
     * statement errored).
     */
    public function nextResult(mixed $stmt): bool;

    /** Release the statement early (discard the rest of the rows). */
    public function free(mixed $stmt): void;

    /** Close the connection (idempotent). */
    public function close(): void;

    /** true when the LAST error (or exception) was a timeout. */
    public function timedOut(): bool;

    /** Short, credential-free text of the last error ('' if none). */
    public function lastError(): string;
}
