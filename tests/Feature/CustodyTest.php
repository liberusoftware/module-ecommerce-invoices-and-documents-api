<?php

declare(strict_types=1);

use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;

/*
 * The host dropped the ownership filter entirely for `hasRole(['super_admin',
 * 'admin'])`, so one merchant's admin read every merchant's invoices on any
 * host. A role name is not a merchant, and this surface never learns one.
 */
it('never lets one merchant read another merchant\'s documents', function () {
    issued(operator('merchant-b'), 'order-1');
    issued(operator('merchant-b'), 'order-2');
    $mine = issued(operator('merchant-a'), 'order-3');

    $this->actingAs(operator('merchant-a'))
        ->getJson(api('documents'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $mine);
});

/*
 * A count over a tenant-restated relation has to be the right non-zero number.
 * The guarded-restatement bug reports zero for everything and looks exactly like
 * isolation working, which is why the assertion is on the figure and not on the
 * scoping.
 */
it('counts the right non-zero number of frozen lines through the restated relation', function () {
    $operator = operator('merchant-a');
    issued(operator('merchant-b'), 'order-1', 'invoice', [line('Theirs')]);
    $mine = issued($operator, 'order-2', 'invoice', [line('One'), line('Two'), line('Three')]);

    $lines = $this->actingAs($operator)->getJson(api("documents/{$mine}"))->assertOk()->json('data.lines');

    expect($lines)->toHaveCount(3)
        ->and(array_column($lines, 'description'))->toBe(['One', 'Two', 'Three']);
});

/*
 * The natural key is scoped to the merchant. Two merchants using the same order
 * reference each get their own document, and neither is handed the other's — the
 * wave-16 defect, where a second subject holding an identical reference was
 * answered with the first subject's row.
 */
it('gives two merchants their own document for a deliberately identical sale reference', function () {
    $a = drafted(operator('merchant-a'), 'order-shared');
    $b = drafted(operator('merchant-b'), 'order-shared');

    expect($a)->not->toBe($b);

    $this->actingAs(operator('merchant-a'))->getJson(api("documents/{$a}"))->assertOk();
    $this->actingAs(operator('merchant-a'))->getJson(api("documents/{$b}"))->assertStatus(404);
});

it('ignores a merchant identifier sent in a body', function () {
    bindSale('merchant-a', 'order-1');

    $reference = $this->actingAs(operator('merchant-a'))
        ->postJson(api('documents'), [
            'kind' => 'invoice',
            'source_ref' => 'order-1',
            'tenant_id' => 'merchant-b',
            'team_id' => 'merchant-b',
        ])
        ->assertStatus(201)
        ->json('data.reference');

    $this->actingAs(operator('merchant-b'))->getJson(api("documents/{$reference}"))->assertStatus(404);
    $this->actingAs(operator('merchant-a'))->getJson(api("documents/{$reference}"))->assertOk();
});

/*
 * The shopper side. A document belonging to another buyer, one belonging to
 * another merchant, one whose buyer has been erased and one nobody minted are
 * one answer with one body.
 */
it('shows a buyer only the documents issued to that buyer', function () {
    $operator = operator('merchant-a');
    $reader = actor([Scope::READ], 'merchant-a');

    $mine = issued($operator, 'order-1', 'invoice', null, subjectOf($reader));
    $theirs = issued($operator, 'order-2', 'invoice', null, 'somebody-else');

    $this->actingAs($reader)
        ->getJson(api('my-documents'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $mine);

    $this->actingAs($reader)->getJson(api("my-documents/{$mine}"))->assertOk();

    $unknown = $this->actingAs($reader)->getJson(api('my-documents/nobody-minted-this'))->assertStatus(404);
    $other = $this->actingAs($reader)->getJson(api("my-documents/{$theirs}"))->assertStatus(404);

    expect($other->json())->toBe($unknown->json());
});

it('refuses a buyer the document of a merchant their credential is not attached to', function () {
    $reader = actor([Scope::READ], 'merchant-a');
    $theirs = issued(operator('merchant-b'), 'order-1', 'invoice', null, subjectOf($reader));

    $this->actingAs($reader)->getJson(api("my-documents/{$theirs}"))->assertStatus(404);
});
