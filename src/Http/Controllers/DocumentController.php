<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\DraftCreditNote;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\DraftDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\IssueDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\RecordDelivery;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\VoidDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Present;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Line;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\NotFound;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\BuildRenderModel;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\FindDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\ListDocuments;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\RenderDocument;

/**
 * The merchant's side of a document: draft it, issue it, correct it, void it,
 * deliver it, read it.
 *
 * **There is no update route and no delete route, and there will not be one.**
 * A document is corrected by issuing another document against it and discarded
 * by voiding it, which records. The domain refuses both at the model and in the
 * schema; this surface refuses them by publishing neither.
 */
final class DocumentController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'index' => Scope::OPERATE,
        'store' => Scope::OPERATE,
        'show' => Scope::OPERATE,
        'issue' => Scope::OPERATE,
        'void' => Scope::OPERATE,
        'creditNote' => Scope::OPERATE,
        'deliver' => Scope::OPERATE,
        'rendition' => Scope::OPERATE,
    ];

    /**
     * This merchant's documents, newest first.
     *
     * ponytail: the domain publishes no paginated listing and no summary
     * projection, so this builds a render model per row. Fine for a merchant's
     * working set; a domain-side paged query is what a large one wants.
     */
    public function index(Request $request, ListDocuments $list, BuildRenderModel $build): JsonResponse
    {
        $input = $this->validated($request, [
            'kind' => ['nullable', Rule::enum(DocumentKind::class)],
            'state' => ['nullable', Rule::enum(DocumentState::class)],
            'buyer_ref' => ['nullable', 'string', 'max:191'],
        ]);

        $kind = $this->nullableString($input['kind'] ?? null);
        $state = $this->nullableString($input['state'] ?? null);

        $documents = $list(
            $this->tenantId(),
            $kind === null ? null : DocumentKind::from($kind),
            $state === null ? null : DocumentState::from($state),
            $this->nullableString($input['buyer_ref'] ?? null),
        );

        $listed = [];

        foreach ($documents as $document) {
            $listed[] = Present::listed($build($this->tenantId(), $document));
        }

        return new JsonResponse(Present::collection($listed));
    }

    /**
     * Draft a document, which is the one moment the sale is read.
     *
     * The natural key is `(merchant, kind, source_ref)`, so a retry of the same
     * sale answers `already_recorded` with the reference already minted. There is
     * no idempotency key on this surface and no need of one.
     */
    public function store(Request $request, DraftDocument $draft): JsonResponse
    {
        $input = $this->validated($request, [
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            'source_ref' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->answer($draft(
            $this->tenantId(),
            DocumentKind::from($this->asString($input['kind'])),
            $this->asString($input['source_ref']),
            $this->nullableString($input['note'] ?? null),
        ));
    }

    /** Everything the document says, from the document's own frozen rows. */
    public function show(string $document, FindDocument $find, BuildRenderModel $build): JsonResponse
    {
        $found = $find($this->tenantId(), $document) ?? throw NotFound::document();

        return new JsonResponse(['data' => Present::document($build($this->tenantId(), $found))]);
    }

    /** Issue it, which is where the number is spent. */
    public function issue(string $document, Request $request, FindDocument $find, IssueDocument $issue): JsonResponse
    {
        $input = $this->validated($request, [
            'series' => ['nullable', 'string', 'max:64'],
        ]);

        $found = $find($this->tenantId(), $document) ?? throw NotFound::document();

        return $this->answer($issue(
            $this->tenantId(),
            $found,
            $this->nullableString($input['series'] ?? null),
            $this->subjectRef(),
        ));
    }

    /** Void it. The number stays spent, which is what keeps a gapless series gapless. */
    public function void(string $document, Request $request, FindDocument $find, VoidDocument $void): JsonResponse
    {
        $input = $this->validated($request, [
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $found = $find($this->tenantId(), $document) ?? throw NotFound::document();

        return $this->answer($void($this->tenantId(), $found, $this->asString($input['reason']), $this->subjectRef()));
    }

    /**
     * Correct it, by issuing another document that references it.
     *
     * The lines are stated by the caller rather than copied, because a credit
     * note is rarely the whole invoice. Every figure arrives computed: this
     * module adds recorded integers and never divides, multiplies or rounds.
     */
    public function creditNote(string $document, Request $request, FindDocument $find, DraftCreditNote $credit): JsonResponse
    {
        $input = $this->validated($request, [
            'source_ref' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity_milli' => ['required', 'integer'],
            'lines.*.currency' => ['required', 'string'],
            'lines.*.exponent' => ['required', 'integer'],
            'lines.*.unit_net_minor' => ['required', 'integer'],
            'lines.*.net_minor' => ['required', 'integer'],
            'lines.*.tax_rate_basis_points' => ['required', 'integer'],
            'lines.*.tax_minor' => ['required', 'integer'],
            'lines.*.gross_minor' => ['required', 'integer'],
        ]);

        $found = $find($this->tenantId(), $document) ?? throw NotFound::document();

        return $this->answer($credit(
            $this->tenantId(),
            $found,
            $this->asString($input['source_ref']),
            $this->lines(is_array($input['lines']) ? $input['lines'] : []),
            $this->nullableString($input['note'] ?? null),
        ));
    }

    /**
     * Record a delivery attempt, and transmit it if a transport is bound.
     *
     * The attempt is a row before it is a transmission, keyed on the delivery
     * reference the caller states, so a retried send cannot transmit twice.
     */
    public function deliver(string $document, Request $request, FindDocument $find, RecordDelivery $record): JsonResponse
    {
        $input = $this->validated($request, [
            'reference' => ['required', 'string', 'max:191'],
            'channel' => ['required', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $found = $find($this->tenantId(), $document) ?? throw NotFound::document();

        return $this->answer($record(
            $this->tenantId(),
            $found,
            $this->asString($input['reference']),
            $this->asString($input['channel']),
            $this->nullableString($input['address'] ?? null),
        ));
    }

    /**
     * The file of the document, when a renderer is bound and produced one.
     *
     * 200 either way: with nothing bound there is no file and the document is
     * still entirely readable, so `rendered` is false and the reason is named
     * rather than the whole read failing.
     */
    public function rendition(string $document, FindDocument $find, RenderDocument $render): JsonResponse
    {
        $found = $find($this->tenantId(), $document) ?? throw NotFound::document();

        return new JsonResponse(['data' => Present::rendition($render($this->tenantId(), $found))]);
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return list<Line>
     */
    private function lines(array $lines): array
    {
        $built = [];

        foreach ($lines as $line) {
            $line = (array) $line;
            $currency = $this->asString($line['currency'] ?? null);
            $exponent = $this->asInt($line['exponent'] ?? null, 2);

            $built[] = new Line(
                $this->asString($line['description'] ?? null),
                $this->asInt($line['quantity_milli'] ?? null, 0),
                new Money($this->asInt($line['unit_net_minor'] ?? null, 0), $currency, $exponent),
                new Money($this->asInt($line['net_minor'] ?? null, 0), $currency, $exponent),
                $this->asInt($line['tax_rate_basis_points'] ?? null, 0),
                new Money($this->asInt($line['tax_minor'] ?? null, 0), $currency, $exponent),
                new Money($this->asInt($line['gross_minor'] ?? null, 0), $currency, $exponent),
            );
        }

        return $built;
    }
}
