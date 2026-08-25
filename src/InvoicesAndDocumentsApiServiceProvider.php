<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers this package's HTTP surface, and nothing else.
 *
 * It binds no interface. The domain's actions and queries are final concrete
 * classes with no constructor arguments, so the container autowires them from a
 * controller signature without a binding — and the domain's three seams
 * (`SaleSource`, `DocumentRenderer`, `DocumentTransport`) are deliberately left
 * unbound. Binding a default would be worse than leaving them out: a sale source
 * answering nothing would draft empty documents, and a transport answering
 * "sent" would record a delivery that never happened, which is the host defect
 * this module exists to remove.
 *
 * Composer boots nothing: `extra.laravel.providers` is absent and the host's
 * module manager registers this only when the package is named in
 * `MODULES_ENABLED`.
 */
final class InvoicesAndDocumentsApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/invoices-and-documents-api.php', 'invoices-and-documents-api');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/invoices-and-documents-api.php' => $this->app->configPath('invoices-and-documents-api.php'),
        ], 'invoices-and-documents-api-config');

        Route::group([
            'prefix' => $this->routePrefix(),
            'middleware' => (array) Config::get('invoices-and-documents-api.route.middleware', []),
            'domain' => Config::get('invoices-and-documents-api.route.domain'),
            'as' => 'invoicing-api.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/api.php'));
    }

    private function routePrefix(): string
    {
        $prefix = Config::get('invoices-and-documents-api.route.prefix', 'api/invoicing');

        return is_string($prefix) ? $prefix : 'api/invoicing';
    }
}
