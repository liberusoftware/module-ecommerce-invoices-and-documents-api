<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;

/*
 * A subject-access request is about a person and not about a merchant, and the
 * host got that backwards: it redacted the customer row in place and rewrote
 * every invoice ever issued to them. The domain's export and erasure are
 * therefore person-wide across every tenant — which is why the ability is
 * `invoicing:platform-privacy` and not a merchant's.
 */
it('exports one person\'s documents across every merchant, naming each merchant', function () {
    issued(operator('merchant-a'), 'order-1', 'invoice', null, 'person-7');
    issued(operator('merchant-b'), 'order-2', 'invoice', null, 'person-7');
    issued(operator('merchant-a'), 'order-3', 'invoice', null, 'somebody-else');

    $documents = $this->actingAs(platform('merchant-a'))
        ->postJson(api('subject-records'), ['subject_ref' => 'person-7'])
        ->assertOk()
        ->assertJsonPath('data.subject_ref', 'person-7')
        ->json('data.documents');

    expect($documents)->toHaveCount(2)
        ->and(array_column($documents, 'tenant_id'))->toBe(['merchant-a', 'merchant-b'])
        ->and($documents[0]['total'])->toBe(['minor' => 1200, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '12.00'])
        ->and($documents[0]['redacted'])->toBeFalse();
});

it('answers a subject it holds nothing about with an empty list rather than a 404', function () {
    $this->actingAs(platform())
        ->postJson(api('subject-records'), ['subject_ref' => 'nobody-here'])
        ->assertOk()
        ->assertJsonPath('data.documents', []);
});

it('refuses a merchant credential both privacy endpoints', function () {
    $this->actingAs(operator())->postJson(api('subject-records'), ['subject_ref' => 'person-7'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');

    $this->actingAs(actor([Scope::READ]))->postJson(api('erasures'), ['subject_ref' => 'person-7'])
        ->assertStatus(403);
});

/*
 * Retention outranks erasure, and the conflict is a fact in the body rather
 * than a silent resolution in either direction. Each refusal names the document,
 * its number and the date its window ends.
 */
it('refuses to redact the buyer on a document still inside its retention window, by name', function () {
    Config::set('invoices-and-documents.retention.years', 6);
    issued(operator('merchant-a'), 'order-1', 'invoice', null, 'person-7');

    $body = $this->actingAs(platform())
        ->postJson(api('erasures'), ['subject_ref' => 'person-7'])
        ->assertOk()
        ->assertJsonPath('data.complete', false)
        ->assertJsonPath('data.redacted_documents', [])
        ->json('data');

    expect($body['refused_documents'])->toHaveCount(1)
        ->and($body['refused_documents'][0]['tenant_id'])->toBe('merchant-a')
        ->and($body['refused_documents'][0]['number'])->toBe('INV-00001')
        ->and($body['refused_documents'][0]['window_is_unknown'])->toBeFalse()
        ->and($body['refused_documents'][0]['retain_until'])->toBeString()
        ->and($body['redacted_contacts'])->toBe(1);
});

/*
 * A window the host never configured is unknown, which is not zero. Guessing in
 * either direction is how the host came to rewrite its own statutory records.
 */
it('refuses on an unknown retention window and says the window is unknown', function () {
    issued(operator('merchant-a'), 'order-1', 'invoice', null, 'person-7');

    $body = $this->actingAs(platform())
        ->postJson(api('erasures'), ['subject_ref' => 'person-7'])
        ->assertOk()
        ->json('data');

    expect($body['complete'])->toBeFalse()
        ->and($body['refused_documents'][0]['window_is_unknown'])->toBeTrue()
        ->and($body['refused_documents'][0]['retain_until'])->toBeNull();
});

it('redacts a document that was never issued, because it has no retention', function () {
    $operator = operator('merchant-a');
    $reference = drafted($operator, 'order-1', 'invoice', null, 'person-7');

    $this->actingAs(platform())
        ->postJson(api('erasures'), ['subject_ref' => 'person-7'])
        ->assertOk()
        ->assertJsonPath('data.complete', true)
        ->assertJsonPath('data.redacted_documents', [$reference])
        ->assertJsonPath('data.refused_documents', []);

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}"))
        ->assertOk()
        ->assertJsonPath('data.buyer.name', 'redacted')
        ->assertJsonPath('data.buyer.email', null);
});

/* The contact details and the delivery addresses always go, refusal or not. Money never changes. */
it('redacts contacts and delivery addresses even where retention refuses the identity', function () {
    $operator = operator('merchant-a');
    $reference = issued($operator, 'order-1', 'invoice', null, 'person-7');
    bindTransport();
    $this->actingAs($operator)->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email']);

    $before = $this->actingAs($operator)->getJson(api("documents/{$reference}"))->json('data.summary');

    $this->actingAs(platform())
        ->postJson(api('erasures'), ['subject_ref' => 'person-7'])
        ->assertOk()
        ->assertJsonPath('data.complete', false)
        ->assertJsonPath('data.redacted_contacts', 1)
        ->assertJsonPath('data.redacted_deliveries', 1);

    $after = $this->actingAs($operator)->getJson(api("documents/{$reference}"))->assertOk();

    expect($after->json('data.summary'))->toBe($before)
        ->and($after->json('data.buyer.email'))->toBeNull()
        ->and($after->json('data.buyer.name'))->toBe('A Buyer');
});

/*
 * The sharpest property of this pair, asserted rather than assumed: the merchant
 * on the credential does not narrow the erasure. A platform credential attached
 * to merchant A erases the person at merchant B too, because that is what the
 * domain's action does and hiding half of its effect would be worse than
 * publishing it.
 */
it('does not narrow an erasure to the merchant on the credential', function () {
    drafted(operator('merchant-a'), 'order-1', 'invoice', null, 'person-7');
    drafted(operator('merchant-b'), 'order-2', 'invoice', null, 'person-7');

    $this->actingAs(platform('merchant-a'))
        ->postJson(api('erasures'), ['subject_ref' => 'person-7'])
        ->assertOk()
        ->assertJsonCount(2, 'data.redacted_documents');
});

it('leaves a redacted buyer unable to read their own document', function () {
    $operator = operator('merchant-a');
    $reader = actor([Scope::READ], 'merchant-a');
    $reference = drafted($operator, 'order-1', 'invoice', null, subjectOf($reader));

    $this->actingAs($reader)->getJson(api("my-documents/{$reference}"))->assertOk();

    $this->actingAs(platform())->postJson(api('erasures'), ['subject_ref' => subjectOf($reader)])->assertOk();

    $this->actingAs($reader)->getJson(api("my-documents/{$reference}"))->assertStatus(404);
});

it('requires a subject reference on both privacy endpoints', function () {
    $this->actingAs(platform())->postJson(api('subject-records'), [])->assertStatus(422);
    $this->actingAs(platform())->postJson(api('erasures'), [])->assertStatus(422);
});
