<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A stand-in for whatever the host issues. Two things about it are the point:
 * it carries a real `tokenCan()`, so `method_exists()` answers honestly where
 * `is_callable()` answers true for any Eloquent model at all; and it carries the
 * merchant on an attribute this package reads by configured name.
 *
 * @property string $team_id
 * @property string|null $person_ref
 * @property list<string> $abilities
 */
class ApiActor extends Authenticatable
{
    protected $table = 'api_actors';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['abilities' => 'array'];
    }

    public function tokenCan(string $ability): bool
    {
        return in_array($ability, (array) $this->getAttribute('abilities'), true);
    }
}
