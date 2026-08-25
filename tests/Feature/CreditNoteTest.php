<?php

declare(strict_types=1);

/*
 * A document is corrected by another document, never by an edit. There is no
 * PUT and no PATCH on this surface; this is what replaces them.
 */
it('corrects an issued document with a credit note that references it', function () {
    $operator = operator();
    $invoice = issued($operator, 'order-1');

    $reference = $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), [
            'source_ref' => 'refund-1',
            'note' => 'Returned unopened.',
            'lines' => [creditLine(400, 80)],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.recording', 'recorded')
        ->json('data.reference');

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}"))
        ->assertOk()
        ->assertJsonPath('data.kind', 'credit_note')
        ->assertJsonPath('data.corrects.reference', $invoice)
        ->assertJsonPath('data.corrects.number', 'INV-00001')
        ->assertJsonPath('data.summary.gross.decimal', '4.80')
        ->assertJsonPath('data.buyer.name', 'A Buyer');
});

it('answers a repeated credit note for the same refund as already recorded', function () {
    $operator = operator();
    $invoice = issued($operator, 'order-1');
    $body = ['source_ref' => 'refund-1', 'lines' => [creditLine(400, 80)]];

    $first = $this->actingAs($operator)->postJson(api("documents/{$invoice}/credit-notes"), $body)->assertStatus(201);

    $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), $body)
        ->assertStatus(200)
        ->assertJsonPath('data.recording', 'already_recorded')
        ->assertJsonPath('data.reference', $first->json('data.reference'));
});

it('refuses to credit a document that has not been issued', function () {
    $operator = operator();
    $draft = drafted($operator, 'order-1');

    $this->actingAs($operator)
        ->postJson(api("documents/{$draft}/credit-notes"), ['source_ref' => 'refund-1', 'lines' => [creditLine()]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_issued');
});

it('refuses to credit a credit note', function () {
    $operator = operator();
    $invoice = issued($operator, 'order-1');

    $note = $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), ['source_ref' => 'refund-1', 'lines' => [creditLine(400, 80)]])
        ->json('data.reference');

    $this->actingAs($operator)
        ->postJson(api("documents/{$note}/credit-notes"), ['source_ref' => 'refund-2', 'lines' => [creditLine(100, 20)]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_correctable');
});

/* Counting every credit note already issued against it, not just this one. */
it('refuses credit notes that would come to more than the document they correct', function () {
    $operator = operator();
    $invoice = issued($operator, 'order-1');

    $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), ['source_ref' => 'refund-1', 'lines' => [creditLine(1000, 200)]])
        ->assertStatus(201);

    $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), ['source_ref' => 'refund-2', 'lines' => [creditLine(100, 20)]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'exceeds_corrected_document')
        ->assertJsonPath('error.resubmittable', false);
});

it('refuses a credit note in a currency the document does not carry', function () {
    $operator = operator();
    $invoice = issued($operator, 'order-1');

    $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), ['source_ref' => 'refund-1', 'lines' => [creditLine(400, 80, 'EUR')]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'mixed_currencies');
});

/* A currency code that is not one at all never reaches the domain as a figure. */
it('refuses an amount whose currency is not an ISO 4217 code', function () {
    $operator = operator();
    $invoice = issued($operator, 'order-1');

    $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), ['source_ref' => 'refund-1', 'lines' => [creditLine(400, 80, 'pounds')]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'money_mismatch')
        ->assertJsonPath('error.resubmittable', true);
});

it('requires at least one line on a credit note', function () {
    $operator = operator();
    $invoice = issued($operator, 'order-1');

    $this->actingAs($operator)
        ->postJson(api("documents/{$invoice}/credit-notes"), ['source_ref' => 'refund-1', 'lines' => []])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});
