
<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function registrar(string $evento, array $dados = []): void
{
    error_log(json_encode([
        'timestamp' => gmdate('c'),
        'endpoint' => 'kiwify-webhook',
        'evento' => $evento,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function primeiroValor(array $valores): ?string
{
    foreach ($valores as $valor) {
        if (is_string($valor) && trim($valor) !== '') {
            return trim($valor);
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }
    }

    return null;
}

function extrairIdentificador(
    array $dados,
    array $nomes
): ?string {
    $objetos = [
        $dados,
        $dados['TrackingParameters'] ?? [],
        $dados['trackingParameters'] ?? [],
        $dados['tracking_parameters'] ?? [],
        $dados['tracking'] ?? [],
        $dados['tracking_data'] ?? [],
        $dados['metadata'] ?? []
    ];

    foreach ($objetos as $objeto) {
        if (!is_array($objeto)) {
            continue;
        }

        $valores = [];

        foreach ($nomes as $nome) {
            $valores[] = $objeto[$nome] ?? null;
        }

        $resultado = primeiroValor($valores);

        if ($resultado !== null) {
            return $resultado;
        }
    }

    return null;
}

/**
 * Valida a assinatura recebida na URL.
 *
 * O método HMAC-SHA1 só deve ser mantido se confirmado
 * como compatível com a assinatura oficial da Kiwify.
 */
function validarAutenticacao(string $corpo): bool
{
    $segredo = getenv('KIWIFY_WEBHOOK_SECRET');

    if (!is_string($segredo) || $segredo === '') {
        registrar('segredo_nao_configurado');

        return false;
    }

    $assinatura = $_GET['signature'] ?? '';

    if (!is_string($assinatura) || $assinatura === '') {
        registrar('assinatura_ausente');

        return false;
    }

    $assinaturaEsperada = hash_hmac(
        'sha1',
        $corpo,
        $segredo
    );

    if (!hash_equals($assinaturaEsperada, $assinatura)) {
        registrar('assinatura_invalida', [
            'assinatura_recebida' => true,
            'tamanho_corpo' => strlen($corpo)
        ]);

        return false;
    }

    registrar('assinatura_validada');

    return true;
}

// 1. Aceitar somente requisições POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    responder(405, [
        'success' => false,
        'error' => 'Método não permitido. Utilize POST.'
    ]);
}

// 2. Ler o corpo original da requisição.
$corpo = file_get_contents('php://input');

if (!is_string($corpo) || $corpo === '') {
    registrar('corpo_vazio');

    responder(400, [
        'success' => false,
        'error' => 'Corpo da requisição vazio.'
    ]);
}

// 3. Validar a assinatura antes de processar o pedido.
if (!validarAutenticacao($corpo)) {
    responder(401, [
        'success' => false,
        'error' => 'Assinatura inválida ou segredo não configurado.'
    ]);
}

// 4. Decodificar o JSON.
$dados = json_decode($corpo, true);

if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
    registrar('json_invalido');

    responder(400, [
        'success' => false,
        'error' => 'JSON inválido.'
    ]);
}

// 5. Identificar o pedido.
$idPedido = primeiroValor([
    $dados['order_id'] ?? null,
    $dados['orderId'] ?? null,
    $dados['order_ref'] ?? null,
    $dados['order']['id'] ?? null,
    $dados['order']['order_id'] ?? null,
    $dados['id'] ?? null
]);

// 6. Identificar o status.
$statusPedido = strtolower(trim((string) primeiroValor([
    $dados['order_status'] ?? null,
    $dados['orderStatus'] ?? null,
    $dados['order']['status'] ?? null,
    $dados['status'] ?? null,
    $dados['webhook_event_type'] ?? null,
    $dados['event'] ?? null
])));

registrar('webhook_recebido', [
    'pedido' => $idPedido,
    'status' => $statusPedido
]);

if ($idPedido === null) {
    responder(400, [
        'success' => false,
        'error' => 'ID do pedido ausente.'
    ]);
}

// 7. Processar somente status reconhecidos como pagos.
$statusConfirmados = [
    'paid',
    'approved',
    'complete',
    'completed',
    'compra aprovada',
    'paid_out'
];

if (!in_array($statusPedido, $statusConfirmados, true)) {
    responder(200, [
        'success' => true,
        'ignored' => true,
        'orderId' => $idPedido,
        'status' => $statusPedido,
        'message' => 'Evento recebido, mas o pagamento não foi reconhecido como confirmado.'
    ]);
}

// 8. Obter o valor informado no payload.
$comissoes = $dados['Commissions']
    ?? $dados['commissions']
    ?? [];

if (!is_array($comissoes)) {
    $comissoes = [];
}

$valorBruto = $comissoes['charge_amount'] ?? null;

if (!is_numeric($valorBruto) || (float) $valorBruto <= 0) {
    registrar('valor_invalido', [
        'pedido' => $idPedido
    ]);

    responder(400, [
        'success' => false,
        'orderId' => $idPedido,
        'error' => 'Valor da venda ausente ou inválido.'
    ]);
}

/*
 * ATENÇÃO:
 * Esta conversão pressupõe que charge_amount está em centavos.
 * Confirmar essa unidade com um payload real antes de usar
 * o valor para enviar conversões ao Google Ads.
 */
$valor = round((float) $valorBruto / 100, 2);

$moeda = strtoupper((string) (
    $comissoes['currency']
    ?? $comissoes['product_base_price_currency']
    ?? 'BRL'
));

if ($moeda !== 'BRL') {
    responder(400, [
        'success' => false,
        'orderId' => $idPedido,
        'currency' => $moeda,
        'error' => 'Esta versão está configurada para vendas em BRL.'
    ]);
}

// 9. Extrair identificadores de clique do Google Ads.
$gclid = extrairIdentificador(
    $dados,
    ['gclid', 'GCLID', 'gclid_id']
);

$gbraid = extrairIdentificador(
    $dados,
    ['gbraid', 'GBRAID']
);

$wbraid = extrairIdentificador(
    $dados,
    ['wbraid', 'WBRAID']
);

// 10. Registrar o resultado sem expor os identificadores.
registrar('pagamento_validado', [
    'pedido' => $idPedido,
    'valor' => $valor,
    'moeda' => $moeda,
    'tem_gclid' => $gclid !== null,
    'tem_gbraid' => $gbraid !== null,
    'tem_wbraid' => $wbraid !== null
]);

// 11. Google Ads ainda não está conectado.
// Nenhuma conversão é enviada nesta etapa.
responder(200, [
    'success' => true,
    'orderId' => $idPedido,
    'status' => $statusPedido,
    'value' => $valor,
    'currency' => $moeda,
    'clickIdentifiers' => [
        'gclid' => $gclid !== null,
        'gbraid' => $gbraid !== null,
        'wbraid' => $wbraid !== null
    ],
    'googleAds' => [
        'configured' => false,
        'sent' => false
    ],
    'message' => 'Webhook recebido. Google Ads ainda não conectado.'
]);
