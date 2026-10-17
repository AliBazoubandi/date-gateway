<?php
declare(strict_types=1);
namespace Db;

/**
 * Query-time failure on a target database. Carries the timeout classification
 * so the API can answer 504 (timeout) vs 502 (other failure).
 */
final class DbQueryException extends \RuntimeException
{
    private bool $timedOut;

    public function __construct(string $message, bool $timedOut = false)
    {
        parent::__construct($message);
        $this->timedOut = $timedOut;
    }

    public function isTimeout(): bool
    {
        return $this->timedOut;
    }
}
