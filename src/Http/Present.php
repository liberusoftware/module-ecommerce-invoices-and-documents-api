<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http;

use DateTimeInterface;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\ContinuityReport;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\DocumentSummary;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\ForgetReport;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Line;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Outcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\ParticipantRecord;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Party;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\RenderModel;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\RenderResult;

/**
 * The only place a domain object becomes JSON.
 *
 * Everything here takes a `Data\` object. Nothing takes an Eloquent model and
 * nothing reads an attribute by name, so this package depends on what the domain
 * publishes rather than on how it stores it.
 *
 * Two things never leave. The merchant, because it is derived from the
 * credential and echoing it back reads as though a caller could have chosen it —
 * the two platform-privacy payloads are the stated exception, being person-wide
 * across tenants. And the database key, because the host showed customers the
 * primary key of `invoices` and published a running count of the deployment.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function document(RenderModel $model): array
    {
        return self::listed($model) + [
            'seller' => self::party($model->seller),
            'buyer' => self::party($model->buyer, true),
            'lines' => array_map(self::line(...), $model->lines),
            'summary' => self::summary($model->summary),
            'note' => $model->note,
        ];
    }

    /**
     * A document in a listing: no address, no email, no lines.
     *
     * @return array<string, mixed>
     */
    public static function listed(RenderModel $model): array
    {
        return [
            'reference' => $model->reference,
            'kind' => $model->kind->value,
            'state' => $model->state->value,
            'number' => $model->number,
            'issued_at' => self::instant($model->issuedAt),
            'buyer_name' => $model->buyer->name,
            'total' => self::money($model->summary->gross),
            'corrects' => $model->correctsReference === null ? null : [
                'reference' => $model->correctsReference,
                'number' => $model->correctsNumber,
            ],
        ];
    }

    /**
     * A document and the file of it, if there is one.
     *
     * `rendered` is false and the reason is named when there is not. That is a
     * 200: the module still knows everything the document says, which is the
     * whole blast radius of an unbound renderer.
     *
     * @return array<string, mixed>
     */
    public static function rendition(RenderResult $result): array
    {
        $rendered = $result->rendered;

        return [
            'rendered' => $result->isRendered(),
            'unavailable_reason' => $result->unavailable?->value,
            'rendition' => $rendered === null ? null : [
                'media_type' => $rendered->mediaType,
                'filename' => $rendered->filename,
                'contents_base64' => base64_encode($rendered->contents),
            ],
            'document' => self::document($result->model),
        ];
    }

    /** @return array<string, mixed> */
    public static function outcome(Outcome $outcome): array
    {
        return [
            'recording' => $outcome->recording->value,
            'reference' => $outcome->reference,
        ];
    }

    /** @return array<string, mixed> */
    public static function continuity(ContinuityReport $report): array
    {
        return [
            'series' => $report->code,
            'gapless' => $report->gapless,
            'continuous' => $report->isContinuous(),
            'issued' => $report->issued,
            'burned' => $report->burned,
            'first' => $report->first,
            'last' => $report->last,
            'missing' => $report->missing,
        ];
    }

    /**
     * One person's documents, across every tenant, with the tenant on each.
     *
     * @return array<string, mixed>
     */
    public static function participantRecord(ParticipantRecord $record): array
    {
        return [
            'subject_ref' => $record->subjectReference,
            'documents' => array_map(static fn ($document): array => [
                'tenant_id' => $document->tenantId,
                'reference' => $document->reference,
                'kind' => $document->kind->value,
                'state' => $document->state->value,
                'number' => $document->number,
                'issued_at' => self::instant($document->issuedAt),
                'buyer_name' => $document->buyerName,
                'total' => self::money($document->gross),
                'retain_until' => self::instant($document->retainUntil),
                'redacted' => $document->redacted,
            ], $record->documents),
        ];
    }

    /**
     * What erasure did, and what retention would not let it do.
     *
     * A refusal names the document, its number and the date the window ends, so
     * the caller can act on the conflict instead of finding out later.
     *
     * @return array<string, mixed>
     */
    public static function forgetReport(ForgetReport $report): array
    {
        return [
            'subject_ref' => $report->subjectReference,
            'complete' => $report->wasComplete(),
            'redacted_documents' => $report->redactedDocuments,
            'redacted_contacts' => $report->redactedContacts,
            'redacted_deliveries' => $report->redactedDeliveries,
            'refused_documents' => array_map(static fn ($refusal): array => [
                'tenant_id' => $refusal->tenantId,
                'reference' => $refusal->reference,
                'number' => $refusal->number,
                'retain_until' => self::instant($refusal->retainUntil),
                'window_is_unknown' => $refusal->windowIsUnknown(),
            ], $report->refusedDocuments),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function collection(array $data): array
    {
        return ['data' => array_values($data)];
    }

    /**
     * Minor units, a code, an exponent and a decimal **string**.
     *
     * The decimal is the domain's own integer arithmetic. A client that parses
     * JSON numbers as doubles still gets an exact answer.
     *
     * @return array{minor: int, currency: string, exponent: int, decimal: string}
     */
    public static function money(Money $money): array
    {
        return [
            'minor' => $money->minor,
            'currency' => $money->currency,
            'exponent' => $money->exponent,
            'decimal' => $money->decimal(),
        ];
    }

    /** @return array<string, mixed> */
    private static function party(Party $party, bool $withContact = false): array
    {
        $fields = [
            'reference' => $party->reference,
            'name' => $party->name,
            'address' => $party->address,
            'tax_id' => $party->taxId,
        ];

        return $withContact ? $fields + ['email' => $party->email] : $fields;
    }

    /** @return array<string, mixed> */
    private static function line(Line $line): array
    {
        return [
            'description' => $line->description,
            'quantity' => $line->quantity(),
            'quantity_milli' => $line->quantityMilli,
            'unit_net' => self::money($line->unitNet),
            'net' => self::money($line->net),
            'tax_rate_basis_points' => $line->taxRateBasisPoints,
            'tax' => self::money($line->tax),
            'gross' => self::money($line->gross),
        ];
    }

    /** @return array<string, mixed> */
    private static function summary(DocumentSummary $summary): array
    {
        return [
            'net' => self::money($summary->net),
            'tax' => self::money($summary->tax),
            'gross' => self::money($summary->gross),
            'by_rate' => array_map(static fn ($total): array => [
                'tax_rate_basis_points' => $total->rateBasisPoints,
                'net' => self::money($total->net),
                'tax' => self::money($total->tax),
                'gross' => self::money($total->gross),
            ], $summary->byRate),
        ];
    }

    private static function instant(?DateTimeInterface $at): ?string
    {
        return $at?->format(DateTimeInterface::ATOM);
    }
}
