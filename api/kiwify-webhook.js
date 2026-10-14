import { extractTrackingId } from '../lib/tracking.js';
import { validateWebhookAuth } from '../lib/auth.js';

function getOrderId(body) {
  const id =
    body?.order_id ??
    body?.orderId ??
    body?.order_ref ??
    body?.order?.id ??
    body?.id;

  return id == null || String(id).trim() === ''
    ? null
    : String(id).trim();
}

function getOrderStatus(body) {
  return String(
    body?.order_status ??
    body?.orderStatus ??
    body?.order?.status ??
    body?.status ??
    ''
  ).trim().toLowerCase();
}

function getValue(body) {
  const commissions =
    body?.Commissions ??
    body?.commissions ??
    {};

  if (commissions.charge_amount != null) {
    const amount = Number(commissions.charge_amount);

    if (!Number.isFinite(amount) || amount < 0) {
      return null;
    }

    // charge_amount pode estar em centavos.
    return amount / 100;
  }

  const raw =
    body?.order_amount ??
    body?.order?.price ??
    body?.total_amount ??
    body?.total ??
    body?.price;

  if (raw == null || raw === '') {
    return null;
  }

  const amount = Number(raw);

  return Number.isFinite(amount) && amount >= 0
    ? amount
    : null;
}

async function sendBeMob({ subid, orderId, value }) {
  const postbackUrl = process.env.BEMOB_POSTBACK_URL;

  // Se a URL do BeMob não estiver configurada, não quebra a aplicação (modo de teste seguro)
  if (!postbackUrl) {
    console.warn('Aviso: BEMOB_POSTBACK_URL não configurada. O postback foi simulado com sucesso.');
    return {
      success: true,
      simulated: true,
      message: 'BeMob postback simulado (URL não configurada).'
    };
  }

  if (!subid) {
    throw new Error(
      'ID de clique do BeMob (subid/cid) ausente no webhook da Kiwify.'
    );
  }

  const url = new URL(postbackUrl);

  // cid é o ID de clique do BeMob que será usado para atribuir a conversão ao Google Ads
  url.searchParams.set('cid', String(subid));
  url.searchParams.set('payout', value.toFixed(2));
  url.searchParams.set('txid', orderId);

  const response = await fetch(url.toString(), {
    method: 'GET',
    signal: AbortSignal.timeout(10000)
  });

  if (!response.ok) {
    throw new Error(`BeMob respondeu com HTTP ${response.status}.`);
  }

  return {
    success: true,
    httpStatus: response.status
  };
}

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');

    return res.status(405).json({
      success: false,
      error: 'Método não permitido.'
    });
  }

  if (!validateWebhookAuth(req)) {
    return res.status(401).json({
      success: false,
      error: 'Autenticação inválida.'
    });
  }

  const body = req.body;

  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return res.status(400).json({
      success: false,
      error: 'Corpo da requisição inválido.'
    });
  }

  const status = getOrderStatus(body);

  // Ignora pagamentos não confirmados
  if (!['paid', 'approved', 'complete'].includes(status)) {
    return res.status(200).json({
      success: true,
      ignored: true,
      message: 'Pagamento ainda não confirmado; conversão ignorada.'
    });
  }

  const orderId = getOrderId(body);

  if (!orderId) {
    return res.status(400).json({
      success: false,
      error: 'ID do pedido ausente.'
    });
  }

  const value = getValue(body);

  if (value === null) {
    return res.status(400).json({
      success: false,
      error: 'Valor da venda ausente ou inválido.'
    });
  }

  const subid = extractTrackingId(body);

  try {
    const bemob = await sendBeMob({
      subid,
      orderId,
      value
    });

    return res.status(200).json({
      success: true,
      orderId,
      value,
      currency: 'BRL',
      subid,
      results: { bemob },
      message: 'Webhook da Kiwify processado com sucesso.'
    });
  } catch (error) {
    console.error('Falha no encaminhamento:', error.message);

    return res.status(502).json({
      success: false,
      orderId,
      error: error.message
    });
  }
}