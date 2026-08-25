<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\ForgetParticipant;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Present;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\ExportParticipantRecord;

/**
 * One person's documents, and the erasure that takes the person out of them.
 *
 * **Both are person-wide across every tenant, and that is the domain's
 * decision rather than this package's.** A subject-access request is about a
 * person and not about a merchant, and the host this module replaces got that
 * backwards. So the ability is `invoicing:platform-privacy`, the tenant on the
 * credential is not consulted, and the merchant appears on every row of both
 * answers because a caller cannot act on a refusal that will not say whose
 * document refused. Do not issue this ability to a storefront credential.
 *
 * Both are POSTs, the export included: a subject reference in a query string is
 * a subject reference in the access log, the referrer and the proxy cache.
 */
final class PrivacyController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'export' => Scope::PLATFORM_PRIVACY,
        'erase' => Scope::PLATFORM_PRIVACY,
    ];

    /**
     * Somebody with nothing here gets an empty list rather than a 404. "We hold
     * nothing about that person" is the answer a privacy request needs.
     */
    public function export(Request $request, ExportParticipantRecord $export): JsonResponse
    {
        $input = $this->validated($request, [
            'subject_ref' => ['required', 'string', 'max:191'],
        ]);

        return new JsonResponse(['data' => Present::participantRecord($export($this->namedSubject($input)))]);
    }

    /**
     * Redact what may be redacted, and say what retention would not let go.
     *
     * 200 whatever happens, with `complete` on the face of it. Contact details,
     * delivery addresses and notes always go; the buyer's identity on a document
     * still inside its retention window does not, and each refusal names the
     * document, its number and the date. A document issued under no configured
     * retention window is refused too — unknown is not zero.
     */
    public function erase(Request $request, ForgetParticipant $forget): JsonResponse
    {
        $input = $this->validated($request, [
            'subject_ref' => ['required', 'string', 'max:191'],
        ]);

        return new JsonResponse(['data' => Present::forgetReport($forget($this->namedSubject($input)))]);
    }
}
