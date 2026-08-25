<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers\Controller;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Contracts\DocumentRenderer;
use Liberu\Ecommerce\InvoicesAndDocuments\Contracts\DocumentTransport;
use Liberu\Ecommerce\InvoicesAndDocuments\Contracts\SaleSource;

/** @return list<Illuminate\Routing\Route> */
function moduleRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'api/invoicing'),
    ));
}

/**
 * Every route as `METHOD uri`, with Laravel's synthesised HEAD dropped.
 *
 * @return list<string>
 */
function routeSignatures(): array
{
    return array_map(
        static fn (Illuminate\Routing\Route $route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
        moduleRoutes(),
    );
}

it('mounts every route under the configured prefix and name', function () {
    expect(moduleRoutes())->toHaveCount(15);

    foreach (moduleRoutes() as $route) {
        expect($route->getName())->toStartWith('invoicing-api.')
            ->and($route->uri())->toStartWith('api/invoicing/');
    }
});

/*
 * The whole of decision 2, on the wire. An issued document is immutable, so this
 * surface publishes no verb that could ask it to change and none that could ask
 * it to disappear. A correction is a credit note; a discard is a void.
 */
it('publishes no way to update a document and no way to delete one', function () {
    foreach (moduleRoutes() as $route) {
        $methods = array_diff($route->methods(), ['HEAD']);

        expect($methods)->not->toContain('PUT')
            ->and($methods)->not->toContain('PATCH')
            ->and($methods)->not->toContain('DELETE');
    }

    foreach (routeSignatures() as $signature) {
        expect(strtolower($signature))->not->toContain('cancel')
            ->not->toContain('amend');
    }
});

it('defaults the group middleware to an empty array rather than to null', function () {
    expect(Config::get('invoices-and-documents-api.route.middleware'))->toBe([])
        ->and(Config::get('invoices-and-documents-api.route.domain'))->toBeNull()
        ->and(Config::get('invoices-and-documents-api.route.prefix'))->toBe('api/invoicing');
});

/*
 * Nothing is bound as a route model. Type-hinting a domain model in a route
 * signature would couple the transport to the domain's storage, and it would
 * fetch the row before custody was asserted — the one way this surface could
 * answer 403 where it must answer 404.
 */
it('binds no route model and names only a document or a series in a path', function () {
    foreach (moduleRoutes() as $route) {
        foreach ($route->signatureParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                expect($type->getName())->not->toContain('InvoicesAndDocuments\\Models');
            }
        }

        foreach ($route->parameterNames() as $name) {
            expect($name)->toBeIn(['document', 'series'], $route->uri());
        }
    }
});

it('takes no merchant identifier in any path', function () {
    foreach (moduleRoutes() as $route) {
        expect($route->uri())->not->toContain('tenant')
            ->not->toContain('team')
            ->not->toContain('store')
            ->not->toContain('merchant');
    }
});

it('publishes an ability for every routed action, and only abilities it defines', function () {
    foreach (moduleRoutes() as $route) {
        $controller = $route->getController();

        expect($controller)->toBeInstanceOf(Controller::class);

        $scope = $controller->scopes()[$route->getActionMethod()] ?? null;

        expect($scope)->toBeIn(Scope::all(), $route->uri().' '.$route->getActionMethod());
    }
});

/*
 * The split is by whose documents the ability reaches, and the sharpest line in
 * it is the last one: erasure and export walk one person across every tenant,
 * because the domain's do, so they are not a merchant's ability.
 */
it('keeps the three abilities apart', function () {
    $byScope = [];

    foreach (moduleRoutes() as $route) {
        $controller = $route->getController();
        $scope = (string) ($controller->scopes()[$route->getActionMethod()] ?? '');
        $byScope[$scope][] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
    }

    foreach (array_keys($byScope) as $scope) {
        sort($byScope[$scope]);
    }

    expect($byScope[Scope::READ])->toBe([
        'GET api/invoicing/my-documents',
        'GET api/invoicing/my-documents/{document}',
    ]);

    expect($byScope[Scope::OPERATE])->toBe([
        'GET api/invoicing/documents',
        'GET api/invoicing/documents/{document}',
        'GET api/invoicing/documents/{document}/rendition',
        'GET api/invoicing/series/{series}/continuity',
        'POST api/invoicing/documents',
        'POST api/invoicing/documents/{document}/credit-notes',
        'POST api/invoicing/documents/{document}/deliveries',
        'POST api/invoicing/documents/{document}/issuances',
        'POST api/invoicing/documents/{document}/voidings',
        'POST api/invoicing/series',
        'POST api/invoicing/series/{series}/burned-numbers',
    ]);

    expect($byScope[Scope::PLATFORM_PRIVACY])->toBe([
        'POST api/invoicing/erasures',
        'POST api/invoicing/subject-records',
    ]);
});

/*
 * The self-only ability is exactly the one whose controller never reads a
 * caller-supplied subject. Asserted over the source rather than the route table,
 * because the property is about what the controller *can* do.
 */
it('never reads a caller-supplied subject under an ability that acts for the holder', function () {
    $selfOnly = [];

    foreach (moduleRoutes() as $route) {
        $controller = $route->getController();
        $scope = (string) ($controller->scopes()[$route->getActionMethod()] ?? '');

        if (in_array($scope, Scope::selfOnly(), true)) {
            $selfOnly[$controller::class] = true;
        }
    }

    expect(array_keys($selfOnly))->toHaveCount(1);

    foreach (array_keys($selfOnly) as $class) {
        $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());

        expect($source)->not->toContain('namedSubject(');
    }
});

it('adds no throttle and therefore promises no wait', function () {
    $middlewares = [];

    foreach (moduleRoutes() as $route) {
        $middlewares = array_merge($middlewares, array_map(
            static fn (mixed $middleware): string => is_string($middleware) ? $middleware : '',
            $route->gatherMiddleware(),
        ));
    }

    expect($middlewares)->toBe([]);
});

it('publishes its config for a host to override', function () {
    expect(file_exists(dirname(__DIR__, 2).'/config/invoices-and-documents-api.php'))->toBeTrue()
        ->and(Config::get('invoices-and-documents-api.actor.tenant_attribute'))->toBe('team_id')
        ->and(Config::get('invoices-and-documents-api.actor.subject_attribute'))->toBeNull();
});

/*
 * None of the three seams is bound here. A sale source answering nothing would
 * draft empty documents; a transport answering "sent" would record a delivery
 * that never happened, which is the host defect this module exists to remove.
 */
it('binds none of the domain seams', function () {
    expect(Config::get('invoices-and-documents.seams.sale'))->toBeNull()
        ->and(Config::get('invoices-and-documents.seams.renderer'))->toBeNull()
        ->and(Config::get('invoices-and-documents.seams.transport'))->toBeNull()
        ->and(app()->bound(SaleSource::class))->toBeFalse()
        ->and(app()->bound(DocumentRenderer::class))->toBeFalse()
        ->and(app()->bound(DocumentTransport::class))->toBeFalse();
});

/*
 * Two absences recorded rather than improvised. The domain publishes no query
 * that enumerates a merchant's series and no paged listing of documents, and an
 * adapter may not reach for a model to invent either.
 */
it('publishes no series listing and no pagination, and says so', function () {
    foreach (routeSignatures() as $signature) {
        expect($signature)->not->toBe('GET api/invoicing/series');
    }

    expect(file_get_contents(dirname(__DIR__, 2).'/docs/adoption.md'))
        ->toContain('ListSeries')
        ->toContain('pagination');
});
