<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function extrairIdentificador(array $dados, array $nomes): ?string
{
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

function validarAutenticacao(): bool
{
    $segredoEsperado = getenv('KIWIFY_WEBHOOK_SECRET');

    if (!is_string($segredoEsperado) || $segredoEsperado === '') {
        registrar('segredo_nao_configurado');
        return false;
    }

    $assinatura = $_SERVER['HTTP_X_KIWIFY_SIGNATURE'] ?? null;

    if (!is_string($assinatura) || $assinatura === '') {
        $assinatura = $_GET['signature'] ?? null;
    }

    if (!is_string($assinatura) || $assinatura === '') {
        return false;
    }

    return hash_equals($segredoEsperado, $assinatura);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    responder(405, [
        'success' => false,
        'error' => 'Método não permitido. Utilize POST.'
    ]);
}

if (!validarAutenticacao()) {
    responder(401, [
        'success' => false,
        'error' => 'Autenticação inválida ou não configurada.'
    ]);
}

$corpo = file_get_contents('php://input');

if (!is_string($corpo) || $corpo === '') {
    responder(400, [
        'success' => false,
        'error' => 'Corpo da requisição vazio.'
    ]);
}

$dados = json_decode($corpo, true);

if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
    responder(400, [
        'success' => false,
        'error' => 'JSON inválido.'
    ]);
}

$idPedido = primeiroValor([
    $dados['order_id'] ?? null,
    $dados['orderId'] ?? null,
    $dados['order_ref'] ?? null,
    $dados['order']['id'] ?? null,
    $dados['order']['order_id'] ?? null,
    $dados['id'] ?? null
]);

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

$comissoes = $dados['Commissions'] ?? $dados['commissions'] ?? [];
$valorBruto = $comissoes['charge_amount'] ?? null;

if (!is_numeric($valorBruto) || (float) $valorBruto <= 0) {
    responder(400, [
        'success' => false,
        'orderId' => $idPedido,
        'error' => 'Valor da venda ausente ou inválido.'
    ]);
}

/*
 * Pressupõe charge_amount em centavos.
 * Confirmar com o payload real da Kiwify antes da ativação.
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

$gclid = extrairIdentificador($dados, ['gclid', 'GCLID', 'gclid_id']);
$gbraid = extrairIdentificador($dados, ['gbraid', 'GBRAID']);
$wbraid = extrairIdentificador($dados, ['wbraid', 'WBRAID']);

registrar('pagamento_validado', [
    'pedido' => $idPedido,
    'valor' => $valor,
    'moeda' => $moeda,
    'tem_gclid' => $gclid !== null,
    'tem_gbraid' => $gbraid !== null,
    'tem_wbraid' => $wbraid !== null
]);

/*
 * Integração Google Ads pendente.
 * Ainda não envia conversões para a API.
 */

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
    'message' => 'Pagamento validado. Integração com o Google Ads ainda não ativada.'
]);
