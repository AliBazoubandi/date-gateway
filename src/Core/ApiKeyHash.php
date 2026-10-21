<?php
declare(strict_types=1);
namespace Core;

/**
 * How a client's API key becomes the stored lookup hash.
 *
 * Two forms live side by side in `clients`:
 *  - api_key_hash: bare SHA-256 of the key. The pre-pepper form; rows that
 *    have not authenticated since the pepper upgrade still carry it.
 *  - api_key_hash_hmac: HMAC-SHA256 of the key, keyed (peppered) with
 *    `security.master_key` — the real value is GATEWAY_MASTER_KEY in the
 *    untracked config/.env. New and rotated keys always write this form and
 *    lookups try it first, so a stolen dump alone can no longer be
 *    brute-forced offline.
 *
 * Compatibility contract: a key stored before the pepper still authenticates
 * through its bare SHA-256 hash, and the first successful lookup re-stores it
 * as the peppered HMAC (Api\ApiAuth does the upgrade) — no existing key is
 * ever invalidated. The legacy hash is deliberately KEPT instead of cleared:
 * if the master key is ever rotated, the HMAC lookups miss while the legacy
 * ones still hit, the keys keep working, and the rows re-upgrade under the
 * new pepper (the same self-healing also covers a genuinely lost key file).
 */
final class ApiKeyHash
{
    /** The legacy form: bare SHA-256 of the key (pre-pepper storage). */
    public static function legacy(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /** The current form: HMAC-SHA256 of the key, keyed by the master key. */
    public static function peppered(string $apiKey): string
    {
        return hash_hmac('sha256', $apiKey, (string)Config::get('security.master_key', ''));
    }
}
