
export function extractTrackingId(body) {
  const tracking =
    body?.TrackingParameters ||
    body?.trackingParameters ||
    body?.tracking_parameters ||
    {};

  return (
    tracking.subid ||
    tracking.subId ||
    tracking.clickid ||
    tracking.clickId ||
    tracking.src ||
    tracking.source ||
    body?.subid ||
    body?.subId ||
    body?.clickid ||
    body?.clickId ||
    body?.src ||
    null
  );
}
