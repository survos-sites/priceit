# Scanfony direction: focused apps first

Status: **Current design proposal**, 2026-11-01. Documentation only; the apps below
are not implemented by this change. This replaces the earlier plan to build one
universal camera app with a generic MCP plugin contract.

## Decision

Build the apps we want, each based on **survos/fw-bundle**, with an explicit,
domain-specific interface. Start with a patch finder, then build museum find/add.
Keep PriceIt's garage-sale and medical loan-closet workflows useful in their own
right. Reuse working capture components; do not require a universal workflow engine,
plugin discovery or server-driven form renderer before shipping an app.

After two or three apps work, inspect their actual overlap and decide what belongs
in shared bundles or a common protocol. Separate apps does not require deciding all
repository/deployment boundaries now. Each should be independently understandable
and runnable; the chosen hackathon demo should have a straightforward setup. There
is no requirement to embed every app into PriceIt or force every capture into its
`Item` entity.

The objective is a compelling working application and practical museum workflows.
Novelty as an “identify anything” platform is not a prerequisite.

## First app: OA patch finder

**Photograph a patch → read its lettering/number → look up catalog records → compare
reference images → confirm → add to my collection.**

For this first scope, use patches with readable names and numbers. That makes them
a useful OCR-and-lookup problem. It does not demonstrate general visual reasoning,
and the app does not need a generative model, an AI agent or MCP to work. OCR may
use machine-learning techniques internally; the product path remains OCR plus
ordinary lookup, with no frontier-model dependency.

### Smallest useful experience

1. Photograph the front or select an existing image. Permit a retake or a closer
   image when the lettering is unclear.
2. Try the user-reported `tacman1123/tiny-ocr` npm package to extract text. Confirm
   its actual package/API, supported runtime and fw-bundle/AssetMapper integration
   before choosing how to load it; this proposal has not verified those details.
3. Display the extracted text for correction. Preserve the original OCR text and
   user edits separately. Allow manual search when recognition is incomplete.
4. Normalize whitespace/case and search the available catalog by name and number.
   Use additional catalog fields where available, without inventing identifiers or
   treating uncertain OCR as an exact match.
5. Show candidate reference images, catalog identifiers and distinguishing details.
   A number/name may identify a group rather than a unique patch variant. Let the
   user compare and choose; support “none of these.”
6. Save the selected record to the user's collection, optionally with their own
   photograph and note. Retrying a save must not create duplicate collection entries
   for the same operation; intentional multiple owned copies remain possible.

The first interface is deliberately patch-specific. Ordinary routes, typed PHP
services, forms and result templates are sufficient. Another photograph is a simple
app interaction, not a generic continuation-token protocol.

### Data and lookup

The first dependency is an authorized, usable reference catalog with stable record
IDs, names/numbers and reference images. Inspect the actual data before designing
an importer or promising match quality. Use a small representative subset first;
label synthetic fixtures if real records are unavailable. Keep catalog records
separate from the user's owned copies.

The minimum domain concepts are a reference record, a capture/search attempt and an
owned-copy association. Persist only what the experience needs; do not implement the
previous proposal's generic sessions/rounds/executions/outbox model by default.
Preserve source image and corrected query when saving them helps review or retry.

Start with whichever simple lookup path fits the catalog, such as a Symfony endpoint
querying its database. A downloadable SQLite catalog queried through SQLite-WASM is
an optional later experiment, not an MVP dependency. It adds catalog size, image
availability, updates and browser-storage questions. Offline capture and offline
catalog lookup are separate capabilities; describe only the ones actually tested.

### What is not in the first patch app

No AI-generated year or price, visual embeddings, automatic variant confirmation,
label-printing platform, generic outcome registry or dynamic plugin forms. Display
a year if it is supported by the selected catalog record; do not guess it from a
plausible model answer. Valuation would need its own evidence and sources.

If later evaluation shows a useful role for visual AI in ambiguous cases, add it as
an optional feature and compare it with the OCR/lookup baseline. Do not add model
calls solely to label the patch finder an AI demonstration.

## Next app: museum find and add

A museum app addresses two concrete staff workflows against the institution's own
catalog. It can share fw-bundle and capture code with the patch app while having its
own screens, authorization, records and services.

| Workflow | Steps | Successful result |
|---|---|---|
| **Find an existing object** | Photograph object and accession label/markings → extract identifiers or search descriptive fields → inspect candidates → staff confirms | A link to the correct authorized inventory record, with unresolved ambiguity visible |
| **Add a new object** | Capture multiple views → enter or extract markings and basic fields → review possible duplicates → staff reviews and saves | A new institution-approved inventory record with photographs and provenance of entered/suggested fields |

Start with one institution and one catalog integration. For finding, use accession
numbers and other existing identifiers when available. Unmarked objects can later
benefit from descriptive or visual search; do not assume every object can be found
from a photograph. Ask for another view or a measurement through the museum app's
own interface when necessary.

For adding, require staff review of any OCR or AI suggestions. A failure to find a
match is not proof the object is absent from inventory. Respect institution-specific
required fields, permissions and identifier-assignment rules. Do not autonomously
assign provenance, authenticity or valuation. Printing an inventory label can be a
small explicit action after confirmation, integrated with that institution's printer.
It does not require a generic action-binding language.

AI has a plausible role here: drafting a description, suggesting search terms or
helping compare candidates. Introduce it where it demonstrably helps staff, using
Symfony AI services directly. Keep the original observations, suggestions and
reviewed catalog values distinct.

The museum offering is practical integration work: catalog access and cleanup,
matching, duplicate review and acquisition workflows. The reusable camera experience
reduces repeated work without eliminating those institution-specific needs.

## PriceIt and the shared foundation

The existing foundation was inspected at `c6fe5a0`; later implementation should check
current code rather than assume this document is a fresh audit.

| Existing code | Near-term reuse |
|---|---|
| [CameraBundle](../src/CameraBundle) and [capture queue](../src/CameraBundle/assets/capture_queue.js) | Reuse camera/audio capture, upload retry and recovery where needed. Keep the host boundary domain-independent. |
| [ItemCaptureHandler](../src/Service/ItemCaptureHandler.php) | Preserve the existing PriceIt host adapter; other apps can have their own handler rather than pretending every capture is a priced item. |
| [CaptureProfile](../src/Profile/CaptureProfile.php), [Item](../src/Entity/Item.php), [Media](../src/Entity/Media.php) | Preserve garage-sale and medical-equipment behavior. These are real domain models, not a template every new app must inherit. |
| [PricingSuggestionService](../src/Service/PricingSuggestionService.php) | Keep the working Symfony AI pricing integration. It is an existing AI-demo path; patch OCR does not have to replace it. |
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

1. **Verify patch data and OCR integration.** Inspect a representative catalog subset
   and run tiny-ocr on real patch photographs, including difficult lettering. Establish
   whether corrected name/number lookup produces useful candidates.
2. **Ship the patch finder.** Capture, correct OCR, look up, compare and save. Make
   this a polished fw-bundle/pwa-bundle demonstration without a model dependency.
3. **Build museum find/add.** Connect one real or clearly labeled demo inventory and
   implement the two staff paths, reusing only components that actually fit.
4. **Choose the AI hackathon demonstration on evidence.** Existing PriceIt pricing or
   a useful museum description/search feature can demonstrate Symfony AI. An OCR-only
   patch finder should be described honestly; verify the event requirements before
   choosing it as the sole submission. No model call is required just to decorate it.
5. **Review shared needs after two or three working apps.** Compare code, user flows
   and integration friction before deciding whether more bundling or MCP is useful.

No repositories, package integrations or deployed apps are created by this design
change. The immediate deliverable is a simpler plan; implementation starts with
catalog and OCR verification.

## Deferred: generic plugins and additional domains

The [earlier MCP/plugin architecture](scanfony-plugins-deferred.md) is preserved as
an exploration, **not an implementation backlog or current acceptance criteria**.
Its acquisition metadata, follow-up tokens, generic match/action contracts, tool
catalog synchronization, execution framework and signed completion webhooks are
parked. So is the one-app-does-it-all requirement. The upstream Symfony AI references
remain there for future use.

Revisit only when working apps reveal repeated needs for independently deployed
backends, shared capture instructions or remote tool access. MCP can expose useful
services later without dictating today's user experience. Keep native PHP services
simple enough to adapt, but do not build unused extension points.

Coins, wine, school-lunch trays and general object identification remain possible
future apps, not commitments for this MVP. Smartify deserves separate investigation
later; no competitor study or follow-up automation is part of this change.

## Acceptance criteria for the simpler direction

### Patch finder

- [ ] A real authorized catalog subset and its image/identifier coverage are documented.
- [ ] Capture and OCR work on the intended mobile browser; manual text correction and
      lookup remain usable when OCR fails. The tiny-ocr integration is verified.
- [ ] Name/number lookup presents reference images and stable identifiers, including
      ambiguous variants and no-match cases; it never silently confirms a candidate.
- [ ] A user can save the chosen record/photo to their collection without accidental
      duplicates on retry. Intentional multiple copies remain distinguishable.
- [ ] The core flow requires no generative AI credentials, agent, MCP server or
      generic plugin framework. Any claimed offline behavior is tested separately.

### Museum find/add

- [ ] Staff can find and confirm a record in one authorized inventory, with ambiguous
      and missing matches handled explicitly.
- [ ] Staff can capture several images, review extracted/suggested fields and possible
      duplicates, then create a record under the institution's rules.
- [ ] Suggestions do not overwrite reviewed catalog facts. Cross-user/institution
      access is denied, and writes or optional label printing require explicit action.

### Reuse and scope

- [ ] Apps use fw-bundle and reuse proven capture code without adopting a universal
      domain model or workflow description language.
- [ ] PriceIt's existing garage-sale and loan-closet paths remain intact.
- [ ] Shared abstraction decisions follow evidence from two or three working apps.

For implementation, validate lookup against known reference records and exercise
capture/correction/save on an actual mobile browser. Add focused tests for lookup,
permissions and duplicate writes. Documentation checks alone do not satisfy these
application acceptance criteria.
