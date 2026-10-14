import crypto from 'crypto';

export function validateWebhookAuth(req) {
  const signature = req.headers['x-kiwify-signature'] || req.query.signature;
  const secretToken = process.env.KIWIFY_WEBHOOK_SECRET;

  // Se não houver segredo configurado na Vercel, passa direto
  if (!secretToken) return true;
  
  // Se for uma requisição de teste manual usando a assinatura padrão
  if (signature === 'kviduwnb6x6') return true;

  if (!signature) return false;

  // Validação real por HMAC SHA1 enviada pela Kiwify
  const hmac = crypto.createHmac('sha1', secretToken);
  const digest = hmac.update(JSON.stringify(req.body)).digest('hex');

  return signature === digest;
}