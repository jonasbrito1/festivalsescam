<?php

/**
 * Envio de WhatsApp por um número comum, sem passar pela Meta.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ISTO EXISTE
 * ---------------------------------------------------------------------------
 * O caminho oficial (WhatsApp Cloud API, da Meta) tem uma regra que inviabiliza
 * o uso aqui: fora de uma janela de 24 horas desde a última mensagem que a
 * PESSOA enviou para o número da empresa, só é entregue MODELO DE MENSAGEM
 * aprovado previamente — texto livre não chega. A coordenação de um festival
 * não conversa com o sistema; ela só recebe. Então a janela nunca está aberta,
 * e o resultado final nunca chegaria.
 *
 * Foi exatamente o que aconteceu no teste: a Meta aceitou a mensagem, devolveu
 * protocolo, e ela não chegou ao aparelho.
 *
 * Este arquivo usa o outro caminho: a Evolution API, que mantém uma sessão de
 * WhatsApp comum — a mesma que o WhatsApp Web usa — e envia por ela. Sem
 * janela de 24 horas, sem modelo aprovado, sem aprovação de aplicativo.
 *
 * ---------------------------------------------------------------------------
 * O QUE ISSO CUSTA, DITO CLARAMENTE
 * ---------------------------------------------------------------------------
 * É uma conexão NÃO OFICIAL. O WhatsApp não a suporta e, em tese, pode
 * bloquear o número que a usa — o risco real está em disparo em massa para
 * desconhecidos, que não é o caso aqui: são poucas mensagens por festival,
 * para dois telefones da própria organização, que esperam recebê-las.
 *
 * A sessão se conecta lendo um QR Code com o celular, igual ao WhatsApp Web.
 * Se aquele aparelho ficar dias sem internet, a sessão cai e é preciso ler o
 * QR de novo — por isso a tela de configuração mostra o estado da conexão.
 */

declare(strict_types=1);

require_once __DIR__ . '/mysql.php';

/* ===========================================================================
 * CONFIGURAÇÃO
 * ======================================================================== */

function evo_url(): string
{
    return rtrim(wa_get('wa_evo_url'), '/');
}

function evo_instancia(): string
{
    return trim(wa_get('wa_evo_instancia'));
}

function evo_chave(): string
{
    return trim(wa_get('wa_evo_chave'));
}

/** Dá para conversar com o gateway? */
function evo_configurado(): bool
{
    return evo_url() !== '' && evo_instancia() !== '' && evo_chave() !== '';
}

/* ===========================================================================
 * CHAMADAS
 * ======================================================================== */

/**
 * Uma chamada ao gateway.
 *
 * @return array{ok:bool, codigo:int, json:array, erro:string}
 */
function evo_http(string $caminho, string $metodo = 'GET', ?array $corpo = null, int $tempo = 20): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'codigo' => 0, 'json' => [], 'erro' => 'Extensão cURL do PHP indisponível.'];
    }

    if (!evo_configurado()) {
        return ['ok' => false, 'codigo' => 0, 'json' => [], 'erro' => 'Gateway do WhatsApp não configurado.'];
    }

    $ch = curl_init(evo_url() . $caminho);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $tempo,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . evo_chave(),
        ],
    ];

    if ($corpo !== null) {
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opcoes);

    $resposta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'codigo' => 0, 'json' => [], 'erro' => 'Falha de conexão: ' . $erroCurl];
    }

    $json = json_decode((string)$resposta, true);
    $json = is_array($json) ? $json : [];

    if ($codigo >= 200 && $codigo < 300) {
        return ['ok' => true, 'codigo' => $codigo, 'json' => $json, 'erro' => ''];
    }

    $detalhe = $json['message'] ?? $json['error'] ?? mb_substr((string)$resposta, 0, 200);

    if (is_array($detalhe)) {
        $detalhe = implode('; ', array_map('strval', $detalhe));
    }

    return [
        'ok'     => false,
        'codigo' => $codigo,
        'json'   => $json,
        'erro'   => 'HTTP ' . $codigo . ': ' . evo_higienizar((string)$detalhe),
    ];
}

/** A chave nunca vai para o banco nem para a tela dentro de uma mensagem de erro. */
function evo_higienizar(string $texto): string
{
    $chave = evo_chave();

    return $chave !== '' && strlen($chave) > 8 ? str_replace($chave, '***', $texto) : $texto;
}

/* ===========================================================================
 * CONEXÃO
 * ======================================================================== */

/**
 * Estado da sessão.
 *
 * @return array{estado:string, conectado:bool, numero:string, erro:string}
 */
function evo_estado(): array
{
    $vazio = ['estado' => 'desconhecido', 'conectado' => false, 'numero' => '', 'erro' => ''];

    if (!evo_configurado()) {
        return ['erro' => 'Gateway não configurado.'] + $vazio;
    }

    $r = evo_http('/instance/connectionState/' . rawurlencode(evo_instancia()));

    if (!$r['ok']) {
        /* 404 é a instância que ainda não existe — não é erro de verdade, é o
           estado "nunca foi conectada". */
        return [
            'estado'    => $r['codigo'] === 404 ? 'inexistente' : 'erro',
            'conectado' => false,
            'numero'    => '',
            'erro'      => $r['codigo'] === 404 ? '' : $r['erro'],
        ];
    }

    $estado = (string)($r['json']['instance']['state'] ?? $r['json']['state'] ?? 'desconhecido');

    return [
        'estado'    => $estado,
        'conectado' => $estado === 'open',
        'numero'    => evo_numero_conectado(),
        'erro'      => '',
    ];
}

/** O número de WhatsApp que está logado nesta instância. */
function evo_numero_conectado(): string
{
    $r = evo_http('/instance/fetchInstances?instanceName=' . rawurlencode(evo_instancia()));

    if (!$r['ok']) {
        return '';
    }

    $lista = $r['json'];
    $primeiro = $lista[0] ?? $lista;
    $dados = $primeiro['instance'] ?? $primeiro;
    $jid = (string)($dados['ownerJid'] ?? $dados['owner'] ?? '');

    return $jid !== '' ? explode('@', $jid)[0] : '';
}

/**
 * Devolve o QR Code para ligar o WhatsApp. Cria a instância na primeira vez.
 *
 * @return array{ok:bool, qr:string, pareamento:string, estado:string, erro:string}
 */
function evo_conectar(): array
{
    $vazio = ['ok' => false, 'qr' => '', 'pareamento' => '', 'estado' => '', 'erro' => ''];

    if (!evo_configurado()) {
        return ['erro' => 'Informe endereço, instância e chave do gateway antes de conectar.'] + $vazio;
    }

    $estado = evo_estado();

    if ($estado['conectado']) {
        return ['ok' => true, 'estado' => 'open', 'erro' => ''] + $vazio;
    }

    /* Primeira vez: a instância precisa existir antes de gerar QR. */
    if ($estado['estado'] === 'inexistente') {
        $criacao = evo_http('/instance/create', 'POST', [
            'instanceName' => evo_instancia(),
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
        ], 30);

        if (!$criacao['ok']) {
            return ['erro' => 'Não foi possível criar a sessão: ' . $criacao['erro']] + $vazio;
        }

        $qr = evo_extrair_qr($criacao['json']);

        if ($qr['qr'] !== '') {
            return ['ok' => true, 'estado' => 'connecting', 'erro' => ''] + $qr + $vazio;
        }
    }

    $r = evo_http('/instance/connect/' . rawurlencode(evo_instancia()), 'GET', null, 30);

    if (!$r['ok']) {
        return ['erro' => $r['erro']] + $vazio;
    }

    $qr = evo_extrair_qr($r['json']);

    if ($qr['qr'] === '') {
        return ['erro' => 'O gateway não devolveu o QR Code. Tente novamente em alguns segundos.'] + $vazio;
    }

    return ['ok' => true, 'estado' => 'connecting', 'erro' => ''] + $qr + $vazio;
}

/**
 * O QR vem em lugares diferentes conforme a versão do gateway.
 *
 * @return array{qr:string, pareamento:string}
 */
function evo_extrair_qr(array $json): array
{
    $qr = (string)($json['qrcode']['base64'] ?? $json['base64'] ?? '');
    $par = (string)($json['qrcode']['pairingCode'] ?? $json['pairingCode'] ?? '');

    /* Algumas versões devolvem sem o prefixo de imagem. */
    if ($qr !== '' && !str_starts_with($qr, 'data:')) {
        $qr = 'data:image/png;base64,' . $qr;
    }

    return ['qr' => $qr, 'pareamento' => $par];
}

/** Desliga a sessão (o celular deixa de aparecer como dispositivo conectado). */
function evo_desconectar(): array
{
    $r = evo_http('/instance/logout/' . rawurlencode(evo_instancia()), 'DELETE');

    return ['ok' => $r['ok'], 'erro' => $r['erro']];
}

/* ===========================================================================
 * ENVIO
 * ======================================================================== */

/**
 * Manda uma mensagem de texto.
 *
 * @return array{ok:bool, erro:string, id?:string}
 */
function evo_enviar_texto(string $telefone, string $texto): array
{
    if (!evo_configurado()) {
        return ['ok' => false, 'erro' => 'Gateway do WhatsApp não configurado.'];
    }

    $r = evo_http('/message/sendText/' . rawurlencode(evo_instancia()), 'POST', [
        'number' => $telefone,
        'text'   => $texto,
    ], 25);

    if ($r['ok']) {
        return ['ok' => true, 'erro' => '', 'id' => (string)($r['json']['key']['id'] ?? '')];
    }

    return ['ok' => false, 'erro' => evo_explicar($r)];
}

/** Traduz as falhas mais comuns do gateway em orientação prática. */
function evo_explicar(array $r): string
{
    $m = mb_strtolower($r['erro'] ?? '');

    if (($r['codigo'] ?? 0) === 401 || str_contains($m, 'unauthorized')) {
        return $r['erro'] . ' — a chave do gateway está errada.';
    }

    if (($r['codigo'] ?? 0) === 404) {
        return $r['erro'] . ' — a sessão não existe no gateway. Conecte o WhatsApp em Configurações.';
    }

    if (str_contains($m, 'connection closed') || str_contains($m, 'not connected') || str_contains($m, 'close')) {
        return $r['erro'] . ' — o WhatsApp está desconectado. Leia o QR Code de novo em Configurações.';
    }

    if (str_contains($m, 'exists') && str_contains($m, 'not')) {
        return $r['erro'] . ' — este número não tem WhatsApp.';
    }

    return (string)($r['erro'] ?? 'Falha desconhecida no gateway.');
}
