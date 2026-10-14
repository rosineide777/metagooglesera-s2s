
import crypto from 'crypto';
import { extractTrackingId } from '../lib/tracking.js';
import { validateWebhookAuth } from '../lib/auth.js';

function sha256(value) {
  return crypto
    .createHash('sha256')
    .update(value, 'utf8')
    .digest('hex');
}

function getTracking(body) {
  return (
    body?.TrackingParameters ||
    body?.trackingParameters ||
    body?.tracking_parameters ||
    {}
  );
}

function getValue(body) {
  const commissions = body?.Commissions || body?.commissions || {};

  if (commissions.charge_amount != null) {
    const amount = Number(commissions.charge_amount);

    if (!Number.isFinite(amount) || amount < 0) {
      return null;
    }

    return amount / 100;
  }

  const raw =
    body?.order_amount ??
    body?.order?.price ??
    body?.price;

  if (raw == null || raw === '') {
    return null;
  }

  const amount = Number(raw);

  return Number.isFinite(amount) && amount >= 0
    ? amount
    : null;
}

function getEventTime(body) {
  const raw =
    body?.paid_at ||
    body?.approved_at ||
    body?.created_at ||
    body?.createdAt;

  if (raw) {
    const date = new Date(raw);

    if (!Number.isNaN(date.getTime())) {
      return Math.floor(date.getTime() / 1000);
    }
  }

  return Math.floor(Date.now() / 1000);
}

async function sendMetaPurchase({
  body,
  orderId,
  value,
  subid,
  tracking,
  eventTime
}) {
  const pixelId = process.env.META_PIXEL_ID;
  const accessToken = process.env.META_ACCESS_TOKEN;

  if (!pixelId || !accessToken) {
    return {
      success: false,
      skipped: true,
      message: 'Credenciais da Meta não configuradas.'
    };
  }

  const userData = {};

  const customer =
    body?.Customer ||
    body?.customer ||
    {};

  const email =
    customer.email ||
    body?.customer_email;

  const phone =
    customer.phone ||
    customer.mobile ||
    body?.customer_phone;

  if (email) {
    userData.em = [
      sha256(String(email).trim().toLowerCase())
    ];
  }

  if (phone) {
    userData.ph = [
      sha256(String(phone).replace(/\D/g, ''))
    ];
  }

  if (subid) {
    userData.external_id = [sha256(String(subid))];
  }

  const fbc = tracking.fbc || body?.fbc;
  const fbp = tracking.fbp || body?.fbp;

  if (fbc) userData.fbc = fbc;
  if (fbp) userData.fbp = fbp;

  if (Object.keys(userData).length === 0) {
    return {
      success: false,
      skipped: true,
      message: 'Nenhum identificador disponível para correspondência na Meta.'
    };
  }

  const payload = {
    data: [
      {
        event_name: 'Purchase',
        event_time: eventTime,
        event_id: orderId,
        action_source: 'system_generated',
        user_data: userData,
        custom_data: {
          currency: 'BRL',
          value
        }
      }
    ]
  };

  const response = await fetch(
    `https://graph.facebook.com/v23.0/${encodeURIComponent(pixelId)}/events`,
    {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${accessToken}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    }
  );

  const result = await response.json();

  return {
    success: response.ok && !result.error,
    httpStatus: response.status,
    response: result
  };
}

async function sendBeMob({ subid, orderId, value }) {
  const baseUrl = process.env.BEMOB_POSTBACK_URL;

  if (!baseUrl || !subid) {
    return {
      success: false,
      skipped: true,
      message: 'URL do postback ou SubID ausente.'
    };
  }

  const url = new URL(baseUrl);

  url.searchParams.set('cid', String(subid));
  url.searchParams.set('payout', value.toFixed(2));
  url.searchParams.set('txid', orderId);

  const response = await fetch(url.toString(), {
    method: 'GET',
    signal: AbortSignal.timeout(10000)
  });

  return {
    success: response.ok,
    httpStatus: response.status
  };
}

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');

    return res.status(405).json({
      success: false,
      error: 'Método não permitido'
    });
  }

  if (!validateWebhookAuth(req)) {
    return res.status(401).json({
      success: false,
      error: 'Autenticação inválida'
    });
  }

  const body = req.body;

  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return res.status(400).json({
      success: false,
      error: 'Corpo da requisição inválido'
    });
  }

  const status = String(
    body.order_status ||
    body.orderStatus ||
    body.status ||
    ''
  ).toLowerCase();

  if (!['paid', 'approved', 'complete'].includes(status)) {
    return res.status(200).json({
      success: true,
      ignored: true,
      message: 'Pedido ignorado: status não aprovado.'
    });
  }

  const orderId = String(
    body.order_id ||
    body.orderId ||
    body.order_ref ||
    body.id ||
    ''
  );

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

  const tracking = getTracking(body);
  const subid = extractTrackingId(body);
  const eventTime = getEventTime(body);
  const results = {};

  try {
    results.bemob = await sendBeMob({
      subid,
      orderId,
      value
    });
  } catch (error) {
    results.bemob = {
      success: false,
      error: error.message
    };
  }

  try {
    results.meta = await sendMetaPurchase({
      body,
      orderId,
      value,
      subid,
      tracking,
      eventTime
    });
  } catch (error) {
    results.meta = {
      success: false,
      error: error.message
    };
  }

  results.google = {
    success: false,
    skipped: true,
    message:
      'O ID AW-17649842006 e a etiqueta e4tQCKrw35sdENbujOBB são dados do Google tag; faltam credenciais e configuração da API para enviar a conversão pelo servidor.'
  };

  return res.status(200).json({
    success: true,
    orderId,
    value,
    currency: 'BRL',
    subid: subid || null,
    results,
    message: 'Webhook processado. Verifique o resultado individual de cada plataforma.'
  });
}
