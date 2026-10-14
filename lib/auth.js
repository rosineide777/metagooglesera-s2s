
import crypto from 'node:crypto';

export function validateWebhookAuth(req) {
  const expectedSecret = process.env.KIWIFY_WEBHOOK_SECRET;

  // A variável precisa estar configurada na Vercel.
  if (
    typeof expectedSecret !== 'string' ||
    expectedSecret.length === 0
  ) {
    console.error(
      '[kiwify-webhook] KIWIFY_WEBHOOK_SECRET não configurado.'
    );

    return false;
  }

  const headerSignature = req.headers?.['x-kiwify-signature'];

  const querySignature =
    typeof req.query?.signature === 'string'
      ? req.query.signature
      : Array.isArray(req.query?.signature)
        ? req.query.signature[0]
        : null;

  const receivedSecret =
    typeof headerSignature === 'string' &&
    headerSignature.length > 0
      ? headerSignature
      : querySignature;

  if (
    typeof receivedSecret !== 'string' ||
    receivedSecret.length === 0
  ) {
    console.warn(
      '[kiwify-webhook] Assinatura ausente na requisição.'
    );

    return false;
  }

  const expected = Buffer.from(expectedSecret, 'utf8');
  const received = Buffer.from(receivedSecret, 'utf8');

  if (expected.length !== received.length) {
    console.warn(
      '[kiwify-webhook] Assinatura recebida não corresponde ao segredo configurado.'
    );

    return false;
  }

  return crypto.timingSafeEqual(expected, received);
}
