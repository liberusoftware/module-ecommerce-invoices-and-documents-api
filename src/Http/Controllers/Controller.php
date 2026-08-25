<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Failure;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Present;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Scope;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Outcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\RefusalReason;
use LogicException;
use Throwable;

/**
 * Who is asking, on whose behalf, and what a domain refusal means over HTTP.
 *
 * The mapping runs in `callAction()` rather than in middleware because
 * `Illuminate\Routing\Pipeline` renders a throwable through the application
 * handler before the surrounding middleware resumes: a middleware mapper sees a
 * rendered 500, not the exception that caused it.
 *
 * The merchant is read from the credential and never from the request. A
 * document belonging to another merchant answers 404, byte for byte as one
 * nobody ever minted does, because the domain's own lookups scope on the tenant
 * and refuse to tell the two apart.
 */
abstract class Controller extends BaseController
{
    /**
     * The ability each action requires, keyed by method name.
     *
     * A method absent from this map is refused. An unanswered authorization
     * question is not a yes.
     *
     * @var array<string, string>
     */
    protected array $scopes = [];

    private string $tenantId = '';

    private string $actorSubjectRef = '';

    private string $ability = '';

    /** @param  array<string, mixed>  $parameters */
    public function callAction($method, $parameters): mixed
    {
        $refusal = $this->resolveActor($method);

        if ($refusal instanceof JsonResponse) {
            return $refusal;
        }

        try {
            return parent::callAction($method, $parameters);
        } catch (ValidationException $exception) {
            $body = Failure::body('validation_failed', 'The request did not satisfy this endpoint.', true);
            $body['error']['fields'] = $exception->errors();

            return new JsonResponse($body, 422);
        } catch (Throwable $exception) {
            $mapping = Failure::for($exception);

            if ($mapping === null) {
                throw $exception;
            }

            return new JsonResponse(
                Failure::body($mapping['code'], $mapping['message'], $mapping['resubmittable']),
                $mapping['status'],
            );
        }
    }

    /** @return array<string, string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    protected function tenantId(): string
    {
        return $this->tenantId;
    }

    /** The person this request acts for, when the ability in force acts for the credential holder. */
    protected function subjectRef(): string
    {
        return $this->actorSubjectRef;
    }

    /**
     * The person an operator request names, from validated input.
     *
     * It refuses under a self-only ability, so a copied method signature cannot
     * quietly widen a shopper endpoint into one that acts on strangers.
     *
     * @param  array<string, mixed>  $input
     */
    protected function namedSubject(array $input): string
    {
        if (in_array($this->ability, Scope::selfOnly(), true)) {
            throw new LogicException("The [{$this->ability}] ability acts for the credential holder and may not name a subject.");
        }

        return $this->asString($input['subject_ref'] ?? null);
    }

    /**
     * `Validator::make(...)->validate()` rather than `$request->validate()`: the
     * latter is a framework-foundation macro this package does not require.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    protected function validated(HttpRequest $request, array $rules): array
    {
        /** @var array<string, mixed> */
        return Validator::make($request->all(), $rules)->validate();
    }

    /**
     * Validated input is still `mixed` to a type checker, and a cast off `mixed`
     * is the one place a surface quietly turns an array into `"Array"`. These
     * three narrow first and fall back to the stated default.
     */
    protected function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    protected function asInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    protected function asBool(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = $this->asString($value);

        return $string === '' ? null : $string;
    }

    /**
     * The one place an `Outcome` becomes a response.
     *
     * A refusal is not a bare status: the reason the domain gave is the error
     * code, in the body, and a caller branches on it rather than on prose.
     */
    protected function answer(Outcome $outcome): JsonResponse
    {
        $reason = $outcome->reason;

        if ($reason instanceof RefusalReason) {
            $mapping = Failure::refusal($reason);

            return new JsonResponse(
                Failure::body($mapping['code'], $mapping['message'], $mapping['resubmittable']),
                $mapping['status'],
            );
        }

        return new JsonResponse(['data' => Present::outcome($outcome)], $outcome->happened() ? 201 : 200);
    }

    /** Null when the request may proceed, a response when it may not. */
    private function resolveActor(string $method): ?JsonResponse
    {
        $scope = $this->scopes[$method] ?? null;

        if ($scope === null) {
            return $this->refuse(403, 'insufficient_scope', 'This operation publishes no ability and cannot be called.');
        }

        $user = Request::user();

        if (! $user instanceof Authenticatable) {
            return $this->refuse(401, 'unauthenticated', 'This endpoint requires an authenticated actor.');
        }

        // `method_exists()`, never `is_callable()`: Eloquent implements `__call`,
        // so `is_callable([$user, 'tokenCan'])` is true for every model on earth.
        if (! method_exists($user, 'tokenCan') || $user->tokenCan($scope) !== true) {
            return $this->refuse(403, 'insufficient_scope', "This credential does not carry the [{$scope}] ability.");
        }

        $tenantId = $this->asString(data_get($user, $this->asString(Config::get('invoices-and-documents-api.actor.tenant_attribute', 'team_id'))));

        if ($tenantId === '') {
            return $this->refuse(403, 'actor_has_no_tenant', 'This credential is not attached to a merchant.');
        }

        $subjectAttribute = $this->nullableString(Config::get('invoices-and-documents-api.actor.subject_attribute'));
        $subjectRef = $this->asString($subjectAttribute === null
            ? $user->getAuthIdentifier()
            : data_get($user, $subjectAttribute));

        if ($subjectRef === '') {
            return $this->refuse(403, 'actor_has_no_subject', 'This credential does not resolve to a person.');
        }

        $this->tenantId = $tenantId;
        $this->actorSubjectRef = $subjectRef;
        $this->ability = $scope;

        return null;
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(Failure::body($code, $message, false), $status);
    }
}
