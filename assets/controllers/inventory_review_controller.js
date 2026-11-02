import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String };
    static targets = ['status'];

    connect() {
        // Never reload over someone's manual edits while the worker is finishing.
        this.dirty = false;
        this.onEdit = () => { this.dirty = true; };
        this.element.closest('.inventory-record').addEventListener('input', this.onEdit);
        this.polls = 0;
        this.timer = setInterval(() => this.poll(), 3000);
    }

    disconnect() {
        clearInterval(this.timer);
        this.element.closest('.inventory-record')?.removeEventListener('input', this.onEdit);
    }

    async poll() {
        if (this.loading) return;
        this.loading = true;
        try {
            const response = await fetch(this.urlValue, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Status unavailable');
            const status = await response.json();
            if (!status.pending) {
                clearInterval(this.timer);
                if (!this.dirty) window.location.reload();
                else this.statusTarget.textContent = 'Identification finished. Your edits are kept on this page; reload only if you want to replace them with the suggestion.';
            } else if (++this.polls >= 40) {
                clearInterval(this.timer);
                this.statusTarget.textContent = 'Still waiting for identification. You can complete the record now or return to this draft later.';
            }
        } catch {
            this.statusTarget.textContent = 'Cannot check identification right now. Your uploaded draft is kept; return to Collections or reload when connected.';
        } finally {
            this.loading = false;
        }
    }
}
