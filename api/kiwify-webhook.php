<?php

header('Content-Type: application/json; charset=utf-8');

// 1. Captura os dados da requisição (seja via GET do BeMob ou POST/Webhook da Kiwify)
$method = $_SERVER['REQUEST_METHOD'];

$subid = $_GET['subid'] ?? '';
$gclid = $_GET['gclid'] ?? '';
$payout = $_GET['payout'] ?? '0';

// Se a requisição for um Webhook (POST) da Kiwify:
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $payloadData = json_decode($rawInput, true) ?? [];

    // Tenta pegar o gclid e subid vindos dos parâmetros da Kiwify (ex: TrackingParameters / src)
    if (empty($gclid)) {
        $gclid = $payloadData['TrackingParameters']['gclid'] ?? $payloadData['gclid'] ?? '';
    }
    
    if (empty($subid)) {
        // A Kiwify repassa o subid no campo 'src' do Checkout
        $subid = $payloadData['TrackingParameters']['src'] ?? $payloadData['src'] ?? '';
    }

    // Pega o valor da comissão/venda na Kiwify (em centavos e converte para Reais)
    if (isset($payloadData['Commissions']['charge_amount'])) {
        $payout = number_format($payloadData['Commissions']['charge_amount'] / 100, 2, '.', '');
    }
}

// 2. Validações básicas
if (empty($subid) && empty($gclid)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum identificador (subid ou gclid) foi fornecido.'
    ]);
    exit;
}

// 3. Obtém credenciais do Google Ads das variáveis de ambiente na Vercel
$developerToken = getenv('GOOGLE_DEVELOPER_TOKEN');
$customerId = getenv('GOOGLE_CUSTOMER_ID'); // Apenas números, sem hífen
$loginCustomerId = getenv('GOOGLE_LOGIN_CUSTOMER_ID'); // Opcional (MCC)
$accessToken = getenv('GOOGLE_ACCESS_TOKEN'); // Token OAuth2
$conversionActionId = getenv('GOOGLE_CONVERSION_ACTION_ID'); // ID da Ação de Conversão

if (!$developerToken || !$customerId || !$accessToken || !$conversionActionId) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Variáveis do Google Ads não configuradas.'
    ]);
    exit;
}

// 4. Prepara e dispara a requisição para a API do Google Ads (Offline Conversions)
$googleResponse = null;
$googleHttpCode = null;
$googleCurlError = '';

if (!empty($gclid)) {
    $endpoint = "https://googleads.googleapis.com/v14/customers/{$customerId}:uploadClickConversions";
    $conversionDateTime = gmdate("Y-m-d\TH:i:s\Z");

    $payloadGoogle = [
        'conversions' => [
            [
                'gclid' => $gclid,
                'conversionAction' => "customers/{$customerId}/conversionActions/{$conversionActionId}",
                'conversionDateTime' => $conversionDateTime,
                'conversionValue' => (float)$payout,
                'currencyCode' => 'BRL'
            ]
        ],
        'partialFailure' => true
    ];

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'developer-token: ' . $developerToken,
        'Content-Type: application/json'
    ];

    if (!empty($loginCustomerId)) {
        $headers[] = 'login-customer-id: ' . $loginCustomerId;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payloadGoogle),
        CURLOPT_TIMEOUT => 15
    ]);

    $googleResponse = curl_exec($ch);
    $googleHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $googleCurlError = curl_error($ch);
    curl_close($ch);
}

// 5. Se o subid do BeMob estiver presente, também dispara o Postback para o BeMob
$bemobResponse = null;
if (!empty($subid)) {
    $bemobPostbackUrl = "https://guiamento.online/postback?cid={$subid}&payout={$payout}";
    
    $chBemob = curl_init($bemobPostbackUrl);
    curl_setopt_array($chBemob, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $bemobResponse = curl_exec($chBemob);
    curl_close($chBemob);
}

// 6. Retorna resposta JSON consolidada
echo json_encode([
    'success' => ($googleHttpCode === 200 || $googleHttpCode === null) && empty($googleCurlError),
    'google_http_code' => $googleHttpCode,
    'google_response' => json_decode($googleResponse, true),
    'bemob_response' => $bemobResponse,
    'curl_error' => $googleCurlError
]);