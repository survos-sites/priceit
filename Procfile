# Without an explicit docroot the buildpack serves the project root and every
# request is a 403 — the app deploys "successfully" and answers nothing.
web: vendor/bin/heroku-php-nginx -C nginx.conf -F fpm_custom.conf public/

# One messenger:consume per item.* transport, plus async. Without this a capture
# lands, the kickoff queues, and the item sits at "new" forever, which reads as
# the AI being broken rather than as a missing process.
#
# --queue async is not optional now that registration requires a confirmed email:
# SendEmailMessage is routed to async (config/packages/messenger.yaml), -w item
# matches only item.*, and UnverifiedUserChecker refuses a login until the address
# is confirmed. Without something draining async the confirmation is queued and
# never sent, so every new account is a permanent dead end -- and it looks like the
# mail provider is broken rather than like a transport nobody is listening to.
worker: php bin/console survos:supervisor -w item --queue async --no-tui
