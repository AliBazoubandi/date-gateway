<?php
declare(strict_types=1);
namespace Db;

/**
 * Positional column names for a target result set.
 *
 * fetch_assoc(), pg_fetch_all() and sqlsrv_fetch_array(ASSOC) build the row
 * array from the raw column names, so a query that repeats an alias silently
 * keeps only the LAST value: SELECT 1 AS n, 2 AS n came back as one "n" => 2
 * and a column the caller approved simply disappeared from the result. The
 * drivers therefore fetch numeric rows and name them from this list instead:
 * repeats get a deterministic _2/_3 suffix and an empty name becomes col_N, so
 * every column of the approved SQL survives into the JSON result.
 */
final class ColumnNames
{
    /**
     * @param list<string> $names raw column names, in result-set order
     * @return list<string> unique keys in the same order
     */
    public static function deduplicate(array $names): array
    {
        $out = [];
        $used = [];
        foreach (array_values($names) as $i => $name) {
            $name = (string)$name;
            if ($name === '') {
                $name = 'col_' . ($i + 1);
            }
            $key = $name;
            $n = 1;
            // A literal "n_2" alias can be taken before the suffixed repeat of
            // "n" gets there, so keep bumping until the key is free.
            while (isset($used[$key])) {
                $n++;
                $key = $name . '_' . $n;
            }
            $used[$key] = true;
            $out[] = $key;
        }
        return $out;
    }
}
