# Adoption

How a host installs this package, what it must bind, and what it must decide before
it issues a credential.

## Install

```bash
composer require liberusoftware/ecommerce-invoices-and-documents-api
```

The domain package is not on Packagist yet, so this package carries a `repositories`
entry pointing at its repository. That entry's presence carries information: *not yet
published*. Remove it when it is.

Installing boots nothing. `extra.laravel.providers` is absent on purpose; the host's
module manager registers
`Liberu\Ecommerce\InvoicesAndDocuments\Api\InvoicesAndDocumentsApiServiceProvider`
only when the package is named in `MODULES_ENABLED`.

```bash
php artisan vendor:publish --tag=invoices-and-documents-api-config
```

## What the host must decide

### 1. The middleware stack

`invoices-and-documents-api.route.middleware` ships as `[]`, and never as `null`. An
empty stack is a host that has not opted in to a middleware; a null stack is Laravel
substituting one, which is an opt-out nobody wrote down. Every endpoint refuses an
unauthenticated caller in the controller regardless — the middleware decides how the
actor arrives, not whether one is required.

A Sanctum host wants `['auth:sanctum']`.

### 2. Where the merchant lives on the credential

`invoices-and-documents-api.actor.tenant_attribute` defaults to `team_id`. The merchant
is read from the authenticated actor and **never** from the request: a caller-supplied
merchant lets one storefront's credential file a fiscal document into another business.

### 3. Where the person lives on the credential

`invoices-and-documents-api.actor.subject_attribute` defaults to `null`, which falls
back to the authentication identifier. A host that mints an opaque person reference of
its own names the attribute here. It is never an email address and never a login as far
as this module is concerned.

### 4. Which abilities go on which credential

| Ability | Who holds it |
|---|---|
| `invoicing:read` | A storefront token, acting as the buyer it was issued to. It reaches that buyer's own documents and nothing else |
| `invoicing:operate` | A back-office credential for one merchant: drafting, issuing, correcting, voiding, delivering, and the numbering series |
| `invoicing:platform-privacy` | **A platform credential, not a merchant's.** See below |

`invoicing:operate` is not a superset of `invoicing:read`. The read ability acts for
the credential holder *as a buyer*, which a service account is not; an operator that
needs one person's documents lists them with `buyer_ref`.

**`invoicing:platform-privacy` erases across every merchant.** The domain's
`ForgetParticipant` and `ExportParticipantRecord` walk one person across every tenant,
by design — a subject-access request is about a person and not about a merchant, and
the application this module replaces got that backwards. This package presents them as
they are rather than pretending they are tenant-scoped: the merchant on the credential
is not consulted by either endpoint, and both answers name the merchant on every row so
that a refusal can be acted on. Issuing this ability to a storefront credential would
let one merchant erase a person's identity on another merchant's invoices. Do not.

If a host needs a merchant-scoped export or erasure, that is a domain gap and not
something to solve here — see below.

## What the host must bind

Nothing, to install. Three seams, to do anything useful, and none of them has a default
because `null` means *nobody answered*, which is not the same as *the answer is
nothing*.

| Seam | Bind it to | Unbound |
|---|---|---|
| `Contracts\SaleSource` | Whatever describes a sale — the order module, the checkout | `POST /documents` refuses with `sale_source_unbound`. Nothing is written |
| `Contracts\DocumentRenderer` | Whatever turns a render model into a file | `GET /documents/{document}/rendition` answers 200 with `rendered: false`. Issuing, numbering, listing and reading all still work |
| `Contracts\DocumentTransport` | The foundation's mail or webhook transport | `POST /documents/{document}/deliveries` records the attempt as pending and refuses with `no_transport_bound` |

Bind them in the host, in `config/invoices-and-documents.php` or in the container. This
package binds none of them and asserts that it does not.

## What it deletes from a host

Nothing automatically. What it *replaces* in the application this module was extracted
from:

| Host thing | Why it is not adopted |
|---|---|
| `App\Models\Invoice` and the `invoices` table | It has no number column, no currency and no tax breakdown, its lines are a `belongsToMany` onto the live catalogue, and its `payment_status` is written once and never again. There is nothing to adopt: the shape is the defect |
| `invoice_product` | `cascadeOnDelete` on `product_id`. Deleting a product deleted invoice lines under a header total that kept printing |
| `App\Http\Controllers\InvoiceController` | Drops the ownership filter entirely for `hasRole(['super_admin','admin'])`, which is a role name and not a merchant. `createInvoiceForOrder()` is unrouted dead code |
| `App\Http\Livewire\InvoicePdf` | Returns a view that does not exist, in a directory Livewire 4 does not discover, and no PDF library is installed |
| `App\Mail\InvoiceMail` | Names a view directory that does not exist, and nothing in the tree constructs it |
| `Invoice::generateForOrder()` | `firstOrCreate` with no unique index behind it, run outside the status transition's transaction and after its audit row, and creating a `Customer` named "Guest" as a side effect of a payment succeeding |

Migrating existing rows is a host job and a lossy one: the host's invoices have no
number, no currency and no per-line description that survived a catalogue change. What
can be carried across is the header total and the pivot's frozen `quantity` and `price`.
Everything else has to be re-derived from the order, and anything that cannot be is a
document that should be re-issued rather than back-filled.

## Gaps in the domain package this surface had to work around

These are reported rather than closed here. An adapter that computed its way past one
would be putting a business rule in the transport.

| Gap | What it costs this surface |
|---|---|
| No paged listing query. `Queries\ListDocuments` returns every matching document | `GET /documents` publishes no **pagination** and no cursor. A merchant with a large working set wants a domain-side paged query before this endpoint is put in front of one |
| No summary projection. Every total comes from `Frozen::linesOf()` | `GET /documents` builds a render model per row, which is a query per document. Correct, and not what a large listing wants |
| No `ListSeries` query | There is no `GET /series`. A merchant cannot discover its own series codes through this API; it has to know them. A domain listing query would bring the endpoint |
| `Data\RenderModel` omits `source_ref`, `void_reason`, `voided_at`, `delivered_at`, `retain_until` and the redaction flag | A document read cannot publish any of them. `state` covers voidness; the rest are simply absent. `Data\ExportedDocument` carries `retain_until` and `redacted`, but only on the person-wide export |
| `Actions\RecordDelivery` writes the attempt row **before** it asks the transport seam | An unbound or failing transport has already spent the delivery reference, so a retry answers `already_recorded` and never transmits. This surface publishes those three refusals as **not** resubmittable and says a new reference is needed. A domain fix would ask the seam first, or leave a pending attempt retryable |
| No tenant-scoped export or erasure | Both privacy endpoints are person-wide and gated on a platform ability. A merchant-scoped variant would need a domain query |
