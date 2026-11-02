# InventoryIt (PriceIt repository)

InventoryIt adds a focused medical loan-closet flow to the existing PriceIt app:
**capture → identify → review → save to a collection**.

Open `/inventory` after signing in, or `/inventory/capture` to add an item. Use up
to three photos (label/box, item, detail) and multiple voice notes. The existing
camera, recording, dictation, IndexedDB queue and medical Symfony AI prompt are
reused. Uploaded evidence is a durable draft until the user reviews the suggestion
and explicitly saves it to **Medical loan closet**. Collection membership and an
asset number persist; repeat saves and upload retries do not duplicate the item.

The mobile review screen allows title, description, equipment type and storage
location corrections. AI suggestions remain separate from reviewed fields. Failed
identification can be retried or completed manually. Saving does not print, publish
to Quickbase, or declare equipment ready to lend. `/lookup`, legacy `/capture`, and
PriceIt's pricing/admin paths remain available.

### Run the inventory slice

```bash
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261102020000' --up
php bin/console messenger:consume item_suggest
php bin/console pwa:compile
```

Apply this migration once to an existing installation; it adds a collection table
and nullable inventory fields without rewriting existing items. The worker uses
the existing `ANTHROPIC_API_KEY` and `PRICEIT_AI_MODEL` configuration. Until it
finishes, the review page checks status and also permits manual completion.

Current boundaries:

- Collections are shared by all signed-in users of this installation; there is no
  per-user or per-organization isolation yet. Do not treat public self-registration
  as membership approval for a private staff deployment.
- Recordings are retained and playable. Existing browser dictation supplies note
  text where supported; otherwise type a summary. Raw audio is **not** transcribed
  by the server or sent to the image-identification model.
- Inventory uploads use validated multipart files (up to 12 MB each, three photos,
  ten recordings), CSRF protection and a separate local outbox. Existing stored-media
  URL delivery remains unchanged; private media access is still future work.
- The new flow supports queued upload/retry, not a verified cold-start offline
  inventory application. Collection/review pages require a connection. Unsubmitted
  photos and notes stay in memory until “Identify and review” queues them.
- The default collection is created on first save; collection creation/management,
  editing saved records in the mobile UI, matching existing items, checkout/return,
  QR printing and bar/pantry/tool-shed features are later increments.

Validation: `vendor/bin/phpunit` covers evidence upload/retry, review/save/reopen,
CSRF/authentication, manual fallback and preservation of reviewed values. Run
`node --test tests/js/capture_queue.test.mjs` for multipart/legacy-outbox compatibility.
These checks use local fixtures and mocked model responses, not paid AI calls.

## PriceIt foundation

A garage sale is basically an inventory problem. **PriceIt** is a small Symfony app for solving it
in the most direct way possible: walk around with your phone, snap a photo (or three) of an item,
optionally speak a quick note about it, and hand the rest off to AI — a title, a fun description,
and a starting price.

It's also a working demo of a few reusable ideas:

- an **offline-first capture queue** (photo + audio → local outbox → background upload, survives
  a dropped connection) that's deliberately decoupled from this app's own domain model, so it can
  be lifted into other projects;
- a clean split between a **mobile capture app** (installable PWA, camera/mic only) and an
  **admin review app** (desktop-oriented, Tabler-based) sharing one Symfony backend;
- a from-scratch, docs-verified integration of [`spomky-labs/pwa-bundle`](https://github.com/Spomky-Labs/pwa-bundle)'s
  manifest/favicon/service-worker compiler, using its current (non-deprecated) config shape.

## The idea

```
select camera or mic
  → snap one or more photos, optionally record a spoken note
  → hit "Save item" — queued locally, uploads in the background
  → AI reads the photo(s) + note and proposes a title, description, and price
  → review/edit the suggestion on the admin side, mark it priced
```

Capture, inventory and admin require sign-in. The public `/lookup` route retains quick
garage-sale identification and pricing. Each capture starts a new item.

## Architecture

For the proposed inventory-first direction, see the [Scanfony design](docs/scanfony.md):
a focused fw-bundle medical loan-closet demo, with multiple photos and voice notes
per item, review, and save to a collection. Museum find/add follows; bar, pantry
and tool shed illustrate later uses.
The generic MCP plugin architecture is deferred until a couple of working apps
reveal what should be shared. The core loan-closet capture/review/save slice is implemented above; broader domains remain proposed.

Two front ends, one Symfony backend, one SQLite database:

| | Mobile app (`/`) | Admin (`/admin`) |
|---|---|---|
| Purpose | Capture only | Review, edit, price |
| UI | Framework7 PWA chrome (`survos/fw-bundle`) | Tabler admin dashboard (`survos/tabler-bundle`) |
| Entry point | `assets/app.js` | `assets/admin.js` |
| Needs a live connection? | No — offline-first | Yes |
| Installable / offline | Full PWA: manifest, icons, service worker, Workbox caching | No |

The two are intentionally independent — the admin UI doesn't load Framework7, Dexie, or the
camera bundle; the mobile app doesn't load Tabler or Bootstrap. They only meet at the database.

### `Survos\CameraBundle` — the capture engine

The offline-first capture/upload logic lives in its own namespace at
[`src/CameraBundle`](src/CameraBundle), *not* under the `App\` namespace, so it can be lifted out
wholesale into a real Composer package (`mono/bu/camera-bundle`) the moment a second app needs it
— see [survos/mono#24](https://github.com/survos/mono/issues/24) for the fuller design this is
following (offline-first event/photo capture, `EventProviderInterface`-style host boundary).

It owns exactly one seam into the host app:

```php
interface CaptureHandlerInterface
{
    public function handle(CaptureRequest $request): CaptureResult;
}
```

`CaptureRequest` is just a client id, a list of uploaded photos, an optional audio file, and free
metadata — the bundle has no idea what an "Item" is. `App\Service\ItemCaptureHandler` is the one
class that implements the interface and knows how to turn a capture into an `Item` + `Media` rows.
Everything else in the bundle — the Dexie-backed outbox, the camera device picker, the audio
recorder — is plain, framework-agnostic JS:

- [`assets/capture_queue.js`](src/CameraBundle/assets/capture_queue.js) — `CaptureQueue`: persists
  each capture session (photos + optional audio + metadata) to IndexedDB via Dexie, drains it with
  retry, and recovers uploads interrupted by a closed tab or a dead connection. Adapted from a
  working scanstation capture queue (`ssai`), stripped of everything scanner-specific.
- [`assets/camera.js`](src/CameraBundle/assets/camera.js) — `Camera`: `getUserMedia` device
  enumeration/ranking (prefers the rear/environment camera on phones) and still-frame capture.
- [`assets/audio_recorder.js`](src/CameraBundle/assets/audio_recorder.js) — `AudioRecorder`:
  `MediaRecorder`-based note recording. Deliberately *not* live Web Speech transcription — that
  needs an active connection and isn't supported in Firefox, which would break the offline-first
  premise. A recorded blob works fully offline; transcription becomes a server-side AI step later.

`assets/controllers/capture_controller.js` (in the main app, not the bundle) is the Stimulus glue
that wires these three classes to the actual capture page markup.

### Data model

```
Item            title, description, price, transcript, status, clientId (idempotency key)
 └─ Media (×N)  kind: photo|audio, Vich-uploaded file, mime type, size
```

`Item.clientId` is the browser-generated UUID from the capture session — if the outbox retries an
upload after a network blip, the server recognizes the same id and returns the existing item
instead of creating a duplicate.

`Item`/`Media` are also exposed via API Platform (`GET /api/items`, `GET /api/items/{id}`,
`PATCH /api/items/{id}`) for anything that wants to consume the collection programmatically —
the admin UI itself just queries the repository directly and renders Twig.

### PWA / offline

The mobile app is a fully installable, offline-capable PWA (`config/packages/pwa.yaml`):

- Manifest + icons + favicons, compiled by `bin/console pwa:compile` (wired into
  `composer.json`'s `auto-scripts`, alongside `assets:install`/`importmap:install`).
- A service worker (Workbox) caches the app shell itself (`StaleWhileRevalidate` on navigation
  requests) plus JS/CSS/fonts/images at runtime, with an offline fallback for a cold first visit.
- **Deliberately not** using Workbox's `background_sync`: `CaptureQueue` already owns upload retry,
  cross-browser (the Background Sync API itself is Chromium-only). Layering both would double-
  queue the same request.
- `{{ pwa() }}` in `templates/base.html.twig`'s `<head>` is what actually injects the manifest
  link, favicon tags, theme-color meta, and the service-worker registration script — nothing does
  this automatically, and the current bundle README doesn't mention it either; the real recipe is
  on the bundle's own docs site.

## Tech stack

- **Symfony 8.1**, SQLite (zero setup for a demo; swap the `DATABASE_URL` for anything Doctrine
  supports)
- **API Platform** for the `Item`/`Media` read/write API
- **VichUploaderBundle** for the photo/audio file uploads
- **`survos/fw-bundle`** (Framework7 + AssetMapper + Dexie glue) for the mobile PWA chrome
- **`survos/tabler-bundle`** for the admin dashboard
- **`spomky-labs/pwa-bundle`** for manifest/icon/service-worker generation
- **Dexie** (IndexedDB) for the client-side offline capture outbox
- No build step — everything ships through Symfony AssetMapper + native browser import maps

## Setup

```bash
composer install
php bin/console doctrine:migrations:migrate
symfony server:start
```

First run also compiles the PWA assets (manifest, icons, service worker) via `pwa:compile`,
wired into Composer's `post-install-cmd`/`post-update-cmd`. Re-run it by hand after changing
anything under `pwa:` in `config/packages/pwa.yaml`:

```bash
php bin/console pwa:compile
```

Visit `/` for the capture app, `/admin` for the review dashboard.

## Routes

| Route | Method | Purpose |
|---|---|---|
| `/` | GET | Mobile capture page |
| `/api/camera/capture` | POST | Capture-bundle upload endpoint (multipart: `client_id`, `photos[]`, `audio`, `metadata`) |
| `/admin` | GET | Item review list |
| `/admin/items/{id}` | GET | Item detail — photo gallery, audio player, edit form |
| `/admin/items/{id}/edit` | POST | Save title/description/price |
| `/api/items` | GET/PATCH | API Platform resource |

## AI pricing and photo lookup

`PricingSuggestionService` uses Symfony AI 1.0 beta 1's Anthropic platform for both
stored-item suggestions and `/lookup`. It sends up to three photos plus the note,
with profile-specific prompts and structured JSON output. Configure
`ANTHROPIC_API_KEY` and `PRICEIT_AI_MODEL` as before. The provider's effort setting
and 16,000-token output budget are preserved. Refusals, truncated output, invalid
JSON and HTTP errors are returned as failed suggestions.

The two Symfony AI packages use `^1.0.0-beta1`; other dependencies retain stable
resolution. Run `vendor/bin/phpunit` for the mocked HTTP integration tests; these
do not spend API credits or send photos to Anthropic.

## Roadmap / deliberately not built yet

- **Marketplace export** — once AI produces a clean title/description/price, "post to eBay" or
  "post to Freecycle" becomes a separate export target off the same `Item`, not a separate capture
  flow.
- **Native wrapper (Capacitor)** — the capture flow is already plain JS talking to one HTTP
  endpoint, which is exactly the shape Capacitor's "bundled" mode wants: swap `getUserMedia`/
  `MediaRecorder` for native camera/filesystem plugins to get a real background upload and a more
  reliable camera than a mobile browser, while the admin side stays an ordinary web app.
- **`camera-bundle` extraction** — once a second app needs it, promote `src/CameraBundle` to a
  real `mono/bu/camera-bundle` Composer package per survos/mono#24.

## Credits

The capture-queue and camera-selection patterns are adapted from a working scanstation capture
pipeline built for a different project (document/photo digitization) — same offline-outbox idea,
stripped of everything specific to that domain (ArUco markers, deskewing, OCR triggers).
