<?php

/**
 * Regras de avaliação de cada evento.
 *
 * ---------------------------------------------------------------------------
 * O QUE ESTE ARQUIVO RESOLVE
 * ---------------------------------------------------------------------------
 * O sistema nasceu com uma regra só, embutida no código: nota de 0 a 10, uma
 * observação por participante, sem penalidades. O Concurso de Quadrilhas do
 * Festival Folclórico tem outras — nota de 9,0 a 10,0, justificativa
 * obrigatória abaixo de 10, nota esquecida valendo 10, e penalidades de 0,5 e
 * 1,0 aplicadas pela organização.
 *
 * Aqui as regras são DADO, não código. Um evento sem regra cadastrada continua
 * exatamente como antes; o próximo concurso com regras diferentes entra sem
 * tocar em nada.
 *
 * ---------------------------------------------------------------------------
 * ONDE CADA REGRA É APLICADA
 * ---------------------------------------------------------------------------
 * faixa de nota ......... na tela do jurado (min/max/step) E no servidor, ao
 *                         gravar. Só na tela não basta: o campo pode ser
 *                         contornado, e o que vale é o que entra no banco.
 * justificativa ......... exigida ao gravar, quando a nota fica abaixo do
 *                         limite. É recusa, não aviso.
 * nota ao finalizar ..... aplicada no "Finalizar Avaliações", que é o momento
 *                         em que o regulamento considera a ficha entregue.
 * penalidades ........... descontadas da nota final, na apuração.
 */

declare(strict_types=1);

/** Regra padrão de quem não tem regra própria: o comportamento de sempre. */
const REGRAS_PADRAO = [
    'nota_minima'             => 0.0,
    'nota_maxima'             => 10.0,
    'passo'                   => 0.1,
    'justificativa_abaixo_de' => null,
    'nota_ao_finalizar'       => null,
    'observacao'              => '',
    'propria'                 => false,
];

/**
 * As regras de um evento, com os padrões preenchidos.
 *
 * @return array{nota_minima:float, nota_maxima:float, passo:float,
 *               justificativa_abaixo_de:?float, nota_ao_finalizar:?float,
 *               observacao:string, propria:bool}
 */
function regras_do_evento(?int $eventId): array
{
    if ($eventId === null) {
        return REGRAS_PADRAO;
    }

    /* Consultadas a cada critério da ficha e a cada nota gravada — dezenas de
       vezes na mesma tela, sempre com a mesma resposta. */
    return cache_lembrar('regras:' . $eventId, static function () use ($eventId): array {
        $pdo = mysql_conexao();

        if (!$pdo) {
            return REGRAS_PADRAO;
        }

        try {
            $sql = $pdo->prepare('SELECT * FROM evento_regras WHERE event_id = ?');
            $sql->execute([$eventId]);
            $r = $sql->fetch();
        } catch (Throwable $e) {
            error_log('regras_do_evento: ' . $e->getMessage());

            return REGRAS_PADRAO;
        }

        if (!$r) {
            return REGRAS_PADRAO;
        }

        return [
            'nota_minima'             => (float)$r['nota_minima'],
            'nota_maxima'             => (float)$r['nota_maxima'],
            'passo'                   => (float)$r['passo'],
            'justificativa_abaixo_de' => $r['justificativa_abaixo_de'] === null ? null : (float)$r['justificativa_abaixo_de'],
            'nota_ao_finalizar'       => $r['nota_ao_finalizar'] === null ? null : (float)$r['nota_ao_finalizar'],
            'observacao'              => (string)($r['observacao'] ?? ''),
            'propria'                 => true,
        ];
    });
}

/** A nota está dentro da faixa permitida pelo evento? */
function nota_valida(float $nota, array $regras): bool
{
    /* Uma folga de meio passo evita recusar 9,7 quando o passo é 0,1 e a
       aritmética de ponto flutuante devolve 9,699999999. */
    $folga = max($regras['passo'], 0.01) / 2;

    return $nota >= $regras['nota_minima'] - $folga
        && $nota <= $regras['nota_maxima'] + $folga;
}

/** Esta nota exige justificativa? */
function exige_justificativa(float $nota, array $regras): bool
{
    $limite = $regras['justificativa_abaixo_de'];

    return $limite !== null && $nota < $limite - 0.001;
}

/** Frase curta com a faixa, para instruir na tela. */
function regras_texto_faixa(array $regras): string
{
    return 'de ' . numero_pt($regras['nota_minima']) . ' a ' . numero_pt($regras['nota_maxima']);
}

/* ===========================================================================
 * FAIXA POR CRITÉRIO
 * ======================================================================== */

/** Teto aceito para a nota máxima de um critério. Cabe em DECIMAL(6,2) e no
    teclado de nota da tela do jurado, que aceita até quatro dígitos. */
const CRITERIO_NOTA_TETO = 1000.0;

/**
 * O critério tem faixa própria? Basta a nota máxima: mínimo e passo têm
 * padrão (0 e 1 ou 0,1) quando não informados.
 */
function criterio_tem_faixa_propria(array $criterio): bool
{
    return isset($criterio['nota_maxima'])
        && $criterio['nota_maxima'] !== null
        && $criterio['nota_maxima'] !== '';
}

/**
 * As regras valendo para UM critério: as do evento, com a faixa que o
 * critério sobrescreve.
 *
 * Critério sem faixa própria devolve exatamente as regras do evento — nenhum
 * evento antigo muda de comportamento.
 *
 * Quando o critério tem faixa própria, os dois limiares do regulamento
 * (justificativa abaixo de X, nota ao finalizar) são levados para a escala
 * do critério na mesma proporção. "Nota esquecida vale 10" num evento de 9 a
 * 10 quer dizer "vale a nota máxima"; num critério de 0 a 100, isso é 100,
 * não 10.
 */
function regras_do_criterio(array $criterio, ?array $regrasEvento = null): array
{
    $regrasEvento ??= regras_do_evento(isset($criterio['event_id']) ? (int)$criterio['event_id'] : null);

    if (!criterio_tem_faixa_propria($criterio)) {
        return $regrasEvento + ['faixa_do_criterio' => false];
    }

    $max = (float)$criterio['nota_maxima'];
    $min = isset($criterio['nota_minima']) && $criterio['nota_minima'] !== null && $criterio['nota_minima'] !== ''
        ? (float)$criterio['nota_minima']
        : 0.0;
    $passo = isset($criterio['passo']) && $criterio['passo'] !== null && $criterio['passo'] !== '' && (float)$criterio['passo'] > 0
        ? (float)$criterio['passo']
        : criterio_passo_padrao($min, $max);

    $regras = $regrasEvento;
    $regras['nota_minima'] = $min;
    $regras['nota_maxima'] = $max;
    $regras['passo'] = $passo;
    $regras['faixa_do_criterio'] = true;

    $deMin = (float)$regrasEvento['nota_minima'];
    $deMax = (float)$regrasEvento['nota_maxima'];

    foreach (['justificativa_abaixo_de', 'nota_ao_finalizar'] as $chave) {
        if ($regrasEvento[$chave] === null || $deMax <= $deMin) {
            continue;
        }

        $proporcao = ((float)$regrasEvento[$chave] - $deMin) / ($deMax - $deMin);
        $valor = $min + $proporcao * ($max - $min);
        $regras[$chave] = round(round($valor / $passo) * $passo, 2);
    }

    return $regras;
}

/** Faixa larga anda de 1 em 1; faixa curta, de décimo em décimo. */
function criterio_passo_padrao(float $min, float $max): float
{
    return ($max - $min) > 20 ? 1.0 : 0.1;
}

/**
 * Confere a faixa digitada na tela de Critérios.
 *
 * @return string|null  mensagem de erro, ou null se estiver tudo certo
 */
function criterio_faixa_erro(float $min, float $max, float $passo): ?string
{
    if ($min < 0) {
        return 'A nota mínima não pode ser negativa.';
    }

    if ($max <= $min) {
        return 'A nota máxima precisa ser maior que a mínima.';
    }

    if ($max > CRITERIO_NOTA_TETO) {
        return 'A nota máxima vai até ' . numero_pt(CRITERIO_NOTA_TETO) . '.';
    }

    if ($passo <= 0 || $passo > ($max - $min)) {
        return 'O intervalo entre notas precisa ser maior que zero e caber na faixa.';
    }

    /* Passo que não divide a faixa deixaria a nota máxima inalcançável:
       de 0 a 10 andando de 0,3 em 0,3 nunca chega a 10. */
    $passos = ($max - $min) / $passo;
    if (abs($passos - round($passos)) > 0.001) {
        return 'O intervalo entre notas precisa dividir a faixa por igual (ex.: de 0 a 100 em passos de 1 ou 0,5).';
    }

    return null;
}

/**
 * A escala que TODOS os critérios do evento compartilham, ou null se eles
 * usam escalas diferentes. Serve para os textos de instrução: "notas de 0 a
 * 10" só pode ser dito quando é verdade para a ficha inteira.
 */
function escala_comum_dos_criterios(array $criterios, array $regrasEvento): ?array
{
    $comum = null;

    foreach ($criterios as $c) {
        $r = regras_do_criterio($c, $regrasEvento);

        if ($comum === null) {
            $comum = $r;
        } elseif ([$comum['nota_minima'], $comum['nota_maxima']] !== [$r['nota_minima'], $r['nota_maxima']]) {
            return null;
        }
    }

    return $comum ?? $regrasEvento;
}

/** "0 a 100", "9 a 10", "0,0 a 10,0" — a faixa como o jurado lê. */
function regras_faixa_curta(array $regras): string
{
    $casas = $regras['passo'] < 1 ? 1 : 0;

    return number_format((float)$regras['nota_minima'], $casas, ',', '.')
        . ' a ' . number_format((float)$regras['nota_maxima'], $casas, ',', '.');
}

function numero_pt(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}

/* ===========================================================================
 * PENALIDADES
 * ======================================================================== */

/**
 * Catálogo de penalidades previstas para o evento.
 *
 * Consultado uma vez para decidir se "Penalidades" entra no menu e de novo
 * para desenhar a tela. Guardar a resposta evita a segunda ida ao banco.
 */
function penalidades_do_evento(int $eventId): array
{
    return cache_lembrar('penalidades:catalogo:' . $eventId, static function () use ($eventId): array {
        $pdo = mysql_conexao();

        if (!$pdo) {
            return [];
        }

        try {
            $sql = $pdo->prepare(
                'SELECT id, nome, descricao, valor FROM evento_penalidades
                  WHERE event_id = ? ORDER BY ordem, id'
            );
            $sql->execute([$eventId]);

            return $sql->fetchAll();
        } catch (Throwable $e) {
            error_log('penalidades_do_evento: ' . $e->getMessage());

            return [];
        }
    });
}

/**
 * Penalidades já aplicadas no evento, agrupadas por participante.
 *
 * @return array<int,array{total:float, itens:array}>
 */
function penalidades_aplicadas(int $eventId): array
{
    return cache_lembrar('penalidades:aplicadas:' . $eventId, static fn(): array => penalidades_aplicadas_consultar($eventId));
}

function penalidades_aplicadas_consultar(int $eventId): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return [];
    }

    try {
        $sql = $pdo->prepare(
            'SELECT a.id, a.participant_id, a.valor, a.motivo, a.aplicada_por, a.aplicada_em,
                    p.nome
               FROM participante_penalidades a
               JOIN evento_penalidades p ON p.id = a.penalidade_id
              WHERE a.event_id = ?
              ORDER BY a.aplicada_em'
        );
        $sql->execute([$eventId]);
        $linhas = $sql->fetchAll();
    } catch (Throwable $e) {
        error_log('penalidades_aplicadas: ' . $e->getMessage());

        return [];
    }

    $saida = [];

    foreach ($linhas as $l) {
        $pid = (int)$l['participant_id'];
        $saida[$pid]['total'] = ($saida[$pid]['total'] ?? 0.0) + (float)$l['valor'];
        $saida[$pid]['itens'][] = $l;
    }

    return $saida;
}

function penalidade_aplicar(int $eventId, int $participantId, int $penalidadeId, string $motivo, string $autor): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['ok' => false, 'mensagem' => 'Banco de dados indisponível.'];
    }

    try {
        /* O valor é copiado do catálogo no momento da aplicação. Se alguém
           corrigir o catálogo depois, o desconto já aplicado não muda
           sozinho — o resultado de uma competição não pode mudar por causa
           de uma edição posterior. */
        $sql = $pdo->prepare('SELECT valor FROM evento_penalidades WHERE id = ? AND event_id = ?');
        $sql->execute([$penalidadeId, $eventId]);
        $valor = $sql->fetchColumn();

        if ($valor === false) {
            return ['ok' => false, 'mensagem' => 'Penalidade não prevista para este evento.'];
        }

        $sql = $pdo->prepare(
            'INSERT INTO participante_penalidades
                 (event_id, participant_id, penalidade_id, valor, motivo, aplicada_por)
             VALUES (:evento, :participante, :penalidade, :valor, :motivo, :autor)'
        );
        $sql->execute([
            ':evento'       => $eventId,
            ':participante' => $participantId,
            ':penalidade'   => $penalidadeId,
            ':valor'        => $valor,
            ':motivo'       => mb_substr($motivo, 0, 400),
            ':autor'        => mb_substr($autor, 0, 120),
        ]);

        cache_esquecer('penalidades');

        return ['ok' => true, 'mensagem' => 'Penalidade aplicada.'];
    } catch (Throwable $e) {
        error_log('penalidade_aplicar: ' . $e->getMessage());

        return ['ok' => false, 'mensagem' => 'Falha ao aplicar a penalidade.'];
    }
}

function penalidade_remover(int $id, int $eventId): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['ok' => false, 'mensagem' => 'Banco de dados indisponível.'];
    }

    try {
        $sql = $pdo->prepare('DELETE FROM participante_penalidades WHERE id = ? AND event_id = ?');
        $sql->execute([$id, $eventId]);

        cache_esquecer('penalidades');

        return ['ok' => true, 'mensagem' => 'Penalidade removida.'];
    } catch (Throwable $e) {
        error_log('penalidade_remover: ' . $e->getMessage());

        return ['ok' => false, 'mensagem' => 'Falha ao remover a penalidade.'];
    }
}
