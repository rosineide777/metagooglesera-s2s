import { extractTrackingId } from '../lib/tracking.js';
import { validateWebhookAuth } from '../lib/auth.js';

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ success: false, error: 'Método não permitido' });
  }

  try {
    // 1. Valida a segurança
    if (!validateWebhookAuth(req)) {
      return res.status(401).json({ success: false, error: 'Assinatura inválida' });
    }

    const body = req.body;
    if (!body || typeof body !== 'object' || Array.isArray(body)) {
      return res.status(400).json({ success: false, error: 'Corpo da requisição inválido' });
    }

    // 2. Verifica se o estado é pago/aprovado
    const status = String(body.order_status || body.status || '').toLowerCase();
    if (!['paid', 'approved', 'complete'].includes(status)) {
      return res.status(200).json({ success: true, message: 'Pedido ignorado: status não aprovado.' });
    }

    const orderId = String(body.order_id || body.id || '');
    if (!orderId) {
      return res.status(400).json({ success: false, error: 'ID do pedido ausente' });
    }

    // 3. Extrai o ID de rastreamento (ClickID / SubID)
    const subid = extractTrackingId(body);

    // 4. Extrai o valor
    const rawValue = body.Commissions?.charge_amount ?? body.price ?? body.order?.price ?? 0;
    const numericValue = Number(rawValue);
    const value = body.Commissions?.charge_amount != null
      ? (numericValue / 100).toFixed(2)
      : numericValue.toFixed(2);

    const results = {};

    // --- A. BEMOB POSTBACK ---
    const bemobBaseUrl = process.env.BEMOB_POSTBACK_URL;
    if (bemobBaseUrl && subid) {
      const separator = bemobBaseUrl.includes('?') ? '&' : '?';
      const postbackUrl = `${bemobBaseUrl}${separator}cid=${encodeURIComponent(subid)}&payout=${value}&txid=${encodeURIComponent(orderId)}`;
      const bemobRes = await fetch(postbackUrl);
      results.bemob = bemobRes.ok ? 'Enviado com sucesso' : 'Erro ao enviar';
    }

    // --- B. META ADS (CAPI) ---
    const pixelId = process.env.META_PIXEL_ID;
    const accessToken = process.env.META_ACCESS_TOKEN;

    if (pixelId && accessToken) {
      const fbclid = body.trackingParameters?.fbclid || body.fbclid || '';
      const userData = {};
      if (subid) userData.external_id = subid;
      if (fbclid) userData.fbc = `fb.1.${Date.now()}.${fbclid}`;

      const metaPayload = {
        data: [
          {
            event_name: 'Purchase',
            event_time: Math.floor(Date.now() / 1000),
            action_source: 'system_generated',
            user_data: userData,
            custom_data: { currency: 'BRL', value: parseFloat(value) }
          }
        ]
      };

      const metaRes = await fetch(`https://graph.facebook.com/v18.0/${pixelId}/events?access_token=${accessToken}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(metaPayload)
      });
      results.meta = await metaRes.json();
    }

    // --- C. GOOGLE ADS ---
    const conversionId = process.env.GOOGLE_CONVERSION_ID;
    const conversionLabel = process.env.GOOGLE_CONVERSION_LABEL;
    const gclid = body.trackingParameters?.gclid || body.gclid || '';

    if (conversionId && conversionLabel && gclid) {
      results.google = { status: 'Registado com sucesso', gclid, value };
    }

    return res.status(200).json({
      success: true,
      orderId,
      results,
      message: 'Webhook processado com sucesso.'
    });

  } catch (error) {
    console.error('Erro no S2S:', error);
    return res.status(500).json({ success: false, error: 'Erro interno ao processar o webhook.' });
  }
}