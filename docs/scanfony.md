# Scanfony direction: inventory first

Status: **Current design proposal**, 2026-11-01. Documentation only; the proposed
experience and acceptance criteria below are not implemented by this change.
This supersedes patch-finder-first delivery and the universal MCP camera-app plan.

## Decision

Build a focused inventory experience based on **survos/fw-bundle**, with a
**medical loan closet as the first demo and AI hackathon application**. The useful
outcome is a saved, findable inventory record, not just a transient identification
or price estimate.

**Capture multiple photos and voice notes → understand the evidence → review →
save to a collection.**

Use PriceIt's existing capture and inventory foundation where it fits. Keep the
interface explicit and focused on loan-closet intake. Build museum find/add next
as a separate focused app. After two or three working apps, decide what to share.
Do not make a universal app, plugin discovery, configurable workflow engine or
server-driven form renderer a prerequisite. Repository and deployment boundaries
can follow implementation needs; the hackathon demo should have one straightforward
setup.

## First demo: medical loan closet

The unit of capture is **one physical item with several pieces of evidence**.
Photograph its label or box if available, then the item, then any useful details.
A wheelchair may have a model label; a shower seat may have no readable marking.
Both must work. Multiple voice notes supply facts that photographs cannot establish,
such as “donated today, put it on shelf B; the footrests are missing.”

### Smallest useful experience

1. Start an item in the loan-closet collection. Add several photos and voice notes
   to the same draft. Allow review, removal, retakes and more evidence before saving.
2. Read visible text with OCR and use Symfony AI to propose a name, equipment type,
   description and visible features from the combined evidence. Transcribe voice
   notes and retain their originals. Extract manufacturer, model, serial or asset
   identifiers only when present; an unlabeled item can still be inventoried.
3. Show an editable draft alongside its evidence. Distinguish extracted text,
   AI suggestions, user statements and confirmed values. Flag uncertainty and leave
   unknown values blank. A label on a box is supporting evidence, not proof that the
   object inside is that model.
4. If a useful field is unclear, offer a concrete follow-up such as “photograph the
   model label” or “add a view of the seat.” Let the user answer, add a voice note,
   or continue with unknown fields. Use ordinary app screens, not a generic plugin
   continuation protocol.
5. Show possible existing inventory records using identifiers and descriptive
   search. A model number identifies a product model, not an individual wheelchair.
   Let the user confirm an existing physical item or create a separate item. A
   missing match is not proof the item is new.
6. Confirm the description, quantity where appropriate, storage location and
   human-assessed condition. **Save to the collection** persists the reviewed record
   and its photos/notes; reopening the collection must find it again. Updating an
   existing item must be an explicit choice.
7. Optionally print a QR inventory label after saving and scan it to reopen the
   record. Label printing is a small follow-on, not a dependency of the core demo.

For example: capture a wheelchair's label, its whole frame and a detail photo;
record a note about its shelf and missing parts; review the proposed record; save
it to “Medical loan closet.” Repeat with an unlabeled shower seat to demonstrate
that identification is not limited to OCR lookup.

AI assists documentation and search. A photograph cannot certify safe operation,
load capacity, cleanliness or suitability for a person. Track readiness as a
separate human review; saving a record must not automatically mark it ready to lend.
Checkout, return and maintenance scheduling follow after intake/find/save works.

## Collections make capture useful later

A collection gives saved items a context and a place to return to. The initial app
can have a preconfigured loan-closet collection; it does not need an environment
marketplace or a configuration language. These examples show later uses of the
same capture/review/save pattern, not additional hackathon requirements:

| Collection | Capture and save | Later useful action |
|---|---|---|
| **My bar** | Bottle and label photos, identity, location, and a user-confirmed full/low/empty state | See what needs replenishing, based on a desired stock list and updated state |
| **My pantry / freezer** | Package or food photos, quantity, location, and voice notes such as “two portions of chili, cooked Sunday” | Find what is stored and maintain a shopping list; record dates when supplied |
| **My tool shed** | Tool and model-label photos, location, accessories and notes | Find a tool before buying another; later track who borrowed it |
| **Mom's storage unit** | Object/box photos and “Christmas decorations, box seven, back shelf” | Search the saved inventory and locate the box |

Replenishment requires both current stock information and a desired amount. A saved
photo becomes stale: do not imply that the app knows what was consumed afterward.
Later, simple actions such as **Low**, **Empty**, **Used one** and **Added two**, plus
an optional minimum stock target, can keep a collection useful. Approximate bottle
levels should be confirmed rather than presented as precise measurements. These
are small domain features to validate in a later app, not a generic action framework.
Pricing can become an optional action on a saved item; it is not required to create
useful inventory.

## Minimum data and processing model

Adapt existing entities after checking their current behavior; these are domain
concepts, not a mandate to create a second parallel inventory system.

| Concept | Minimum responsibility |
|---|---|
| Collection | Name, owner/organization and access rules; contains saved items |
| Inventory item | Stable physical-item ID, collection, reviewed name/description, identifiers, location and domain status; quantity only where meaningful |
| Evidence | Multiple ordered photos and voice recordings attached to one draft/item; upload status, optional view label, transcript/OCR text and source links |
| Analysis | Processing status, model/prompt version, proposed fields with evidence references and uncertainty; distinct from confirmed values |
| Review/save | User corrections and confirmation, stable save operation ID and saved record ID; changes must not silently replace reviewed facts |

Model/serial/asset identifiers are separate fields. Identical models can correspond
to multiple physical items; keep individually tracked loan equipment separate.
Preserve the relationship between each proposed fact and its source photo, OCR or
voice note so staff can inspect and correct it.

Keep processing states small: **draft → processing → ready for review → saved**.
A processing failure retains the draft and evidence, offers retry or manual entry,
and never pretends analysis succeeded. Additional evidence can trigger another
analysis; preserve user edits and mark suggestions based on older evidence as stale.
Queued processing should return a durable draft ID that the PWA can reopen and poll.
Use Messenger when needed; provider batch execution, push notifications and signed
completion webhooks are deferred until an actual integration needs them.

Use ordinary authenticated application endpoints for draft creation, evidence
upload/removal, analysis start/status, candidate search, confirmed save and collection
listing/detail. Reuse existing routes/services where possible. No new public generic
MCP contract is needed for this demo. Retried uploads and saves must not create
accidental duplicates, while intentional separate physical items remain possible.

## Next app: museum find and add

Build a focused staff experience against one institution's authorized inventory,
reusing proven capture components and retaining its own catalog rules and screens.

| Workflow | Steps | Successful result |
|---|---|---|
| **Find an existing object** | Photograph object and accession label/markings → extract identifiers or search descriptions → inspect candidates → staff confirms | The correct authorized inventory record, with ambiguity visible |
| **Add a new object** | Capture multiple views and voice notes → review suggested fields and possible duplicates → staff confirms and saves | A new inventory record with photographs and provenance of entered/suggested fields |

Prefer reliable identifiers when available. For unmarked objects, evaluate descriptive
and visual retrieval on the institution's actual photos, including different views,
lighting and backgrounds. Similar appearance is candidate evidence, not confirmation
of identity. Ask for another view or measurement when useful. No match is not proof
of absence, and model-generated provenance, authenticity or valuation is not fact.
Respect institutional required fields, permissions and identifier-assignment rules.

## PriceIt and the shared foundation

The existing foundation was inspected at `c6fe5a0`; later implementation should check
current code rather than assume this document is a fresh audit.

| Existing code | Near-term reuse |
|---|---|
| [CameraBundle](../src/CameraBundle) and [capture queue](../src/CameraBundle/assets/capture_queue.js) | Reuse camera/audio capture, upload retry and recovery where needed. Keep the host boundary domain-independent. |
| [ItemCaptureHandler](../src/Service/ItemCaptureHandler.php) | Preserve the existing PriceIt host adapter; other apps can have their own handler rather than pretending every capture is a priced item. |
| [CaptureProfile](../src/Profile/CaptureProfile.php), [Item](../src/Entity/Item.php), [Media](../src/Entity/Media.php) | Adapt existing inventory/capture concepts for the loan-closet demo while preserving garage-sale behavior. These are real domain models, not a template every new app must inherit. |
| [PricingSuggestionService](../src/Service/PricingSuggestionService.php) | Keep the working Symfony AI pricing integration. Pricing is an optional future action on a saved item, not the intake prerequisite. |
| [Item workflow](workflow/item.md) | Retain existing inventory transitions and Messenger processing. Add queues to new apps only when their actual processing requires them. |
| [Security configuration](../config/packages/security.yaml) | Reuse authentication patterns and implement appropriate ownership/catalog access in each app. |

Use fw-bundle for the mobile UI and pwa-bundle for installability/offline assets.
Share established camera/upload utilities first. Extract further reusable components
when a second app uses them; domain-specific search screens are acceptable. Do not
build a survey builder because POSSE previously needed one.

Keep upload validation, private-media access, retry deduplication and explicit
confirmation for inventory writes. These are ordinary app requirements, independent
of plugin architecture. Existing production/migration notes remain in the
[operational handoff](../HANDOFF-2026-08-25.md).

## Delivery order and hackathon scope

1. **Build loan-closet intake in PriceIt.** Group photos and voice notes per item,
   analyze the evidence, review the draft and save it to a collection. Use fw-bundle
   for the focused interface and verify the capture path on an actual phone.
2. **Demonstrate persistence and finding.** Reopen the collection, search saved items,
   inspect evidence and distinguish a possible existing item from a new same-model
   item. Include a labeled wheelchair and an unlabeled shower seat.
3. **Polish the hackathon story.** Show Symfony AI contributing a useful draft from
   multiple pieces of evidence, with OCR, user correction and durable saved results.
   Use a small authorized demo inventory. Document setup and required credentials;
   label fixtures clearly. Add QR labels only if the core experience is complete.
4. **Build museum find/add.** Integrate one real or clearly labeled demo catalog and
   reuse only components that actually fit. Bar, pantry and tool shed remain later
   examples rather than parallel MVP builds.
5. **Review common needs after two or three working apps.** Let repeated working
   code and integration requirements justify further bundles or remote services.

OA patch finding remains a useful optional OCR-and-catalog-lookup experiment, but
is no longer first in delivery order or the AI hackathon centerpiece. Readable names
and numbers may be sufficient without generative AI. Verify the catalog and the
user-reported tiny-ocr package before implementation; SQLite-WASM lookup is optional.

This change creates no app implementation, new dependency or deployment.

## Access and evidence handling

Enforce collection ownership and organization access on records, draft/status routes
and every media request. The existing single-household interface is not evidence of
multi-user authorization: implement and test that boundary before shared deployment.
Validate upload types and sizes, keep media private, and restrict any server-side
image fetch to trusted storage. Do not expose model credentials in the PWA.

Treat OCR, transcripts and image text as untrusted evidence, not instructions to
execute tools or change records. Send only necessary evidence to configured providers,
make that processing clear, and provide deletion/retention behavior for recordings
and images. Avoid capturing donor or patient information unnecessarily. Inventory
writes, candidate confirmation and label printing remain explicit user actions.

## Deferred: generic plugins

The [earlier MCP/plugin architecture](scanfony-plugins-deferred.md) remains an
exploration, **not an implementation backlog or current acceptance criteria**.
Generic acquisition metadata, follow-up tokens, match/action contracts, tool catalog
sync, external completion webhooks and the one-app-does-it-all requirement stay parked.

Revisit MCP when working apps need independently deployed services or remote tools.
The upstream references remain relevant context:
[Symfony AI #2003](https://github.com/symfony/ai/issues/2003),
[#2539](https://github.com/symfony/ai/issues/2539) and
[MCP bridge PR #2497](https://github.com/symfony/ai/pull/2497).
If adopted later, reuse Symfony AI DTOs and native `#[AsTool]` / `#[McpTool]`
registration rather than bespoke YAML or duplicated tool definitions. This is a
future integration direction, not a claim about the PR's current merge status.

## Acceptance criteria

### Loan-closet demo

- [ ] A phone user can attach multiple photos and multiple voice notes to one item,
      inspect them, retake/remove evidence and recover from an interrupted upload.
- [ ] Both a labeled wheelchair and an unlabeled shower seat produce reviewable
      drafts. Missing identifiers stay unknown; OCR and AI failures allow correction,
      retry or manual entry without losing evidence.
- [ ] Proposed fields can be traced to evidence and edited; user-confirmed values
      are preserved when analysis is retried or new evidence is added.
- [ ] Possible matches are candidates only. Two same-model items can be stored as
      different physical items; updating an existing record requires confirmation.
- [ ] Save persists the item, photos and notes in the selected collection. Reopening
      the app shows the saved record and supports finding it. Retry does not duplicate
      the same save operation.
- [ ] Readiness to lend requires human review and is not inferred from appearance.
- [ ] Collection and media permissions are enforced; credentials remain server-side.
- [ ] The demo works without MCP discovery, dynamic plugin forms, batch infrastructure
      or a generic workflow engine. Any claimed offline behavior is tested separately.

### Follow-on scope and reuse

- [ ] Museum find/add uses one authorized catalog, with staff confirmation, duplicate
      review and institutional field/access rules.
- [ ] Bar, pantry and tool shed remain documented examples; replenishment explicitly
      depends on updated stock state and user-specified targets.
- [ ] Apps use fw-bundle and proven capture components. Further abstractions follow
      evidence from working apps, and existing garage-sale behavior is preserved.

For implementation, exercise capture/review/save/reopen on an actual mobile browser
and test permissions, retry deduplication and preservation of user edits. Documentation
checks alone do not satisfy these application acceptance criteria.
