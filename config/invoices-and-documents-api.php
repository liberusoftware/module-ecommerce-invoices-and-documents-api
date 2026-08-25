<?php

declare(strict_types=1);

return [
    /*
     * The group's middleware is `[]` and never null: an empty stack is a host
     * that has not opted in to one, a null stack is Laravel substituting one.
     * Every endpoint refuses an unauthenticated caller in the controller
     * regardless.
     */
    'route' => [
        'prefix' => 'api/invoicing',
        'middleware' => [],
        'domain' => null,
    ],

    /*
     * The merchant is read from the authenticated actor and never from the
     * request; the attribute holding it is host-specific, which is why it is
     * configuration. The subject reference is the authentication identifier
     * unless a host stores an opaque one of its own.
     */
    'actor' => [
        'tenant_attribute' => 'team_id',
        'subject_attribute' => null,
    ],
];
