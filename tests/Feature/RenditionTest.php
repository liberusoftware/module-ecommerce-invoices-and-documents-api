<?php

declare(strict_types=1);

/*
 * The blast radius of an unbound renderer is exactly the file and nothing else.
 * So a rendition with nothing bound is a 200 that says so and still carries
 * everything the document says — never an empty file, and never a read that
 * fails because a deployment has not chosen a PDF library.
 */
it('answers with the document and no file when no renderer is bound', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}/rendition"))
        ->assertOk()
        ->assertJsonPath('data.rendered', false)
        ->assertJsonPath('data.unavailable_reason', 'no_renderer_bound')
        ->assertJsonPath('data.rendition', null)
        ->assertJsonPath('data.document.number', 'INV-00001')
        ->assertJsonPath('data.document.summary.gross.decimal', '12.00');
});

/* Declined is a different answer from unasked. */
it('distinguishes a renderer that declined from no renderer at all', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');
    bindRenderer(true);

    $this->actingAs($operator)
        ->getJson(api("documents/{$reference}/rendition"))
        ->assertOk()
        ->assertJsonPath('data.rendered', false)
        ->assertJsonPath('data.unavailable_reason', 'renderer_declined');
});

it('publishes the file a bound renderer produced', function () {
    $operator = operator();
    $reference = issued($operator, 'order-1');
    bindRenderer();

    $response = $this->actingAs($operator)->getJson(api("documents/{$reference}/rendition"))->assertOk();

    expect($response->json('data.rendered'))->toBeTrue()
        ->and($response->json('data.unavailable_reason'))->toBeNull()
        ->and($response->json('data.rendition.media_type'))->toBe('application/pdf')
        ->and($response->json('data.rendition.filename'))->toBe('INV-00001.pdf')
        ->and(base64_decode((string) $response->json('data.rendition.contents_base64'), true))->toBe('bytes');
});
