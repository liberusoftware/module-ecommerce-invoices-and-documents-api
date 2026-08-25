<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api;

/**
 * The OpenAPI 3.1 document this package publishes.
 *
 * A static file rather than something generated from the router, because the
 * suite asserts parity in both directions and a generator cannot disagree with
 * its own source.
 */
final class OpenApi
{
    public static function path(): string
    {
        return __DIR__.'/../resources/openapi/openapi.json';
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $decoded = json_decode((string) file_get_contents(self::path()), true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
