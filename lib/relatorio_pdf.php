<?php

/**
 * Relatórios do módulo "Criar relatório", em PDF de verdade.
 *
 * ---------------------------------------------------------------------------
 * POR QUE NÃO USAR A IMPRESSÃO DO NAVEGADOR
 * ---------------------------------------------------------------------------
 * O botão "Salvar / Imprimir PDF" abre a caixa de impressão, e ali a pessoa
 * ainda precisa escolher "Salvar como PDF", conferir margem, orientação e
 * lembrar de desligar cabeçalho e rodapé do navegador. O arquivo sai diferente
 * conforme quem clicou e em qual navegador.
 *
 * Aqui o servidor monta o PDF e manda baixar. Um clique, um arquivo, sempre
 * igual — e com o nome do evento no nome do arquivo, o que importa quando a
 * coordenação junta dez relatórios na mesma pasta.
 *
 * Usa o gerador de lib/pdf.php: sem imagens e sem fonte própria, mas com
 * tabelas, quebra de página e acentuação correta, que é o que um relatório de
 * notas precisa.
 */

declare(strict_types=1);

require_once __DIR__ . '/pdf.php';

/**
 * Cabeçalho comum: título, o evento a que se refere e quando foi emitido.
 *
 * A data de emissão não é enfeite — dois relatórios do mesmo evento, tirados
 * antes e depois de um jurado corrigir a nota, são documentos diferentes.
 */
function relatorio_pdf_cabecalho(array &$doc, string $titulo, string $subtitulo): void
{
    pdf_titulo($doc, $titulo, 16.0);
    pdf_paragrafo($doc, $subtitulo . ' · emitido em ' . date('d/m/Y H:i'), 9.0);
}

/** Número no formato do relatório; traço quando não há nota. */
function relatorio_pdf_numero(?float $valor, int $casas = 2): string
{
    return $valor === null ? '-' : number_format($valor, $casas, ',', '.');
}

/* ===========================================================================
 * RANKING POR QUESITO
 * ======================================================================== */

function relatorio_pdf_quesito(array $db, array $event, array $quesitos): string
{
    $doc = pdf_novo();
    $doc['rodape'] = (string)($event['name'] ?? '');
    pdf_nova_pagina($doc);

    relatorio_pdf_cabecalho($doc, 'Ranking por quesito', (string)($event['name'] ?? ''));

    $colunas = pdf_colunas($doc, [
        ['rotulo' => 'Pos.',         'peso' => 0.8, 'alinhar' => 'direita'],
        ['rotulo' => 'Participante', 'peso' => 4.0],
        ['rotulo' => 'Média',        'peso' => 1.2, 'alinhar' => 'direita'],
        ['rotulo' => 'Soma',         'peso' => 1.2, 'alinhar' => 'direita'],
        ['rotulo' => 'Jurados',      'peso' => 1.0, 'alinhar' => 'direita'],
    ]);

    foreach ($quesitos as $c) {
        $linhas = [];

        foreach (ranking_por_criterio($db, (int)$event['id'], (int)$c['id']) as $l) {
            $temNota = (int)$l['jurados'] > 0;

            $linhas[] = [
                'celulas' => [
                    (int)$l['posicao'] . 'º',
                    (string)$l['participant']['name'],
                    $temNota ? relatorio_pdf_numero((float)$l['media']) : '-',
                    $temNota ? relatorio_pdf_numero((float)$l['soma']) : '-',
                    (string)(int)$l['jurados'],
                ],
                // O primeiro lugar em destaque: é o que se procura na folha.
                'destaque' => (int)$l['posicao'] === 1 && $temNota,
                'negrito'  => (int)$l['posicao'] === 1 && $temNota,
            ];
        }

        pdf_titulo($doc, (string)$c['name'], 12.0);

        if ($linhas === []) {
            pdf_paragrafo($doc, 'Nenhum participante cadastrado neste evento.');
            continue;
        }

        pdf_tabela($doc, $colunas, $linhas);
    }

    pdf_paragrafo(
        $doc,
        'Classificacao pela media dos jurados; somar favoreceria quem foi avaliado por mais gente. '
        . 'A soma fica ao lado para conferencia com a folha de papel. Penalidades nao entram no '
        . 'quesito: descontam da nota geral do grupo.',
        8.0
    );

    return pdf_finalizar($doc);
}

/* ===========================================================================
 * FICHA INDIVIDUAL
 * ======================================================================== */

function relatorio_pdf_participante(array $db, array $event, array $alvos): string
{
    $eventId = (int)$event['id'];
    $doc = pdf_novo();
    $doc['rodape'] = (string)($event['name'] ?? '');
    pdf_nova_pagina($doc);

    $rankingEvento = ranking_for_event($db, $eventId);
    $primeiro = true;

    foreach ($alvos as $p) {
        /* Uma ficha por página: é documento de entrega, e chegar com metade da
           ficha da concorrente atrás não serve. */
        if (!$primeiro) {
            pdf_nova_pagina($doc);
        }
        $primeiro = false;

        relatorio_pdf_cabecalho($doc, 'Ficha individual', (string)($event['name'] ?? ''));

        $ficha = relatorio_participante($db, $eventId, (int)$p['id']);

        $posicao = 0;
        $linhaRanking = null;
        foreach ($rankingEvento as $i => $r) {
            if ((int)$r['participant']['id'] === (int)$p['id']) {
                $posicao = $i + 1;
                $linhaRanking = $r;
                break;
            }
        }

        pdf_titulo($doc, (string)$p['name'], 13.0);

        $resumo = 'Ordem de apresentacao ' . str_pad((string)(int)($p['order'] ?? 0), 2, '0', STR_PAD_LEFT);

        if ($linhaRanking) {
            $resumo .= '   ·   Nota final ' . relatorio_pdf_numero((float)$linhaRanking['score'])
                . '   ·   ' . $posicao . 'o lugar geral';

            if ((float)$linhaRanking['penalidade'] > 0) {
                $resumo .= '   ·   penalidade de ' . relatorio_pdf_numero((float)$linhaRanking['penalidade'])
                    . ' (bruta ' . relatorio_pdf_numero((float)$linhaRanking['score_bruto']) . ')';
            }
        }

        pdf_paragrafo($doc, $resumo, 9.0, [45, 60, 90]);

        /* Uma coluna por jurado. O cabeçalho leva só o primeiro nome — nome
           inteiro não cabe em coluna estreita e sairia cortado pela metade;
           a legenda logo abaixo diz quem é quem. */
        $definicao = [['rotulo' => 'Quesito', 'peso' => 2.6]];
        $legenda = [];

        foreach ($ficha['jurados'] as $j) {
            $partes = preg_split('/\s+/u', trim((string)$j['name'])) ?: [''];
            $definicao[] = ['rotulo' => $partes[0], 'peso' => 1.1, 'alinhar' => 'direita'];
            $legenda[] = $partes[0] . ' = ' . (string)$j['name'];
        }

        $definicao[] = ['rotulo' => 'Média', 'peso' => 1.0, 'alinhar' => 'direita'];
        $colunas = pdf_colunas($doc, $definicao);

        $linhas = [];
        foreach ($ficha['criterios'] as $c) {
            $doQuesito = $ficha['notas'][(int)$c['id']] ?? [];
            $celulas = [(string)$c['name']];

            foreach ($ficha['jurados'] as $j) {
                $voto = $doQuesito[(int)$j['id']] ?? null;
                $celulas[] = $voto ? relatorio_pdf_numero((float)$voto['score'], 1) : '-';
            }

            $media = $doQuesito
                ? array_sum(array_map(static fn($v): float => (float)$v['score'], $doQuesito)) / count($doQuesito)
                : null;

            $celulas[] = relatorio_pdf_numero($media);
            $linhas[] = ['celulas' => $celulas];
        }

        pdf_tabela($doc, $colunas, $linhas, 8.5);

        if ($legenda !== []) {
            pdf_paragrafo($doc, implode('   ·   ', $legenda), 7.5);
        }

        /* As justificativas saem como texto corrido, e não dentro da tabela.
           Na tabela elas seriam cortadas na largura da coluna — e justamente
           a justificativa é o que a quadrilha quer ler por inteiro. */
        $temJustificativa = false;
        foreach ($ficha['criterios'] as $c) {
            foreach ($ficha['jurados'] as $j) {
                $voto = $ficha['notas'][(int)$c['id']][(int)$j['id']] ?? null;

                if ($voto && trim((string)($voto['justificativa'] ?? '')) !== '') {
                    if (!$temJustificativa) {
                        pdf_titulo($doc, 'Justificativas das notas', 11.0);
                        $temJustificativa = true;
                    }

                    pdf_paragrafo(
                        $doc,
                        (string)$c['name'] . ' — ' . (string)$j['name'] . ' ('
                        . relatorio_pdf_numero((float)$voto['score'], 1) . '): '
                        . (string)$voto['justificativa'],
                        8.5,
                        [45, 60, 90]
                    );
                }
            }
        }

        $itens = $ficha['penalidades']['itens'] ?? [];
        if ($itens !== []) {
            pdf_titulo($doc, 'Penalidades', 11.0);

            foreach ($itens as $pen) {
                $texto = (string)$pen['nome'] . ' — desconto de ' . relatorio_pdf_numero((float)$pen['valor']);

                if (trim((string)($pen['motivo'] ?? '')) !== '') {
                    $texto .= ' — ' . (string)$pen['motivo'];
                }

                if (trim((string)($pen['aplicada_por'] ?? '')) !== '') {
                    $texto .= ' (aplicada por ' . (string)$pen['aplicada_por'] . ')';
                }

                pdf_paragrafo($doc, $texto, 8.5, [179, 38, 30]);
            }
        }

        $temObservacao = false;
        foreach ($ficha['jurados'] as $j) {
            $obs = trim((string)($ficha['observacoes'][(int)$j['id']] ?? ''));

            if ($obs !== '') {
                if (!$temObservacao) {
                    pdf_titulo($doc, 'Observações dos jurados', 11.0);
                    $temObservacao = true;
                }

                pdf_paragrafo($doc, (string)$j['name'] . ': ' . $obs, 8.5, [45, 60, 90]);
            }
        }
    }

    return pdf_finalizar($doc);
}

/* ===========================================================================
 * GERAL DO EVENTO
 * ======================================================================== */

function relatorio_pdf_evento(array $db, array $event): string
{
    $eventId = (int)$event['id'];
    $doc = pdf_novo();
    $doc['rodape'] = (string)($event['name'] ?? '');
    pdf_nova_pagina($doc);

    relatorio_pdf_cabecalho($doc, 'Relatório geral do evento', (string)($event['name'] ?? ''));

    $participantes = items_for_event($db['participants'] ?? [], $eventId);
    $jurados = items_for_event($db['judges'] ?? [], $eventId);
    $criterios = ordenar_criterios(items_for_event($db['criteria'] ?? [], $eventId));
    $andamento = resultado_andamento($db, $eventId);

    pdf_paragrafo($doc, sprintf(
        '%d participante(s)   ·   %d jurado(s)   ·   %d quesito(s)   ·   %d de %d ficha(s) entregue(s)',
        count($participantes),
        count($jurados),
        count($criterios),
        (int)$andamento['finalizados'],
        (int)$andamento['total']
    ), 9.0);

    if ($andamento['faltam'] !== []) {
        pdf_aviso($doc, 'Ainda falta finalizar: ' . implode(', ', $andamento['faltam']));
    }

    pdf_titulo($doc, 'Classificação geral', 12.0);

    $linhas = [];
    foreach (ranking_for_event($db, $eventId) as $i => $r) {
        $linhas[] = [
            'celulas' => [
                ($i + 1) . 'º',
                (string)$r['participant']['name'],
                relatorio_pdf_numero((float)$r['score']),
                (float)$r['penalidade'] > 0 ? '-' . relatorio_pdf_numero((float)$r['penalidade']) : '-',
                (string)(int)$r['judge_count'],
            ],
            'destaque' => $i === 0,
            'negrito'  => $i === 0,
        ];
    }

    pdf_tabela($doc, pdf_colunas($doc, [
        ['rotulo' => 'Pos.',         'peso' => 0.8, 'alinhar' => 'direita'],
        ['rotulo' => 'Participante', 'peso' => 4.0],
        ['rotulo' => 'Nota final',   'peso' => 1.3, 'alinhar' => 'direita'],
        ['rotulo' => 'Penalidade',   'peso' => 1.2, 'alinhar' => 'direita'],
        ['rotulo' => 'Jurados',      'peso' => 1.0, 'alinhar' => 'direita'],
    ]), $linhas);

    pdf_titulo($doc, 'Campeão de cada quesito', 12.0);

    $linhasQuesito = [];
    foreach ($criterios as $c) {
        $topo = ranking_por_criterio($db, $eventId, (int)$c['id'])[0] ?? null;
        $temNota = $topo && (int)$topo['jurados'] > 0;

        $linhasQuesito[] = ['celulas' => [
            (string)$c['name'],
            $temNota ? (string)$topo['participant']['name'] : '-',
            $temNota ? relatorio_pdf_numero((float)$topo['media']) : '-',
        ]];
    }

    pdf_tabela($doc, pdf_colunas($doc, [
        ['rotulo' => 'Quesito',  'peso' => 2.5],
        ['rotulo' => '1º lugar', 'peso' => 4.0],
        ['rotulo' => 'Média',    'peso' => 1.2, 'alinhar' => 'direita'],
    ]), $linhasQuesito);

    return pdf_finalizar($doc);
}

/* ===========================================================================
 * CONSOLIDADO DE TODOS OS EVENTOS
 * ======================================================================== */

function relatorio_pdf_consolidado(array $db): string
{
    // Paisagem: nove colunas não cabem em retrato sem virar coluna de sigla.
    $doc = pdf_novo(true);
    $doc['rodape'] = 'Consolidado de eventos';
    pdf_nova_pagina($doc);

    relatorio_pdf_cabecalho($doc, 'Consolidado de todos os eventos', 'Sistema de notas de jurados');

    $linhas = [];
    foreach (relatorio_eventos_consolidado($db) as $ev) {
        $evId = (int)$ev['id'];
        $rk = ranking_for_event($db, $evId);
        $primeiro = $rk[0] ?? null;
        $temNota = $primeiro && (int)$primeiro['judge_count'] > 0;
        $and = resultado_andamento($db, $evId);

        $linhas[] = ['celulas' => [
            (string)$ev['name'],
            (string)($ev['date'] ?? ''),
            (string)($ev['status'] ?? ''),
            (string)count(items_for_event($db['participants'] ?? [], $evId)),
            (string)count(items_for_event($db['judges'] ?? [], $evId)),
            (string)count(items_for_event($db['criteria'] ?? [], $evId)),
            (int)$and['finalizados'] . ' de ' . (int)$and['total'],
            $temNota ? (string)$primeiro['participant']['name'] : '-',
            $temNota ? relatorio_pdf_numero((float)$primeiro['score']) : '-',
        ]];
    }

    pdf_tabela($doc, pdf_colunas($doc, [
        ['rotulo' => 'Evento',    'peso' => 3.4],
        ['rotulo' => 'Data',      'peso' => 1.1],
        ['rotulo' => 'Situação',  'peso' => 1.0],
        ['rotulo' => 'Particip.', 'peso' => 0.8, 'alinhar' => 'direita'],
        ['rotulo' => 'Jurados',   'peso' => 0.8, 'alinhar' => 'direita'],
        ['rotulo' => 'Quesitos',  'peso' => 0.8, 'alinhar' => 'direita'],
        ['rotulo' => 'Entregues', 'peso' => 1.0, 'alinhar' => 'direita'],
        ['rotulo' => '1º lugar',  'peso' => 2.6],
        ['rotulo' => 'Nota',      'peso' => 0.9, 'alinhar' => 'direita'],
    ]), $linhas, 8.5);

    pdf_paragrafo(
        $doc,
        'Eventos arquivados nao entram. O primeiro lugar considera a nota ja com penalidades '
        . 'descontadas, e so aparece quando ha nota lancada.',
        8.0
    );

    return pdf_finalizar($doc);
}

/* ===========================================================================
 * PORTA DE ENTRADA
 * ======================================================================== */

/**
 * Monta o PDF do tipo pedido e devolve conteúdo e nome do arquivo.
 *
 * @return array{conteudo:string, nome:string}
 */
function relatorio_pdf_montar(array $db, ?array $event, string $tipo, array $quesitos, array $alvos): array
{
    $carimbo = date('Y-m-d H\hi');
    $nomeEvento = $event ? preg_replace('/[^A-Za-z0-9 \-]/', '', (string)$event['name']) : 'eventos';

    switch ($tipo) {
        case 'participante':
            return [
                'conteudo' => relatorio_pdf_participante($db, (array)$event, $alvos),
                'nome'     => 'Ficha individual - ' . $nomeEvento . ' - ' . $carimbo . '.pdf',
            ];

        case 'evento':
            return [
                'conteudo' => relatorio_pdf_evento($db, (array)$event),
                'nome'     => 'Relatorio geral - ' . $nomeEvento . ' - ' . $carimbo . '.pdf',
            ];

        case 'consolidado':
            return [
                'conteudo' => relatorio_pdf_consolidado($db),
                'nome'     => 'Consolidado de eventos - ' . $carimbo . '.pdf',
            ];

        case 'quesito':
        default:
            return [
                'conteudo' => relatorio_pdf_quesito($db, (array)$event, $quesitos),
                'nome'     => 'Ranking por quesito - ' . $nomeEvento . ' - ' . $carimbo . '.pdf',
            ];
    }
}
