import crypto from 'crypto';

export function validateWebhookAuth(req) {
  const signature =
    req.headers['x-kiwify-signature'] ||
    req.query?.signature;

  const secretToken = process.env.KIWIFY_WEBHOOK_SECRET;

  if (!secretToken) {
    console.error('KIWIFY_WEBHOOK_SECRET não configurado.');
    return false;
  }

  if (!signature || typeof signature !== 'string') {
    return false;
  }

  try {
    const body =
      typeof req.rawBody === 'string'
        ? req.rawBody
        : JSON.stringify(req.body);

    const expectedSignature = crypto
      .createHmac('sha1', secretToken)
      .update(body, 'utf8')
      .digest('hex');

    const received = Buffer.from(signature, 'hex');
    const expected = Buffer.from(expectedSignature, 'hex');

    if (
      received.length !== expected.length ||
      received.length === 0
    ) {
      return false;
    }

    return crypto.timingSafeEqual(received, expected);
  } catch (error) {
    console.error('Erro ao validar assinatura do webhook.');
    return false;
  }
}