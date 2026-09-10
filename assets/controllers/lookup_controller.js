import { Controller } from '@hotwired/stimulus';
import { Camera } from 'camera-bundle/camera';

/*
 * Price it: camera -> "Researching..." -> the answer -> back to the camera.
 *
 * One photo per item, sent the moment it is taken. The request is answered while the phone
 * waits, and the server keeps the photo and the answer as an item for next year's comparison.
 *
 * The camera is released whenever this page is not showing the viewfinder (researching, the
 * answer, the tab in the background), so a PriceIt tab left open elsewhere does not hold it.
 */
// Claude reads images at about this size, so sending more only costs upload time on a hotspot.
const MAX_EDGE = 1568;

export default class extends Controller {
    static targets = ['cameraStep', 'video', 'torch', 'cameraError', 'researchStep', 'preview', 'spinner',
        'status', 'retry', 'resultStep', 'resultPhoto', 'result'];
    static values = { url: String };

    connect() {
        this.camera = new Camera();
        this.torchOn = false;
        this.onVisibility = () => {
            if (document.hidden) this.camera.close();
            else if (!this.cameraStepTarget.hidden) this.openCamera();
        };
        document.addEventListener('visibilitychange', this.onVisibility);
        this.openCamera();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        clearInterval(this.timer);
        this.camera.close();
    }

    async openCamera() {
        this.cameraErrorTarget.hidden = true;
        try {
            await this.camera.open(this.videoTarget);
            const track = this.camera.stream?.getVideoTracks()[0];
            this.torchTarget.hidden = !track?.getCapabilities?.().torch;
            this.torchOn = false;
            this.torchTarget.classList.remove('on');
        } catch (err) {
            this.cameraErrorTarget.textContent = `No live camera (${err.message}). Use "Camera app" below instead.`;
            this.cameraErrorTarget.hidden = false;
        }
    }

    async toggleTorch() {
        const track = this.camera.stream?.getVideoTracks()[0];
        if (!track) return;
        this.torchOn = !this.torchOn;
        try {
            await track.applyConstraints({ advanced: [{ torch: this.torchOn }] });
            this.torchTarget.classList.toggle('on', this.torchOn);
        } catch {
            this.torchOn = false;
            this.torchTarget.hidden = true;
        }
    }

    async shoot() {
        if (!this.camera.stream) return;
        let blob;
        try {
            blob = await this.camera.capture(0.9);
        } catch (err) {
            this.cameraErrorTarget.textContent = `Could not take the photo: ${err.message}`;
            this.cameraErrorTarget.hidden = false;
            return;
        }
        this.camera.close();
        this.photo = await shrink(blob);
        this.send();
    }

    async pick(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        this.camera.close();
        this.photo = await shrink(file);
        this.send();
    }

    async send() {
        if (!this.photo) return;
        this.show('research');
        this.previewTarget.src = this.photoUrl();
        this.spinnerTarget.hidden = false;
        this.retryTarget.hidden = true;

        const started = Date.now();
        const tick = () => { this.statusTarget.textContent = `Researching… ${Math.round((Date.now() - started) / 1000)}s`; };
        tick();
        clearInterval(this.timer);
        this.timer = setInterval(tick, 1000);

        const form = new FormData();
        form.append('photos[]', this.photo, 'photo.jpg');

        try {
            const response = await fetch(this.urlValue, { method: 'POST', body: form });
            const answer = await response.json().catch(() => ({ ok: false, message: `The server answered ${response.status}.` }));
            if (!answer.ok) throw new Error(answer.message || 'No answer came back.');
            this.render(answer.data);
            this.show('result');
        } catch (err) {
            this.spinnerTarget.hidden = true;
            this.statusTarget.textContent = navigator.onLine ? `Didn't work: ${err.message}` : 'No connection.';
            this.retryTarget.hidden = false;
        } finally {
            clearInterval(this.timer);
        }
    }

    next() {
        this.photo = null;
        this.show('camera');
        this.openCamera();
    }

    show(step) {
        this.cameraStepTarget.hidden = step !== 'camera';
        this.researchStepTarget.hidden = step !== 'research';
        this.resultStepTarget.hidden = step !== 'result';
        if (step === 'result') this.resultStepTarget.scrollTop = 0;
    }

    photoUrl() {
        if (this.photoObjectUrl) URL.revokeObjectURL(this.photoObjectUrl);
        this.photoObjectUrl = URL.createObjectURL(this.photo);
        return this.photoObjectUrl;
    }

    render(d) {
        const money = (n) => `$${Number(n).toFixed(Number(n) % 1 ? 2 : 0)}`;
        this.resultPhotoTarget.src = this.photoObjectUrl;
        this.resultTarget.innerHTML = `
            <div class="lk-card">
                <h2>${esc(d.title)}</h2>
                <span class="lk-conf ${esc(d.confidence)}">${esc(d.confidence)} confidence</span>
                <p class="lk-price">${money(d.priceUsd)}</p>
                <p class="lk-note">Friday/Saturday price. Half that on Sunday.</p>
                ${d.tagPriceUsd > 0 ? `<p class="lk-line">Tag says <strong>${money(d.tagPriceUsd)}</strong></p>` : ''}
                ${d.onlineLowUsd && d.onlineHighUsd ? `<p class="lk-line">Sells online for <strong>${money(d.onlineLowUsd)}–${money(d.onlineHighUsd)}</strong></p>` : ''}
                <p class="lk-why">${esc(d.why)}</p>
                <p class="lk-desc">${esc(d.description)}</p>
            </div>`;
    }
}

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Scale a photo down to what the model reads, as JPEG. Falls back to the original. */
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
