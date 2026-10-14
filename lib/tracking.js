export function extractTrackingId(body) {
  return (
    body.trackingParameters?.subid ||
    body.trackingParameters?.clickid ||
    body.trackingParameters?.src ||
    body.subid ||
    body.clickid ||
    body.src ||
    null
  );
}