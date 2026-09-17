<?php

/**
 * Resultado final por WhatsApp, quando os jurados terminam.
 *
 * ---------------------------------------------------------------------------
 * QUANDO É QUE "OS JURADOS TERMINARAM"
 * ---------------------------------------------------------------------------
 * Quando TODOS os jurados ativos do evento clicaram em "Finalizar Avaliações".
 * Não é "todo mundo lançou todas as notas": um jurado pode ter lançado tudo e
 * ainda estar revendo, e pode finalizar com nota em branco (que o regulamento
 * de alguns eventos preenche automaticamente nesse momento). Finalizar é a
 * declaração dele de que a ficha está entregue — é isso que o disparo espera.
 *
 * Antes, essa declaração vivia só na sessão do navegador: o jurado saía do
 * sistema e voltava a aparecer como se não tivesse terminado. Agora fica no
 * banco (ver mysql_10_resultado_final.sql).
 *
 * ---------------------------------------------------------------------------
 * POR QUE SÓ UMA VEZ
 * ---------------------------------------------------------------------------
 * A mensagem sai uma vez por evento, e o registro disso é uma linha em
 * evento_resultado_enviado. Sem esse registro, cada nova finalização — ou um
 * F5 na hora errada, ou um jurado que reabre e finaliza de novo — mandaria o
 * resultado outra vez para os mesmos telefones. Reenviar é decisão da
 * organização, pelo botão do painel, nunca do acaso.
 *
 * ---------------------------------------------------------------------------
 * O QUE VAI NA MENSAGEM
 * ---------------------------------------------------------------------------
 * A classificação como ela está na apuração: a mesma média, já com as
 * penalidades descontadas, e na mesma ordem. Se a mensagem e a tela pudessem
 * discordar, a mensagem não serviria para nada.
 */

declare(strict_types=1);

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/whatsapp.php';

/* ===========================================================================
 * QUEM JÁ TERMINOU
 * ======================================================================== */

/** Registra que este jurado finalizou. Repetir não muda a hora do primeiro. */
function resultado_marcar_finalizado(int $eventId, int $judgeId): void
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return;
    }

    try {
        $sql = $pdo->prepare(
            'INSERT INTO jurado_finalizou (event_id, judge_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE event_id = event_id'
        );
        $sql->execute([$eventId, $judgeId]);
    } catch (Throwable $e) {
        error_log('resultado_marcar_finalizado: ' . $e->getMessage());
    }
}

/**
 * Reabre a ficha de um jurado, desfazendo a finalização.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ISTO EXISTE
 * ---------------------------------------------------------------------------
 * "Finalizar Avaliações" congela a ficha, e isso está certo: é o momento em
 * que o regulamento considera a ficha entregue. O que estava errado era não
 * haver volta. Um toque a mais no botão — num tablet, com o dedo — trancava o
 * jurado no meio do trabalho, sem nenhum caminho dentro do sistema para
 * corrigir. Foi o que aconteceu em 30/08.
 *
 * Reabrir é uma ação legítima da organização de um festival. O que não pode é
 * acontecer em silêncio: cada reabertura fica registrada no log, com quem
 * pediu, porque mexe na ficha que sustenta o resultado.
 */
function resultado_desmarcar_finalizado(int $eventId, int $judgeId, string $quem = ''): bool
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return false;
    }

    try {
        $sql = $pdo->prepare('DELETE FROM jurado_finalizou WHERE event_id = ? AND judge_id = ?');
        $sql->execute([$eventId, $judgeId]);

        error_log(sprintf(
            'Ficha reaberta: evento %d, jurado %d, por %s',
            $eventId,
            $judgeId,
            $quem !== '' ? $quem : 'o proprio jurado'
        ));

        cache_esquecer('resultado');

        return true;
    } catch (Throwable $e) {
        error_log('resultado_desmarcar_finalizado: ' . $e->getMessage());

        return false;
    }
}

/** Este jurado já finalizou neste evento? */
function resultado_jurado_finalizou(int $eventId, int $judgeId): bool
{
    return isset(resultado_finalizados($eventId)[$judgeId]);
}

/** @return array<int,string> judge_id => data da finalização */
function resultado_finalizados(int $eventId): array
{
    return cache_lembrar('resultado:finalizados:' . $eventId, static function () use ($eventId): array {
        $pdo = mysql_conexao();

        if (!$pdo) {
            return [];
        }

        try {
            $sql = $pdo->prepare('SELECT judge_id, finalizado_em FROM jurado_finalizou WHERE event_id = ?');
            $sql->execute([$eventId]);
            $saida = [];

            foreach ($sql->fetchAll() as $l) {
                $saida[(int)$l['judge_id']] = (string)$l['finalizado_em'];
            }

            return $saida;
        } catch (Throwable $e) {
            error_log('resultado_finalizados: ' . $e->getMessage());

            return [];
        }
    });
}

/**
 * Andamento da votação do evento.
 *
 * @return array{total:int, finalizados:int, faltam:array<int,string>, completo:bool}
 */
function resultado_andamento(array $db, int $eventId): array
{
    $jurados = array_values(array_filter(
        items_for_event($db['judges'] ?? [], $eventId),
        static fn(array $j): bool => ($j['status'] ?? 'ativo') === 'ativo'
    ));

    $prontos = resultado_finalizados($eventId);
    $faltam = [];

    foreach ($jurados as $j) {
        if (!isset($prontos[(int)$j['id']])) {
            $faltam[(int)$j['id']] = (string)$j['name'];
        }
    }

    return [
        'total'       => count($jurados),
        'finalizados' => count($jurados) - count($faltam),
        'faltam'      => $faltam,
        /* Evento sem jurado nenhum não está "completo" — está vazio. Sem esta
           checagem, um evento recém-criado dispararia o resultado sozinho. */
        'completo'    => count($jurados) > 0 && $faltam === [],
    ];
}

/* ===========================================================================
 * O TEXTO
 * ======================================================================== */

/**
 * A mensagem do resultado final.
 *
 * Texto puro: o WhatsApp aceita *negrito* e quebra de linha, e nada mais.
 */
function resultado_texto(array $db, int $eventId): string
{
    $evento = find_by_id($db['events'] ?? [], $eventId);
    $ranking = ranking_for_event($db, $eventId);
    $andamento = resultado_andamento($db, $eventId);
    $regras = function_exists('regras_do_evento') ? regras_do_evento($eventId) : null;

    $linhas = [
        'Jurados terminaram a votação e o resultado abaixo',
        '',
        '*' . mb_strtoupper((string)($evento['name'] ?? 'Evento')) . '*',
    ];

    if (!empty($evento['date'])) {
        $linhas[] = resultado_data((string)$evento['date']);
    }

    $linhas[] = '';
    $linhas[] = '*CLASSIFICAÇÃO*';

    if ($ranking === []) {
        $linhas[] = 'Nenhum participante avaliado.';
    }

    $posicao = 0;
    $anterior = null;
    $iguais = 0;

    foreach ($ranking as $linha) {
        $nota = (float)$linha['score'];

        /* Empate divide a mesma posição: dois com 9,80 são os dois em 1º, e o
           seguinte é 3º. Numerar em sequência inventaria uma diferença que a
           apuração não encontrou. */
        if ($anterior !== null && abs($nota - $anterior) < 0.005) {
            $iguais++;
        } else {
            $posicao += 1 + $iguais;
            $iguais = 0;
        }

        $anterior = $nota;

        $texto = $posicao . 'º  ' . (string)$linha['participant']['name']
            . ' — *' . resultado_numero($nota) . '*';

        /* Penalidade aparece: um resultado menor do que as notas dos jurados
           explicam vira pergunta na hora errada. */
        if ((float)($linha['penalidade'] ?? 0) > 0) {
            $texto .= ' (' . resultado_numero((float)$linha['score_bruto'])
                . ' − ' . resultado_numero((float)$linha['penalidade']) . ' de penalidade)';
        }

        $linhas[] = $texto;
    }

    $linhas[] = '';
    $linhas[] = $andamento['total'] === 1
        ? '1 jurado, todos os critérios lançados.'
        : $andamento['total'] . ' jurados, todos finalizaram.';

    if ($regras !== null && !empty($regras['propria'])) {
        $linhas[] = 'Escala do regulamento: de ' . resultado_numero((float)$regras['nota_minima'])
            . ' a ' . resultado_numero((float)$regras['nota_maxima']) . '.';
    }

    $linhas[] = '';
    $linhas[] = 'Sistema de Notas de Jurados — Sesc Amazonas';

    return implode("\n", $linhas);
}

function resultado_numero(float $n): string
{
    return number_format($n, 2, ',', '.');
}

function resultado_data(string $iso): string
{
    $t = strtotime($iso);

    return $t ? date('d/m/Y', $t) : $iso;
}

/* ===========================================================================
 * ENVIO
 * ======================================================================== */

/**
 * Para quem vai o resultado.
 *
 * @return array<int,string>
 */
function resultado_destinatarios(): array
{
    $bruto = wa_get('wa_resultado_para');

    if (trim($bruto) === '') {
        return [];
    }

    $saida = [];

    foreach (preg_split('/[;,\n]+/', $bruto) ?: [] as $pedaco) {
        $tel = wa_telefone(trim($pedaco));

        if ($tel !== '' && !in_array($tel, $saida, true)) {
            $saida[] = $tel;
        }
    }

    return $saida;
}

function resultado_ja_enviado(int $eventId): ?array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return null;
    }

    try {
        $sql = $pdo->prepare('SELECT * FROM evento_resultado_enviado WHERE event_id = ?');
        $sql->execute([$eventId]);
        $linha = $sql->fetch();

        return $linha ?: null;
    } catch (Throwable $e) {
        error_log('resultado_ja_enviado: ' . $e->getMessage());

        return null;
    }
}

/**
 * Envia o resultado final do evento.
 *
 * @param  bool $forcar  Reenvio pedido pela organização, mesmo já tendo saído.
 * @return array{ok:bool, mensagem:string, enviados:int, falhas:int}
 */
function resultado_enviar(array $db, int $eventId, string $origem = 'automatico', bool $forcar = false): array
{
    if (!$forcar && resultado_ja_enviado($eventId) !== null) {
        return ['ok' => false, 'mensagem' => 'O resultado deste evento já foi enviado.', 'enviados' => 0, 'falhas' => 0];
    }

    $destinos = resultado_destinatarios();

    if ($destinos === []) {
        return [
            'ok' => false,
            'enviados' => 0,
            'falhas' => 0,
            'mensagem' => 'Nenhum número cadastrado para receber o resultado. '
                . 'Configure em Configurações › WhatsApp.',
        ];
    }

    $evento = find_by_id($db['events'] ?? [], $eventId);
    $texto = resultado_texto($db, $eventId);
    $enviados = 0;
    $falhas = 0;
    $ultimoErro = '';

    foreach ($destinos as $telefone) {
        /* Enfileira ANTES de tentar enviar, sempre. Se a API estiver fora, a
           mensagem fica registrada com o erro e pode ser reenviada pela tela
           de mensagens — em vez de sumir sem deixar rastro. */
        $id = wa_enfileirar(
            'resultado',
            'Resultado — ' . (string)($evento['name'] ?? 'Evento'),
            $telefone,
            $texto,
            $eventId
        );

        if ($id === 0) {
            $falhas++;
            continue;
        }

        $envio = wa_enviar($id);

        if (!empty($envio['ok'])) {
            $enviados++;
        } else {
            $falhas++;
            $ultimoErro = (string)($envio['erro'] ?? '');
        }
    }

    /* O registro marca que o resultado DESTE evento já foi disparado, tenha a
       API aceitado ou não. Se a Meta estiver bloqueando, o certo é reenviar
       pela tela de mensagens depois de resolver — não disparar de novo
       sozinho a cada finalização. */
    resultado_registrar_envio($eventId, $destinos, $origem);

    if ($enviados === 0) {
        return [
            'ok' => false,
            'enviados' => 0,
            'falhas' => $falhas,
            'mensagem' => 'O resultado foi montado e ficou registrado, mas o WhatsApp não aceitou o envio'
                . ($ultimoErro !== '' ? ': ' . $ultimoErro : '.'),
        ];
    }

    return [
        'ok' => true,
        'enviados' => $enviados,
        'falhas' => $falhas,
        'mensagem' => 'Resultado enviado para ' . $enviados . ' número(s)'
            . ($falhas > 0 ? ', com ' . $falhas . ' falha(s).' : '.'),
    ];
}

function resultado_registrar_envio(int $eventId, array $destinos, string $origem): void
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return;
    }

    try {
        $sql = $pdo->prepare(
            'INSERT INTO evento_resultado_enviado (event_id, destinatarios, origem)
             VALUES (:ev, :dest, :origem)
             ON DUPLICATE KEY UPDATE enviado_em = CURRENT_TIMESTAMP,
                                     destinatarios = VALUES(destinatarios),
                                     origem = VALUES(origem)'
        );
        $sql->execute([
            ':ev'     => $eventId,
            ':dest'   => mb_substr(implode(', ', $destinos), 0, 400),
            ':origem' => $origem === 'manual' ? 'manual' : 'automatico',
        ]);
    } catch (Throwable $e) {
        error_log('resultado_registrar_envio: ' . $e->getMessage());
    }
}

/**
 * Chamado logo depois de um jurado finalizar.
 *
 * Não faz nada até o último terminar. Silencioso de propósito: o jurado não
 * precisa saber que existe um WhatsApp indo para a coordenação.
 */
function resultado_talvez_enviar(array $db, int $eventId): void
{
    if (wa_get('wa_resultado_ativo', '1') !== '1') {
        return;
    }

    if (!resultado_andamento($db, $eventId)['completo']) {
        return;
    }

    if (resultado_ja_enviado($eventId) !== null) {
        return;
    }

    $r = resultado_enviar($db, $eventId, 'automatico');

    if (!$r['ok']) {
        error_log('Resultado final do evento ' . $eventId . ': ' . $r['mensagem']);
    }
}
