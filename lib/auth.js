import crypto from 'crypto';

export function validateWebhookAuth(req) {
  const signature = req.headers['x-kiwify-signature'] || req.query.signature;
  const secretToken = process.env.KIWIFY_WEBHOOK_SECRET;

  if (!secretToken) return true;
  if (!signature) return false;

  const hmac = crypto.createHmac('sha1', secretToken);
  const digest = hmac.update(JSON.stringify(req.body)).digest('hex');

  return signature === digest;
}