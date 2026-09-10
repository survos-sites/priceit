import { Controller } from '@hotwired/stimulus';

/*
 * Shows the right way to install PriceIt on this device.
 *
 * pwa-bundle ships an install controller, but it can't be used: it counts
 * `window.self === window.top` as "already installed", and that is true of every
 * page that isn't in an iframe, so it hides the install button everywhere.
 *
 * Chrome on Android gets a real button from beforeinstallprompt. iOS never fires
 * that event, so it gets the Share -> Add to Home Screen steps. If Android hasn't
 * offered the prompt after a few seconds (already installed, or Chrome decided
 * not to), the manual menu steps are shown instead.
 */
export default class extends Controller {
    static targets = ['installed', 'prompt', 'ios', 'android', 'qr'];

    connect() {
        if (this.isStandalone()) {
            this.show('installed');
            this.qrTarget.hidden = true;
            return;
        }

        if (this.isIos()) {
            this.show('ios');
            return;
        }

        this.onPrompt = (event) => {
            event.preventDefault();
            window.priceitInstallPrompt = event;
            this.show('prompt');
        };
        window.addEventListener('beforeinstallprompt', this.onPrompt);

        this.onInstalled = () => this.show('installed');
        window.addEventListener('appinstalled', this.onInstalled);

        if (window.priceitInstallPrompt) {
            this.show('prompt');
        } else {
            this.fallback = setTimeout(() => {
                if (!window.priceitInstallPrompt) {
                    this.show('android');
                }
            }, 2500);
        }
    }

    disconnect() {
        window.removeEventListener('beforeinstallprompt', this.onPrompt);
        window.removeEventListener('appinstalled', this.onInstalled);
        clearTimeout(this.fallback);
    }

    async install() {
        const prompt = window.priceitInstallPrompt;
        if (!prompt) {
            this.show('android');
            return;
        }

        // A prompt can be used once, whatever the answer.
        window.priceitInstallPrompt = null;
        prompt.prompt();
        const { outcome } = await prompt.userChoice;
        this.show(outcome === 'accepted' ? 'installed' : 'android');
    }

    show(which) {
        for (const name of ['installed', 'prompt', 'ios', 'android']) {
            this[`${name}Target`].hidden = name !== which;
        }
    }

    isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: fullscreen)').matches
            || window.navigator.standalone === true;
    }

    isIos() {
        const ua = navigator.userAgent;
        // iPadOS reports itself as a Mac; the touch points give it away.
        return /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    }
}
