<?php

declare(strict_types=1);

const ROOT_DIR = __DIR__;
const DB_JSON_FILE = ROOT_DIR . '/data/db.json';
const CONFIG_FILE = ROOT_DIR . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

if (!extension_loaded('sqlsrv')) {
    fwrite(STDERR, "A extensao sqlsrv nao esta carregada no PHP CLI.\n");
    exit(1);
}

$fresh = in_array('--fresh', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$config = require CONFIG_FILE;
$db = load_json_database(DB_JSON_FILE);

$connectionInfo = [
    'Database' => $config['database'] ?? '',
    'UID' => $config['username'] ?? '',
    'PWD' => $config['password'] ?? '',
    'CharacterSet' => 'UTF-8',
];

foreach (($config['options'] ?? []) as $key => $value) {
    $connectionInfo[$key] = $value;
}

$conn = sqlsrv_connect($config['server'] ?? '', $connectionInfo);
if ($conn === false) {
    fail("Falha ao conectar no SQL Server.", sqlsrv_errors());
}

verify_required_tables($conn);

if ($dryRun) {
    echo "Conexao validada e tabelas obrigatorias encontradas.\n";
    echo "Resumo do JSON:\n";
    echo "- admins: " . count($db['admins'] ?? []) . "\n";
    echo "- events: " . count($db['events'] ?? []) . "\n";
    echo "- judges: " . count($db['judges'] ?? []) . "\n";
    echo "- participants: " . count($db['participants'] ?? []) . "\n";
    echo "- criteria: " . count($db['criteria'] ?? []) . "\n";
    echo "- votes: " . count($db['votes'] ?? []) . "\n";
    echo "- observations: " . count($db['observations'] ?? []) . "\n";
    echo "- judge_reviews: " . count($db['judge_reviews'] ?? []) . "\n";
    sqlsrv_close($conn);
    exit(0);
}

if (!sqlsrv_begin_transaction($conn)) {
    fail("Nao foi possivel iniciar a transacao.", sqlsrv_errors());
}

try {
    if ($fresh) {
        reset_application_tables($conn);
    }

    migrate_admins($conn, $db['admins'] ?? []);
    migrate_events($conn, $db['events'] ?? []);
    migrate_event_children($conn, $db['events'] ?? []);
    migrate_judges($conn, $db['judges'] ?? []);
    migrate_participants($conn, $db['participants'] ?? []);
    migrate_criteria($conn, $db['criteria'] ?? []);
    migrate_votes($conn, $db['votes'] ?? []);
    migrate_observations($conn, $db['observations'] ?? []);
    migrate_judge_reviews($conn, $db['judge_reviews'] ?? []);

    if (!sqlsrv_commit($conn)) {
        fail("Falha ao confirmar a transacao.", sqlsrv_errors());
    }

    echo "Migracao concluida com sucesso.\n";
    echo "Modo fresh: " . ($fresh ? 'sim' : 'nao') . "\n";
    echo "Eventos importados: " . count($db['events'] ?? []) . "\n";
    echo "Jurados importados: " . count($db['judges'] ?? []) . "\n";
    echo "Participantes importados: " . count($db['participants'] ?? []) . "\n";
    echo "Criterios importados: " . count($db['criteria'] ?? []) . "\n";
    echo "Votos importados: " . count($db['votes'] ?? []) . "\n";
    echo "Observacoes importadas: " . count($db['observations'] ?? []) . "\n";
    echo "Reviews importadas: " . count($db['judge_reviews'] ?? []) . "\n";
} catch (Throwable $e) {
    sqlsrv_rollback($conn);
    fwrite(STDERR, "Migracao cancelada: " . $e->getMessage() . "\n");
    sqlsrv_close($conn);
    exit(1);
}

sqlsrv_close($conn);

function load_json_database(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Arquivo JSON nao encontrado em {$path}.");
    }

    $content = file_get_contents($path);
    $db = json_decode($content ?: '{}', true);
    if (!is_array($db)) {
        throw new RuntimeException('Nao foi possivel ler o db.json.');
    }

    return $db;
}

function verify_required_tables($conn): void
{
    $required = [
        'admins',
        'events',
        'event_notifications',
        'event_periods',
        'event_publication',
        'event_advanced_settings',
        'event_phase_advancers',
        'judges',
        'participants',
        'criteria',
        'votes',
        'judge_observations',
        'judge_reviews',
    ];

    foreach ($required as $table) {
        $exists = scalar($conn, "SELECT COUNT(1) FROM sys.tables WHERE name = ?", [$table]);
        if ((int)$exists !== 1) {
            throw new RuntimeException("Tabela obrigatoria ausente no SQL Server: {$table}. Rode primeiro o schema base e o arquivo database_sql_server_complement.sql.");
        }
    }
}

function reset_application_tables($conn): void
{
    $tables = [
        'judge_reviews',
        'judge_observations',
        'votes',
        'criteria',
        'participants',
        'judges',
        'event_phase_advancers',
        'event_advanced_settings',
        'event_publication',
        'event_notifications',
        'event_periods',
        'events',
        'admins',
    ];

    foreach ($tables as $table) {
        exec_sql($conn, "DELETE FROM dbo.{$table}");
    }
}

function migrate_admins($conn, array $admins): void
{
    foreach ($admins as $admin) {
        upsert_identity_row(
            $conn,
            'admins',
            (int)$admin['id'],
            [
                'name' => (string)($admin['name'] ?? ''),
                'email' => (string)($admin['email'] ?? ''),
                'password_hash' => (string)($admin['password'] ?? ''),
                'created_at' => normalize_datetime($admin['created_at'] ?? null) ?? current_timestamp_string(),
            ]
        );
    }
}

function migrate_events($conn, array $events): void
{
    foreach ($events as $event) {
        $startDate = normalize_date($event['date'] ?? null) ?? date('Y-m-d');
        $endDate = normalize_date($event['end_date'] ?? null) ?? $startDate;
        $location = text_or_null($event['location'] ?? null);
        $description = text_or_null($event['description'] ?? null);

        upsert_identity_row(
            $conn,
            'events',
            (int)$event['id'],
            [
                'name' => (string)($event['name'] ?? ''),
                'description' => $description,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'location' => $location,
                'status' => normalize_event_status((string)($event['status'] ?? 'rascunho')),
                'event_format' => normalize_event_format((string)($event['event_format'] ?? 'unica')),
                'evaluation_minutes' => max(1, (int)($event['evaluation_minutes'] ?? 136)),
                'created_at' => normalize_datetime($event['created_at'] ?? null) ?? current_timestamp_string(),
                'updated_at' => normalize_datetime($event['updated_at'] ?? null),
            ]
        );
    }
}

function migrate_event_children($conn, array $events): void
{
    foreach ($events as $event) {
        $eventId = (int)$event['id'];
        $advanced = is_array($event['advanced'] ?? null) ? $event['advanced'] : [];
        $publication = is_array($event['publication'] ?? null) ? $event['publication'] : [];
        $notifications = is_array($event['notifications'] ?? null) ? $event['notifications'] : [];
        $periods = is_array($event['periods'] ?? null) ? $event['periods'] : [];
        $advancers = is_array($event['phase_advancers'] ?? null) ? $event['phase_advancers'] : [];

        upsert_keyed_row(
            $conn,
            'event_advanced_settings',
            ['event_id' => $eventId],
            [
                'allow_edit_after_submit' => to_bit($advanced['allow_edit_after_submit'] ?? false),
                'show_partial_average' => to_bit($advanced['show_partial_average'] ?? false),
                'tie_breaker' => (string)($advanced['tie_breaker'] ?? 'highest_weight'),
                'decimal_places' => max(0, min(3, (int)($advanced['decimal_places'] ?? 2))),
                'prevent_multi_login' => to_bit($advanced['prevent_multi_login'] ?? true),
            ]
        );

        upsert_keyed_row(
            $conn,
            'event_notifications',
            ['event_id' => $eventId],
            [
                'judge_open' => to_bit($notifications['judge_open'] ?? true),
                'judge_reminder' => to_bit($notifications['judge_reminder'] ?? true),
                'admin_complete' => to_bit($notifications['admin_complete'] ?? true),
                'participant_results' => to_bit($notifications['participant_results'] ?? true),
                'event_changes' => to_bit($notifications['event_changes'] ?? false),
            ]
        );

        upsert_keyed_row(
            $conn,
            'event_publication',
            ['event_id' => $eventId],
            [
                'auto_publish' => to_bit($publication['auto_publish'] ?? true),
                'publish_at' => normalize_datetime($publication['publish_date'] ?? null),
                'show_individual_scores' => to_bit($publication['show_individual'] ?? false),
                'show_judge_comments' => to_bit($publication['show_comments'] ?? false),
                'result_order' => normalize_publication_order((string)($publication['order'] ?? 'score_desc')),
            ]
        );

        if (($event['event_format'] ?? 'unica') === 'fases' || $advancers !== []) {
            upsert_keyed_row(
                $conn,
                'event_phase_advancers',
                ['event_id' => $eventId],
                [
                    'classificatoria_count' => max(0, (int)($advancers['classificatoria'] ?? 12)),
                    'semifinal_count' => max(0, (int)($advancers['semifinal'] ?? 6)),
                    'final_count' => max(0, (int)($advancers['final'] ?? 3)),
                ]
            );
        }

        foreach ($periods as $periodKey => $period) {
            if (!is_array($period)) {
                continue;
            }

            upsert_keyed_row(
                $conn,
                'event_periods',
                ['event_id' => $eventId, 'period_key' => (string)$periodKey],
                [
                    'name' => period_label((string)$periodKey),
                    'starts_at' => normalize_datetime($period['start'] ?? null),
                    'ends_at' => normalize_datetime($period['end'] ?? null),
                    'status' => normalize_period_status((string)($period['status'] ?? 'programado')),
                ]
            );
        }
    }
}

function migrate_judges($conn, array $judges): void
{
    foreach ($judges as $judge) {
        upsert_identity_row(
            $conn,
            'judges',
            (int)$judge['id'],
            [
                'event_id' => (int)$judge['event_id'],
                'name' => (string)($judge['name'] ?? ''),
                'username' => (string)($judge['username'] ?? ''),
                'password_hash' => (string)($judge['password'] ?? ''),
                'status' => normalize_active_status((string)($judge['status'] ?? 'ativo')),
                'created_at' => normalize_datetime($judge['created_at'] ?? null) ?? current_timestamp_string(),
            ]
        );
    }
}

function migrate_participants($conn, array $participants): void
{
    foreach ($participants as $participant) {
        upsert_identity_row(
            $conn,
            'participants',
            (int)$participant['id'],
            [
                'event_id' => (int)$participant['event_id'],
                'name' => (string)($participant['name'] ?? ''),
                'category' => text_or_null($participant['category'] ?? null),
                'song' => text_or_null($participant['song'] ?? null),
                'presentation_order' => (int)($participant['order'] ?? 0),
                'photo_url' => text_or_null($participant['photo'] ?? null),
                'status' => normalize_active_status((string)($participant['status'] ?? 'ativo')),
                'created_at' => normalize_datetime($participant['created_at'] ?? null) ?? current_timestamp_string(),
            ]
        );
    }
}

function migrate_criteria($conn, array $criteria): void
{
    $displayOrderByEvent = [];

    foreach ($criteria as $criterion) {
        $eventId = (int)$criterion['event_id'];
        $displayOrderByEvent[$eventId] = ($displayOrderByEvent[$eventId] ?? 0) + 1;

        upsert_identity_row(
            $conn,
            'criteria',
            (int)$criterion['id'],
            [
                'event_id' => $eventId,
                'name' => (string)($criterion['name'] ?? ''),
                'description' => text_or_null($criterion['description'] ?? null),
                'weight' => normalize_decimal($criterion['weight'] ?? 1),
                'display_order' => (int)($criterion['display_order'] ?? $displayOrderByEvent[$eventId]),
                'created_at' => normalize_datetime($criterion['created_at'] ?? null) ?? current_timestamp_string(),
            ]
        );
    }
}

function migrate_votes($conn, array $votes): void
{
    foreach ($votes as $vote) {
        upsert_identity_row(
            $conn,
            'votes',
            (int)$vote['id'],
            [
                'event_id' => (int)$vote['event_id'],
                'judge_id' => (int)$vote['judge_id'],
                'participant_id' => (int)$vote['participant_id'],
                'criterion_id' => (int)$vote['criterion_id'],
                'score' => normalize_decimal($vote['score'] ?? 0),
                'created_at' => normalize_datetime($vote['created_at'] ?? null) ?? current_timestamp_string(),
                'updated_at' => normalize_datetime($vote['updated_at'] ?? null),
            ]
        );
    }
}

function migrate_observations($conn, array $observations): void
{
    foreach ($observations as $observation) {
        upsert_keyed_row(
            $conn,
            'judge_observations',
            [
                'event_id' => (int)$observation['event_id'],
                'judge_id' => (int)$observation['judge_id'],
                'participant_id' => (int)$observation['participant_id'],
            ],
            [
                'observation' => text_or_null($observation['text'] ?? null),
                'updated_at' => normalize_datetime($observation['updated_at'] ?? null),
            ],
            [
                'created_at' => normalize_datetime($observation['created_at'] ?? null) ?? current_timestamp_string(),
            ]
        );
    }
}

function migrate_judge_reviews($conn, array $reviews): void
{
    foreach ($reviews as $review) {
        $signatureMode = normalize_signature_mode((string)($review['signature_mode'] ?? 'text'));
        $signatureText = text_or_null($review['signature_text'] ?? ($review['signature'] ?? null));
        $signatureTouch = text_or_null($review['signature_touch'] ?? null);

        upsert_keyed_row(
            $conn,
            'judge_reviews',
            [
                'event_id' => (int)$review['event_id'],
                'judge_id' => (int)$review['judge_id'],
                'participant_id' => (int)$review['participant_id'],
            ],
            [
                'checklist_done' => to_bit($review['checklist_done'] ?? false),
                'signature_mode' => $signatureMode,
                'signature_text' => $signatureText,
                'signature_touch' => $signatureTouch,
                'updated_at' => normalize_datetime($review['updated_at'] ?? null),
            ],
            [
                'created_at' => normalize_datetime($review['created_at'] ?? null) ?? current_timestamp_string(),
            ]
        );
    }
}

function upsert_identity_row($conn, string $table, int $id, array $data): void
{
    $exists = (int)scalar($conn, "SELECT COUNT(1) FROM dbo.{$table} WHERE id = ?", [$id]) > 0;
    $columns = array_keys($data);

    if ($exists) {
        $set = implode(', ', array_map(static fn(string $column): string => "{$column} = ?", $columns));
        $params = array_values($data);
        $params[] = $id;
        exec_sql($conn, "UPDATE dbo.{$table} SET {$set} WHERE id = ?", $params);
        return;
    }

    exec_sql($conn, "SET IDENTITY_INSERT dbo.{$table} ON");

    try {
        $insertColumns = array_merge(['id'], $columns);
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $params = array_merge([$id], array_values($data));
        exec_sql(
            $conn,
            "INSERT INTO dbo.{$table} (" . implode(', ', $insertColumns) . ") VALUES ({$placeholders})",
            $params
        );
    } finally {
        exec_sql($conn, "SET IDENTITY_INSERT dbo.{$table} OFF");
    }
}

function upsert_keyed_row($conn, string $table, array $keys, array $data, array $insertExtras = []): void
{
    $whereParts = [];
    $whereValues = [];
    foreach ($keys as $column => $value) {
        $whereParts[] = "{$column} = ?";
        $whereValues[] = $value;
    }

    $exists = (int)scalar($conn, "SELECT COUNT(1) FROM dbo.{$table} WHERE " . implode(' AND ', $whereParts), $whereValues) > 0;

    if ($exists) {
        $set = implode(', ', array_map(static fn(string $column): string => "{$column} = ?", array_keys($data)));
        $params = array_merge(array_values($data), $whereValues);
        exec_sql($conn, "UPDATE dbo.{$table} SET {$set} WHERE " . implode(' AND ', $whereParts), $params);
        return;
    }

    $insert = array_merge($keys, $insertExtras, $data);
    $columns = array_keys($insert);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    exec_sql(
        $conn,
        "INSERT INTO dbo.{$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})",
        array_values($insert)
    );
}

function exec_sql($conn, string $sql, array $params = []): void
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        fail("Falha ao executar SQL: {$sql}", sqlsrv_errors());
    }
    sqlsrv_free_stmt($stmt);
}

function scalar($conn, string $sql, array $params = [])
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        fail("Falha ao executar consulta escalar.", sqlsrv_errors());
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    return $row[0] ?? null;
}

function normalize_date($value): ?string
{
    $text = text_or_null($value);
    if ($text === null) {
        return null;
    }

    $timestamp = strtotime($text);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function normalize_datetime($value): ?string
{
    $text = text_or_null($value);
    if ($text === null) {
        return null;
    }

    $timestamp = strtotime($text);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

function normalize_decimal($value): string
{
    return number_format((float)$value, 1, '.', '');
}

function text_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }

    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function to_bit($value): int
{
    return !empty($value) ? 1 : 0;
}

function normalize_event_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['rascunho', 'aberto', 'encerrado'], true) ? $status : 'rascunho';
}

function normalize_event_format(string $format): string
{
    $format = strtolower(trim($format));
    return $format === 'fases' ? 'fases' : 'unica';
}

function normalize_active_status(string $status): string
{
    $status = strtolower(trim($status));
    return $status === 'inativo' ? 'inativo' : 'ativo';
}

function normalize_period_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['ativo', 'programado', 'encerrado'], true) ? $status : 'programado';
}

function normalize_publication_order(string $order): string
{
    $order = strtolower(trim($order));
    return $order === 'name' ? 'name' : 'score_desc';
}

function normalize_signature_mode(string $mode): string
{
    $mode = strtolower(trim($mode));
    return $mode === 'touch' ? 'touch' : 'text';
}

function current_timestamp_string(): string
{
    return date('Y-m-d H:i:s');
}

function period_label(string $periodKey): string
{
    return match ($periodKey) {
        'unica' => 'Etapa unica',
        'classificatoria' => 'Classificatoria',
        'semifinal' => 'Semifinal',
        'final' => 'Final',
        default => ucfirst(str_replace('_', ' ', $periodKey)),
    };
}

function fail(string $message, ?array $errors = null): never
{
    fwrite(STDERR, $message . "\n");
    if ($errors) {
        foreach ($errors as $error) {
            $sqlState = $error['SQLSTATE'] ?? 'n/a';
            $code = $error['code'] ?? 'n/a';
            $text = $error['message'] ?? '';
            fwrite(STDERR, "[{$sqlState}] {$code}: {$text}\n");
        }
    }
    exit(1);
}
