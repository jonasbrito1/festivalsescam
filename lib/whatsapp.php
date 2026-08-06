<?php

/**
 * Integração com o WhatsApp Cloud API.
 *
 * ---------------------------------------------------------------------------
 * COMO FUNCIONA
 * ---------------------------------------------------------------------------
 * Toda mensagem vira uma linha na tabela `mensagens` ANTES de sair. O envio
 * atualiza essa linha para 'enviado' ou 'erro'. É isso que sustenta a tela de
 * acompanhamento e o botão de reenviar: nenhuma mensagem se perde sem deixar
 * registro, mesmo que a API esteja fora.
 *
 * Dois caminhos de saída, decididos pela configuração:
 *
 *   1. Endpoint próprio (ex.: http://127.0.0.1:3000/api/whatsapp/send)
 *      Quando preenchido, o sistema entrega a mensagem a esse serviço.
 *      Útil quando já existe um backend cuidando da fila e do WhatsApp.
 *
 *   2. Graph API direto (https://graph.facebook.com/<versao>/<phone_id>/messages)
 *      Usado quando não há endpoint próprio configurado.
 *
 * ---------------------------------------------------------------------------
 * SOBRE O TOKEN
 * ---------------------------------------------------------------------------
 * O token de acesso fica na tabela `integracao_config`, marcado como sigiloso,
 * e NUNCA é devolvido para o navegador — a tela mostra apenas se ele existe.
 * Também não aparece em log nenhum: as mensagens de erro são higienizadas
 * antes de serem gravadas.
 */

declare(strict_types=1);

require_once __DIR__ . '/mysql.php';

/* ===========================================================================
 * CONFIGURAÇÃO
 * ======================================================================== */

/** @return array<string,string> */
function wa_config(bool $recarregar = false): array
{
    static $cache = null;

    if ($cache !== null && !$recarregar) {
        return $cache;
    }

    $cache = [];
    $pdo = mysql_conexao();
    if (!$pdo) {
        return $cache;
    }

    try {
        foreach ($pdo->query('SELECT chave, valor FROM integracao_config') as $l) {
            $cache[$l['chave']] = (string) ($l['valor'] ?? '');
        }
    } catch (Throwable $e) {
        error_log('WhatsApp: falha ao ler configuração: ' . $e->getMessage());
    }

    return $cache;
}

function wa_get(string $chave, string $padrao = ''): string
{
    /* O token pode vir do AMBIENTE, e o ambiente tem precedência.
     *
     * É a forma mais segura de guardá-lo: definido no pool do PHP-FPM
     * (env[FESTIVAL_WA_TOKEN]), ele nunca entra no banco e, portanto, nunca
     * aparece em dump, backup ou cópia da base. A tela passa a mostrar que a
     * credencial vem do servidor e deixa de aceitar edição.
     *
     * Sem a variável, cai para o valor gravado pela tela — que continua
     * funcionando, só com uma superfície de exposição maior. */
    if ($chave === 'wa_token') {
        $doAmbiente = getenv('FESTIVAL_WA_TOKEN');
        if (is_string($doAmbiente) && $doAmbiente !== '') {
            return $doAmbiente;
        }
    }

    $c = wa_config();

    return $c[$chave] ?? $padrao;
}

/** O token está vindo do ambiente (e não do banco)? */
function wa_token_do_ambiente(): bool
{
    $v = getenv('FESTIVAL_WA_TOKEN');

    return is_string($v) && $v !== '';
}

/**
 * Grava configurações. Valores vazios em campos sigilosos são IGNORADOS —
 * assim a tela pode enviar o campo do token em branco sem apagar o que já
 * está gravado.
 *
 * @param array<string,string> $valores
 */
function wa_salvar_config(array $valores): bool
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return false;
    }

    $sigilosos = ['wa_token'];

    try {
        $sql = $pdo->prepare(
            'INSERT INTO integracao_config (chave, valor, sigiloso) VALUES (:c, :v, :s)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );

        foreach ($valores as $chave => $valor) {
            $ehSigiloso = in_array($chave, $sigilosos, true);

            if ($ehSigiloso && trim((string) $valor) === '') {
                continue; // preserva o valor atual
            }

            $sql->execute([
                ':c' => $chave,
                ':v' => (string) $valor,
                ':s' => $ehSigiloso ? 1 : 0,
            ]);
        }

        wa_config(true);

        return true;
    } catch (Throwable $e) {
        error_log('WhatsApp: falha ao salvar configuração: ' . $e->getMessage());

        return false;
    }
}

function wa_ativo(): bool
{
    if (wa_get('wa_ativo') !== '1') {
        return false;
    }

    // Precisa de um caminho de saída válido.
    if (wa_get('wa_endpoint') !== '') {
        return true;
    }

    return wa_get('wa_token') !== '' && wa_get('wa_phone_number_id') !== '';
}

/** A tela nunca recebe o token; só sabe se ele existe. */
function wa_tem_token(): bool
{
    return wa_get('wa_token') !== '';
}

/* ===========================================================================
 * TELEFONE
 * ======================================================================== */

/**
 * Normaliza o telefone para o formato que a API espera: só dígitos, com DDI.
 *
 * Aceita o que a pessoa digitar — "(92) 98487-8678", "92984878678",
 * "+55 92 98487-8678" — e devolve "5592984878678".
 *
 * Devolve '' se não for um número plausível.
 */
function wa_telefone(string $bruto, string $ddiPadrao = ''): string
{
    $ddiPadrao = $ddiPadrao !== '' ? $ddiPadrao : (wa_get('wa_ddi_padrao') ?: '55');
    $d = preg_replace('/\D+/', '', $bruto) ?? '';

    if ($d === '') {
        return '';
    }

    // Já veio com DDI do Brasil e comprimento coerente (55 + DDD + número).
    if (str_starts_with($d, '55') && strlen($d) >= 12 && strlen($d) <= 13) {
        return $d;
    }

    // DDD + número, sem DDI.
    if (strlen($d) === 10 || strlen($d) === 11) {
        return $ddiPadrao . $d;
    }

    // Número internacional já completo.
    if (strlen($d) >= 11 && strlen($d) <= 15) {
        return $d;
    }

    return '';
}

/** Versão legível, para mostrar na tela. */
function wa_telefone_exibicao(string $e164): string
{
    $d = preg_replace('/\D+/', '', $e164) ?? '';

    if (str_starts_with($d, '55') && strlen($d) >= 12) {
        $ddd = substr($d, 2, 2);
        $num = substr($d, 4);
        $meio = strlen($num) === 9 ? substr($num, 0, 5) : substr($num, 0, 4);
        $fim = strlen($num) === 9 ? substr($num, 5) : substr($num, 4);

        return "($ddd) $meio-$fim";
    }

    return $d !== '' ? '+' . $d : '';
}

/* ===========================================================================
 * FILA
 * ======================================================================== */

/**
 * Coloca uma mensagem na fila. Devolve o id da linha, ou 0 em falha.
 *
 * Nada é enviado aqui — só registrado. Quem envia é wa_enviar().
 */
function wa_enfileirar(
    string $tipo,
    string $destinatario,
    string $telefone,
    string $mensagem,
    ?int $eventoId = null,
    ?int $juradoId = null,
    ?int $participanteId = null
): int {
    $pdo = mysql_conexao();
    if (!$pdo) {
        return 0;
    }

    $tel = wa_telefone($telefone);
    if ($tel === '') {
        error_log('WhatsApp: telefone inválido para ' . $destinatario);
    }

    try {
        $sql = $pdo->prepare(
            'INSERT INTO mensagens
                (event_id, judge_id, participant_id, tipo, destinatario, telefone, mensagem, status, erro)
             VALUES (:ev, :ju, :pa, :tipo, :dest, :tel, :msg, :status, :erro)'
        );
        $sql->execute([
            ':ev'     => $eventoId,
            ':ju'     => $juradoId,
            ':pa'     => $participanteId,
            ':tipo'   => $tipo,
            ':dest'   => mb_substr($destinatario, 0, 120),
            ':tel'    => $tel !== '' ? $tel : preg_replace('/\D+/', '', $telefone),
            ':msg'    => $mensagem,
            ':status' => $tel === '' ? 'erro' : 'pendente',
            ':erro'   => $tel === '' ? 'Telefone inválido ou não informado.' : null,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('WhatsApp: falha ao enfileirar: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Envia uma mensagem da fila. Devolve ['ok'=>bool, 'erro'=>string].
 */
function wa_enviar(int $mensagemId): array
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return ['ok' => false, 'erro' => 'Banco indisponível.'];
    }

    $st = $pdo->prepare('SELECT * FROM mensagens WHERE id = ?');
    $st->execute([$mensagemId]);
    $m = $st->fetch();

    if (!$m) {
        return ['ok' => false, 'erro' => 'Mensagem não encontrada.'];
    }

    /* Recusa reenviar credenciais.
     *
     * O corpo guardado tem a senha mascarada — reenviar mandaria ao jurado
     * uma mensagem com "Senha: ••••••••", inútil e confusa. A regra fica
     * aqui, e não só na tela: esconder o botão não impede alguém de chamar
     * a ação direto. */
    if (($m['tipo'] ?? '') === 'credenciais') {
        return [
            'ok'   => false,
            'erro' => 'Credenciais não podem ser reenviadas — a senha não fica guardada. Gere uma nova senha.',
        ];
    }

    if (!wa_ativo()) {
        wa_marcar($mensagemId, 'erro', 'Integração do WhatsApp desativada ou incompleta.');

        return ['ok' => false, 'erro' => 'Integração desativada.'];
    }

    $tel = wa_telefone((string) $m['telefone']);
    if ($tel === '') {
        wa_marcar($mensagemId, 'erro', 'Telefone inválido.');

        return ['ok' => false, 'erro' => 'Telefone inválido.'];
    }

    $r = wa_disparar($tel, (string) $m['mensagem']);

    if ($r['ok']) {
        wa_marcar($mensagemId, 'enviado', null, $r['id'] ?? null);

        return ['ok' => true, 'erro' => ''];
    }

    wa_marcar($mensagemId, 'erro', $r['erro']);

    return ['ok' => false, 'erro' => $r['erro']];
}

function wa_marcar(int $id, string $status, ?string $erro = null, ?string $idProvedor = null): void
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return;
    }

    try {
        $pdo->prepare(
            'UPDATE mensagens
                SET status = :s,
                    erro = :e,
                    id_provedor = COALESCE(:p, id_provedor),
                    tentativas = tentativas + 1,
                    enviado_em = IF(:s2 = \'enviado\', NOW(), enviado_em)
              WHERE id = :id'
        )->execute([
            ':s'  => $status,
            ':s2' => $status,
            ':e'  => $erro !== null ? mb_substr(wa_higienizar($erro), 0, 500) : null,
            ':p'  => $idProvedor,
            ':id' => $id,
        ]);
    } catch (Throwable $e) {
        error_log('WhatsApp: falha ao marcar mensagem: ' . $e->getMessage());
    }
}

/**
 * Remove qualquer token que apareça em mensagem de erro antes de gravá-la.
 * Sem isto, uma resposta de erro da API poderia gravar a credencial no banco
 * e exibi-la na tela de acompanhamento.
 */
function wa_higienizar(string $texto): string
{
    $token = wa_get('wa_token');

    if ($token !== '' && strlen($token) > 8) {
        $texto = str_replace($token, '***', $texto);
    }

    return preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]{12,}/i', '$1***', $texto) ?? $texto;
}

/* ===========================================================================
 * SAÍDA
 * ======================================================================== */

/**
 * Faz o disparo de fato. @return array{ok:bool,erro:string,id?:string}
 */
function wa_disparar(string $telefone, string $texto): array
{
    $endpoint = wa_get('wa_endpoint');

    return $endpoint !== ''
        ? wa_via_endpoint($endpoint, $telefone, $texto)
        : wa_via_graph($telefone, $texto);
}

/** Entrega a um backend próprio. */
function wa_via_endpoint(string $url, string $telefone, string $texto): array
{
    $corpo = json_encode(['to' => $telefone, 'message' => $texto], JSON_UNESCAPED_UNICODE);

    $cabecalhos = ['Content-Type: application/json'];
    if (wa_get('wa_token') !== '') {
        $cabecalhos[] = 'Authorization: Bearer ' . wa_get('wa_token');
    }

    return wa_http($url, $corpo, $cabecalhos);
}

/** Chama a Graph API do WhatsApp Cloud. */
function wa_via_graph(string $telefone, string $texto): array
{
    $versao = wa_get('wa_versao_api') ?: 'v20.0';
    $phoneId = wa_get('wa_phone_number_id');

    if ($phoneId === '') {
        return ['ok' => false, 'erro' => 'Phone Number ID não configurado.'];
    }

    $url = sprintf('https://graph.facebook.com/%s/%s/messages', $versao, $phoneId);

    $corpo = json_encode([
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $telefone,
        'type'              => 'text',
        'text'              => ['preview_url' => false, 'body' => $texto],
    ], JSON_UNESCAPED_UNICODE);

    return wa_http($url, $corpo, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . wa_get('wa_token'),
    ]);
}

/** @return array{ok:bool,erro:string,id?:string} */
function wa_http(string $url, string $corpo, array $cabecalhos): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'Extensão cURL do PHP indisponível.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $resposta = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'erro' => 'Falha de conexão: ' . $erroCurl];
    }

    $json = json_decode((string) $resposta, true);

    if ($codigo >= 200 && $codigo < 300) {
        $id = $json['messages'][0]['id'] ?? ($json['id'] ?? null);

        return ['ok' => true, 'erro' => '', 'id' => $id ? (string) $id : ''];
    }

    $detalhe = $json['error']['message']
        ?? $json['message']
        ?? mb_substr((string) $resposta, 0, 200);

    $codigoMeta = (int) ($json['error']['code'] ?? 0);
    $dica = wa_explicar_erro($codigoMeta, (string) $detalhe);

    return ['ok' => false, 'erro' => "HTTP {$codigo}: {$detalhe}" . ($dica !== '' ? ' — ' . $dica : '')];
}

/**
 * Traduz os erros mais comuns da API em orientação prática.
 *
 * Sem isto a tela mostra só "HTTP 400: API access blocked", que não diz a
 * quem opera o festival o que fazer. Quase nenhum desses erros se resolve no
 * código: são estado da conta, da permissão ou da janela de conversa.
 */
function wa_explicar_erro(int $codigo, string $mensagem): string
{
    $m = mb_strtolower($mensagem);

    if ($codigo === 200 || str_contains($m, 'access blocked')) {
        return 'a Meta bloqueou o acesso deste aplicativo. Verifique no Meta Business Manager '
             . 'se o app está ativo, o WhatsApp Business Account verificado e sem restrição de política. '
             . 'Não é falha do sistema.';
    }

    if ($codigo === 190 || str_contains($m, 'expired') || str_contains($m, 'session has been invalidated')) {
        return 'o token expirou ou foi revogado. Gere um novo token permanente no Meta Business Manager.';
    }

    if ($codigo === 131030 || str_contains($m, 'not in allowed list')) {
        return 'o número de destino não está na lista de teste. Em modo de desenvolvimento o WhatsApp '
             . 'só entrega para números cadastrados no painel da Meta.';
    }

    if ($codigo === 131047 || str_contains($m, 're-engagement') || str_contains($m, '24 hour')) {
        return 'passou da janela de 24 horas. Fora dela o WhatsApp só aceita MODELO DE MENSAGEM aprovado, '
             . 'não texto livre. É o caso de quem nunca conversou com este número antes.';
    }

    if ($codigo === 133010 || str_contains($m, 'not registered')) {
        return 'o número de saída não está registrado na Cloud API. Conclua o registro no painel da Meta.';
    }

    if ($codigo === 100) {
        return 'parâmetro inválido. Confira o Phone Number ID (não é o número de telefone) e a versão da API.';
    }

    if ($codigo === 131026 || str_contains($m, 'undeliverable')) {
        return 'o destinatário não tem WhatsApp ativo, ou o número está incorreto.';
    }

    return '';
}

/**
 * Verifica a integração SEM enviar mensagem.
 *
 * Um "teste de envio" que falha não distingue token inválido de número
 * errado, de conta bloqueada, de janela de 24h. Aqui cada etapa é
 * verificada em separado, e o resultado diz onde está o problema.
 *
 * @return array<int,array{etapa:string,ok:bool,detalhe:string}>
 */
function wa_diagnostico(): array
{
    $r = [];
    $token = wa_get('wa_token');
    $phoneId = wa_get('wa_phone_number_id');
    $versao = wa_get('wa_versao_api') ?: 'v20.0';
    $endpoint = wa_get('wa_endpoint');

    $r[] = [
        'etapa'   => 'Token de acesso',
        'ok'      => $token !== '',
        'detalhe' => $token !== ''
            ? (wa_token_do_ambiente() ? 'Definido no servidor (mais seguro).' : 'Gravado no banco.')
            : 'Não configurado.',
    ];

    $r[] = [
        'etapa'   => 'Phone Number ID',
        'ok'      => $phoneId !== '',
        'detalhe' => $phoneId !== '' ? $phoneId : 'Não configurado.',
    ];

    if ($endpoint !== '') {
        $r[] = [
            'etapa'   => 'Endpoint próprio',
            'ok'      => true,
            'detalhe' => $endpoint . ' — as verificações abaixo não se aplicam.',
        ];

        return $r;
    }

    if ($token === '') {
        return $r;
    }

    // Etapa 1: o token é aceito pela Meta?
    $c = wa_http_get("https://graph.facebook.com/{$versao}/me?access_token=" . urlencode($token));
    $erroMeta = $c['json']['error']['message'] ?? '';
    $codMeta = (int) ($c['json']['error']['code'] ?? 0);

    $r[] = [
        'etapa'   => 'Token aceito pela Meta',
        'ok'      => $c['codigo'] >= 200 && $c['codigo'] < 300,
        'detalhe' => $c['codigo'] >= 200 && $c['codigo'] < 300
            ? 'Sim.'
            : $erroMeta . ' — ' . wa_explicar_erro($codMeta, $erroMeta),
    ];

    // Etapa 2: o token enxerga este número?
    if ($phoneId !== '') {
        $c2 = wa_http_get(
            "https://graph.facebook.com/{$versao}/{$phoneId}"
            . '?fields=display_phone_number,verified_name,quality_rating&access_token=' . urlencode($token)
        );
        $ok2 = $c2['codigo'] >= 200 && $c2['codigo'] < 300;
        $erro2 = $c2['json']['error']['message'] ?? '';

        $r[] = [
            'etapa'   => 'Número de saída acessível',
            'ok'      => $ok2,
            'detalhe' => $ok2
                ? trim(($c2['json']['display_phone_number'] ?? '') . ' ' . ($c2['json']['verified_name'] ?? ''))
                : $erro2 . ' — ' . wa_explicar_erro((int) ($c2['json']['error']['code'] ?? 0), $erro2),
        ];
    }

    return $r;
}

/** @return array{codigo:int,json:array} */
function wa_http_get(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['codigo' => 0, 'json' => ['error' => ['message' => 'cURL indisponível.']]];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'codigo' => $codigo,
        'json'   => is_string($resp) ? (json_decode($resp, true) ?: []) : [],
    ];
}

/* ===========================================================================
 * MENSAGENS PRONTAS
 * ======================================================================== */

/**
 * Envia as credenciais AGORA e grava o histórico com a senha mascarada.
 *
 * O caminho normal é enfileirar e depois enviar — mas isso guardaria o corpo
 * da mensagem no banco, e o corpo contém a senha em texto. Guardar a senha
 * assim anularia todo o cuidado de só armazenar o hash: quem lesse a tabela
 * `mensagens` teria a senha de todos os jurados.
 *
 * Aqui a mensagem real existe apenas em memória, o tempo do disparo. O que
 * fica registrado é a mesma mensagem com a linha da senha substituída.
 *
 * Por isso mensagens deste tipo não podem ser "reenviadas": o conteúdo
 * original não existe mais em lugar nenhum. Reenviar exige gerar nova senha.
 *
 * @return array{ok:bool,erro:string,id:int}
 */
function wa_enviar_credenciais(
    string $nome,
    string $telefone,
    string $textoReal,
    ?int $eventoId = null,
    ?int $juradoId = null
): array {
    $mascarado = preg_replace('/^(Senha:\s*).+$/mu', '$1••••••••', $textoReal) ?? $textoReal;

    $id = wa_enfileirar('credenciais', $nome, $telefone, $mascarado, $eventoId, $juradoId);
    if ($id === 0) {
        return ['ok' => false, 'erro' => 'Não foi possível registrar o envio.', 'id' => 0];
    }

    if (!wa_ativo()) {
        wa_marcar($id, 'erro', 'Integração do WhatsApp desativada ou incompleta.');

        return ['ok' => false, 'erro' => 'Integração desativada.', 'id' => $id];
    }

    $tel = wa_telefone($telefone);
    if ($tel === '') {
        wa_marcar($id, 'erro', 'Telefone inválido.');

        return ['ok' => false, 'erro' => 'Telefone inválido.', 'id' => $id];
    }

    // Dispara com o texto REAL, que não é gravado em lugar nenhum.
    $r = wa_disparar($tel, $textoReal);

    if ($r['ok']) {
        wa_marcar($id, 'enviado', null, $r['id'] ?? null);

        return ['ok' => true, 'erro' => '', 'id' => $id];
    }

    wa_marcar($id, 'erro', $r['erro']);

    return ['ok' => false, 'erro' => $r['erro'], 'id' => $id];
}

function wa_texto_credenciais(string $nome, string $usuario, string $senha, string $evento, string $url): string
{
    return implode("\n", [
        "Olá, {$nome}!",
        '',
        "Seu acesso ao sistema de notas do *{$evento}*:",
        '',
        "Usuário: {$usuario}",
        "Senha: {$senha}",
        '',
        "Acesse: {$url}",
        '',
        'Guarde esta mensagem. A senha não pode ser recuperada depois — se precisar, a organização gera uma nova.',
    ]);
}

function wa_texto_voto(string $jurado, string $participante, string $evento, int $feitos, int $total): string
{
    $restam = max(0, $total - $feitos);

    $linhas = [
        "Notas registradas ✅",
        '',
        "Jurado: {$jurado}",
        "Participante: {$participante}",
        "Evento: {$evento}",
        '',
        "Avaliados: {$feitos} de {$total}",
    ];

    $linhas[] = $restam > 0
        ? "Faltam {$restam} participante(s)."
        : 'Você concluiu todas as avaliações. Obrigado!';

    return implode("\n", $linhas);
}

/* ===========================================================================
 * CONSULTAS PARA A TELA
 * ======================================================================== */

/** @return array<int,array<string,mixed>> */
function wa_mensagens(int $limite = 100, ?int $eventoId = null, string $status = ''): array
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT * FROM mensagens';
    $onde = [];
    $p = [];

    /* Inclui as mensagens SEM evento vinculado.
     *
     * As mensagens de teste da tela de configuração não pertencem a evento
     * nenhum. Filtrando só por event_id elas sumiam da lista — e era
     * justamente quem estava testando a integração que precisava vê-las. */
    if ($eventoId) {
        $onde[] = '(event_id = :ev OR event_id IS NULL)';
        $p[':ev'] = $eventoId;
    }
    if ($status !== '') {
        $onde[] = 'status = :st';
        $p[':st'] = $status;
    }
    if ($onde) {
        $sql .= ' WHERE ' . implode(' AND ', $onde);
    }

    $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limite));

    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);

        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('WhatsApp: falha ao listar mensagens: ' . $e->getMessage());

        return [];
    }
}

/** @return array<string,int> */
function wa_resumo(?int $eventoId = null): array
{
    $pdo = mysql_conexao();
    $saida = ['pendente' => 0, 'enviado' => 0, 'erro' => 0, 'total' => 0];

    if (!$pdo) {
        return $saida;
    }

    try {
        // Mesmo critério da listagem: mensagens sem evento sempre contam.
        $sql = 'SELECT status, COUNT(*) n FROM mensagens'
             . ($eventoId ? ' WHERE (event_id = :ev OR event_id IS NULL)' : '')
             . ' GROUP BY status';
        $st = $pdo->prepare($sql);
        $st->execute($eventoId ? [':ev' => $eventoId] : []);

        foreach ($st->fetchAll() as $l) {
            $saida[$l['status']] = (int) $l['n'];
            $saida['total'] += (int) $l['n'];
        }
    } catch (Throwable $e) {
        error_log('WhatsApp: falha no resumo: ' . $e->getMessage());
    }

    return $saida;
}
