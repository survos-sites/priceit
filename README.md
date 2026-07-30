# PriceIt

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
  → (planned) AI reads the photo(s) + note and proposes a title, description, and price
  → review/edit the suggestion on the admin side, mark it priced
```

No login yet (single-household use case), no external event/schedule dependency — every visit to
the mobile app just starts a new item.

## Architecture

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

## Roadmap / deliberately not built yet

- **AI enrichment** — read the photo(s) + spoken-note transcript, propose a title, a fun
  description, and a starting price. `Item` already has `title`/`description`/`price`/`transcript`
  fields waiting for it. The natural fit is `survos/ai-workflow-bundle`'s `Task` pattern
  (`ObserveTask` → `GenerateTitleTask`), skipping its claims-ledger/workflow-subject machinery
  (built for a multi-source museum pipeline) in favor of calling the same underlying
  `Symfony\AI\Agent\AgentInterface` directly.
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
