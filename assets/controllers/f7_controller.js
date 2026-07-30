import { Controller } from '@hotwired/stimulus';
import Framework7 from 'framework7/bundle';
import 'framework7/css/bundle';
import 'framework7-icons/css/framework7-icons.min.css';

export default class extends Controller {
    connect() {
        window.app = new Framework7({
            el: this.element,
            theme: 'auto',
            // Framework7 auto-creates a routed "main view" on .view-main and hijacks every
            // <a> click inside it via its own AJAX router. PriceIt is plain server-rendered
            // Symfony pages with F7 as PWA chrome only, so every link must go through a real
            // browser navigation instead.
            clicks: {
                externalLinks: 'a',
            },
        });
    }
}
