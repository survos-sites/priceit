import { Controller } from '@hotwired/stimulus';
import { Camera } from 'camera-bundle/camera';
import { AudioRecorder } from 'camera-bundle/audio-recorder';
import { CaptureQueue } from 'camera-bundle/capture-queue';

/**
 * Composes camera-bundle's plain classes into the actual capture-page UI: snap one or more
 * photos, optionally record a spoken note, submit as one Item to the offline outbox.
 *
 * Targets: video, thumbs, submitBtn, audioBtn, audioStatus, pendingCount, statusMsg
 */
export default class extends Controller {
  static targets = ['video', 'thumbs', 'submitBtn', 'audioBtn', 'audioStatus', 'pendingCount', 'statusMsg'];
  static values = { captureUrl: { type: String, default: '/api/camera/capture' } };

  connect() {
    this.camera = new Camera();
    this.audio = new AudioRecorder();
    this.photos = [];
    this.audioBlob = null;

    this.queue = new CaptureQueue({ endpoint: this.captureUrlValue });
    this.queue.onProgress = () => this._refreshPendingCount();
    this.queue.onConfirmed = () => {
      this._setStatus('Saved ✓');
      this._refreshPendingCount();
    };
    this.queue.onError = (item) => this._setStatus(`Upload error (will retry): ${item.error}`);
    this.queue.start();
    this._refreshPendingCount();

    this.camera.open(this.videoTarget).catch((err) => this._setStatus(`Camera error: ${err.message}`));
  }

  disconnect() {
    this.camera.close();
    this.audio.cancel();
    this.queue.stop();
  }

  async snap() {
    try {
      const blob = await this.camera.capture();
      this.photos.push(blob);
      this._renderThumbs();
    } catch (err) {
      this._setStatus(`Capture failed: ${err.message}`);
    }
  }

  removePhoto(event) {
    const index = Number(event.currentTarget.dataset.index);
    this.photos.splice(index, 1);
    this._renderThumbs();
  }

  async toggleAudio() {
    if (this.audio.isRecording) {
      this.audioBlob = await this.audio.stop();
      this.audioBtnTarget.textContent = '🎙️ Re-record note';
      this.audioStatusTarget.textContent = 'Note recorded';
    } else {
      try {
        await this.audio.start();
        this.audioBtnTarget.textContent = '⏹️ Stop recording';
        this.audioStatusTarget.textContent = 'Recording…';
      } catch (err) {
        this._setStatus(`Mic error: ${err.message}`);
      }
    }
  }

  async submit() {
    if (this.photos.length === 0) {
      this._setStatus('Take at least one photo first');
      return;
    }

    try {
      await this.queue.enqueue({
        photos: this.photos,
        audio: this.audioBlob,
        metadata: {},
      });
    } catch (err) {
      this._setStatus(`Could not save locally: ${err.message}`);
      return;
    }

    this._setStatus('Queued — uploading in the background');
    this.photos = [];
    this.audioBlob = null;
    this.audioBtnTarget.textContent = '🎙️ Record a note';
    this.audioStatusTarget.textContent = '';
    this._renderThumbs();
    this._refreshPendingCount();
  }

  _renderThumbs() {
    this.thumbsTarget.innerHTML = this.photos
      .map((blob, i) => `
        <div class="capture-thumb">
          <img src="${URL.createObjectURL(blob)}" alt="">
          <button type="button" data-action="capture#removePhoto" data-index="${i}">✕</button>
        </div>
      `)
      .join('');
    this.submitBtnTarget.disabled = this.photos.length === 0;
  }

  async _refreshPendingCount() {
    if (!this.hasPendingCountTarget) return;
    const n = await this.queue.pendingCount();
    this.pendingCountTarget.textContent = n > 0 ? `${n} pending upload` : '';
  }

  _setStatus(msg) {
    if (this.hasStatusMsgTarget) this.statusMsgTarget.textContent = msg;
  }
}
