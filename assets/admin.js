/*
 * Admin entrypoint — deliberately independent of assets/app.js: the review/pricing area has no
 * camera, no Framework7, no Dexie outbox. Tabler's own JS (dropdowns, offcanvas sidebar) needs
 * Bootstrap's JS bundle, same as zm's assets/app.js.
 */
import * as bootstrap from 'bootstrap';
import '@tabler/core/dist/css/tabler.min.css';
import './styles/admin.css';

window.bootstrap = bootstrap;
