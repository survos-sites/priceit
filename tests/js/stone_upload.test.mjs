import test from 'node:test';
import assert from 'node:assert/strict';
import { uploadStone } from '../../assets/lib/stone_upload.js';
const shot = {id: 1, captureId: 'capture-1', batchId: 'batch-1', stoneId: 'stone-1', gps: {latitude: 38.65}, pose: {sensorStatus: 'unavailable'}, blob: new Blob(['original'], {type: 'image/jpeg'})};
const receipt = {stored: true, captureId: 'capture-1', imageId: 'image-1', intakeCode: 'intake-1', sha256: 'a'.repeat(64)};

test('uploads original and grouping and accepts only matching storage receipt', async () => {
  const result = await uploadStone(shot, {url: '/stones/upload', csrf: 'csrf', fetcher: async (url, options) => {
    assert.equal(url, '/stones/upload'); assert.equal(options.headers['X-CSRF-Token'], 'csrf');
    const meta = JSON.parse(options.body.get('metadata'));
    assert.equal(meta.stoneId, shot.stoneId); assert.deepEqual(meta.gps, shot.gps); assert.deepEqual(meta.pose, shot.pose);
    assert.equal(await options.body.get('file').text(), 'original');
    assert.equal(meta.blob, undefined);
    return {ok: true, json: async () => receipt};
  }});
  assert.deepEqual(result, receipt); assert.equal(shot.receipt, undefined);
});
test('lost responses are retryable with exactly the same capture identity', async () => {
  const seen = [];
  const fetcher = async (url, options) => {
    seen.push(JSON.parse(options.body.get('metadata')).captureId);
    if (seen.length === 1) throw new TypeError('Connection lost after server saved');
    return {ok: true, json: async () => receipt};
  };
  await assert.rejects(uploadStone(shot, {url: '/', csrf: '', fetcher}));
  assert.deepEqual(await uploadStone(shot, {url: '/', csrf: '', fetcher}), receipt);
  assert.deepEqual(seen, ['capture-1', 'capture-1']);
});
test('rejects login redirects, service failures and mismatched acknowledgements', async () => {
  for (const response of [
    {redirected: true},
    {ok: false, status: 503, json: async () => ({error: 'Unavailable'})},
    {ok: true, json: async () => ({...receipt, captureId: 'wrong'})},
    {ok: true, json: async () => ({...receipt, stored: false})},
  ]) await assert.rejects(uploadStone(shot, {url: '/', csrf: '', fetcher: async () => response}));
});
