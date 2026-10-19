<?php
declare(strict_types=1);
namespace Services;

/**
 * Control-flow exception: SqlGuard refused the stored SQL as not provably
 * read-only. Deliberately distinct from a query failure - nothing reached the
 * target database - so callers map it to 502 query_rejected (API), a clear
 * validation error (admin) or a failed execution (worker) instead of a generic
 * driver error.
 */
final class SqlRejectedException extends \RuntimeException
{
}
