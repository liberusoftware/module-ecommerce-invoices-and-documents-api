# Domain, as this surface presents it

What this package decided, and why. It holds no business rule: every decision about a
document is the domain package's, and what is written down here is only how those
decisions reach the wire.

## 1. What is not here, and that is the point

**There is no `PUT`, no `PATCH` and no `DELETE` anywhere on this API.**

An issued document is immutable. It is corrected by issuing another document that
references it — a credit note — and discarded by voiding it, which records rather than
erases. The domain enforces that twice: a guard on the model refuses any change to a
frozen attribute once issued, and `restrictOnDelete` in the schema refuses the removal.

A surface could still have published an update route that always failed. This one
publishes no verb that could ask, `RoutingTest` asserts it over the route table, and
`OpenApiParityTest` asserts it over the document. Both are cheap; neither can be
satisfied by a comment.

`Exceptions\DocumentsAreImmutable` is nevertheless in the failure map, mapped to 409.
It cannot currently be raised through this surface, because there is no route that could
raise it. It is mapped so that one appearing tomorrow could not arrive as a 500.

## 2. A refusal is a fact, not a status

The domain returns two different things when it will not do something.

An **exception** is a condition the caller had no business reaching: a reference that is
not theirs, an amount whose currency is not one. Three concrete classes, mapped in a
table, asserted exhaustively.

A **`RefusalReason`** is a decision the domain made and *returned*, as part of an
`Outcome`. Twenty of them. They reach a caller as the error `code` itself — the domain's
own name for the decision, unchanged — with a status and a `resubmittable` flag. Nothing
here invents a second vocabulary for a decision the domain already named.

That table is a `match` over the enum rather than an array, so a reason added to the
domain fails static analysis in this package rather than arriving as a 500. The array
version would have compiled.

### `resubmittable` is about the identical request

True means the same request could succeed later: a correctable input (422), or a seam
this deployment has not bound (503).

Three delivery refusals are 502 or 503 and are **not** resubmittable, which looks wrong
until you read `Actions\RecordDelivery`. It writes the attempt row *before* it asks the
transport. So an unbound transport, a transport that failed and a transport that
suppressed have all already spent the delivery reference: repeating the identical call
answers `already_recorded` and transmits nothing. Sending again needs a new reference,
which is a different request. Saying "resubmittable" there would be this surface lying
about what a retry does.

## 3. There is no idempotency key

Every write on this surface already has a natural key the database enforces:

| Write | Key |
|---|---|
| Draft a document | `(merchant, kind, source_ref)` — the sale |
| Draft a credit note | `(merchant, credit_note, source_ref)` — the refund |
| Open a series | `(merchant, code)` |
| Record a delivery | `(merchant, reference)` |

The cause exists before this module does, so there is nothing to mint and nothing for a
client to hold. A key the client holds is a key the client can change, which makes it
strictly weaker than the index already arbitrating the write. Repeat the call and you
get `already_recorded` with the reference already minted.

`Actions\IssueDocument` and `Actions\VoidDocument` are idempotent by state rather than
by key: a second issuance of an issued document, and a second void of a void one, both
answer `already_recorded`.

## 4. Erasure and export are person-wide, and the ability says so

`Actions\ForgetParticipant` and `Queries\ExportParticipantRecord` take a subject
reference and walk **every tenant**. That is the domain's decision, and the right one: a
subject-access request is about a person, and the application this module replaces
redacted the customer row in place with no retention rule anywhere, silently rewriting
every invoice ever issued to them.

This surface presents them as they are. Three consequences, all deliberate:

- The ability is **`invoicing:platform-privacy`**, named for what it is. A merchant's
  operator credential cannot reach either endpoint.
- The merchant on the credential is **not consulted** by either endpoint, and the suite
  asserts it: a platform credential attached to merchant A erases the person at merchant
  B too. Filtering the *answer* while the *write* stayed person-wide would have been
  worse than publishing both.
- Both answers publish `tenant_id` on every row. It is the only place on this surface
  the merchant leaves, and it has to: a refusal that will not say whose document refused
  cannot be acted on.

A host that needs a merchant-scoped erasure needs a domain query that does not exist.
That is recorded in `docs/adoption.md` as a gap, not solved here.

### Retention outranks erasure, and the conflict is in the body

`POST /erasures` answers **200** whatever happens, with `complete` on the face of it.
Contact details, delivery addresses and free-text notes always go. The buyer's identity
on a document still inside its retention window does not, and every refusal names the
document, its number and the date the window ends.

A document issued under **no** configured retention window is refused too, with
`window_is_unknown: true`. Unknown is not zero. Guessing in either direction is how a
host comes to rewrite its own statutory records.

Money never changes. The suite asserts the summary is identical before and after.

## 5. Two answers this surface refuses to collapse

**A rendition with nothing bound is a 200, not an error.** `Queries\RenderDocument`
returns the render model either way: the module still knows everything the document
says, it just has no file. So the endpoint publishes `rendered: false` with the reason
named, and the document beside it. That is the blast radius of the missing binding and
nothing more. `no_renderer_bound` and `renderer_declined` are separate reasons because
they are separate facts: a deployment that has chosen no renderer, and one that has and
which produced nothing for this document.

**Four wrong references are one answer.** A document belonging to another merchant, a
document belonging to another buyer, a document whose buyer has been erased, and a
reference nobody ever minted all answer 404 with the same body. The suite compares the
two bodies rather than the two statuses, because a differing message publishes the
distinction just as well as a differing status does.

## 6. The listing carries a name and no contact details

An invoice listing without the buyer's name is not an invoice listing, so
`ListedDocument` carries `buyer_name`. It carries neither the address nor the email:
those are on the document, which is one reference away, and a listing does not need
them. Wave 11 shipped reviewer PII on a public listing; this is the same rule applied
before anybody asked.

## 7. No database key ever leaves

A document is named by its `reference`, a series by its `code`. `Data\Outcome` carries
an `id` and `Http\Present` drops it.

The application this module replaces labelled the `invoices` table's auto-increment
primary key "Invoice #" and showed it to customers, which published the platform's
running count of every invoice ever issued and is, in every jurisdiction that regulates
invoicing, not a valid invoice number.
