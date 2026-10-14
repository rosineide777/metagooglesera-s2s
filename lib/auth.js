
import crypto from 'crypto';

export function validateWebhookAuth(req) {
  const expectedSecret = process.env.KIWIFY_WEBHOOK_SECRET;

  const receivedSecret =
    req.query?.signature ||
    req.headers['x-kiwify-signature'];

  if (
    typeof expectedSecret !== 'string' ||
    typeof receivedSecret !== 'string' ||
    !expectedSecret ||
    !receivedSecret
  ) {
    return false;
  }

  const expected = Buffer.from(expectedSecret, 'utf8');
  const received = Buffer.from(receivedSecret, 'utf8');

  if (expected.length !== received.length) {
    return false;
  }

  return crypto.timingSafeEqual(expected, received);
}
