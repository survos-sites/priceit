import { Controller } from '@hotwired/stimulus';

/*
 * Quick lookup: photos in, "what is it and what should we charge" out.
 *
 * The request is answered while the phone waits. No outbox, because nothing is saved:
 * if it fails, the person presses the button again.
 */
const MAX_PHOTOS = 3;
// Claude reads images at about this size, so sending more only costs upload time on a hotspot.
const MAX_EDGE = 1568;

export default class extends Controller {
    static targets = ['form', 'thumbs', 'note', 'ask', 'status', 'result'];
    static values = { url: String };

    connect() {
        this.photos = [];
    }

    async add(event) {
        const files = [...event.target.files];
        event.target.value = ''; // the same photo can be picked again after removing it
        for (const file of files) {
            if (this.photos.length >= MAX_PHOTOS) break;
            this.photos.push(await shrink(file));
        }
        this.render();
    }

    remove(event) {
        this.photos.splice(Number(event.currentTarget.dataset.index), 1);
        this.render();
    }

    render() {
        this.thumbsTarget.innerHTML = this.photos.map((blob, i) => `
            <div class="thumb">
                <img src="${URL.createObjectURL(blob)}" alt="">
                <button type="button" data-action="lookup#remove" data-index="${i}">✕</button>
            </div>`).join('');
        this.askTarget.disabled = this.photos.length === 0;
    }

    async ask() {
        if (this.photos.length === 0) return;

        const form = new FormData();
        this.photos.forEach((blob, i) => form.append('photos[]', blob, `photo-${i}.jpg`));
        form.append('note', this.noteTarget.value.trim());

        this.askTarget.disabled = true;
        const started = Date.now();
        const tick = () => {
            this.statusTarget.textContent = `Looking up… ${Math.round((Date.now() - started) / 1000)}s`;
        };
        tick();
        this.timer = setInterval(tick, 1000);

        try {
            const response = await fetch(this.urlValue, { method: 'POST', body: form });
            const answer = await response.json().catch(() => ({ ok: false, message: `The server answered ${response.status}.` }));
            if (!answer.ok) throw new Error(answer.message || 'No answer came back.');
            this.show(answer.data);
        } catch (err) {
            this.statusTarget.textContent = navigator.onLine
                ? `Didn't work: ${err.message} Press Price it! to try again.`
                : 'No connection. Press Price it! again once you are back online.';
            this.askTarget.disabled = false;
        } finally {
            clearInterval(this.timer);
        }
    }

    show(d) {
        const money = (n) => `$${Number(n).toFixed(Number(n) % 1 ? 2 : 0)}`;
        const online = d.onlineLowUsd && d.onlineHighUsd
            ? `<p class="online">Sells online for <strong>${money(d.onlineLowUsd)}–${money(d.onlineHighUsd)}</strong></p>`
            : '';
        this.resultTarget.innerHTML = `
            <div class="card">
                <h3>${esc(d.title)}</h3>
                <span class="confidence ${esc(d.confidence)}">${esc(d.confidence)} confidence</span>
                <p class="price">${money(d.priceUsd)}</p>
                <p class="price-note">Friday/Saturday price. Half that on Sunday.</p>
                ${online}
                <p class="why">${esc(d.why)}</p>
                <p class="desc">${esc(d.description)}</p>
            </div>
            <button type="button" class="again" data-action="lookup#reset">📷 Price the next item</button>`;
        this.formTarget.hidden = true;
        this.resultTarget.hidden = false;
        window.scrollTo(0, 0);
    }

    reset() {
        this.photos = [];
        this.noteTarget.value = '';
        this.render();
        this.statusTarget.textContent = '';
        this.resultTarget.hidden = true;
        this.resultTarget.innerHTML = '';
        this.formTarget.hidden = false;
        window.scrollTo(0, 0);
    }
}

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Scale a phone photo down to what the model reads, as JPEG. Falls back to the original. */
async function shrink(file) {
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        return blob || file;
    } catch {
        return file;
    }
}
