# Runbook

What breaks on this surface, what it looks like, and what to do.

## Every write answers `refused` and nothing is ever created

**Symptom.** `POST /documents` answers 503 `sale_source_unbound`.

Nothing is bound to `Contracts\SaleSource`. The module refuses to draft rather than
inventing lines. Bind it in `config/invoices-and-documents.php` or in the container, in
the host. Nothing was written, so retry the identical request once it is bound.

## A gapless series reports a hole

**Symptom.** `GET /series/{series}/continuity` answers with `gapless: true` and a
non-empty `missing`.

**This is the one alarm here that cannot wait, and nothing in the domain can fix it.**
A gapless series promises that every number between its first and its last is accounted
for by a document or by a recorded burn. A hole means a number was spent and the row
that spent it is gone — a partially-committed transaction, a restored backup, a manual
`DELETE`.

There is no domain action that fills a hole in, and adding one would defeat the point of
the guarantee. What to do:

1. Read `missing`. Each entry is a sequence value, not a formatted number.
2. Find out what was in it. `invoicing_document_events` survives a document being rolled
   back only if the event row committed, so check both it and any application log
   covering the window.
3. Decide, with whoever owns the filing obligation, whether the series can continue.
   Where it cannot, open a new series and leave the old one closed. The module does not
   close a series; a host stops naming it.

Do **not** burn numbers to "fill" a gap: `BurnNumber` refuses on a gapless series, and
on any other series it spends the *next* number rather than a missing one.

## A delivery is stuck pending and repeating it does nothing

**Symptom.** `POST /documents/{document}/deliveries` answered 503 `no_transport_bound`,
502 `transport_failed` or 409 `transport_suppressed`. A transport is now bound, or the
fault is fixed, and repeating the identical call answers 200 `already_recorded`.

That is the domain writing the attempt row before it asks the transport. The delivery
reference is spent, and the attempt row stays in whatever state it settled in.

**Send again with a new `reference`.** Anything the caller can generate will do; it is
the caller's own handle for the attempt and this module never resolves it. The stuck row
stays as the record that the first attempt happened, which is what it is for.

## An operator says they cannot see a merchant's invoices

**Symptom.** `GET /documents` answers 200 with an empty list, or a known reference
answers 404.

The merchant is read from the credential, never from the request. Check, in order:

1. `invoices-and-documents-api.actor.tenant_attribute` names an attribute that actually
   exists on the host's authenticatable. A 403 `actor_has_no_tenant` says it does not.
2. The value on that attribute is the same string the documents were filed under. The
   module treats it as opaque: `1` and `"1"` are the same after the cast, `"team-1"` and
   `"Team-1"` are not.
3. The credential carries `invoicing:operate`. A 403 `insufficient_scope` says it does
   not.

A 404 here is deliberately indistinguishable from a reference that does not exist. It is
not evidence that the document is gone.

## A storefront token gets 403 `actor_has_no_subject`

`invoices-and-documents-api.actor.subject_attribute` is configured and the attribute is
empty on that actor. Either populate it, or set the key back to `null` to fall back to
the authentication identifier. A credential that resolves to no person cannot be answered
under `invoicing:read`, because there is nobody to answer for.

## A buyer stopped being able to read their own document

Expected, after an erasure. `POST /erasures` replaces the buyer reference with a
per-document redaction token, so the document no longer matches the credential's subject
and `CustodyPolicy::buyerMayRead` refuses a redacted document outright. The document is
still there, still adds up, and is still readable under `invoicing:operate`.

If the erasure was a mistake, there is no undo. The reference was replaced, not stored.

## An erasure keeps answering `complete: false`

Read `refused_documents`. Two cases:

- `window_is_unknown: false` — the document is inside its retention window and will be
  erasable after `retain_until`. Nothing to do but wait or accept the refusal.
- `window_is_unknown: true` — the host has configured no retention window at all.
  Set `invoices-and-documents.retention.years`. It only affects documents issued *after*
  it is set: `retain_until` is stamped at issue and is frozen with everything else.
  Documents issued before then stay unknown, and stay refused, permanently. That is the
  honest answer and not a bug.

## `GET /documents` is slow

Every row builds a render model, which is a query for its lines and one for the document
it corrects. The domain publishes no paged listing and no summary projection, so this
endpoint has no cursor and no limit.

Narrow it with `kind`, `state` or `buyer_ref`. If that is not enough, the fix is a
domain-side paged query, recorded in `docs/adoption.md`. Do not put a limit in the
transport: a listing that silently truncates is a merchant told they have fewer invoices
than they have.

## A 500 with no error body

Something threw that this surface does not own. That is deliberate: the failure map has
no catch-all arm, because one would dress a genuine defect as a plausible 4xx and hide
it from whoever is on call. The stack trace is the host's to log.
