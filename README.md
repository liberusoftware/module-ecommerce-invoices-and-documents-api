# Ecommerce Invoices and Documents — HTTP API

[![Tests](https://github.com/liberusoftware/module-ecommerce-invoices-and-documents-api/actions/workflows/tests.yml/badge.svg)](https://github.com/liberusoftware/module-ecommerce-invoices-and-documents-api/actions/workflows/tests.yml)

The HTTP surface over
[`liberusoftware/ecommerce-invoices-and-documents`](https://github.com/liberusoftware/module-ecommerce-invoices-and-documents):
the document a sale produces, and the number that document is filed under.

It presents the domain. It reimplements none of it, holds no business rule, and reads
none of the domain's Eloquent models — everything goes through the actions, queries and
value objects that package publishes.

## The four things worth knowing before you call it

**There is no update operation and no delete operation.** An issued document is
immutable: it is corrected by issuing a credit note that references it, and discarded by
voiding it, which records rather than erases. No `PUT`, no `PATCH` and no `DELETE`
appears anywhere on this API, and the suite asserts that over the route table *and* over
the OpenAPI document.

**A refusal is a fact in the body.** Every refusal the domain can return carries the
domain's own reason as the error `code`, with `resubmittable` saying whether asking again
could work. The refusal table is a `match` over the domain's enum, so a reason added to
the domain fails static analysis here rather than arriving as a 500.

**The merchant is never accepted.** It comes from the credential, always, and appears in
no path, no query string and no body. The two privacy operations are the exception in the
other direction: they are person-wide across every merchant, because a subject-access
request is about a person, and they publish the merchant on every row so a caller can act
on what they say. Their ability is `invoicing:platform-privacy` and it is not a
merchant's.

**There is no idempotency key.** Every write already has a natural key the database
enforces: a document on its sale, a credit note on its refund, a delivery on its own
reference. A key a client holds is a key a client can change. Send your own reference and
retry freely — a repeat answers `already_recorded` with the reference already minted.

## The endpoints

Mounted at `api/invoicing` by default.

| | Ability | |
| --- | --- | --- |
| `GET /documents` | `invoicing:operate` | This merchant's documents, newest first |
| `POST /documents` | `invoicing:operate` | Draft one from a sale — the moment the sale is read |
| `GET /documents/{document}` | `invoicing:operate` | Everything the document says, from its own frozen rows |
| `GET /documents/{document}/rendition` | `invoicing:operate` | The file of it, or the reason there is none |
| `POST /documents/{document}/issuances` | `invoicing:operate` | Issue it, spending the number |
| `POST /documents/{document}/voidings` | `invoicing:operate` | Void it, keeping the number |
| `POST /documents/{document}/credit-notes` | `invoicing:operate` | Correct it with another document |
| `POST /documents/{document}/deliveries` | `invoicing:operate` | Record an attempt, and transmit if a transport is bound |
| `GET /my-documents` | `invoicing:read` | The documents issued to the credential holder |
| `GET /my-documents/{document}` | `invoicing:read` | One of them |
| `POST /series` | `invoicing:operate` | Open a numbering series |
| `GET /series/{series}/continuity` | `invoicing:operate` | Reconcile what it has spent |
| `POST /series/{series}/burned-numbers` | `invoicing:operate` | Spend a number on nothing, on the record |
| `POST /subject-records` | `invoicing:platform-privacy` | Export one person's documents, across every merchant |
| `POST /erasures` | `invoicing:platform-privacy` | Erase them, where retention allows it |

The OpenAPI 3.1 document is at `resources/openapi/openapi.json`, and the suite asserts
parity with the router in **both** directions — including that every operation documents
the ability the controller actually enforces, and that the document's error-code enum is
exactly the set of codes this surface can produce.

## Failures

One body shape, everywhere:

```json
{"error": {"code": "series_is_gapless", "message": "…", "resubmittable": false}}
```

`code` is the domain's own name for the decision. Branch on it, never on the prose.
`resubmittable` is true only when the *identical* request could succeed later — a
correctable input, or a seam this deployment has not bound. Three delivery refusals are
false despite being 502 or 503, because the domain writes the attempt row before it asks
the transport: the delivery reference is already spent and sending again needs a new one.

Nothing on this surface is transient. There is no 429, no 423 and no retry header.

## What it does not publish

- **No listing of numbering series**, because the domain publishes no query that
  enumerates them and a filter invented here would be this package deciding what a series
  is.
- **No pagination**, because the domain publishes no paged listing. Narrow `GET
  /documents` with `kind`, `state` or `buyer_ref`.
- **No merchant-scoped erasure**, because the domain's erasure is person-wide.

All three are recorded in [`docs/adoption.md`](docs/adoption.md) as gaps in the domain
package rather than improvised here.

## Documentation

| | |
|---|---|
| [`docs/adoption.md`](docs/adoption.md) | Installing it, the three seams, which ability goes on which credential, and what it replaces in a host |
| [`docs/domain.md`](docs/domain.md) | Every decision this surface took, and why |
| [`docs/runbook.md`](docs/runbook.md) | What breaks, what it looks like, what to do |
