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
  static targets = ['video', 'thumbs', 'submitBtn', 'audioBtn', 'audioStatus', 'pendingCount', 'statusMsg', 'note', 'printToggle', 'profile', 'fileInput', 'price', 'recordings'];
  static values = { captureUrl: { type: String, default: '/api/camera/capture' }, inventory: Boolean, csrfToken: String };

  connect() {
    this.camera = new Camera();
    this.audio = new AudioRecorder();
    this.speech = new Speech();
    this.transcript = '';
    this.notePrefix = '';
    this._onConnectivityChange = () => { this._updateSpeechAvailability(); if (navigator.onLine) this.queue.retryErrors(); };
    this.photos = [];
    this.audioBlob = null;
    this.audioNotes = [];

    this.queue = new CaptureQueue({ endpoint: this.captureUrlValue,
      ...(this.inventoryValue ? { dbName: 'inventoryit_captures', directUpload: false, csrfToken: this.csrfTokenValue } : {}),
    });
    this.queue.onProgress = () => this._refreshPendingCount();
    this.queue.onConfirmed = (capture) => {
      if (this.inventoryValue && capture.serverResponse.reviewUrl) {
        window.location.assign(capture.serverResponse.reviewUrl);
        return;
      }
      this._setStatus('Saved ✓');
      this._refreshPendingCount();
    };
    this.queue.onError = (item) => this._setStatus(`Upload error (will retry): ${item.error}`);
    this.queue.start();
    if (this.inventoryValue && navigator.onLine) this.queue.retryErrors();
    this._refreshPendingCount();

    // Connectivity can change mid-sale — a phone in a garage drifts on and off
    // wifi constantly, so this is checked continuously rather than once on load.
    window.addEventListener('online', this._onConnectivityChange);
    window.addEventListener('offline', this._onConnectivityChange);
    this._updateSpeechAvailability();

    // The template ticks "print now"; a device that unticked it keeps it unticked.
    try {
      const saved = localStorage.getItem('priceit.printNow');
      if (saved !== null && this.hasPrintToggleTarget) this.printToggleTarget.checked = saved === '1';
    } catch {
      // Storage blocked: the template default stands.
    }

    this.camera.open(this.videoTarget).catch((err) => this._setStatus(`Camera error: ${err.message}`));

    // Let go of the camera whenever this tab is out of sight. Android Chrome gives the camera
    // to one tab at a time, and a capture tab left open in the background was holding it, so
    // the Price it page showed a black screen.
    this._onVisibility = () => {
      if (document.hidden) {
        this.camera.close();
      } else {
        this.camera.open(this.videoTarget).catch((err) => this._setStatus(`Camera error: ${err.message}`));
      }
    };
    document.addEventListener('visibilitychange', this._onVisibility);
  }

  printChanged() {
    try {
      localStorage.setItem('priceit.printNow', this.printToggleTarget.checked ? '1' : '0');
    } catch {
      // Storage blocked: the choice lasts until the page reloads.
    }
  }

  /** Whatever is in the box, typed or dictated. */
  _note() {
    return this.hasNoteTarget ? this.noteTarget.value.trim() : this.transcript.trim();
  }

  disconnect() {
    document.removeEventListener('visibilitychange', this._onVisibility);
    this.camera.close();
    this.audio.cancel();
    if (this.speech.isListening) this.speech.stop();
    window.removeEventListener('online', this._onConnectivityChange);
    window.removeEventListener('offline', this._onConnectivityChange);
    this.queue.stop();
    [...(this.photoUrls || []), ...(this.recordingUrls || [])].forEach(url => URL.revokeObjectURL(url));
  }

  async snap() {
    try {
      const blob = await this.camera.capture();
      if (this.inventoryValue && this.photos.length >= 3) return this._setStatus('Use up to three photos: label, item, detail.');
      this.photos.push(blob);
      this._renderThumbs();
    } catch (err) {
      this._setStatus(`Capture failed: ${err.message}`);
    }
  }

  /** Opens the native picker. The <input> stays hidden so the button matches the others. */
  pickFiles() {
    this.fileInputTarget.click();
  }

  /**
   * Photos from the phone's own camera app or its library.
   *
   * The in-page <video> capture is framed by whatever getUserMedia hands back, which
   * is a fixed crop and the wrong aspect for most objects — fine for reading a
   * barcode, poor for a photo someone will buy from. The native picker gives the
   * real camera app: correct framing, HDR, flash, and the chance to retake before
   * committing. It also reaches the library, so a picture taken earlier still counts.
   *
   * A File IS a Blob, so nothing downstream changes — thumbnails and the offline
   * outbox take these unmodified.
   *
   * Deliberately no downscaling: eBay and Etsy both want large images (Etsy asks for
   * 2000px on the long edge), so shrinking here to save upload bytes would degrade
   * the listing to save a few seconds.
   */
  addFiles(event) {
    const chosen = Array.from(event.target.files ?? []).filter((f) => f.type.startsWith('image/'));

    if (chosen.length === 0) {
      return;
    }

    if (this.inventoryValue && this.photos.length + chosen.length > 3) return this._setStatus('Choose up to three photos for one item.');
    this.photos.push(...chosen);
    this._renderThumbs();
    this._setStatus(`Added ${chosen.length} photo${chosen.length === 1 ? '' : 's'}`);

    // Cleared so picking the same file twice in a row still fires a change event.
    event.target.value = '';
  }

  removePhoto(event) {
    const index = Number(event.currentTarget.dataset.index);
    this.photos.splice(index, 1);
    this._renderThumbs();
  }

  async toggleAudio() {
    if (this.inventoryValue) return this._toggleRecording();
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
    this.notePrefix = this.transcript;
    this.speech.reset();
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
    this.audioBtnTarget.textContent = this.inventoryValue ? '🎙️ Add voice note' : '🎙️ Say something about it';
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

    this.audioBtnTarget.hidden = this.inventoryValue ? !window.MediaRecorder : !usable;

    if (!usable && this.speech.isListening) {
      this.transcript = this.speech.stop();
      this.audioStatusTarget.textContent = 'Connection lost — finish the note by typing';
    }
  }

  /** Keep the note prompt honest: it asks a different question per profile. */
  profileChanged() {
    if (!this.hasProfileTarget || !this.hasNoteTarget) return;
    const option = this.profileTarget.selectedOptions[0];
    const placeholder = option?.dataset.notePlaceholder;
    if (placeholder) this.noteTarget.placeholder = placeholder;
  }

  async submit() {
    if (this.submitting || this.recordingBusy) return;
    if (this.photos.length === 0) {
      this._setStatus('Take at least one photo first');
      return;
    }

    this.submitting = true;
    if (this.audio.isRecording) await this._toggleRecording();
    if (this.speech.isListening) this.speech.stop();
    this.submitBtnTarget.disabled = true;
    try {
      await this.queue.enqueue({
        photos: this.photos,
        audio: this.audioBlob,
        audioNotes: this.audioNotes,
        metadata: {
          ...(this._note() ? { transcript: this._note() } : {}),
          // Chosen per capture: an auction lot and a loan-closet item are photographed
          // the same way but described, labelled, and filed differently.
          profile: this.hasProfileTarget ? this.profileTarget.value : 'auction',
          // Sent per capture, so it can be turned off for one odd item without
          // changing a setting somewhere else.
          print: this.hasPrintToggleTarget ? this.printToggleTarget.checked : false,
          // Blank means "let the AI price it"; a number here wins over the AI's.
          ...(this.hasPriceTarget && this.priceTarget.value.trim() !== '' ? { price: this.priceTarget.value.trim() } : {}),
        },
      });
    } catch (err) {
      this.submitting = false;
      this.submitBtnTarget.disabled = false;
      this._setStatus(`Could not save locally: ${err.message}`);
      return;
    }

    this._setStatus('Queued — uploading in the background');
    this.photos = [];
    this.audioNotes = [];
    this._renderRecordings();
    this.clearNote();
    if (this.hasPriceTarget) this.priceTarget.value = '';
    this._renderThumbs();
    this._refreshPendingCount();
    this.submitting = false;
  }

  async retryUploads() {
    await this.queue.retryErrors();
  }

  async _toggleRecording() {
    if (this.recordingBusy) return;
    this.recordingBusy = true;
    try {
      if (this.audio.isRecording) {
        const blob = await this.audio.stop();
        if (this.speech.isListening) this.speech.stop();
        this.audioNotes.push(blob);
        this._renderRecordings();
        this.audioBtnTarget.textContent = '🎙️ Add another voice note';
        this.audioStatusTarget.textContent = 'Recording kept. Check the note text below; only that text is used for identification.';
      } else {
        if (this.audioNotes.length >= 10) return this._setStatus('Use up to ten voice notes per item.');
        await this.audio.start();
        this.audioBtnTarget.textContent = '⏹️ Finish voice note';
        this.audioStatusTarget.textContent = 'Recording…';
        if (Speech.isSupported() && navigator.onLine) {
          this.notePrefix = this._note();
          this.speech.reset();
          this.speech.onUpdate = text => { this.noteTarget.value = this._composeNote(text); };
          this.speech.onError = () => { this.audioStatusTarget.textContent = 'Recording continues. Type a summary below if dictation is unavailable.'; };
          try { this.speech.start(); } catch { /* Recording is retained even without dictation. */ }
        }
      }
    } catch (err) {
      this._setStatus(`Voice note: ${err.message}`);
    } finally {
      this.recordingBusy = false;
    }
  }

  removeRecording(event) {
    this.audioNotes.splice(Number(event.currentTarget.dataset.index), 1);
    this._renderRecordings();
    this._setStatus('Recording removed. Edit its text below if needed.');
  }

  _renderRecordings() {
    if (!this.hasRecordingsTarget) return;
    (this.recordingUrls || []).forEach(url => URL.revokeObjectURL(url));
    this.recordingUrls = this.audioNotes.map(blob => URL.createObjectURL(blob));
    this.recordingsTarget.replaceChildren();
    this.recordingUrls.forEach((url, i) => {
      const row = document.createElement('div');
      const player = document.createElement('audio');
      player.controls = true;
      player.src = url;
      player.setAttribute('aria-label', `Voice note ${i + 1}`);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = `Remove note ${i + 1}`;
      remove.dataset.action = 'capture#removeRecording';
      remove.dataset.index = i;
      row.append(player, remove);
      this.recordingsTarget.append(row);
    });
  }

  _renderThumbs() {
    (this.photoUrls || []).forEach(url => URL.revokeObjectURL(url));
    this.photoUrls = this.photos.map(blob => URL.createObjectURL(blob));
    this.thumbsTarget.innerHTML = this.photos
      .map((blob, i) => `
        <div class="capture-thumb">
          <img src="${this.photoUrls[i]}" alt="">
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
