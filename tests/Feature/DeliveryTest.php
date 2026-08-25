<?php

declare(strict_types=1);

use Liberu\Ecommerce\InvoicesAndDocuments\Data\TransportOutcome;

/*
 * Delivered is what a transport answered, never what dispatching implied. The
 * host shipped a mailable nothing constructed and had no fact anywhere.
 */
it('records a delivery and moves the document to delivered when a transport says sent', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');
    $transport = bindTransport();

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email'])
        ->assertStatus(201)
        ->assertJsonPath('data.recording', 'recorded')
        ->assertJsonPath('data.reference', 'send-1');

    expect($transport->delivered)->toBe(1)
        ->and($transport->sawAddress)->toBe('buyer@example.test')
        ->and($transport->sawRendered)->toBeNull();

    $this->actingAs($operator)->getJson(api("documents/{$reference}"))->assertJsonPath('data.state', 'delivered');
});

/*
 * The attempt is a row before it is a transmission, so a retried send cannot
 * transmit twice.
 */
it('answers a repeated delivery reference as already recorded and transmits once', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');
    $transport = bindTransport();
    $body = ['reference' => 'send-1', 'channel' => 'email'];

    $this->actingAs($operator)->postJson(api("documents/{$reference}/deliveries"), $body)->assertStatus(201);
    $this->actingAs($operator)->postJson(api("documents/{$reference}/deliveries"), $body)
        ->assertStatus(200)
        ->assertJsonPath('data.recording', 'already_recorded');

    expect($transport->delivered)->toBe(1);
});

/*
 * With nothing bound the attempt is recorded as pending and the transmission is
 * refused by name. It is **not** resubmittable: the domain writes the attempt row
 * before it asks the transport, so this delivery reference is already spent and
 * repeating the call would answer `already_recorded` rather than sending.
 */
it('refuses to transmit when nothing is bound, and says the reference is spent', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'no_transport_bound')
        ->assertJsonPath('error.resubmittable', false);

    $this->actingAs($operator)->getJson(api("documents/{$reference}"))->assertJsonPath('data.state', 'issued');

    bindTransport();

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email'])
        ->assertStatus(200)
        ->assertJsonPath('data.recording', 'already_recorded');
});

it('publishes a transport that failed and a transport that suppressed as different answers', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');

    bindTransport(TransportOutcome::failed('mailbox full'));

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'transport_failed');

    bindTransport(TransportOutcome::suppressed('unsubscribed'));

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-2', 'channel' => 'email'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'transport_suppressed');

    $this->actingAs($operator)->getJson(api("documents/{$reference}"))->assertJsonPath('data.state', 'issued');
});

it('hands the transport the rendered file when a renderer is bound', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');
    $transport = bindTransport();
    bindRenderer();

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email'])
        ->assertStatus(201);

    expect($transport->sawRendered)->not->toBeNull();
});

it('refuses a delivery with no address and no buyer email to fall back on', function () {
    $operator = operator();
    bindSaleWithoutContact();

    $reference = $this->actingAs($operator)
        ->postJson(api('documents'), ['kind' => 'invoice', 'source_ref' => 'order-1'])
        ->json('data.reference');

    openSeries($operator, 'INV');
    $this->actingAs($operator)->postJson(api("documents/{$reference}/issuances"), ['series' => 'INV']);
    bindTransport();

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'no_delivery_address');
});

it('refuses to deliver a document that has not been issued', function () {
    $operator = operator();
    $reference = drafted($operator, 'order-1');
    bindTransport();

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), ['reference' => 'send-1', 'channel' => 'email'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_issued');
});

it('takes a delivery address stated by the caller over the one the document froze', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');
    $transport = bindTransport();

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/deliveries"), [
            'reference' => 'send-1',
            'channel' => 'email',
            'address' => 'accounts@example.test',
        ])
        ->assertStatus(201);

    expect($transport->sawAddress)->toBe('accounts@example.test');
});
