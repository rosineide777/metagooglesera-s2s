
import crypto from 'crypto';

export function validateWebhookAuth(req) {
  const expectedSecret = process.env.KIWIFY_WEBHOOK_SECRET;

  const headerSignature =
    req.headers?.['x-kiwify-signature'];

  const querySignature =
    typeof req.query?.signature === 'string'
      ? req.query.signature
      : null;

  const receivedSecret =
    typeof headerSignature === 'string'
      ? headerSignature
      : querySignature;

  // Sem segredo configurado, não autoriza a requisição.
  if (
    typeof expectedSecret !== 'string' ||
    expectedSecret.trim() === ''
  ) {
    console.error(
      '[kiwify-webhook] KIWIFY_WEBHOOK_SECRET não configurado.'
    );

    return false;
  }

  if (
    typeof receivedSecret !== 'string' ||
    receivedSecret.length === 0
  ) {
    return false;
  }

  // Compara o token recebido com o segredo configurado.
  const expected = Buffer.from(expectedSecret, 'utf8');
  const received = Buffer.from(receivedSecret, 'utf8');

  if (expected.length !== received.length) {
    return false;
  }

  return crypto.timingSafeEqual(expected, received);
}
