<?php

/**
 * Conferencia do espelhamento: compara data/db.json com o MySQL.
 *
 * Uso:
 *   php tools/comparar_json_mysql.php
 *
 * Rode durante a fase de espelho, especialmente depois de lancar notas e
 * mexer em cadastros. Enquanto houver divergencia, NAO vire a chave para
 * 'primario'.
 *
 * Codigo de saida 0 = tudo confere; 1 = ha divergencia.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este utilitario roda apenas via linha de comando.\n");
}

require_once __DIR__ . '/../lib/mysql.php';

if (!getenv('FESTIVAL_DB_MODO')) {
    putenv('FESTIVAL_DB_MODO=espelho');
}

$pdo = mysql_conexao();
if (!$pdo) {
    fwrite(STDERR, "Nao foi possivel conectar ao MySQL.\n");
    exit(1);
}

$db = json_decode((string) file_get_contents(__DIR__ . '/../data/db.json'), true) ?: [];

$divergencias = [];

/* ---------------------------------------------------------------------------
 * 1. Contagens
 * ------------------------------------------------------------------------- */
$mapa = [
    'admins'        => 'admins',
    'events'        => 'events',
    'judges'        => 'judges',
    'participants'  => 'participants',
    'criteria'      => 'criteria',
    'votes'         => 'votes',
    'observations'  => 'judge_observations',
    'judge_reviews' => 'judge_reviews',
];

echo "=== Contagens ===\n";
printf("  %-16s %8s %8s   %s\n", 'entidade', 'json', 'mysql', 'situacao');

foreach ($mapa as $chaveJson => $tabela) {
    $noJson = count($db[$chaveJson] ?? []);
    $noMysql = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabela}`")->fetchColumn();
    $ok = $noJson === $noMysql;

    if (!$ok) {
        $divergencias[] = "{$chaveJson}: json={$noJson} mysql={$noMysql}";
    }

    printf("  %-16s %8d %8d   %s\n", $chaveJson, $noJson, $noMysql, $ok ? 'ok' : '<< DIVERGE');
}

/* ---------------------------------------------------------------------------
 * 2. Notas, uma a uma
 *
 * A contagem bater nao garante que os valores batam. Aqui cada nota do JSON
 * e procurada no MySQL pela chave (evento, jurado, participante, criterio).
 * ------------------------------------------------------------------------- */
echo "\n=== Notas (valor a valor) ===\n";

$noMysql = [];
foreach ($pdo->query('SELECT event_id, judge_id, participant_id, criterion_id, score FROM votes') as $v) {
    $chave = "{$v['event_id']}:{$v['judge_id']}:{$v['participant_id']}:{$v['criterion_id']}";
    $noMysql[$chave] = (float) $v['score'];
}

$faltando = 0;
$diferentes = 0;
$conferidas = 0;

foreach ($db['votes'] ?? [] as $v) {
    $chave = ((int) $v['event_id']) . ':' . ((int) $v['judge_id']) . ':'
           . ((int) $v['participant_id']) . ':' . ((int) $v['criterion_id']);

    if (!array_key_exists($chave, $noMysql)) {
        if ($faltando < 10) {
            echo "  ausente no mysql: {$chave}\n";
        }
        $faltando++;
        continue;
    }

    if (abs($noMysql[$chave] - (float) $v['score']) > 0.001) {
        if ($diferentes < 10) {
            printf("  valor diferente: %s  json=%.1f  mysql=%.1f\n", $chave, (float) $v['score'], $noMysql[$chave]);
        }
        $diferentes++;
        continue;
    }

    $conferidas++;
}

$sobrando = count($noMysql) - $conferidas - $diferentes;

printf("  conferidas: %d   ausentes: %d   valor diferente: %d   sobrando no mysql: %d\n",
    $conferidas, $faltando, $diferentes, max(0, $sobrando));

if ($faltando || $diferentes || $sobrando > 0) {
    $divergencias[] = "notas: {$faltando} ausentes, {$diferentes} com valor diferente, {$sobrando} sobrando";
}

/* ---------------------------------------------------------------------------
 * 3. Ranking calculado pelos dois lados
 * ------------------------------------------------------------------------- */
echo "\n=== Ranking pela view vw_event_ranking ===\n";
$linhas = $pdo->query(
    'SELECT event_id, participant_name, judge_count, final_score
     FROM vw_event_ranking
     WHERE final_score IS NOT NULL
     ORDER BY event_id, final_score DESC
     LIMIT 10'
)->fetchAll();

if ($linhas === []) {
    echo "  (sem notas apuradas)\n";
} else {
    foreach ($linhas as $l) {
        printf("  evento %d | %-34s | jurados %2d | %s\n",
            $l['event_id'], mb_substr((string) $l['participant_name'], 0, 34),
            (int) $l['judge_count'], $l['final_score']);
    }
}

/* ---------------------------------------------------------------------------
 * Veredito
 * ------------------------------------------------------------------------- */
echo "\n";
if ($divergencias === []) {
    echo "RESULTADO: os dois lados conferem. Seguro virar para 'primario'.\n";
    exit(0);
}

echo "RESULTADO: HA DIVERGENCIA — nao vire a chave ainda.\n";
foreach ($divergencias as $d) {
    echo "  - {$d}\n";
}
exit(1);
