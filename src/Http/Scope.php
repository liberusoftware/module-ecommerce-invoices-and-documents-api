<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http;

/**
 * Three abilities, split by whose records the caller reaches.
 *
 * `invoicing:platform-privacy` is named for what it is. The domain's export and
 * erasure walk one person across **every** tenant, deliberately, so an ability
 * that reaches them is not a merchant's — see `docs/domain.md` §4.
 */
final class Scope
{
    public const READ = 'invoicing:read';

    public const OPERATE = 'invoicing:operate';

    public const PLATFORM_PRIVACY = 'invoicing:platform-privacy';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::READ, self::OPERATE, self::PLATFORM_PRIVACY];
    }

    /**
     * The abilities that act for the credential holder and may never name a subject.
     *
     * @return list<string>
     */
    public static function selfOnly(): array
    {
        return [self::READ];
    }
}
