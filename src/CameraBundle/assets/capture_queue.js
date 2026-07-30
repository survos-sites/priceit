/**
 * CaptureQueue — Dexie-backed persistent outbox for item captures.
 *
 * Unlike a per-photo capture queue, one queue record here is one whole capture session: an
 * array of photo blobs plus an optional audio blob, uploaded together as one multipart POST.
 * Adapted from ssai's scanstation/capture_queue.js (same lifecycle: new -> uploading ->
 * confirmed | error), trimmed of anything scanstation-specific (batch/station ids).
 *
 * Lifecycle: new -> uploading -> confirmed | error
 *
 * Usage:
 *   import { CaptureQueue } from 'camera-bundle/capture_queue';
 *   const queue = new CaptureQueue({ endpoint: '/api/camera/capture' });
 *   queue.onProgress = (capture) => updateUI(capture);
 *   await queue.enqueue({ photos: [blob1, blob2], audio: null, metadata: {} });
 *   queue.start();
 */

import Dexie from 'dexie';

const DB_NAME = 'camera_bundle_captures';
const DB_VERSION = 1;

class CaptureDb extends Dexie {
  constructor() {
    super(DB_NAME);
    this.version(DB_VERSION).stores({
      captures: '++id, status, capturedAt, clientId',
    });
  }
}

export class CaptureQueue {
  /** @param {{ endpoint: string, concurrency?: number }} opts */
  constructor(opts = {}) {
    this.endpoint = opts.endpoint || '/api/camera/capture';
    this.concurrency = opts.concurrency || 1;
    this.db = new CaptureDb();
    this._running = false;
    this._inFlight = 0;

    /** Called with the capture record on every status change. */
    this.onProgress = null;
    /** Called when a capture is confirmed by the server. */
    this.onConfirmed = null;
    /** Called on error. */
    this.onError = null;
  }

  /**
   * Add a capture session to the queue.
   * @param {{ photos: Blob[], audio: Blob|null, metadata?: Object }} capture
   * @returns {Promise<number>} Dexie record id
   */
  async enqueue({ photos, audio, metadata = {} }) {
    const clientId = crypto.randomUUID ? crypto.randomUUID() : String(Date.now());
    console.log('[capture-queue] enqueue: %d photo(s), audio=%o', photos.length, !!audio);
    let id;
    try {
      id = await this.db.captures.add({
        status: 'new',
        capturedAt: Date.now(),
        clientId,
        photos,
        audio: audio || null,
        metadata,
      });
    } catch (err) {
      console.error('[capture-queue] enqueue failed — IndexedDB write rejected:', err);
      throw err;
    }
    console.log('[capture-queue] enqueued id=%d clientId=%s', id, clientId);
    this._notify(id);
    if (this._running) this._drain();
    return id;
  }

  /** Start the queue drain loop. Call once after constructing. */
  start() {
    this._running = true;
    console.log('[capture-queue] started, endpoint=%s', this.endpoint);
    this._drain();
    this._recoverStuck();
  }

  stop() {
    this._running = false;
  }

  /** Resume items that were 'uploading' when the page closed. */
  async _recoverStuck() {
    const stuck = await this.db.captures.where('status').equals('uploading').toArray();
    for (const item of stuck) {
      await this.db.captures.update(item.id, { status: 'new' });
    }
    if (stuck.length) this._drain();
  }

  async _drain() {
    if (!this._running || this._inFlight >= this.concurrency) {
      console.log('[capture-queue] _drain: blocked — running=%o inFlight=%d', this._running, this._inFlight);
      return;
    }

    const next = await this.db.captures.where('status').equals('new').first();
    if (!next) {
      console.log('[capture-queue] _drain: nothing to drain');
      return;
    }
    console.log('[capture-queue] _drain: uploading id=%d', next.id);

    this._inFlight++;
    await this.db.captures.update(next.id, { status: 'uploading' });
    this._notify(next.id);

    try {
      const result = await this._upload(next);
      await this.db.captures.update(next.id, {
        status: 'confirmed',
        serverResponse: result,
        sentAt: Date.now(),
      });
      console.log('[capture-queue] upload confirmed id=%d server=%o', next.id, result);
      this._notify(next.id);
      if (this.onConfirmed) this.onConfirmed({ ...next, status: 'confirmed', serverResponse: result });
    } catch (err) {
      console.error('[capture-queue] upload FAILED id=%d:', next.id, err);
      await this.db.captures.update(next.id, {
        status: 'error',
        error: String(err?.message || err),
        errorAt: Date.now(),
      });
      this._notify(next.id);
      if (this.onError) this.onError({ ...next, status: 'error', error: String(err) });
    } finally {
      this._inFlight--;
      if (this._running) setTimeout(() => this._drain(), 0);
    }
  }

  async _upload(capture) {
    const { photos, audio, metadata, clientId } = capture;

    const form = new FormData();
    form.append('client_id', clientId);
    photos.forEach((blob, i) => {
      const ext = blob.type === 'image/png' ? 'png' : 'jpg';
      form.append('photos[]', blob, `photo-${i}.${ext}`);
    });
    if (audio) {
      const ext = audio.type === 'audio/webm' ? 'webm' : 'ogg';
      form.append('audio', audio, `note.${ext}`);
    }
    form.append('metadata', JSON.stringify({
      ...metadata,
      captured_at: new Date(capture.capturedAt).toISOString(),
    }));

    const resp = await fetch(this.endpoint, { method: 'POST', body: form });

    if (!resp.ok) {
      const text = await resp.text().catch(() => '');
      throw new Error(`HTTP ${resp.status}: ${text.slice(0, 200)}`);
    }

    return resp.json();
  }

  async _notify(id) {
    if (!this.onProgress) return;
    const item = await this.db.captures.get(id);
    if (item) this.onProgress(item);
  }

  /** Get all captures for display, most recent first. */
  async getAll(limit = 50) {
    return this.db.captures.orderBy('capturedAt').reverse().limit(limit).toArray();
  }

  /** Pending count (new + uploading). */
  async pendingCount() {
    const [n, u] = await Promise.all([
      this.db.captures.where('status').equals('new').count(),
      this.db.captures.where('status').equals('uploading').count(),
    ]);
    return n + u;
  }

  /** Retry errored captures. */
  async retryErrors() {
    await this.db.captures.where('status').equals('error').modify({ status: 'new' });
    this._drain();
  }

  /** Clear confirmed captures older than maxAgeMs (default 24h) -- keeps local storage from growing forever. */
  async pruneConfirmed(maxAgeMs = 86400000) {
    const cutoff = Date.now() - maxAgeMs;
    await this.db.captures
      .where('status').equals('confirmed')
      .and((item) => item.capturedAt < cutoff)
      .delete();
  }
}
