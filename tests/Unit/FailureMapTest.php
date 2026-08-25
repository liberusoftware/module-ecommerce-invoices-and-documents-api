<?php

declare(strict_types=1);

use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Failure;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\RefusalReason;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\DocumentsAreImmutable;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\MoneyMismatch;
use Liberu\Ecommerce\InvoicesAndDocuments\Exceptions\NotFound;

/**
 * Every concrete exception the domain publishes, written out by hand.
 *
 * A scan of the directory would quietly cover a new one, and covering it is the
 * opposite of the point. `InvoicesAndDocumentsException` is the abstract base and
 * is the only exclusion: mapping it would swallow every future exception into
 * one status.
 *
 * @return list<class-string>
 */
function domainExceptions(): array
{
    return [DocumentsAreImmutable::class, MoneyMismatch::class, NotFound::class];
}

it('names only exception classes that actually exist', function () {
    $classes = [...domainExceptions(), 'Liberu\\Ecommerce\\InvoicesAndDocuments\\Exceptions\\InvoicesAndDocumentsException'];

    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue("[{$class}] does not autoload.");
    }
});

it('maps every exception the domain publishes, and exactly three of them', function () {
    $unmapped = array_values(array_diff(domainExceptions(), array_keys(Failure::exceptions())));
    $unknown = array_values(array_diff(array_keys(Failure::exceptions()), domainExceptions()));

    expect($unmapped)->toBe([])
        ->and($unknown)->toBe([])
        ->and(domainExceptions())->toHaveCount(3);
});

it('never maps the abstract base, which would swallow every future exception', function () {
    expect(array_keys(Failure::exceptions()))
        ->not->toContain('Liberu\\Ecommerce\\InvoicesAndDocuments\\Exceptions\\InvoicesAndDocumentsException');
});

/*
 * The domain's refusal is the caller's error code. Nothing here can invent a
 * second name for the same decision, and a reason added to the domain fails
 * `Failure::refusal()`'s match at analysis time rather than arriving as a 500.
 */
it('answers every refusal the domain can return, under the domain\'s own name', function () {
    expect(RefusalReason::cases())->toHaveCount(20);

    foreach (RefusalReason::cases() as $reason) {
        $mapping = Failure::refusal($reason);

        expect($mapping['code'])->toBe($reason->value)
            ->and($mapping['message'])->toBeString()->not->toBe('')
            ->and($mapping['status'])->toBeGreaterThanOrEqual(400)
            ->and($mapping['status'])->toBeLessThan(600);
    }
});

it('classifies every mapping as resubmittable or spent', function () {
    foreach (allMappings() as $key => $mapping) {
        expect($mapping)->toHaveKeys(['status', 'code', 'message', 'resubmittable'], $key)
            ->and($mapping['resubmittable'])->toBeBool();
    }
});

/**
 * Both tables, keyed by something a failure message can name.
 *
 * @return array<string, array{status: int, code: string, message: string, resubmittable: bool}>
 */
function allMappings(): array
{
    $mappings = Failure::exceptions();

    foreach (RefusalReason::cases() as $reason) {
        $mappings[$reason->value] = Failure::refusal($reason);
    }

    return $mappings;
}

/*
 * Resubmittable means the identical request can succeed later, which is only
 * ever a correctable input (422) or a seam this deployment has not bound (503).
 * Three of the delivery refusals are 502 or 503 and are **not** resubmittable,
 * because `RecordDelivery` writes the attempt row before it asks the transport:
 * the delivery reference is spent, and repeating the call answers
 * `already_recorded` rather than transmitting.
 */
it('never calls a failure resubmittable unless the same request could succeed', function () {
    foreach (allMappings() as $key => $mapping) {
        if ($mapping['resubmittable']) {
            expect($mapping['status'])->toBeIn([422, 503], $key);
        }
    }

    expect(Failure::refusal(RefusalReason::NoTransportBound)['resubmittable'])->toBeFalse()
        ->and(Failure::refusal(RefusalReason::TransportFailed)['resubmittable'])->toBeFalse()
        ->and(Failure::refusal(RefusalReason::TransportSuppressed)['resubmittable'])->toBeFalse();
});

/*
 * Nothing in this domain is transient. There is no rate limit of its own and no
 * in-flight claim to wait out, so there is no 429, no 423, and no message
 * inviting somebody to wait. A courtesy retry prompt on a permanent refusal is a
 * lie the surface tells on the domain's behalf.
 */
it('never invites a wait, because nothing here is transient', function () {
    foreach (allMappings() as $key => $mapping) {
        expect($mapping['status'])->not->toBe(429, $key)
            ->and($mapping['status'])->not->toBe(423, $key);

        expect(strtolower($mapping['message']))->not->toContain('try again')
            ->not->toContain('shortly')
            ->not->toContain('temporar')
            ->not->toContain('in a moment');
    }

    expect(file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/Controller.php'))
        ->not->toContain('Retry'.'-After');
});

/*
 * No 403 in either table. A 403 for a reference belonging to somebody else
 * confirms it exists, which is the disclosure the 404 was chosen to avoid. The
 * four 403s this surface emits are about the credential itself and are built in
 * the base controller.
 */
it('maps nothing to a 403 that could confirm a record exists', function () {
    foreach (allMappings() as $key => $mapping) {
        expect($mapping['status'])->not->toBe(403, $key);
    }
});

it('answers every unknown reference identically', function () {
    $notFound = array_values(array_filter(allMappings(), static fn (array $m): bool => $m['status'] === 404));

    expect($notFound)->toHaveCount(1)
        ->and($notFound[0]['code'])->toBe('not_found')
        ->and($notFound[0]['resubmittable'])->toBeFalse();

    foreach (['document', 'series', 'invoice', 'credit'] as $tell) {
        expect(strtolower($notFound[0]['message']))->not->toContain($tell);
    }
});

/*
 * `DocumentsAreImmutable` is mapped and cannot currently be raised through this
 * surface, because there is no update route and no delete route to raise it. It
 * is mapped so that one appearing could not arrive as a 500.
 */
it('maps the immutability exception even though no route can reach it', function () {
    $mapping = Failure::for(DocumentsAreImmutable::forDeletion('a-reference'));

    expect($mapping)->not->toBeNull()
        ->and($mapping['status'])->toBe(409)
        ->and($mapping['code'])->toBe('documents_are_immutable');
});

it('renders no domain exception message to a caller', function () {
    foreach (glob(dirname(__DIR__, 2).'/src/Http/Controllers/*.php') ?: [] as $file) {
        expect(php_strip_whitespace((string) $file))->not->toContain('getMessage()');
    }
});

it('lets an unmapped throwable bubble rather than dressing it as a 4xx', function () {
    expect(Failure::for(new LogicException('something nobody planned for')))->toBeNull();
});

it('shapes every error body the same way', function () {
    expect(Failure::body('a_code', 'A message.', true))
        ->toBe(['error' => ['code' => 'a_code', 'message' => 'A message.', 'resubmittable' => true]]);
});
