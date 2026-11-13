// Device axes: x right, y top, z out of screen. Rear lens points along -z.
// W3C intrinsic Z-X'-Y'' rotation into east/north/up. Screen rotation does not
// change the optical axis. Never interpret relative alpha as a compass bearing.
export function cameraDirection(sample, facingMode) {
  if (!sample || !['environment', 'user'].includes(facingMode) ||
      ![sample.alpha, sample.beta, sample.gamma].every(Number.isFinite)) return null;
  const [a, b, g] = [sample.alpha, sample.beta, sample.gamma].map(v => v * Math.PI / 180);
  const sign = facingMode === 'environment' ? -1 : 1;
  const east = sign * (Math.cos(a)*Math.sin(g) + Math.sin(a)*Math.sin(b)*Math.cos(g));
  const north = sign * (Math.sin(a)*Math.sin(g) - Math.cos(a)*Math.sin(b)*Math.cos(g));
  const up = sign * Math.cos(b)*Math.cos(g);
  return {
    bearingDegrees: sample.absolute && Math.hypot(east, north) > 0.01 ? (Math.atan2(east, north)*180/Math.PI + 360)%360 : null,
    elevationDegrees: Math.asin(Math.max(-1, Math.min(1, up)))*180/Math.PI,
    reference: sample.absolute ? 'device-absolute-north' : 'relative',
    calibrated: false,
  };
}
