<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures;

use Liberu\Ecommerce\InvoicesAndDocuments\Contracts\DocumentTransport;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Rendered;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\RenderModel;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\TransportOutcome;

final class FakeTransport implements DocumentTransport
{
    public int $delivered = 0;

    public ?Rendered $sawRendered = null;

    public ?string $sawAddress = null;

    public function __construct(private ?TransportOutcome $answer = null) {}

    public function deliver(RenderModel $model, ?Rendered $rendered, string $channel, string $address): TransportOutcome
    {
        $this->delivered++;
        $this->sawRendered = $rendered;
        $this->sawAddress = $address;

        return $this->answer ??= TransportOutcome::sent();
    }
}
