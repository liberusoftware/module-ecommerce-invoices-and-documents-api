<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers\Controller;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;

/**
 * A controller that does the thing the base class forbids: reads a
 * caller-supplied subject under a self-only ability.
 *
 * It exists so the guard is asserted rather than assumed. No route in this
 * package reaches it.
 */
final class SelfOnlyProbe extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'probe' => Scope::READ,
    ];

    public function probe(): JsonResponse
    {
        return new JsonResponse(['subject' => $this->namedSubject(['subject_ref' => 'somebody-else'])]);
    }

    /** Deliberately absent from the scope map: an unanswered authorization question is not a yes. */
    public function nothing(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
