<?php
const DATA_DIR = __DIR__ . '/data';
const SESSION_DIR = DATA_DIR . '/sessions';
const DB_FILE = DATA_DIR . '/db.json';
const PARTICIPANT_UPLOAD_DIR = __DIR__ . '/public/uploads/participants';

if (!is_dir(SESSION_DIR)) {
    mkdir(SESSION_DIR, 0775, true);
}

session_save_path(SESSION_DIR);
session_start();

function ensure_database(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    if (!file_exists(DB_FILE)) {
        /* [SEGURANCA] Era 'admin123', fixa no codigo — com o repositorio
         * publico, equivale a instalar ja com a senha divulgada. Agora e
         * sorteada e gravada em data/PRIMEIRO_ACESSO.txt (fora do Git). */
        $senhaInicial = bin2hex(random_bytes(6));
        $adminPassword = password_hash($senhaInicial, PASSWORD_DEFAULT);
        @file_put_contents(
            DATA_DIR . '/PRIMEIRO_ACESSO.txt',
            "Acesso inicial\n  E-mail: admin@festival.local\n  Senha:  $senhaInicial\n\n"
            . "Troque a senha no primeiro acesso e apague este arquivo.\n"
        );

        $initial = [
            'counters' => [
                'events' => 1,
                'criteria' => 1,
                'judges' => 1,
                'participants' => 1,
                'admins' => 2,
                'votes' => 1,
            ],
            'admins' => [
                [
                    'id' => 1,
                    'name' => 'Administrador',
                    'email' => 'admin@festival.local',
                    'password' => $adminPassword,
                    'created_at' => date('c'),
                ],
            ],
            'events' => [],
            'criteria' => [],
            'judges' => [],
            'participants' => [],
            'votes' => [],
            'observations' => [],
        ];

        file_put_contents(DB_FILE, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function db_read(): array
{
    ensure_database();
    $content = file_get_contents(DB_FILE);
    return json_decode($content ?: '{}', true) ?: [];
}

function db_write(array $db): void
{
    ensure_database();
    $handle = fopen(DB_FILE, 'c+');
    if (!$handle) {
        throw new RuntimeException('Nao foi possivel abrir o arquivo de dados.');
    }

    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function next_id(array &$db, string $table): int
{
    $id = (int)($db['counters'][$table] ?? 1);
    $db['counters'][$table] = $id + 1;
    return $id;
}

function redirect_to(string $page = 'dashboard', array $params = []): void
{
    $query = array_merge(['page' => $page], $params);
    header('Location: ?' . http_build_query($query));
    exit;
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function clean(string $value): string
{
    return trim(filter_var($value, FILTER_UNSAFE_RAW));
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $first = strtoupper(substr($parts[0] ?? 'P', 0, 1));
    $second = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $first . ($second ?: '');
}

function upload_participant_photo(int $participantId): string
{
    if (empty($_FILES['photo']['tmp_name']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        return clean($_POST['photo_url'] ?? '');
    }

    $extension = strtolower(pathinfo($_FILES['photo']['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return '';
    }

    if (!is_dir(PARTICIPANT_UPLOAD_DIR)) {
        mkdir(PARTICIPANT_UPLOAD_DIR, 0775, true);
    }

    $filename = 'participante-' . $participantId . '.' . $extension;
    $target = PARTICIPANT_UPLOAD_DIR . '/' . $filename;
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
        return 'public/uploads/participants/' . $filename;
    }

    return '';
}

function participant_photo_html(array $participant, string $class = ''): string
{
    $photo = $participant['photo'] ?? '';
    if ($photo !== '') {
        return '<img class="participant-photo ' . h($class) . '" src="' . h($photo) . '" alt="Foto de ' . h($participant['name'] ?? 'participante') . '">';
    }

    return '<span class="participant-photo placeholder ' . h($class) . '">' . h(initials($participant['name'] ?? 'P')) . '</span>';
}

function evaluation_seconds_from_event(?array $event): int
{
    $minutes = (int)($event['evaluation_minutes'] ?? 136);
    return max(1, $minutes) * 60;
}

function active_evaluation_period(?array $event): bool
{
    $periods = $event['periods'] ?? [];
    if (!$periods) {
        return true;
    }

    $now = time();
    foreach ($periods as $period) {
        if (($period['status'] ?? '') !== 'ativo') {
            continue;
        }

        $start = !empty($period['start']) ? strtotime($period['start']) : null;
        $end = !empty($period['end']) ? strtotime($period['end']) : null;
        if (($start === null || $now >= $start) && ($end === null || $now <= $end)) {
            return true;
        }
    }

    return false;
}

function results_are_public(?array $event): bool
{
    $publication = $event['publication'] ?? [];
    if (!($publication['auto_publish'] ?? true)) {
        return false;
    }

    if (!empty($publication['publish_date'])) {
        $publishAt = strtotime($publication['publish_date']);
        if ($publishAt && time() < $publishAt) {
            return false;
        }
    }

    return true;
}

function is_json_request(): bool
{
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strtolower($requestedWith) === 'xmlhttprequest' || str_contains(strtolower($accept), 'application/json');
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function database_status(): array
{
    $configPath = __DIR__ . '/config/database.php';
    if (!file_exists($configPath)) {
        return ['state' => 'offline', 'label' => 'Banco nao configurado'];
    }

    $config = require $configPath;
    if (($config['driver'] ?? '') !== 'sqlsrv' || !function_exists('sqlsrv_connect')) {
        return ['state' => 'offline', 'label' => 'Driver SQL Server indisponivel'];
    }

    $connection = @sqlsrv_connect($config['server'] ?? '', [
        'Database' => $config['database'] ?? '',
        'UID' => $config['username'] ?? '',
        'PWD' => $config['password'] ?? '',
        'Encrypt' => false,
        'TrustServerCertificate' => true,
        'LoginTimeout' => 2,
    ]);

    if ($connection) {
        sqlsrv_close($connection);
        return ['state' => 'online', 'label' => 'Banco de dados conectado'];
    }

    return ['state' => 'warning', 'label' => 'Modo local ativo / integracao configurada'];
}

function is_admin(): bool
{
    return isset($_SESSION['admin_id']);
}

function is_judge(): bool
{
    return isset($_SESSION['judge_id']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect_to('admin-login');
    }
}

function require_judge(): void
{
    if (!is_judge()) {
        redirect_to('judge-login');
    }
}

function find_by_id(array $items, int $id): ?array
{
    foreach ($items as $item) {
        if ((int)$item['id'] === $id) {
            return $item;
        }
    }

    return null;
}

function event_options(array $db): array
{
    $events = $db['events'] ?? [];
    usort($events, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    return $events;
}

function active_event_id(array $db): ?int
{
    if (isset($_GET['event_id'])) {
        return (int)$_GET['event_id'];
    }

    $events = event_options($db);
    return $events ? (int)$events[0]['id'] : null;
}

function items_for_event(array $items, int $eventId): array
{
    return array_values(array_filter($items, fn($item) => (int)$item['event_id'] === $eventId));
}

function ranking_for_event(array $db, int $eventId): array
{
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $votes = items_for_event($db['votes'] ?? [], $eventId);
    $criteriaById = [];
    foreach ($criteria as $criterion) {
        $criteriaById[(int)$criterion['id']] = max((float)$criterion['weight'], 0.1);
    }

    $ranking = [];
    foreach ($participants as $participant) {
        $participantVotes = array_values(array_filter(
            $votes,
            fn($vote) => (int)$vote['participant_id'] === (int)$participant['id']
        ));

        $judgeScores = [];
        foreach ($participantVotes as $vote) {
            $judgeId = (int)$vote['judge_id'];
            $criterionId = (int)$vote['criterion_id'];
            $weight = $criteriaById[$criterionId] ?? 1;
            $judgeScores[$judgeId]['points'] = ($judgeScores[$judgeId]['points'] ?? 0) + ((float)$vote['score'] * $weight);
            $judgeScores[$judgeId]['weights'] = ($judgeScores[$judgeId]['weights'] ?? 0) + $weight;
        }

        $total = 0;
        $judgeCount = 0;
        foreach ($judgeScores as $score) {
            if (($score['weights'] ?? 0) > 0) {
                $total += $score['points'] / $score['weights'];
                $judgeCount++;
            }
        }

        $ranking[] = [
            'participant' => $participant,
            'score' => $judgeCount > 0 ? $total / $judgeCount : 0,
            'judge_count' => $judgeCount,
        ];
    }

    usort($ranking, fn($a, $b) => $b['score'] <=> $a['score']);
    return $ranking;
}

function observation_for(array $db, int $eventId, int $judgeId, int $participantId): ?array
{
    foreach (($db['observations'] ?? []) as $observation) {
        if (
            (int)$observation['event_id'] === $eventId &&
            (int)$observation['judge_id'] === $judgeId &&
            (int)$observation['participant_id'] === $participantId
        ) {
            return $observation;
        }
    }

    return null;
}

function event_vote_count(array $db, int $eventId): int
{
    return count(items_for_event($db['votes'] ?? [], $eventId));
}

function judge_progress_for_event(array $db, int $eventId): array
{
    $judges = items_for_event($db['judges'] ?? [], $eventId);
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $votes = items_for_event($db['votes'] ?? [], $eventId);
    $participantsTotal = count($participants);
    $criteriaTotal = count($criteria);
    $progress = [];

    foreach ($judges as $judge) {
        $judgeVotes = array_values(array_filter($votes, fn($vote) => (int)$vote['judge_id'] === (int)$judge['id']));
        $lastVoteAt = '';

        foreach ($judgeVotes as $vote) {
            if (($vote['created_at'] ?? '') > $lastVoteAt) {
                $lastVoteAt = $vote['created_at'];
            }
        }

        $evaluatedParticipants = 0;
        if ($criteriaTotal > 0) {
            foreach ($participants as $participant) {
                $participantVoteCount = count(array_filter(
                    $judgeVotes,
                    fn($vote) => (int)$vote['participant_id'] === (int)$participant['id']
                ));
                if ($participantVoteCount >= $criteriaTotal) {
                    $evaluatedParticipants++;
                }
            }
        }

        $progress[] = [
            'judge' => $judge,
            'notes_count' => count($judgeVotes),
            'participants_done' => $evaluatedParticipants,
            'participants_total' => $participantsTotal,
            'pending' => max(0, $participantsTotal - $evaluatedParticipants),
            'last_vote_at' => $lastVoteAt,
        ];
    }

    return $progress;
}

function detailed_votes_for_event(array $db, int $eventId): array
{
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    $judges = items_for_event($db['judges'] ?? [], $eventId);
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $ranking = ranking_for_event($db, $eventId);
    $scoreByParticipant = [];
    foreach ($ranking as $row) {
        $scoreByParticipant[(int)$row['participant']['id']] = (float)$row['score'];
    }

    $criteriaById = [];
    foreach ($criteria as $criterion) {
        $criteriaById[(int)$criterion['id']] = $criterion;
    }

    $votesByJudgeParticipant = [];
    foreach (items_for_event($db['votes'] ?? [], $eventId) as $vote) {
        $judgeParticipantKey = (int)$vote['judge_id'] . ':' . (int)$vote['participant_id'];
        $votesByJudgeParticipant[$judgeParticipantKey][] = $vote;
    }

    $rows = [];
    foreach ($participants as $participant) {
        foreach ($judges as $judge) {
            $judgeParticipantKey = (int)$judge['id'] . ':' . (int)$participant['id'];
            $observation = observation_for($db, $eventId, (int)$judge['id'], (int)$participant['id']);
            $judgeVotes = $votesByJudgeParticipant[$judgeParticipantKey] ?? [];
            $weightedPoints = 0.0;
            $weights = 0.0;

            foreach ($judgeVotes as $vote) {
                $criterion = $criteriaById[(int)$vote['criterion_id']] ?? null;
                $weight = max((float)($criterion['weight'] ?? 1), 0.1);
                $weightedPoints += (float)$vote['score'] * $weight;
                $weights += $weight;
            }

            $judgeAverage = $weights > 0 ? $weightedPoints / $weights : null;

            foreach ($criteria as $criterion) {
                $criterionVote = null;
                foreach ($judgeVotes as $vote) {
                    if ((int)$vote['criterion_id'] === (int)$criterion['id']) {
                        $criterionVote = $vote;
                        break;
                    }
                }

                $rows[] = [
                    'participant' => $participant,
                    'judge' => $judge,
                    'criterion' => $criterion,
                    'vote' => $criterionVote,
                    'judge_average' => $judgeAverage,
                    'participant_average' => $scoreByParticipant[(int)$participant['id']] ?? 0,
                    'observation' => $observation['text'] ?? '',
                    'observation_updated_at' => $observation['updated_at'] ?? '',
                ];
            }
        }
    }

    usort($rows, function ($a, $b) {
        $orderCompare = ((int)($a['participant']['order'] ?? 0)) <=> ((int)($b['participant']['order'] ?? 0));
        if ($orderCompare !== 0) {
            return $orderCompare;
        }

        $judgeCompare = strcmp($a['judge']['name'] ?? '', $b['judge']['name'] ?? '');
        if ($judgeCompare !== 0) {
            return $judgeCompare;
        }

        return strcmp($a['criterion']['name'] ?? '', $b['criterion']['name'] ?? '');
    });

    return $rows;
}

function event_completion_stats(array $db, int $eventId): array
{
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    $judges = items_for_event($db['judges'] ?? [], $eventId);
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $votes = items_for_event($db['votes'] ?? [], $eventId);

    $participantCount = count($participants);
    $judgeCount = count($judges);
    $criteriaCount = count($criteria);
    $expectedVotes = $participantCount * $judgeCount * $criteriaCount;
    $voteCount = count($votes);
    $completed = $expectedVotes > 0 ? min(100, (int)round(($voteCount / $expectedVotes) * 100)) : 0;

    $participantJudgePairs = $participantCount * $judgeCount;
    $pairsByCompletion = [];
    if ($criteriaCount > 0) {
        foreach ($judges as $judge) {
            foreach ($participants as $participant) {
                $count = count(array_filter(
                    $votes,
                    fn($vote) => (int)$vote['judge_id'] === (int)$judge['id'] && (int)$vote['participant_id'] === (int)$participant['id']
                ));
                if ($count >= $criteriaCount) {
                    $pairsByCompletion['completed'] = ($pairsByCompletion['completed'] ?? 0) + 1;
                } elseif ($count > 0) {
                    $pairsByCompletion['in_progress'] = ($pairsByCompletion['in_progress'] ?? 0) + 1;
                } else {
                    $pairsByCompletion['pending'] = ($pairsByCompletion['pending'] ?? 0) + 1;
                }
            }
        }
    }

    $completedPairs = (int)($pairsByCompletion['completed'] ?? 0);
    $inProgressPairs = (int)($pairsByCompletion['in_progress'] ?? 0);
    $pendingPairs = max(0, $participantJudgePairs - $completedPairs - $inProgressPairs);

    return [
        'expected_votes' => $expectedVotes,
        'votes_count' => $voteCount,
        'completed_percent' => $completed,
        'completed_pairs' => $completedPairs,
        'in_progress_pairs' => $inProgressPairs,
        'pending_pairs' => $pendingPairs,
        'participants_active' => count(array_filter($participants, fn($participant) => !empty($participant['name']))),
        'judges_active' => $judgeCount,
    ];
}

function admin_recent_activities(array $db, int $eventId): array
{
    $event = find_by_id($db['events'] ?? [], $eventId);
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    $judges = items_for_event($db['judges'] ?? [], $eventId);
    $votes = items_for_event($db['votes'] ?? [], $eventId);
    $activities = [];

    foreach ($votes as $vote) {
        $judge = find_by_id($judges, (int)$vote['judge_id']);
        $participant = find_by_id($participants, (int)$vote['participant_id']);
        $activities[] = [
            'type' => 'vote',
            'icon' => 'AV',
            'title' => ($judge['name'] ?? 'Jurado') . ' enviou notas para ' . ($participant['name'] ?? 'participante'),
            'time' => $vote['created_at'] ?? '',
        ];
    }

    foreach ($participants as $participant) {
        $activities[] = [
            'type' => 'participant',
            'icon' => 'PA',
            'title' => 'Novo participante inscrito: ' . ($participant['name'] ?? 'Participante'),
            'time' => $participant['created_at'] ?? '',
        ];
    }

    foreach ($judges as $judge) {
        $activities[] = [
            'type' => 'judge',
            'icon' => 'JU',
            'title' => 'Jurado cadastrado: ' . ($judge['name'] ?? 'Jurado'),
            'time' => $judge['created_at'] ?? '',
        ];
    }

    if ($event) {
        $activities[] = [
            'type' => 'event',
            'icon' => 'EV',
            'title' => 'Evento em foco: ' . ($event['name'] ?? 'Evento'),
            'time' => $event['created_at'] ?? '',
        ];
    }

    usort($activities, fn($a, $b) => strcmp($b['time'] ?? '', $a['time'] ?? ''));
    return array_slice($activities, 0, 6);
}

function relative_time_label(?string $isoDate): string
{
    if (!$isoDate) {
        return 'Agora mesmo';
    }

    $timestamp = strtotime($isoDate);
    if (!$timestamp) {
        return 'Agora mesmo';
    }

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) {
        return 'Ha poucos segundos';
    }
    if ($diff < 3600) {
        return 'Ha ' . max(1, (int)floor($diff / 60)) . ' minuto(s)';
    }
    if ($diff < 86400) {
        return 'Ha ' . max(1, (int)floor($diff / 3600)) . ' hora(s)';
    }

    return 'Ha ' . max(1, (int)floor($diff / 86400)) . ' dia(s)';
}

function handle_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $db = db_read();
    $action = $_POST['action'] ?? '';

    if ($action === 'admin_login') {
        $email = strtolower(clean($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        foreach ($db['admins'] ?? [] as $admin) {
            if (strtolower($admin['email']) === $email && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                redirect_to('dashboard');
            }
        }
        flash('E-mail ou senha invalidos.', 'error');
        redirect_to('admin-login');
    }

    if ($action === 'judge_login') {
        $username = strtolower(clean($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        foreach ($db['judges'] ?? [] as $judge) {
            if (strtolower($judge['username']) === $username && password_verify($password, $judge['password'])) {
                $_SESSION['judge_id'] = $judge['id'];
                $_SESSION['judge_name'] = $judge['name'];
                $_SESSION['judge_event_id'] = $judge['event_id'];
                $judgeEvent = find_by_id($db['events'] ?? [], (int)$judge['event_id']);
                $_SESSION['judge_deadlines'][$judge['event_id']] = $_SESSION['judge_deadlines'][$judge['event_id']] ?? (time() + evaluation_seconds_from_event($judgeEvent));
                redirect_to('judge-panel');
            }
        }
        flash('Acesso do jurado nao encontrado.', 'error');
        redirect_to('judge-login');
    }

    if ($action === 'logout') {
        session_destroy();
        redirect_to('admin-login');
    }

    if ($action === 'create_event') {
        require_admin();
        $eventId = next_id($db, 'events');
        $db['events'][] = [
            'id' => $eventId,
            'name' => clean($_POST['name'] ?? ''),
            'date' => clean($_POST['date'] ?? ''),
            'status' => clean($_POST['status'] ?? 'rascunho'),
            'description' => clean($_POST['description'] ?? ''),
            'evaluation_minutes' => 136,
            'event_format' => clean($_POST['event_format'] ?? 'unica'),
            'periods' => (($_POST['event_format'] ?? 'unica') === 'fases') ? [
                'classificatoria' => ['start' => clean($_POST['class_start'] ?? ''), 'end' => clean($_POST['class_end'] ?? ''), 'status' => 'ativo'],
                'semifinal' => ['start' => clean($_POST['semi_start'] ?? ''), 'end' => clean($_POST['semi_end'] ?? ''), 'status' => 'programado'],
                'final' => ['start' => clean($_POST['final_start'] ?? ''), 'end' => clean($_POST['final_end'] ?? ''), 'status' => 'programado'],
            ] : [],
            'created_at' => date('c'),
        ];

        $defaultCriteria = ['Afinacao', 'Interpretacao', 'Presenca de palco'];
        foreach ($defaultCriteria as $criterionName) {
            $db['criteria'][] = [
                'id' => next_id($db, 'criteria'),
                'event_id' => $eventId,
                'name' => $criterionName,
                'weight' => 1,
            ];
        }

        db_write($db);
        flash('Evento criado com criterios padrao.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'jurados']);
    }

    if ($action === 'create_criterion') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $db['criteria'][] = [
            'id' => next_id($db, 'criteria'),
            'event_id' => $eventId,
            'name' => clean($_POST['name'] ?? ''),
            'weight' => max((float)($_POST['weight'] ?? 1), 0.1),
        ];
        db_write($db);
        flash('Criterio adicionado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'criterios']);
    }

    if ($action === 'create_judge') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $db['judges'][] = [
            'id' => next_id($db, 'judges'),
            'event_id' => $eventId,
            'name' => clean($_POST['name'] ?? ''),
            'username' => strtolower(clean($_POST['username'] ?? '')),
            'password' => password_hash((string)($_POST['password'] ?? '123456'), PASSWORD_DEFAULT),
            'created_at' => date('c'),
        ];
        db_write($db);
        flash('Jurado cadastrado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'jurados']);
    }

    if ($action === 'create_participant') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $participantId = next_id($db, 'participants');
        $db['participants'][] = [
            'id' => $participantId,
            'event_id' => $eventId,
            'name' => clean($_POST['name'] ?? ''),
            'category' => clean($_POST['category'] ?? ''),
            'song' => clean($_POST['song'] ?? ''),
            'order' => (int)($_POST['order'] ?? 0),
            'photo' => upload_participant_photo($participantId),
            'created_at' => date('c'),
        ];
        db_write($db);
        flash('Participante cadastrado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'participantes']);
    }

    if ($action === 'create_admin') {
        require_admin();
        $db['admins'][] = [
            'id' => next_id($db, 'admins'),
            'name' => clean($_POST['name'] ?? ''),
            'email' => strtolower(clean($_POST['email'] ?? '')),
            'password' => password_hash((string)($_POST['password'] ?? bin2hex(random_bytes(6))), PASSWORD_DEFAULT),
            'created_at' => date('c'),
        ];
        db_write($db);
        flash('Administrador cadastrado.');
        redirect_to('dashboard', ['event_id' => active_event_id($db), 'section' => 'apuracao']);
    }

    if ($action === 'update_event_config') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $configTab = clean($_POST['config_tab'] ?? 'gerais');
        $db['events'] = $db['events'] ?? [];
        foreach ($db['events'] as &$event) {
            if ((int)$event['id'] === $eventId) {
                if ($configTab === 'gerais') {
                    $event['name'] = clean($_POST['name'] ?? $event['name']);
                    $event['description'] = clean($_POST['description'] ?? $event['description']);
                    $event['date'] = clean($_POST['date'] ?? $event['date']);
                    $event['end_date'] = clean($_POST['end_date'] ?? ($event['end_date'] ?? $event['date']));
                    $event['location'] = clean($_POST['location'] ?? ($event['location'] ?? 'Teatro Sesc Centro'));
                    $event['status'] = clean($_POST['status'] ?? $event['status']);
                    $event['evaluation_minutes'] = max(1, (int)($_POST['evaluation_minutes'] ?? ($event['evaluation_minutes'] ?? 136)));
                    $event['event_format'] = clean($_POST['event_format'] ?? ($event['event_format'] ?? 'unica'));
                }

                if ($configTab === 'periodos') {
                    $event['event_format'] = clean($_POST['period_mode'] ?? ($event['event_format'] ?? 'unica'));
                    if ($event['event_format'] === 'fases') {
                        $event['periods'] = [
                            'classificatoria' => ['start' => clean($_POST['class_start'] ?? ''), 'end' => clean($_POST['class_end'] ?? ''), 'status' => clean($_POST['class_status'] ?? 'ativo')],
                            'semifinal' => ['start' => clean($_POST['semi_start'] ?? ''), 'end' => clean($_POST['semi_end'] ?? ''), 'status' => clean($_POST['semi_status'] ?? 'programado')],
                            'final' => ['start' => clean($_POST['final_start'] ?? ''), 'end' => clean($_POST['final_end'] ?? ''), 'status' => clean($_POST['final_status'] ?? 'programado')],
                        ];
                    } else {
                        $event['periods'] = [
                            'unica' => ['start' => clean($_POST['single_start'] ?? ''), 'end' => clean($_POST['single_end'] ?? ''), 'status' => 'ativo'],
                        ];
                    }
                }

                if ($configTab === 'notificacoes') {
                    $event['notifications'] = [
                        'judge_open' => isset($_POST['judge_open']),
                        'judge_reminder' => isset($_POST['judge_reminder']),
                        'admin_complete' => isset($_POST['admin_complete']),
                        'participant_results' => isset($_POST['participant_results']),
                        'event_changes' => isset($_POST['event_changes']),
                    ];
                }

                if ($configTab === 'publicacao') {
                    $event['publication'] = [
                        'auto_publish' => isset($_POST['auto_publish']),
                        'publish_date' => clean($_POST['publish_date'] ?? ''),
                        'show_individual' => isset($_POST['show_individual']),
                        'show_comments' => isset($_POST['show_comments']),
                        'order' => clean($_POST['publication_order'] ?? 'score_desc'),
                    ];
                }

                if ($configTab === 'outras') {
                    $event['advanced'] = [
                        'allow_edit_after_submit' => isset($_POST['allow_edit_after_submit']),
                        'show_partial_average' => isset($_POST['show_partial_average']),
                        'tie_breaker' => clean($_POST['tie_breaker'] ?? 'highest_weight'),
                        'decimal_places' => max(0, min(3, (int)($_POST['decimal_places'] ?? 2))),
                        'prevent_multi_login' => isset($_POST['prevent_multi_login']),
                    ];
                }
                break;
            }
        }
        unset($event);
        db_write($db);
        flash('Configuracoes salvas.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'configuracoes', 'config_tab' => $configTab]);
    }

    if ($action === 'save_votes') {
        require_judge();
        $eventId = (int)$_SESSION['judge_event_id'];
        $judgeId = (int)$_SESSION['judge_id'];
        $participantId = (int)$_POST['participant_id'];
        $scores = $_POST['scores'] ?? [];
        $observationText = clean((string)($_POST['observation'] ?? ''));

        if (!empty($_SESSION['judge_finished'][$eventId]) || time() >= (int)($_SESSION['judge_deadlines'][$eventId] ?? PHP_INT_MAX)) {
            if (is_json_request()) {
                json_response(['ok' => false, 'message' => 'O tempo de avaliacao foi encerrado.', 'redirect' => '?page=judge-panel&section=resumo'], 409);
            }
            flash('O tempo de avaliacao foi encerrado.', 'error');
            redirect_to('judge-panel', ['section' => 'resumo']);
        }

        if (!active_evaluation_period(find_by_id($db['events'] ?? [], $eventId))) {
            if (is_json_request()) {
                json_response(['ok' => false, 'message' => 'Nao ha periodo de avaliacao ativo no momento.', 'redirect' => '?page=judge-panel&section=participantes'], 409);
            }
            flash('Nao ha periodo de avaliacao ativo no momento.', 'error');
            redirect_to('judge-panel', ['section' => 'participantes']);
        }

        $db['votes'] = array_values(array_filter($db['votes'] ?? [], function ($vote) use ($eventId, $judgeId, $participantId, $scores) {
            return !(
                (int)$vote['event_id'] === $eventId &&
                (int)$vote['judge_id'] === $judgeId &&
                (int)$vote['participant_id'] === $participantId &&
                isset($scores[(string)$vote['criterion_id']])
            );
        }));

        foreach ($scores as $criterionId => $score) {
            $db['votes'][] = [
                'id' => next_id($db, 'votes'),
                'event_id' => $eventId,
                'judge_id' => $judgeId,
                'participant_id' => $participantId,
                'criterion_id' => (int)$criterionId,
                'score' => min(max((float)$score, 0), 10),
                'created_at' => date('c'),
            ];
        }

        $db['observations'] = array_values(array_filter($db['observations'] ?? [], function ($observation) use ($eventId, $judgeId, $participantId) {
            return !(
                (int)$observation['event_id'] === $eventId &&
                (int)$observation['judge_id'] === $judgeId &&
                (int)$observation['participant_id'] === $participantId
            );
        }));

        if ($observationText !== '') {
            $db['observations'][] = [
                'event_id' => $eventId,
                'judge_id' => $judgeId,
                'participant_id' => $participantId,
                'text' => $observationText,
                'updated_at' => date('c'),
            ];
        }

        db_write($db);
        if (is_json_request()) {
            json_response([
                'ok' => true,
                'message' => 'Notas salvas.',
                'redirect' => '?page=judge-panel&participant_id=' . $participantId . '&section=votacao',
            ]);
        }
        flash('Notas salvas.');
        redirect_to('judge-panel', ['participant_id' => $participantId]);
    }

    if ($action === 'finalize_evaluation') {
        require_judge();
        $eventId = (int)$_SESSION['judge_event_id'];
        $_SESSION['judge_finished'][$eventId] = true;
        $_SESSION['judge_deadlines'][$eventId] = time();
        flash('Avaliacoes finalizadas.');
        redirect_to('judge-panel', ['section' => 'resumo']);
    }
}

function render_header(string $title): void
{
    $flash = flash();
    $page = $_GET['page'] ?? 'home';
    $bodyClass = 'page-' . preg_replace('/[^a-z0-9-]/', '', strtolower($page));
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> | Festival de Calouros</title>
        <meta name="theme-color" content="#17389d">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Festival Jurados">
        <meta name="description" content="Sistema de notas de jurados preparado para tablets, instalacao PWA e empacotamento Android.">
        <link rel="manifest" href="manifest.webmanifest">
        <link rel="icon" type="image/png" sizes="192x192" href="public/assets/icons/icon-192.png">
        <link rel="apple-touch-icon" href="public/assets/icons/icon-192.png">
        <link rel="stylesheet" href="public/assets/css/app.css">
    </head>
    <body class="<?= h($bodyClass) ?>">
        <header class="topbar">
            <a class="brand" href="?page=home">
                <span class="brand-mark">FC</span>
                <span>Festival de Calouros</span>
            </a>
            <nav>
                <a href="?page=ranking">Ranking</a>
                <?php if (is_admin()): ?>
                    <a href="?page=dashboard">Admin</a>
                <?php else: ?>
                    <a href="?page=admin-login">Administrador</a>
                <?php endif; ?>
                <?php if (is_judge()): ?>
                    <a href="?page=judge-panel">Votacao</a>
                <?php else: ?>
                    <a href="?page=judge-login">Jurado</a>
                <?php endif; ?>
            </nav>
        </header>
        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>
        <main>
    <?php
}

function render_footer(): void
{
    ?>
        </main>
        <div class="pwa-banner" data-pwa-banner hidden>
            <div>
                <strong>Instalar aplicativo</strong>
                <span>Adicione esta versao a tela inicial do tablet para usar como app.</span>
            </div>
            <div class="pwa-banner-actions">
                <button class="button primary" type="button" data-pwa-install>Instalar</button>
                <button class="button ghost" type="button" data-pwa-dismiss>Agora nao</button>
            </div>
        </div>
        <div class="network-banner" data-network-banner hidden></div>
        <script src="public/assets/js/app.js"></script>
    </body>
    </html>
    <?php
}

function render_login(string $type): void
{
    $dbStatus = database_status();
    $defaultPanel = $type === 'judge' ? 'judge' : 'admin';
    render_header('Login');
    ?>
    <section class="sesc-login apk-login-screen">
        <div class="apk-login-layout" data-default-login-panel="<?= h($defaultPanel) ?>">
            <aside class="apk-login-brand">
                <div>
                    <div class="sesc-logo"><span>Sesc</span></div>
                    <p class="apk-brand-title">Sistema de Notas de Jurados</p>
                    <span class="gold-line"></span>
                </div>
                <div class="apk-brand-copy">
                    <div class="apk-brand-icon">CO</div>
                    <p>Cuidar de <strong>pessoas</strong><br>transforma vidas</p>
                </div>
            </aside>

            <div class="apk-login-main">
                <header class="apk-login-head">
                    <h1>Bem-vindo!</h1>
                    <p>Faca login para acessar o sistema</p>
                </header>

                <div class="apk-login-divider">
                    <span></span>
                    <strong>Selecione o seu perfil</strong>
                    <span></span>
                </div>

                <section class="apk-profile-grid">
                    <article class="apk-profile-card">
                        <div class="apk-profile-icon admin">AD</div>
                        <h2>Administrador</h2>
                        <p>Acesse o painel administrativo e gerencie o evento.</p>
                        <button class="button primary apk-card-button" type="button" data-open-login-panel="admin">Entrar como Administrador</button>
                    </article>

                    <article class="apk-profile-card judge">
                        <div class="apk-profile-icon judge">JU</div>
                        <h2>Participante / Jurado</h2>
                        <p>Acesse o sistema para avaliar os participantes.</p>
                        <button class="button gold apk-card-button" type="button" data-open-login-panel="judge">Entrar como Jurado</button>
                    </article>
                </section>

                <section class="apk-login-panels">
                    <form class="apk-login-panel form-stack" method="post" data-login-panel="admin" hidden>
                        <div class="apk-panel-head">
                            <div>
                                <strong>Administrador</strong>
                                <small>Use seu e-mail e senha para acessar o painel.</small>
                            </div>
                            <button type="button" class="button ghost small" data-close-login-panel>Voltar</button>
                        </div>
                        <input type="hidden" name="action" value="admin_login">
                        <label>Usuario
                            <input required name="email" type="email" placeholder="admin@festival.local">
                        </label>
                        <label>Senha
                            <input required name="password" type="password" placeholder="Digite sua senha">
                        </label>
                        <div class="login-row">
                            <label class="remember"><input type="checkbox"> Lembrar-me</label>
                            <span class="muted">Procure a organizacao se precisar redefinir a senha.</span>
                        </div>
                        <button class="button primary" type="submit">Entrar como Administrador</button>
                    </form>

                    <form class="apk-login-panel form-stack" method="post" data-login-panel="judge" hidden>
                        <div class="apk-panel-head">
                            <div>
                                <strong>Participante / Jurado</strong>
                                <small>Entre com o usuario cadastrado para votar.</small>
                            </div>
                            <button type="button" class="button ghost small" data-close-login-panel>Voltar</button>
                        </div>
                        <input type="hidden" name="action" value="judge_login">
                        <label>Usuario ou E-mail
                            <input required name="username" type="text" placeholder="jurado01">
                        </label>
                        <label>Senha
                            <input required name="password" type="password" placeholder="Digite sua senha">
                        </label>
                        <div class="login-row">
                            <label class="remember"><input type="checkbox"> Lembrar-me</label>
                            <span class="muted">Em caso de bloqueio, fale com a organizacao do evento.</span>
                        </div>
                        <button class="button gold" type="submit">Entrar como Jurado</button>
                    </form>
                </section>

                <footer class="apk-login-footer">
                    <div class="apk-help-box">
                        <div class="apk-help-icon">?</div>
                        <div>
                            <strong>Precisa de ajuda?</strong>
                            <p>Entre em contato com a organizacao do evento.</p>
                        </div>
                    </div>
                    <div class="db-status-card <?= h($dbStatus['state']) ?>">
                        <strong>Status da integracao</strong>
                        <span><?= h($dbStatus['label']) ?></span>
                    </div>
                </footer>
            </div>
        </div>
    </section>
    <?php
    render_footer();
}
function render_login_home(): void
{
    render_login('home');
}

function render_home(): void
{
    render_header('Inicio');
    ?>
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow">Sistema de avaliacao</p>
            <h1>Festival de Calouros</h1>
            <p>Cadastre eventos, distribua jurados, organize participantes e acompanhe o ranking em tempo real.</p>
            <div class="actions">
                <a class="button primary" href="?page=admin-login">Painel do administrador</a>
                <a class="button ghost" href="?page=judge-login">Entrada do jurado</a>
            </div>
        </div>
        <div class="score-preview">
            <span>Nota media</span>
            <strong>9.7</strong>
            <small>Ranking atualizado conforme os jurados votam</small>
        </div>
    </section>
    <?php
    render_footer();
}

function render_dashboard(): void
{
    require_admin();
    $db = db_read();
    $eventId = active_event_id($db);
    $section = $_GET['section'] ?? 'painel';
    $event = $eventId ? find_by_id($db['events'] ?? [], $eventId) : null;
    $events = event_options($db);
    $criteria = $eventId ? items_for_event($db['criteria'] ?? [], $eventId) : [];
    $judges = $eventId ? items_for_event($db['judges'] ?? [], $eventId) : [];
    $participants = $eventId ? items_for_event($db['participants'] ?? [], $eventId) : [];
    $ranking = $eventId ? ranking_for_event($db, $eventId) : [];
    $voteCount = $eventId ? event_vote_count($db, $eventId) : 0;
    $judgeProgress = $eventId ? judge_progress_for_event($db, $eventId) : [];
    $detailedRows = $eventId ? detailed_votes_for_event($db, $eventId) : [];
    $completionStats = $eventId ? event_completion_stats($db, $eventId) : [];
    $recentActivities = $eventId ? admin_recent_activities($db, $eventId) : [];

    render_header('Painel do Administrador');
    ?>
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <div class="sidebar-logo">
                <div class="sesc-logo small"><span>Sesc</span></div>
                <small>Sistema de Notas de Jurados</small>
            </div>
            <nav class="admin-menu" aria-label="Menu do administrador">
                <a class="<?= $section === 'painel' ? 'active' : '' ?>" href="?page=dashboard&section=painel<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>IN</span>Painel Principal</a>
                <a class="<?= $section === 'eventos' ? 'active' : '' ?>" href="?page=dashboard&section=eventos<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>EV</span>Eventos</a>
                <a class="<?= $section === 'jurados' ? 'active' : '' ?>" href="?page=dashboard&section=jurados<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>JU</span>Jurados</a>
                <a class="<?= $section === 'participantes' ? 'active' : '' ?>" href="?page=dashboard&section=participantes<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>PA</span>Participantes</a>
                <a class="<?= $section === 'criterios' ? 'active' : '' ?>" href="?page=dashboard&section=criterios<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>CR</span>Criterios</a>
                <a class="<?= $section === 'apuracao' ? 'active' : '' ?>" href="?page=dashboard&section=apuracao<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>AP</span>Apuracao</a>
                <a class="<?= $section === 'relatorios' ? 'active' : '' ?>" href="?page=dashboard&section=relatorios<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>RE</span>Relatorios</a>
                <a class="<?= $section === 'placar' ? 'active' : '' ?>" href="?page=dashboard&section=placar<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>PL</span>Placar em Tempo Real</a>
                <a class="<?= $section === 'exportar' ? 'active' : '' ?>" href="?page=dashboard&section=exportar<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>PDF</span>Exportar Notas</a>
                <a class="<?= $section === 'configuracoes' ? 'active' : '' ?>" href="?page=dashboard&section=configuracoes<?= $eventId ? '&event_id=' . $eventId : '' ?>"><span>CF</span>Configuracoes do Evento</a>
            </nav>
            <a class="admin-help-link" href="?page=dashboard&section=relatorios">Ajuda</a>
            <form method="post" class="sidebar-logout">
                <input type="hidden" name="action" value="logout">
                <button type="submit"><span>SA</span>Sair</button>
            </form>
        </aside>

        <section class="admin-content">
            <header class="admin-top">
                <div>
                    <h1>Ola, Administrador</h1>
                    <p>Bem-vindo ao painel administrativo</p>
                </div>
                <div class="admin-profile">
                    <a class="top-icon-button" href="?page=dashboard&section=relatorios" aria-label="Ver relatorios">RE</a>
                    <a class="top-icon-button" href="?page=dashboard&section=configuracoes" aria-label="Abrir configuracoes">CF</a>
                    <div><strong>Administrador</strong><small>Nivel: Administrador</small></div>
                </div>
            </header>

            <div class="admin-inner">

    <?php if ($section === 'painel'): ?>
        <section class="apk-admin-dashboard">
            <div class="section-title compact apk-admin-headline">
                <div>
                    <h2>Visao Geral do Evento</h2>
                    <p class="muted"><?= $event ? 'Acompanhe o andamento do evento selecionado.' : 'Selecione um evento para iniciar a administracao.' ?></p>
                </div>
                <div class="actions">
                    <form class="inline-form" method="get">
                        <input type="hidden" name="page" value="dashboard">
                        <input type="hidden" name="section" value="painel">
                        <select name="event_id" onchange="this.form.submit()">
                            <option value="">Selecione um evento</option>
                            <?php foreach ($events as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $eventId === (int)$item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a class="button primary" href="?page=dashboard&section=eventos#novo-evento">Criar Evento</a>
                </div>
            </div>

            <?php if ($event): ?>
                <section class="apk-admin-stats">
                    <article class="apk-stat-card featured">
                        <span class="metric-icon blue">EV</span>
                        <div>
                            <small>Evento em andamento</small>
                            <strong><?= h($event['name']) ?></strong>
                            <em class="status-pill <?= h($event['status']) ?>"><?= h($event['status']) ?></em>
                        </div>
                    </article>
                    <article class="apk-stat-card">
                        <span class="metric-icon green">PA</span>
                        <div>
                            <small>Participantes</small>
                            <strong><?= count($participants) ?></strong>
                            <em>Ativos: <?= (int)($completionStats['participants_active'] ?? 0) ?></em>
                        </div>
                    </article>
                    <article class="apk-stat-card">
                        <span class="metric-icon gold">JU</span>
                        <div>
                            <small>Jurados</small>
                            <strong><?= count($judges) ?></strong>
                            <em>Ativos: <?= (int)($completionStats['judges_active'] ?? 0) ?></em>
                        </div>
                    </article>
                    <article class="apk-stat-card progress">
                        <span class="metric-icon purple">AV</span>
                        <div>
                            <small>Avaliacoes</small>
                            <strong><?= (int)($completionStats['completed_percent'] ?? 0) ?>%</strong>
                            <em><?= (int)($completionStats['votes_count'] ?? 0) ?> de <?= (int)($completionStats['expected_votes'] ?? 0) ?> notas</em>
                            <div class="mini-progress"><span style="width: <?= (int)($completionStats['completed_percent'] ?? 0) ?>%"></span></div>
                        </div>
                    </article>
                </section>

                <section class="apk-admin-grid">
                    <article class="panel apk-progress-panel">
                        <div class="panel-title-row">
                            <h3>Progresso das Avaliacoes</h3>
                            <a href="?page=dashboard&section=apuracao&event_id=<?= $eventId ?>">Ver apuracao parcial</a>
                        </div>
                        <div class="apk-progress-wrap">
                            <div class="apk-progress-ring">
                                <strong><?= (int)($completionStats['completed_percent'] ?? 0) ?>%</strong>
                                <span>Concluidas</span>
                            </div>
                            <div class="apk-progress-legend">
                                <p><span class="dot blue"></span><strong>Concluidas</strong><em><?= (int)($completionStats['completed_pairs'] ?? 0) ?></em></p>
                                <p><span class="dot gold"></span><strong>Em andamento</strong><em><?= (int)($completionStats['in_progress_pairs'] ?? 0) ?></em></p>
                                <p><span class="dot soft"></span><strong>Pendentes</strong><em><?= (int)($completionStats['pending_pairs'] ?? 0) ?></em></p>
                            </div>
                        </div>
                    </article>

                    <article class="panel apk-activity-panel">
                        <div class="panel-title-row">
                            <h3>Atividades Recentes</h3>
                            <a href="?page=dashboard&section=relatorios&event_id=<?= $eventId ?>">Ver todas</a>
                        </div>
                        <div class="apk-activity-list">
                            <?php foreach (array_slice($recentActivities, 0, 5) as $activity): ?>
                                <div class="apk-activity-item">
                                    <span class="apk-activity-icon"><?= h($activity['icon'] ?? 'AT') ?></span>
                                    <div>
                                        <strong><?= h($activity['title'] ?? 'Atualizacao do evento') ?></strong>
                                        <small><?= h(relative_time_label($activity['time'] ?? '')) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$recentActivities): ?>
                                <div class="apk-empty-inline">Nenhuma atividade recente registrada neste evento.</div>
                            <?php endif; ?>
                        </div>
                    </article>
                </section>

                <section class="apk-quick-access">
                    <h3>Acesso Rapido</h3>
                    <div class="apk-quick-grid">
                        <a class="apk-quick-card blue" href="?page=dashboard&section=eventos&event_id=<?= $eventId ?>"><span>EV</span><strong>Eventos</strong><small>Gerencie seus eventos</small></a>
                        <a class="apk-quick-card green" href="?page=dashboard&section=jurados&event_id=<?= $eventId ?>"><span>JU</span><strong>Jurados</strong><small>Gerencie os jurados</small></a>
                        <a class="apk-quick-card gold" href="?page=dashboard&section=participantes&event_id=<?= $eventId ?>"><span>PA</span><strong>Participantes</strong><small>Veja os participantes</small></a>
                        <a class="apk-quick-card purple" href="?page=dashboard&section=criterios&event_id=<?= $eventId ?>"><span>CR</span><strong>Criterios</strong><small>Gerencie os criterios</small></a>
                        <a class="apk-quick-card sky" href="?page=dashboard&section=apuracao&event_id=<?= $eventId ?>"><span>AP</span><strong>Apuracao</strong><small>Acompanhe os resultados</small></a>
                        <a class="apk-quick-card gray" href="?page=dashboard&section=configuracoes&event_id=<?= $eventId ?>"><span>CF</span><strong>Configuracoes</strong><small>Ajuste o evento</small></a>
                    </div>
                </section>
            <?php else: ?>
                <section class="panel empty-center">
                    <strong>Nenhum evento selecionado.</strong>
                    <small>Crie um evento ou escolha um dos eventos cadastrados para abrir o painel.</small>
                    <a class="button primary" href="?page=dashboard&section=eventos#novo-evento">Criar Evento</a>
                </section>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($section === 'eventos'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Eventos</h2>
                <div class="management-actions">
                    <input type="search" placeholder="Buscar evento...">
                    <a class="button primary" href="#novo-evento">+ Criar Evento</a>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Nome do Evento</th><th>Data</th><th>Local</th><th>Status</th><th>Acoes</th></tr></thead>
                        <tbody>
                        <?php if (!$events): ?>
                            <tr><td colspan="5">Nenhum evento cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($events as $item): ?>
                            <tr>
                                <td><a class="table-link" href="?page=dashboard&section=eventos&event_id=<?= (int)$item['id'] ?>"><?= h($item['name']) ?></a></td>
                                <td><?= h($item['date']) ?></td>
                                <td><?= h($item['description'] ?: 'Sesc Centro') ?></td>
                                <td><span class="status-pill <?= h($item['status']) ?>"><?= h($item['status']) ?></span></td>
                                <td><div class="table-actions"><a class="icon-link" href="?page=dashboard&section=painel&event_id=<?= (int)$item['id'] ?>">Abrir</a><a class="icon-link" href="?page=dashboard&section=configuracoes&event_id=<?= (int)$item['id'] ?>">Configurar</a><a class="icon-link" href="?page=dashboard&section=relatorios&event_id=<?= (int)$item['id'] ?>">Relatorios</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <form id="novo-evento" class="panel form-stack compact-form event-create-form" method="post">
                <h2>Novo evento</h2>
                <input type="hidden" name="action" value="create_event">
                <label>Nome do evento <input required name="name" placeholder="Festival de Musica Sesc 2024"></label>
                <label>Data <input required name="date" type="date"></label>
                <label>Status
                    <select name="status">
                        <option value="rascunho">Rascunho</option>
                        <option value="aberto">Em andamento</option>
                        <option value="encerrado">Encerrado</option>
                    </select>
                </label>
                <label>Local / descricao <textarea name="description" rows="3"></textarea></label>
                <label>Formato do evento
                    <select name="event_format" data-phase-toggle>
                        <option value="unica">Etapa unica</option>
                        <option value="fases">Classificatoria, semifinal e final</option>
                    </select>
                </label>
                <div class="phase-fields" hidden>
                    <h3>Fases do evento</h3>
                    <label>Classificatoria - Inicio <input name="class_start" type="datetime-local"></label>
                    <label>Classificatoria - Fim <input name="class_end" type="datetime-local"></label>
                    <label>Semifinal - Inicio <input name="semi_start" type="datetime-local"></label>
                    <label>Semifinal - Fim <input name="semi_end" type="datetime-local"></label>
                    <label>Final - Inicio <input name="final_start" type="datetime-local"></label>
                    <label>Final - Fim <input name="final_end" type="datetime-local"></label>
                </div>
                <button class="button primary" type="submit">Salvar Evento</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if (!$event && !in_array($section, ['eventos', 'painel'], true)): ?>
        <section class="panel empty-state">
            <h2>Crie ou selecione um evento primeiro</h2>
            <a class="button primary" href="?page=dashboard&section=eventos">Ir para eventos</a>
        </section>
    <?php endif; ?>

    <?php if ($event && false): ?>
        <section class="section-title compact">
            <div>
                <p class="eyebrow">Evento selecionado</p>
                <h2><?= h($event['name']) ?></h2>
            </div>
            <a class="button ghost" href="?page=ranking&event_id=<?= $eventId ?>">Ver ranking publico</a>
        </section>

        <section class="stats-grid">
            <div class="stat"><strong><?= count($participants) ?></strong><span>Participantes</span></div>
            <div class="stat"><strong><?= count($judges) ?></strong><span>Jurados</span></div>
            <div class="stat"><strong><?= count($criteria) ?></strong><span>Criterios</span></div>
            <div class="stat"><strong><?= count($db['admins'] ?? []) ?></strong><span>Administradores</span></div>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'jurados'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Jurados</h2>
                <div class="management-actions">
                    <input type="search" placeholder="Buscar jurado...">
                    <a class="button primary" href="#novo-jurado">+ Adicionar Jurado</a>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Nome</th><th>E-mail</th><th>Evento</th><th>Status</th><th>Acoes</th></tr></thead>
                        <tbody>
                        <?php if (!$judges): ?>
                            <tr><td colspan="5">Nenhum jurado cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($judges as $judge): ?>
                            <tr>
                                <td><?= h($judge['name']) ?></td>
                                <td><?= h($judge['username']) ?></td>
                                <td><?= h($event['name']) ?></td>
                                <td><span class="status-pill ativo">Ativo</span></td>
                                <td><div class="table-actions"><a class="icon-link" href="?page=dashboard&section=relatorios&event_id=<?= $eventId ?>">Relatorio</a><a class="icon-link" href="?page=judge-login">Acesso</a><a class="icon-link" href="?page=dashboard&section=configuracoes&event_id=<?= $eventId ?>&config_tab=notificacoes">Avisos</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <form id="novo-jurado" class="panel form-stack compact-form" method="post">
                <h2>Novo jurado</h2>
                <input type="hidden" name="action" value="create_judge">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name"></label>
                <label>Usuario <input required name="username"></label>
                <label>Senha <input required name="password" type="password"></label>
                <button class="button primary" type="submit">Cadastrar jurado</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'participantes'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Participantes</h2>
                <div class="management-actions">
                    <input type="search" placeholder="Buscar participante...">
                    <a class="button primary" href="#novo-participante">+ Adicionar Participante</a>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Nome / Grupo</th><th>Categoria</th><th>Ordem</th><th>Evento</th><th>Status</th><th>Acoes</th></tr></thead>
                        <tbody>
                        <?php if (!$participants): ?>
                            <tr><td colspan="6">Nenhum participante cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td><span class="participant-name-cell"><?= participant_photo_html($participant, 'thumb') ?><?= h($participant['name']) ?></span></td>
                                <td><?= h($participant['category']) ?></td>
                                <td><?= str_pad((string)(int)$participant['order'], 2, '0', STR_PAD_LEFT) ?></td>
                                <td><?= h($event['name']) ?></td>
                                <td><span class="status-pill ativo">Ativo</span></td>
                                <td><div class="table-actions"><a class="icon-link" href="?page=dashboard&section=apuracao&event_id=<?= $eventId ?>">Avaliacoes</a><a class="icon-link" href="?page=dashboard&section=relatorios&event_id=<?= $eventId ?>">Notas</a><a class="icon-link" href="?page=dashboard&section=participantes&event_id=<?= $eventId ?>#novo-participante">Cadastro</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <form id="novo-participante" class="panel form-stack compact-form" method="post" enctype="multipart/form-data">
                <h2>Novo participante</h2>
                <input type="hidden" name="action" value="create_participant">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name"></label>
                <label>Categoria <input name="category" placeholder="Solo, grupo, instrumental"></label>
                <label>Musica <input name="song"></label>
                <label>Ordem <input name="order" type="number" min="0"></label>
                <label>Foto do participante <input name="photo" type="file" accept="image/png,image/jpeg,image/webp"></label>
                <label>Ou URL da foto <input name="photo_url" type="url" placeholder="https://..."></label>
                <button class="button primary" type="submit">Cadastrar participante</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'criterios'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Criterios</h2>
                <div class="management-actions">
                    <form class="inline-form" method="get">
                        <input type="hidden" name="page" value="dashboard">
                        <input type="hidden" name="section" value="criterios">
                        <select name="event_id" onchange="this.form.submit()">
                            <?php foreach ($events as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $eventId === (int)$item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a class="button primary" href="#novo-criterio">+ Novo Criterio</a>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Ordem</th><th>Criterio</th><th>Peso (%)</th><th>Descricao</th><th>Acoes</th></tr></thead>
                        <tbody>
                        <?php foreach ($criteria as $index => $criterion): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= h($criterion['name']) ?></td>
                                <td><?= number_format((float)$criterion['weight'] * 20, 0, ',', '.') ?>%</td>
                                <td>Avaliacao do participante neste criterio.</td>
                                <td><div class="table-actions"><a class="icon-link" href="?page=dashboard&section=configuracoes&event_id=<?= $eventId ?>&config_tab=pesos">Pesos</a><a class="icon-link" href="?page=dashboard&section=apuracao&event_id=<?= $eventId ?>">Resumo</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="2">Total dos Pesos</td><td><?= count($criteria) ? '100%' : '0%' ?></td><td colspan="2"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="info-note">A soma dos pesos dos criterios deve ser igual a 100%.</div>
            <form id="novo-criterio" class="panel form-stack compact-form" method="post">
                <h2>Novo criterio</h2>
                <input type="hidden" name="action" value="create_criterion">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name" placeholder="Originalidade"></label>
                <label>Peso <input required name="weight" type="number" min="0.1" step="0.1" value="1"></label>
                <button class="button primary" type="submit">Adicionar criterio</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'apuracao'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Apuracao</h2>
                <div class="management-actions">
                    <a class="button ghost" href="?page=ranking&event_id=<?= $eventId ?>">Visualizar Notas</a>
                    <a class="button primary" href="?page=ranking&event_id=<?= $eventId ?>">Gerar Resultado</a>
                </div>
            </div>
            <section class="grid two apuracao-grid">
                <div class="panel data-panel">
                    <?= render_ranking_table($ranking) ?>
                </div>
                <div class="panel score-summary">
                    <h2>Resumo da Apuracao</h2>
                    <p><span>Participantes</span><strong><?= count($participants) ?></strong></p>
                    <p><span>Jurados</span><strong><?= count($judges) ?></strong></p>
                    <p><span>Criterios</span><strong><?= count($criteria) ?></strong></p>
                    <p><span>Notas lancadas</span><strong><?= $voteCount ?></strong></p>
                </div>
            </section>
            <div class="info-note">O resultado sera exibido aos participantes somente apos a finalizacao da apuracao.</div>
            <form class="panel form-stack compact-form" method="post">
                <h2>Novo administrador</h2>
                <input type="hidden" name="action" value="create_admin">
                <label>Nome <input required name="name"></label>
                <label>E-mail <input required name="email" type="email"></label>
                <label>Senha <input required name="password" type="password"></label>
                <button class="button primary" type="submit">Cadastrar administrador</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'relatorios'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Relatorios</h2>
                <div class="management-actions">
                    <a class="button ghost" href="?page=dashboard&section=exportar&event_id=<?= $eventId ?>">Exportar PDF</a>
                    <a class="button primary" href="?page=dashboard&section=placar&event_id=<?= $eventId ?>">Abrir Placar</a>
                </div>
            </div>
            <section class="report-grid">
                <div class="panel report-card"><span><?= count($participants) ?></span><strong>Participantes inscritos</strong><small>Total no evento selecionado</small></div>
                <div class="panel report-card"><span><?= count($judges) ?></span><strong>Jurados cadastrados</strong><small>Equipe de avaliacao</small></div>
                <div class="panel report-card"><span><?= $voteCount ?></span><strong>Notas lancadas</strong><small>Registros salvos neste evento</small></div>
                <div class="panel report-card"><span><?= $ranking ? number_format((float)$ranking[0]['score'], 1, ',', '.') : '-' ?></span><strong>Maior media</strong><small>Melhor resultado parcial</small></div>
            </section>
            <div class="panel data-panel">
                <?= render_ranking_table($ranking) ?>
            </div>
            <div class="panel data-panel">
                <div class="management-head compact">
                    <h3>Acompanhamento dos jurados</h3>
                </div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Jurado</th><th>Participantes avaliados</th><th>Notas lancadas</th><th>Pendentes</th><th>Ultima atualizacao</th></tr></thead>
                        <tbody>
                        <?php if (!$judgeProgress): ?>
                            <tr><td colspan="5">Nenhum jurado cadastrado para este evento.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($judgeProgress as $row): ?>
                            <tr>
                                <td><?= h($row['judge']['name']) ?></td>
                                <td><?= (int)$row['participants_done'] ?> / <?= (int)$row['participants_total'] ?></td>
                                <td><?= (int)$row['notes_count'] ?></td>
                                <td><?= (int)$row['pending'] ?></td>
                                <td><?= $row['last_vote_at'] ? h(date('d/m/Y H:i', strtotime($row['last_vote_at']))) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="management-head compact">
                    <h3>Relatorio detalhado de notas</h3>
                </div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Participante</th><th>Jurado</th><th>Criterio</th><th>Nota</th><th>Media do jurado</th><th>Media do participante</th><th>Observacao</th><th>Atualizado em</th></tr></thead>
                        <tbody>
                        <?php if (!$detailedRows): ?>
                            <tr><td colspan="8">Ainda nao existem notas detalhadas para este evento.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($detailedRows as $row): ?>
                            <tr>
                                <td><?= h($row['participant']['name']) ?></td>
                                <td><?= h($row['judge']['name']) ?></td>
                                <td><?= h($row['criterion']['name']) ?></td>
                                <td><?= isset($row['vote']['score']) ? number_format((float)$row['vote']['score'], 1, ',', '.') : '-' ?></td>
                                <td><?= $row['judge_average'] !== null ? number_format((float)$row['judge_average'], 2, ',', '.') : '-' ?></td>
                                <td><?= number_format((float)$row['participant_average'], 2, ',', '.') ?></td>
                                <td><?= $row['observation'] !== '' ? h($row['observation']) : '-' ?></td>
                                <td>
                                    <?php
                                    $updatedAt = $row['vote']['created_at'] ?? $row['observation_updated_at'] ?? '';
                                    echo $updatedAt ? h(date('d/m/Y H:i', strtotime($updatedAt))) : '-';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'placar'): ?>
        <section class="live-scoreboard" data-refresh-seconds="20">
            <div class="scoreboard-title">
                <div>
                    <span>Placar em tempo real</span>
                    <h2><?= h($event['name']) ?></h2>
                </div>
                <a class="button ghost" href="?page=dashboard&section=placar&event_id=<?= $eventId ?>">Atualizar</a>
            </div>
            <div class="scoreboard-list">
                <?php foreach ($ranking as $index => $row): ?>
                    <div class="scoreboard-row">
                        <strong><?= $index + 1 ?>Âº</strong>
                        <span><?= h($row['participant']['name']) ?></span>
                        <em><?= number_format((float)$row['score'], 2, ',', '.') ?></em>
                    </div>
                <?php endforeach; ?>
                <?php if (!$ranking): ?>
                    <div class="scoreboard-row"><strong>-</strong><span>Sem notas lancadas ainda</span><em>0,00</em></div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'exportar'): ?>
        <section class="management-page export-page">
            <div class="management-head no-print">
                <h2>Exportar notas em PDF</h2>
                <div class="management-actions">
                    <button class="button primary" type="button" onclick="window.print()">Exportar / Imprimir PDF</button>
                </div>
            </div>
            <div class="panel pdf-sheet">
                <div class="pdf-head">
                    <div class="sesc-logo small"><span>Sesc</span></div>
                    <div>
                        <h1>Relatorio de Notas</h1>
                        <p><?= h($event['name']) ?> Â· <?= h($event['date']) ?></p>
                    </div>
                </div>
                <?= render_ranking_table($ranking) ?>
                <h2>Notas por jurado</h2>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Participante</th><th>Jurado</th><th>Criterio</th><th>Nota</th><th>Media do jurado</th><th>Observacao</th><th>Atualizado em</th></tr></thead>
                        <tbody>
                        <?php foreach ($detailedRows as $row): ?>
                            <tr>
                                <td><?= h($row['participant']['name']) ?></td>
                                <td><?= h($row['judge']['name']) ?></td>
                                <td><?= h($row['criterion']['name']) ?></td>
                                <td><?= isset($row['vote']['score']) ? number_format((float)$row['vote']['score'], 1, ',', '.') : '-' ?></td>
                                <td><?= $row['judge_average'] !== null ? number_format((float)$row['judge_average'], 2, ',', '.') : '-' ?></td>
                                <td><?= $row['observation'] !== '' ? h($row['observation']) : '-' ?></td>
                                <td>
                                    <?php
                                    $updatedAt = $row['vote']['created_at'] ?? $row['observation_updated_at'] ?? '';
                                    echo $updatedAt ? h(date('d/m/Y H:i', strtotime($updatedAt))) : '-';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'configuracoes'): ?>
        <?php
            $configTab = $_GET['config_tab'] ?? 'gerais';
            $configTabs = [
                'gerais' => 'Informacoes Gerais',
                'periodos' => 'Periodos de Avaliacao',
                'pesos' => 'Pesos dos Criterios',
                'notificacoes' => 'Notificacoes',
                'publicacao' => 'Publicacao de Resultados',
                'outras' => 'Outras Configuracoes',
            ];
            $periods = $event['periods'] ?? [];
            $notifications = $event['notifications'] ?? [];
            $publication = $event['publication'] ?? [];
            $advanced = $event['advanced'] ?? [];
        ?>
        <section class="management-page config-page">
            <div class="management-head">
                <div>
                    <h2>Configuracoes do Evento</h2>
                    <p class="muted">Configurando: <strong><?= h($event['name']) ?></strong></p>
                </div>
                <form class="inline-form" method="get">
                    <input type="hidden" name="page" value="dashboard">
                    <input type="hidden" name="section" value="configuracoes">
                    <input type="hidden" name="config_tab" value="<?= h($configTab) ?>">
                    <select name="event_id" onchange="this.form.submit()">
                        <?php foreach ($events as $item): ?>
                            <option value="<?= (int)$item['id'] ?>" <?= $eventId === (int)$item['id'] ? 'selected' : '' ?>>
                                <?= h($item['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="selected-event-banner">
                <span>Evento selecionado</span>
                <strong><?= h($event['name']) ?></strong>
                <small><?= h($event['date']) ?> Â· <?= h($event['location'] ?? 'Teatro Sesc Centro') ?> Â· <?= h($event['status']) ?></small>
            </div>
            <div class="panel config-panel">
                <aside class="config-tabs">
                    <?php foreach ($configTabs as $key => $label): ?>
                        <a class="<?= $configTab === $key ? 'active' : '' ?>" href="?page=dashboard&section=configuracoes&event_id=<?= $eventId ?>&config_tab=<?= h($key) ?>"><?= h($label) ?></a>
                    <?php endforeach; ?>
                </aside>
                <div class="config-content">
                    <?php if ($configTab === 'gerais'): ?>
                        <form class="form-stack" method="post">
                            <input type="hidden" name="action" value="update_event_config">
                            <input type="hidden" name="config_tab" value="gerais">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label>Nome do Evento <input name="name" value="<?= h($event['name']) ?>"></label>
                            <label>Descricao <textarea name="description" rows="4"><?= h($event['description']) ?></textarea></label>
                            <div class="grid two">
                                <label>Data de Inicio <input name="date" type="date" value="<?= h($event['date']) ?>"></label>
                                <label>Data de Fim <input name="end_date" type="date" value="<?= h($event['end_date'] ?? $event['date']) ?>"></label>
                            </div>
                            <label>Local <input name="location" value="<?= h($event['location'] ?? 'Teatro Sesc Centro') ?>"></label>
                            <label>Status do Evento
                                <select name="status">
                                    <option value="rascunho" <?= ($event['status'] ?? '') === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                                    <option value="aberto" <?= ($event['status'] ?? '') === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                                    <option value="encerrado" <?= ($event['status'] ?? '') === 'encerrado' ? 'selected' : '' ?>>Encerrado</option>
                                </select>
                            </label>
                            <label>Tempo de Avaliacao por Jurado (minutos)
                                <input name="evaluation_minutes" type="number" min="1" value="<?= h((string)($event['evaluation_minutes'] ?? 136)) ?>">
                            </label>
                            <label>Formato do Evento
                                <select name="event_format">
                                    <option value="unica" <?= ($event['event_format'] ?? 'unica') === 'unica' ? 'selected' : '' ?>>Etapa unica</option>
                                    <option value="fases" <?= ($event['event_format'] ?? 'unica') === 'fases' ? 'selected' : '' ?>>Classificatoria, semifinal e final</option>
                                </select>
                            </label>
                            <button class="button primary" type="submit">Salvar Alteracoes</button>
                        </form>
                    <?php elseif ($configTab === 'periodos'): ?>
                        <form class="form-stack" method="post">
                            <input type="hidden" name="action" value="update_event_config">
                            <input type="hidden" name="config_tab" value="periodos">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label>Formato da avaliacao
                                <select name="period_mode" data-period-mode>
                                    <option value="unica" <?= ($event['event_format'] ?? 'unica') === 'unica' ? 'selected' : '' ?>>Etapa unica</option>
                                    <option value="fases" <?= ($event['event_format'] ?? 'unica') === 'fases' ? 'selected' : '' ?>>Classificatoria, semifinal e final</option>
                                </select>
                            </label>
                            <div class="table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Periodo</th><th>Inicio</th><th>Fim</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <tr data-period-row="unica"><td>Etapa unica</td><td><input name="single_start" type="datetime-local" value="<?= h($periods['unica']['start'] ?? '') ?>"></td><td><input name="single_end" type="datetime-local" value="<?= h($periods['unica']['end'] ?? '') ?>"></td><td><span class="status-pill ativo">Ativo</span></td></tr>
                                        <tr data-period-row="fases"><td>Classificatoria</td><td><input name="class_start" type="datetime-local" value="<?= h($periods['classificatoria']['start'] ?? '') ?>"></td><td><input name="class_end" type="datetime-local" value="<?= h($periods['classificatoria']['end'] ?? '') ?>"></td><td><select name="class_status"><option value="ativo" <?= ($periods['classificatoria']['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option><option value="programado" <?= ($periods['classificatoria']['status'] ?? '') === 'programado' ? 'selected' : '' ?>>Programado</option></select></td></tr>
                                        <tr data-period-row="fases"><td>Semifinal</td><td><input name="semi_start" type="datetime-local" value="<?= h($periods['semifinal']['start'] ?? '') ?>"></td><td><input name="semi_end" type="datetime-local" value="<?= h($periods['semifinal']['end'] ?? '') ?>"></td><td><select name="semi_status"><option value="programado" <?= ($periods['semifinal']['status'] ?? 'programado') === 'programado' ? 'selected' : '' ?>>Programado</option><option value="ativo" <?= ($periods['semifinal']['status'] ?? '') === 'ativo' ? 'selected' : '' ?>>Ativo</option></select></td></tr>
                                        <tr data-period-row="fases"><td>Final</td><td><input name="final_start" type="datetime-local" value="<?= h($periods['final']['start'] ?? '') ?>"></td><td><input name="final_end" type="datetime-local" value="<?= h($periods['final']['end'] ?? '') ?>"></td><td><select name="final_status"><option value="programado" <?= ($periods['final']['status'] ?? 'programado') === 'programado' ? 'selected' : '' ?>>Programado</option><option value="ativo" <?= ($periods['final']['status'] ?? '') === 'ativo' ? 'selected' : '' ?>>Ativo</option></select></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="info-note">Em etapa unica, somente a linha Etapa unica controla a avaliacao. Em fases, apenas periodos com status ativo estarao disponiveis.</div>
                            <button class="button primary" type="submit">Salvar Alteracoes</button>
                        </form>
                    <?php elseif ($configTab === 'pesos'): ?>
                        <div class="table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Ordem</th><th>Criterio</th><th>Descricao</th><th>Peso (%)</th></tr></thead>
                                <tbody>
                                    <?php foreach ($criteria as $index => $criterion): ?>
                                        <tr><td><?= $index + 1 ?></td><td><?= h($criterion['name']) ?></td><td>Avaliacao do participante neste criterio.</td><td><?= number_format((float)$criterion['weight'] * 20, 0, ',', '.') ?>%</td></tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row"><td colspan="3">Total dos Pesos</td><td>100%</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <a class="button primary" href="?page=dashboard&section=criterios&event_id=<?= $eventId ?>">Editar Pesos nos Criterios</a>
                    <?php elseif ($configTab === 'notificacoes'): ?>
                        <form class="form-stack config-switches" method="post">
                            <input type="hidden" name="action" value="update_event_config"><input type="hidden" name="config_tab" value="notificacoes"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label><span>Notificar jurados sobre abertura de avaliacoes</span><input type="checkbox" name="judge_open" <?= ($notifications['judge_open'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar jurados sobre lembretes de avaliacao</span><input type="checkbox" name="judge_reminder" <?= ($notifications['judge_reminder'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar administrador sobre avaliacoes concluidas</span><input type="checkbox" name="admin_complete" <?= ($notifications['admin_complete'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar participantes sobre resultados</span><input type="checkbox" name="participant_results" <?= ($notifications['participant_results'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar alteracoes no evento</span><input type="checkbox" name="event_changes" <?= ($notifications['event_changes'] ?? false) ? 'checked' : '' ?>></label>
                            <button class="button primary" type="submit">Salvar Alteracoes</button>
                        </form>
                    <?php elseif ($configTab === 'publicacao'): ?>
                        <form class="form-stack config-switches" method="post">
                            <input type="hidden" name="action" value="update_event_config"><input type="hidden" name="config_tab" value="publicacao"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label><span>Publicar resultados automaticamente</span><input type="checkbox" name="auto_publish" <?= ($publication['auto_publish'] ?? true) ? 'checked' : '' ?>></label>
                            <label>Data de inicio da publicacao <input name="publish_date" type="datetime-local" value="<?= h($publication['publish_date'] ?? '') ?>"></label>
                            <label><span>Exibir notas individuais dos jurados</span><input type="checkbox" name="show_individual" <?= ($publication['show_individual'] ?? false) ? 'checked' : '' ?>></label>
                            <label><span>Exibir comentarios dos jurados</span><input type="checkbox" name="show_comments" <?= ($publication['show_comments'] ?? false) ? 'checked' : '' ?>></label>
                            <label>Ordem de exibicao no resultado <select name="publication_order"><option value="score_desc" <?= ($publication['order'] ?? 'score_desc') === 'score_desc' ? 'selected' : '' ?>>Por pontuacao</option><option value="name" <?= ($publication['order'] ?? '') === 'name' ? 'selected' : '' ?>>Por nome</option></select></label>
                            <button class="button primary" type="submit">Salvar Alteracoes</button>
                        </form>
                    <?php else: ?>
                        <form class="form-stack config-switches" method="post">
                            <input type="hidden" name="action" value="update_event_config"><input type="hidden" name="config_tab" value="outras"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label><span>Permitir edicao de notas pelos jurados</span><input type="checkbox" name="allow_edit_after_submit" <?= ($advanced['allow_edit_after_submit'] ?? false) ? 'checked' : '' ?>></label>
                            <label><span>Mostrar media parcial aos jurados</span><input type="checkbox" name="show_partial_average" <?= ($advanced['show_partial_average'] ?? false) ? 'checked' : '' ?>></label>
                            <label>Criterio de desempate <select name="tie_breaker"><option value="highest_weight">Maior nota no criterio de maior peso</option><option value="oldest_vote">Primeiro voto lancado</option></select></label>
                            <label>Quantidade de casas decimais <input name="decimal_places" type="number" min="0" max="3" value="<?= h((string)($advanced['decimal_places'] ?? 2)) ?>"></label>
                            <label><span>Impedir multiplos acessos simultaneos</span><input type="checkbox" name="prevent_multi_login" <?= ($advanced['prevent_multi_login'] ?? true) ? 'checked' : '' ?>></label>
                            <button class="button primary" type="submit">Salvar Alteracoes</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
            </div>
        </section>
    </div>
    <?php
    render_footer();
}

function render_ranking_table(array $ranking): string
{
    ob_start();
    ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Participante</th><th>Nota</th><th>Jurados</th></tr></thead>
            <tbody>
            <?php if (!$ranking): ?>
                <tr><td colspan="4">Sem notas ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($ranking as $index => $row): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= h($row['participant']['name']) ?></td>
                    <td><?= number_format((float)$row['score'], 2, ',', '.') ?></td>
                    <td><?= (int)$row['judge_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

function old_dashboard_fragment_unused(): void
{
    ?>
        <div class="panel">
            <h2>Eventos</h2>
            <?php if (!$events): ?>
                <p class="muted">Nenhum evento cadastrado ainda.</p>
            <?php endif; ?>
            <div class="list">
                <?php foreach ($events as $item): ?>
                    <a class="list-row <?= $eventId === (int)$item['id'] ? 'active' : '' ?>" href="?page=dashboard&event_id=<?= (int)$item['id'] ?>">
                        <span><?= h($item['name']) ?></span>
                        <small><?= h($item['date']) ?> Â· <?= h($item['status']) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($event): ?>
        <section class="section-title">
            <div>
                <p class="eyebrow">Evento selecionado</p>
                <h2><?= h($event['name']) ?></h2>
            </div>
            <a class="button ghost" href="?page=ranking&event_id=<?= $eventId ?>">Ver ranking publico</a>
        </section>

        <section class="stats-grid">
            <div class="stat"><strong><?= count($participants) ?></strong><span>Participantes</span></div>
            <div class="stat"><strong><?= count($judges) ?></strong><span>Jurados</span></div>
            <div class="stat"><strong><?= count($criteria) ?></strong><span>Criterios</span></div>
            <div class="stat"><strong><?= count($db['admins'] ?? []) ?></strong><span>Administradores</span></div>
        </section>

        <section class="grid three">
            <form class="panel form-stack" method="post">
                <h2>Participante</h2>
                <input type="hidden" name="action" value="create_participant">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name"></label>
                <label>Categoria <input name="category" placeholder="Solo, grupo, instrumental"></label>
                <label>Musica <input name="song"></label>
                <label>Ordem <input name="order" type="number" min="0"></label>
                <button class="button primary" type="submit">Cadastrar</button>
            </form>

            <form class="panel form-stack" method="post">
                <h2>Jurado</h2>
                <input type="hidden" name="action" value="create_judge">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name"></label>
                <label>Usuario <input required name="username"></label>
                <label>Senha <input required name="password" type="password"></label>
                <button class="button primary" type="submit">Cadastrar</button>
            </form>

            <form class="panel form-stack" method="post">
                <h2>Criterio</h2>
                <input type="hidden" name="action" value="create_criterion">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name" placeholder="Originalidade"></label>
                <label>Peso <input required name="weight" type="number" min="0.1" step="0.1" value="1"></label>
                <button class="button primary" type="submit">Adicionar</button>
            </form>
        </section>

        <section class="grid two">
            <div class="panel">
                <h2>Participantes</h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Ordem</th><th>Nome</th><th>Categoria</th><th>Musica</th></tr></thead>
                        <tbody>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td><?= (int)$participant['order'] ?></td>
                                <td><?= h($participant['name']) ?></td>
                                <td><?= h($participant['category']) ?></td>
                                <td><?= h($participant['song']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <h2>Jurados e criterios</h2>
                <div class="chips">
                    <?php foreach ($judges as $judge): ?>
                        <span><?= h($judge['name']) ?> Â· <?= h($judge['username']) ?></span>
                    <?php endforeach; ?>
                </div>
                <hr>
                <div class="chips">
                    <?php foreach ($criteria as $criterion): ?>
                        <span><?= h($criterion['name']) ?> Â· peso <?= h((string)$criterion['weight']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="grid two">
            <div class="panel">
                <h2>Ranking parcial</h2>
                <?= render_ranking_table($ranking) ?>
            </div>
            <form class="panel form-stack" method="post">
                <h2>Novo administrador</h2>
                <input type="hidden" name="action" value="create_admin">
                <label>Nome <input required name="name"></label>
                <label>E-mail <input required name="email" type="email"></label>
                <label>Senha <input required name="password" type="password"></label>
                <button class="button primary" type="submit">Cadastrar administrador</button>
            </form>
        </section>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_ranking_table_old(array $ranking): string
{
    ob_start();
    ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Participante</th><th>Nota</th><th>Jurados</th></tr></thead>
            <tbody>
            <?php if (!$ranking): ?>
                <tr><td colspan="4">Sem notas ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($ranking as $index => $row): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= h($row['participant']['name']) ?></td>
                    <td><?= number_format((float)$row['score'], 2, ',', '.') ?></td>
                    <td><?= (int)$row['judge_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

function render_judge_panel(): void
{
    require_judge();
    $db = db_read();
    $eventId = (int)$_SESSION['judge_event_id'];
    $judgeId = (int)$_SESSION['judge_id'];
    $section = $_GET['section'] ?? 'votacao';
    $event = find_by_id($db['events'] ?? [], $eventId);
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    usort($participants, fn($a, $b) => ((int)$a['order'] <=> (int)$b['order']) ?: strcmp($a['name'], $b['name']));
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $participantId = isset($_GET['participant_id']) ? (int)$_GET['participant_id'] : (int)($participants[0]['id'] ?? 0);
    $selected = find_by_id($participants, $participantId);
    $selectedIndex = 0;
    foreach ($participants as $index => $participant) {
        if ((int)$participant['id'] === $participantId) {
            $selectedIndex = $index;
            break;
        }
    }
    $prev = $selectedIndex > 0 ? ($participants[$selectedIndex - 1] ?? null) : null;
    $next = $selectedIndex < (count($participants) - 1) ? ($participants[$selectedIndex + 1] ?? null) : null;
    $scores = [];
    foreach ($db['votes'] ?? [] as $vote) {
        if ((int)$vote['event_id'] === $eventId && (int)$vote['judge_id'] === $judgeId && (int)$vote['participant_id'] === $participantId) {
            $scores[(int)$vote['criterion_id']] = (float)$vote['score'];
        }
    }
    $observation = observation_for($db, $eventId, $judgeId, $participantId);
    $scoreTotal = array_sum($scores);
    $scoreAverage = count($criteria) ? $scoreTotal / count($criteria) : 0;
    $deadline = (int)($_SESSION['judge_deadlines'][$eventId] ?? (time() + evaluation_seconds_from_event($event)));
    $_SESSION['judge_deadlines'][$eventId] = $deadline;
    $remaining = max(0, $deadline - time());
    $timerText = gmdate('H:i:s', $remaining);
    $isFinished = !empty($_SESSION['judge_finished'][$eventId]) || $remaining <= 0;
    $periodIsActive = active_evaluation_period($event);

    render_header('Painel do Jurado');
    ?>
    <div class="judge-shell">
        <aside class="admin-sidebar judge-sidebar">
            <div class="sidebar-logo">
                <div class="sesc-logo small"><span>Sesc</span></div>
                <small>Sistema de Notas de Jurados</small>
            </div>
            <nav class="admin-menu">
                <a class="<?= $section === 'votacao' ? 'active' : '' ?>" href="?page=judge-panel&section=votacao"><span>VO</span>Votacao</a>
                <a class="<?= $section === 'participantes' ? 'active' : '' ?>" href="?page=judge-panel&section=participantes"><span>PA</span>Participantes</a>
                <a class="<?= $section === 'criterios' ? 'active' : '' ?>" href="?page=judge-panel&section=criterios"><span>CR</span>Criterios</a>
                <a class="<?= $section === 'resumo' ? 'active' : '' ?>" href="?page=judge-panel&section=resumo"><span>RE</span>Resumo de Notas</a>
                <a class="<?= $section === 'instrucoes' ? 'active' : '' ?>" href="?page=judge-panel&section=instrucoes"><span>IN</span>Instrucoes</a>
            </nav>
            <div class="judge-side-help"><strong>Duvidas?</strong><small>Fale com a organizacao</small></div>
            <form method="post" class="sidebar-logout">
                <input type="hidden" name="action" value="logout">
                <button type="submit"><span>SA</span>Sair</button>
            </form>
        </aside>
        <section class="judge-content">
            <header class="judge-event-head">
                <div>
                    <span>Evento:</span>
                    <h1><?= h($event['name'] ?? 'Evento') ?></h1>
                    <p>â–£ <?= h($event['date'] ?? '') ?> &nbsp;&nbsp; â—‰ Teatro Sesc Centro</p>
                </div>
                <div class="judge-person">
                    <span class="avatar">â—‹</span>
                    <div><span>Jurado:</span><strong><?= h($_SESSION['judge_name'] ?? '') ?></strong><small>Jurado</small></div>
                </div>
                <div class="judge-timer">
                    <span>Tempo restante para avaliacao</span>
                    <strong id="judge-timer" data-deadline="<?= h(date('c', $deadline)) ?>"><?= h($timerText) ?></strong>
                    <small>Tempo disponivel para avaliar todos os participantes</small>
                </div>
            </header>

            <?php if ($section === 'participantes'): ?>
                <section class="judge-list-page">
                    <div class="management-head">
                        <h2>Participantes</h2>
                        <input type="search" placeholder="Buscar participante...">
                    </div>
                    <div class="panel data-panel">
                        <table class="admin-table">
                            <thead><tr><th>Ordem</th><th>Participante</th><th>Categoria</th><th>Situacao</th><th>Acao</th></tr></thead>
                            <tbody>
                            <?php foreach ($participants as $participant): ?>
                                <?php $participantScores = array_filter($db['votes'] ?? [], fn($vote) => (int)$vote['judge_id'] === $judgeId && (int)$vote['participant_id'] === (int)$participant['id']); ?>
                                <tr>
                                    <td><?= str_pad((string)(int)$participant['order'], 2, '0', STR_PAD_LEFT) ?></td>
                                    <td><span class="participant-name-cell"><?= participant_photo_html($participant, 'thumb') ?><?= h($participant['name']) ?></span></td>
                                    <td><?= h($participant['category']) ?></td>
                                    <td><span class="status-pill <?= $participantScores ? 'ativo' : 'pendente' ?>"><?= $participantScores ? 'Avaliado' : 'Pendente' ?></span></td>
                                    <td><a class="button ghost small" href="?page=judge-panel&section=votacao&participant_id=<?= (int)$participant['id'] ?>">Avaliar</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="info-note">Clique em Avaliar para lancar suas notas para o participante.</div>
                </section>
            <?php elseif ($section === 'criterios'): ?>
                <section class="judge-list-page">
                    <div class="management-head"><h2>Criterios de Avaliacao</h2></div>
                    <div class="panel data-panel">
                        <table class="admin-table">
                            <thead><tr><th>Ordem</th><th>Criterio</th><th>Descricao</th><th>Peso</th></tr></thead>
                            <tbody>
                            <?php foreach ($criteria as $index => $criterion): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= h($criterion['name']) ?></td>
                                    <td>Qualidade avaliada pelo jurado durante a apresentacao.</td>
                                    <td><?= number_format((float)$criterion['weight'] * 20, 0, ',', '.') ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="info-note">A soma dos pesos dos criterios deve ser igual a 100%.</div>
                </section>
            <?php elseif ($section === 'resumo'): ?>
                <section class="judge-list-page">
                    <div class="management-head">
                        <h2>Resumo de Notas</h2>
                        <select data-participant-jump data-base-url="?page=judge-panel&section=votacao">
                            <option value="">Todos os participantes</option>
                            <?php foreach ($participants as $participant): ?>
                                <option value="<?= (int)$participant['id'] ?>" <?= $participantId === (int)$participant['id'] ? 'selected' : '' ?>><?= h($participant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="panel data-panel">
                        <?= render_ranking_table(ranking_for_event($db, $eventId)) ?>
                    </div>
                    <div class="info-note">As notas so serao exibidas apos o termino do evento.</div>
                </section>
            <?php elseif ($section === 'instrucoes'): ?>
                <section class="judge-list-page">
                    <div class="management-head"><h2>Instrucoes para Avaliacao</h2></div>
                    <div class="panel instructions-panel">
                        <div><strong>Como avaliar</strong><p>Para cada participante, atribua notas de 0,0 a 10,0 para cada criterio de avaliacao.</p></div>
                        <div><strong>Importante</strong><p>Suas notas sao confidenciais e so serao usadas na apuracao do evento.</p></div>
                        <div><strong>Criterios</strong><p>A avaliacao deve considerar todos os criterios e pesos definidos pelo organizador.</p></div>
                        <div><strong>Tempo</strong><p>Fique atento ao tempo disponivel para avaliar todos os participantes.</p></div>
                    </div>
                    <label class="confirm-instructions"><input type="checkbox" data-enable-target="judge-start-button"> Li e concordo com as instrucoes acima</label>
                    <a class="button primary is-disabled" id="judge-start-button" aria-disabled="true" href="?page=judge-panel&section=votacao">Iniciar Avaliacao</a>
                </section>
            <?php elseif (!$selected): ?>
                <section class="panel empty-state">
                    <h2>Nenhum participante cadastrado</h2>
                    <p class="muted">Aguarde o administrador adicionar participantes ao evento.</p>
                </section>
            <?php elseif (!$periodIsActive): ?>
                <section class="judge-list-page">
                    <div class="panel empty-state">
                        <h2>Periodo de avaliacao indisponivel</h2>
                        <p class="muted">O administrador ainda nao abriu um periodo ativo para este evento.</p>
                        <a class="button primary" href="?page=judge-panel&section=criterios">Ver criterios</a>
                    </div>
                </section>
            <?php else: ?>
                <div class="judge-work-head">
                    <h2>Avaliar Participante</h2>
                    <div class="participant-switch">
                        <?php if ($prev): ?><a href="?page=judge-panel&section=votacao&participant_id=<?= (int)$prev['id'] ?>">Anterior</a><?php endif; ?>
                        <span>Participante <?= $selectedIndex + 1 ?> de <?= count($participants) ?></span>
                        <?php if ($next): ?><a href="?page=judge-panel&section=votacao&participant_id=<?= (int)$next['id'] ?>">Proximo</a><?php endif; ?>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="finalize_evaluation">
                        <button class="button primary" type="submit"><?= $isFinished ? 'Avaliacoes Finalizadas' : 'Finalizar Avaliacoes' ?></button>
                    </form>
                </div>

                <section class="participant-hero-card">
                    <?= participant_photo_html($selected, 'large') ?>
                    <span class="order-badge photo-overlap"><?= str_pad((string)(int)$selected['order'], 2, '0', STR_PAD_LEFT) ?></span>
                    <div>
                        <h2><?= h($selected['name']) ?></h2>
                        <p>Categoria: <mark><?= h($selected['category'] ?: 'Geral') ?></mark></p>
                        <p>Ordem de apresentacao: <?= str_pad((string)(int)$selected['order'], 2, '0', STR_PAD_LEFT) ?></p>
                    </div>
                    <div class="info-note">Avalie cada criterio abaixo com notas de 0,0 a 10,0. Voce pode alterar as notas ate finalizar as avaliacoes.</div>
                </section>

                <section class="judge-tutorial">
                    <button class="tutorial-toggle" type="button" data-toggle-tutorial>Como votar nesta tela</button>
                    <div class="tutorial-content" data-tutorial-content hidden>
                        <p><strong>1.</strong> Escolha uma nota clicando nos botoes de 0 a 10 ou digite a nota no campo ao lado.</p>
                        <p><strong>2.</strong> Para notas quebradas, digite valores como 8,5 ou 9,2 no campo numerico.</p>
                        <p><strong>3.</strong> Escreva uma observacao se quiser justificar a avaliacao.</p>
                        <p><strong>4.</strong> Clique em Salvar Notas antes de ir para o proximo participante.</p>
                    </div>
                </section>

                <section class="judge-vote-grid">
                    <form class="panel criteria-vote-form" method="post" data-offline-form data-participant-id="<?= (int)$selected['id'] ?>" data-event-id="<?= $eventId ?>" data-judge-id="<?= $judgeId ?>">
                        <input type="hidden" name="action" value="save_votes">
                        <input type="hidden" name="participant_id" value="<?= (int)$selected['id'] ?>">
                        <div class="offline-status" data-offline-status hidden></div>
                        <div class="criteria-head"><strong>Criterios de Avaliacao</strong><strong>Notas</strong></div>
                        <?php foreach ($criteria as $criterion): ?>
                            <?php $current = (string)($scores[(int)$criterion['id']] ?? ''); ?>
                            <div class="criterion-row">
                                <div class="criterion-name">
                                    <span class="metric-icon blue">â˜†</span>
                                    <div><strong><?= h($criterion['name']) ?></strong><small>Avaliacao do participante neste criterio.</small></div>
                                </div>
                                <div class="score-picker">
                                    <?php for ($score = 0; $score <= 10; $score++): ?>
                                        <label class="<?= $current !== '' && (float)$current === (float)$score ? 'checked' : '' ?>">
                                            <input type="radio" name="score_buttons[<?= (int)$criterion['id'] ?>]" value="<?= $score ?>" <?= $current !== '' && (float)$current === (float)$score ? 'checked' : '' ?> <?= $isFinished ? 'disabled' : '' ?>>
                                            <span><?= $score ?></span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                                <input class="score-box" required name="scores[<?= (int)$criterion['id'] ?>]" type="number" min="0" max="10" step="0.1" inputmode="decimal" value="<?= $current !== '' ? h((string)(float)$current) : '' ?>" placeholder="-" <?= $isFinished ? 'disabled' : '' ?>>
                            </div>
                        <?php endforeach; ?>
                        <label class="observations">Observacoes (opcional)<textarea name="observation" rows="3" placeholder="Descreva aqui seus comentarios sobre a apresentacao do participante..." <?= $isFinished ? 'disabled' : '' ?>><?= h($observation['text'] ?? '') ?></textarea></label>
                        <div class="judge-nav">
                            <?php if ($prev): ?><a class="button ghost" href="?page=judge-panel&section=votacao&participant_id=<?= (int)$prev['id'] ?>">Participante Anterior</a><?php else: ?><span></span><?php endif; ?>
                            <button class="button primary" type="submit" <?= $isFinished ? 'disabled' : '' ?>>Salvar Notas</button>
                            <?php if ($next): ?><a class="button primary" href="?page=judge-panel&section=votacao&participant_id=<?= (int)$next['id'] ?>">Proximo Participante</a><?php endif; ?>
                        </div>
                    </form>
                    <aside class="panel evaluation-summary">
                        <h2>Resumo da Avaliacao</h2>
                        <div class="average-ring"><strong><?= number_format($scoreAverage, 1, ',', '.') ?></strong><span>Media Geral</span></div>
                        <?php foreach ($criteria as $criterion): ?>
                            <p><span><?= h($criterion['name']) ?></span><strong><?= isset($scores[(int)$criterion['id']]) ? number_format((float)$scores[(int)$criterion['id']], 1, ',', '.') : '-' ?></strong></p>
                        <?php endforeach; ?>
                        <p class="summary-total"><span>Total</span><strong><?= number_format($scoreTotal, 1, ',', '.') ?></strong></p>
                        <p class="summary-average"><span>Media Geral</span><strong><?= number_format($scoreAverage, 1, ',', '.') ?></strong></p>
                    </aside>
                </section>
            <?php endif; ?>
        </section>
    </div>
    <?php
    render_footer();
}

function render_judge_panel_cards_old(): void
{
    require_judge();
    $db = db_read();
    $eventId = (int)$_SESSION['judge_event_id'];
    $judgeId = (int)$_SESSION['judge_id'];
    $event = find_by_id($db['events'] ?? [], $eventId);
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    usort($participants, fn($a, $b) => ((int)$a['order'] <=> (int)$b['order']) ?: strcmp($a['name'], $b['name']));
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $votesByParticipant = [];
    foreach ($db['votes'] ?? [] as $vote) {
        if ((int)$vote['event_id'] === $eventId && (int)$vote['judge_id'] === $judgeId) {
            $votesByParticipant[(int)$vote['participant_id']][(int)$vote['criterion_id']] = $vote['score'];
        }
    }

    render_header('Painel do Jurado');
    ?>
    <section class="dashboard-head">
        <div>
            <p class="eyebrow">Jurado: <?= h($_SESSION['judge_name'] ?? '') ?></p>
            <h1><?= h($event['name'] ?? 'Evento') ?></h1>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <button class="button ghost" type="submit">Sair</button>
        </form>
    </section>

    <?php if (!$participants): ?>
        <section class="panel empty-state">
            <h2>Nenhum participante cadastrado</h2>
            <p class="muted">Quando o administrador adicionar participantes, os cards de votacao aparecem aqui.</p>
        </section>
    <?php else: ?>
        <section class="vote-card-grid">
            <?php foreach ($participants as $participant): ?>
                <?php $scores = $votesByParticipant[(int)$participant['id']] ?? []; ?>
                <form class="participant-card vote-form" method="post">
                    <input type="hidden" name="action" value="save_votes">
                    <input type="hidden" name="participant_id" value="<?= (int)$participant['id'] ?>">
                    <div class="participant-card-head">
                        <span class="order-badge"><?= (int)$participant['order'] ?: '-' ?></span>
                        <div>
                            <h2><?= h($participant['name']) ?></h2>
                            <p><?= h($participant['category'] ?: 'Sem categoria') ?></p>
                        </div>
                    </div>
                    <?php if (!empty($participant['song'])): ?>
                        <p class="song-line"><?= h($participant['song']) ?></p>
                    <?php endif; ?>
                    <div class="score-grid">
                        <?php foreach ($criteria as $criterion): ?>
                            <label>
                                <span><?= h($criterion['name']) ?></span>
                                <input required name="scores[<?= (int)$criterion['id'] ?>]" type="number" min="0" max="10" step="0.1" value="<?= h((string)($scores[(int)$criterion['id']] ?? '')) ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button class="button primary" type="submit">
                        <?= $scores ? 'Atualizar notas' : 'Salvar notas' ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_judge_panel_old(): void
{
    require_judge();
    $db = db_read();
    $eventId = (int)$_SESSION['judge_event_id'];
    $judgeId = (int)$_SESSION['judge_id'];
    $event = find_by_id($db['events'] ?? [], $eventId);
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    usort($participants, fn($a, $b) => ((int)$a['order'] <=> (int)$b['order']) ?: strcmp($a['name'], $b['name']));
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $participantId = isset($_GET['participant_id']) ? (int)$_GET['participant_id'] : (int)($participants[0]['id'] ?? 0);
    $selected = find_by_id($participants, $participantId);
    $votes = array_values(array_filter($db['votes'] ?? [], fn($vote) =>
        (int)$vote['event_id'] === $eventId &&
        (int)$vote['judge_id'] === $judgeId &&
        (int)$vote['participant_id'] === $participantId
    ));
    $scores = [];
    foreach ($votes as $vote) {
        $scores[(int)$vote['criterion_id']] = $vote['score'];
    }

    render_header('Painel do Jurado');
    ?>
    <section class="dashboard-head">
        <div>
            <p class="eyebrow">Jurado: <?= h($_SESSION['judge_name'] ?? '') ?></p>
            <h1><?= h($event['name'] ?? 'Evento') ?></h1>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <button class="button ghost" type="submit">Sair</button>
        </form>
    </section>

    <section class="judge-layout">
        <aside class="panel">
            <h2>Participantes</h2>
            <div class="list">
                <?php foreach ($participants as $participant): ?>
                    <a class="list-row <?= $participantId === (int)$participant['id'] ? 'active' : '' ?>" href="?page=judge-panel&participant_id=<?= (int)$participant['id'] ?>">
                        <span><?= h($participant['name']) ?></span>
                        <small><?= h($participant['category']) ?> <?= $participant['order'] ? 'Â· ordem ' . (int)$participant['order'] : '' ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <form class="panel form-stack vote-form" method="post">
            <h2><?= $selected ? h($selected['name']) : 'Nenhum participante cadastrado' ?></h2>
            <?php if ($selected): ?>
                <p class="muted"><?= h($selected['song']) ?></p>
                <input type="hidden" name="action" value="save_votes">
                <input type="hidden" name="participant_id" value="<?= $participantId ?>">
                <?php foreach ($criteria as $criterion): ?>
                    <label class="score-line">
                        <span><?= h($criterion['name']) ?> <small>Peso <?= h((string)$criterion['weight']) ?></small></span>
                        <input required name="scores[<?= (int)$criterion['id'] ?>]" type="number" min="0" max="10" step="0.1" value="<?= h((string)($scores[(int)$criterion['id']] ?? '')) ?>">
                    </label>
                <?php endforeach; ?>
                <button class="button primary" type="submit">Salvar notas</button>
            <?php else: ?>
                <p class="muted">Aguarde o administrador cadastrar os participantes.</p>
            <?php endif; ?>
        </form>
    </section>
    <?php
    render_footer();
}

function render_ranking_page(): void
{
    $db = db_read();
    $eventId = active_event_id($db);
    $event = $eventId ? find_by_id($db['events'] ?? [], $eventId) : null;
    $events = event_options($db);
    $ranking = $eventId ? ranking_for_event($db, $eventId) : [];

    render_header('Ranking');
    ?>
    <section class="dashboard-head">
        <div>
            <p class="eyebrow">Resultado publico</p>
            <h1><?= h($event['name'] ?? 'Ranking') ?></h1>
        </div>
        <form class="inline-form" method="get">
            <input type="hidden" name="page" value="ranking">
            <select name="event_id" onchange="this.form.submit()">
                <?php foreach ($events as $item): ?>
                    <option value="<?= (int)$item['id'] ?>" <?= $eventId === (int)$item['id'] ? 'selected' : '' ?>>
                        <?= h($item['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </section>
    <section class="panel ranking-panel">
        <?php if ($event && results_are_public($event)): ?>
            <?= render_ranking_table($ranking) ?>
        <?php else: ?>
            <div class="empty-state">
                <h2>Resultados ainda nao publicados</h2>
                <p class="muted">A publicacao dos resultados sera liberada conforme a configuracao do evento.</p>
            </div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}

handle_post();

$page = $_GET['page'] ?? 'home';
match ($page) {
    'home' => render_login_home(),
    'login' => render_login_home(),
    'admin-login' => render_login('admin'),
    'judge-login' => render_login('judge'),
    'dashboard' => render_dashboard(),
    'judge-panel' => render_judge_panel(),
    'ranking' => render_ranking_page(),
    default => render_login_home(),
};

