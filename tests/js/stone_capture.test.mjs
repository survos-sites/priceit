import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';
import test from 'node:test';
const source = (await readFile(new URL('../../assets/controllers/stone_capture_controller.js', import.meta.url), 'utf8'))
  .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
  .replace("import Dexie from 'dexie';", '')
  .replace("import L from 'leaflet';", '')
  .replace("import 'leaflet/dist/leaflet.min.css';", '')
  .replace("import { cameraDirection } from '../lib/stone_pose.js';", '')
  .replace("import { uploadStone } from '../lib/stone_upload.js';", '')
  .replace("import { Camera } from 'camera-bundle/camera';", '');
globalThis.cameraDirection = (await import('../../assets/lib/stone_pose.js')).cameraDirection;
const { default: Capture } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
function fixture() {
  const shots = [];
  const c = new Capture();
  Object.assign(c, { sequence: 0, camera: {stream: {}, capture: async () => new Blob(['photo'])},
    videoTarget: {videoWidth: 1920}, shutterTarget: {}, advanceTarget: {checked: true},
    cemeteryTarget: {value: '1', selectedOptions: [{textContent: 'Cemetery A'}]},
    gpsTarget: {}, position: {latitude: 40, longitude: -75, accuracy: 8, timestamp: Date.now()}, statusTarget: {}, db: {shots: {add: async shot => shots.push(shot)}}, render: async () => {} });
  return {c, shots};
}
test('continuous capture groups details, then resumes new stones without stopping camera', async () => {
  const {c, shots} = fixture();
  await c.snap(); await c.snap();
  c.advanceTarget.checked = false;
  await c.snap(); await c.snap();
  c.advanceTarget.checked = true;
  await c.snap();
  assert.deepEqual(shots.map(s => s.stoneNumber), [1,2,2,2,3]);
  assert.equal(shots[1].stoneId, shots[3].stoneId);
  assert.notEqual(shots[0].stoneId, shots[1].stoneId);
  assert.equal(c.shutterTarget.disabled, false);
});
test('storage failure does not advance stone or lose GPS', async () => {
  const {c, shots} = fixture();
  const add = c.db.shots.add;
  c.db.shots.add = async () => {throw new Error('Quota exceeded');};
  await c.snap();
  assert.equal(c.sequence, 0);
  assert.match(c.statusTarget.textContent, /NOT saved/);
  c.db.shots.add = add;
  await c.snap();
  assert.equal(shots[0].stoneNumber, 1);
  assert.equal(shots[0].gps.latitude, 40);
});
test('changing cemeteries starts a new stone even with grouping enabled', async () => {
  const {c, shots} = fixture();
  await c.snap();
  c.advanceTarget.checked = false;
  c.cemeteryTarget.value = '2'; c.preferences = () => {};
  c.cemeteryChanged();
  await c.snap();
  assert.deepEqual(shots.map(s => s.stoneNumber), [1,2]);
  assert.equal(shots[1].cemeteryId, '2');
});

test('missing, stale, invalid and future GPS blocks capture; fresh GPS re-enables it', async () => {
  const {c, shots} = fixture();
  for (const position of [null, {latitude: 40, longitude: -75, accuracy: 8, timestamp: Date.now()-31000}, {latitude: NaN, longitude: 0, accuracy: 8, timestamp: Date.now()}, {latitude: 40, longitude: -75, accuracy: 8, timestamp: Date.now()+60000}]) {
    c.position = position;
    await c.snap();
    assert.equal(shots.length, 0);
    assert.equal(c.shutterTarget.disabled, true);
  }
  c.position = {latitude: 40, longitude: -75, accuracy: 8, timestamp: Date.now()};
  c.updateGpsState();
  assert.equal(c.shutterTarget.disabled, false);
  await c.snap();
  assert.equal(shots.length, 1);
  assert.equal(shots[0].gps.accuracy, 8);
});

test('shutter freezes pose and estimated feet before encoding completes', async () => {
  const {c, shots} = fixture();
  c.hasDistanceTarget = true; c.distanceTarget = {value: '3'};
  c.orientationSample = {alpha: 270, beta: 90, gamma: 0, absolute: true, timestamp: Date.now()};
  c.camera.stream.getVideoTracks = () => [{getSettings: () => ({facingMode: 'environment'})}];
  c.camera.capture = async () => {
    c.orientationSample.alpha = 0; c.distanceTarget.value = '6';
    return new Blob(['photo']);
  };
  await c.snap();
  assert.equal(shots[0].pose.sensor.alpha, 270);
  assert.equal(shots[0].pose.direction.bearingDegrees, 90);
  assert.ok(Math.abs(shots[0].pose.distance.meters - 0.9144) < 1e-9);
});
test('pose rejects stale readings, missing distance and unknown lens facing', () => {
  const {c} = fixture();
  c.orientationSample = {alpha: 0, beta: 90, gamma: 0, absolute: true, timestamp: Date.now()-3000};
  assert.equal(c.capturePose().sensor, null);
  assert.equal(c.capturePose().direction, null);
  assert.equal(c.capturePose().distance, null);
  c.orientationSample.timestamp = Date.now();
  assert.equal(c.capturePose().direction, null);
});
test('lens axis handles portrait, landscape, front, relative and vertical poses', () => {
  const direction = globalThis.cameraDirection;
  const pose = {alpha: 0, beta: 90, gamma: 0, absolute: true};
  assert.equal(direction(pose, 'environment').bearingDegrees, 0);
  assert.equal(direction({...pose, alpha:270}, 'environment').bearingDegrees, 90);
  assert.equal(direction(pose, 'user').bearingDegrees, 180);
  // Same north-facing optical axis with phone rotated into landscape.
  assert.equal(direction({...pose, alpha:90, beta:0, gamma:-90}, 'environment').bearingDegrees, 0);
  assert.equal(direction({...pose, absolute:false}, 'environment').bearingDegrees, null);
  assert.equal(direction({...pose, beta:0}, 'environment').bearingDegrees, null);
  assert.equal(direction({...pose, alpha:null}, 'environment'), null);
});
test('switching settings/camera panels keeps stream and grouping intact', async () => {
  const {c} = fixture();
  const stream = c.camera.stream;
  c.current = {id:'same-stone',number:4}; c.advanceTarget.checked = false;
  c.panelTargets = ['camera','map','settings'].map(panel => ({dataset:{panel}}));
  c.tabTargets = ['camera','map','settings'].map(panel => ({dataset:{panel},setAttribute(key,value) {this[key]=value;}}));
  await c.showPanel({currentTarget:{dataset:{panel:'settings'}}});
  assert.equal(c.panelTargets[0].hidden,true);
  assert.equal(c.panelTargets[2].hidden,false);
  await c.showPanel({currentTarget:{dataset:{panel:'camera'}}});
  assert.equal(c.camera.stream,stream);
  assert.equal(c.current.id,'same-stone');
  assert.equal(c.advanceTarget.checked,false);
});
