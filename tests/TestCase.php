<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\InvoicesAndDocumentsApiServiceProvider;
use Liberu\Ecommerce\InvoicesAndDocuments\InvoicesAndDocumentsServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;

/**
 * The domain package is named here rather than duplicated into `require-dev`,
 * which would put one package in both sections and warn on `composer validate`.
 *
 * No seam is bound. The default state of the suite is the honest one: nothing
 * drafts, nothing renders and nothing transmits until a test says so.
 */
abstract class TestCase extends PackageTestCase
{
    use RefreshDatabase;

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique(array_merge(
            [InvoicesAndDocumentsServiceProvider::class, InvoicesAndDocumentsApiServiceProvider::class],
            parent::getPackageProviders($app),
        )));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', Fixtures\ApiActor::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
