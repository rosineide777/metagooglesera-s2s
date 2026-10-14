
import { extractTrackingId } from '../lib/tracking.js';
import { validateWebhookAuth } from '../lib/auth.js';

function logEvent(event, details = {}) {
  console.log(
    JSON.stringify({
      timestamp: new Date().toISOString(),
      endpoint: 'kiwify-webhook',
      event,
      ...details
    })
  );
}

function getOrderId(body) {
  const id =
    body?.order_id ??
    body?.orderId ??
    body?.order_ref ??
    body?.order?.id ??
    body?.order?.order_id ??
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
    body?.webhook_event_type ??
    body?.event ??
    ''
  )
    .trim()
    .toLowerCase();
}

function getValue(body) {
  const commissions =
    body?.Commissions ??
    body?.commissions ??
    {};

  const raw =
    body?.order_amount ??
    body?.order?.price ??
    body?.total_amount ??
    body?.total ??
    body?.price ??
    commissions.charge_amount;

  if (raw == null || raw === '') {
    return null;
  }

  const amount = Number(raw);

  if (!Number.isFinite(amount) || amount < 0) {
    return null;
  }

  // Não presumir que o valor está em centavos.
  // Confirmaremos o formato com o payload real da Kiwify.
  return Math.round((amount + Number.EPSILON) * 100) / 100;
}

function isConfirmedPayment(status) {
  return [
    'paid',
    'approved',
    'complete',
    'completed',
    'compra aprovada',
    'paid_out'
  ].includes(status);
}

async function sendBeMob({ subid, orderId, value }) {
  const postbackUrl = process.env.BEMOB_POSTBACK_URL;

  // O BeMob ainda não será utilizado nesta etapa.
  if (!postbackUrl) {
    logEvent('bemob_not_configured', {
      orderId,
      message: 'Postback não enviado: URL ainda não configurada.'
    });

    return {
      success: false,
      simulated: false,
      configured: false,
      message: 'BeMob ainda não configurado. Webhook da Kiwify testado separadamente.'
    };
  }

  if (!subid) {
    throw new Error(
      'ID de rastreamento ausente. Verifique o payload da Kiwify.'
    );
  }

  let url;

  try {
    url = new URL(postbackUrl);
  } catch {
    throw new Error('BEMOB_POSTBACK_URL não é uma URL válida.');
  }

  if (url.protocol !== 'https:') {
    throw new Error('A URL do postback deve usar HTTPS.');
  }

  url.searchParams.set('cid', String(subid));
  url.searchParams.set('payout', value.toFixed(2));
  url.searchParams.set('txid', String(orderId));

  logEvent('bemob_request_started', {
    orderId,
    value
  });

  const response = await fetch(url.toString(), {
    method: 'GET',
    signal: AbortSignal.timeout(10000)
  });

  if (!response.ok) {
    logEvent('bemob_request_failed', {
      orderId,
      httpStatus: response.status
    });

    throw new Error(
      `O endpoint do BeMob respondeu com HTTP ${response.status}.`
    );
  }

  logEvent('bemob_request_succeeded', {
    orderId,
    httpStatus: response.status
  });

  return {
    success: true,
    configured: true,
    httpStatus: response.status
  };
}

export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');

  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');

    return res.status(405).json({
      success: false,
      error: 'Método não permitido. Utilize POST.'
    });
  }

  logEvent('request_received', {
    method: req.method,
    contentType: req.headers['content-type'] || null
  });

  try {
    // Valida a autenticação configurada no projeto.
    if (!validateWebhookAuth(req)) {
      logEvent('authentication_failed');

      return res.status(401).json({
        success: false,
        error: 'Autenticação inválida.'
      });
    }

    let body = req.body;

    // Aceita JSON já interpretado ou JSON recebido como texto.
    if (typeof body === 'string') {
      try {
        body = JSON.parse(body);
      } catch {
        logEvent('invalid_json');

        return res.status(400).json({
          success: false,
          error: 'O corpo da requisição não contém JSON válido.'
        });
      }
    }

    if (!body || typeof body !== 'object' || Array.isArray(body)) {
      logEvent('invalid_body');

      return res.status(400).json({
        success: false,
        error: 'Corpo da requisição inválido.'
      });
    }

    const orderId = getOrderId(body);
    const status = getOrderStatus(body);

    // Registra os campos disponíveis sem imprimir dados pessoais.
    logEvent('payload_received', {
      orderId,
      status: status || '(vazio)',
      fields: Object.keys(body)
    });

    if (!orderId) {
      logEvent('order_id_missing');

      return res.status(400).json({
        success: false,
        error: 'ID do pedido ausente no payload recebido.'
      });
    }

    // Não registra venda se o status não indicar pagamento confirmado.
    if (!isConfirmedPayment(status)) {
      logEvent('order_ignored', {
        orderId,
        status,
        reason: 'Status não reconhecido como pagamento confirmado.'
      });

      return res.status(200).json({
        success: true,
        ignored: true,
        orderId,
        status,
        message: 'Webhook recebido, mas nenhuma venda foi processada.'
      });
    }

    const value = getValue(body);

    if (value === null) {
      logEvent('invalid_order_value', {
        orderId
      });

      return res.status(400).json({
        success: false,
        orderId,
        error: 'Valor da venda ausente ou inválido.'
      });
    }

    const subid = extractTrackingId(body);

    logEvent('paid_order_validated', {
      orderId,
      value,
      currency: 'BRL',
      hasSubid: Boolean(subid)
    });

    const bemob = await sendBeMob({
      subid,
      orderId,
      value
    });

    logEvent('webhook_processed', {
      orderId,
      value,
      bemobConfigured: bemob.configured,
      bemobSuccess: bemob.success
    });

    return res.status(200).json({
      success: true,
      orderId,
      status,
      value,
      currency: 'BRL',
      hasSubid: Boolean(subid),
      results: {
        kiwify: {
          received: true,
          paymentConfirmed: true
        },
        bemob
      },
      message: 'Webhook recebido e processado pela aplicação.'
    });
  } catch (error) {
    logEvent('processing_failed', {
      error: error instanceof Error
        ? error.message
        : 'Erro desconhecido'
    });

    return res.status(500).json({
      success: false,
      error: 'Falha ao processar o webhook.',
      details: error instanceof Error
        ? error.message
        : 'Erro desconhecido'
    });
  }
}
