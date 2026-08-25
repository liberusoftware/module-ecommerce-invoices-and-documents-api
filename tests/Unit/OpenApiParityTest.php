<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers\Controller;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Failure;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\OpenApi;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\RefusalReason;

/**
 * Every route this package registers, as `METHOD /path` relative to the mount.
 *
 * `HEAD` is dropped: Laravel synthesises one per `GET` and OpenAPI cannot
 * describe it, so requiring an operation for it would fail parity over a routing
 * detail rather than over anything anybody wrote.
 *
 * @return array<string, RoutingRoute>
 */
function routed(): array
{
    $prefix = 'api/invoicing';
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), $prefix)) {
            continue;
        }

        $path = '/'.ltrim(substr($route->uri(), strlen($prefix)), '/');

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $routes[strtolower($method).' '.rtrim($path, '/')] = $route;
        }
    }

    return $routes;
}

/** @return array<string, array<string, mixed>> */
function documented(): array
{
    $operations = [];

    foreach (OpenApi::document()['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            if ($method === 'parameters') {
                continue;
            }

            $operations[$method.' '.$path] = $operation;
        }
    }

    return $operations;
}

it('ships a valid OpenAPI 3.1 document', function () {
    $document = OpenApi::document();
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true);

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['info']['version'])->toBe($manifest['version'])
        ->and($document['paths'])->not->toBeEmpty();
});

it('documents every route it registers', function () {
    expect(array_values(array_diff(array_keys(routed()), array_keys(documented()))))->toBe([]);
});

it('registers a route for every operation it documents', function () {
    expect(array_values(array_diff(array_keys(documented()), array_keys(routed()))))->toBe([]);
});

it('documents the same ability the controller enforces', function () {
    $mismatched = [];
    $routes = routed();

    foreach (documented() as $key => $operation) {
        $controller = $routes[$key]->getController();

        expect($controller)->toBeInstanceOf(Controller::class);

        $enforced = $controller->scopes()[$routes[$key]->getActionMethod()] ?? null;
        $declared = $operation['security'][0]['bearer'][0] ?? null;

        if ($enforced !== $declared) {
            $mismatched[$key] = ['documented' => $declared, 'enforced' => $enforced];
        }
    }

    expect($mismatched)->toBe([]);
});

it('documents only abilities this package publishes', function () {
    foreach (documented() as $operation) {
        expect(Scope::all())->toContain($operation['security'][0]['bearer'][0]);
    }
});

it('gives every operation an identifier, a summary, a description and a tag', function () {
    $ids = [];
    $tags = array_column(OpenApi::document()['tags'], 'name');

    foreach (documented() as $key => $operation) {
        expect($operation)->toHaveKeys(['operationId', 'summary', 'description', 'tags', 'responses'], $key)
            ->and($tags)->toContain($operation['tags'][0]);

        $ids[] = $operation['operationId'];
    }

    expect(array_unique($ids))->toHaveCount(count($ids));
});

it('documents 401 and 403 on every operation, because every one is authenticated and scoped', function () {
    foreach (documented() as $key => $operation) {
        $statuses = array_map(strval(...), array_keys($operation['responses']));

        // `toContain` is variadic: a second argument is another needle and not a
        // message. One argument, always.
        expect($statuses)->toContain('401')
            ->and($statuses)->toContain('403');
    }
});

/*
 * The whole of decision 2, on the wire. An issued document is immutable, so
 * nothing here describes a way to change one or to remove one — and the document
 * says why, rather than leaving a reader to notice the absence.
 */
it('describes no way to update a document and no way to delete one', function () {
    foreach (array_keys(documented()) as $key) {
        [$method] = explode(' ', $key, 2);

        expect($method)->toBeIn(['get', 'post'], $key);
    }

    expect(OpenApi::document()['info']['description'])
        ->toContain('no update operation and no delete operation')
        ->toContain('credit note');
});

/*
 * Every code a caller can receive, published as an enum, and it is exactly the
 * union of the base controller's four credential refusals, its validation
 * failure, the three exceptions this surface maps, and every `RefusalReason` the
 * domain publishes. A reason added to the domain fails here as well as in the
 * failure map.
 */
it('publishes exactly the error codes this surface can produce', function () {
    $expected = ['unauthenticated', 'insufficient_scope', 'actor_has_no_tenant', 'actor_has_no_subject', 'validation_failed'];

    foreach (Failure::exceptions() as $mapping) {
        $expected[] = $mapping['code'];
    }

    foreach (RefusalReason::cases() as $reason) {
        $expected[] = $reason->value;
    }

    $expected = array_values(array_unique($expected));
    sort($expected);

    $documented = OpenApi::document()['components']['schemas']['Error']['properties']['error']['properties']['code']['enum'];
    sort($documented);

    expect($documented)->toBe($expected);
});

it('classifies every documented failure', function () {
    $error = OpenApi::document()['components']['schemas']['Error'];

    expect($error['properties']['error']['required'])->toContain('resubmittable');
});

/*
 * Nothing in this domain is transient: no rate limit of its own, no in-flight
 * claim to wait out. So no response carries a `Retry-After` and nothing in the
 * document invites a wait.
 */
it('promises no wait anywhere, because nothing here is transient', function () {
    $prose = strtolower((string) json_encode(OpenApi::document()));

    expect($prose)->not->toContain('try again')
        ->not->toContain('shortly')
        ->not->toContain('retry-after');

    foreach (OpenApi::document()['components']['responses'] as $response) {
        expect($response)->not->toHaveKey('headers');
    }

    foreach (documented() as $key => $operation) {
        $statuses = array_map(strval(...), array_keys($operation['responses']));

        expect($statuses)->not->toContain('429')
            ->and($statuses)->not->toContain('423');
    }
});

/*
 * No request accepts a merchant identifier — not in a path, not in a query
 * string, not in a body. One storefront's credential must not be able to file a
 * document into another business.
 */
it('accepts no merchant identifier in any request', function () {
    $tells = ['tenant_id', 'team_id', 'store_id', 'merchant_id'];

    foreach (OpenApi::document()['components']['parameters'] as $parameter) {
        expect($tells)->not->toContain($parameter['name']);
    }

    foreach (documented() as $key => $operation) {
        foreach ($operation['parameters'] ?? [] as $parameter) {
            expect($tells)->not->toContain($parameter['name'] ?? '');
        }
    }

    foreach (OpenApi::document()['components']['schemas'] as $name => $schema) {
        if (! str_ends_with((string) $name, 'Request')) {
            continue;
        }

        foreach (array_keys($schema['properties'] ?? []) as $property) {
            expect($tells)->not->toContain($property);
        }
    }
});

/*
 * The merchant leaves on exactly two response shapes, and both are person-wide
 * across merchants by the domain's own design. A privacy answer that will not
 * say whose document refused cannot be acted on; every other shape derives the
 * merchant from the credential and echoing it back would read as though a caller
 * could have chosen it.
 */
it('publishes the merchant on the two person-wide answers and on nothing else', function () {
    $carrying = [];

    foreach (OpenApi::document()['components']['schemas'] as $name => $schema) {
        if (array_key_exists('tenant_id', $schema['properties'] ?? [])) {
            $carrying[] = (string) $name;
        }
    }

    sort($carrying);

    expect($carrying)->toBe(['ExportedDocument', 'RetentionRefusal']);
});

/*
 * The decision recorded rather than copied. Every write here already has a
 * natural key the database enforces, so a client-held key would be strictly
 * weaker than the constraint already there.
 */
it('offers no idempotency key, having reasoned it through', function () {
    $prose = strtolower((string) json_encode(OpenApi::document()));

    expect($prose)->not->toContain('idempotency-key')
        ->and($prose)->not->toContain('idempotency_key');

    expect(OpenApi::document()['info']['description'])->toContain('no idempotency key');
    expect(documented()['post /documents']['description'])->toContain('natural key');
});

/*
 * Money is never a bare number and never a float. No request accepts a decimal
 * amount either, so this surface takes no rounding decision on a caller's behalf.
 */
it('publishes money as minor units, a currency, an exponent and a decimal string', function () {
    $schemas = OpenApi::document()['components']['schemas'];
    $money = $schemas['Money'];

    expect($money['required'])->toBe(['minor', 'currency', 'exponent', 'decimal'])
        ->and($money['properties']['minor']['type'])->toBe('integer')
        ->and($money['properties']['decimal']['type'])->toBe('string');

    foreach ($schemas['CreditNoteLine']['properties'] as $name => $property) {
        expect($property['type'])->not->toBe('number', (string) $name);
    }
});

/*
 * An unbound renderer removes the file and nothing else, and "declined" is a
 * different answer from "nobody was asked". Neither is published as an empty
 * file.
 */
it('publishes an unavailable rendition as a reason rather than as an empty file', function () {
    $rendition = OpenApi::document()['components']['schemas']['Rendition'];

    expect($rendition['properties']['unavailable_reason']['enum'])
        ->toBe([null, RefusalReason::NoRendererBound->value, RefusalReason::RendererDeclined->value])
        ->and($rendition['properties']['unavailable_reason']['description'])->toContain('**not** the same answer');
});

/*
 * Retention outranks erasure, and the refusal is a fact the payload carries: the
 * document, its number and the date. An unknown window is refused as unknown
 * rather than treated as zero.
 */
it('publishes a retention refusal as a named document rather than as a count', function () {
    $refusal = OpenApi::document()['components']['schemas']['RetentionRefusal'];

    expect($refusal['required'])->toContain('reference')
        ->and($refusal['required'])->toContain('number')
        ->and($refusal['required'])->toContain('retain_until')
        ->and($refusal['properties']['window_is_unknown']['description'])->toContain('not zero');

    expect(OpenApi::document()['components']['schemas']['ForgetReport']['required'])->toContain('complete');
});

it('names every path parameter its route declares', function () {
    $paths = OpenApi::document()['paths'];

    foreach (documented() as $key => $operation) {
        [, $path] = explode(' ', $key, 2);

        preg_match_all('/\{([a-z]+)\}/', $path, $matches);

        $declared = array_map(
            static fn (array $parameter): string => basename((string) ($parameter['$ref'] ?? $parameter['name'] ?? '')),
            array_merge($paths[$path]['parameters'] ?? [], $operation['parameters'] ?? []),
        );

        foreach ($matches[1] as $name) {
            expect($declared)->toContain($name);
        }
    }
});
