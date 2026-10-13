export default async function handler(req, res) {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');

  const subid = req.query.subid || '';
  const fbclid = req.query.fbclid || '';

  if (!subid) {
    return res.status(400).json({
      success: false,
      message: 'SubID não informado.'
    });
  }

  const pixelId = process.env.META_PIXEL_ID;
  const accessToken = process.env.META_ACCESS_TOKEN;

  if (!pixelId || !accessToken) {
    return res.status(500).json({
      success: false,
      message: 'Variáveis do Meta Ads (META_PIXEL_ID / META_ACCESS_TOKEN) não configuradas.'
    });
  }

  const userData = {
    external_id: subid
  };

  if (fbclid) {
    userData.fbc = `fb.1.${Date.now()}.${fbclid}`;
  }

  // Payload seguro: sem URL da página e com action_source interno
  const payload = {
    data: [
      {
        event_name: 'Purchase',
        event_time: Math.floor(Date.now() / 1000),
        action_source: 'system_generated',
        user_data: userData,
        custom_data: {
          currency: 'BRL',
          value: parseFloat(req.query.value || '0.00')
        }
      }
    ]
  };

  try {
    const apiResponse = await fetch(`https://graph.facebook.com/v18.0/${pixelId}/events?access_token=${accessToken}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const responseData = await apiResponse.json();

    return res.status(200).json({
      success: apiResponse.ok,
      meta_http_code: apiResponse.status,
      meta_response: responseData,
      payload_enviado: payload,
      error: null
    });
  } catch (error) {
    return res.status(500).json({
      success: false,
      meta_http_code: 500,
      meta_response: null,
      payload_enviado: payload,
      error: error.message
    });
  }
}