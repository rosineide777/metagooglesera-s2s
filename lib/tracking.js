
/**
 * Extrai o identificador de rastreamento recebido no webhook da Kiwify.
 *
 * Nesta etapa, apenas identificamos os parâmetros presentes no payload.
 * A integração com o BeMob será configurada posteriormente.
 *
 * Importante:
 * - Não confundir GCLID com o identificador de clique do BeMob.
 * - Não utilizar valores genéricos de origem como IDs de clique.
 * - Aceitar somente identificadores em formato de texto não vazio.
 */

function getTrackingObjects(body) {
  const objects = [
    body?.TrackingParameters,
    body?.trackingParameters,
    body?.tracking_parameters,
    body?.tracking_parameters_data,
    body?.tracking,
    body?.trackingData,
    body?.tracking_data,
    body?.metadata
  ];

  return objects.filter(
    value =>
      value !== null &&
      typeof value === 'object' &&
      !Array.isArray(value)
  );
}

function getNonEmptyString(values) {
  for (const value of values) {
    if (typeof value === 'string' && value.trim() !== '') {
      return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
      return String(value);
    }
  }

  return null;
}

/**
 * Extrai o identificador de clique utilizado pelo rastreamento.
 *
 * A interpretação definitiva dos campos será confirmada
 * com o payload real enviado pela Kiwify.
 */
export function extractTrackingId(body) {
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return null;
  }

  const trackingObjects = getTrackingObjects(body);

  const candidates = [];

  for (const tracking of trackingObjects) {
    candidates.push(
      tracking.subid,
      tracking.subId,
      tracking.sub_id,
      tracking.clickid,
      tracking.clickId,
      tracking.click_id,
      tracking.bemob_click_id,
      tracking.bemobClickId,
      tracking.cid
    );
  }

  candidates.push(
    body.subid,
    body.subId,
    body.sub_id,
    body.clickid,
    body.clickId,
    body.click_id,
    body.bemob_click_id,
    body.bemobClickId,
    body.cid
  );

  return getNonEmptyString(candidates);
}

/**
 * Extrai o identificador de clique do Google Ads (GCLID).
 *
 * Mantém esse identificador separado do ID de rastreamento
 * para evitar atribuições incorretas.
 */
export function extractGoogleClickId(body) {
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return null;
  }

  const trackingObjects = getTrackingObjects(body);
  const candidates = [];

  for (const tracking of trackingObjects) {
    candidates.push(
      tracking.gclid,
      tracking.gclid_id,
      tracking.GCLID
    );
  }

  candidates.push(
    body.gclid,
    body.GCLID,
    body.gclid_id
  );

  return getNonEmptyString(candidates);
}
