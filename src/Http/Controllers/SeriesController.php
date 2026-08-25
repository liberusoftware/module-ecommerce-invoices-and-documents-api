<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\BurnNumber;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\OpenSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Present;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\CheckSeriesContinuity;

/**
 * The numbering series a document is filed under.
 *
 * A series belongs to the merchant rather than to a storefront: the filing
 * obligation is the merchant's. `gapless` is a policy of the series and not a
 * property of the module — jurisdictions differ — and it is the one thing here
 * that cannot be changed afterwards without an argument, so it is stated when
 * the series is opened.
 *
 * **No listing.** The domain publishes no query that enumerates a merchant's
 * series, and a filter invented here would be this package deciding what a
 * series is.
 */
final class SeriesController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'store' => Scope::OPERATE,
        'continuity' => Scope::OPERATE,
        'burn' => Scope::OPERATE,
    ];

    public function store(Request $request, OpenSeries $open): JsonResponse
    {
        $input = $this->validated($request, [
            'code' => ['required', 'string', 'max:64'],
            'prefix' => ['nullable', 'string', 'max:32'],
            'pad' => ['nullable', 'integer', 'min:0', 'max:20'],
            'fiscal' => ['nullable', 'boolean'],
            'gapless' => ['nullable', 'boolean'],
            'start_at' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->answer($open(
            $this->tenantId(),
            $this->asString($input['code']),
            $this->nullableString($input['prefix'] ?? null) ?? '',
            $this->asInt($input['pad'] ?? null, 0),
            $this->asBool($input['fiscal'] ?? null, true),
            $this->asBool($input['gapless'] ?? null, true),
            $this->asInt($input['start_at'] ?? null, 1),
        ));
    }

    /**
     * Every number the series has spent, on a document or on the record of one
     * it could not use.
     *
     * A gapless series with anything in `missing` is the alarm the runbook says
     * cannot wait: nothing in the domain can fill a hole in afterwards.
     */
    public function continuity(string $series, CheckSeriesContinuity $check): JsonResponse
    {
        return new JsonResponse(['data' => Present::continuity($check($this->tenantId(), $series))]);
    }

    /** Spend a number on nothing, on the record. A gapless series refuses. */
    public function burn(string $series, Request $request, BurnNumber $burn): JsonResponse
    {
        $input = $this->validated($request, [
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->answer($burn($this->tenantId(), $series, $this->asString($input['reason'])));
    }
}
