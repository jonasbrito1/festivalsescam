<?php

/**
 * Carga inicial: data/db.json -> MySQL.
 *
 * Uso:
 *   php tools/importar_json_mysql.php [--forcar]
 *
 * Sem --forcar, recusa rodar se o MySQL ja tiver dados, para nao
 * sobrescrever um banco em uso por engano.
 *
 * As variaveis FESTIVAL_DB_* precisam estar no ambiente. Em producao elas
 * vem do pool PHP-FPM; na linha de comando, passe na frente do comando.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este utilitario roda apenas via linha de comando.\n");
}

require_once __DIR__ . '/../lib/mysql.php';

// A carga precisa da conexao mesmo que o site ainda esteja em modo 'off'.
if (!getenv('FESTIVAL_DB_MODO')) {
    putenv('FESTIVAL_DB_MODO=espelho');
}

$forcar = in_array('--forcar', $argv, true);

$pdo = mysql_conexao();
if (!$pdo) {
    fwrite(STDERR, "Nao foi possivel conectar ao MySQL. Confira as variaveis FESTIVAL_DB_*.\n");
    exit(1);
}

$arquivo = __DIR__ . '/../data/db.json';
if (!is_readable($arquivo)) {
    fwrite(STDERR, "data/db.json nao encontrado.\n");
    exit(1);
}

$db = json_decode((string) file_get_contents($arquivo), true);
if (!is_array($db)) {
    fwrite(STDERR, "data/db.json invalido.\n");
    exit(1);
}

echo "Origem (db.json):\n";
foreach (['admins', 'events', 'judges', 'participants', 'criteria', 'votes', 'observations', 'judge_reviews'] as $k) {
    printf("  %-16s %d\n", $k, count($db[$k] ?? []));
}

$existente = (int) $pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();
if ($existente > 0 && !$forcar) {
    fwrite(STDERR, "\nO MySQL ja tem {$existente} votos. Use --forcar para sobrescrever.\n");
    exit(1);
}

echo "\nImportando…\n";

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();

    foreach (['judge_reviews', 'judge_observations', 'votes', 'criteria', 'participants',
              'judges', 'event_phase_advancers', 'event_advanced_settings',
              'event_publication', 'event_notifications', 'event_periods',
              'events', 'admins'] as $t) {
        $pdo->exec("DELETE FROM `{$t}`");
    }

    // Cadastros: reaproveita exatamente o mesmo codigo que o site usa para
    // espelhar, garantindo que carga e operacao sigam a mesma regra.
    mysql_sync_admins($pdo, $db['admins'] ?? []);
    mysql_sync_events($pdo, $db['events'] ?? []);
    mysql_sync_judges($pdo, $db['judges'] ?? []);
    mysql_sync_participants($pdo, $db['participants'] ?? []);
    mysql_sync_criteria($pdo, $db['criteria'] ?? []);

    // ---- Votos ----
    $criteriosValidos = [];
    foreach ($db['criteria'] ?? [] as $c) {
        $criteriosValidos[(int) $c['id']] = true;
    }
    $participantesValidos = [];
    foreach ($db['participants'] ?? [] as $p) {
        $participantesValidos[(int) $p['id']] = true;
    }
    $juradosValidos = [];
    foreach ($db['judges'] ?? [] as $j) {
        $juradosValidos[(int) $j['id']] = true;
    }

    $sqlVoto = $pdo->prepare(
        'INSERT INTO votes (event_id, judge_id, participant_id, criterion_id, score, created_at)
         VALUES (:evento, :jurado, :participante, :criterio, :nota, :criado)
         ON DUPLICATE KEY UPDATE score = VALUES(score)'
    );

    $votosOk = 0;
    $votosDescartados = [];

    foreach ($db['votes'] ?? [] as $v) {
        $jurado = (int) $v['judge_id'];
        $participante = (int) $v['participant_id'];
        $criterio = (int) $v['criterion_id'];
        $nota = (float) $v['score'];

        // Linhas orfas no JSON quebrariam as chaves estrangeiras.
        if (!isset($juradosValidos[$jurado], $participantesValidos[$participante], $criteriosValidos[$criterio])) {
            $votosDescartados[] = "voto id={$v['id']} referencia inexistente";
            continue;
        }

        if ($nota < 0 || $nota > 10) {
            $votosDescartados[] = "voto id={$v['id']} nota fora de 0-10 ({$nota})";
            continue;
        }

        $sqlVoto->execute([
            ':evento'       => (int) $v['event_id'],
            ':jurado'       => $jurado,
            ':participante' => $participante,
            ':criterio'     => $criterio,
            ':nota'         => round($nota, 1),
            ':criado'       => mysql_data($v['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
        $votosOk++;
    }

    // ---- Observacoes ----
    $sqlObs = $pdo->prepare(
        'INSERT INTO judge_observations (event_id, judge_id, participant_id, observation, created_at)
         VALUES (:evento, :jurado, :participante, :texto, :criado)
         ON DUPLICATE KEY UPDATE observation = VALUES(observation)'
    );
    $obsOk = 0;
    foreach ($db['observations'] ?? [] as $o) {
        if (!isset($juradosValidos[(int) $o['judge_id']], $participantesValidos[(int) $o['participant_id']])) {
            continue;
        }
        $sqlObs->execute([
            ':evento'       => (int) $o['event_id'],
            ':jurado'       => (int) $o['judge_id'],
            ':participante' => (int) $o['participant_id'],
            ':texto'        => mb_substr((string) ($o['text'] ?? ''), 0, 500),
            ':criado'       => mysql_data($o['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
        $obsOk++;
    }

    // ---- Fichas ----
    $sqlFicha = $pdo->prepare(
        'INSERT INTO judge_reviews
            (event_id, judge_id, participant_id, checklist_done, signature_mode,
             signature_text, signature_touch, created_at)
         VALUES (:evento, :jurado, :participante, :checklist, :modo, :texto, :traco, :criado)
         ON DUPLICATE KEY UPDATE checklist_done = VALUES(checklist_done)'
    );
    $fichaOk = 0;
    foreach ($db['judge_reviews'] ?? [] as $r) {
        if (!isset($juradosValidos[(int) $r['judge_id']], $participantesValidos[(int) $r['participant_id']])) {
            continue;
        }
        $modo = ($r['signature_mode'] ?? 'text') === 'touch' ? 'touch' : 'text';
        $traco = (string) ($r['signature_touch'] ?? '');

        $sqlFicha->execute([
            ':evento'       => (int) $r['event_id'],
            ':jurado'       => (int) $r['judge_id'],
            ':participante' => (int) $r['participant_id'],
            ':checklist'    => !empty($r['checklist_done']) ? 1 : 0,
            ':modo'         => $modo,
            ':texto'        => mb_substr((string) ($r['signature_text'] ?? ''), 0, 255),
            ':traco'        => $traco !== '' ? $traco : null,
            ':criado'       => mysql_data($r['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
        $fichaOk++;
    }

    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "\nImportado:\n";
    printf("  votos            %d\n", $votosOk);
    printf("  observacoes      %d\n", $obsOk);
    printf("  fichas           %d\n", $fichaOk);

    if ($votosDescartados !== []) {
        echo "\nDescartados (" . count($votosDescartados) . "):\n";
        foreach (array_slice($votosDescartados, 0, 20) as $d) {
            echo "  - {$d}\n";
        }
    }

    echo "\nContagem final no MySQL:\n";
    foreach (mysql_contar() as $t => $n) {
        printf("  %-20s %d\n", $t, $n);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, "\nFalha na importacao: " . $e->getMessage() . "\n");
    exit(1);
}
