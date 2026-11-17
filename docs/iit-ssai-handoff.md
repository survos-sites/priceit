# IIT → SSAI closeout, 2026-11-17

## Completed

- Historical `survos-sites/scanner` injected JavaScript removed; README points to
  IIT. Pushed `fe3895c`.
- IIT book profile (ISBN lookup, WASM fallback, copy grouping, local export) pushed
  as `d66659a` on main. Uses existing Framework7 and Symfony 8.2 beta.
- Stone outbox now uploads through IIT to SSAI. Originals, capture time, GPS, pose
  and photographer grouping are retained in SSAI. Successful receipts require
  matching capture IDs and checksums. Old local shots gain stable IDs before upload.
- Real local HTTP + database/filesystem smoke test confirms one record after a retry
  and 409 on changed evidence. Automated tests cover lost responses, mismatched
  receipts, invalid GPS/IDs, CSRF, missing configuration and separate capture IDs.
- Credentials configured locally in both `.env.local` files; not committed. IIT uses
  SSAI `https://127.0.0.1:8021`. Both apps use their existing shared PostgreSQL instance.

## Next work / acceptance

1. **Rollout and phone test:** deploy SSAI receiver first, set shared dedicated
   `IIT_CAPTURE_KEY` and receiver tenant, ensure persistent `AI_TOOLS_SHARED_DIR`,
   then deploy IIT and point `IIT_CAPTURE_URL` at the reachable HTTPS receiver.
   Verify on the S24 using its original capture hostname: old outbox drains; offline
   shots survive reload; interrupted uploads produce one SSAI image; sign-in expiry
   preserves photos; GPS/pose/grouping and checksums match. Production is not yet
   deployed/configured by this work. Do not infer success from the local smoke test.
2. **OCR and MemorialAtlas:** stored images are tagged grave and AI-locked, with
   `processing=pending`. Add an explicit bounded/retryable hosted-rusto workflow;
   use existing imagor/rusto routes, never a new OCR server. Keep capture
   acknowledgement independent of OCR. Carry `mobileCapture` GPS/grouping through
   cemetery-scoped marker/person resolution and downstream MemorialAtlas import.
3. **Books:** currently a browser inventory with export, not SSAI/server sync. Test
   real ISBN recognition on Android and the WASM fallback; add server-backed book
   collections if needed. ISBN identifies an edition, not a physical copy.

## Worktree boundaries

Do not sweep unrelated changes into commits. The SSAI checkout already contained
AGENTS/config generation edits and changes to `IntakeImportCommand.php` from other
work. This delivery commits the reusable LocalCaptureService but leaves the command
changes for their owner. MemorialAtlas/scanfony also had pending local-intake work
from earlier in the conversation; inspect its current diff before extending it.

## Local commands

```bash
# IIT (/Users/tac/sites/priceit)
symfony server:start -d
node --test tests/js/book_grouping.test.mjs tests/js/stone_capture.test.mjs tests/js/stone_upload.test.mjs
vendor/bin/phpunit tests/Service/BookLookupTest.php tests/Service/StoneCaptureUploadTest.php tests/Controller/BookCaptureControllerTest.php tests/Controller/StoneControllerTest.php

# SSAI (/Users/tac/sites/ssai)
vendor/bin/phpunit tests/Dto/MobileCaptureTest.php tests/Service/LocalCaptureServiceTest.php
```

Canonical upload contract: `museado/ssai:docs/iit-mobile-captures.md`.
