<?php

/**
 * Gerador de PDF mínimo.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ESCRITO À MÃO
 * ---------------------------------------------------------------------------
 * O servidor não tem nenhuma biblioteca de PDF, não há composer no projeto, e
 * o pool do PHP-FPM bloqueia exec/shell_exec — então conversores de linha de
 * comando (wkhtmltopdf e afins) estão fora. Arrastar um pacote inteiro para
 * imprimir três tabelas custaria mais, em dependência e em superfície, do que
 * as poucas centenas de linhas abaixo.
 *
 * ---------------------------------------------------------------------------
 * O QUE ELE FAZ E O QUE NÃO FAZ
 * ---------------------------------------------------------------------------
 * Faz: texto em Helvetica normal e negrito, tabelas com faixas e bordas,
 * títulos, quebra de página automática, retrato ou paisagem A4.
 *
 * Não faz: imagens, fontes próprias, cores fora de RGB simples, acentuação
 * fora do Latin-1. Para um relatório de notas em português isso basta —
 * Helvetica é uma das 14 fontes que todo leitor de PDF tem, então nada
 * precisa ser embutido, e o arquivo sai com poucos kB.
 *
 * ---------------------------------------------------------------------------
 * ACENTUAÇÃO
 * ---------------------------------------------------------------------------
 * O texto do sistema é UTF-8; o PDF, com WinAnsiEncoding, espera CP1252.
 * Todo texto passa por iconv na entrada. Ç, ã, é, ú, º — tudo que o português
 * usa está em CP1252. O travessão (—) não está, e é convertido para hífen
 * pelo //TRANSLIT em vez de virar um quadrado vazio.
 */

declare(strict_types=1);

/** Larguras dos glifos da Helvetica, em milésimos de em. */
const PDF_LARGURAS = [
    32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667,
    39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
    46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556,
    53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278,
    60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667,
    67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278,
    74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
    81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
    88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469,
    95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556,
    102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500,
    108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556,
    114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
    120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334,
    126 => 584,
];

/**
 * Um documento em construção.
 *
 * @return array<string,mixed>
 */
function pdf_novo(bool $paisagem = false): array
{
    return [
        'largura'  => $paisagem ? 842.0 : 595.0,
        'altura'   => $paisagem ? 595.0 : 842.0,
        'margem'   => 40.0,
        'y'        => 0.0,           // preenchido por pdf_nova_pagina
        'paginas'  => [],
        'atual'    => '',
        'rodape'   => '',
    ];
}

/** Largura útil, já descontadas as duas margens. */
function pdf_util(array $doc): float
{
    return $doc['largura'] - 2 * $doc['margem'];
}

/** UTF-8 -> CP1252, que é o que o WinAnsiEncoding do PDF espera. */
function pdf_texto_cru(string $texto): string
{
    $convertido = @iconv('UTF-8', 'CP1252//TRANSLIT', $texto);

    return $convertido === false ? $texto : $convertido;
}

/** Escapa o que quebraria uma string literal de PDF. */
function pdf_escapar(string $texto): string
{
    return strtr(pdf_texto_cru($texto), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
}

/** Largura de um texto, em pontos, para o tamanho de fonte dado. */
function pdf_largura_texto(string $texto, float $tamanho, bool $negrito = false): float
{
    $cru = pdf_texto_cru($texto);
    $total = 0;

    for ($i = 0, $n = strlen($cru); $i < $n; $i++) {
        $codigo = ord($cru[$i]);
        /* Acentuados e demais códigos altos não estão na tabela: 556 é a
           largura média da Helvetica e erra pouco. */
        $total += PDF_LARGURAS[$codigo] ?? 556;
    }

    /* O negrito da Helvetica é cerca de 5% mais largo. Aproximar aqui evita
       carregar uma segunda tabela de 100 entradas para ganhar meio ponto. */
    return $total * $tamanho / 1000 * ($negrito ? 1.05 : 1.0);
}

/** Corta o texto e acrescenta reticências se não couber na largura. */
function pdf_encurtar(string $texto, float $limite, float $tamanho, bool $negrito = false): string
{
    if (pdf_largura_texto($texto, $tamanho, $negrito) <= $limite) {
        return $texto;
    }

    $corte = $texto;
    while ($corte !== '' && pdf_largura_texto($corte . '...', $tamanho, $negrito) > $limite) {
        $corte = mb_substr($corte, 0, mb_strlen($corte) - 1, 'UTF-8');
    }

    return $corte . '...';
}

function pdf_nova_pagina(array &$doc): void
{
    if ($doc['atual'] !== '') {
        $doc['paginas'][] = $doc['atual'];
    }

    $doc['atual'] = '';
    $doc['y'] = $doc['altura'] - $doc['margem'];
}

/** Garante espaço vertical; abre página nova quando não há. */
function pdf_espaco(array &$doc, float $altura): void
{
    if ($doc['y'] - $altura < $doc['margem'] + 24) {
        pdf_nova_pagina($doc);
    }
}

function pdf_texto(array &$doc, string $texto, float $x, float $y, float $tamanho, bool $negrito = false, array $cor = [0, 0, 0]): void
{
    $doc['atual'] .= sprintf(
        "BT /%s %.1f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
        $negrito ? 'F2' : 'F1',
        $tamanho,
        $cor[0] / 255,
        $cor[1] / 255,
        $cor[2] / 255,
        $x,
        $y,
        pdf_escapar($texto)
    );
}

function pdf_retangulo(array &$doc, float $x, float $y, float $largura, float $altura, array $cor): void
{
    $doc['atual'] .= sprintf(
        "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
        $cor[0] / 255,
        $cor[1] / 255,
        $cor[2] / 255,
        $x,
        $y,
        $largura,
        $altura
    );
}

function pdf_linha(array &$doc, float $x1, float $y1, float $x2, float $y2, array $cor = [210, 216, 228], float $espessura = 0.6): void
{
    $doc['atual'] .= sprintf(
        "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
        $cor[0] / 255,
        $cor[1] / 255,
        $cor[2] / 255,
        $espessura,
        $x1,
        $y1,
        $x2,
        $y2
    );
}

/* ===========================================================================
 * BLOCOS DE ALTO NÍVEL
 * ======================================================================== */

function pdf_titulo(array &$doc, string $texto, float $tamanho = 15.0, array $cor = [0, 47, 143]): void
{
    pdf_espaco($doc, $tamanho + 16);
    $doc['y'] -= $tamanho + 6;
    pdf_texto($doc, $texto, $doc['margem'], $doc['y'], $tamanho, true, $cor);
    $doc['y'] -= 8;
}

function pdf_paragrafo(array &$doc, string $texto, float $tamanho = 9.0, array $cor = [83, 96, 120]): void
{
    $palavras = preg_split('/\s+/u', $texto) ?: [];
    $linha = '';
    $largura = pdf_util($doc);

    foreach ($palavras as $palavra) {
        $tentativa = $linha === '' ? $palavra : $linha . ' ' . $palavra;

        if (pdf_largura_texto($tentativa, $tamanho) > $largura && $linha !== '') {
            pdf_espaco($doc, $tamanho + 4);
            $doc['y'] -= $tamanho + 3;
            pdf_texto($doc, $linha, $doc['margem'], $doc['y'], $tamanho, false, $cor);
            $linha = $palavra;
            continue;
        }

        $linha = $tentativa;
    }

    if ($linha !== '') {
        pdf_espaco($doc, $tamanho + 4);
        $doc['y'] -= $tamanho + 3;
        pdf_texto($doc, $linha, $doc['margem'], $doc['y'], $tamanho, false, $cor);
    }

    $doc['y'] -= 4;
}

/** Faixa colorida com um recado dentro — usada para o aviso de incompleto. */
function pdf_aviso(array &$doc, string $texto, array $fundo = [253, 236, 235], array $tinta = [179, 38, 30]): void
{
    $altura = 22.0;
    pdf_espaco($doc, $altura + 10);
    $doc['y'] -= $altura + 4;
    pdf_retangulo($doc, $doc['margem'], $doc['y'], pdf_util($doc), $altura, $fundo);
    pdf_texto($doc, $texto, $doc['margem'] + 8, $doc['y'] + 7, 9.0, true, $tinta);
    $doc['y'] -= 6;
}

/**
 * Uma tabela.
 *
 * @param array<int,array{rotulo:string, largura:float, alinhar?:string}> $colunas
 * @param array<int,array{celulas:array<int,string>, destaque?:bool, negrito?:bool}> $linhas
 */
function pdf_tabela(array &$doc, array $colunas, array $linhas, float $tamanho = 9.0): void
{
    $alturaLinha = $tamanho + 9;
    $cabecalho = static function (array &$doc) use ($colunas, $tamanho, $alturaLinha): void {
        pdf_retangulo($doc, $doc['margem'], $doc['y'] - $alturaLinha, pdf_util($doc), $alturaLinha, [238, 241, 247]);
        $x = $doc['margem'];

        foreach ($colunas as $coluna) {
            $texto = pdf_encurtar($coluna['rotulo'], $coluna['largura'] - 10, $tamanho - 0.5, true);
            $destino = ($coluna['alinhar'] ?? 'esquerda') === 'direita'
                ? $x + $coluna['largura'] - 5 - pdf_largura_texto($texto, $tamanho - 0.5, true)
                : $x + 5;
            pdf_texto($doc, $texto, $destino, $doc['y'] - $alturaLinha + 6, $tamanho - 0.5, true, [45, 60, 90]);
            $x += $coluna['largura'];
        }

        $doc['y'] -= $alturaLinha;
    };

    pdf_espaco($doc, $alturaLinha * 3);
    $cabecalho($doc);

    foreach ($linhas as $linha) {
        if ($doc['y'] - $alturaLinha < $doc['margem'] + 24) {
            pdf_nova_pagina($doc);
            $cabecalho($doc);
        }

        if (!empty($linha['destaque'])) {
            pdf_retangulo($doc, $doc['margem'], $doc['y'] - $alturaLinha, pdf_util($doc), $alturaLinha, [232, 244, 236]);
        }

        $x = $doc['margem'];
        $negrito = !empty($linha['negrito']);

        foreach ($colunas as $i => $coluna) {
            $valor = (string)($linha['celulas'][$i] ?? '');
            $texto = pdf_encurtar($valor, $coluna['largura'] - 10, $tamanho, $negrito);
            $destino = ($coluna['alinhar'] ?? 'esquerda') === 'direita'
                ? $x + $coluna['largura'] - 5 - pdf_largura_texto($texto, $tamanho, $negrito)
                : $x + 5;
            pdf_texto($doc, $texto, $destino, $doc['y'] - $alturaLinha + 6, $tamanho, $negrito);
            $x += $coluna['largura'];
        }

        pdf_linha($doc, $doc['margem'], $doc['y'] - $alturaLinha, $doc['largura'] - $doc['margem'], $doc['y'] - $alturaLinha);
        $doc['y'] -= $alturaLinha;
    }

    $doc['y'] -= 10;
}

/** Reparte a largura útil em colunas, a partir de pesos relativos. */
function pdf_colunas(array $doc, array $definicao): array
{
    $peso = array_sum(array_column($definicao, 'peso'));
    $util = pdf_util($doc);
    $colunas = [];

    foreach ($definicao as $d) {
        $colunas[] = [
            'rotulo'  => $d['rotulo'],
            'largura' => $util * $d['peso'] / $peso,
            'alinhar' => $d['alinhar'] ?? 'esquerda',
        ];
    }

    return $colunas;
}

/* ===========================================================================
 * MONTAGEM DO ARQUIVO
 * ======================================================================== */

/**
 * Fecha o documento e devolve os bytes do PDF.
 *
 * O rodapé com "página X de Y" só pode ser escrito aqui: o total de páginas
 * não se sabe antes de terminar a última.
 */
function pdf_finalizar(array $doc): string
{
    if ($doc['atual'] !== '') {
        $doc['paginas'][] = $doc['atual'];
        $doc['atual'] = '';
    }

    if ($doc['paginas'] === []) {
        $doc['paginas'][] = '';
    }

    $total = count($doc['paginas']);

    foreach ($doc['paginas'] as $i => $conteudo) {
        $rodape = $doc['rodape'] !== '' ? $doc['rodape'] . '   ·   ' : '';
        $rodape .= sprintf('Página %d de %d', $i + 1, $total);

        $largura = pdf_largura_texto($rodape, 8.0);
        $conteudo .= sprintf(
            "BT /F1 8.0 Tf 0.514 0.588 0.706 rg %.2f %.2f Td (%s) Tj ET\n",
            ($doc['largura'] - $largura) / 2,
            $doc['margem'] / 2 + 6,
            pdf_escapar($rodape)
        );
        $doc['paginas'][$i] = $conteudo;
    }

    /* Objetos: 1 catálogo, 2 árvore de páginas, 3 e 4 as fontes, e depois
       dois por página (a página e o seu conteúdo). */
    $objetos = [];
    $primeiraPagina = 5;

    $idsPaginas = [];
    for ($i = 0; $i < $total; $i++) {
        $idsPaginas[] = $primeiraPagina + $i * 2;
    }

    $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objetos[2] = sprintf(
        "<< /Type /Pages /Count %d /Kids [%s] >>",
        $total,
        implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $idsPaginas))
    );
    $objetos[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objetos[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    foreach ($doc['paginas'] as $i => $conteudo) {
        $idPagina = $primeiraPagina + $i * 2;
        $idConteudo = $idPagina + 1;

        $objetos[$idPagina] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
            . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
            $doc['largura'],
            $doc['altura'],
            $idConteudo
        );

        $objetos[$idConteudo] = sprintf(
            "<< /Length %d >>\nstream\n%s\nendstream",
            strlen($conteudo),
            $conteudo
        );
    }

    ksort($objetos);

    $pdf = "%PDF-1.4\n";
    $posicoes = [];

    foreach ($objetos as $id => $corpo) {
        $posicoes[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $corpo . "\nendobj\n";
    }

    $inicioXref = strlen($pdf);
    $ultimo = max(array_keys($objetos));

    $pdf .= "xref\n0 " . ($ultimo + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($id = 1; $id <= $ultimo; $id++) {
        $pdf .= isset($posicoes[$id])
            ? sprintf("%010d 00000 n \n", $posicoes[$id])
            : "0000000000 65535 f \n";
    }

    $pdf .= sprintf(
        "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
        $ultimo + 1,
        $inicioXref
    );

    return $pdf;
}
