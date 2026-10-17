<?php
declare(strict_types=1);
namespace Db;

/**
 * One row must be tiny: description of the failure with no credentials inside.
 * Thrown by the drivers' connect step.
 */
final class DbConnectException extends \RuntimeException
{
}
