<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures\ApiActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures\SelfOnlyProbe;

it('refuses an unauthenticated caller', function () {
    $this->getJson(api('documents'))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('error.resubmittable', false);
});

it('refuses a credential that does not carry the ability', function () {
    $this->actingAs(actor([Scope::READ]))
        ->getJson(api('documents'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');
});

it('refuses a credential attached to no merchant', function () {
    $this->actingAs(ApiActor::query()->create(['team_id' => null, 'abilities' => [Scope::OPERATE]]))
        ->getJson(api('documents'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'actor_has_no_tenant');
});

it('refuses a credential that resolves to no person', function () {
    Config::set('invoices-and-documents-api.actor.subject_attribute', 'person_ref');

    $this->actingAs(operator())
        ->getJson(api('documents'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'actor_has_no_subject');
});

it('reads the subject from the attribute a host names for it', function () {
    issued(operator(), 'order-1', 'invoice', null, 'person-7');

    Config::set('invoices-and-documents-api.actor.subject_attribute', 'person_ref');

    $reader = ApiActor::query()->create([
        'team_id' => 'merchant-a',
        'person_ref' => 'person-7',
        'abilities' => [Scope::READ],
    ]);

    $this->actingAs($reader)
        ->getJson(api('my-documents'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/*
 * A method absent from the scope map is refused rather than allowed. An
 * unanswered authorization question is not a yes.
 */
it('refuses an action that publishes no ability', function () {
    Route::get('probe-nothing', [SelfOnlyProbe::class, 'nothing']);

    $this->actingAs(actor([Scope::READ]))
        ->getJson('/probe-nothing')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');
});

/*
 * The guard that keeps a copied method signature from widening a shopper
 * endpoint into one that acts on strangers. No route in this package reaches it;
 * the probe exists so it is asserted rather than assumed.
 */
it('refuses to read a caller-supplied subject under a self-only ability', function () {
    Route::get('probe-subject', [SelfOnlyProbe::class, 'probe']);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(actor([Scope::READ]))->getJson('/probe-subject'))
        ->toThrow(LogicException::class);
});

it('rejects a body this endpoint does not accept', function () {
    $this->actingAs(operator())
        ->postJson(api('documents'), ['kind' => 'not-a-kind', 'source_ref' => 'order-1'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['kind']]]);
});
