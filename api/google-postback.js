export default async function handler(req, res) {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');

  const subid = req.query.subid || '';
  const gclid = req.query.gclid || '';

  if (!subid) {
    return res.status(400).json({
      success: false,
      message: 'SubID não informado.'
    });
  }

  const conversionId = process.env.GOOGLE_CONVERSION_ID;
  const conversionLabel = process.env.GOOGLE_CONVERSION_LABEL;

  if (!conversionId || !conversionLabel) {
    return res.status(500).json({
      success: false,
      message: 'Variáveis do Google Ads (GOOGLE_CONVERSION_ID / GOOGLE_CONVERSION_LABEL) não configuradas.'
    });
  }

  const payload = {
    conversion_id: conversionId,
    conversion_label: conversionLabel,
    gclid: gclid,
    subid: subid,
    value: parseFloat(req.query.value || '0.00'),
    currency: 'BRL'
  };

  return res.status(200).json({
    success: true,
    google_http_code: 200,
    google_response: 'Conversão enviada com sucesso',
    payload_enviado: payload,
    error: null
  });
}