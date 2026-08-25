<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http;

use Liberu\Ecommerce\InvoicesAndDocuments\Enums\RefusalReason;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\DocumentsAreImmutable;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\MoneyMismatch;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\NotFound;
use Throwable;

/**
 * Every way a request to this surface can fail, in one place.
 *
 * Two tables, because the domain refuses in two ways. An exception is a
 * condition the caller had no business reaching; a `RefusalReason` is a decision
 * the domain made and returned, and it reaches a caller as the error code
 * itself. The refusal table is a `match` over the enum rather than an array, so
 * a reason added to the domain fails static analysis here instead of arriving as
 * a 500.
 *
 * `resubmittable` means the identical request can succeed later. Three delivery
 * refusals are not: `RecordDelivery` writes the attempt row before it asks the
 * transport, so the delivery reference is spent and a retry answers
 * `already_recorded`. Repeating those needs a new reference, which is a
 * different request.
 */
final class Failure
{
    /** @var array{status: int, code: string, message: string, resubmittable: bool} */
    private const NOT_FOUND = [
        'status' => 404,
        'code' => 'not_found',
        'message' => 'No such record exists for this credential.',
        'resubmittable' => false,
    ];

    /** @var array<class-string, array{status: int, code: string, message: string, resubmittable: bool}> */
    private const EXCEPTIONS = [
        NotFound::class => self::NOT_FOUND,
        DocumentsAreImmutable::class => [
            'status' => 409,
            'code' => 'documents_are_immutable',
            'message' => 'An issued document cannot be changed or removed. Correct it by issuing a credit note against it, or void it, which records rather than erases.',
            'resubmittable' => false,
        ],
        MoneyMismatch::class => [
            'status' => 422,
            'code' => 'money_mismatch',
            'message' => 'An amount arrived with a currency or an exponent that does not match the rest of the request. A document carries one currency.',
            'resubmittable' => true,
        ],
    ];

    /** @return array<class-string, array{status: int, code: string, message: string, resubmittable: bool}> */
    public static function exceptions(): array
    {
        return self::EXCEPTIONS;
    }

    /**
     * The mapping for a thrown exception, or null if this surface does not own it.
     *
     * An unowned throwable bubbles to the application handler as a 500. A
     * catch-all would dress a defect as a plausible 4xx.
     *
     * @return array{status: int, code: string, message: string, resubmittable: bool}|null
     */
    public static function for(Throwable $exception): ?array
    {
        foreach (self::EXCEPTIONS as $class => $mapping) {
            if ($exception instanceof $class) {
                return $mapping;
            }
        }

        return null;
    }

    /** @return array{status: int, code: string, message: string, resubmittable: bool} */
    public static function refusal(RefusalReason $reason): array
    {
        return match ($reason) {
            RefusalReason::SaleSourceUnbound => self::entry(503, $reason, 'This deployment has bound nothing that can read a sale, so there is nothing to copy onto a document. Nothing was written.', true),
            RefusalReason::SaleNotFound => self::entry(422, $reason, 'The sale named by source_ref is not one this merchant can describe.', true),
            RefusalReason::SaleHasNoLines => self::entry(409, $reason, 'That sale has no lines, and a document with no lines is not a document.', false),
            RefusalReason::MixedCurrencies => self::entry(409, $reason, 'The lines do not share one currency and one exponent. A document carries exactly one currency.', false),
            RefusalReason::CreditNoteRequiresCorrectedDocument => self::entry(422, $reason, 'A credit note names the document it corrects. Post it to that document instead.', true),
            RefusalReason::StatedTotalDisagreesWithLines => self::entry(409, $reason, 'The sale states a total its own lines do not add up to. Nothing was written, and it will not be until the sale agrees with itself.', false),
            RefusalReason::SeriesNotFound => self::entry(422, $reason, 'No numbering series with that code is open for this merchant.', true),
            RefusalReason::SeriesRequired => self::entry(422, $reason, 'A fiscal document is filed under a series, so this call has to name one.', true),
            RefusalReason::ProformaMayNotUseFiscalSeries => self::entry(409, $reason, 'A proforma is a quotation with a document\'s shape and may not take a number from a fiscal series.', false),
            RefusalReason::SeriesIsGapless => self::entry(409, $reason, 'This series promises no gaps, so it will not spend a number on nothing.', false),
            RefusalReason::NotIssued => self::entry(409, $reason, 'That document has not been issued, so there is nothing yet to correct or to deliver.', false),
            RefusalReason::IllegalTransition => self::entry(409, $reason, 'That document is not in a state this operation can move it out of.', false),
            RefusalReason::NotCorrectable => self::entry(409, $reason, 'A credit note is not itself corrected by a credit note.', false),
            RefusalReason::ExceedsCorrectedDocument => self::entry(409, $reason, 'The credit notes against that document would come to more than the document itself.', false),
            RefusalReason::NoRendererBound => self::entry(503, $reason, 'This deployment has bound nothing that turns a document into a file. Everything the document says is still readable.', true),
            RefusalReason::RendererDeclined => self::entry(409, $reason, 'The bound renderer produced no file for this document.', false),
            RefusalReason::NoTransportBound => self::entry(503, $reason, 'This deployment has bound nothing that can transmit a document. The attempt is recorded as pending; sending it once a transport is bound needs a new delivery reference.', false),
            RefusalReason::NoDeliveryAddress => self::entry(422, $reason, 'There is no address to deliver to, and the document carries no buyer email to fall back on.', true),
            RefusalReason::TransportFailed => self::entry(502, $reason, 'The transport did not accept the document. The attempt is recorded as failed; trying again needs a new delivery reference.', false),
            RefusalReason::TransportSuppressed => self::entry(409, $reason, 'The transport suppressed this delivery. The attempt is recorded as suppressed.', false),
        };
    }

    /** @return array{error: array{code: string, message: string, resubmittable: bool}} */
    public static function body(string $code, string $message, bool $resubmittable): array
    {
        return ['error' => ['code' => $code, 'message' => $message, 'resubmittable' => $resubmittable]];
    }

    /**
     * The refusal's own name is the error code; nothing here can invent a second one.
     *
     * @return array{status: int, code: string, message: string, resubmittable: bool}
     */
    private static function entry(int $status, RefusalReason $reason, string $message, bool $resubmittable): array
    {
        return ['status' => $status, 'code' => $reason->value, 'message' => $message, 'resubmittable' => $resubmittable];
    }
}
