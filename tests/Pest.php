<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures\ApiActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures\FakeRenderer;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures\FakeSaleSource;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures\FakeTransport;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\TestCase;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Line;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Party;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Sale;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\TransportOutcome;

/*
 * Both suites get the same case: the unit suite asserts on the route table, the
 * failure map and the OpenAPI document, which are properties of a booted
 * application rather than of a class in isolation.
 */
uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/*
 * No test inherits a binding. A suite that leaked one would prove the opposite
 * of what half of it claims about an unbound seam.
 */
uses()->beforeEach(function (): void {
    Config::set('invoices-and-documents.seams.sale', null);
    Config::set('invoices-and-documents.seams.renderer', null);
    Config::set('invoices-and-documents.seams.transport', null);
    Config::set('invoices-and-documents.retention.years', null);
})->in('Feature');

/**
 * A credential for one merchant, carrying exactly the abilities named.
 *
 * The default is reading, which is a shopper's token, and deliberately not
 * operating and not platform privacy.
 *
 * @param  list<string>  $abilities
 */
function actor(array $abilities = [Scope::READ], string $tenant = 'merchant-a'): ApiActor
{
    return ApiActor::query()->create(['team_id' => $tenant, 'abilities' => $abilities]);
}

function operator(string $tenant = 'merchant-a'): ApiActor
{
    return actor([Scope::OPERATE], $tenant);
}

function platform(string $tenant = 'merchant-a'): ApiActor
{
    return actor([Scope::PLATFORM_PRIVACY], $tenant);
}

function subjectOf(ApiActor $actor): string
{
    return (string) $actor->getAuthIdentifier();
}

/** The base path every route on this surface hangs off. */
function api(string $path = ''): string
{
    return rtrim('/api/invoicing/'.ltrim($path, '/'), '/');
}

function line(string $description = 'A widget', int $netMinor = 1000, int $rateBp = 2000, int $taxMinor = 200, int $quantityMilli = 1000, string $currency = 'GBP'): Line
{
    return new Line(
        $description,
        $quantityMilli,
        new Money($netMinor, $currency),
        new Money($netMinor, $currency),
        $rateBp,
        new Money($taxMinor, $currency),
        new Money($netMinor + $taxMinor, $currency),
    );
}

/**
 * Offer a sale to the module through the seam, the way a host's adapter would.
 *
 * @param  list<Line>|null  $lines
 */
function bindSale(string $tenant = 'merchant-a', string $saleRef = 'order-1', ?array $lines = null, ?string $buyerRef = null, ?Money $statedGross = null): FakeSaleSource
{
    $source = Config::get('invoices-and-documents.seams.sale');
    $source = $source instanceof FakeSaleSource ? $source : new FakeSaleSource();

    $source->offer(
        $tenant,
        $saleRef,
        $lines ?? [line()],
        $buyerRef === null ? null : new Party($buyerRef, 'A Buyer', '2 Home Road', null, $buyerRef.'@example.test'),
        $statedGross,
    );

    Config::set('invoices-and-documents.seams.sale', $source);

    return $source;
}

function bindRenderer(bool $declines = false): FakeRenderer
{
    $renderer = new FakeRenderer($declines);
    Config::set('invoices-and-documents.seams.renderer', $renderer);

    return $renderer;
}

function bindTransport(?TransportOutcome $answer = null): FakeTransport
{
    $transport = new FakeTransport($answer);
    Config::set('invoices-and-documents.seams.transport', $transport);

    return $transport;
}

/** Open a numbering series through the API. */
function openSeries(ApiActor $operator, string $code = 'INV', array $overrides = []): void
{
    test()->actingAs($operator)->postJson(api('series'), array_merge([
        'code' => $code,
        'prefix' => $code.'-',
        'pad' => 5,
    ], $overrides));
}

/**
 * Draft a document through the API, offering the sale through the seam first,
 * and hand back the reference it was minted under.
 *
 * @param  list<Line>|null  $lines
 */
function drafted(ApiActor $operator, string $saleRef = 'order-1', string $kind = 'invoice', ?array $lines = null, ?string $buyerRef = null): string
{
    bindSale((string) $operator->getAttribute('team_id'), $saleRef, $lines, $buyerRef);

    return (string) test()->actingAs($operator)->postJson(api('documents'), [
        'kind' => $kind,
        'source_ref' => $saleRef,
    ])->json('data.reference');
}

/** @param  list<Line>|null  $lines */
function issued(ApiActor $operator, string $saleRef = 'order-1', string $kind = 'invoice', ?array $lines = null, ?string $buyerRef = null, string $code = 'INV'): string
{
    $reference = drafted($operator, $saleRef, $kind, $lines, $buyerRef);
    openSeries($operator, $code);

    test()->actingAs($operator)->postJson(api("documents/{$reference}/issuances"), ['series' => $code]);

    return $reference;
}

/**
 * A credit note line, in the shape the wire takes: every figure stated, because
 * this module adds and never divides.
 *
 * @return array<string, mixed>
 */
function creditLine(int $netMinor = 1000, int $taxMinor = 200, string $currency = 'GBP'): array
{
    return [
        'description' => 'A refunded widget',
        'quantity_milli' => 1000,
        'currency' => $currency,
        'exponent' => 2,
        'unit_net_minor' => $netMinor,
        'net_minor' => $netMinor,
        'tax_rate_basis_points' => 2000,
        'tax_minor' => $taxMinor,
        'gross_minor' => $netMinor + $taxMinor,
    ];
}

/** A sale the seam knows about and that has nothing on it. */
function bindEmptySale(string $tenant = 'merchant-a', string $saleRef = 'order-empty'): void
{
    $source = Config::get('invoices-and-documents.seams.sale');
    $source = $source instanceof FakeSaleSource ? $source : new FakeSaleSource();

    $source->sales[$tenant.'/'.$saleRef] = new Sale(
        $saleRef,
        new Party('seller-1', 'Merchant Ltd', '1 Trade Street'),
        new Party('person-1', 'A Buyer', '2 Home Road'),
        [],
        Money::zero('GBP'),
        Money::zero('GBP'),
        Money::zero('GBP'),
    );

    Config::set('invoices-and-documents.seams.sale', $source);
}

/** A sale whose buyer has no contact address at all. */
function bindSaleWithoutContact(string $tenant = 'merchant-a', string $saleRef = 'order-1'): void
{
    $source = Config::get('invoices-and-documents.seams.sale');
    $source = $source instanceof FakeSaleSource ? $source : new FakeSaleSource();

    $source->offer($tenant, $saleRef, [line()], new Party('person-1', 'A Buyer', '2 Home Road'));

    Config::set('invoices-and-documents.seams.sale', $source);
}
