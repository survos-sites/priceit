import { Controller } from '@hotwired/stimulus';
import { Camera } from 'camera-bundle/camera';
import { AudioRecorder } from 'camera-bundle/audio-recorder';
import { Speech } from 'camera-bundle/speech';
import { CaptureQueue } from 'camera-bundle/capture-queue';

/**
 * Composes camera-bundle's plain classes into the actual capture-page UI: snap one or more
 * photos, optionally record a spoken note, submit as one Item to the offline outbox.
 *
 * Targets: video, thumbs, submitBtn, audioBtn, audioStatus, pendingCount, statusMsg
 */
export default class extends Controller {
  static targets = ['video', 'thumbs', 'submitBtn', 'audioBtn', 'audioStatus', 'pendingCount', 'statusMsg', 'note', 'printToggle'];
  static values = { captureUrl: { type: String, default: '/api/camera/capture' } };

  connect() {
    this.camera = new Camera();
    this.audio = new AudioRecorder();
    this.speech = new Speech();
    this.transcript = '';
    this.notePrefix = '';
    this._onConnectivityChange = () => this._updateSpeechAvailability();
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

    // Connectivity can change mid-sale — a phone in a garage drifts on and off
    // wifi constantly, so this is checked continuously rather than once on load.
    window.addEventListener('online', this._onConnectivityChange);
    window.addEventListener('offline', this._onConnectivityChange);
    this._updateSpeechAvailability();

    this.camera.open(this.videoTarget).catch((err) => this._setStatus(`Camera error: ${err.message}`));
  }

  /** Whatever is in the box, typed or dictated. */
  _note() {
    return this.hasNoteTarget ? this.noteTarget.value.trim() : this.transcript.trim();
  }

  disconnect() {
    this.camera.close();
    this.audio.cancel();
    if (this.speech.isListening) this.speech.stop();
    window.removeEventListener('online', this._onConnectivityChange);
    window.removeEventListener('offline', this._onConnectivityChange);
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
    // The button only exists when dictation is usable, so there is no
    // fallback branch here any more: no connection means no button, and the
    // note gets typed. Recording audio nobody transcribes helped no one.
    return this._toggleDictation();
  }

  _toggleDictation() {
    if (this.speech.isListening) {
      this.transcript = this.speech.stop();
      this.audioBtnTarget.textContent = this.transcript ? '🎙️ Say more' : '🎙️ Say something about it';
      this.audioStatusTarget.textContent = this.transcript ? '' : 'Nothing heard';
      return;
    }

    this.speech.onUpdate = (text, interim) => {
      // Straight into the textarea: spoken and typed notes end up in the same
      // place, so nothing has to reconcile two sources later — and a misheard
      // word can just be corrected by hand.
      this.transcript = this._composeNote(text);
      if (this.hasNoteTarget) {
        this.noteTarget.value = this.transcript;
        this.noteTarget.classList.toggle('is-interim', Boolean(interim));
      }
    };
    this.speech.onError = (err) => {
      this.audioStatusTarget.textContent =
        err === 'not-allowed' ? 'Microphone blocked — allow it in the address bar' : `Speech error: ${err}`;
      this.audioBtnTarget.textContent = '🎙️ Say something about it';
    };

    try {
      this.speech.start();
      this.audioBtnTarget.textContent = '⏹️ Stop';
      this.audioStatusTarget.textContent = 'Listening…';
    } catch (err) {
      this._setStatus(err.message);
    }
  }

  noteChanged() {
    // Typing wins: whatever is in the box is the note. Anything already
    // dictated becomes the base that further speech appends to.
    this.transcript = this.hasNoteTarget ? this.noteTarget.value : '';
    this.speech.reset();
    this.notePrefix = this.transcript;
  }

  clearNote() {
    this.speech.reset();
    this.transcript = '';
    this.notePrefix = '';
    this.audioBlob = null;
    if (this.hasNoteTarget) {
      this.noteTarget.value = '';
      this.noteTarget.classList.remove('is-interim');
    }
    this.audioStatusTarget.textContent = '';
    this.audioBtnTarget.textContent = '🎙️ Say something about it';
  }

  /** Dictated words appended to whatever was already typed. */
  _composeNote(spoken) {
    const typed = (this.notePrefix || '').trim();
    return typed ? `${typed} ${spoken}`.trim() : spoken;
  }

  /**
   * Dictation is offered only when it can actually work: the browser has to
   * implement SpeechRecognition, and there has to be a connection, because
   * Chrome recognises by sending the audio to a server. Offline it is hidden
   * rather than shown-and-broken, and the textarea carries the note instead.
   */
  _updateSpeechAvailability() {
    const usable = Speech.isSupported() && navigator.onLine;

    this.audioBtnTarget.hidden = !usable;

    if (!usable && this.speech.isListening) {
      this.transcript = this.speech.stop();
      this.audioStatusTarget.textContent = 'Connection lost — finish the note by typing';
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
        metadata: {
          ...(this._note() ? { transcript: this._note() } : {}),
          // Sent per capture, so it can be turned off for one odd item without
          // changing a setting somewhere else.
          print: this.hasPrintToggleTarget ? this.printToggleTarget.checked : false,
        },
      });
    } catch (err) {
      this._setStatus(`Could not save locally: ${err.message}`);
      return;
    }

    this._setStatus('Queued — uploading in the background');
    this.photos = [];
    this.clearNote();
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
