/**
 * Camera — plain, framework-agnostic wrapper around getUserMedia + device selection +
 * still-frame capture. Deliberately not a Stimulus controller: this class only needs a
 * <video> element to attach a stream to and a callback surface, so it can be reused from any
 * UI layer (Stimulus here, but no reason a future consumer couldn't wire it differently).
 *
 * Device ranking/fallback-constraint-ladder logic adapted from ssai's camera_controller.js,
 * stripped of everything scanstation-specific (ArUco, deskew, CV worker, OCR, still-detection,
 * torch). A garage-sale item just needs a decent photo -- prefer the rear/environment camera
 * on phones, otherwise take whatever's available.
 */
export class Camera {
  constructor() {
    this.stream = null;
    this.videoEl = null;
  }

  _isMobileLike() {
    return /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent || '')
      || (navigator.maxTouchPoints || 0) > 1;
  }

  _cameraScore(device) {
    const label = (device.label || '').toLowerCase();
    const mobile = this._isMobileLike();
    let score = 0;
    if (/rear|back|environment|world/i.test(label)) score += mobile ? 18 : 4;
    if (/front|user|facetime|selfie/i.test(label)) score -= mobile ? 24 : 8;
    if (/built.?in|internal|integrated/i.test(label)) score -= mobile ? 2 : 6;
    return score;
  }

  /** Permission-safe enumeration -- getUserMedia is what actually grants device labels. */
  async listDevices() {
    let devices = [];
    try {
      const tmp = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      tmp.getTracks().forEach((t) => t.stop());
      devices = await navigator.mediaDevices.enumerateDevices();
    } catch {
      return [];
    }
    return devices
      .filter((d) => d.kind === 'videoinput')
      .sort((a, b) => this._cameraScore(b) - this._cameraScore(a));
  }

  /** @param {HTMLVideoElement} videoEl @param {string|undefined} deviceId */
  async open(videoEl, deviceId) {
    this.close();
    this.videoEl = videoEl;

    const devConstraint = deviceId
      ? { deviceId: { exact: deviceId } }
      : { facingMode: { ideal: 'environment' } };

    const attempts = [
      { video: { ...devConstraint, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false },
      { video: devConstraint, audio: false },
      { video: true, audio: false },
    ];

    let lastErr;
    for (const constraints of attempts) {
      try {
        this.stream = await navigator.mediaDevices.getUserMedia(constraints);
        break;
      } catch (err) {
        lastErr = err;
      }
    }

    if (!this.stream) throw lastErr || new Error('No camera available');

    videoEl.srcObject = this.stream;
    await videoEl.play();
  }

  /** Grab the current frame as a JPEG Blob. */
  async capture(quality = 0.9) {
    if (!this.videoEl) throw new Error('Camera not open');
    const { videoWidth: w, videoHeight: h } = this.videoEl;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(this.videoEl, 0, 0, w, h);
    return new Promise((resolve, reject) => {
      canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('capture failed'))), 'image/jpeg', quality);
    });
  }

  close() {
    if (this.stream) {
      this.stream.getTracks().forEach((t) => t.stop());
      this.stream = null;
    }
    if (this.videoEl) {
      this.videoEl.srcObject = null;
      this.videoEl = null;
    }
  }
}
