#!/usr/bin/env bash

# Dev accounts. Same shape as harvest/kpa/openfoto — trivial passwords on purpose,
# because these only ever exist on a local database.
#
# /admin, /items and /capture all require ROLE_USER (see security.yaml), so any of
# these can sign in. Registration through the UI needs an emailed confirmation;
# this skips that, which is what you want when the mail transport is not wired.

bin/console survos:user:create super@survos.com tt --roles ROLE_SUPER_ADMIN --force
bin/console survos:user:create tac@survos.com tt --roles ROLE_ADMIN --force
bin/console survos:user:create tacman@gmail.com tt --roles ROLE_ALLOWED_TO_SWITCH --roles ROLE_ADMIN --force

# Dave, the seller. ROLE_USER only -- security.yaml gates /admin, /items and
# /capture on ROLE_USER, so this is enough to review his own items and see what
# would be listed, without the admin roles above.
bin/console survos:user:create dglass123@hotmail.com tt --roles ROLE_USER --force

# UnverifiedUserChecker refuses a login until isVerified, and the confirmation
# arrives by email — which is not wired locally. These accounts never go through
# registration, so mark them verified directly.
bin/console dbal:run-sql "UPDATE \"user\" SET is_verified = true WHERE email IN ('super@survos.com','tac@survos.com','tacman@gmail.com','dglass123@hotmail.com')" >/dev/null
echo "  → marked verified"
