<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Integrations;

use WP_Error;

/**
 * The single shared credential every self-hosted companion service must
 * present to bind into this site's authentication and credit ledger (the
 * `aiya/integrations/v1` machine endpoints): one key, stored in the
 * file-serve page's options like every other service credential in this
 * plugin (raw, never re-displayed). Deliberately not per-service — the
 * key holder is trusted infrastructure by definition, so issuing several
 * keys would only add rotation work without shrinking anyone's blast
 * radius.
 *
 * An empty setting means the whole integration surface answers 503: no
 * half-configured state exists. Verification is a hash_equals on the
 * presented Bearer value; the REST-level user resolution is unaffected —
 * a service key carries no user session (it has no `{userId}.{secret}`
 * shape, so TokenAuthentication short-circuits it before the lookup).
 */
final class ServiceKey
{
    private const FIELD_ID = 'service_key';

    public static function configured(): bool
    {
        return self::stored() !== '';
    }

    /**
     * The route guard for every machine endpoint: null when the request
     * presents the configured key, otherwise the error to reject with.
     * The caller passes the already-parsed bearer value (the REST layer
     * owns header parsing); an absent header arrives as null.
     */
    public static function guard(?string $presented): ?WP_Error
    {
        $stored = self::stored();
        if ($stored === '') {
            return new WP_Error(
                'aiya_service_disabled',
                __('The service integration is not configured.', 'aiya-core'),
                ['status' => 503]
            );
        }

        if ($presented === null || $presented === '' || !hash_equals($stored, $presented)) {
            return new WP_Error(
                'aiya_service_unauthorized',
                __('Service authentication failed.', 'aiya-core'),
                ['status' => 401]
            );
        }

        return null;
    }

    private static function stored(): string
    {
        return trim((string) aiya_core_opt('fileserve', self::FIELD_ID, ''));
    }
}
