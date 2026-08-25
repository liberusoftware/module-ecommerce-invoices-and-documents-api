# Changelog

## 0.1.0

The HTTP surface over `liberusoftware/ecommerce-invoices-and-documents` `0.1.0`.

Fifteen endpoints: a merchant's documents and their whole lifecycle, the buyer's own
documents, numbering series and their reconciliation, and the person-wide export and
erasure.

### Decisions

- **No update operation and no delete operation.** An issued document is immutable, so
  this surface publishes no `PUT`, no `PATCH` and no `DELETE`. A correction is a credit
  note; a discard is a void. Asserted over the route table and over the OpenAPI document.
- **A refusal is a fact in the body**, carrying the domain's own `RefusalReason` as the
  error code. The refusal table is a `match` over the domain's enum rather than an array,
  so a reason added to the domain fails static analysis here instead of arriving as a
  500.
- **`resubmittable` means the identical request could succeed.** Three delivery refusals
  are 502 or 503 and are published as *not* resubmittable, because `RecordDelivery`
  writes the attempt row before it asks the transport: the reference is spent and a retry
  answers `already_recorded`.
- **No idempotency key anywhere.** Every write already has a natural key the database
  enforces, and a key the client holds is a key the client can change.
- **Erasure and export are person-wide across every merchant**, because the domain's
  are, so they are gated on `invoicing:platform-privacy` rather than on an operator
  ability, and both publish `tenant_id` on every row. That is the only place the merchant
  leaves this surface.
- **A rendition with no renderer bound is a 200** carrying `rendered: false`, the reason,
  and the whole document. The blast radius of an unbound renderer is the file and nothing
  else. `no_renderer_bound` and `renderer_declined` stay separate answers.
- **Four wrong references are one answer.** Another merchant's document, another buyer's,
  one whose buyer was erased and one nobody minted all answer 404 with the same body.
- **No database key leaves.** A document is named by its reference and a series by its
  code; `Outcome`'s id is dropped by the presenter.
- **The listing carries the buyer's name and neither their address nor their email.**

### Deliberately not shipped

- **A series listing.** The domain publishes no query that enumerates a merchant's
  series.
- **Pagination.** The domain publishes no paged listing, and a limit invented in the
  transport is a merchant told they have fewer invoices than they have.
- **A merchant-scoped erasure or export.** The domain's are person-wide.
- **`source_ref`, `void_reason`, `delivered_at` and `retain_until` on a document read.**
  `Data\RenderModel` does not carry them, and this package types against what the domain
  publishes rather than against the models behind it.

All four are recorded in `docs/adoption.md` with the domain query that would bring them.
