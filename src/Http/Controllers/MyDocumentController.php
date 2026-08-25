<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Present;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\NotFound;
use Liberu\Ecommerce\InvoicesAndDocuments\Policies\CustodyPolicy;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\BuildRenderModel;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\FindDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\ListDocuments;

/**
 * The documents issued to the credential holder, and nothing else.
 *
 * The buyer is the credential's own subject reference. It is never read from a
 * body here, and the base controller refuses a caller-supplied subject under
 * this ability anyway, so an endpoint that grew one would fail rather than
 * quietly widen.
 *
 * A document belonging to another buyer, a document belonging to another
 * merchant, a document whose buyer has been erased, and a reference nobody ever
 * minted are one answer: 404. Anything else publishes which of them it was.
 */
final class MyDocumentController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'index' => Scope::READ,
        'show' => Scope::READ,
    ];

    public function index(ListDocuments $list, BuildRenderModel $build): JsonResponse
    {
        $listed = [];

        foreach ($list($this->tenantId(), null, null, $this->subjectRef()) as $document) {
            $listed[] = Present::listed($build($this->tenantId(), $document));
        }

        return new JsonResponse(Present::collection($listed));
    }

    public function show(string $document, FindDocument $find, BuildRenderModel $build): JsonResponse
    {
        $found = $find($this->tenantId(), $document);

        if ($found === null || ! CustodyPolicy::buyerMayRead($found, $this->tenantId(), $this->subjectRef())) {
            throw NotFound::document();
        }

        return new JsonResponse(['data' => Present::document($build($this->tenantId(), $found))]);
    }
}
