<?php
declare(strict_types=1);
namespace Services;

/**
 * Control-flow exception: a result grew past the configured byte cap
 * (limits.max_result_bytes / limits.instant.max_result_bytes). Deliberately
 * NOT a query error — QueryRunner re-throws it so callers can map it to a
 * distinct outcome (413 for instant, failed execution for delayed) instead
 * of burying it as a generic execution failure.
 */
final class ResultTooLargeException extends \RuntimeException
{
}
