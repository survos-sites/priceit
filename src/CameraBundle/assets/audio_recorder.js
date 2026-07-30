/**
 * AudioRecorder — plain wrapper around MediaRecorder for a press-to-talk note.
 *
 * Deliberately records a raw audio Blob rather than live-transcribing with the Web Speech API
 * (the pattern ssai/voice_controller.js uses): SpeechRecognition needs an active connection and
 * isn't supported in Firefox, which breaks the offline-first premise of the capture queue.
 * Recording a blob works fully offline; transcription (if wanted) becomes a server-side AI step
 * once the queue uploads it, run alongside the other capture-enrichment tasks.
 */
export class AudioRecorder {
  constructor() {
    this.stream = null;
    this.recorder = null;
    this._chunks = [];
  }

  get isRecording() {
    return this.recorder?.state === 'recording';
  }

  async start() {
    this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    this._chunks = [];
    const mimeType = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
    this.recorder = mimeType ? new MediaRecorder(this.stream, { mimeType }) : new MediaRecorder(this.stream);
    this.recorder.addEventListener('dataavailable', (e) => {
      if (e.data.size > 0) this._chunks.push(e.data);
    });
    this.recorder.start();
  }

  /** @returns {Promise<Blob>} */
  stop() {
    return new Promise((resolve, reject) => {
      if (!this.recorder) return reject(new Error('not recording'));
      this.recorder.addEventListener('stop', () => {
        const blob = new Blob(this._chunks, { type: this.recorder.mimeType || 'audio/webm' });
        this.stream.getTracks().forEach((t) => t.stop());
        this.stream = null;
        this.recorder = null;
        resolve(blob);
      }, { once: true });
      this.recorder.stop();
    });
  }

  cancel() {
    if (this.recorder?.state === 'recording') this.recorder.stop();
    this.stream?.getTracks().forEach((t) => t.stop());
    this.stream = null;
    this.recorder = null;
    this._chunks = [];
  }
}
