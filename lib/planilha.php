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
            'SELECT id, nome, danca, mosaico, atualizado, atualizado_por
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

        $saida[] = [
            'id'                => $id,
            'nome'              => (string)$b['nome'],
            'danca'             => $danca,
            'mosaico'           => $mosaico,
            'turmas'            => $lista,
            'total_individual'  => $somaIndividual,
            'total_geral'       => $somaIndividual + (float)$danca + (float)$mosaico,
            'maximo_individual' => count($lista) * SER_NOTA_MAXIMA * count(SER_CRITERIOS),
            'maximo_geral'      => count($lista) * SER_NOTA_MAXIMA * count(SER_CRITERIOS) + 2 * SER_NOTA_MAXIMA,
            'atualizado_por'    => (string)($b['atualizado_por'] ?? ''),
        ];
    }

    return [
        'blocos'     => $saida,
        'revisao'    => ser_revisao(),
        'atualizado' => $ultima,
    ];
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
