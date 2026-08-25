<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures;

use Liberu\Ecommerce\InvoicesAndDocuments\Contracts\DocumentRenderer;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Rendered;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\RenderModel;

final class FakeRenderer implements DocumentRenderer
{
    public ?RenderModel $sawModel = null;

    public function __construct(public bool $declines = false) {}

    public function render(RenderModel $model): ?Rendered
    {
        $this->sawModel = $model;

        return $this->declines ? null : new Rendered('application/pdf', ($model->number ?? $model->reference).'.pdf', 'bytes');
    }
}
