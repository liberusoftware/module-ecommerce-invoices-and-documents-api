<?php

declare(strict_types=1);

use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;

it('drafts a document and names it by reference, never by a database key', function () {
    bindSale();

    $response = $this->actingAs(operator())
        ->postJson(api('documents'), ['kind' => 'invoice', 'source_ref' => 'order-1', 'note' => 'Thank you.'])
        ->assertStatus(201)
        ->assertJsonPath('data.recording', 'recorded');

    expect($response->json('data.reference'))->toBeString()->not->toBe('')
        ->and(json_encode($response->json()))->not->toContain('"id"');
});

/*
 * The cause is the natural key. There is no idempotency key on this surface,
 * because a key the client holds is a key the client can change and the database
 * already arbitrates the one that matters.
 */
it('answers a repeated draft of the same sale with the reference it already minted', function () {
    $operator = operator();
    $first = drafted($operator);

    $this->actingAs($operator)
        ->postJson(api('documents'), ['kind' => 'invoice', 'source_ref' => 'order-1'])
        ->assertStatus(200)
        ->assertJsonPath('data.recording', 'already_recorded')
        ->assertJsonPath('data.reference', $first);
});

/*
 * The blast radius of an unbound sale source, on the wire: nothing is written
 * and the refusal says which seam is missing. It never drafts an empty document.
 */
it('refuses to draft when nothing is bound to read a sale, and says so in the body', function () {
    $this->actingAs(operator())
        ->postJson(api('documents'), ['kind' => 'invoice', 'source_ref' => 'order-1'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'sale_source_unbound')
        ->assertJsonPath('error.resubmittable', true);
});

it('refuses to draft a credit note through the endpoint that reads a sale', function () {
    bindSale();

    $this->actingAs(operator())
        ->postJson(api('documents'), ['kind' => 'credit_note', 'source_ref' => 'order-1'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'credit_note_requires_corrected_document');
});

it('refuses a sale the seam cannot describe', function () {
    bindSale('merchant-a', 'order-1');

    $this->actingAs(operator())
        ->postJson(api('documents'), ['kind' => 'invoice', 'source_ref' => 'order-nobody-has'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'sale_not_found');
});

/*
 * The guard the host never had. A header total its own lines do not add up to is
 * the state the host's invoice was born in.
 */
it('refuses a sale whose stated total disagrees with its own lines', function () {
    bindSale('merchant-a', 'order-1', [line()], null, new Money(999_99, 'GBP'));

    $this->actingAs(operator())
        ->postJson(api('documents'), ['kind' => 'invoice', 'source_ref' => 'order-1'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'stated_total_disagrees_with_lines')
        ->assertJsonPath('error.resubmittable', false);
});

it('refuses a sale with no lines', function () {
    bindEmptySale();

    $this->actingAs(operator())
        ->postJson(api('documents'), ['kind' => 'invoice', 'source_ref' => 'order-empty'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'sale_has_no_lines');
});

/*
 * Everything the document says comes from the document's own frozen rows, and
 * the merchant is not among them: it is derived from the credential, so echoing
 * it back would read as though a caller could have chosen it.
 */
it('publishes the frozen document, its lines and its per-rate tax block', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1', 'invoice', [line('A widget'), line('A second widget', 500, 500, 25)]);

    $response = $this->actingAs($operator)->getJson(api("documents/{$reference}"))->assertOk();

    expect($response->json('data.lines'))->toHaveCount(2)
        ->and($response->json('data.lines.0.description'))->toBe('A widget')
        ->and($response->json('data.lines.0.quantity'))->toBe('1')
        ->and($response->json('data.summary.gross'))->toBe(['minor' => 1725, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '17.25'])
        ->and($response->json('data.summary.by_rate'))->toHaveCount(2)
        ->and($response->json('data.number'))->toBe('INV-00001')
        ->and($response->json('data.buyer.email'))->toBe('buyer@example.test')
        ->and(json_encode($response->json()))->not->toContain('tenant_id');
});

it('answers an unknown reference and another merchant\'s reference with the same body', function () {
    $reference = issued(operator('merchant-b'), 'order-1');

    $mine = $this->actingAs(operator('merchant-a'))->getJson(api('documents/nobody-minted-this'))->assertStatus(404);
    $theirs = $this->actingAs(operator('merchant-a'))->getJson(api("documents/{$reference}"))->assertStatus(404);

    expect($theirs->json())->toBe($mine->json())
        ->and($mine->json('error.code'))->toBe('not_found');
});

it('lists this merchant\'s documents and filters them by kind and by state', function () {
    $operator = operator();
    issued($operator, 'order-1', 'invoice');
    drafted($operator, 'order-2', 'receipt');
    drafted($operator, 'order-3', 'proforma');

    $this->actingAs($operator)->getJson(api('documents'))->assertOk()->assertJsonCount(3, 'data');
    $this->actingAs($operator)->getJson(api('documents?kind=receipt'))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($operator)->getJson(api('documents?state=issued'))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($operator)->getJson(api('documents?kind=invoice&state=draft'))->assertOk()->assertJsonCount(0, 'data');
});

/* A listing carries the buyer's name because that is what an invoice list is; it carries no address and no email. */
it('keeps contact details off the listing', function () {
    $operator = operator();
    issued($operator);

    $listed = $this->actingAs($operator)->getJson(api('documents'))->assertOk()->json('data.0');

    expect($listed['buyer_name'])->toBe('A Buyer')
        ->and($listed)->not->toHaveKey('buyer')
        ->and(json_encode($listed))->not->toContain('example.test')
        ->not->toContain('Home Road');
});

it('issues a fiscal document only under a series, and spends the number then', function () {
    $operator = operator();
    $reference = drafted($operator);

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/issuances"), [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'series_required');

    openSeries($operator, 'INV');

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/issuances"), ['series' => 'INV'])
        ->assertStatus(201)
        ->assertJsonPath('data.recording', 'recorded');

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}"))
        ->assertJsonPath('data.number', 'INV-00001')
        ->assertJsonPath('data.state', 'issued');
});

it('answers a second issuance of the same document as already recorded', function () {
    $operator = operator();
    $reference = issued($operator);

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/issuances"), ['series' => 'INV'])
        ->assertStatus(200)
        ->assertJsonPath('data.recording', 'already_recorded');
});

it('refuses a series this merchant has not opened', function () {
    $operator = operator();
    $reference = drafted($operator);

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/issuances"), ['series' => 'NOPE'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'series_not_found');
});

/* A proforma is a quotation with a document's shape, and may not take a fiscal number. */
it('refuses to file a proforma under a fiscal series and issues it unnumbered without one', function () {
    $operator = operator();
    openSeries($operator, 'INV');
    $reference = drafted($operator, 'order-1', 'proforma');

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/issuances"), ['series' => 'INV'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'proforma_may_not_use_fiscal_series');

    $this->actingAs($operator)->postJson(api("documents/{$reference}/issuances"), [])->assertStatus(201);

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}"))
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.state', 'issued');
});

/* Void records; it does not erase. The number stays spent. */
it('voids a document, keeps its number, and answers a second void as already recorded', function () {
    $operator = operator();
    $reference = issued($operator);

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/voidings"), ['reason' => 'Issued in error.'])
        ->assertStatus(201);

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}"))
        ->assertJsonPath('data.state', 'void')
        ->assertJsonPath('data.number', 'INV-00001');

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/voidings"), ['reason' => 'Again.'])
        ->assertStatus(200)
        ->assertJsonPath('data.recording', 'already_recorded');
});

it('refuses every write against a document that is not this merchant\'s', function () {
    $reference = issued(operator('merchant-b'));
    $mine = operator('merchant-a');

    $this->actingAs($mine)->postJson(api("documents/{$reference}/issuances"), [])->assertStatus(404);
    $this->actingAs($mine)->postJson(api("documents/{$reference}/voidings"), ['reason' => 'x'])->assertStatus(404);
    $this->actingAs($mine)->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'd-1', 'channel' => 'email'])->assertStatus(404);
    $this->actingAs($mine)->getJson(api("documents/{$reference}/rendition"))->assertStatus(404);
    $this->actingAs($mine)->postJson(api("documents/{$reference}/credit-notes"), [
        'source_ref' => 'refund-1',
        'lines' => [creditLine()],
    ])->assertStatus(404);
});
