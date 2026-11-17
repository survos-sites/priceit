import { Controller } from '@hotwired/stimulus';
import Dexie from 'dexie';
import { Camera } from 'camera-bundle/camera';
import { decideBookGroup } from '../lib/book_grouping.js';

export default class extends Controller {
  static targets = ['video', 'isbn', 'status', 'current', 'list'];
  static values = { owner: String, lookup: String };

  async connect() {
    this.camera = new Camera();
    this.db = new Dexie(`book-inventory:${this.ownerValue}`);
    this.db.version(1).stores({ copies: 'id,isbn,createdAt', photos: 'id,copyId' });
    this.ready = this.db.open().then(async () => {
      this.current = await this.db.copies.orderBy('createdAt').last();
      await this.render();
    }).catch(error => { this.failed = true; this.say(`Storage unavailable: ${error.message}`); });
    this.visibility = () => { if (document.hidden) this.stop(); };
    document.addEventListener('visibilitychange', this.visibility);
  }

  disconnect() {
    this.disconnected = true;
    this.stop();
    document.removeEventListener('visibilitychange', this.visibility);
    this.audio?.close();
    this.db.close();
  }

  say(message) { if (!this.disconnected) this.statusTarget.textContent = message; }

  async start() {
    if (this.running || this.starting) return;
    this.starting = true;
    try {
      await this.ready;
      if (this.failed) return;
      this.audio ??= new AudioContext();
      await this.audio.resume();
      let Detector = globalThis.BarcodeDetector;
      if (!Detector || !(await Detector.getSupportedFormats()).includes('ean_13')) {
        ({ BarcodeDetector: Detector } = await import('barcode-detector/ponyfill'));
      }
      this.detector = new Detector({ formats: ['ean_13'] });
      await this.camera.open(this.videoTarget);
      if (this.disconnected || document.hidden) { this.camera.close(); return; }
      this.running = true;
      this.say('Point at the book’s ISBN barcode.');
      void this.scan();
    } catch (error) { this.stop(); this.say(`Scanner unavailable: ${error.message}. You can enter an ISBN below.`); }
    finally { this.starting = false; }
  }

  stop() { this.running = false; clearTimeout(this.timer); this.camera?.close(); }

  async scan() {
    if (!this.running) return;
    try {
      if (this.videoTarget.readyState >= 2 && !this.busy) {
        const codes = await this.detector.detect(this.videoTarget);
        if (this.running) for (const code of codes) {
          if (await this.record(code.rawValue)) break;
        }
      }
    } catch (error) { this.say(`Could not read barcode: ${error.message}`); }
    if (this.running) this.timer = setTimeout(() => this.scan(), 350);
  }

  async manual(event) { event.preventDefault(); await this.record(this.isbnTarget.value, false, true); }
  async newCopy() {
    if (this.current) await this.record(this.current.isbn, true, true);
    else this.say('Scan an ISBN first.');
  }

  async record(input, newCopy = false, manual = false) {
    await this.ready;
    if (this.failed || this.busy) return false;
    const decision = decideBookGroup(this.current, input, newCopy);
    if (decision.action === 'reject') { if (manual) this.say(decision.reason); return false; }
    if (decision.action === 'repeat') { if (manual) this.say('Already on this copy. Choose “New copy” for another physical book.'); return true; }
    this.busy = true;
    try {
      const row = { id: crypto.randomUUID(), isbn: decision.isbn, createdAt: new Date().toISOString(), reason: decision.reason, lookupStatus: 'pending' };
      await this.db.copies.add(row);
      this.current = row;
      this.beep();
      this.say(`Saved ISBN ${row.isbn}. Ready for the next book.`);
      await this.render();
      void this.lookup(row);
      return true;
    } catch (error) { this.say(`Not saved: ${error.message}`); return false; }
    finally { this.busy = false; }
  }

  beep() {
    try {
      if (!this.audio || this.audio.state !== 'running') return;
      const oscillator = this.audio.createOscillator(), gain = this.audio.createGain();
      oscillator.frequency.value = 880; gain.gain.value = 0.08;
      oscillator.connect(gain).connect(this.audio.destination);
      oscillator.start(); oscillator.stop(this.audio.currentTime + 0.12);
      oscillator.onended = () => { oscillator.disconnect(); gain.disconnect(); };
    } catch { /* A saved record does not depend on speaker availability. */ }
  }

  async lookup(row) {
    try {
      const response = await fetch(this.lookupValue.replace('ISBN', row.isbn), { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(15000) });
      if (!response.ok || response.redirected) throw new Error('Lookup unavailable');
      const book = await response.json();
      await this.db.copies.update(row.id, { book, lookupStatus: book.found ? 'found' : 'not-found' });
    } catch {
      if (!this.disconnected) await this.db.copies.update(row.id, { lookupStatus: 'retry' });
    }
    if (!this.disconnected) await this.render();
  }

  async retryLookup() { if (this.current) await this.lookup(this.current); }

  async photo() {
    await this.ready;
    if (!this.current || !this.camera.stream) { this.say('Scan a book and start the camera before attaching a photo.'); return; }
    if (this.busy) return;
    this.busy = true;
    const copyId = this.current.id;
    try {
      const blob = await this.camera.capture();
      await this.db.photos.add({ id: crypto.randomUUID(), copyId, capturedAt: new Date().toISOString(), blob, groupingReason: 'operator-attached-to-current-copy' });
      this.beep(); this.say('Photo saved with this copy.'); await this.render();
    } catch (error) { this.say(`Photo not saved: ${error.message}`); }
    finally { this.busy = false; }
  }

  async render() {
    const rows = await this.db.copies.orderBy('createdAt').reverse().toArray();
    if (this.disconnected) return;
    if (this.current) this.current = rows.find(row => row.id === this.current.id) || this.current;
    this.currentTarget.textContent = this.current ? `Current: ${this.current.book?.title || this.current.isbn}` : 'No current book';
    this.listTarget.replaceChildren();
    for (const row of rows) {
      const li = document.createElement('li');
      const count = await this.db.photos.where('copyId').equals(row.id).count();
      li.textContent = `${row.book?.title || row.isbn} · ${row.isbn} · ${count} photos · ${row.lookupStatus}`;
      this.listTarget.append(li);
    }
  }

  async export() {
    await this.ready;
    try {
      const copies = await this.db.copies.toArray(), photos = await this.db.photos.toArray();
      const readBlob = blob => new Promise((resolve, reject) => {
        const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = () => reject(reader.error); reader.readAsDataURL(blob);
      });
      const evidence = await Promise.all(photos.map(async ({ blob, ...photo }) => ({ ...photo, dataUrl: await readBlob(blob) })));
      const url = URL.createObjectURL(new Blob([JSON.stringify({ version: 1, profile: 'book', copies, photos: evidence })], { type: 'application/json' }));
      const a = document.createElement('a'); a.href = url; a.download = 'book-inventory.json'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (error) { this.say(`Export failed: ${error.message}`); }
  }
}
