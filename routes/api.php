<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers\DocumentController;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers\MyDocumentController;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers\PrivacyController;
use Liberu\Ecommerce\InvoicesAndDocuments\Api\Http\Controllers\SeriesController;

/*
 * Fifteen endpoints, and the two that are not here are the point.
 *
 * **No PUT, PATCH or DELETE on a document, anywhere.** An issued document is
 * immutable: it is corrected by a credit note that references it and discarded
 * by a void that records. The domain guards both on the model and in the schema;
 * this surface guards them by publishing no verb that could ask.
 *
 * The merchant appears in no path, no query string and no body. It is derived
 * from the credential in the base controller, once.
 *
 * Nothing is bound as a route model. Type-hinting a domain model in a route
 * signature would fetch the row before custody was asserted, which is the one
 * way this surface could answer 403 where it must answer 404.
 *
 * Deliberately absent: a listing of numbering series, because the domain
 * publishes no query that enumerates them, and pagination, because it publishes
 * no paged listing. Both are recorded in `docs/adoption.md` as domain gaps
 * rather than improvised here.
 */

Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
Route::get('documents/{document}/rendition', [DocumentController::class, 'rendition'])->name('documents.rendition.show');
Route::post('documents/{document}/issuances', [DocumentController::class, 'issue'])->name('documents.issuances.store');
Route::post('documents/{document}/voidings', [DocumentController::class, 'void'])->name('documents.voidings.store');
Route::post('documents/{document}/credit-notes', [DocumentController::class, 'creditNote'])->name('documents.credit-notes.store');
Route::post('documents/{document}/deliveries', [DocumentController::class, 'deliver'])->name('documents.deliveries.store');

/*
 * The shopper's own, split from the merchant's by route rather than by a branch
 * inside one. Under `invoicing:read` the subject is the credential holder and
 * cannot be named, which is enforced in the base controller.
 */
Route::get('my-documents', [MyDocumentController::class, 'index'])->name('my-documents.index');
Route::get('my-documents/{document}', [MyDocumentController::class, 'show'])->name('my-documents.show');

Route::post('series', [SeriesController::class, 'store'])->name('series.store');
Route::get('series/{series}/continuity', [SeriesController::class, 'continuity'])->name('series.continuity.show');
Route::post('series/{series}/burned-numbers', [SeriesController::class, 'burn'])->name('series.burned-numbers.store');

/*
 * Both privacy endpoints are person-wide across every tenant, because the
 * domain's export and erasure are. `invoicing:platform-privacy` is not a
 * merchant's ability.
 */
Route::post('subject-records', [PrivacyController::class, 'export'])->name('subject-records.store');
Route::post('erasures', [PrivacyController::class, 'erase'])->name('erasures.store');
