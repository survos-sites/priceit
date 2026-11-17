/** Retry one persistent shot. A lost response leaves the same capture ID for the next attempt. */
export async function uploadStone(shot, { url, csrf, fetcher = fetch }) {
  const { blob, id, receipt, uploadError, ...metadata } = shot;
  const body = new FormData();
  body.append('file', blob, `${metadata.captureId}.jpg`);
  body.append('metadata', JSON.stringify(metadata));
  const response = await fetcher(url, { method: 'POST', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' }, body, signal: AbortSignal.timeout(65000) });
  if (response.redirected) throw new Error('Sign in again, then retry upload. Your photos are still on this device.');
  const result = await response.json();
  if (!response.ok) {
    const error = new Error(result.error || 'Upload failed; photo remains queued.');
    error.status = response.status;
    throw error;
  }
  if (result.stored !== true || result.captureId !== shot.captureId || !result.imageId || !result.intakeCode || !/^[a-f0-9]{64}$/.test(result.sha256 || '')) throw new Error('Storage confirmation did not match this photo.');
  return result;
}
