/**
 * Extrai o identificador de clique do BeMob (subid / clickid / cid)
 * recebido nos dados de rastreamento da Kiwify.
 *
 * Mantém o GCLID do Google Ads separado para evitar
 * usá-lo incorretamente como CID do BeMob.
 */
export function extractTrackingId(body) {
  const tracking =
    body?.TrackingParameters ||
    body?.trackingParameters ||
    body?.tracking_parameters ||
    body?.tracking ||
    {};

  const candidates = [
    tracking.subid,
    tracking.subId,
    tracking.clickid,
    tracking.clickId,
    tracking.bemob_click_id,
    tracking.bemobClickId,
    tracking.cid,
    tracking.src,
    tracking.source,
    tracking.sck,
    body?.subid,
    body?.subId,
    body?.clickid,
    body?.clickId,
    body?.bemob_click_id,
    body?.bemobClickId,
    body?.cid,
    body?.src,
    body?.sck,
    body?.Sck
  ];

  const id = candidates.find(
    value =>
      typeof value === 'string' &&
      value.trim().length > 0
  );

  return id ? id.trim() : null;
}

/**
 * Extrai o identificador de clique do Google Ads (GCLID).
 * Utilizado para rastreamento direto ou validações paralelas.
 */
export function extractGoogleClickId(body) {
  const tracking =
    body?.TrackingParameters ||
    body?.trackingParameters ||
    body?.tracking_parameters ||
    body?.tracking ||
    {};

  const candidates = [
    tracking.gclid,
    tracking.gclid_id,
    tracking.GCLID,
    body?.gclid,
    body?.GCLID
  ];

  const id = candidates.find(
    value =>
      typeof value === 'string' &&
      value.trim().length > 0
  );

  return id ? id.trim() : null;
}