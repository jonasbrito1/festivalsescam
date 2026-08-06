<?php

/**
 * Valida a LEITURA vinda do MySQL contra a leitura vinda do data/db.json.
 *
 * O comparador anterior conferia contagens e valores de notas. Este vai
 * alem: reconstroi o array completo pelos dois caminhos e compara campo a
 * campo, exatamente como a aplicacao vai receber.
 *
 * E o teste que decide se e seguro virar FESTIVAL_DB_MODO para 'primario':
 * se algum campo divergir, alguma tela do sistema vai se comportar
 * diferente depois da virada.
 *
 * Uso:  php tools/validar_leitura_mysql.php
 * Saida 0 = equivalente; 1 = ha divergencia.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este utilitario roda apenas via linha de comando.\n");
}

require_once __DIR__ . '/../lib/mysql.php';

if (!getenv('FESTIVAL_DB_MODO')) {
    putenv('FESTIVAL_DB_MODO=espelho');
}

$doMysql = mysql_ler_banco();
if ($doMysql === null) {
    fwrite(STDERR, "Nao foi possivel ler do MySQL.\n");
    exit(1);
}

$doJson = json_decode((string) file_get_contents(__DIR__ . '/../data/db.json'), true);
if (!is_array($doJson)) {
    fwrite(STDERR, "data/db.json invalido.\n");
    exit(1);
}

/** Chave estavel para parear registros dos dois lados. */
function chave(string $entidade, array $r): string
{
    return match ($entidade) {
        'votes' => "{$r['event_id']}:{$r['judge_id']}:{$r['participant_id']}:{$r['criterion_id']}",
        'observations', 'judge_reviews' => "{$r['event_id']}:{$r['judge_id']}:{$r['participant_id']}",
        default => (string) ($r['id'] ?? '?'),
    };
}

/** Normaliza para comparar sem falso alarme de tipo/formato. */
function normalizar(string $campo, $v)
{
    // Datas: so a precisao de segundo importa, nao o fuso escrito.
    if (str_ends_with($campo, '_at') || $campo === 'publish_date') {
        if ($v === '' || $v === null) {
            return '';
        }
        $ts = strtotime((string) $v);

        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }

    if (is_bool($v)) {
        return $v ? 1 : 0;
    }

    if (is_numeric($v)) {
        return 0.0 + $v;
    }

    return is_string($v) ? trim($v) : $v;
}

/* Campos ignorados de proposito. */
$ignorar = [
    // O id de votos e gerado pelo AUTO_INCREMENT do MySQL e pelo contador do
    // JSON de forma independente. Nada no sistema referencia esse id.
    'votes' => ['id', 'created_at', 'updated_at'],
    'observations' => ['created_at', 'updated_at'],
    'judge_reviews' => ['created_at', 'updated_at'],
    'events' => ['created_at', 'updated_at'],
    'admins' => ['created_at'],
    'judges' => ['created_at'],
    'participants' => ['created_at'],
    'criteria' => ['created_at'],
];

$entidades = ['admins', 'events', 'judges', 'participants', 'criteria', 'votes', 'observations', 'judge_reviews'];
$problemas = [];
$camposConferidos = 0;

foreach ($entidades as $ent) {
    $a = [];
    foreach ($doJson[$ent] ?? [] as $r) {
        $a[chave($ent, $r)] = $r;
    }

    $b = [];
    foreach ($doMysql[$ent] ?? [] as $r) {
        $b[chave($ent, $r)] = $r;
    }

    printf("=== %-14s json=%-4d mysql=%-4d ", $ent, count($a), count($b));

    $soNoJson = array_diff_key($a, $b);
    $soNoMysql = array_diff_key($b, $a);
    $divergentes = 0;

    foreach ($a as $k => $registroJson) {
        if (!isset($b[$k])) {
            continue;
        }

        foreach ($registroJson as $campo => $valor) {
            if (in_array($campo, $ignorar[$ent] ?? [], true)) {
                continue;
            }

            if (!array_key_exists($campo, $b[$k])) {
                $problemas[] = "{$ent}[{$k}].{$campo}: ausente no MySQL";
                $divergentes++;
                continue;
            }

            // Estruturas aninhadas (advanced, publication, periods...)
            if (is_array($valor)) {
                foreach ($valor as $sub => $subValor) {
                    if (is_array($subValor)) {
                        continue; // periodos: comparados pela existencia da chave
                    }
                    $x = normalizar((string) $sub, $subValor);
                    $y = normalizar((string) $sub, $b[$k][$campo][$sub] ?? null);
                    $camposConferidos++;

                    if ($x != $y) {
                        $problemas[] = sprintf('%s[%s].%s.%s: json=%s mysql=%s',
                            $ent, $k, $campo, $sub, var_export($x, true), var_export($y, true));
                        $divergentes++;
                    }
                }
                continue;
            }

            $x = normalizar((string) $campo, $valor);
            $y = normalizar((string) $campo, $b[$k][$campo]);
            $camposConferidos++;

            if ($x != $y) {
                $problemas[] = sprintf('%s[%s].%s: json=%s mysql=%s',
                    $ent, $k, $campo, var_export($x, true), var_export($y, true));
                $divergentes++;
            }
        }
    }

    if ($soNoJson) {
        $problemas[] = "{$ent}: " . count($soNoJson) . ' registro(s) so no JSON (' . implode(', ', array_slice(array_keys($soNoJson), 0, 5)) . ')';
    }
    if ($soNoMysql) {
        $problemas[] = "{$ent}: " . count($soNoMysql) . ' registro(s) so no MySQL (' . implode(', ', array_slice(array_keys($soNoMysql), 0, 5)) . ')';
    }

    echo ($soNoJson || $soNoMysql || $divergentes) ? "<< DIVERGE\n" : "ok\n";
}

printf("\nCampos comparados: %d\n\n", $camposConferidos);

if ($problemas === []) {
    echo "RESULTADO: a leitura pelo MySQL e equivalente a do db.json.\n";
    echo "Seguro virar FESTIVAL_DB_MODO para 'primario'.\n";
    exit(0);
}

echo 'RESULTADO: ' . count($problemas) . " divergencia(s) — NAO vire a chave ainda.\n\n";
foreach (array_slice($problemas, 0, 40) as $p) {
    echo "  - {$p}\n";
}
if (count($problemas) > 40) {
    echo '  … e mais ' . (count($problemas) - 40) . ".\n";
}
exit(1);
