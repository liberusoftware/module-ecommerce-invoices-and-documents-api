<?php

declare(strict_types=1);

it('opens a series and answers a second opening as already recorded', function () {
    $operator = operator();

    $this->actingAs($operator)
        ->postJson(api('series'), ['code' => 'INV', 'prefix' => 'INV-', 'pad' => 5])
        ->assertStatus(201)
        ->assertJsonPath('data.recording', 'recorded')
        ->assertJsonPath('data.reference', 'INV');

    $this->actingAs($operator)
        ->postJson(api('series'), ['code' => 'INV'])
        ->assertStatus(200)
        ->assertJsonPath('data.recording', 'already_recorded');
});

/*
 * The alarm the runbook says cannot wait. Nothing in the domain can fill a hole
 * in afterwards, so a gapless series with anything in `missing` is a fact an
 * operator needs on the face of the answer.
 */
it('reconciles what a series has spent against the documents and burns accounting for it', function () {
    $operator = operator();
    issued($operator, 'order-1');
    issued($operator, 'order-2');

    $this->actingAs($operator)
        ->getJson(api('series/INV/continuity'))
        ->assertOk()
        ->assertJsonPath('data.series', 'INV')
        ->assertJsonPath('data.gapless', true)
        ->assertJsonPath('data.continuous', true)
        ->assertJsonPath('data.issued', 2)
        ->assertJsonPath('data.burned', 0)
        ->assertJsonPath('data.first', 1)
        ->assertJsonPath('data.last', 2)
        ->assertJsonPath('data.missing', []);
});

/*
 * Gaplessness is a policy of the series, not an invariant of the module: the
 * jurisdictions differ. A gapless series will not spend a number on nothing; any
 * other one records the burn rather than leaving a hole nobody can explain.
 */
it('refuses to burn a number from a gapless series', function () {
    $operator = operator();
    openSeries($operator, 'INV');

    $this->actingAs($operator)
        ->postJson(api('series/INV/burned-numbers'), ['reason' => 'A printer ate it.'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'series_is_gapless')
        ->assertJsonPath('error.resubmittable', false);
});

it('records a burned number on a series that allows one', function () {
    $operator = operator();
    openSeries($operator, 'PRO', ['gapless' => false, 'fiscal' => false, 'prefix' => 'PRO-']);

    $this->actingAs($operator)
        ->postJson(api('series/PRO/burned-numbers'), ['reason' => 'A printer ate it.'])
        ->assertStatus(201)
        ->assertJsonPath('data.recording', 'recorded')
        ->assertJsonPath('data.reference', 'PRO-00001');

    $this->actingAs($operator)
        ->getJson(api('series/PRO/continuity'))
        ->assertOk()
        ->assertJsonPath('data.burned', 1)
        ->assertJsonPath('data.continuous', true)
        ->assertJsonPath('data.missing', []);
});

it('answers a series code this merchant has not opened as not found', function () {
    $operator = operator();
    openSeries(operator('merchant-b'), 'THEIRS');

    $mine = $this->actingAs($operator)->getJson(api('series/NOBODY/continuity'))->assertStatus(404);
    $theirs = $this->actingAs($operator)->getJson(api('series/THEIRS/continuity'))->assertStatus(404);

    expect($theirs->json())->toBe($mine->json());

    $this->actingAs($operator)
        ->postJson(api('series/THEIRS/burned-numbers'), ['reason' => 'x'])
        ->assertStatus(404);
});

it('numbers a proforma from a non-fiscal series', function () {
    $operator = operator();
    openSeries($operator, 'PRO', ['fiscal' => false, 'prefix' => 'PRO-']);
    $reference = drafted($operator, 'order-1', 'proforma');

    $this->actingAs($operator)
        ->postJson(api("documents/{$reference}/issuances"), ['series' => 'PRO'])
        ->assertStatus(201);

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}"))
        ->assertJsonPath('data.number', 'PRO-00001');
});
