# Without an explicit docroot the buildpack serves the project root and every
# request is a 403 — the app deploys "successfully" and answers nothing.
web: heroku-php-nginx -C nginx_app.conf public/

# One messenger:consume per item.* transport. Without this a capture lands, the
# kickoff queues, and the item sits at "new" forever, which reads as the AI
# being broken rather than as a missing process.
worker: php bin/console survos:supervisor -w item --no-tui
