import { Controller } from '@hotwired/stimulus';
import Dexie from 'dexie';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { cameraDirection } from '../lib/stone_pose.js';
import { Camera } from 'camera-bundle/camera';
import { uploadStone } from '../lib/stone_upload.js';

export default class extends Controller {
  static targets = ['video', 'empty', 'context', 'advance', 'hint', 'shutter', 'status', 'cemetery', 'gps', 'gallery', 'count', 'panel', 'tab', 'map', 'mapStatus', 'orientation', 'distance', 'syncStatus'];
  static values = { owner: String, uploadUrl: String, csrf: String };

  async connect() {
    this.disconnected = false;
    this.camera = new Camera();
    this.activePanel = 'camera';
    this.urls = [];
    this.key = `stone-capture:${this.ownerValue}`;
    this.db = new Dexie(this.key);
    this.db.version(1).stores({ shots: '++id, stoneId, capturedAt' });
    this.db.version(2).stores({ shots: '++id, stoneId, capturedAt', state: 'key' });
    try {
      await this.db.open();
      const last = await this.db.shots.orderBy('id').last();
      this.current = last ? { id: last.stoneId, number: last.stoneNumber, cemeteryId: last.cemeteryId } : null;
      this.sequence = last?.stoneNumber || 0;
      const saved = JSON.parse(localStorage.getItem(this.key) || '{}');
      this.advanceTarget.checked = saved.advance !== false;
      this.distanceTarget.value = saved.distanceFeet || '';
      // Cemetery detection is pending; do not restore an earlier manual assignment.
      this.cemeteryTarget.value = '';
      // A changed cemetery must never append evidence to a previous site's stone.
      if (this.current?.cemeteryId !== this.cemeteryTarget.value) this.current = null;
      await this.render();
      this.onlineHandler = () => void this.sync();
      window.addEventListener('online', this.onlineHandler);
      void this.sync();
    } catch (error) { this.statusTarget.textContent = `Local storage unavailable: ${error.message}`; this.storageFailed = true; }
    this.visibility = () => { if (document.hidden) this.stop(); };
    document.addEventListener('visibilitychange', this.visibility);
  }

  disconnect() {
    this.disconnected = true;
    clearTimeout(this.syncTimer);
    window.removeEventListener('online', this.onlineHandler);
    this.stop();
    document.removeEventListener('visibilitychange', this.visibility);
    this.urls.forEach(url => URL.revokeObjectURL(url));
    this.db.close();
    window.removeEventListener('deviceorientation', this.onOrientation);
    window.removeEventListener('deviceorientationabsolute', this.onOrientation);
    this.map?.remove();
  }

  async start() {
    if (this.storageFailed || this.opening) return;
    this.opening = true;
    if (!this.onOrientation) void this.enableOrientation();
    try {
      await this.camera.open(this.videoTarget);
      this.emptyTarget.hidden = true;
      this.updateGpsState();
      this.statusTarget.textContent = 'Camera ready. A fresh GPS fix is required to capture.';
      clearInterval(this.gpsTimer);
      this.gpsTimer = setInterval(() => this.updateGpsState(), 1000);
      navigator.storage?.persist?.().catch(() => {});
      if (navigator.geolocation && this.watch === undefined) {
        this.watch = navigator.geolocation.watchPosition(position => {
          this.position = { source: 'browser-geolocation', altitude: position.coords.altitude, altitudeAccuracy: position.coords.altitudeAccuracy, latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy, timestamp: position.timestamp };
          this.gpsError = null;
          this.updateGpsState();
          this.updateMapPosition();
        }, error => {
          this.position = null;
          this.gpsError = error.code === 1 ? 'Location permission required. Allow location for this site, then restart the camera.' : 'Waiting for GPS — capture is disabled.';
          this.updateGpsState();
        }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 15000 });
      } else if (!navigator.geolocation) {
        this.gpsError = 'Location is unavailable on this browser. GPS is required.';
        this.updateGpsState();
      }
    } catch (error) { this.statusTarget.textContent = `Camera unavailable: ${error.message}`; }
    finally { this.opening = false; }
  }

  stop() {
    clearInterval(this.gpsTimer);
    this.camera?.close();
    this.shutterTarget.disabled = true;
    this.emptyTarget.hidden = false;
    if (this.watch !== undefined) navigator.geolocation.clearWatch(this.watch);
    this.watch = undefined;
    this.position = null;
    this.gpsError = null;
    this.updateMapPosition();
    this.gpsTarget.textContent = 'GPS required. Start the camera to acquire a location.';
  }

  freshGps() {
    const p = this.position;
    if (!p || !Number.isFinite(p.latitude) || Math.abs(p.latitude) > 90 || !Number.isFinite(p.longitude) || Math.abs(p.longitude) > 180 || !Number.isFinite(p.accuracy) || p.accuracy < 0 || !Number.isFinite(p.timestamp)) return null;
    const age = Date.now() - p.timestamp;
    return age >= 0 && age < 30000 ? { ...p } : null;
  }

  updateGpsState() {
    const gps = this.freshGps();
    this.shutterTarget.disabled = this.saving || this.storageFailed || !this.camera.stream || !gps;
    this.updateOrientationStatus();
    this.gpsTarget.textContent = gps ? `GPS ±${Math.round(gps.accuracy)} m · Ready` : (this.gpsError || 'Waiting for a fresh GPS fix — capture is disabled.');
  }

  modeChanged() { this.preferences(); this.render(); }
  cemeteryChanged() { this.current = null; this.preferences(); this.render(); }
  preferences() {
    try { localStorage.setItem(this.key, JSON.stringify({ advance: this.advanceTarget.checked, cemetery: this.cemeteryTarget.value, distanceFeet: this.hasDistanceTarget ? this.distanceTarget.value : '' })); }
    catch { /* Photo persistence is independent of optional preferences. */ }
  }

  async snap() {
    if (this.saving || !this.camera.stream || this.storageFailed) return;
    const gps = this.freshGps();
    if (!gps) {
      this.updateGpsState();
      this.statusTarget.textContent = 'Photo not taken. A fresh GPS fix is required.';
      return;
    }
    this.saving = true;
    this.shutterTarget.disabled = true;
    // Freeze grouping and position at shutter time, before asynchronous image encoding.
    const newStone = this.advanceTarget.checked || !this.current;
    const cemeteryId = this.cemeteryTarget.value;
    const stone = newStone ? { id: crypto.randomUUID(), number: this.sequence + 1, cemeteryId } : { ...this.current };
    const capturedAt = new Date().toISOString();
    const pose = this.capturePose();
    this.advanceTarget.disabled = this.cemeteryTarget.disabled = true;
    try {
      if (!this.videoTarget.videoWidth) throw new Error('Wait for the camera preview');
      const blob = await this.camera.capture(0.95);
      await this.db.shots.add({ stoneId: stone.id, stoneNumber: stone.number, cemeteryId,
        cemeteryName: this.cemeteryTarget.selectedOptions[0]?.textContent || '', capturedAt, gps, pose, blob });
      this.current = stone;
      this.sequence = stone.number;
      this.statusTarget.textContent = `Saved locally · Stone ${stone.number} · GPS recorded`;
      await this.render();
      void this.sync();
    } catch (error) { this.statusTarget.textContent = `Photo NOT saved: ${error.message}. Try again.`; }
    finally {
      this.saving = false;
      this.advanceTarget.disabled = this.cemeteryTarget.disabled = false;
      this.updateGpsState();
    }
  }

  async render() {
    const shots = await this.db.shots.orderBy('id').toArray();
    this.shots = shots;
    if (this.hasSyncStatusTarget && !this.syncing) {
      const uploaded = shots.filter(shot => shot.receipt).length;
      this.syncStatusTarget.textContent = `${uploaded} saved in SSAI · ${shots.length - uploaded} waiting to upload`;
    }
    if (this.map) this.renderMap();
    const count = shots.filter(shot => shot.stoneId === this.current?.id).length;
    const held = !this.advanceTarget.checked;
    this.element.classList.toggle('stone-held', held);
    this.contextTarget.textContent = held && this.current ? `Next shot → linked to previous stone · #${this.current.number} · ${count} photos` : 'Next shot → new stone';
    this.hintTarget.textContent = held ? (this.current ? 'Detail, another angle, or the back. Same stone.' : 'First shot starts a stone. Following shots stay with it.') : 'Every shot starts a new stone.';
    this.countTarget.textContent = `· ${new Set(shots.map(s => s.stoneId)).size} stones / ${shots.length} photos`;
    this.urls.forEach(url => URL.revokeObjectURL(url));
    this.urls = [];
    this.galleryTarget.replaceChildren();
    for (const shot of shots.slice(-12).reverse()) {
      const card = document.createElement('div'); card.className = 'stone-card';
      const img = document.createElement('img'); img.src = URL.createObjectURL(shot.blob); img.alt = `Stone ${shot.stoneNumber}`; this.urls.push(img.src);
      const caption = document.createElement('p'); caption.textContent = `Stone ${shot.stoneNumber} · ${shot.gps ? 'GPS' : 'No GPS'} · ${shot.receipt ? 'Saved in SSAI' : shot.uploadError || 'Waiting to upload'}`;
      card.append(img, caption); this.galleryTarget.append(card);
    }
  }

  async showPanel(event) {
    const name = event.currentTarget.dataset.panel;
    this.activePanel = name;
    for (const panel of this.panelTargets) panel.hidden = panel.dataset.panel !== name;
    for (const tab of this.tabTargets) tab.setAttribute('aria-pressed', String(tab.dataset.panel === name));
    // Keep the video mounted and its stream alive while switching in-app tabs.
    if (name === 'map') {
      if (!this.map) {
        this.map = L.map(this.mapTarget).setView([20, 0], 2);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).on('tileerror', () => { this.mapStatusTarget.textContent = 'Basemap unavailable. Saved capture points are still shown.'; }).addTo(this.map);
        this.markers = L.layerGroup().addTo(this.map);
        this.renderMap();
        this.centerMap();
      }
      requestAnimationFrame(() => this.map.invalidateSize());
    }
  }

  renderMap() {
    this.markers.clearLayers();
    const stones = new Map();
    for (const shot of this.shots || []) {
      if (!shot.gps) continue;
      if (!stones.has(shot.stoneId)) stones.set(shot.stoneId, { shot, count: 0 });
      stones.get(shot.stoneId).count++;
    }
    for (const {shot, count} of stones.values()) {
      const label = document.createElement('span');
      label.textContent = `Stone ${shot.stoneNumber} · ${count} photos · camera GPS ±${Math.round(shot.gps.accuracy)} m`;
      L.circleMarker([shot.gps.latitude, shot.gps.longitude], {radius: 8, color: '#496f27', fillOpacity: 0.8})
        .bindTooltip(`Stone ${Number(shot.stoneNumber)}`, {permanent: true, direction: 'top'})
        .bindPopup(label).addTo(this.markers);
    }
    this.mapStatusTarget.textContent = `${stones.size} stones on this device. Points show the first photo’s camera location, not the surveyed stone location. Basemap needs internet.`;
    this.updateMapPosition();
  }

  updateMapPosition() {
    if (!this.map) return;
    const gps = this.freshGps();
    if (this.locationCircle) this.map.removeLayer(this.locationCircle);
    this.locationCircle = null;
    if (gps) this.locationCircle = L.circle([gps.latitude, gps.longitude], {radius: gps.accuracy, color: '#318be5', weight: 1, fillOpacity: 0.1}).bindPopup('Current camera GPS accuracy').addTo(this.map);
  }

  centerMap() {
    const point = this.freshGps() || this.shots?.findLast(s => s.gps)?.gps;
    if (point) this.map.setView([point.latitude, point.longitude], 19);
    else this.mapStatusTarget.textContent = 'No locations yet. Start the camera to acquire GPS, or capture your first stone.';
  }

  async enableOrientation() {
    const Sensor = window.DeviceOrientationEvent;
    if (!Sensor) { this.orientationTarget.textContent = 'Orientation sensors unavailable. Photos still work.'; return; }
    try {
      if (typeof Sensor.requestPermission === 'function' && await Sensor.requestPermission(true) !== 'granted') {
        this.orientationTarget.textContent = 'Orientation permission denied. Photos still work.'; return;
      }
      if (!this.onOrientation) {
        this.onOrientation = event => {
          // Prefer fresh absolute readings when both event streams are emitted.
          if (!event.absolute && this.orientationSample?.absolute && Date.now() - this.orientationSample.timestamp < 2000) return;
          const finite = value => Number.isFinite(value) ? value : null;
          this.orientationSample = {
            alpha: finite(event.alpha), beta: finite(event.beta), gamma: finite(event.gamma),
            absolute: event.absolute === true, timestamp: Date.now(), source: event.type,
            webkitCompassHeading: finite(event.webkitCompassHeading), webkitCompassAccuracy: finite(event.webkitCompassAccuracy)
          };
          this.updateOrientationStatus();
        };
        window.addEventListener('deviceorientation', this.onOrientation);
        window.addEventListener('deviceorientationabsolute', this.onOrientation);
      }
      this.orientationTarget.textContent = 'Waiting for orientation sensors…';
    } catch (error) { this.orientationTarget.textContent = `Orientation unavailable: ${error.message}. Photos still work.`; }
  }

  capturePose() {
    const now = Date.now();
    const sample = this.orientationSample;
    const fresh = sample && now >= sample.timestamp && now - sample.timestamp < 2000;
    const settings = this.camera.stream?.getVideoTracks?.()[0]?.getSettings?.() || {};
    const feet = this.hasDistanceTarget ? Number(this.distanceTarget.value) : NaN;
    return {
      version: 1,
      screen: { type: globalThis.screen?.orientation?.type || null, angleDegrees: globalThis.screen?.orientation?.angle ?? globalThis.window?.orientation ?? null },
      sensor: fresh ? {...sample} : null,
      sensorStatus: fresh ? 'available' : sample ? 'stale' : 'unavailable',
      camera: { facingMode: settings.facingMode || 'unknown', width: this.videoTarget.videoWidth, height: this.videoTarget.videoHeight },
      direction: fresh ? cameraDirection(sample, settings.facingMode) : null,
      distance: Number.isFinite(feet) && feet > 0 ? {meters: feet * 0.3048, enteredFeet: feet, source: 'manual-estimate', kind: 'camera-to-stone'} : null
    };
  }

  updateOrientationStatus() {
    if (!this.hasOrientationTarget || !this.orientationSample) return;
    const pose = this.capturePose();
    const direction = pose.direction;
    this.orientationTarget.textContent = pose.sensorStatus !== 'available' ? 'Orientation reading stale — no angle will be attached.' :
      direction?.bearingDegrees != null ? `Lens bearing ≈${Math.round(direction.bearingDegrees)}° · elevation ≈${Math.round(direction.elevationDegrees)}° · uncalibrated` :
      'Device tilt recorded. Absolute lens bearing unavailable; compass readings saved when provided.';
  }

  async sync() {
    if (this.syncing || this.storageFailed || this.disconnected || !this.hasUploadUrlValue) return;
    clearTimeout(this.syncTimer);
    this.syncing = true;
    let failure = null;
    try {
      if (!navigator.onLine) throw new Error('Offline — photos are saved here and will upload when connected.');
      const rows = await this.db.shots.orderBy('id').toArray();
      for (const row of rows) {
        if (this.disconnected) break;
        if (row.receipt) continue;
        // Persist retry identity before any network request, including legacy browser-only shots.
        const shot = await this.db.transaction('rw', this.db.shots, this.db.state, async () => {
          const shot = await this.db.shots.get(row.id);
          if (shot.receipt) return shot;
          const key = `batch:${shot.capturedAt.slice(0, 10)}`;
          let batch = await this.db.state.get(key);
          if (!batch) { batch = { key, id: crypto.randomUUID() }; await this.db.state.put(batch); }
          shot.captureId ||= crypto.randomUUID();
          shot.batchId ||= batch.id;
          await this.db.shots.put(shot);
          return shot;
        });
        if (shot.receipt) continue;
        this.syncStatusTarget.textContent = `Uploading stone ${shot.stoneNumber}…`;
        try {
          const receipt = await uploadStone(shot, { url: this.uploadUrlValue, csrf: this.csrfValue });
          if (this.disconnected) break; // The next visit safely retries the same ID.
          await this.db.shots.update(shot.id, { receipt, uploadError: null });
        } catch (error) {
          if (this.disconnected) break;
          await this.db.shots.update(shot.id, { uploadError: error.message });
          // One invalid legacy photo must not prevent the remaining photographs from uploading.
          if (![409, 413, 422].includes(error.status)) throw error;
          failure = error.message;
        }
      }
    } catch (error) { failure = error.message; }
    finally {
      this.syncing = false;
      if (!this.disconnected) {
        await this.render();
        if (failure) this.syncStatusTarget.textContent = failure;
        if (failure || this.shots.some(shot => !shot.receipt)) this.syncTimer = setTimeout(() => void this.sync(), 30000);
      }
    }
  }

  async exportPhotos() {
    // One self-contained JSON export avoids multiple-download blocking and preserves grouping.
    try {
      const shots = await this.db.shots.orderBy('id').toArray();
      if (!shots.length) { this.statusTarget.textContent = 'No photos to export yet.'; return; }
      const rows = [];
      for (const { blob, ...metadata } of shots) {
        const image = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(blob); });
        rows.push({ ...metadata, image });
      }
      const url = URL.createObjectURL(new Blob([JSON.stringify({ version: 2, profile: 'gravestone', shots: rows })], { type: 'application/json' }));
      const link = document.createElement('a'); link.href = url; link.download = `stones-${new Date().toISOString().slice(0,10)}.json`; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
      this.statusTarget.textContent = `Export prepared · ${rows.length} photos with grouping and location.`;
    } catch (error) { this.statusTarget.textContent = `Export failed: ${error.message}`; }
  }
}
