<?php

/**
 * Planilha SER SESC — a "PROJETO SER SESC.xlsx" administrada dentro do sistema.
 *
 * ---------------------------------------------------------------------------
 * O QUE ESTE MÓDULO RESOLVE
 * ---------------------------------------------------------------------------
 * O arquivo circulava por e-mail e pendrive. Duas pessoas abrindo a mesma
 * planilha geram duas versões, e quem salvar por último apaga o trabalho do
 * outro — sem aviso nenhum. Aqui cada célula grava sozinha, direto na própria
 * linha e coluna do banco, e a tela se atualiza a cada poucos segundos.
 *
 * ---------------------------------------------------------------------------
 * ESTRUTURA
 * ---------------------------------------------------------------------------
 * Bloco  = categoria + turno (INFANTIL 1 MÉXICO MATUTINO...). Guarda a nota
 *          de DANÇA e a de MOSAICO, que valem para o bloco inteiro.
 * Turma  = uma linha da aba INDIVIDUAL, com país e três notas de 0 a 10:
 *          BANDEIRA, MASCOTE e CARACTERIZAÇÃO.
 *
 * Nenhum total é guardado — todos saem do cálculo na leitura. Ver o comentário
 * em sql/mysql_04_ser_sesc.sql.
 *
 * ---------------------------------------------------------------------------
 * DEPENDE DO MySQL
 * ---------------------------------------------------------------------------
 * Ao contrário do resto do sistema, este módulo não tem espelho em db.json.
 * Edição simultânea com gravação por célula precisa de banco de verdade; um
 * arquivo JSON reescrito inteiro a cada gravação traria de volta exatamente o
 * problema que o módulo existe para resolver. Sem MySQL, a tela avisa em vez
 * de fingir que funciona.
 */

declare(strict_types=1);

/** As três colunas de nota da aba INDIVIDUAL, na ordem em que aparecem. */
const SER_CRITERIOS = [
    'bandeira'       => 'Bandeira',
    'mascote'        => 'Mascote',
    'caracterizacao' => 'Caracterização',
];

/** Nota máxima de qualquer célula da planilha. */
const SER_NOTA_MAXIMA = 10.0;

/**
 * As etapas, na ordem da aba PROGRAMAÇÃO do arquivo:
 * INDIVIDUAL → TEMPO → DANÇA → TEMPO → MOSAICO.
 *
 * 'campo' null marca a etapa que não é uma nota única de grupo — a individual
 * tem três notas por turma; as outras duas, uma nota por grupo.
 */
const SER_ETAPAS = [
    'individual' => [
        'titulo'    => 'Individual',
        'campo'     => null,
        'descricao' => 'Bandeira, mascote e caracterização — nota por turma, de 0 a 10 cada.',
    ],
    'danca' => [
        'titulo'    => 'Dança',
        'campo'     => 'danca',
        'descricao' => 'Sincronia, criatividade, expressão corporal e organização — uma nota de 0 a 10 por grupo.',
    ],
    'mosaico' => [
        'titulo'    => 'Mosaico',
        'campo'     => 'mosaico',
        'descricao' => 'Organização, impacto visual, participação coletiva e formação da imagem — uma nota de 0 a 10 por grupo.',
    ],
];

function ser_disponivel(): bool
{
    return mysql_ativo() && mysql_conexao() !== null;
}

/* ===========================================================================
 * LEITURA
 * ======================================================================== */

/**
 * Devolve a planilha inteira, já com os totais calculados.
 *
 * Formato:
 *   [
 *     'blocos' => [ ['id','nome','danca','mosaico','turmas'=>[...],
 *                    'total_individual','total_geral',
 *                    'maximo_individual','maximo_geral'], ... ],
 *     'revisao'   => string,   // muda quando qualquer célula muda
 *     'atualizado'=> string,   // data da alteração mais recente
 *   ]
 */
function ser_ler(): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['blocos' => [], 'revisao' => '', 'atualizado' => ''];
    }

    try {
        $blocos = $pdo->query(
            'SELECT id, nome, categoria, turno, ordem_categoria,
                    danca, mosaico, atualizado, atualizado_por
             FROM ser_blocos ORDER BY ordem, nome'
        )->fetchAll();

        $turmas = $pdo->query(
            'SELECT id, bloco_id, turma, pais, ordem,
                    bandeira, mascote, caracterizacao, atualizado, atualizado_por
             FROM ser_turmas ORDER BY bloco_id, ordem, turma'
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('SER SESC ler: ' . $e->getMessage());

        return ['blocos' => [], 'revisao' => '', 'atualizado' => ''];
    }

    $porBloco = [];
    foreach ($turmas as $t) {
        $porBloco[(int)$t['bloco_id']][] = $t;
    }

    $saida = [];
    $ultima = '';

    foreach ($blocos as $b) {
        $id = (int)$b['id'];
        $lista = $porBloco[$id] ?? [];
        $somaIndividual = 0.0;

        foreach ($lista as $i => $t) {
            $total = 0.0;
            foreach (array_keys(SER_CRITERIOS) as $coluna) {
                $total += (float)($t[$coluna] ?? 0);
            }

            $lista[$i]['total'] = $total;
            $lista[$i]['completa'] = ser_turma_completa($t);
            $somaIndividual += $total;

            if ($t['atualizado'] > $ultima) {
                $ultima = (string)$t['atualizado'];
            }
        }

        if ($b['atualizado'] > $ultima) {
            $ultima = (string)$b['atualizado'];
        }

        $danca = $b['danca'] === null ? null : (float)$b['danca'];
        $mosaico = $b['mosaico'] === null ? null : (float)$b['mosaico'];

        $maximoIndividual = count($lista) * SER_NOTA_MAXIMA * count(SER_CRITERIOS);

        $saida[] = [
            'id'                => $id,
            'nome'              => (string)$b['nome'],
            'categoria'         => (string)($b['categoria'] ?? ''),
            'turno'             => (string)($b['turno'] ?? ''),
            'ordem_categoria'   => (int)($b['ordem_categoria'] ?? 0),
            'danca'             => $danca,
            'mosaico'           => $mosaico,
            'turmas'            => $lista,
            'total_individual'  => $somaIndividual,
            'total_geral'       => $somaIndividual + (float)$danca + (float)$mosaico,
            'maximo_individual' => $maximoIndividual,
            'maximo_geral'      => $maximoIndividual + 2 * SER_NOTA_MAXIMA,
            'atualizado_por'    => (string)($b['atualizado_por'] ?? ''),
            'faltando'          => ser_faltando_no_bloco($lista, $danca, $mosaico),
        ];
    }

    return [
        'blocos'     => $saida,
        'revisao'    => ser_revisao(),
        'atualizado' => $ultima,
    ];
}

/**
 * O que ainda não foi lançado neste grupo, por etapa.
 *
 * Vira o aviso da tela e o rodapé do relatório. Um relatório que não diz o que
 * falta parece completo, e é assim que um número parcial acaba virando
 * resultado oficial.
 */
function ser_faltando_no_bloco(array $turmas, ?float $danca, ?float $mosaico): array
{
    $individual = 0;

    foreach ($turmas as $t) {
        foreach (array_keys(SER_CRITERIOS) as $coluna) {
            if ($t[$coluna] === null || $t[$coluna] === '') {
                $individual++;
            }
        }
    }

    return [
        'individual' => $individual,
        'danca'      => $danca === null ? 1 : 0,
        'mosaico'    => $mosaico === null ? 1 : 0,
        'total'      => $individual + ($danca === null ? 1 : 0) + ($mosaico === null ? 1 : 0),
    ];
}

/* ===========================================================================
 * A DISPUTA
 *
 * Matutino contra vespertino, dentro de cada categoria. Os tetos do arquivo
 * original mostram que é assim: 200 e 200 no Infantil 1, 140 e 140 no
 * Infantil 2, 110 e 110 no Juvenil. Entre categorias os tetos diferem, então
 * uma lista única dos seis grupos premiaria quem tem mais turmas.
 * ======================================================================== */

/**
 * Agrupa os blocos por categoria e aponta o vencedor de cada uma.
 *
 * @return array<int,array{categoria:string, grupos:array, vencedor:?array,
 *                         empate:bool, completa:bool, faltando:int}>
 */
function ser_disputas(array $planilha): array
{
    $porCategoria = [];

    foreach ($planilha['blocos'] as $b) {
        $chave = $b['categoria'] !== '' ? $b['categoria'] : 'Sem categoria';
        $porCategoria[$chave]['ordem'] = $b['ordem_categoria'];
        $porCategoria[$chave]['grupos'][] = $b;
    }

    uasort($porCategoria, static fn(array $a, array $b): int => $a['ordem'] <=> $b['ordem']);

    $disputas = [];

    foreach ($porCategoria as $categoria => $dados) {
        $grupos = $dados['grupos'];

        usort($grupos, static fn(array $a, array $b): int
            => $b['total_geral'] <=> $a['total_geral'] ?: strcmp($a['turno'], $b['turno']));

        $faltando = 0;
        foreach ($grupos as $g) {
            $faltando += $g['faltando']['total'];
        }

        /* Empate só é empate quando os dois primeiros têm o mesmo total E a
           categoria está fechada. Antes disso, 0 a 0 não é empate — é o
           placar ainda em branco. */
        $lider = $grupos[0] ?? null;
        $segundo = $grupos[1] ?? null;
        $empate = $lider !== null && $segundo !== null
            && abs($lider['total_geral'] - $segundo['total_geral']) < 0.005;

        $disputas[] = [
            'categoria' => $categoria,
            'grupos'    => $grupos,
            'vencedor'  => ($faltando === 0 && !$empate) ? $lider : null,
            'empate'    => $faltando === 0 && $empate,
            'completa'  => $faltando === 0,
            'faltando'  => $faltando,
        ];
    }

    return $disputas;
}

/** Quantas notas faltam na planilha inteira, por etapa. */
function ser_pendencias(array $planilha): array
{
    $soma = ['individual' => 0, 'danca' => 0, 'mosaico' => 0, 'total' => 0];

    foreach ($planilha['blocos'] as $b) {
        foreach ($soma as $chave => $_) {
            $soma[$chave] += $b['faltando'][$chave];
        }
    }

    return $soma;
}

/** Uma turma só conta como avaliada quando as três notas foram lançadas. */
function ser_turma_completa(array $turma): bool
{
    foreach (array_keys(SER_CRITERIOS) as $coluna) {
        if ($turma[$coluna] === null || $turma[$coluna] === '') {
            return false;
        }
    }

    return true;
}

/**
 * Impressão digital do estado atual.
 *
 * O navegador guarda a última que recebeu e só redesenha a grade quando ela
 * muda. Sem isto, a atualização automática reescreveria a tela a cada poucos
 * segundos mesmo sem nada ter acontecido, o que rouba o cursor de quem está
 * digitando.
 */
function ser_revisao(): string
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return '';
    }

    try {
        $linha = $pdo->query(
            "SELECT CONCAT(
                        IFNULL(MAX(t.atualizado), ''), '|', COUNT(t.id), '|',
                        IFNULL(SUM(IFNULL(t.bandeira,0) + IFNULL(t.mascote,0)
                                 + IFNULL(t.caracterizacao,0)), 0)
                    ) AS marca
             FROM ser_turmas t"
        )->fetch();

        $blocos = $pdo->query(
            "SELECT CONCAT(IFNULL(MAX(atualizado), ''), '|',
                           IFNULL(SUM(IFNULL(danca,0) + IFNULL(mosaico,0)), 0)) AS marca
             FROM ser_blocos"
        )->fetch();

        return substr(md5(($linha['marca'] ?? '') . ($blocos['marca'] ?? '')), 0, 12);
    } catch (Throwable $e) {
        error_log('SER SESC revisao: ' . $e->getMessage());

        return '';
    }
}

/* ===========================================================================
 * ESCRITA — uma célula por vez
 * ======================================================================== */

/**
 * Grava UMA nota.
 *
 * A coluna nunca vem crua do POST: é procurada numa lista fixa. Sem isso o
 * nome da coluna entraria no SQL vindo do navegador, e nenhum prepare protege
 * contra troca de identificador.
 *
 * @param string $campo  bandeira|mascote|caracterizacao (turma) ou danca|mosaico (bloco)
 * @return array{ok:bool, mensagem:string}
 */
function ser_gravar_nota(string $alvo, int $id, string $campo, ?float $nota, string $autor): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['ok' => false, 'mensagem' => 'Banco de dados indisponível. A nota NÃO foi salva.'];
    }

    $permitidos = $alvo === 'turma'
        ? array_keys(SER_CRITERIOS)
        : ['danca', 'mosaico'];

    if (!in_array($campo, $permitidos, true)) {
        return ['ok' => false, 'mensagem' => 'Campo desconhecido.'];
    }

    if ($nota !== null && ($nota < 0 || $nota > SER_NOTA_MAXIMA)) {
        return ['ok' => false, 'mensagem' => 'A nota precisa estar entre 0 e ' . (int)SER_NOTA_MAXIMA . '.'];
    }

    $tabela = $alvo === 'turma' ? 'ser_turmas' : 'ser_blocos';

    try {
        $sql = $pdo->prepare(
            "UPDATE {$tabela}
                SET {$campo} = :nota, atualizado_por = :autor
              WHERE id = :id"
        );
        $sql->execute([
            ':nota'  => $nota === null ? null : round($nota, 2),
            ':autor' => mb_substr($autor, 0, 120),
            ':id'    => $id,
        ]);

        if ($sql->rowCount() === 0) {
            /* rowCount 0 tanto para linha inexistente quanto para gravação do
               mesmo valor. Só a primeira é erro. */
            $existe = $pdo->prepare("SELECT 1 FROM {$tabela} WHERE id = :id");
            $existe->execute([':id' => $id]);

            if (!$existe->fetchColumn()) {
                return ['ok' => false, 'mensagem' => 'Linha não encontrada. Atualize a página.'];
            }
        }

        return ['ok' => true, 'mensagem' => 'Salvo.'];
    } catch (Throwable $e) {
        error_log('SER SESC gravar_nota: ' . $e->getMessage());

        return ['ok' => false, 'mensagem' => 'Falha ao salvar. A nota NÃO foi registrada.'];
    }
}

/** Renomeia turma ou país. Cadastro, não nota — vai por formulário comum. */
function ser_gravar_turma(int $id, string $turma, string $pais): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['ok' => false, 'mensagem' => 'Banco de dados indisponível.'];
    }

    $turma = trim($turma);
    $pais = trim($pais);

    if ($turma === '' || $pais === '') {
        return ['ok' => false, 'mensagem' => 'Turma e país são obrigatórios.'];
    }

    try {
        $sql = $pdo->prepare(
            'UPDATE ser_turmas SET turma = :turma, pais = :pais WHERE id = :id'
        );
        $sql->execute([
            ':turma' => mb_substr($turma, 0, 60),
            ':pais'  => mb_substr($pais, 0, 60),
            ':id'    => $id,
        ]);

        return ['ok' => true, 'mensagem' => 'Turma atualizada.'];
    } catch (Throwable $e) {
        error_log('SER SESC gravar_turma: ' . $e->getMessage());

        /* 23000 = violação de chave única: já existe turma com esse nome no
           mesmo bloco. Dizer isso é mais útil do que "erro ao salvar". */
        $duplicada = str_starts_with((string)$e->getCode(), '23');

        return [
            'ok' => false,
            'mensagem' => $duplicada
                ? 'Já existe uma turma com esse nome neste bloco.'
                : 'Falha ao salvar a turma.',
        ];
    }
}

/** Acrescenta uma turma ao fim de um bloco. */
function ser_criar_turma(int $blocoId, string $turma, string $pais): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['ok' => false, 'mensagem' => 'Banco de dados indisponível.'];
    }

    $turma = trim($turma);
    $pais = trim($pais);

    if ($turma === '' || $pais === '') {
        return ['ok' => false, 'mensagem' => 'Turma e país são obrigatórios.'];
    }

    try {
        $sql = $pdo->prepare(
            'INSERT INTO ser_turmas (bloco_id, turma, pais, ordem)
             SELECT :bloco, :turma, :pais, IFNULL(MAX(ordem), 0) + 1
               FROM ser_turmas WHERE bloco_id = :bloco2'
        );
        $sql->execute([
            ':bloco'  => $blocoId,
            ':turma'  => mb_substr($turma, 0, 60),
            ':pais'   => mb_substr($pais, 0, 60),
            ':bloco2' => $blocoId,
        ]);

        return ['ok' => true, 'mensagem' => 'Turma adicionada.'];
    } catch (Throwable $e) {
        error_log('SER SESC criar_turma: ' . $e->getMessage());

        $duplicada = str_starts_with((string)$e->getCode(), '23');

        return [
            'ok' => false,
            'mensagem' => $duplicada
                ? 'Já existe uma turma com esse nome neste bloco.'
                : 'Falha ao adicionar a turma.',
        ];
    }
}

function ser_excluir_turma(int $id): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['ok' => false, 'mensagem' => 'Banco de dados indisponível.'];
    }

    try {
        $sql = $pdo->prepare('DELETE FROM ser_turmas WHERE id = :id');
        $sql->execute([':id' => $id]);

        return ['ok' => true, 'mensagem' => 'Turma removida.'];
    } catch (Throwable $e) {
        error_log('SER SESC excluir_turma: ' . $e->getMessage());

        return ['ok' => false, 'mensagem' => 'Falha ao remover a turma.'];
    }
}

/* ===========================================================================
 * RELATÓRIO EM PDF
 *
 * Este arquivo é o que sobra quando o módulo for retirado do sistema. Por
 * isso ele carrega tudo: o resultado de cada categoria, as três etapas
 * detalhadas e, quando faltar nota, um aviso dizendo exatamente o que falta.
 * ======================================================================== */

function ser_pdf_gerar(array $planilha): string
{
    $disputas = ser_disputas($planilha);
    $pendencias = ser_pendencias($planilha);

    $doc = pdf_novo();
    $doc['rodape'] = 'Projeto SER SESC · gerado em ' . date('d/m/Y \à\s H:i');
    pdf_nova_pagina($doc);

    pdf_titulo($doc, 'Projeto SER SESC', 20.0);
    pdf_paragrafo($doc, 'Resultado das três etapas: individual, dança e mosaico. '
        . 'A disputa é entre o turno matutino e o vespertino de cada categoria.');

    if ($pendencias['total'] > 0) {
        pdf_aviso($doc, sprintf(
            'RELATÓRIO PARCIAL — faltam %d nota(s): %d na individual, %d na dança, %d no mosaico.',
            $pendencias['total'],
            $pendencias['individual'],
            $pendencias['danca'],
            $pendencias['mosaico']
        ));
    } else {
        pdf_aviso(
            $doc,
            'Todas as notas lançadas. Resultado final.',
            [232, 244, 236],
            [28, 122, 74]
        );
    }

    /* ---- Resultado ---- */
    pdf_titulo($doc, 'Resultado por categoria');

    foreach ($disputas as $disputa) {
        pdf_espaco($doc, 90);
        $doc['y'] -= 16;
        pdf_texto($doc, $disputa['categoria'], $doc['margem'], $doc['y'], 11.0, true, [0, 47, 143]);

        $situacao = $disputa['empate']
            ? 'EMPATE'
            : ($disputa['vencedor'] !== null
                ? 'Vencedor: ' . $disputa['vencedor']['turno']
                : sprintf('em aberto — faltam %d nota(s)', $disputa['faltando']));

        $largura = pdf_largura_texto($situacao, 9.5, true);
        pdf_texto($doc, $situacao, $doc['largura'] - $doc['margem'] - $largura, $doc['y'], 9.5, true,
            $disputa['completa'] ? [28, 122, 74] : [140, 110, 20]);
        $doc['y'] -= 6;

        $linhas = [];
        foreach ($disputa['grupos'] as $grupo) {
            $campeao = $disputa['vencedor'] !== null && $grupo['id'] === $disputa['vencedor']['id'];
            $linhas[] = [
                'celulas' => [
                    $grupo['turno'] . ($campeao ? '   *' : ''),
                    ser_numero_relatorio($grupo['total_individual']) . ' / ' . ser_numero_relatorio($grupo['maximo_individual']),
                    ser_numero_relatorio($grupo['danca']),
                    ser_numero_relatorio($grupo['mosaico']),
                    ser_numero_relatorio($grupo['total_geral']) . ' / ' . ser_numero_relatorio($grupo['maximo_geral']),
                ],
                'destaque' => $campeao,
                'negrito'  => $campeao,
            ];
        }

        pdf_tabela($doc, pdf_colunas($doc, [
            ['rotulo' => 'Turno',      'peso' => 2.2],
            ['rotulo' => 'Individual', 'peso' => 2.0, 'alinhar' => 'direita'],
            ['rotulo' => 'Dança',      'peso' => 1.2, 'alinhar' => 'direita'],
            ['rotulo' => 'Mosaico',    'peso' => 1.2, 'alinhar' => 'direita'],
            ['rotulo' => 'Total',      'peso' => 2.0, 'alinhar' => 'direita'],
        ]), $linhas);
    }

    /* ---- Etapa 1 ---- */
    pdf_nova_pagina($doc);
    pdf_titulo($doc, 'Etapa 1 — Individual');
    pdf_paragrafo($doc, SER_ETAPAS['individual']['descricao']
        . ' A nota de cada turma soma para o grupo; as turmas não disputam entre si.');

    foreach ($planilha['blocos'] as $bloco) {
        pdf_espaco($doc, 80);
        $doc['y'] -= 16;
        pdf_texto($doc, $bloco['nome'], $doc['margem'], $doc['y'], 10.5, true, [0, 47, 143]);
        $doc['y'] -= 4;

        $linhas = [];
        foreach ($bloco['turmas'] as $turma) {
            $linhas[] = ['celulas' => [
                $turma['turma'],
                $turma['pais'],
                ser_numero_relatorio($turma['bandeira']),
                ser_numero_relatorio($turma['mascote']),
                ser_numero_relatorio($turma['caracterizacao']),
                ser_numero_relatorio($turma['total']),
            ]];
        }

        $linhas[] = [
            'celulas' => [
                'SOMA DO GRUPO', '', '', '', '',
                ser_numero_relatorio($bloco['total_individual']) . ' / ' . ser_numero_relatorio($bloco['maximo_individual']),
            ],
            'negrito' => true,
        ];

        pdf_tabela($doc, pdf_colunas($doc, [
            ['rotulo' => 'Turma',          'peso' => 2.0],
            ['rotulo' => 'País',           'peso' => 2.4],
            ['rotulo' => 'Bandeira',       'peso' => 1.3, 'alinhar' => 'direita'],
            ['rotulo' => 'Mascote',        'peso' => 1.3, 'alinhar' => 'direita'],
            ['rotulo' => 'Caracterização', 'peso' => 1.7, 'alinhar' => 'direita'],
            ['rotulo' => 'Total',          'peso' => 1.3, 'alinhar' => 'direita'],
        ]), $linhas);
    }

    /* ---- Etapas 2 e 3 ---- */
    pdf_nova_pagina($doc);

    foreach (['danca' => 'Etapa 2 — Dança', 'mosaico' => 'Etapa 3 — Mosaico'] as $campo => $titulo) {
        pdf_titulo($doc, $titulo);
        pdf_paragrafo($doc, SER_ETAPAS[$campo]['descricao']);

        $linhas = [];
        foreach ($planilha['blocos'] as $bloco) {
            $linhas[] = ['celulas' => [
                $bloco['categoria'] !== '' ? $bloco['categoria'] : $bloco['nome'],
                $bloco['turno'],
                ser_numero_relatorio($bloco[$campo]) . ' / 10',
            ]];
        }

        pdf_tabela($doc, pdf_colunas($doc, [
            ['rotulo' => 'Categoria', 'peso' => 4.0],
            ['rotulo' => 'Turno',     'peso' => 2.0],
            ['rotulo' => 'Nota',      'peso' => 2.0, 'alinhar' => 'direita'],
        ]), $linhas);
    }

    /* ---- Consolidado ---- */
    pdf_titulo($doc, 'Total geral');
    pdf_paragrafo($doc, 'Os seis grupos lado a lado. O teto difere entre categorias — '
        . 'por isso a disputa é dentro de cada uma, e não nesta lista.');

    $linhas = [];
    foreach ($planilha['blocos'] as $bloco) {
        $linhas[] = ['celulas' => [
            $bloco['categoria'] !== '' ? $bloco['categoria'] : $bloco['nome'],
            $bloco['turno'],
            ser_numero_relatorio($bloco['total_individual']),
            ser_numero_relatorio($bloco['danca']),
            ser_numero_relatorio($bloco['mosaico']),
            ser_numero_relatorio($bloco['total_geral']),
            ser_numero_relatorio($bloco['maximo_geral']),
        ]];
    }

    pdf_tabela($doc, pdf_colunas($doc, [
        ['rotulo' => 'Categoria',  'peso' => 3.0],
        ['rotulo' => 'Turno',      'peso' => 1.8],
        ['rotulo' => 'Individual', 'peso' => 1.5, 'alinhar' => 'direita'],
        ['rotulo' => 'Dança',      'peso' => 1.1, 'alinhar' => 'direita'],
        ['rotulo' => 'Mosaico',    'peso' => 1.2, 'alinhar' => 'direita'],
        ['rotulo' => 'Total',      'peso' => 1.2, 'alinhar' => 'direita'],
        ['rotulo' => 'Máximo',     'peso' => 1.2, 'alinhar' => 'direita'],
    ]), $linhas);

    return pdf_finalizar($doc);
}

/** Número para o relatório: '—' quando a nota não foi lançada. */
function ser_numero_relatorio($valor): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }

    return rtrim(rtrim(number_format((float)$valor, 2, ',', '.'), '0'), ',');
}

/* ===========================================================================
 * LEITURA DO .xlsx
 *
 * Um .xlsx é um zip de XML. São necessárias três coisas: a lista de abas em
 * xl/workbook.xml, o texto compartilhado em xl/sharedStrings.xml e as células
 * de cada xl/worksheets/sheetN.xml. Não há biblioteca externa envolvida — o
 * formato usado aqui é simples e a alternativa seria arrastar um pacote
 * inteiro para ler três colunas.
 * ======================================================================== */

/**
 * Lê a aba INDIVIDUAL de um .xlsx no formato da planilha original.
 *
 * @return array{ok:bool, mensagem:string, blocos?:array}
 */
function ser_xlsx_ler(string $caminho): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'mensagem' => 'O servidor não sabe abrir arquivos .xlsx.'];
    }

    $zip = new ZipArchive();

    if ($zip->open($caminho) !== true) {
        return ['ok' => false, 'mensagem' => 'Não foi possível abrir o arquivo. Ele é mesmo um .xlsx?'];
    }

    $textos = ser_xlsx_textos($zip->getFromName('xl/sharedStrings.xml') ?: '');
    $abas = ser_xlsx_abas($zip);
    $indice = null;

    foreach ($abas as $nome => $arquivo) {
        if (str_contains(ser_normalizar($nome), 'INDIVIDUAL')) {
            $indice = $arquivo;
            break;
        }
    }

    /* Sem aba com esse nome, usa a primeira: planilhas renomeadas são comuns
       e recusar o arquivo por causa do rótulo seria rigor inútil. */
    $indice ??= reset($abas) ?: 'xl/worksheets/sheet1.xml';
    $xml = $zip->getFromName($indice) ?: '';
    $zip->close();

    if ($xml === '') {
        return ['ok' => false, 'mensagem' => 'A planilha não tem nenhuma aba legível.'];
    }

    $linhas = ser_xlsx_linhas($xml, $textos);

    return ser_xlsx_interpretar($linhas);
}

/** Lista de abas do workbook: nome visível => caminho do XML. */
function ser_xlsx_abas(ZipArchive $zip): array
{
    $workbook = $zip->getFromName('xl/workbook.xml') ?: '';
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels') ?: '';

    $alvo = [];
    if (preg_match_all('/<Relationship\b[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/i', $rels, $m, PREG_SET_ORDER)) {
        foreach ($m as $r) {
            $alvo[$r[1]] = 'xl/' . ltrim(str_replace('/xl/', '', $r[2]), '/');
        }
    }

    $abas = [];
    if (preg_match_all('/<sheet\b[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/i', $workbook, $m, PREG_SET_ORDER)) {
        foreach ($m as $s) {
            $abas[html_entity_decode($s[1], ENT_QUOTES | ENT_XML1, 'UTF-8')] = $alvo[$s[2]] ?? '';
        }
    }

    return array_filter($abas);
}

/** Tabela de textos compartilhados — no .xlsx as strings ficam fora da aba. */
function ser_xlsx_textos(string $xml): array
{
    $textos = [];

    if (!preg_match_all('/<si>(.*?)<\/si>/s', $xml, $itens)) {
        return $textos;
    }

    foreach ($itens[1] as $item) {
        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $item, $pedacos);
        $textos[] = html_entity_decode(implode('', $pedacos[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    return $textos;
}

/** Células de uma aba, como [numero_da_linha => ['A' => valor, ...]]. */
function ser_xlsx_linhas(string $xml, array $textos): array
{
    $linhas = [];

    if (!preg_match_all('/<row[^>]*\br="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rows, PREG_SET_ORDER)) {
        return $linhas;
    }

    foreach ($rows as $row) {
        $celulas = [];

        if (preg_match_all('/<c\b[^>]*r="([A-Z]+)\d+"([^>]*)>(.*?)<\/c>/s', $row[2], $cs, PREG_SET_ORDER)) {
            foreach ($cs as $c) {
                $tipo = preg_match('/t="([^"]+)"/', $c[2], $t) ? $t[1] : '';
                $bruto = preg_match('/<v>(.*?)<\/v>/s', $c[3], $v) ? $v[1] : '';

                if ($bruto === '' && $tipo === 'inlineStr') {
                    $bruto = preg_match('/<t[^>]*>(.*?)<\/t>/s', $c[3], $i) ? $i[1] : '';
                    $celulas[$c[1]] = html_entity_decode($bruto, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    continue;
                }

                $celulas[$c[1]] = $tipo === 's'
                    ? ($textos[(int)$bruto] ?? '')
                    : html_entity_decode($bruto, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        $linhas[(int)$row[1]] = $celulas;
    }

    return $linhas;
}

/**
 * Transforma as linhas cruas na estrutura de blocos e turmas.
 *
 * O reconhecimento é pela coluna A: linha que começa com "CATEGORIA" abre um
 * bloco; linha com A e B preenchidos dentro de um bloco é turma. Linhas de
 * total (só a coluna G) são ignoradas — o total aqui é recalculado.
 */
function ser_xlsx_interpretar(array $linhas): array
{
    $blocos = [];
    $atual = null;

    foreach ($linhas as $celulas) {
        $a = trim((string)($celulas['A'] ?? ''));
        $b = trim((string)($celulas['B'] ?? ''));

        if ($a === '') {
            continue;
        }

        if (str_starts_with(ser_normalizar($a), 'CATEGORIA')) {
            /* "CATEGORIA : INFANTIL 1 MÉXICO MATUTINO" -> o que vem depois. */
            $nome = trim((string)preg_replace('/^\s*CATEGORIA\s*:?\s*/iu', '', $a));

            if ($nome === '') {
                continue;
            }

            $atual = $nome;
            $blocos[$atual] ??= [];
            continue;
        }

        if ($atual === null || $b === '') {
            continue;
        }

        $blocos[$atual][] = [
            'turma'          => $a,
            'pais'           => $b,
            'bandeira'       => ser_nota_de_celula($celulas['D'] ?? null),
            'mascote'        => ser_nota_de_celula($celulas['E'] ?? null),
            'caracterizacao' => ser_nota_de_celula($celulas['F'] ?? null),
        ];
    }

    if ($blocos === []) {
        return [
            'ok' => false,
            'mensagem' => 'Nenhuma turma reconhecida. A coluna A precisa ter as linhas '
                . '"CATEGORIA : ..." e, abaixo de cada uma, a turma na coluna A e o país na B.',
        ];
    }

    return ['ok' => true, 'mensagem' => '', 'blocos' => $blocos];
}

/**
 * Converte o conteúdo de uma célula em nota.
 *
 * O arquivo original traz "0 A 10" nas colunas de nota — é o gabarito da
 * faixa, não um valor. Texto que não seja número vira "sem nota".
 */
function ser_nota_de_celula($valor): ?float
{
    $texto = trim((string)$valor);

    if ($texto === '' || !is_numeric(str_replace(',', '.', $texto))) {
        return null;
    }

    $nota = (float)str_replace(',', '.', $texto);

    return ($nota < 0 || $nota > SER_NOTA_MAXIMA) ? null : $nota;
}

/** Maiúsculas sem acento — para comparar rótulos vindos do arquivo. */
function ser_normalizar(string $texto): string
{
    $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

    return strtoupper(trim($semAcento === false ? $texto : $semAcento));
}

/**
 * Aplica ao banco o que veio do arquivo.
 *
 * Cadastro é sincronizado; nota só é gravada quando o arquivo traz número.
 * Assim, reimportar o gabarito original — onde as células dizem "0 A 10" —
 * não apaga o que já foi lançado na tela.
 *
 * @return array{ok:bool, mensagem:string}
 */
function ser_xlsx_aplicar(array $blocos, string $autor): array
{
    $pdo = mysql_conexao();

    if (!$pdo) {
        return ['ok' => false, 'mensagem' => 'Banco de dados indisponível. Nada foi importado.'];
    }

    $novosBlocos = 0;
    $novasTurmas = 0;
    $comNota = 0;

    try {
        $pdo->beginTransaction();

        $acharBloco = $pdo->prepare('SELECT id FROM ser_blocos WHERE nome = :nome');
        $criarBloco = $pdo->prepare(
            'INSERT INTO ser_blocos (nome, ordem)
             SELECT :nome, IFNULL(MAX(ordem), 0) + 1 FROM ser_blocos'
        );
        $acharTurma = $pdo->prepare(
            'SELECT id FROM ser_turmas WHERE bloco_id = :bloco AND turma = :turma'
        );
        $criarTurma = $pdo->prepare(
            'INSERT INTO ser_turmas (bloco_id, turma, pais, ordem)
             VALUES (:bloco, :turma, :pais, :ordem)'
        );
        $ajustarTurma = $pdo->prepare(
            'UPDATE ser_turmas SET pais = :pais, ordem = :ordem WHERE id = :id'
        );
        $gravarNotas = $pdo->prepare(
            'UPDATE ser_turmas
                SET bandeira = :bandeira, mascote = :mascote,
                    caracterizacao = :carac, atualizado_por = :autor
              WHERE id = :id'
        );

        foreach ($blocos as $nome => $turmas) {
            $acharBloco->execute([':nome' => mb_substr($nome, 0, 120)]);
            $blocoId = (int)($acharBloco->fetchColumn() ?: 0);

            if ($blocoId === 0) {
                $criarBloco->execute([':nome' => mb_substr($nome, 0, 120)]);
                $blocoId = (int)$pdo->lastInsertId();
                $novosBlocos++;
            }

            foreach ($turmas as $ordem => $t) {
                $acharTurma->execute([
                    ':bloco' => $blocoId,
                    ':turma' => mb_substr($t['turma'], 0, 60),
                ]);
                $turmaId = (int)($acharTurma->fetchColumn() ?: 0);

                if ($turmaId === 0) {
                    $criarTurma->execute([
                        ':bloco' => $blocoId,
                        ':turma' => mb_substr($t['turma'], 0, 60),
                        ':pais'  => mb_substr($t['pais'], 0, 60),
                        ':ordem' => $ordem + 1,
                    ]);
                    $turmaId = (int)$pdo->lastInsertId();
                    $novasTurmas++;
                } else {
                    $ajustarTurma->execute([
                        ':pais'  => mb_substr($t['pais'], 0, 60),
                        ':ordem' => $ordem + 1,
                        ':id'    => $turmaId,
                    ]);
                }

                $temNota = $t['bandeira'] !== null || $t['mascote'] !== null
                    || $t['caracterizacao'] !== null;

                if ($temNota) {
                    $gravarNotas->execute([
                        ':bandeira' => $t['bandeira'],
                        ':mascote'  => $t['mascote'],
                        ':carac'    => $t['caracterizacao'],
                        ':autor'    => mb_substr($autor . ' (importação)', 0, 120),
                        ':id'       => $turmaId,
                    ]);
                    $comNota++;
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('SER SESC importar: ' . $e->getMessage());

        return ['ok' => false, 'mensagem' => 'Falha ao importar. Nada foi alterado.'];
    }

    $partes = [];
    $partes[] = $novosBlocos > 0 ? "{$novosBlocos} bloco(s) novo(s)" : 'nenhum bloco novo';
    $partes[] = $novasTurmas > 0 ? "{$novasTurmas} turma(s) nova(s)" : 'nenhuma turma nova';
    $partes[] = $comNota > 0
        ? "{$comNota} turma(s) com nota vinda do arquivo"
        : 'nenhuma nota no arquivo (só o cadastro foi sincronizado)';

    return ['ok' => true, 'mensagem' => 'Importado: ' . implode(', ', $partes) . '.'];
}

/* ===========================================================================
 * GERAÇÃO DO .xlsx
 *
 * Escreve o mínimo que o Excel e o LibreOffice aceitam: content types, a
 * relação raiz, o workbook e uma aba por seção. Todo texto vai como inlineStr
 * para dispensar a tabela de strings compartilhadas.
 * ======================================================================== */

/** Monta o arquivo e devolve o caminho temporário, ou null se falhar. */
function ser_xlsx_gerar(array $planilha): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $arquivo = tempnam(sys_get_temp_dir(), 'ser');

    if ($arquivo === false) {
        return null;
    }

    $zip = new ZipArchive();

    if ($zip->open($arquivo, ZipArchive::OVERWRITE) !== true) {
        @unlink($arquivo);

        return null;
    }

    $abas = [
        'RESULTADO'  => ser_aba_resultado($planilha),
        'INDIVIDUAL' => ser_aba_individual($planilha),
        'DANÇA'      => ser_aba_criterio($planilha, 'danca'),
        'MOSAICO'    => ser_aba_criterio($planilha, 'mosaico'),
        'TOTAL'      => ser_aba_total($planilha),
    ];

    $zip->addFromString('[Content_Types].xml', ser_xml_content_types(count($abas)));
    $zip->addFromString('_rels/.rels', ser_xml_rels_raiz());
    $zip->addFromString('xl/workbook.xml', ser_xml_workbook(array_keys($abas)));
    $zip->addFromString('xl/_rels/workbook.xml.rels', ser_xml_rels_workbook(count($abas)));

    $n = 1;
    foreach ($abas as $linhas) {
        $zip->addFromString("xl/worksheets/sheet{$n}.xml", ser_xml_aba($linhas));
        $n++;
    }

    $zip->close();

    return $arquivo;
}

/**
 * Aba RESULTADO — a primeira que se abre no Excel.
 *
 * Traz a disputa de cada categoria e, no topo, o aviso de quantas notas
 * faltam. Um arquivo parcial que não se anuncia como parcial vira resultado
 * oficial na mão de quem o receber depois.
 */
function ser_aba_resultado(array $planilha): array
{
    $disputas = ser_disputas($planilha);
    $pendencias = ser_pendencias($planilha);

    $linhas = [
        ['PROJETO SER SESC — RESULTADO'],
        ['Gerado em', date('d/m/Y H:i')],
        [$pendencias['total'] > 0
            ? sprintf(
                'PARCIAL: faltam %d nota(s) — %d na individual, %d na dança, %d no mosaico',
                $pendencias['total'],
                $pendencias['individual'],
                $pendencias['danca'],
                $pendencias['mosaico']
            )
            : 'COMPLETO: todas as notas lançadas'],
        [],
        ['Categoria', 'Turno', 'Individual', 'Dança', 'Mosaico', 'Total', 'Máximo', 'Situação'],
    ];

    foreach ($disputas as $disputa) {
        foreach ($disputa['grupos'] as $grupo) {
            $campeao = $disputa['vencedor'] !== null && $grupo['id'] === $disputa['vencedor']['id'];

            $linhas[] = [
                $disputa['categoria'],
                $grupo['turno'],
                $grupo['total_individual'],
                $grupo['danca'] === null ? '' : (float)$grupo['danca'],
                $grupo['mosaico'] === null ? '' : (float)$grupo['mosaico'],
                $grupo['total_geral'],
                $grupo['maximo_geral'],
                $campeao ? 'VENCEDOR' : ($disputa['empate'] ? 'EMPATE' : ($disputa['completa'] ? '' : 'em aberto')),
            ];
        }

        $linhas[] = [];
    }

    return $linhas;
}

function ser_aba_individual(array $planilha): array
{
    $linhas = [];

    foreach ($planilha['blocos'] as $bloco) {
        $linhas[] = ['CATEGORIA : ' . $bloco['nome'], '', '', 'BANDEIRA', 'MASCOTE', 'CARACTERIZAÇÃO', 'TOTAL'];

        foreach ($bloco['turmas'] as $t) {
            $linhas[] = [
                $t['turma'],
                $t['pais'],
                '',
                $t['bandeira'] === null ? '' : (float)$t['bandeira'],
                $t['mascote'] === null ? '' : (float)$t['mascote'],
                $t['caracterizacao'] === null ? '' : (float)$t['caracterizacao'],
                (float)$t['total'],
            ];
        }

        $linhas[] = ['', '', '', '', '', 'TOTAL DO BLOCO', $bloco['total_individual']];
        $linhas[] = [];
    }

    return $linhas;
}

function ser_aba_criterio(array $planilha, string $campo): array
{
    $rotulo = $campo === 'danca'
        ? 'DANÇA (SINCRONIA, CRIATIVIDADE, EXPRESSÃO CORPORAL, ORGANIZAÇÃO)'
        : 'MOSAICO (ORGANIZAÇÃO, IMPACTO VISUAL, PARTICIPAÇÃO COLETIVA E FORMAÇÃO DA IMAGEM)';

    $linhas = [[$rotulo], ['CATEGORIA', 'NOTA']];

    foreach ($planilha['blocos'] as $bloco) {
        $linhas[] = [$bloco['nome'], $bloco[$campo] === null ? '' : (float)$bloco[$campo]];
    }

    return $linhas;
}

function ser_aba_total(array $planilha): array
{
    $linhas = [['CATEGORIA', 'INDIVIDUAL (BANDEIRA, MASCOTE, CARACTERIZAÇÃO)', 'DANÇA', 'MOSAICO', 'TOTAL']];

    foreach ($planilha['blocos'] as $bloco) {
        $linhas[] = [
            $bloco['nome'],
            $bloco['total_individual'],
            $bloco['danca'] === null ? '' : (float)$bloco['danca'],
            $bloco['mosaico'] === null ? '' : (float)$bloco['mosaico'],
            $bloco['total_geral'],
        ];
    }

    return $linhas;
}

function ser_xml_content_types(int $abas): string
{
    $partes = '';
    for ($n = 1; $n <= $abas; $n++) {
        $partes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" '
        . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $partes
        . '</Types>';
}

function ser_xml_rels_raiz(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" '
        . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
        . 'Target="xl/workbook.xml"/></Relationships>';
}

function ser_xml_workbook(array $nomes): string
{
    $abas = '';
    foreach ($nomes as $i => $nome) {
        $abas .= sprintf(
            '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
            htmlspecialchars($nome, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $i + 1,
            $i + 1
        );
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $abas . '</sheets></workbook>';
}

function ser_xml_rels_workbook(int $abas): string
{
    $rels = '';
    for ($n = 1; $n <= $abas; $n++) {
        $rels .= '<Relationship Id="rId' . $n . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
            . 'Target="worksheets/sheet' . $n . '.xml"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $rels . '</Relationships>';
}

function ser_xml_aba(array $linhas): string
{
    $xml = '';

    foreach ($linhas as $i => $celulas) {
        $numero = $i + 1;
        $conteudo = '';

        foreach (array_values($celulas) as $coluna => $valor) {
            if ($valor === '' || $valor === null) {
                continue;
            }

            $ref = ser_coluna_letra($coluna) . $numero;

            if (is_int($valor) || is_float($valor)) {
                $conteudo .= '<c r="' . $ref . '"><v>' . $valor . '</v></c>';
                continue;
            }

            $conteudo .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                . htmlspecialchars((string)$valor, ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '</t></is></c>';
        }

        $xml .= '<row r="' . $numero . '">' . $conteudo . '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $xml . '</sheetData></worksheet>';
}

/** 0 => A, 25 => Z, 26 => AA. */
function ser_coluna_letra(int $indice): string
{
    $letra = '';

    for ($n = $indice; $n >= 0; $n = intdiv($n, 26) - 1) {
        $letra = chr(65 + $n % 26) . $letra;
    }

    return $letra;
}
