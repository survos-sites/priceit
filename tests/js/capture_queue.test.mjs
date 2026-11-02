import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';
import test from 'node:test';

// Test the real multipart boundary without a browser database or a network request.
const source = (await readFile(new URL('../../src/CameraBundle/assets/capture_queue.js', import.meta.url), 'utf8'))
  .replace("import Dexie from 'dexie';", 'class Dexie {}');
const { CaptureQueue } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

test('inventory sends all evidence and current CSRF token together without public uploads', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/inventory/capture');
        assert.equal(options.headers['X-CSRF-Token'], 'current-session-token');
        assert.equal(options.body.getAll('photos[]').length, 3);
        assert.equal(options.body.getAll('audio_notes[]').length, 2);
        assert.equal(options.body.get('client_id'), 'same-id');
        assert.equal(JSON.parse(options.body.get('metadata')).transcript, 'Shelf B');
        return {ok: true, json: async () => ({id: '42', reviewUrl: '/inventory/items/42'})};
    };
    try {
        const result = await CaptureQueue.prototype._upload.call({endpoint: '/inventory/capture', directUpload: false, csrfToken: 'current-session-token'}, {
            photos: Array.from({length: 3}, () => new Blob(['photo'], {type: 'image/jpeg'})),
            audioNotes: [new Blob(['one'], {type: 'audio/webm'}), new Blob(['two'], {type: 'audio/mp4'})],
            clientId: 'same-id', metadata: {transcript: 'Shelf B'}, capturedAt: 0,
        });
        assert.equal(result.reviewUrl, '/inventory/items/42');
    } finally { globalThis.fetch = originalFetch; }
});

test('old outbox entries with a single audio blob still upload', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url, {body}) => {
        assert.equal(body.getAll('audio_notes[]').length, 0);
        assert.equal(await body.get('audio').text(), 'legacy');
        assert.equal(body.getAll('photo_urls[]').length, 1);
        return {ok: true, json: async () => ({id: '1'})};
    };
    try {
        await CaptureQueue.prototype._upload.call({endpoint: '/api/camera/capture', directUpload: true, _uploadDirect: async () => ['https://example.test/photo.jpg']}, {
            photos: [new Blob(['photo'])], audio: new Blob(['legacy'], {type:'audio/webm'}), clientId: 'old-id', metadata: {}, capturedAt: 0,
        });
    } finally { globalThis.fetch = originalFetch; }
});
