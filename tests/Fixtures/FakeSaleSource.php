<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures;

use Liberu\Ecommerce\InvoicesAndDocuments\Contracts\SaleSource;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Line;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Party;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Sale;

final class FakeSaleSource implements SaleSource
{
    /** @var array<string, Sale> */
    public array $sales = [];

    public int $asked = 0;

    public function sale(string $tenantId, string $saleReference): ?Sale
    {
        $this->asked++;

        return $this->sales[$tenantId.'/'.$saleReference] ?? null;
    }

    /** @param  list<Line>  $lines */
    public function offer(string $tenantId, string $saleReference, array $lines, ?Party $buyer = null, ?Money $statedGross = null): self
    {
        $currency = $lines[0]->net->currency;
        $exponent = $lines[0]->net->exponent;
        $net = Money::zero($currency, $exponent);
        $tax = Money::zero($currency, $exponent);
        $gross = Money::zero($currency, $exponent);

        foreach ($lines as $line) {
            $net = $net->plus($line->net);
            $tax = $tax->plus($line->tax);
            $gross = $gross->plus($line->gross);
        }

        $this->sales[$tenantId.'/'.$saleReference] = new Sale(
            $saleReference,
            new Party('seller-1', 'Merchant Ltd', '1 Trade Street', 'GB123456789'),
            $buyer ?? new Party('person-1', 'A Buyer', '2 Home Road', null, 'buyer@example.test'),
            $lines,
            $net,
            $tax,
            $statedGross ?? $gross,
        );

        return $this;
    }
}
