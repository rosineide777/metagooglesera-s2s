
<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const GOOGLE_ADS_CUSTOMER_ID = '3435514966';
const GOOGLE_ADS_CONVERSION_ACTION_ID = '7842822186';

/* =========================================================
   RESPOSTAS E LOGS
   ========================================================= */

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

/* =========================================================
   PRIMEIRO VALOR VÁLIDO
   ========================================================= */

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

/* =========================================================
   BUSCA RECURSIVA DE IDENTIFICADORES
   ========================================================= */

function extrairIdentificador(
    array $dados,
    array $nomes
): ?string {
    $nomesNormalizados = array_map(
        'strtolower',
        $nomes
    );

    $buscar = function (array $objeto) use (
        &$buscar,
        $nomesNormalizados
    ): ?string {
        foreach ($objeto as $chave => $valor) {
            if (
                is_string($chave) &&
                in_array(
                    strtolower($chave),
                    $nomesNormalizados,
                    true
                )
            ) {
                $resultado = primeiroValor([$valor]);

                if ($resultado !== null) {
                    return $resultado;
                }
            }

            if (is_array($valor)) {
                $resultado = $buscar($valor);

                if ($resultado !== null) {
                    return $resultado;
                }
            }
        }

        return null;
    };

    return $buscar($dados);
}

/* =========================================================
   VALIDAÇÃO DA ASSINATURA KIWIFY
   ========================================================= */

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

    $esperada = hash_hmac('sha1', $corpo, $segredo);

    if (!hash_equals($esperada, $assinatura)) {
        registrar('assinatura_invalida');
        return false;
    }

    registrar('assinatura_validada');

    return true;
}

/* =========================================================
   TOKEN OAUTH DO GOOGLE
   ========================================================= */

function obterTokenGoogle(): string
{
    $clientId = getenv('GOOGLE_OAUTH_CLIENT_ID');
    $clientSecret = getenv('GOOGLE_OAUTH_CLIENT_SECRET');
    $refreshToken = getenv('GOOGLE_OAUTH_REFRESH_TOKEN');

    if (
        !$clientId ||
        !$clientSecret ||
        !$refreshToken
    ) {
        throw new RuntimeException(
            'Variáveis OAuth do Google não configuradas.'
        );
    }

    $curl = curl_init(
        'https://oauth2.googleapis.com/token'
    );

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ])
    ]);

    $resposta = curl_exec($curl);

    $statusHttp = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    $erroCurl = curl_error($curl);

    curl_close($curl);

    if (
        !is_string($resposta) ||
        $erroCurl !== '' ||
        $statusHttp < 200 ||
        $statusHttp >= 300
    ) {
        registrar('erro_token_google', [
            'http_status' => $statusHttp,
            'erro_curl' => $erroCurl !== ''
                ? $erroCurl
                : null
        ]);

        throw new RuntimeException(
            'Não foi possível obter o token OAuth do Google.'
        );
    }

    $dados = json_decode($resposta, true);

    if (
        !is_array($dados) ||
        empty($dados['access_token'])
    ) {
        throw new RuntimeException(
            'Resposta OAuth do Google sem access_token.'
        );
    }

    return $dados['access_token'];
}

/* =========================================================
   ENVIO DA CONVERSÃO PARA O GOOGLE DATA MANAGER API
   ========================================================= */

function enviarConversaoGoogle(
    string $token,
    string $idPedido,
    float $valor,
    string $moeda,
    string $timestamp,
    array $identificadores
): array {
    $adIdentifiers = [];

    foreach (['gclid', 'gbraid', 'wbraid'] as $campo) {
        if (
            isset($identificadores[$campo]) &&
            $identificadores[$campo] !== ''
        ) {
            $adIdentifiers[$campo] =
                $identificadores[$campo];
        }
    }

    if (count($adIdentifiers) === 0) {
        return [
            'sent' => false,
            'reason' => 'identificador_de_anuncio_ausente'
        ];
    }

    $payload = [
        'destinations' => [
            [
                'operatingAccount' => [
                    'accountType' => 'GOOGLE_ADS',
                    'accountId' => GOOGLE_ADS_CUSTOMER_ID
                ],
                'loginAccount' => [
                    'accountType' => 'GOOGLE_ADS',
                    'accountId' => GOOGLE_ADS_CUSTOMER_ID
                ],
                'productDestinationId' =>
                    GOOGLE_ADS_CONVERSION_ACTION_ID
            ]
        ],
        'encoding' => 'HEX',
        'events' => [
            [
                'adIdentifiers' => $adIdentifiers,
                'conversionValue' => $valor,
                'currency' => $moeda,
                'eventTimestamp' => $timestamp,
                'transactionId' => $idPedido,
                'eventSource' => 'WEB'
            ]
        ],
        'validateOnly' => false
    ];

    $corpoJson = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (!is_string($corpoJson)) {
        throw new RuntimeException(
            'Não foi possível montar o JSON para o Google.'
        );
    }

    $curl = curl_init(
        'https://datamanager.googleapis.com/v1/events:ingest'
    );

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $corpoJson
    ]);

    $resposta = curl_exec($curl);

    $statusHttp = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    $erroCurl = curl_error($curl);

    curl_close($curl);

    $respostaJson = is_string($resposta)
        ? json_decode($resposta, true)
        : null;

    if (
        $erroCurl !== '' ||
        !is_string($resposta) ||
        $statusHttp < 200 ||
        $statusHttp >= 300
    ) {
        registrar('erro_envio_google', [
            'pedido' => $idPedido,
            'http_status' => $statusHttp,
            'erro_curl' => $erroCurl !== ''
                ? $erroCurl
                : null,
            'resposta_google' => is_array($respostaJson)
                ? $respostaJson
                : null
        ]);

        return [
            'sent' => false,
            'httpStatus' => $statusHttp,
            'reason' => 'erro_na_api_google'
        ];
    }

    registrar('resposta_google_recebida', [
        'pedido' => $idPedido,
        'http_status' => $statusHttp,
        'resposta' => is_array($respostaJson)
            ? $respostaJson
            : null
    ]);

    return [
        'sent' => true,
        'httpStatus' => $statusHttp,
        'message' => 'Solicitação aceita pela API do Google.'
    ];
}

/* =========================================================
   1. SOMENTE POST
   ========================================================= */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    responder(405, [
        'success' => false,
        'error' => 'Método não permitido. Utilize POST.'
    ]);
}

/* =========================================================
   2. LER CORPO ORIGINAL
   ========================================================= */

$corpo = file_get_contents('php://input');

if (!is_string($corpo) || $corpo === '') {
    responder(400, [
        'success' => false,
        'error' => 'Corpo da requisição vazio.'
    ]);
}

/* =========================================================
   3. VALIDAR ASSINATURA
   ========================================================= */

if (!validarAutenticacao($corpo)) {
    responder(401, [
        'success' => false,
        'error' => 'Assinatura inválida ou segredo não configurado.'
    ]);
}

/* =========================================================
   4. DECODIFICAR JSON
   ========================================================= */

$dados = json_decode($corpo, true);

if (
    !is_array($dados) ||
    json_last_error() !== JSON_ERROR_NONE
) {
    responder(400, [
        'success' => false,
        'error' => 'JSON inválido.'
    ]);
}

/* =========================================================
   5. IDENTIFICAR PEDIDO
   ========================================================= */

$idPedido = primeiroValor([
    $dados['order_id'] ?? null,
    $dados['orderId'] ?? null,
    $dados['order_ref'] ?? null,
    $dados['order']['id'] ?? null,
    $dados['order']['order_id'] ?? null,
    $dados['id'] ?? null
]);

if ($idPedido === null) {
    responder(400, [
        'success' => false,
        'error' => 'ID do pedido ausente.'
    ]);
}

/* =========================================================
   6. VALIDAR STATUS DO PEDIDO
   ========================================================= */

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
        'message' => 'Pagamento não reconhecido como confirmado.'
    ]);
}

/* =========================================================
   7. DADOS FINANCEIROS
   ========================================================= */

$comissoes = $dados['Commissions']
    ?? $dados['commissions']
    ?? [];

if (!is_array($comissoes)) {
    $comissoes = [];
}

$valorBruto = $comissoes['charge_amount'] ?? null;

if (
    !is_numeric($valorBruto) ||
    (float) $valorBruto <= 0
) {
    responder(400, [
        'success' => false,
        'orderId' => $idPedido,
        'error' => 'Valor da venda ausente ou inválido.'
    ]);
}

/*
 * Mantida a regra anterior de dividir charge_amount por 100.
 * A unidade precisa ser confirmada com o payload real da Kiwify.
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
        'error' => 'Moeda não suportada por esta configuração.'
    ]);
}

/* =========================================================
   8. EXTRAIR IDENTIFICADORES DE ANÚNCIO
   ========================================================= */

$identificadores = [
    'gclid' => extrairIdentificador(
        $dados,
        [
            'gclid',
            'GCLID',
            'gclid_id',
            'google_click_id',
            'googleClickId'
        ]
    ),

    'gbraid' => extrairIdentificador(
        $dados,
        [
            'gbraid',
            'GBRAID'
        ]
    ),

    'wbraid' => extrairIdentificador(
        $dados,
        [
            'wbraid',
            'WBRAID'
        ]
    )
];

registrar('pagamento_validado', [
    'pedido' => $idPedido,
    'valor' => $valor,
    'moeda' => $moeda,
    'tem_gclid' => !empty($identificadores['gclid']),
    'tem_gbraid' => !empty($identificadores['gbraid']),
    'tem_wbraid' => !empty($identificadores['wbraid'])
]);

/* =========================================================
   9. NÃO ENVIAR SEM IDENTIFICADOR
   ========================================================= */

if (
    empty($identificadores['gclid']) &&
    empty($identificadores['gbraid']) &&
    empty($identificadores['wbraid'])
) {
    registrar('conversao_nao_enviada_sem_identificador', [
        'pedido' => $idPedido
    ]);

    responder(200, [
        'success' => true,
        'orderId' => $idPedido,
        'status' => $statusPedido,
        'value' => $valor,
        'currency' => $moeda,
        'googleAds' => [
            'configured' => true,
            'sent' => false,
            'reason' => 'identificador_de_anuncio_ausente'
        ],
        'message' => 'Evento recebido, mas não enviado ao Google Ads porque o webhook não contém um identificador reconhecido.'
    ]);
}

/* =========================================================
   10. DATA E HORA DO EVENTO
   ========================================================= */

$timestampOriginal = primeiroValor([
    $dados['approved_at'] ?? null,
    $dados['paid_at'] ?? null,
    $dados['created_at'] ?? null,
    $dados['updated_at'] ?? null,
    $dados['order']['approved_at'] ?? null,
    $dados['order']['created_at'] ?? null
]);

$timestamp = $timestampOriginal !== null
    ? strtotime($timestampOriginal)
    : false;

if ($timestamp === false) {
    $timestamp = time();
}

$timestampGoogle = gmdate(
    'Y-m-d\TH:i:s\Z',
    $timestamp
);

/* =========================================================
   11. ENVIAR PARA O GOOGLE
   ========================================================= */

try {
    $token = obterTokenGoogle();

    $resultadoGoogle = enviarConversaoGoogle(
        $token,
        $idPedido,
        $valor,
        $moeda,
        $timestampGoogle,
        $identificadores
    );

} catch (Throwable $e) {
    registrar('falha_google_ads', [
        'pedido' => $idPedido,
        'erro' => $e->getMessage()
    ]);

    responder(200, [
        'success' => true,
        'orderId' => $idPedido,
        'status' => $statusPedido,
        'googleAds' => [
            'configured' => true,
            'sent' => false,
            'reason' => 'falha_na_integracao'
        ],
        'message' => 'Evento recebido. O envio ao Google Ads falhou; confira os logs da Vercel.'
    ]);
}

/* =========================================================
   12. RESPOSTA FINAL
   ========================================================= */

responder(200, [
    'success' => true,
    'orderId' => $idPedido,
    'status' => $statusPedido,
    'value' => $valor,
    'currency' => $moeda,
    'googleAds' => array_merge(
        ['configured' => true],
        $resultadoGoogle
    )
]);
