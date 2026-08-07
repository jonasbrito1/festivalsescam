<?php
const DATA_DIR = __DIR__ . '/data';
const SESSION_DIR = DATA_DIR . '/sessions';
const DB_FILE = DATA_DIR . '/db.json';
const PARTICIPANT_UPLOAD_DIR = __DIR__ . '/public/uploads/participants';

/* [MIGRACAO MySQL] Camada de dados MySQL. Fica inerte enquanto
 * FESTIVAL_DB_MODO nao for definido — ver lib/mysql.php. */
require_once __DIR__ . '/lib/mysql.php';

/* Integracao WhatsApp: configuracao, fila e envio. Inerte enquanto a
 * integracao nao estiver ligada na tela de Configuracoes. */
require_once __DIR__ . '/lib/whatsapp.php';

/* Gerador de PDF, escrito a mao: o servidor nao tem biblioteca nenhuma e o
 * pool do PHP-FPM bloqueia exec — ver lib/pdf.php. */
require_once __DIR__ . '/lib/pdf.php';

/* Planilha SER SESC: a PROJETO SER SESC.xlsx administrada online. Modulo
 * proprio, sem ligacao com eventos/jurados — ver lib/planilha.php. */
require_once __DIR__ . '/lib/planilha.php';

/* ---------------------------------------------------------------------------
 * [SEGURANCA] Onde ficam os arquivos de sessao.
 *
 * O padrao era data/sessions, dentro da pasta servida pelo servidor web.
 * Baixar um desses arquivos entrega a sessao de um administrador logado.
 * Em producao a variavel FESTIVAL_SESSION_DIR aponta para fora do docroot;
 * no XAMPP, sem a variavel, o comportamento local continua o mesmo.
 * ------------------------------------------------------------------------- */
$sessaoExterna = getenv('FESTIVAL_SESSION_DIR') ?: '';
if ($sessaoExterna !== '' && is_dir($sessaoExterna)) {
    session_save_path($sessaoExterna);
} else {
    if (!is_dir(SESSION_DIR)) {
        mkdir(SESSION_DIR, 0775, true);
    }
    session_save_path(SESSION_DIR);
}

/* [SEGURANCA] Cookie de sessao inacessivel ao JavaScript e restrito ao site. */
$sobreHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $sobreHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_start();

/* ---------------------------------------------------------------------------
 * [SEGURANCA] Protecao contra CSRF.
 *
 * O sistema tem 19 ações POST (excluir evento, excluir participante, salvar
 * notas, criar administrador...) e nenhuma exigia comprovacao de origem.
 * Um site externo conseguia fazer o navegador de um administrador logado
 * disparar qualquer uma delas apenas ao ser aberto.
 *
 * O token e injetado automaticamente em todo <form method="post"> pelo
 * filtro de saida abaixo, sem precisar alterar os 28 formularios um a um.
 * ------------------------------------------------------------------------- */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrf_validar(): void
{
    $enviado = $_POST['_csrf'] ?? '';
    $esperado = $_SESSION['_csrf'] ?? '';

    if (!is_string($enviado) || $esperado === '' || !hash_equals($esperado, $enviado)) {
        http_response_code(419);
        exit('Sessão expirada ou requisicao invalida. Recarregue a página e tente novamente.');
    }
}

function csrf_injetar(string $html): string
{
    $campo = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';

    return preg_replace_callback(
        '/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>/i',
        static fn(array $m): string => $m[0] . $campo,
        $html
    ) ?? $html;
}

/* O token precisa nascer AGORA, e nao dentro do filtro de saida: aquele
 * callback roda no encerramento da requisicao, quando o PHP ja gravou a
 * sessao em disco. O valor iria para o HTML sem nunca ser guardado, e toda
 * validacao seguinte falharia. */
csrf_token();

ob_start('csrf_injetar');

/* ---------------------------------------------------------------------------
 * [SEGURANCA] Expiracao por inatividade.
 *
 * A sessao so terminava quando o PHP resolvia limpa-la. Um computador
 * deixado aberto no palco, ou o notebook da organizacao esquecido em cima
 * da mesa, continuava com o painel administrativo acessivel.
 *
 * Quatro horas cobrem um festival inteiro sem derrubar jurado no meio da
 * votacao, e ainda assim fecham a porta depois do evento.
 * ------------------------------------------------------------------------- */
const SESSAO_INATIVIDADE = 4 * 3600;

if (isset($_SESSION['admin_id']) || isset($_SESSION['judge_id'])) {
    $ultimo = $_SESSION['_ultimo_acesso'] ?? time();

    if (time() - $ultimo > SESSAO_INATIVIDADE) {
        $_SESSION = [];
        session_destroy();
        session_start();
        csrf_token();
        $_SESSION['flash'] = ['message' => 'Sua sessao expirou por inatividade. Entre novamente.', 'type' => 'error'];
    } else {
        $_SESSION['_ultimo_acesso'] = time();
    }
} else {
    $_SESSION['_ultimo_acesso'] = time();
}

/* ---------------------------------------------------------------------------
 * [SEGURANCA] Limite de tentativas de login.
 *
 * Nada impedia tentar senhas indefinidamente. Com um front controller unico
 * e login por POST, um script consegue milhares de tentativas por minuto —
 * e a senha de administrador comanda o festival inteiro.
 *
 * A contagem vive em arquivo, nao na sessao: guardada na sessao, bastaria
 * descartar o cookie a cada tentativa para o contador nunca subir.
 *
 * Dois baldes independentes:
 *   - por identidade (e-mail/usuario): protege uma conta especifica
 *   - por IP: barra varredura de varias contas a partir da mesma origem
 * ------------------------------------------------------------------------- */
const LOGIN_MAX_IDENTIDADE = 5;
const LOGIN_MAX_IP = 15;
const LOGIN_JANELA = 900; // 15 minutos

function diretorio_estado(): string
{
    $sessao = getenv('FESTIVAL_SESSION_DIR') ?: '';
    $dir = ($sessao !== '' && is_dir($sessao)) ? dirname($sessao) : DATA_DIR;

    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    return $dir;
}

/** IP real do visitante. Atras da Cloudflare, REMOTE_ADDR e sempre a CDN. */
function ip_visitante(): string
{
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }

    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Abre o arquivo de tentativas com trava exclusiva, entrega o conteudo ao
 * callback e grava o que ele devolver. A trava evita que dois pedidos
 * simultaneos sobrescrevam a contagem um do outro.
 */
function tentativas_transacao(callable $operacao)
{
    $novos = null;
    $arquivo = diretorio_estado() . '/login_attempts.json';
    $fp = @fopen($arquivo, 'c+');

    if (!$fp) {
        // Sem poder gravar, nao trava o acesso legitimo no meio do evento:
        // opera sobre uma contagem vazia e segue.
        return $operacao([], $novos);
    }

    flock($fp, LOCK_EX);
    $bruto = stream_get_contents($fp);
    $dados = json_decode($bruto ?: '{}', true) ?: [];

    $agora = time();
    foreach ($dados as $chave => $marcas) {
        $dados[$chave] = array_values(array_filter(
            (array)$marcas,
            static fn($t): bool => ($agora - (int)$t) < LOGIN_JANELA
        ));
        if ($dados[$chave] === []) {
            unset($dados[$chave]);
        }
    }

    $resultado = $operacao($dados, $novos);

    if (is_array($novos)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($novos));
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $resultado;
}

function login_bloqueado(string $identidade): bool
{
    return tentativas_transacao(static function (array $dados) use ($identidade): bool {
        $porId = count($dados['id:' . mb_strtolower($identidade)] ?? []);
        $porIp = count($dados['ip:' . ip_visitante()] ?? []);

        return $porId >= LOGIN_MAX_IDENTIDADE || $porIp >= LOGIN_MAX_IP;
    });
}

function login_registrar_falha(string $identidade): void
{
    tentativas_transacao(static function (array $dados, &$novos) use ($identidade): void {
        $agora = time();
        $dados['id:' . mb_strtolower($identidade)][] = $agora;
        $dados['ip:' . ip_visitante()][] = $agora;
        $novos = $dados;
    });
}

function login_limpar(string $identidade): void
{
    tentativas_transacao(static function (array $dados, &$novos) use ($identidade): void {
        unset($dados['id:' . mb_strtolower($identidade)]);
        $novos = $dados;
    });
}

function login_minutos_restantes(string $identidade): int
{
    return tentativas_transacao(static function (array $dados) use ($identidade): int {
        $marcas = array_merge(
            $dados['id:' . mb_strtolower($identidade)] ?? [],
            $dados['ip:' . ip_visitante()] ?? []
        );

        if ($marcas === []) {
            return 1;
        }

        $restante = LOGIN_JANELA - (time() - (int)min($marcas));

        return max(1, (int)ceil($restante / 60));
    });
}

function ensure_database(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    if (!file_exists(DB_FILE)) {
        write_local_database(default_database_payload());
    }
}

function default_database_payload(): array
{
    /* [SEGURANCA] A senha do primeiro acesso era 'admin123', fixa no codigo.
     * Como o codigo e publico, isso equivale a instalar o sistema ja com a
     * senha do administrador divulgada.
     *
     * Agora e sorteada na instalacao e gravada em data/PRIMEIRO_ACESSO.txt,
     * que o .gitignore mantem fora do repositorio. Quem instala le o arquivo,
     * entra, troca a senha e apaga o arquivo. */
    $senhaInicial = bin2hex(random_bytes(6));
    $adminPassword = password_hash($senhaInicial, PASSWORD_DEFAULT);

    $aviso = DATA_DIR . '/PRIMEIRO_ACESSO.txt';
    @file_put_contents($aviso, implode(PHP_EOL, [
        'Acesso inicial do administrador',
        '',
        '  E-mail: admin@festival.local',
        '  Senha:  ' . $senhaInicial,
        '',
        'Troque a senha no primeiro acesso e apague este arquivo.',
        'Gerado em ' . date('d/m/Y H:i:s'),
        '',
    ]));
    @chmod($aviso, 0600);
    return [
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
        'judge_reviews' => [],
    ];
}

function normalize_database(array $db): array
{
    $db['admins'] = $db['admins'] ?? [];
    $db['events'] = $db['events'] ?? [];
    $db['criteria'] = $db['criteria'] ?? [];
    $db['judges'] = $db['judges'] ?? [];
    $db['participants'] = $db['participants'] ?? [];
    $db['votes'] = $db['votes'] ?? [];
    $db['observations'] = $db['observations'] ?? [];
    $db['judge_reviews'] = $db['judge_reviews'] ?? [];
    $db['counters'] = [
        'events' => max_record_id($db['events']) + 1,
        'criteria' => max_record_id($db['criteria']) + 1,
        'judges' => max_record_id($db['judges']) + 1,
        'participants' => max_record_id($db['participants']) + 1,
        'admins' => max_record_id($db['admins']) + 1,
        'votes' => max_record_id($db['votes']) + 1,
    ];
    return $db;
}

function max_record_id(array $items): int
{
    $max = 0;
    foreach ($items as $item) {
        $max = max($max, (int)($item['id'] ?? 0));
    }
    return $max;
}

function write_local_database(array $db): void
{
    $handle = fopen(DB_FILE, 'c+');
    if (!$handle) {
        throw new RuntimeException('Não foi possível abrir o arquivo de dados.');
    }

    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function read_local_database(): array
{
    ensure_database();
    $content = file_get_contents(DB_FILE);
    $db = json_decode($content ?: '{}', true) ?: [];
    return normalize_database($db);
}

function sql_config(): ?array
{
    static $config;
    static $loaded = false;

    if ($loaded) {
        return $config;
    }

    $loaded = true;
    $configPath = __DIR__ . '/config/database.php';
    if (!file_exists($configPath)) {
        $config = null;
        return null;
    }

    $config = require $configPath;
    if (($config['driver'] ?? '') !== 'sqlsrv' || !function_exists('sqlsrv_connect')) {
        $config = null;
        return null;
    }

    return $config;
}

function sql_connection(int $timeout = 2)
{
    $config = sql_config();
    if (!$config) {
        return null;
    }

    $options = [
        'Database' => $config['database'] ?? '',
        'UID' => $config['username'] ?? '',
        'PWD' => $config['password'] ?? '',
        'Encrypt' => false,
        'TrustServerCertificate' => true,
        'LoginTimeout' => $timeout,
        'CharacterSet' => 'UTF-8',
        'ReturnDatesAsStrings' => true,
    ];

    foreach (($config['options'] ?? []) as $key => $value) {
        $options[$key] = $value;
    }

    return @sqlsrv_connect($config['server'] ?? '', $options);
}

function sql_scalar($connection, string $sql, array $params = [])
{
    $statement = sqlsrv_query($connection, $sql, $params);
    if ($statement === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($statement);
    return $row[0] ?? null;
}

function sql_fetch_all($connection, string $sql, array $params = []): array
{
    $statement = sqlsrv_query($connection, $sql, $params);
    if ($statement === false) {
        throw new RuntimeException('Falha ao consultar dados no SQL Server.');
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($statement);
    return $rows;
}

function sql_exec($connection, string $sql, array $params = []): void
{
    $statement = sqlsrv_query($connection, $sql, $params);
    if ($statement === false) {
        $errors = sqlsrv_errors() ?: [];
        $message = $errors ? ($errors[0]['message'] ?? 'Falha ao executar comando SQL.') : 'Falha ao executar comando SQL.';
        throw new RuntimeException($message);
    }
    sqlsrv_free_stmt($statement);
}

function sql_date_to_iso(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function sql_datetime_to_iso(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('c', $timestamp) : null;
}

function sql_backend_available(): bool
{
    $connection = sql_connection(2);
    if (!$connection) {
        return false;
    }

    sqlsrv_close($connection);
    return true;
}

function sql_database_exists($connection): bool
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
        if ((int)(sql_scalar($connection, "SELECT COUNT(1) FROM sys.tables WHERE name = ?", [$table]) ?? 0) !== 1) {
            return false;
        }
    }

    return true;
}

function sql_read_database($connection): array
{
    if (!sql_database_exists($connection)) {
        throw new RuntimeException('Schema SQL Server incompleto para o sistema.');
    }

    $db = default_database_payload();
    $db['admins'] = [];
    $db['events'] = [];
    $db['criteria'] = [];
    $db['judges'] = [];
    $db['participants'] = [];
    $db['votes'] = [];
    $db['observations'] = [];
    $db['judge_reviews'] = [];

    foreach (sql_fetch_all($connection, "SELECT id, name, email, password_hash, created_at FROM dbo.admins ORDER BY id") as $row) {
        $db['admins'][] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'password' => (string)$row['password_hash'],
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
        ];
    }

    $eventNotifications = [];
    foreach (sql_fetch_all($connection, "SELECT * FROM dbo.event_notifications") as $row) {
        $eventNotifications[(int)$row['event_id']] = [
            'judge_open' => (bool)$row['judge_open'],
            'judge_reminder' => (bool)$row['judge_reminder'],
            'admin_complete' => (bool)$row['admin_complete'],
            'participant_results' => (bool)$row['participant_results'],
            'event_changes' => (bool)$row['event_changes'],
        ];
    }

    $eventPublication = [];
    foreach (sql_fetch_all($connection, "SELECT * FROM dbo.event_publication") as $row) {
        $eventPublication[(int)$row['event_id']] = [
            'auto_publish' => (bool)$row['auto_publish'],
            'publish_date' => sql_datetime_to_iso($row['publish_at'] ?? null) ?? '',
            'show_individual' => (bool)$row['show_individual_scores'],
            'show_comments' => (bool)$row['show_judge_comments'],
            'order' => (string)$row['result_order'],
        ];
    }

    $eventAdvanced = [];
    foreach (sql_fetch_all($connection, "SELECT * FROM dbo.event_advanced_settings") as $row) {
        $eventAdvanced[(int)$row['event_id']] = [
            'allow_edit_after_submit' => (bool)$row['allow_edit_after_submit'],
            'show_partial_average' => (bool)$row['show_partial_average'],
            'tie_breaker' => (string)$row['tie_breaker'],
            'decimal_places' => (int)$row['decimal_places'],
            'prevent_multi_login' => (bool)$row['prevent_multi_login'],
        ];
    }

    $eventPeriods = [];
    foreach (sql_fetch_all($connection, "SELECT event_id, period_key, starts_at, ends_at, status FROM dbo.event_periods ORDER BY event_id, id") as $row) {
        $eventId = (int)$row['event_id'];
        $eventPeriods[$eventId][(string)$row['period_key']] = [
            'start' => sql_datetime_to_iso($row['starts_at'] ?? null) ?? '',
            'end' => sql_datetime_to_iso($row['ends_at'] ?? null) ?? '',
            'status' => (string)$row['status'],
        ];
    }

    $phaseAdvancers = [];
    foreach (sql_fetch_all($connection, "SELECT event_id, classificatoria_count, semifinal_count, final_count FROM dbo.event_phase_advancers") as $row) {
        $phaseAdvancers[(int)$row['event_id']] = [
            'classificatoria' => (int)$row['classificatoria_count'],
            'semifinal' => (int)$row['semifinal_count'],
            'final' => (int)$row['final_count'],
        ];
    }

    foreach (sql_fetch_all($connection, "SELECT * FROM dbo.events ORDER BY id") as $row) {
        $eventId = (int)$row['id'];
        $db['events'][] = [
            'id' => $eventId,
            'name' => (string)$row['name'],
            'date' => sql_date_to_iso($row['start_date'] ?? null) ?? date('Y-m-d'),
            'status' => (string)$row['status'],
            'description' => (string)($row['description'] ?? ''),
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
            'updated_at' => sql_datetime_to_iso($row['updated_at'] ?? null) ?? null,
            'end_date' => sql_date_to_iso($row['end_date'] ?? null) ?? sql_date_to_iso($row['start_date'] ?? null) ?? date('Y-m-d'),
            'location' => (string)($row['location'] ?? ''),
            'evaluation_minutes' => (int)$row['evaluation_minutes'],
            'event_format' => (string)$row['event_format'],
            'advanced' => $eventAdvanced[$eventId] ?? [
                'allow_edit_after_submit' => false,
                'show_partial_average' => false,
                'tie_breaker' => 'highest_weight',
                'decimal_places' => 2,
                'prevent_multi_login' => true,
            ],
            'periods' => $eventPeriods[$eventId] ?? [],
            'publication' => $eventPublication[$eventId] ?? [
                'auto_publish' => true,
                'publish_date' => '',
                'show_individual' => false,
                'show_comments' => false,
                'order' => 'score_desc',
            ],
            'notifications' => $eventNotifications[$eventId] ?? [
                'judge_open' => true,
                'judge_reminder' => true,
                'admin_complete' => true,
                'participant_results' => true,
                'event_changes' => false,
            ],
            'phase_advancers' => $phaseAdvancers[$eventId] ?? [
                'classificatoria' => 12,
                'semifinal' => 6,
                'final' => 3,
            ],
        ];
    }

    foreach (sql_fetch_all($connection, "SELECT id, event_id, name, description, weight, display_order, created_at FROM dbo.criteria ORDER BY event_id, display_order, id") as $row) {
        $db['criteria'][] = [
            'id' => (int)$row['id'],
            'event_id' => (int)$row['event_id'],
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'weight' => (float)$row['weight'],
            'display_order' => (int)$row['display_order'],
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
        ];
    }

    foreach (sql_fetch_all($connection, "SELECT id, event_id, name, username, password_hash, status, created_at FROM dbo.judges ORDER BY id") as $row) {
        $db['judges'][] = [
            'id' => (int)$row['id'],
            'event_id' => (int)$row['event_id'],
            'name' => (string)$row['name'],
            'username' => (string)$row['username'],
            'password' => (string)$row['password_hash'],
            'status' => (string)$row['status'],
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
        ];
    }

    foreach (sql_fetch_all($connection, "SELECT id, event_id, name, category, song, presentation_order, photo_url, status, created_at FROM dbo.participants ORDER BY event_id, presentation_order, id") as $row) {
        $db['participants'][] = [
            'id' => (int)$row['id'],
            'event_id' => (int)$row['event_id'],
            'name' => (string)$row['name'],
            'category' => (string)($row['category'] ?? ''),
            'song' => (string)($row['song'] ?? ''),
            'order' => (int)$row['presentation_order'],
            'photo' => (string)($row['photo_url'] ?? ''),
            'status' => (string)$row['status'],
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
        ];
    }

    foreach (sql_fetch_all($connection, "SELECT id, event_id, judge_id, participant_id, criterion_id, score, created_at, updated_at FROM dbo.votes ORDER BY id") as $row) {
        $db['votes'][] = [
            'id' => (int)$row['id'],
            'event_id' => (int)$row['event_id'],
            'judge_id' => (int)$row['judge_id'],
            'participant_id' => (int)$row['participant_id'],
            'criterion_id' => (int)$row['criterion_id'],
            'score' => (float)$row['score'],
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
            'updated_at' => sql_datetime_to_iso($row['updated_at'] ?? null) ?? null,
        ];
    }

    foreach (sql_fetch_all($connection, "SELECT event_id, judge_id, participant_id, observation, created_at, updated_at FROM dbo.judge_observations ORDER BY id") as $row) {
        $db['observations'][] = [
            'event_id' => (int)$row['event_id'],
            'judge_id' => (int)$row['judge_id'],
            'participant_id' => (int)$row['participant_id'],
            'text' => (string)($row['observation'] ?? ''),
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
            'updated_at' => sql_datetime_to_iso($row['updated_at'] ?? null) ?? null,
        ];
    }

    foreach (sql_fetch_all($connection, "SELECT event_id, judge_id, participant_id, checklist_done, signature_mode, signature_text, signature_touch, created_at, updated_at FROM dbo.judge_reviews ORDER BY id") as $row) {
        $signatureMode = (string)$row['signature_mode'];
        $signatureText = (string)($row['signature_text'] ?? '');
        $signatureTouch = (string)($row['signature_touch'] ?? '');
        $db['judge_reviews'][] = [
            'event_id' => (int)$row['event_id'],
            'judge_id' => (int)$row['judge_id'],
            'participant_id' => (int)$row['participant_id'],
            'signature' => $signatureMode === 'touch' ? 'Assinatura touch' : $signatureText,
            'signature_mode' => $signatureMode,
            'signature_text' => $signatureText,
            'signature_touch' => $signatureTouch,
            'checklist_done' => (bool)$row['checklist_done'],
            'created_at' => sql_datetime_to_iso($row['created_at'] ?? null) ?? date('c'),
            'updated_at' => sql_datetime_to_iso($row['updated_at'] ?? null) ?? null,
        ];
    }

    return normalize_database($db);
}

function sql_insert_identity_rows($connection, string $table, array $rows): void
{
    if (!$rows) {
        return;
    }

    sql_exec($connection, "SET IDENTITY_INSERT dbo.{$table} ON");
    try {
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            sql_exec(
                $connection,
                "INSERT INTO dbo.{$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})",
                array_values($row)
            );
        }
    } finally {
        sql_exec($connection, "SET IDENTITY_INSERT dbo.{$table} OFF");
    }
}

function sql_write_database($connection, array $db): void
{
    $db = normalize_database($db);
    if (!sql_database_exists($connection)) {
        throw new RuntimeException('Schema SQL Server incompleto para escrita.');
    }

    if (!sqlsrv_begin_transaction($connection)) {
        throw new RuntimeException('Não foi possível iniciar a transação no SQL Server.');
    }

    try {
        sql_exec($connection, "EXEC sp_getapplock @Resource = 'festival_calouros_snapshot_write', @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 15000");

        foreach ([
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
        ] as $table) {
            sql_exec($connection, "DELETE FROM dbo.{$table}");
        }

        sql_insert_identity_rows($connection, 'admins', array_map(static function (array $admin): array {
            return [
                'id' => (int)$admin['id'],
                'name' => (string)$admin['name'],
                'email' => (string)$admin['email'],
                'password_hash' => (string)$admin['password'],
                'created_at' => date('Y-m-d H:i:s', strtotime($admin['created_at'] ?? 'now')),
            ];
        }, $db['admins']));

        sql_insert_identity_rows($connection, 'events', array_map(static function (array $event): array {
            $startDate = !empty($event['date']) ? date('Y-m-d', strtotime($event['date'])) : date('Y-m-d');
            $endDate = !empty($event['end_date']) ? date('Y-m-d', strtotime($event['end_date'])) : $startDate;
            return [
                'id' => (int)$event['id'],
                'name' => (string)$event['name'],
                'description' => (string)($event['description'] ?? ''),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'location' => (string)($event['location'] ?? ''),
                'status' => (string)($event['status'] ?? 'rascunho'),
                'event_format' => (string)($event['event_format'] ?? 'unica'),
                'evaluation_minutes' => max(1, (int)($event['evaluation_minutes'] ?? 136)),
                'created_at' => date('Y-m-d H:i:s', strtotime($event['created_at'] ?? 'now')),
                'updated_at' => !empty($event['updated_at']) ? date('Y-m-d H:i:s', strtotime($event['updated_at'])) : null,
            ];
        }, $db['events']));

        foreach ($db['events'] as $event) {
            $eventId = (int)$event['id'];
            $notifications = is_array($event['notifications'] ?? null) ? $event['notifications'] : [];
            $publication = is_array($event['publication'] ?? null) ? $event['publication'] : [];
            $advanced = is_array($event['advanced'] ?? null) ? $event['advanced'] : [];
            $periods = is_array($event['periods'] ?? null) ? $event['periods'] : [];
            $phaseAdvancers = is_array($event['phase_advancers'] ?? null) ? $event['phase_advancers'] : [];

            sql_exec($connection, "INSERT INTO dbo.event_notifications (event_id, judge_open, judge_reminder, admin_complete, participant_results, event_changes) VALUES (?, ?, ?, ?, ?, ?)", [
                $eventId,
                !empty($notifications['judge_open']) ? 1 : 0,
                !empty($notifications['judge_reminder']) ? 1 : 0,
                !empty($notifications['admin_complete']) ? 1 : 0,
                !empty($notifications['participant_results']) ? 1 : 0,
                !empty($notifications['event_changes']) ? 1 : 0,
            ]);

            sql_exec($connection, "INSERT INTO dbo.event_publication (event_id, auto_publish, publish_at, show_individual_scores, show_judge_comments, result_order) VALUES (?, ?, ?, ?, ?, ?)", [
                $eventId,
                !empty($publication['auto_publish']) ? 1 : 0,
                !empty($publication['publish_date']) ? date('Y-m-d H:i:s', strtotime($publication['publish_date'])) : null,
                !empty($publication['show_individual']) ? 1 : 0,
                !empty($publication['show_comments']) ? 1 : 0,
                (string)($publication['order'] ?? 'score_desc'),
            ]);

            sql_exec($connection, "INSERT INTO dbo.event_advanced_settings (event_id, allow_edit_after_submit, show_partial_average, tie_breaker, decimal_places, prevent_multi_login) VALUES (?, ?, ?, ?, ?, ?)", [
                $eventId,
                !empty($advanced['allow_edit_after_submit']) ? 1 : 0,
                !empty($advanced['show_partial_average']) ? 1 : 0,
                (string)($advanced['tie_breaker'] ?? 'highest_weight'),
                max(0, min(3, (int)($advanced['decimal_places'] ?? 2))),
                !empty($advanced['prevent_multi_login']) ? 1 : 0,
            ]);

            if (($event['event_format'] ?? 'unica') === 'fases' || $phaseAdvancers) {
                sql_exec($connection, "INSERT INTO dbo.event_phase_advancers (event_id, classificatoria_count, semifinal_count, final_count) VALUES (?, ?, ?, ?)", [
                    $eventId,
                    max(0, (int)($phaseAdvancers['classificatoria'] ?? 12)),
                    max(0, (int)($phaseAdvancers['semifinal'] ?? 6)),
                    max(0, (int)($phaseAdvancers['final'] ?? 3)),
                ]);
            }

            foreach ($periods as $periodKey => $period) {
                if (!is_array($period)) {
                    continue;
                }
                sql_exec($connection, "INSERT INTO dbo.event_periods (event_id, period_key, name, starts_at, ends_at, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                    $eventId,
                    (string)$periodKey,
                    ucfirst(str_replace('_', ' ', (string)$periodKey)),
                    !empty($period['start']) ? date('Y-m-d H:i:s', strtotime($period['start'])) : null,
                    !empty($period['end']) ? date('Y-m-d H:i:s', strtotime($period['end'])) : null,
                    (string)($period['status'] ?? 'programado'),
                    date('Y-m-d H:i:s'),
                ]);
            }
        }

        sql_insert_identity_rows($connection, 'judges', array_map(static function (array $judge): array {
            return [
                'id' => (int)$judge['id'],
                'event_id' => (int)$judge['event_id'],
                'name' => (string)$judge['name'],
                'username' => (string)$judge['username'],
                'password_hash' => (string)$judge['password'],
                'status' => (string)($judge['status'] ?? 'ativo'),
                'created_at' => date('Y-m-d H:i:s', strtotime($judge['created_at'] ?? 'now')),
            ];
        }, $db['judges']));

        sql_insert_identity_rows($connection, 'participants', array_map(static function (array $participant): array {
            return [
                'id' => (int)$participant['id'],
                'event_id' => (int)$participant['event_id'],
                'name' => (string)$participant['name'],
                'category' => (string)($participant['category'] ?? ''),
                'song' => (string)($participant['song'] ?? ''),
                'presentation_order' => (int)($participant['order'] ?? 0),
                'photo_url' => (string)($participant['photo'] ?? ''),
                'status' => (string)($participant['status'] ?? 'ativo'),
                'created_at' => date('Y-m-d H:i:s', strtotime($participant['created_at'] ?? 'now')),
            ];
        }, $db['participants']));

        sql_insert_identity_rows($connection, 'criteria', array_map(static function (array $criterion): array {
            return [
                'id' => (int)$criterion['id'],
                'event_id' => (int)$criterion['event_id'],
                'name' => (string)$criterion['name'],
                'description' => (string)($criterion['description'] ?? ''),
                'weight' => (float)($criterion['weight'] ?? 1),
                'display_order' => (int)($criterion['display_order'] ?? 0),
                'created_at' => date('Y-m-d H:i:s', strtotime($criterion['created_at'] ?? 'now')),
            ];
        }, $db['criteria']));

        sql_insert_identity_rows($connection, 'votes', array_map(static function (array $vote): array {
            return [
                'id' => (int)$vote['id'],
                'event_id' => (int)$vote['event_id'],
                'judge_id' => (int)$vote['judge_id'],
                'participant_id' => (int)$vote['participant_id'],
                'criterion_id' => (int)$vote['criterion_id'],
                'score' => (float)$vote['score'],
                'created_at' => date('Y-m-d H:i:s', strtotime($vote['created_at'] ?? 'now')),
                'updated_at' => !empty($vote['updated_at']) ? date('Y-m-d H:i:s', strtotime($vote['updated_at'])) : null,
            ];
        }, $db['votes']));

        foreach ($db['observations'] as $observation) {
            sql_exec($connection, "INSERT INTO dbo.judge_observations (event_id, judge_id, participant_id, observation, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [
                (int)$observation['event_id'],
                (int)$observation['judge_id'],
                (int)$observation['participant_id'],
                (string)($observation['text'] ?? ''),
                !empty($observation['created_at']) ? date('Y-m-d H:i:s', strtotime($observation['created_at'])) : date('Y-m-d H:i:s'),
                !empty($observation['updated_at']) ? date('Y-m-d H:i:s', strtotime($observation['updated_at'])) : null,
            ]);
        }

        foreach ($db['judge_reviews'] as $review) {
            $signatureMode = in_array(($review['signature_mode'] ?? 'text'), ['text', 'touch'], true) ? $review['signature_mode'] : 'text';
            sql_exec($connection, "INSERT INTO dbo.judge_reviews (event_id, judge_id, participant_id, checklist_done, signature_mode, signature_text, signature_touch, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                (int)$review['event_id'],
                (int)$review['judge_id'],
                (int)$review['participant_id'],
                !empty($review['checklist_done']) ? 1 : 0,
                $signatureMode,
                (string)($review['signature_text'] ?? ''),
                (string)($review['signature_touch'] ?? ''),
                !empty($review['created_at']) ? date('Y-m-d H:i:s', strtotime($review['created_at'])) : date('Y-m-d H:i:s'),
                !empty($review['updated_at']) ? date('Y-m-d H:i:s', strtotime($review['updated_at'])) : null,
            ]);
        }

        if (!sqlsrv_commit($connection)) {
            throw new RuntimeException('Não foi possível confirmar a escrita no SQL Server.');
        }
    } catch (Throwable $exception) {
        sqlsrv_rollback($connection);
        throw $exception;
    }
}

function db_read(): array
{
    ensure_database();

    /* [MIGRACAO MySQL] Modo primario: o MySQL e a fonte da verdade.
     *
     * O db.json continua sendo escrito a cada leitura, mas ja como copia de
     * seguranca — nao mais como banco. Se o MySQL cair, a leitura degrada
     * para essa copia e o sistema segue exibindo dados; a ESCRITA, porem, e
     * bloqueada em db_write(), para nao criar duas verdades divergentes. */
    if (mysql_modo() === 'primario') {
        $doMysql = mysql_ler_banco();

        if ($doMysql !== null) {
            $doMysql = normalize_database($doMysql);
            write_local_database($doMysql);

            return $doMysql;
        }

        error_log('MySQL primário indisponível na leitura; usando a cópia local.');

        return read_local_database();
    }

    $connection = sql_connection(2);
    if ($connection) {
        try {
            $db = sql_read_database($connection);
            write_local_database($db);
            sqlsrv_close($connection);
            return $db;
        } catch (Throwable $exception) {
            sqlsrv_close($connection);
            error_log('SQL read fallback: ' . $exception->getMessage());
        }
    }

    return read_local_database();
}

function db_write(array $db): void
{
    ensure_database();
    $db = normalize_database($db);
    $sqlSaved = false;
    $connection = sql_connection(5);
    if ($connection) {
        try {
            sql_write_database($connection, $db);
            $sqlSaved = true;
        } catch (Throwable $exception) {
            error_log('SQL write fallback: ' . $exception->getMessage());
        } finally {
            sqlsrv_close($connection);
        }
    }

    /* [MIGRACAO MySQL] Grava os CADASTROS (evento, jurados, participantes,
     * criterios e configuracoes). Notas, observacoes e fichas ficam de fora
     * de proposito: elas so mudam pela escrita dirigida em save_votes, e e
     * exatamente isso que impede um cadastro salvo durante o evento de
     * apagar as notas ja lancadas. */
    $mysqlOk = mysql_ativo() ? mysql_sincronizar_cadastros($db) : true;

    /* Em modo primario, um cadastro que nao chegou ao MySQL nao pode ser
     * dado como salvo: gravar so no db.json criaria duas verdades, e a
     * proxima leitura (que vem do MySQL) faria a alteracao "sumir". */
    if (mysql_modo() === 'primario' && !$mysqlOk) {
        error_log('MySQL primário indisponível na escrita; alteração recusada.');
        http_response_code(503);
        exit('Banco de dados indisponível no momento. Sua alteração NÃO foi salva. Tente novamente em instantes.');
    }

    write_local_database($db);

    if ($sqlSaved) {
        $_SESSION['storage_backend'] = 'sqlsrv';
    } else {
        $_SESSION['storage_backend'] = 'local';
    }
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

/**
 * Apaga com segurança a foto de um participante.
 *
 * Só remove dentro da pasta de fotos: o caminho vem do banco, mas um valor
 * adulterado ali não pode virar exclusão de arquivo do sistema.
 */
function remover_foto_participante(string $caminhoRelativo): void
{
    if ($caminhoRelativo === '' || !str_starts_with($caminhoRelativo, 'public/uploads/participants/')) {
        return;
    }

    $alvo = realpath(__DIR__ . '/' . $caminhoRelativo);
    $base = realpath(PARTICIPANT_UPLOAD_DIR);

    if ($alvo && $base && str_starts_with($alvo, $base . DIRECTORY_SEPARATOR) && is_file($alvo)) {
        @unlink($alvo);
    }
}

function upload_participant_photo(int $participantId): string
{
    /* Antes, sem arquivo enviado, esta função devolvia o campo "Ou URL da
     * foto". Esse campo era `type="url"` e vinha preenchido com o caminho
     * local da imagem (public/uploads/...), que não é uma URL válida — o
     * navegador barrava o envio e o formulário simplesmente não salvava,
     * sem dizer por quê.
     *
     * O campo foi retirado. Sem arquivo novo, devolve vazio; quem chama
     * decide manter a foto anterior. */
    if (empty($_FILES['photo']['tmp_name']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        return '';
    }

    /* [SEGURANCA] A extensao era lida do nome enviado pelo usuario. A lista
     * branca ja barrava .php, mas nada garantia que o conteudo fosse mesmo
     * uma imagem. Agora o tipo vem do cabecalho binario do arquivo, e a
     * extensao gravada deriva dele — o nome enviado e ignorado. */
    $tipos = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    $info = @getimagesize($_FILES['photo']['tmp_name']);
    if ($info === false || !isset($tipos[$info[2]])) {
        return '';
    }

    // Limite de 5 MB: evita encher o disco do servidor.
    if (($_FILES['photo']['size'] ?? 0) > 5 * 1024 * 1024) {
        return '';
    }

    $extension = $tipos[$info[2]];

    if (!is_dir(PARTICIPANT_UPLOAD_DIR)) {
        mkdir(PARTICIPANT_UPLOAD_DIR, 0775, true);
    }

    $filename = 'participante-' . $participantId . '.' . $extension;
    $target = PARTICIPANT_UPLOAD_DIR . '/' . $filename;
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
        @chmod($target, 0644);
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
    /* Esta função só olhava para o SQL Server. Depois da migração, o banco
     * é o MySQL — e a tela de acesso exibia "Banco não configurado" a todo
     * visitante, mesmo com o sistema funcionando normalmente. Um aviso
     * alarmante e falso é pior do que nenhum aviso. */
    if (mysql_ativo()) {
        if (mysql_conexao()) {
            return mysql_modo() === 'primario'
                ? ['state' => 'online', 'label' => 'Banco de dados conectado']
                : ['state' => 'online', 'label' => 'Banco de dados sincronizado'];
        }

        return ['state' => 'warning', 'label' => 'Banco indisponível — somente leitura'];
    }

    // Integração legada com SQL Server, mantida para uso fora do servidor.
    if (!sql_config()) {
        return ['state' => 'warning', 'label' => 'Armazenamento local em uso'];
    }

    $connection = sql_connection(2);
    if ($connection) {
        $healthy = sql_database_exists($connection);
        sqlsrv_close($connection);

        return $healthy
            ? ['state' => 'online', 'label' => 'SQL Server conectado e em uso']
            : ['state' => 'warning', 'label' => 'SQL conectado, mas schema incompleto'];
    }

    return ['state' => 'warning', 'label' => 'Armazenamento local em uso'];
}

/**
 * Gera uma senha legível para ditar por telefone se preciso.
 *
 * Sem caracteres que se confundem na leitura (O/0, I/l/1), porque estas
 * senhas são transmitidas a pessoas — uma senha "segura" que ninguém
 * consegue digitar acaba anotada num papel colado no monitor.
 */
function gerar_senha(int $tamanho = 10): string
{
    $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alfabeto) - 1;
    $saida = '';

    for ($i = 0; $i < $tamanho; $i++) {
        $saida .= $alfabeto[random_int(0, $max)];
    }

    return $saida;
}

/**
 * Enfileira e tenta enviar as credenciais por WhatsApp.
 * Devolve uma frase para juntar ao aviso da tela (ou '' se não se aplica).
 */
function enviar_credenciais(
    string $nome,
    string $usuario,
    string $senha,
    string $telefone,
    ?array $evento,
    int $eventoId,
    ?int $juradoId = null
): string {
    if (trim($telefone) === '') {
        return 'Sem telefone cadastrado — as credenciais não foram enviadas.';
    }

    if (!wa_ativo()) {
        return 'Envio por WhatsApp desativado — entregue a senha pessoalmente.';
    }

    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/';

    $texto = wa_texto_credenciais(
        $nome,
        $usuario,
        $senha,
        (string) ($evento['name'] ?? 'Festival'),
        $url
    );

    /* Via dedicada: dispara com o texto real e grava o histórico com a senha
     * mascarada — ver wa_enviar_credenciais(). */
    $r = wa_enviar_credenciais($nome, $telefone, $texto, $eventoId, $juradoId);

    return $r['ok']
        ? 'Credenciais enviadas por WhatsApp.'
        : 'Falha ao enviar por WhatsApp (' . $r['erro'] . '). Gere uma nova senha para tentar de novo.';
}

function is_admin(): bool
{
    return isset($_SESSION['admin_id']);
}

function is_judge(): bool
{
    return isset($_SESSION['judge_id']);
}

/**
 * [SEGURANCA] Conta com senha provisoria.
 *
 * Marcada assim, a conta entra mas nao chega a lugar nenhum ate trocar a
 * senha. A trava fica no require_admin() de proposito: e por onde passam
 * TODAS as telas e TODAS as acoes do administrador, entao nao ha rota
 * esquecida por onde a senha provisoria continue servindo.
 */
function precisa_trocar_senha(): bool
{
    return !empty($_SESSION['admin_trocar_senha']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect_to('admin-login');
    }

    if (precisa_trocar_senha()) {
        redirect_to('trocar-senha');
    }
}

function require_judge(): void
{
    if (!is_judge()) {
        redirect_to('judge-login');
    }
}

/**
 * Coloca a sessão do jurado dentro de um evento.
 *
 * O event_id NUNCA é aceito cru: só passa se estiver na lista montada no
 * login. Sem essa conferência, trocar o número na requisição daria acesso às
 * notas de qualquer evento.
 *
 * A contagem regressiva de cada evento começa aqui, e não no login: os três
 * eventos acontecem em sequência ao longo do dia, e um relógio disparado de
 * manhã para todos deixaria o jurado sem tempo no evento da tarde.
 */
function judge_entrar_no_evento(array $db, int $eventoId): bool
{
    foreach ($_SESSION['judge_acessos'] ?? [] as $acesso) {
        if ((int)$acesso['event_id'] !== $eventoId) {
            continue;
        }

        $_SESSION['judge_id'] = (int)$acesso['judge_id'];
        $_SESSION['judge_event_id'] = $eventoId;

        $evento = find_by_id($db['events'] ?? [], $eventoId);
        $_SESSION['judge_deadlines'][$eventoId] = $_SESSION['judge_deadlines'][$eventoId]
            ?? (time() + evaluation_seconds_from_event($evento));

        return true;
    }

    return false;
}

/** Os eventos que este jurado pode acessar, com o andamento de cada um. */
function judge_eventos_disponiveis(array $db): array
{
    $lista = [];

    foreach ($_SESSION['judge_acessos'] ?? [] as $acesso) {
        $evento = find_by_id($db['events'] ?? [], (int)$acesso['event_id']);

        if (!$evento) {
            continue;
        }

        $participantes = items_for_event($db['participants'] ?? [], (int)$evento['id']);
        $criterios = items_for_event($db['criteria'] ?? [], (int)$evento['id']);
        $avaliados = [];

        foreach ($db['votes'] ?? [] as $voto) {
            if ((int)$voto['event_id'] === (int)$evento['id']
                && (int)$voto['judge_id'] === (int)$acesso['judge_id']) {
                $avaliados[(int)$voto['participant_id']] = true;
            }
        }

        $lista[] = [
            'evento'        => $evento,
            'judge_id'      => (int)$acesso['judge_id'],
            'participantes' => count($participantes),
            'criterios'     => count($criterios),
            'avaliados'     => count($avaliados),
            'aberto'        => active_evaluation_period($evento),
            'atual'         => (int)$evento['id'] === (int)($_SESSION['judge_event_id'] ?? 0),
        ];
    }

    return $lista;
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
        $requestedId = (int)$_GET['event_id'];
        foreach ($db['events'] ?? [] as $event) {
            if ((int)$event['id'] === $requestedId) {
                return $requestedId;
            }
        }
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
            if (!isset($criteriaById[$criterionId])) {
                continue;
            }
            $weight = $criteriaById[$criterionId];
            $judgeScores[$judgeId]['points'] = ($judgeScores[$judgeId]['points'] ?? 0) + ((float)$vote['score'] * $weight);
            $judgeScores[$judgeId]['weights'] = ($judgeScores[$judgeId]['weights'] ?? 0) + $weight;
        }

        $total = 0;
        $judgeCount = 0;
        /* Soma bruta de nota × peso, de todos os jurados e critérios.
         * Diferente da média: cresce conforme mais jurados avaliam, e é o
         * número que a organização costuma acompanhar no placar. */
        $pontosTotais = 0.0;
        $notasLancadas = 0;

        foreach ($judgeScores as $score) {
            $pontosTotais += (float)($score['points'] ?? 0);
            if (($score['weights'] ?? 0) > 0) {
                $total += $score['points'] / $score['weights'];
                $judgeCount++;
            }
        }

        foreach ($participantVotes as $vote) {
            if (isset($criteriaById[(int)$vote['criterion_id']])) {
                $notasLancadas++;
            }
        }

        $ranking[] = [
            'participant' => $participant,
            // Média ponderada por jurado, promediada entre os jurados.
            'score' => $judgeCount > 0 ? $total / $judgeCount : 0,
            'total_points' => $pontosTotais,
            'judge_count' => $judgeCount,
            'vote_count' => $notasLancadas,
        ];
    }

    usort($ranking, fn($a, $b) => $b['score'] <=> $a['score']);
    return $ranking;
}

function total_scores_for_event(array $db, int $eventId): array
{
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $criteriaIds = array_map(fn($criterion) => (int)$criterion['id'], $criteria);
    $votes = array_values(array_filter(
        items_for_event($db['votes'] ?? [], $eventId),
        fn($vote) => in_array((int)$vote['criterion_id'], $criteriaIds, true)
    ));

    $rows = [];
    foreach ($participants as $participant) {
        $participantVotes = array_values(array_filter(
            $votes,
            fn($vote) => (int)$vote['participant_id'] === (int)$participant['id']
        ));
        $judgeIds = [];
        $total = 0.0;
        foreach ($participantVotes as $vote) {
            $judgeIds[(int)$vote['judge_id']] = true;
            $total += (float)$vote['score'];
        }

        $rows[] = [
            'participant' => $participant,
            'total_score' => $total,
            'notes_count' => count($participantVotes),
            'judge_count' => count($judgeIds),
        ];
    }

    usort($rows, function ($a, $b) {
        $scoreCompare = $b['total_score'] <=> $a['total_score'];
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return ((int)($a['participant']['order'] ?? 0)) <=> ((int)($b['participant']['order'] ?? 0));
    });

    return $rows;
}

function phase_advancers_for_event(array $event): array
{
    $defaults = [
        'classificatoria' => 12,
        'semifinal' => 6,
        'final' => 3,
    ];
    $configured = $event['phase_advancers'] ?? [];

    return [
        'classificatoria' => max(1, (int)($configured['classificatoria'] ?? $defaults['classificatoria'])),
        'semifinal' => max(1, (int)($configured['semifinal'] ?? $defaults['semifinal'])),
        'final' => max(1, (int)($configured['final'] ?? $defaults['final'])),
    ];
}

function phase_bracket_for_event(array $db, array $event): array
{
    if (($event['event_format'] ?? 'unica') !== 'fases') {
        return [];
    }

    $ranking = ranking_for_event($db, (int)$event['id']);
    $advancers = phase_advancers_for_event($event);
    $classCount = min(count($ranking), $advancers['classificatoria']);
    $semiCount = min($classCount, $advancers['semifinal']);
    $finalCount = min($semiCount, $advancers['final']);

    return [
        'classificatoria' => array_slice($ranking, 0, $classCount),
        'semifinal' => array_slice($ranking, 0, $semiCount),
        'final' => array_slice($ranking, 0, $finalCount),
    ];
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

function judge_review_for(array $db, int $eventId, int $judgeId, int $participantId): ?array
{
    foreach (($db['judge_reviews'] ?? []) as $review) {
        if (
            (int)$review['event_id'] === $eventId &&
            (int)$review['judge_id'] === $judgeId &&
            (int)$review['participant_id'] === $participantId
        ) {
            return $review;
        }
    }

    return null;
}

function signature_payload_from_review(?array $review): array
{
    $mode = (string)($review['signature_mode'] ?? '');
    $text = (string)($review['signature_text'] ?? ($review['signature'] ?? ''));
    $touch = (string)($review['signature_touch'] ?? '');

    if ($mode === '') {
        $mode = $touch !== '' ? 'touch' : 'text';
    }

    return [
        'mode' => $mode,
        'text' => $text,
        'touch' => $touch,
        'display' => $mode === 'touch' && $touch !== '' ? 'Assinatura touch' : $text,
    ];
}

function render_signature_markup(?array $review): string
{
    $signature = signature_payload_from_review($review);
    if ($signature['mode'] === 'touch' && $signature['touch'] !== '' && str_starts_with($signature['touch'], 'data:image/')) {
        return '<div class="signature-preview report"><img src="' . h($signature['touch']) . '" alt="Assinatura do jurado"></div>';
    }

    if ($signature['text'] !== '') {
        return '<span class="signature-text">' . h($signature['text']) . '</span>';
    }

    return '-';
}

function phase_report_rows(array $phaseBracket): array
{
    $labels = [
        'classificatoria' => 'Classificatória',
        'semifinal' => 'Semifinal',
        'final' => 'Final',
    ];

    $rows = [];
    foreach ($labels as $key => $label) {
        foreach (($phaseBracket[$key] ?? []) as $index => $row) {
            $rows[] = [
                'phase_key' => $key,
                'phase_label' => $label,
                'position' => $index + 1,
                'participant' => $row['participant'],
                'score' => (float)($row['score'] ?? 0),
                'judge_count' => (int)($row['judge_count'] ?? 0),
            ];
        }
    }

    return $rows;
}

function participant_completion_matrix(array $db, int $eventId): array
{
    $participants = items_for_event($db['participants'] ?? [], $eventId);
    usort($participants, fn($a, $b) => ((int)$a['order'] <=> (int)$b['order']) ?: strcmp($a['name'], $b['name']));
    $judges = items_for_event($db['judges'] ?? [], $eventId);
    $criteria = items_for_event($db['criteria'] ?? [], $eventId);
    $criteriaTotal = count($criteria);
    $votes = items_for_event($db['votes'] ?? [], $eventId);

    $rows = [];
    foreach ($participants as $participant) {
        $judgeStatuses = [];
        $completedCount = 0;

        foreach ($judges as $judge) {
            $judgeVotes = array_values(array_filter(
                $votes,
                fn($vote) =>
                    (int)$vote['judge_id'] === (int)$judge['id']
                    && (int)$vote['participant_id'] === (int)$participant['id']
            ));
            $review = judge_review_for($db, $eventId, (int)$judge['id'], (int)$participant['id']);
            $hasAllScores = $criteriaTotal > 0 && count($judgeVotes) >= $criteriaTotal;
            $checklistDone = (bool)($review['checklist_done'] ?? false);
            $done = $hasAllScores && $checklistDone;
            if ($done) {
                $completedCount++;
            }

            $judgeStatuses[] = [
                'judge' => $judge,
                'done' => $done,
                'has_all_scores' => $hasAllScores,
                'checklist_done' => $checklistDone,
                'signature' => signature_payload_from_review($review),
            ];
        }

        $rows[] = [
            'participant' => $participant,
            'judge_statuses' => $judgeStatuses,
            'completed_count' => $completedCount,
            'judges_total' => count($judges),
            'all_done' => count($judges) > 0 && $completedCount === count($judges),
        ];
    }

    return [
        'participants' => $participants,
        'judges' => $judges,
        'rows' => $rows,
    ];
}

function redirect_query(string $query): void
{
    header('Location: ' . $query);
    exit;
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
        $judgeReviews = array_values(array_filter(
            $db['judge_reviews'] ?? [],
            fn($review) => (int)$review['event_id'] === $eventId && (int)$review['judge_id'] === (int)$judge['id']
        ));
        $lastVoteAt = '';

        foreach ($judgeVotes as $vote) {
            if (($vote['created_at'] ?? '') > $lastVoteAt) {
                $lastVoteAt = $vote['created_at'];
            }
        }

        $evaluatedParticipants = 0;
        $checklistsDone = 0;
        if ($criteriaTotal > 0) {
            foreach ($participants as $participant) {
                $participantVoteCount = count(array_filter(
                    $judgeVotes,
                    fn($vote) => (int)$vote['participant_id'] === (int)$participant['id']
                ));
                if ($participantVoteCount >= $criteriaTotal) {
                    $evaluatedParticipants++;
                }

                $review = null;
                foreach ($judgeReviews as $candidateReview) {
                    if ((int)$candidateReview['participant_id'] === (int)$participant['id']) {
                        $review = $candidateReview;
                        break;
                    }
                }
                if (($review['checklist_done'] ?? false) === true) {
                    $checklistsDone++;
                }
            }
        }

        $allParticipantsVoted = $participantsTotal > 0 && $evaluatedParticipants >= $participantsTotal;
        $allChecklistsDone = $participantsTotal > 0 && $checklistsDone >= $participantsTotal;

        $progress[] = [
            'judge' => $judge,
            'notes_count' => count($judgeVotes),
            'participants_done' => $evaluatedParticipants,
            'participants_total' => $participantsTotal,
            'pending' => max(0, $participantsTotal - $evaluatedParticipants),
            'checklists_done' => $checklistsDone,
            'all_participants_voted' => $allParticipantsVoted,
            'all_checklists_done' => $allChecklistsDone,
            'ready_checkbox' => $allParticipantsVoted && $allChecklistsDone,
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
    $ranking = total_scores_for_event($db, $eventId);
    $scoreByParticipant = [];
    foreach ($ranking as $row) {
        $scoreByParticipant[(int)$row['participant']['id']] = (float)$row['total_score'];
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
            $review = judge_review_for($db, $eventId, (int)$judge['id'], (int)$participant['id']);
            $judgeVotes = $votesByJudgeParticipant[$judgeParticipantKey] ?? [];
            $judgeTotal = 0.0;

            foreach ($judgeVotes as $vote) {
                $judgeTotal += (float)$vote['score'];
            }

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
                    'judge_total' => $judgeTotal,
                    'participant_total' => $scoreByParticipant[(int)$participant['id']] ?? 0,
                    'observation' => $observation['text'] ?? '',
                    'observation_updated_at' => $observation['updated_at'] ?? '',
                    'signature' => $review['signature'] ?? '',
                    'signature_mode' => signature_payload_from_review($review)['mode'],
                    'signature_text' => signature_payload_from_review($review)['text'],
                    'signature_touch' => signature_payload_from_review($review)['touch'],
                    'checklist_done' => (bool)($review['checklist_done'] ?? false),
                    'review_updated_at' => $review['updated_at'] ?? '',
                    'review' => $review,
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

function handle_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    // [SEGURANCA] Toda acao POST precisa comprovar que partiu deste site.
    csrf_validar();

    $db = db_read();
    $action = $_POST['action'] ?? '';

    if ($action === 'admin_login') {
        $email = strtolower(clean($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        // [SEGURANCA] Barra a tentativa antes de comparar qualquer senha.
        if (login_bloqueado($email)) {
            flash('Muitas tentativas. Tente novamente em ' . login_minutos_restantes($email) . ' minuto(s).', 'error');
            redirect_to('admin-login');
        }

        foreach ($db['admins'] ?? [] as $admin) {
            if (strtolower($admin['email']) === $email && password_verify($password, $admin['password'])) {
                // [SEGURANCA] Troca o ID da sessao no login: impede fixacao de
                // sessao, em que o atacante planta um ID conhecido antes.
                session_regenerate_id(true);
                login_limpar($email);
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['_ultimo_acesso'] = time();
                $_SESSION['admin_trocar_senha'] = !empty($admin['must_change_password']);

                if (precisa_trocar_senha()) {
                    redirect_to('trocar-senha');
                }

                redirect_to('dashboard');
            }
        }

        login_registrar_falha($email);
        flash('E-mail ou senha inválidos.', 'error');
        redirect_to('admin-login');
    }

    if ($action === 'judge_login') {
        $username = strtolower(clean($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        // [SEGURANCA] Ver comentario no login de administrador.
        if (login_bloqueado($username)) {
            flash('Muitas tentativas. Tente novamente em ' . login_minutos_restantes($username) . ' minuto(s).', 'error');
            redirect_to('judge-login');
        }

        /* Um jurado pode servir em mais de um evento.
         *
         * O cadastro guarda uma linha por (jurado, evento) — a chave única da
         * tabela é (event_id, username), não o username sozinho. Então o mesmo
         * usuário e a mesma senha aparecem em cada evento em que a pessoa
         * julga, e o login recolhe TODAS as linhas que conferem em vez de
         * parar na primeira.
         *
         * Sem isto, quem julga três modalidades precisaria de três logins
         * diferentes — e no meio de um festival isso vira jurado parado na
         * porta perguntando qual senha usar. */
        $acessos = [];
        foreach ($db['judges'] ?? [] as $judge) {
            if (strtolower($judge['username']) !== $username
                || !password_verify($password, $judge['password'])) {
                continue;
            }

            if (($judge['status'] ?? 'ativo') !== 'ativo') {
                continue;
            }

            $evento = find_by_id($db['events'] ?? [], (int)$judge['event_id']);

            /* Evento em rascunho não aparece: quem está montando o cadastro
               não quer o jurado entrando antes da hora. */
            if (!$evento || ($evento['status'] ?? '') === 'rascunho') {
                continue;
            }

            $acessos[] = [
                'judge_id' => (int)$judge['id'],
                'event_id' => (int)$judge['event_id'],
                'nome'     => (string)$judge['name'],
            ];
        }

        if ($acessos !== []) {
            session_regenerate_id(true);
            login_limpar($username);

            $_SESSION['judge_acessos'] = $acessos;
            $_SESSION['judge_name'] = $acessos[0]['nome'];
            $_SESSION['_ultimo_acesso'] = time();
            judge_entrar_no_evento($db, $acessos[0]['event_id']);

            /* Com mais de um evento, a escolha vem primeiro. */
            redirect_to('judge-panel', count($acessos) > 1 ? ['section' => 'eventos'] : []);
        }

        login_registrar_falha($username);
        flash('Acesso do jurado não encontrado.', 'error');
        redirect_to('judge-login');
    }

    /* Troca de evento dentro do painel do jurado. */
    if ($action === 'judge_trocar_evento') {
        require_judge();

        if (!judge_entrar_no_evento($db, (int)($_POST['event_id'] ?? 0))) {
            flash('Você não tem acesso a este evento.', 'error');
            redirect_to('judge-panel', ['section' => 'eventos']);
        }

        redirect_to('judge-panel');
    }

    if ($action === 'logout') {
        /* [SEGURANCA] session_destroy() sozinho apagava o arquivo no servidor
         * mas deixava $_SESSION preenchido no resto da requisicao e o cookie
         * no navegador. Aqui a sessao e limpa, o arquivo removido e o cookie
         * expirado — os tres passos. */
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

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
            'phase_advancers' => [
                'classificatoria' => max(1, (int)($_POST['class_advancers'] ?? 12)),
                'semifinal' => max(1, (int)($_POST['semi_advancers'] ?? 6)),
                'final' => max(1, (int)($_POST['final_advancers'] ?? 3)),
            ],
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
        flash('Evento criado com critérios padrão.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'jurados']);
    }

    if ($action === 'update_event') {
        require_admin();
        $eventId = (int)($_POST['event_id'] ?? 0);
        $eventFormat = clean($_POST['event_format'] ?? 'unica');

        /* A lista precisa existir ANTES do laço.
         *
         * Isto era `foreach ($db['events'] ?? [] as &$event)`. O `??` produz um
         * valor temporário, e a referência `&$event` passava a apontar para
         * essa cópia: as alterações eram gravadas nela e descartadas no fim do
         * laço. O formulário respondia "Evento atualizado.", o redirecionamento
         * acontecia, e nada mudava — o pior tipo de falha, porque parece
         * sucesso. Editar pela tela de Configurações funcionava, e é
         * justamente por já fazer esta atribuição antes. */
        $db['events'] = $db['events'] ?? [];

        foreach ($db['events'] as &$event) {
            if ((int)$event['id'] !== $eventId) {
                continue;
            }

            $event['name'] = clean($_POST['name'] ?? $event['name']);
            $event['date'] = clean($_POST['date'] ?? $event['date']);
            $event['status'] = clean($_POST['status'] ?? $event['status']);
            $event['description'] = clean($_POST['description'] ?? ($event['description'] ?? ''));
            $event['event_format'] = $eventFormat;
            $event['phase_advancers'] = [
                'classificatoria' => max(1, (int)($_POST['class_advancers'] ?? ($event['phase_advancers']['classificatoria'] ?? 12))),
                'semifinal' => max(1, (int)($_POST['semi_advancers'] ?? ($event['phase_advancers']['semifinal'] ?? 6))),
                'final' => max(1, (int)($_POST['final_advancers'] ?? ($event['phase_advancers']['final'] ?? 3))),
            ];

            if ($eventFormat === 'fases') {
                $event['periods'] = [
                    'classificatoria' => ['start' => clean($_POST['class_start'] ?? ''), 'end' => clean($_POST['class_end'] ?? ''), 'status' => clean($_POST['class_status'] ?? 'ativo')],
                    'semifinal' => ['start' => clean($_POST['semi_start'] ?? ''), 'end' => clean($_POST['semi_end'] ?? ''), 'status' => clean($_POST['semi_status'] ?? 'programado')],
                    'final' => ['start' => clean($_POST['final_start'] ?? ''), 'end' => clean($_POST['final_end'] ?? ''), 'status' => clean($_POST['final_status'] ?? 'programado')],
                ];
            } else {
                $existingSingle = $event['periods']['unica'] ?? ['start' => '', 'end' => '', 'status' => 'ativo'];
                $event['periods'] = [
                    'unica' => [
                        'start' => clean($_POST['single_start'] ?? ($existingSingle['start'] ?? '')),
                        'end' => clean($_POST['single_end'] ?? ($existingSingle['end'] ?? '')),
                        'status' => clean($_POST['single_status'] ?? ($existingSingle['status'] ?? 'ativo')),
                    ],
                ];
            }
            break;
        }
        unset($event);

        db_write($db);
        flash('Evento atualizado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'eventos']);
    }

    if ($action === 'delete_event') {
        require_admin();
        $eventId = (int)($_POST['event_id'] ?? 0);
        $participantIds = array_map(fn($participant) => (int)$participant['id'], items_for_event($db['participants'] ?? [], $eventId));
        $judgeIds = array_map(fn($judge) => (int)$judge['id'], items_for_event($db['judges'] ?? [], $eventId));
        $criterionIds = array_map(fn($criterion) => (int)$criterion['id'], items_for_event($db['criteria'] ?? [], $eventId));

        $db['events'] = array_values(array_filter($db['events'] ?? [], fn($event) => (int)$event['id'] !== $eventId));
        $db['participants'] = array_values(array_filter($db['participants'] ?? [], fn($participant) => (int)$participant['event_id'] !== $eventId));
        $db['judges'] = array_values(array_filter($db['judges'] ?? [], fn($judge) => (int)$judge['event_id'] !== $eventId));
        $db['criteria'] = array_values(array_filter($db['criteria'] ?? [], fn($criterion) => (int)$criterion['event_id'] !== $eventId));
        $db['votes'] = array_values(array_filter(
            $db['votes'] ?? [],
            fn($vote) => (int)$vote['event_id'] !== $eventId
                && !in_array((int)$vote['participant_id'], $participantIds, true)
                && !in_array((int)$vote['judge_id'], $judgeIds, true)
                && !in_array((int)$vote['criterion_id'], $criterionIds, true)
        ));
        $db['observations'] = array_values(array_filter(
            $db['observations'] ?? [],
            fn($observation) => (int)$observation['event_id'] !== $eventId
                && !in_array((int)$observation['participant_id'], $participantIds, true)
                && !in_array((int)$observation['judge_id'], $judgeIds, true)
        ));
        $db['judge_reviews'] = array_values(array_filter(
            $db['judge_reviews'] ?? [],
            fn($review) => (int)$review['event_id'] !== $eventId
                && !in_array((int)$review['participant_id'], $participantIds, true)
                && !in_array((int)$review['judge_id'], $judgeIds, true)
        ));

        db_write($db);
        flash('Evento excluído.');
        redirect_to('dashboard', ['section' => 'eventos']);
    }

    if ($action === 'create_criterion') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $db['criteria'][] = [
            'id' => next_id($db, 'criteria'),
            'event_id' => $eventId,
            'name' => clean($_POST['name'] ?? ''),
            // A coluna Descrição já existia na tabela, mas com texto fixo:
            // não havia campo para preenchê-la.
            'description' => mb_substr(clean($_POST['description'] ?? ''), 0, 255),
            'weight' => max((float)($_POST['weight'] ?? 1), 0.1),
        ];
        db_write($db);
        flash('Critério adicionado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'criterios']);
    }

    if ($action === 'update_criterion') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $criterionId = (int)$_POST['criterion_id'];
        $updated = false;

        foreach (($db['criteria'] ?? []) as $index => $criterion) {
            if ((int)$criterion['id'] !== $criterionId) {
                continue;
            }

            $eventId = (int)$criterion['event_id'];
            $db['criteria'][$index]['name'] = clean($_POST['name'] ?? '');
            $db['criteria'][$index]['description'] = mb_substr(clean($_POST['description'] ?? ''), 0, 255);
            $db['criteria'][$index]['weight'] = max((float)($_POST['weight'] ?? 1), 0.1);
            $updated = true;
            break;
        }

        if (!$updated) {
            flash('Critério não encontrado.', 'error');
            redirect_to('dashboard', ['event_id' => active_event_id($db), 'section' => 'criterios']);
        }

        db_write($db);
        flash('Critério atualizado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'criterios']);
    }

    if ($action === 'delete_criterion') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $criterionId = (int)$_POST['criterion_id'];
        foreach ($db['criteria'] ?? [] as $criterion) {
            if ((int)$criterion['id'] === $criterionId) {
                $eventId = (int)$criterion['event_id'];
                break;
            }
        }

        $db['criteria'] = array_values(array_filter($db['criteria'] ?? [], fn($criterion) => (int)$criterion['id'] !== $criterionId));
        $db['votes'] = array_values(array_filter($db['votes'] ?? [], fn($vote) => (int)$vote['criterion_id'] !== $criterionId));

        db_write($db);
        flash('Critério excluído.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'criterios']);
    }

    if ($action === 'create_judge') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $nome = clean($_POST['name'] ?? '');
        $usuario = strtolower(clean($_POST['username'] ?? ''));
        $telefone = clean($_POST['phone'] ?? '');

        /* [SEGURANCA] A senha padrao era '123456', fixa no codigo. Quem
         * cadastrasse um jurado sem preencher o campo criava um acesso de
         * senha trivial — e ninguem percebia. Agora, em branco, o sistema
         * sorteia uma senha e a mostra UMA vez para a organizacao. */
        $senha = (string)($_POST['password'] ?? '');
        $gerada = false;
        if (strlen($senha) < 6) {
            $senha = gerar_senha();
            $gerada = true;
        }

        $judgeId = next_id($db, 'judges');
        $db['judges'][] = [
            'id' => $judgeId,
            'event_id' => $eventId,
            'name' => $nome,
            'username' => $usuario,
            'phone' => $telefone,
            'password' => password_hash($senha, PASSWORD_DEFAULT),
            'created_at' => date('c'),
        ];
        db_write($db);

        $aviso = 'Jurado cadastrado.';
        if ($gerada) {
            // A senha nao fica guardada em lugar nenhum de forma recuperavel.
            $aviso .= ' Senha gerada: ' . $senha . ' — anote agora, ela nao pode ser consultada depois.';
        }

        // Envio das credenciais, se houver telefone e integracao ligada.
        $evento = find_by_id($db['events'] ?? [], $eventId);
        $envio = enviar_credenciais($nome, $usuario, $senha, $telefone, $evento, $eventId, $judgeId);
        if ($envio !== '') {
            $aviso .= ' ' . $envio;
        }

        flash($aviso);
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'jurados']);
    }

    if ($action === 'update_judge') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $judgeId = (int)$_POST['judge_id'];
        $password = (string)($_POST['password'] ?? '');
        $updated = false;

        foreach (($db['judges'] ?? []) as $index => $judge) {
            if ((int)$judge['id'] !== $judgeId) {
                continue;
            }

            $eventId = (int)$judge['event_id'];
            $db['judges'][$index]['name'] = clean($_POST['name'] ?? '');
            $db['judges'][$index]['username'] = strtolower(clean($_POST['username'] ?? ''));
            $db['judges'][$index]['phone'] = clean($_POST['phone'] ?? ($judge['phone'] ?? ''));
            if ($password !== '') {
                $db['judges'][$index]['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $updated = true;
            break;
        }

        if (!$updated) {
            flash('Jurado não encontrado.', 'error');
            redirect_to('dashboard', ['event_id' => active_event_id($db), 'section' => 'jurados']);
        }

        db_write($db);
        flash('Jurado atualizado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'jurados']);
    }

    if ($action === 'delete_judge') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $judgeId = (int)$_POST['judge_id'];
        foreach ($db['judges'] ?? [] as $judge) {
            if ((int)$judge['id'] === $judgeId) {
                $eventId = (int)$judge['event_id'];
                break;
            }
        }

        $db['judges'] = array_values(array_filter($db['judges'] ?? [], fn($judge) => (int)$judge['id'] !== $judgeId));
        $db['votes'] = array_values(array_filter($db['votes'] ?? [], fn($vote) => (int)$vote['judge_id'] !== $judgeId));
        $db['observations'] = array_values(array_filter($db['observations'] ?? [], fn($observation) => (int)$observation['judge_id'] !== $judgeId));
        $db['judge_reviews'] = array_values(array_filter($db['judge_reviews'] ?? [], fn($review) => (int)$review['judge_id'] !== $judgeId));

        db_write($db);
        flash('Jurado excluído.');
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

    if ($action === 'update_participant') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $participantId = (int)$_POST['participant_id'];
        $existingPhoto = clean($_POST['existing_photo'] ?? '');
        $updatedPhoto = upload_participant_photo($participantId);
        $updated = false;

        foreach (($db['participants'] ?? []) as $index => $participant) {
            if ((int)$participant['id'] !== $participantId) {
                continue;
            }

            $eventId = (int)$participant['event_id'];
            $db['participants'][$index]['name'] = clean($_POST['name'] ?? '');
            $db['participants'][$index]['category'] = clean($_POST['category'] ?? '');
            $db['participants'][$index]['song'] = clean($_POST['song'] ?? '');
            $db['participants'][$index]['order'] = (int)($_POST['order'] ?? 0);

            /* Regra da foto, nesta ordem:
             *   1. marcou "remover"  -> apaga a imagem do disco e limpa
             *   2. enviou arquivo    -> passa a valer o novo
             *   3. nada              -> mantém o que já estava */
            if (isset($_POST['remover_foto'])) {
                remover_foto_participante($existingPhoto);
                $db['participants'][$index]['photo'] = '';
            } elseif ($updatedPhoto !== '') {
                // Troca de formato (jpg -> png) deixaria o arquivo antigo órfão.
                if ($existingPhoto !== '' && $existingPhoto !== $updatedPhoto) {
                    remover_foto_participante($existingPhoto);
                }
                $db['participants'][$index]['photo'] = $updatedPhoto;
            } else {
                $db['participants'][$index]['photo'] = $existingPhoto;
            }

            $updated = true;
            break;
        }

        if (!$updated) {
            flash('Participante não encontrado.', 'error');
            redirect_to('dashboard', ['event_id' => active_event_id($db), 'section' => 'participantes']);
        }

        db_write($db);
        flash('Participante atualizado.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'participantes']);
    }

    if ($action === 'delete_participant') {
        require_admin();
        $eventId = (int)$_POST['event_id'];
        $participantId = (int)$_POST['participant_id'];
        foreach ($db['participants'] ?? [] as $participant) {
            if ((int)$participant['id'] === $participantId) {
                $eventId = (int)$participant['event_id'];
                break;
            }
        }

        $db['participants'] = array_values(array_filter($db['participants'] ?? [], fn($participant) => (int)$participant['id'] !== $participantId));
        $db['votes'] = array_values(array_filter($db['votes'] ?? [], fn($vote) => (int)$vote['participant_id'] !== $participantId));
        $db['observations'] = array_values(array_filter($db['observations'] ?? [], fn($observation) => (int)$observation['participant_id'] !== $participantId));
        $db['judge_reviews'] = array_values(array_filter($db['judge_reviews'] ?? [], fn($review) => (int)$review['participant_id'] !== $participantId));

        db_write($db);
        flash('Participante excluído.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'participantes']);
    }

    if ($action === 'create_admin') {
        require_admin();

        /* [SEGURANCA] Sem senha no formulario, a conta era criada com
         * 'admin123' silenciosamente — um administrador de senha conhecida,
         * sem ninguem perceber. Agora o cadastro exige uma senha. */
        $senhaNova = (string) ($_POST['password'] ?? '');
        if (strlen($senhaNova) < 8) {
            flash('Informe uma senha de ao menos 8 caracteres para o administrador.', 'error');
            redirect_to('dashboard', ['event_id' => active_event_id($db), 'section' => 'apuracao']);
        }

        $emailNovo = strtolower(clean($_POST['email'] ?? ''));

        foreach (($db['admins'] ?? []) as $a) {
            if (strtolower((string)$a['email']) === $emailNovo) {
                flash('Já existe um administrador com este e-mail.', 'error');
                redirect_to('dashboard', ['event_id' => active_event_id($db), 'section' => 'usuarios']);
            }
        }

        $db['admins'][] = [
            'id' => next_id($db, 'admins'),
            'name' => clean($_POST['name'] ?? ''),
            'email' => $emailNovo,
            'phone' => clean($_POST['phone'] ?? ''),
            'password' => password_hash($senhaNova, PASSWORD_DEFAULT),
            /* Quem cria a conta conhece a senha. Marcada assim, ela serve uma
               vez só: o dono troca no primeiro acesso e nem quem cadastrou
               continua com o acesso. Os formulários antigos, sem o campo,
               caem no padrão de exigir — o lado seguro. */
            'must_change_password' => ($_POST['exigir_troca'] ?? '1') === '1',
            'created_at' => date('c'),
        ];
        db_write($db);
        flash('Administrador cadastrado.');
        redirect_to('dashboard', ['event_id' => active_event_id($db), 'section' => 'usuarios']);
    }

    /* Troca da senha provisória.
     *
     * NÃO usa require_admin(): aquele redireciona justamente para cá quando a
     * marca está ativa, e a ação entraria em laço consigo mesma. A conferência
     * é feita aqui, direto na sessão.
     *
     * Exige a senha atual mesmo estando a pessoa logada. Sem isso, um
     * computador deixado aberto na tela de troca entrega a conta a quem
     * passar — e é uma tela por onde se passa exatamente uma vez, no
     * corre-corre do primeiro acesso. */
    if ($action === 'trocar_senha') {
        if (!is_admin()) {
            redirect_to('admin-login');
        }

        $atual = (string)($_POST['senha_atual'] ?? '');
        $nova = (string)($_POST['senha_nova'] ?? '');
        $confirma = (string)($_POST['senha_confirma'] ?? '');
        $euId = (int)($_SESSION['admin_id'] ?? 0);

        $eu = null;
        foreach (($db['admins'] ?? []) as $a) {
            if ((int)$a['id'] === $euId) {
                $eu = $a;
                break;
            }
        }

        if ($eu === null) {
            session_destroy();
            redirect_to('admin-login');
        }

        if (!password_verify($atual, (string)$eu['password'])) {
            flash('A senha atual está incorreta.', 'error');
            redirect_to('trocar-senha');
        }

        if (strlen($nova) < 8) {
            flash('A nova senha deve ter ao menos 8 caracteres.', 'error');
            redirect_to('trocar-senha');
        }

        if ($nova !== $confirma) {
            flash('A confirmação não confere com a nova senha.', 'error');
            redirect_to('trocar-senha');
        }

        /* Repetir a provisória não troca nada — seria cumprir a exigência sem
           resolver o que ela existe para resolver. */
        if (password_verify($nova, (string)$eu['password'])) {
            flash('A nova senha precisa ser diferente da atual.', 'error');
            redirect_to('trocar-senha');
        }

        foreach (($db['admins'] ?? []) as $i => $a) {
            if ((int)$a['id'] === $euId) {
                $db['admins'][$i]['password'] = password_hash($nova, PASSWORD_DEFAULT);
                $db['admins'][$i]['must_change_password'] = false;
                break;
            }
        }

        db_write($db);

        /* Sessão nova depois de trocar a senha: se alguém tinha capturado a
           anterior, ela morre aqui junto com a senha antiga. */
        session_regenerate_id(true);
        $_SESSION['admin_trocar_senha'] = false;

        flash('Senha alterada. Bem-vindo ao painel.');
        redirect_to('dashboard');
    }

    /* Edição de administrador.
     *
     * require_admin() no topo é o que restringe a ação: só quem já está
     * autenticado como administrador pode alterar qualquer conta. */
    if ($action === 'update_admin') {
        require_admin();

        $adminId = (int)($_POST['admin_id'] ?? 0);
        $nome = clean($_POST['name'] ?? '');
        $email = strtolower(clean($_POST['email'] ?? ''));
        $telefone = clean($_POST['phone'] ?? '');
        $senha = (string)($_POST['password'] ?? '');
        $destino = ['event_id' => active_event_id($db), 'section' => 'usuarios'];

        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Informe nome e um e-mail válido.', 'error');
            redirect_to('dashboard', $destino);
        }

        if ($senha !== '' && strlen($senha) < 8) {
            flash('A nova senha deve ter ao menos 8 caracteres.', 'error');
            redirect_to('dashboard', $destino);
        }

        // O e-mail é a chave de login: não pode colidir com outra conta.
        foreach (($db['admins'] ?? []) as $a) {
            if ((int)$a['id'] !== $adminId && strtolower((string)$a['email']) === $email) {
                flash('Este e-mail já pertence a outro administrador.', 'error');
                redirect_to('dashboard', $destino);
            }
        }

        $achou = false;
        foreach (($db['admins'] ?? []) as $i => $a) {
            if ((int)$a['id'] !== $adminId) {
                continue;
            }

            $db['admins'][$i]['name'] = $nome;
            $db['admins'][$i]['email'] = $email;
            $db['admins'][$i]['phone'] = $telefone;

            if ($senha !== '') {
                $db['admins'][$i]['password'] = password_hash($senha, PASSWORD_DEFAULT);

                /* Senha definida para OUTRA pessoa volta a ser provisória: quem
                   digitou aqui a conhece. Trocando a própria, não faz sentido
                   exigir troca de novo — acabou de ser trocada. */
                $db['admins'][$i]['must_change_password'] =
                    $adminId !== (int)($_SESSION['admin_id'] ?? 0)
                    && ($_POST['exigir_troca'] ?? '') === '1';
            }

            $achou = true;
            break;
        }

        if (!$achou) {
            flash('Administrador não encontrado.', 'error');
            redirect_to('dashboard', $destino);
        }

        db_write($db);

        /* Se a pessoa alterou a própria senha, a sessão continua válida —
         * mas o nome exibido no topo ficaria desatualizado até o próximo
         * login. Atualiza aqui. */
        if ((int)($_SESSION['admin_id'] ?? 0) === $adminId) {
            $_SESSION['admin_name'] = $nome;
        }

        flash('Administrador atualizado.' . ($senha !== '' ? ' A senha foi alterada.' : ''));
        redirect_to('dashboard', $destino);
    }

    /* Exclusão de administrador, com trava contra ficar sem ninguém. */
    if ($action === 'delete_admin') {
        require_admin();

        $adminId = (int)($_POST['admin_id'] ?? 0);
        $destino = ['event_id' => active_event_id($db), 'section' => 'usuarios'];

        if (count($db['admins'] ?? []) <= 1) {
            flash('Não é possível excluir o único administrador — o sistema ficaria sem acesso.', 'error');
            redirect_to('dashboard', $destino);
        }

        if ((int)($_SESSION['admin_id'] ?? 0) === $adminId) {
            flash('Você não pode excluir a própria conta enquanto está usando o sistema.', 'error');
            redirect_to('dashboard', $destino);
        }

        $db['admins'] = array_values(array_filter(
            $db['admins'] ?? [],
            static fn($a) => (int)$a['id'] !== $adminId
        ));
        db_write($db);

        flash('Administrador excluído.');
        redirect_to('dashboard', $destino);
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
                    $event['phase_advancers'] = [
                        'classificatoria' => max(1, (int)($_POST['class_advancers'] ?? ($event['phase_advancers']['classificatoria'] ?? 12))),
                        'semifinal' => max(1, (int)($_POST['semi_advancers'] ?? ($event['phase_advancers']['semifinal'] ?? 6))),
                        'final' => max(1, (int)($_POST['final_advancers'] ?? ($event['phase_advancers']['final'] ?? 3))),
                    ];
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
        flash('Configurações salvas.');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'configuracoes', 'config_tab' => $configTab]);
    }

    /* ===================================================================
     * Integração WhatsApp
     * =================================================================== */

    if ($action === 'save_whatsapp') {
        require_admin();

        $ok = wa_salvar_config([
            'wa_ativo'           => isset($_POST['wa_ativo']) ? '1' : '0',
            'wa_numero_saida'    => clean($_POST['wa_numero_saida'] ?? ''),
            'wa_phone_number_id' => clean($_POST['wa_phone_number_id'] ?? ''),
            'wa_business_id'     => clean($_POST['wa_business_id'] ?? ''),
            // Em branco preserva o token já gravado — ver wa_salvar_config().
            'wa_token'           => (string)($_POST['wa_token'] ?? ''),
            'wa_versao_api'      => clean($_POST['wa_versao_api'] ?? 'v20.0'),
            'wa_endpoint'        => clean($_POST['wa_endpoint'] ?? ''),
            'wa_notificar_voto'  => isset($_POST['wa_notificar_voto']) ? '1' : '0',
            'wa_ddi_padrao'      => preg_replace('/\D+/', '', (string)($_POST['wa_ddi_padrao'] ?? '55')) ?: '55',
        ]);

        flash($ok ? 'Configuração do WhatsApp salva.' : 'Não foi possível salvar a configuração.', $ok ? 'success' : 'error');
        redirect_to('dashboard', ['section' => 'whatsapp', 'event_id' => active_event_id($db)]);
    }

    if ($action === 'testar_whatsapp') {
        require_admin();
        $telefone = clean($_POST['telefone_teste'] ?? '');

        if ($telefone === '') {
            flash('Informe um telefone para o teste.', 'error');
            redirect_to('dashboard', ['section' => 'whatsapp', 'event_id' => active_event_id($db)]);
        }

        $id = wa_enfileirar(
            'avulsa',
            'Teste de integração',
            $telefone,
            "Teste de integração do sistema de notas de jurados.\n\nSe você recebeu esta mensagem, o envio automático está funcionando."
        );
        $r = $id ? wa_enviar($id) : ['ok' => false, 'erro' => 'Falha ao registrar.'];

        flash(
            $r['ok'] ? 'Mensagem de teste enviada.' : 'Falha no teste: ' . $r['erro'],
            $r['ok'] ? 'success' : 'error'
        );
        redirect_to('dashboard', ['section' => 'whatsapp', 'event_id' => active_event_id($db)]);
    }

    if ($action === 'reenviar_mensagem') {
        require_admin();
        $id = (int)($_POST['mensagem_id'] ?? 0);

        if ($id > 0) {
            $r = wa_enviar($id);
            flash($r['ok'] ? 'Mensagem enviada.' : 'Falha ao enviar: ' . $r['erro'], $r['ok'] ? 'success' : 'error');
        }

        redirect_to('dashboard', ['section' => 'mensagens', 'event_id' => active_event_id($db)]);
    }

    if ($action === 'reenviar_pendentes') {
        require_admin();
        $enviadas = 0;
        $falhas = 0;

        foreach (wa_mensagens(200, null, 'pendente') as $m) {
            $r = wa_enviar((int)$m['id']);
            $r['ok'] ? $enviadas++ : $falhas++;
        }
        foreach (wa_mensagens(200, null, 'erro') as $m) {
            $r = wa_enviar((int)$m['id']);
            $r['ok'] ? $enviadas++ : $falhas++;
        }

        flash("Reenvio concluído: {$enviadas} enviada(s), {$falhas} com falha.", $falhas ? 'error' : 'success');
        redirect_to('dashboard', ['section' => 'mensagens', 'event_id' => active_event_id($db)]);
    }

    /* Nova senha para um jurado + reenvio das credenciais.
     *
     * Não existe "reenviar a mesma senha": a senha só existe em hash, que é
     * de mão única. Reenviar exige gerar uma nova — e é isso que este bloco
     * faz, deixando claro na mensagem que a anterior deixou de valer. */
    if ($action === 'nova_senha_jurado') {
        require_admin();
        $judgeId = (int)($_POST['judge_id'] ?? 0);
        $eventId = active_event_id($db);

        foreach (($db['judges'] ?? []) as $i => $j) {
            if ((int)$j['id'] !== $judgeId) {
                continue;
            }

            $senha = gerar_senha();
            $db['judges'][$i]['password'] = password_hash($senha, PASSWORD_DEFAULT);
            $eventId = (int)$j['event_id'];
            db_write($db);

            $evento = find_by_id($db['events'] ?? [], $eventId);
            $envio = enviar_credenciais(
                (string)$j['name'],
                (string)$j['username'],
                $senha,
                (string)($j['phone'] ?? ''),
                $evento,
                $eventId,
                $judgeId
            );

            flash('Nova senha de ' . $j['name'] . ': ' . $senha . ' — a anterior deixou de valer. ' . $envio);
            redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'usuarios']);
        }

        flash('Jurado não encontrado.', 'error');
        redirect_to('dashboard', ['event_id' => $eventId, 'section' => 'usuarios']);
    }

    if ($action === 'save_votes') {
        require_judge();
        $eventId = (int)$_SESSION['judge_event_id'];
        $judgeId = (int)$_SESSION['judge_id'];
        $participantId = (int)$_POST['participant_id'];
        $rawScores = $_POST['scores'] ?? [];
        $observationText = clean((string)($_POST['observation'] ?? ''));
        $nextUrl = clean((string)($_POST['next_url'] ?? ''));
        $signatureMode = clean((string)($_POST['signature_mode'] ?? 'text'));
        $signatureText = clean((string)($_POST['signature'] ?? ''));
        $signatureTouch = trim((string)($_POST['signature_touch'] ?? ''));
        $checklistDone = isset($_POST['checklist_done']);
        $participant = find_by_id(items_for_event($db['participants'] ?? [], $eventId), $participantId);
        $criteria = items_for_event($db['criteria'] ?? [], $eventId);
        $criteriaIds = array_map(fn($criterion) => (int)$criterion['id'], $criteria);
        $validScores = [];
        $missingCriteriaIds = [];
        $invalidCriteriaIds = [];
        $redirectUrl = '?page=judge-panel&participant_id=' . $participantId . '&section=votacao';
        if ($nextUrl !== '' && str_starts_with($nextUrl, '?page=judge-panel')) {
            $redirectUrl = $nextUrl;
        }

        if (!empty($_SESSION['judge_finished'][$eventId]) || time() >= (int)($_SESSION['judge_deadlines'][$eventId] ?? PHP_INT_MAX)) {
            if (is_json_request()) {
                json_response(['ok' => false, 'message' => 'O tempo de avaliação foi encerrado.', 'redirect' => '?page=judge-panel&section=resumo'], 409);
            }
            flash('O tempo de avaliação foi encerrado.', 'error');
            redirect_to('judge-panel', ['section' => 'resumo']);
        }

        if (!active_evaluation_period(find_by_id($db['events'] ?? [], $eventId))) {
            if (is_json_request()) {
                json_response(['ok' => false, 'message' => 'Não há período de avaliação ativo no momento.', 'redirect' => '?page=judge-panel&section=participantes'], 409);
            }
            flash('Não há período de avaliação ativo no momento.', 'error');
            redirect_to('judge-panel', ['section' => 'participantes']);
        }

        if (!$participant) {
            if (is_json_request()) {
                json_response(['ok' => false, 'message' => 'Participante invalido para este evento.', 'redirect' => '?page=judge-panel&section=participantes'], 422);
            }
            flash('Participante inválido para este evento.', 'error');
            redirect_to('judge-panel', ['section' => 'participantes']);
        }

        if (!$criteriaIds) {
            if (is_json_request()) {
                json_response(['ok' => false, 'message' => 'Não há critérios ativos para este evento. Aguarde o administrador ajustar a avaliação.', 'redirect' => '?page=judge-panel&section=criterios'], 409);
            }
            flash('Não há critérios ativos para este evento.', 'error');
            redirect_to('judge-panel', ['section' => 'criterios']);
        }

        foreach ($rawScores as $criterionId => $score) {
            $criterionId = (int)$criterionId;
            if (!in_array($criterionId, $criteriaIds, true)) {
                $invalidCriteriaIds[] = $criterionId;
                continue;
            }
            $normalizedScore = trim((string)$score);
            if ($normalizedScore === '') {
                continue;
            }
            $validScores[$criterionId] = min(max((float)str_replace(',', '.', $normalizedScore), 0), 10);
        }

        foreach ($criteriaIds as $criterionId) {
            if (!array_key_exists($criterionId, $validScores)) {
                $missingCriteriaIds[] = $criterionId;
            }
        }

        sort($criteriaIds);
        $submittedCriteriaIds = array_keys($validScores);
        sort($submittedCriteriaIds);
        if ($missingCriteriaIds || $invalidCriteriaIds || $submittedCriteriaIds !== $criteriaIds) {
            $message = $missingCriteriaIds
                ? 'Preencha todas as notas antes de salvar e seguir para o próximo participante.'
                : 'Os critérios deste evento foram atualizados. Recarregue a página e revise a avaliação antes de salvar.';
            if (is_json_request()) {
                json_response(['ok' => false, 'message' => $message, 'redirect' => '?page=judge-panel&participant_id=' . $participantId . '&section=votacao'], 409);
            }
            flash($message, 'error');
            redirect_to('judge-panel', ['participant_id' => $participantId, 'section' => 'votacao']);
        }

        $db['votes'] = array_values(array_filter($db['votes'] ?? [], function ($vote) use ($eventId, $judgeId, $participantId, $validScores) {
            return !(
                (int)$vote['event_id'] === $eventId &&
                (int)$vote['judge_id'] === $judgeId &&
                (int)$vote['participant_id'] === $participantId &&
                isset($validScores[(int)$vote['criterion_id']])
            );
        }));

        foreach ($validScores as $criterionId => $score) {
            $db['votes'][] = [
                'id' => next_id($db, 'votes'),
                'event_id' => $eventId,
                'judge_id' => $judgeId,
                'participant_id' => $participantId,
                'criterion_id' => (int)$criterionId,
                'score' => $score,
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

        $db['judge_reviews'] = array_values(array_filter($db['judge_reviews'] ?? [], function ($review) use ($eventId, $judgeId, $participantId) {
            return !(
                (int)$review['event_id'] === $eventId &&
                (int)$review['judge_id'] === $judgeId &&
                (int)$review['participant_id'] === $participantId
            );
        }));
        $db['judge_reviews'][] = [
            'event_id' => $eventId,
            'judge_id' => $judgeId,
            'participant_id' => $participantId,
            'signature' => $signatureMode === 'touch' ? 'Assinatura touch' : $signatureText,
            'signature_mode' => in_array($signatureMode, ['text', 'touch'], true) ? $signatureMode : 'text',
            'signature_text' => $signatureText,
            'signature_touch' => str_starts_with($signatureTouch, 'data:image/') ? $signatureTouch : '',
            'checklist_done' => $checklistDone,
            'updated_at' => date('c'),
        ];

        db_write($db);

        /* [MIGRACAO MySQL] Escrita dirigida.
         *
         * Esta e a rota onde 8 jurados gravam ao mesmo tempo. Aqui cada um
         * grava APENAS as proprias linhas, apoiado nas chaves unicas do
         * schema — ao contrario do snapshot, que apagava tudo e reinseria,
         * fazendo o ultimo a salvar sobrescrever as notas dos demais. */
        if (mysql_ativo()) {
            $gravou = mysql_salvar_votos($eventId, $judgeId, $participantId, $validScores);
            mysql_salvar_observacao($eventId, $judgeId, $participantId, $observationText);
            mysql_salvar_ficha(
                $eventId,
                $judgeId,
                $participantId,
                $checklistDone,
                $signatureMode,
                $signatureText,
                str_starts_with($signatureTouch, 'data:image/') ? $signatureTouch : ''
            );

            /* Em modo primario o jurado nao pode ver "Notas salvas" se a nota
             * nao chegou ao banco que manda. Melhor pedir para repetir do que
             * descobrir a ausencia so na apuracao. */
            if (mysql_modo() === 'primario' && !$gravou) {
                $erro = 'Falha ao gravar as notas no banco. Elas NÃO foram salvas — tente novamente.';

                if (is_json_request()) {
                    json_response(['ok' => false, 'message' => $erro, 'redirect' => $redirectUrl], 503);
                }

                flash($erro, 'error');
                redirect_query($redirectUrl);
            }
        }

        /* Notificação de voto registrado.
         *
         * Enfileirada DEPOIS de a nota estar gravada — assim a mensagem nunca
         * anuncia um lançamento que não aconteceu. Uma falha de envio não
         * derruba a votação: fica registrada na fila para reenvio. */
        if (wa_ativo() && wa_get('wa_notificar_voto') === '1') {
            $jurado = find_by_id($db['judges'] ?? [], $judgeId);
            $telefoneJurado = (string)($jurado['phone'] ?? '');

            if ($telefoneJurado !== '') {
                $todos = items_for_event($db['participants'] ?? [], $eventId);
                $avaliados = [];
                foreach (($db['votes'] ?? []) as $v) {
                    if ((int)$v['event_id'] === $eventId && (int)$v['judge_id'] === $judgeId) {
                        $avaliados[(int)$v['participant_id']] = true;
                    }
                }

                $texto = wa_texto_voto(
                    (string)($jurado['name'] ?? 'Jurado'),
                    (string)($participant['name'] ?? 'Participante'),
                    (string)(find_by_id($db['events'] ?? [], $eventId)['name'] ?? 'Festival'),
                    count($avaliados),
                    count($todos)
                );

                $idMsg = wa_enfileirar(
                    'voto_registrado',
                    (string)($jurado['name'] ?? 'Jurado'),
                    $telefoneJurado,
                    $texto,
                    $eventId,
                    $judgeId,
                    $participantId
                );

                if ($idMsg > 0) {
                    wa_enviar($idMsg);
                }
            }
        }

        if (is_json_request()) {
            json_response([
                'ok' => true,
                'message' => 'Notas salvas.',
                'redirect' => $redirectUrl,
            ]);
        }
        flash('Notas salvas.');
        redirect_query($redirectUrl);
    }

    if ($action === 'finalize_evaluation') {
        require_judge();
        $eventId = (int)$_SESSION['judge_event_id'];
        $_SESSION['judge_finished'][$eventId] = true;
        $_SESSION['judge_deadlines'][$eventId] = time();
        flash('Avaliações finalizadas.');
        redirect_to('judge-panel', ['section' => 'resumo']);
    }

    /* -----------------------------------------------------------------------
     * Planilha SER SESC
     * -------------------------------------------------------------------- */

    /* Uma célula por requisição, respondida em JSON. É o que permite gravar
       enquanto se digita sem recarregar a página inteira e sem que duas
       pessoas editando blocos diferentes se atrapalhem. */
    if ($action === 'ser_salvar_nota') {
        require_admin();

        $alvo = ($_POST['alvo'] ?? '') === 'bloco' ? 'bloco' : 'turma';
        $id = (int)($_POST['id'] ?? 0);
        $campo = (string)($_POST['campo'] ?? '');
        $bruto = trim((string)($_POST['nota'] ?? ''));

        /* Campo esvaziado é "sem nota", não zero: zero é uma nota, e tratar
           os dois como a mesma coisa faria uma célula em branco valer ponto. */
        $nota = null;
        if ($bruto !== '') {
            $numero = str_replace(',', '.', $bruto);

            if (!is_numeric($numero)) {
                json_response(['ok' => false, 'mensagem' => 'Digite um número de 0 a 10.'], 422);
            }

            $nota = (float)$numero;
        }

        $resultado = ser_gravar_nota($alvo, $id, $campo, $nota, (string)($_SESSION['admin_name'] ?? ''));

        if (!$resultado['ok']) {
            json_response($resultado + ['revisao' => ser_revisao()], 422);
        }

        json_response($resultado + ser_resumo_para_json());
    }

    if ($action === 'ser_salvar_turma') {
        require_admin();
        $resultado = ser_gravar_turma(
            (int)($_POST['id'] ?? 0),
            clean($_POST['turma'] ?? ''),
            clean($_POST['pais'] ?? '')
        );
        flash($resultado['mensagem'], $resultado['ok'] ? 'success' : 'error');
        redirect_to('dashboard', ['section' => 'planilha']);
    }

    if ($action === 'ser_criar_turma') {
        require_admin();
        $resultado = ser_criar_turma(
            (int)($_POST['bloco_id'] ?? 0),
            clean($_POST['turma'] ?? ''),
            clean($_POST['pais'] ?? '')
        );
        flash($resultado['mensagem'], $resultado['ok'] ? 'success' : 'error');
        redirect_to('dashboard', ['section' => 'planilha']);
    }

    if ($action === 'ser_excluir_turma') {
        require_admin();
        $resultado = ser_excluir_turma((int)($_POST['id'] ?? 0));
        flash($resultado['mensagem'], $resultado['ok'] ? 'success' : 'error');
        redirect_to('dashboard', ['section' => 'planilha']);
    }

    if ($action === 'ser_importar') {
        require_admin();

        $arquivo = $_FILES['planilha'] ?? null;

        if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Escolha um arquivo .xlsx para importar.', 'error');
            redirect_to('dashboard', ['section' => 'planilha']);
        }

        if (!is_uploaded_file($arquivo['tmp_name'])) {
            flash('Envio inválido.', 'error');
            redirect_to('dashboard', ['section' => 'planilha']);
        }

        $lido = ser_xlsx_ler($arquivo['tmp_name']);

        if (!$lido['ok']) {
            flash($lido['mensagem'], 'error');
            redirect_to('dashboard', ['section' => 'planilha']);
        }

        $aplicado = ser_xlsx_aplicar($lido['blocos'], (string)($_SESSION['admin_name'] ?? ''));
        flash($aplicado['mensagem'], $aplicado['ok'] ? 'success' : 'error');
        redirect_to('dashboard', ['section' => 'planilha']);
    }
}

/**
 * Estado da planilha para a atualização automática.
 *
 * Devolve só a revisão quando o navegador já tem a versão atual: é uma
 * resposta de poucos bytes, e a grade não é redesenhada à toa por cima de
 * quem está digitando.
 */
function ser_responder_estado(): void
{
    $revisao = ser_revisao();

    if (($_GET['revisao'] ?? '') === $revisao && $revisao !== '') {
        json_response(['ok' => true, 'mudou' => false, 'revisao' => $revisao]);
    }

    $planilha = ser_ler();
    $celulas = [];

    foreach ($planilha['blocos'] as $b) {
        $celulas['b' . $b['id'] . '-danca'] = ser_numero_ou_vazio($b['danca']);
        $celulas['b' . $b['id'] . '-mosaico'] = ser_numero_ou_vazio($b['mosaico']);

        foreach ($b['turmas'] as $t) {
            foreach (array_keys(SER_CRITERIOS) as $coluna) {
                $celulas['t' . $t['id'] . '-' . $coluna] = ser_numero_ou_vazio($t[$coluna]);
            }
        }
    }

    json_response([
        'ok'      => true,
        'mudou'   => true,
        'celulas' => $celulas,
    ] + ser_resumo_para_json());
}

/** Envia o resultado como .xlsx ou .pdf e encerra a requisição. */
function ser_responder_download(): void
{
    $planilha = ser_ler();
    $pdf = ($_GET['ser_exportar'] ?? '') === 'pdf';
    $nome = 'PROJETO SER SESC - resultado ' . date('Y-m-d H\hi');

    if ($pdf) {
        $conteudo = ser_pdf_gerar($planilha);
        $tipo = 'application/pdf';
        $nome .= '.pdf';
    } else {
        $arquivo = ser_xlsx_gerar($planilha);

        if ($arquivo === null) {
            flash('Não foi possível gerar o arquivo.', 'error');
            redirect_to('dashboard', ['section' => 'planilha', 'etapa' => 'resultado']);
        }

        $conteudo = (string)file_get_contents($arquivo);
        @unlink($arquivo);
        $tipo = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $nome .= '.xlsx';
    }

    /* O filtro de CSRF é um callback de saída: sem limpar o buffer, o HTML
       já produzido entraria dentro do arquivo e o corromperia. */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $tipo);
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Content-Length: ' . (string)strlen($conteudo));
    header('Cache-Control: no-store');
    echo $conteudo;
    exit;
}

/** Nota para a tela: '' quando não lançada, sem zeros decorativos. */
function ser_numero_ou_vazio($valor): string
{
    if ($valor === null || $valor === '') {
        return '';
    }

    return rtrim(rtrim(number_format((float)$valor, 2, ',', ''), '0'), ',');
}

/** Totais recalculados, para o navegador atualizar os rodapés sem recarregar. */
function ser_resumo_para_json(): array
{
    $planilha = ser_ler();
    $blocos = [];

    foreach ($planilha['blocos'] as $b) {
        $turmas = [];
        foreach ($b['turmas'] as $t) {
            $turmas[(int)$t['id']] = (float)$t['total'];
        }

        $blocos[$b['id']] = [
            'total_individual' => $b['total_individual'],
            'total_geral'      => $b['total_geral'],
            'turmas'           => $turmas,
        ];
    }

    return ['revisao' => $planilha['revisao'], 'blocos' => $blocos];
}

/**
 * Icones do menu.
 *
 * Substituem as siglas em caixa alta ("IN", "EV", "JU"...) que apareciam
 * antes do titulo. Alem de poluirem a leitura, elas nao diziam nada a quem
 * usava o sistema pela primeira vez. Sao SVG inline, herdam a cor por
 * currentColor e nao dependem de nenhuma fonte de icones externa.
 */
function menu_icone(string $nome): string
{
    $formas = [
        'painel'        => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'evento'        => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'jurado'        => '<path d="M17 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="3.5"/><path d="M18 8h4M20 6v4"/>',
        'participante'  => '<path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="16.5" cy="16" r="2.5"/>',
        'criterio'      => '<path d="M9 5h9M9 12h9M9 19h9"/><path d="M4 5l1 1 2-2M4 12l1 1 2-2M4 19l1 1 2-2"/>',
        'apuracao'      => '<path d="M12 3v18M5 8v13M19 12v9"/><circle cx="12" cy="3" r="1.6"/><circle cx="5" cy="8" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'relatorio'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'placar'        => '<path d="M7 21h10M12 17v4"/><path d="M6 4h12v5a6 6 0 0 1-12 0z"/><path d="M6 6H3v2a3 3 0 0 0 3 3M18 6h3v2a3 3 0 0 1-3 3"/>',
        'exportar'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'configuracao'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H1a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 2.6 7a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H7a1.6 1.6 0 0 0 1-1.5V1a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V7a1.6 1.6 0 0 0 1.5 1H23a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'votacao'       => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'resumo'        => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'instrucao'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.8-.9 1.4v.6"/><path d="M12 17h.01"/>',
        'sair'          => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        // Tela de acesso
        'escudo'        => '<path d="M12 3l8 3v6c0 4.4-3.2 8.2-8 9-4.8-.8-8-4.6-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'pessoa'        => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'cadeado'       => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'whatsapp'      => '<path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.2-5.5A8.4 8.4 0 1 1 21 11.5z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.4-2-1-.9.8a5 5 0 0 1-2-2l.8-.9-1-2z"/>',
        'mensagem'      => '<path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 13h5"/>',
        'seta-baixo'    => '<path d="M6 9l6 6 6-6"/>',
        'planilha'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>',
        'telefone'      => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
    ];

    $forma = $formas[$nome] ?? $formas['painel'];

    return '<span class="menu-icone" aria-hidden="true"><svg viewBox="0 0 24 24">' . $forma . '</svg></span>';
}

/**
 * Iniciais para o avatar do cabeçalho.
 *
 * Primeira e última palavra do nome — "Maria Helena Souza" vira "MS". Sem
 * nome utilizável devolve "?", porque um avatar vazio parece defeito.
 * mb_* porque nomes acentuados são a regra, não a exceção.
 */
function perfil_iniciais(string $nome): string
{
    $partes = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if ($partes === []) {
        return '?';
    }

    $primeira = mb_substr($partes[0], 0, 1, 'UTF-8');
    $ultima = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1, 'UTF-8') : '';

    return mb_strtoupper($primeira . $ultima, 'UTF-8');
}

/**
 * Um item do menu lateral.
 *
 * Os rotulos usam letra maiuscula apenas na inicial da frase — "Painel
 * principal", não "Painel Principal". E a norma do portugues e reduz o
 * ruido visual de uma lista com dez entradas.
 */
function menu_item(string $href, string $icone, string $titulo, bool $ativo): string
{
    return sprintf(
        '<a class="%s" href="%s" data-titulo="%s"%s>%s<span class="menu-rotulo">%s</span></a>',
        $ativo ? 'active' : '',
        h($href),
        h($titulo),
        $ativo ? ' aria-current="page"' : '',
        menu_icone($icone),
        h($titulo)
    );
}

/**
 * Icone dos cartoes de resumo e de atalho.
 *
 * Substitui os simbolos que estavam no lugar dos icones — um quadrado vazio
 * para Eventos, um trevo para Jurados, um circulo para Participantes. Sao
 * caracteres tipograficos soltos, sem relacao com o que representam, e cada
 * sistema operacional desenha de um jeito (ou nao desenha).
 *
 * Reaproveita as mesmas formas do menu, para o icone de "Jurados" no cartao
 * ser igual ao de "Jurados" na lateral.
 */
function card_icone(string $nome, string $cor): string
{
    $extras = [
        'novo'      => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'estrela'   => '<path d="M12 3l2.6 5.6 6 .8-4.4 4.2 1.1 6-5.3-2.9L6.7 19.6l1.1-6L3.4 9.4l6-.8z"/>',
    ];

    $forma = $extras[$nome] ?? null;

    if ($forma === null) {
        // menu_icone já devolve o <span> pronto; aqui só o miolo interessa.
        $svg = menu_icone($nome);
        preg_match('/<svg viewBox="0 0 24 24">(.*)<\/svg>/s', $svg, $m);
        $forma = $m[1] ?? '';
    }

    return '<span class="metric-icon ' . h($cor) . '" aria-hidden="true">'
         . '<svg viewBox="0 0 24 24">' . $forma . '</svg></span>';
}

/**
 * URL de um arquivo estatico com marca de versão.
 *
 * O nginx serve CSS/JS com "expires 7d" e o dominio esta atras da
 * Cloudflare, que respeita esse cabecalho. Sem isto, uma correcao de estilo
 * levava até sete dias para chegar ao navegador do usuário — ou exigia
 * limpar o cache da Cloudflare na mao a cada publicação.
 *
 * Anexar a data de modificacao do arquivo faz a URL mudar junto com o
 * conteudo: a Cloudflare trata como recurso novo e busca na origem.
 */
function asset(string $caminho): string
{
    $absoluto = __DIR__ . '/' . ltrim($caminho, '/');
    $versao = is_file($absoluto) ? (string) filemtime($absoluto) : '0';

    return h($caminho . '?v=' . $versao);
}

/** Botao sanduiche. Recolhe o menu no desktop e abre a gaveta no celular. */
function menu_botao(): string
{
    return '<button class="app-menu-btn" type="button" data-toggle-menu'
         . ' aria-expanded="true" aria-controls="menu-lateral">'
         . '<span class="hamburguer" aria-hidden="true"><i></i><i></i><i></i></span>'
         . '<span class="sr-only">Abrir ou recolher o menu</span>'
         . '</button>';
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
        <meta name="color-scheme" content="light">
        <link rel="stylesheet" href="<?= asset('public/assets/css/app.css') ?>">
        <!-- Camada de refinamento: carregada depois, sobrepoe o necessario. -->
        <link rel="stylesheet" href="<?= asset('public/assets/css/ui.css') ?>">
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
                    <a href="?page=judge-panel">Votação</a>
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
        <script src="<?= asset('public/assets/js/app.js') ?>"></script>
        <!-- Menu recolhivel / gaveta. Carregado depois do app.js. -->
        <script src="<?= asset('public/assets/js/ui.js') ?>"></script>
    </body>
    </html>
    <?php
}

/* ===========================================================================
 * Telas de gestão de usuários e integração
 * ======================================================================== */

/** Painel único de administradores e jurados, com telefone e senha. */
function render_secao_usuarios(array $db, int $eventId): void
{
    $judges = $eventId ? items_for_event($db['judges'] ?? [], $eventId) : ($db['judges'] ?? []);
    $admins = $db['admins'] ?? [];
    $evento = $eventId ? find_by_id($db['events'] ?? [], $eventId) : null;
    ?>
    <section class="management-page">
        <div class="management-head">
            <h2>Usuários</h2>
        </div>

        <!-- ---------- Jurados ---------- -->
        <div class="panel data-panel">
            <div class="management-head compact">
                <h2>Jurados<?= $evento ? ' — ' . h($evento['name']) : '' ?></h2>
            </div>
            <div class="table-wrap">
                <table class="admin-table responsive-cards">
                    <thead>
                        <tr>
                            <th>Nome</th><th>Usuário</th><th>Telefone</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$judges): ?>
                        <tr><td colspan="4">Nenhum jurado cadastrado neste evento.</td></tr>
                    <?php else: foreach ($judges as $j): ?>
                        <tr>
                            <td data-label="Nome"><strong><?= h($j['name']) ?></strong></td>
                            <td data-label="Usuário"><?= h($j['username']) ?></td>
                            <td data-label="Telefone">
                                <?php $tel = (string)($j['phone'] ?? ''); ?>
                                <?= $tel !== '' ? h(wa_telefone_exibicao(wa_telefone($tel))) : '<span class="dica">não informado</span>' ?>
                            </td>
                            <td data-label="Ações" class="table-actions">
                                <a class="button small" href="?page=dashboard&section=jurados&event_id=<?= $eventId ?>&judge_edit=<?= (int)$j['id'] ?>">Editar</a>
                                <form method="post" class="em-linha"
                                      onsubmit="return confirm('Gerar uma nova senha para <?= h(addslashes($j['name'])) ?>? A senha atual deixa de valer.');">
                                    <input type="hidden" name="action" value="nova_senha_jurado">
                                    <input type="hidden" name="judge_id" value="<?= (int)$j['id'] ?>">
                                    <button class="button small" type="submit">Nova senha + enviar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---------- Administradores ---------- -->
        <?php
        $editId = isset($_GET['admin_edit']) ? (int)$_GET['admin_edit'] : 0;
        $emEdicao = null;
        foreach ($admins as $a) {
            if ((int)$a['id'] === $editId) {
                $emEdicao = $a;
                break;
            }
        }
        $eu = (int)($_SESSION['admin_id'] ?? 0);
        ?>

        <div class="panel data-panel">
            <div class="management-head compact">
                <h2>Administradores</h2>
            </div>
            <div class="table-wrap">
                <table class="admin-table responsive-cards">
                    <thead><tr><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($admins as $a): ?>
                        <tr>
                            <td data-label="Nome">
                                <strong><?= h($a['name']) ?></strong>
                                <?php if ((int)$a['id'] === $eu): ?>
                                    <span class="status-pill ativo">você</span>
                                <?php endif; ?>
                                <?php /* Saber quem ainda não trocou é o que permite cobrar. */ ?>
                                <?php if (!empty($a['must_change_password'])): ?>
                                    <span class="status-pill pendente">senha provisória</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="E-mail"><?= h($a['email']) ?></td>
                            <td data-label="Telefone">
                                <?php $tel = (string)($a['phone'] ?? ''); ?>
                                <?= $tel !== '' ? h(wa_telefone_exibicao(wa_telefone($tel))) : '<span class="dica">não informado</span>' ?>
                            </td>
                            <td data-label="Ações" class="table-actions">
                                <a class="button small" href="?page=dashboard&section=usuarios&event_id=<?= $eventId ?>&admin_edit=<?= (int)$a['id'] ?>#form-admin">Editar</a>
                                <?php /* Sem botão de excluir para a própria conta nem quando há
                                         apenas um administrador — o servidor também recusa. */ ?>
                                <?php if ((int)$a['id'] !== $eu && count($admins) > 1): ?>
                                    <form method="post" class="em-linha"
                                          onsubmit="return confirm('Excluir o administrador <?= h(addslashes($a['name'])) ?>?');">
                                        <input type="hidden" name="action" value="delete_admin">
                                        <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                                        <button class="button small" type="submit">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel form-stack compact-form" id="form-admin">
            <h2><?= $emEdicao ? 'Editar administrador' : 'Novo administrador' ?></h2>
            <form method="post" class="form-stack" autocomplete="off">
                <input type="hidden" name="action" value="<?= $emEdicao ? 'update_admin' : 'create_admin' ?>">
                <?php if ($emEdicao): ?>
                    <input type="hidden" name="admin_id" value="<?= (int)$emEdicao['id'] ?>">
                <?php endif; ?>

                <label>Nome
                    <input name="name" required maxlength="120" value="<?= h((string)($emEdicao['name'] ?? '')) ?>">
                </label>
                <label>E-mail
                    <input name="email" type="email" required maxlength="180" value="<?= h((string)($emEdicao['email'] ?? '')) ?>">
                </label>
                <label>Telefone (WhatsApp)
                    <input name="phone" inputmode="tel" placeholder="(92) 98888-7777" value="<?= h((string)($emEdicao['phone'] ?? '')) ?>">
                </label>
                <label>Senha
                    <input name="password" type="password" minlength="8" autocomplete="new-password"
                           <?= $emEdicao ? '' : 'required' ?>
                           placeholder="<?= $emEdicao ? 'deixe em branco para manter a atual' : 'mínimo 8 caracteres' ?>">
                </label>
                <?php if ($emEdicao): ?>
                    <p class="dica">A senha atual não pode ser consultada. Preencha apenas se for trocá-la.</p>
                <?php endif; ?>

                <?php /* A senha que você define aqui é combinada por fora — dita, escrita
                         num bilhete, mandada por mensagem. Enquanto ela valer, todo mundo
                         que passou por aquele canal tem o acesso. */ ?>
                <label class="linha-escolha">
                    <input type="checkbox" name="exigir_troca" value="1" checked>
                    <span>Exigir troca da senha no primeiro acesso</span>
                </label>

                <div class="form-actions">
                    <button class="button primary" type="submit">
                        <?= $emEdicao ? 'Salvar alterações' : 'Cadastrar administrador' ?>
                    </button>
                    <?php if ($emEdicao): ?>
                        <a class="button" href="?page=dashboard&section=usuarios&event_id=<?= $eventId ?>">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>
    <?php
}

/** Configuração da integração com o WhatsApp. */
function render_secao_whatsapp(array $db, int $eventId): void
{
    $c = wa_config(true);
    ?>
    <section class="management-page">
        <div class="management-head">
            <h2>WhatsApp</h2>
        </div>

        <div class="config-duas">
            <div class="panel form-stack compact-form">
                <h2>Canal WhatsApp Cloud API</h2>
                <form method="post" class="form-stack" autocomplete="off">
                    <input type="hidden" name="action" value="save_whatsapp">

                    <label>Número de saída
                        <input name="wa_numero_saida" value="<?= h($c['wa_numero_saida'] ?? '') ?>" placeholder="(92) 98487-8678">
                    </label>

                    <label>Phone Number ID
                        <input name="wa_phone_number_id" value="<?= h($c['wa_phone_number_id'] ?? '') ?>" inputmode="numeric">
                    </label>
                    <p class="dica">Use o Phone Number ID do API Setup, não o número de telefone.</p>

                    <label>Business Account ID
                        <input name="wa_business_id" value="<?= h($c['wa_business_id'] ?? '') ?>" inputmode="numeric">
                    </label>

                    <?php if (wa_token_do_ambiente()): ?>
                        <label>Access Token
                            <input type="password" value="" placeholder="definido no servidor" disabled>
                        </label>
                        <p class="dica">
                            O token vem de <code>FESTIVAL_WA_TOKEN</code>, definida no servidor.
                            É a forma mais segura: ele não passa pelo banco e, portanto, não
                            aparece em backup nem em cópia da base. Para alterá-lo, mude a
                            variável e recarregue o PHP-FPM.
                        </p>
                    <?php else: ?>
                        <label>Access Token
                            <input name="wa_token" type="password" autocomplete="new-password"
                                   placeholder="<?= wa_tem_token() ? '•••••••• (já configurado — deixe em branco para manter)' : 'cole o token aqui' ?>">
                        </label>
                        <p class="dica">
                            <?php /* O token nunca volta para a tela: o campo mostra apenas se ele
                                     existe. Se ele fosse devolvido no HTML, bastaria abrir o
                                     código-fonte da página para copiá-lo. */ ?>
                            Por segurança o token nunca é exibido de volta.
                            <?= wa_tem_token() ? 'Há um token gravado no banco.' : 'Nenhum token gravado ainda.' ?>
                            Para não guardá-lo no banco, defina <code>FESTIVAL_WA_TOKEN</code> no servidor.
                        </p>
                    <?php endif; ?>

                    <label>Versão da API
                        <input name="wa_versao_api" value="<?= h($c['wa_versao_api'] ?? 'v20.0') ?>">
                    </label>

                    <label>Endpoint próprio (opcional)
                        <input name="wa_endpoint" value="<?= h($c['wa_endpoint'] ?? '') ?>" placeholder="http://127.0.0.1:3000/api/whatsapp/send">
                    </label>
                    <p class="dica">Se preenchido, as mensagens vão para este serviço em vez da Graph API.</p>

                    <label>DDI padrão
                        <input name="wa_ddi_padrao" value="<?= h($c['wa_ddi_padrao'] ?? '55') ?>" inputmode="numeric" size="4">
                    </label>

                    <label class="caixa">
                        <input type="checkbox" name="wa_ativo" value="1" <?= ($c['wa_ativo'] ?? '0') === '1' ? 'checked' : '' ?>>
                        Ativar envio por WhatsApp
                    </label>

                    <label class="caixa">
                        <input type="checkbox" name="wa_notificar_voto" value="1" <?= ($c['wa_notificar_voto'] ?? '1') === '1' ? 'checked' : '' ?>>
                        Avisar o jurado a cada participante avaliado
                    </label>

                    <div class="form-actions">
                        <button class="button primary" type="submit">Salvar configuração</button>
                    </div>
                </form>
            </div>

            <div class="lado">
                <div class="panel">
                    <h2>Situação</h2>
                    <ul class="lista-status">
                        <li class="<?= wa_ativo() ? 'ok' : 'off' ?>">
                            <strong><?= wa_ativo() ? 'Integração ativa' : 'Integração inativa' ?></strong>
                            <small><?= wa_ativo()
                                ? 'As mensagens saem automaticamente.'
                                : 'Ative e informe token + Phone Number ID, ou um endpoint próprio.' ?></small>
                        </li>
                        <li class="<?= wa_tem_token() ? 'ok' : 'off' ?>">
                            <strong>Token de acesso</strong>
                            <small><?= wa_tem_token() ? 'Gravado.' : 'Não configurado.' ?></small>
                        </li>
                        <li class="<?= ($c['wa_endpoint'] ?? '') !== '' ? 'ok' : 'neutro' ?>">
                            <strong>Caminho de saída</strong>
                            <small><?= ($c['wa_endpoint'] ?? '') !== '' ? 'Endpoint próprio.' : 'Graph API do WhatsApp.' ?></small>
                        </li>
                    </ul>
                </div>

                <?php if (isset($_GET['diag'])): ?>
                    <div class="panel">
                        <h2>Diagnóstico</h2>
                        <ul class="lista-status">
                            <?php foreach (wa_diagnostico() as $d): ?>
                                <li class="<?= $d['ok'] ? 'ok' : 'off' ?>">
                                    <strong><?= h($d['etapa']) ?></strong>
                                    <small><?= h($d['detalhe']) ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="panel">
                        <h2>Diagnóstico</h2>
                        <p class="dica">Verifica token, número e permissões sem enviar mensagem.</p>
                        <a class="button" href="?page=dashboard&section=whatsapp&event_id=<?= $eventId ?>&diag=1">Verificar conexão</a>
                    </div>
                <?php endif; ?>

                <div class="panel form-stack">
                    <h2>Enviar um teste</h2>
                    <form method="post" class="form-stack">
                        <input type="hidden" name="action" value="testar_whatsapp">
                        <label>Telefone <input name="telefone_teste" inputmode="tel" placeholder="(92) 98888-7777" required></label>
                        <div class="form-actions">
                            <button class="button" type="submit">Enviar teste</button>
                        </div>
                    </form>
                </div>

                <div class="panel">
                    <h2>O que é enviado</h2>
                    <ul class="lista-status">
                        <li class="ok"><strong>Credenciais</strong><small>Ao cadastrar um jurado ou gerar nova senha.</small></li>
                        <li class="ok"><strong>Voto registrado</strong><small>Quando o jurado conclui um participante.</small></li>
                        <li class="ok"><strong>Fila</strong><small>Cada destinatário gera uma mensagem própria, com status e reenvio.</small></li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
    <?php
}

/** Acompanhamento das mensagens: status, erro e reenvio. */
function render_secao_mensagens(array $db, int $eventId): void
{
    $filtro = $_GET['status'] ?? '';
    $mensagens = wa_mensagens(150, $eventId ?: null, is_string($filtro) ? $filtro : '');
    $resumo = wa_resumo($eventId ?: null);
    $base = '?page=dashboard&section=mensagens&event_id=' . $eventId;
    ?>
    <section class="management-page">
        <div class="management-head">
            <h2>Mensagens</h2>
            <div class="management-actions">
                <form method="post" class="em-linha">
                    <input type="hidden" name="action" value="reenviar_pendentes">
                    <button class="button primary" type="submit">Reenviar pendentes e falhas</button>
                </form>
            </div>
        </div>

        <section class="summary-strip">
            <div><?= card_icone('relatorio', 'blue') ?><strong><?= (int)$resumo['total'] ?></strong><small>Total</small></div>
            <div><?= card_icone('votacao', 'green') ?><strong><?= (int)$resumo['enviado'] ?></strong><small>Enviadas</small></div>
            <div><?= card_icone('apuracao', 'gold') ?><strong><?= (int)$resumo['pendente'] ?></strong><small>Pendentes</small></div>
            <div><?= card_icone('instrucao', 'purple') ?><strong><?= (int)$resumo['erro'] ?></strong><small>Com erro</small></div>
        </section>

        <div class="filtros-linha">
            <a class="chip <?= $filtro === '' ? 'ativo' : '' ?>" href="<?= h($base) ?>">Todas</a>
            <a class="chip <?= $filtro === 'enviado' ? 'ativo' : '' ?>" href="<?= h($base) ?>&status=enviado">Enviadas</a>
            <a class="chip <?= $filtro === 'pendente' ? 'ativo' : '' ?>" href="<?= h($base) ?>&status=pendente">Pendentes</a>
            <a class="chip <?= $filtro === 'erro' ? 'ativo' : '' ?>" href="<?= h($base) ?>&status=erro">Com erro</a>
        </div>

        <div class="panel data-panel">
            <div class="table-wrap">
                <table class="admin-table responsive-cards">
                    <thead>
                        <tr><th>Quando</th><th>Destinatário</th><th>Tipo</th><th>Situação</th><th>Ações</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$mensagens): ?>
                        <tr><td colspan="5">Nenhuma mensagem registrada.</td></tr>
                    <?php else: foreach ($mensagens as $m): ?>
                        <tr>
                            <td data-label="Quando"><?= h(date('d/m H:i', strtotime((string)$m['criado_em']))) ?></td>
                            <td data-label="Destinatário">
                                <strong><?= h((string)$m['destinatario']) ?></strong><br>
                                <small><?= h(wa_telefone_exibicao((string)$m['telefone'])) ?></small>
                            </td>
                            <td data-label="Tipo">
                                <?php
                                $rotulos = [
                                    'credenciais'     => 'Credenciais',
                                    'voto_registrado' => 'Voto registrado',
                                    'avulsa'          => 'Avulsa',
                                ];
                                echo h($rotulos[$m['tipo']] ?? (string)$m['tipo']);
                                ?>
                            </td>
                            <td data-label="Situação">
                                <span class="status-pill <?= h((string)$m['status']) ?>"><?= h((string)$m['status']) ?></span>
                                <?php if (!empty($m['erro'])): ?>
                                    <br><small class="erro-texto"><?= h((string)$m['erro']) ?></small>
                                <?php endif; ?>
                                <?php if ((int)$m['tentativas'] > 1): ?>
                                    <br><small class="dica"><?= (int)$m['tentativas'] ?> tentativas</small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Ações" class="table-actions">
                                <?php if ($m['tipo'] === 'credenciais'): ?>
                                    <?php /* Não há reenvio aqui: a senha não fica guardada, então
                                             o conteúdo original não existe mais. Reenviar exige
                                             gerar uma nova senha. */ ?>
                                    <a class="button small" href="?page=dashboard&section=usuarios&event_id=<?= $eventId ?>">Gerar nova senha</a>
                                <?php else: ?>
                                    <form method="post" class="em-linha">
                                        <input type="hidden" name="action" value="reenviar_mensagem">
                                        <input type="hidden" name="mensagem_id" value="<?= (int)$m['id'] ?>">
                                        <button class="button small" type="submit">
                                            <?= $m['status'] === 'enviado' ? 'Reenviar' : 'Enviar' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php
}

/**
 * Planilha SER SESC.
 *
 * Quatro telas, na ordem da aba PROGRAMAÇÃO do arquivo original:
 *
 *   Individual -> Dança -> Mosaico -> Resultado
 *
 * Ficam separadas porque as etapas acontecem separadas no evento. Ter as três
 * na mesma tela obrigava a procurar, no meio de 26 turmas, os dois campos de
 * dança e mosaico daquele grupo.
 *
 * Cada campo grava sozinho ao sair dele, e a página consulta o servidor a
 * cada poucos segundos para refletir o que os outros lançaram — sem
 * recarregar, que roubaria o cursor de quem está digitando.
 */
function render_secao_planilha(): void
{
    if (!ser_disponivel()) {
        ?>
        <section class="management-page">
            <div class="management-head"><h2>Planilha SER SESC</h2></div>
            <div class="panel">
                <div class="info-note aviso">
                    Esta tela precisa do banco de dados, que está indisponível no momento.
                    Edição simultânea com gravação célula a célula não funciona sobre arquivo —
                    é justamente o problema que o módulo existe para resolver.
                </div>
            </div>
        </section>
        <?php
        return;
    }

    $etapa = (string)($_GET['etapa'] ?? 'individual');
    if (!isset(SER_ETAPAS[$etapa]) && $etapa !== 'resultado') {
        $etapa = 'individual';
    }

    $planilha = ser_ler();
    $pendencias = ser_pendencias($planilha);
    $edicaoId = isset($_GET['ser_turma_edit']) ? (int)$_GET['ser_turma_edit'] : 0;
    ?>
    <section class="management-page planilha-ser"
             data-ser-planilha
             data-revisao="<?= h($planilha['revisao']) ?>"
             data-intervalo="5">
        <div class="management-head">
            <h2>Planilha SER SESC</h2>
            <p class="dica">Última alteração:
                <strong data-ser-relogio><?= $planilha['atualizado'] !== ''
                    ? h(date('d/m/Y H:i', strtotime($planilha['atualizado'])))
                    : '—' ?></strong>
                <span data-ser-estado>· atualiza sozinho a cada 5 segundos</span>
            </p>
        </div>

        <?php /* Passos numerados: a ordem importa e é a mesma do evento. */ ?>
        <nav class="ser-etapas" aria-label="Etapas do projeto">
            <?php $n = 0; foreach (SER_ETAPAS as $chave => $dados): $n++; ?>
                <a class="<?= $etapa === $chave ? 'atual' : '' ?>"
                   href="?page=dashboard&section=planilha&etapa=<?= h($chave) ?>"
                   <?= $etapa === $chave ? 'aria-current="page"' : '' ?>>
                    <span class="ser-passo"><?= $n ?></span>
                    <span class="ser-nome"><?= h($dados['titulo']) ?></span>
                    <?php $falta = $pendencias[$chave]; ?>
                    <span class="ser-falta <?= $falta === 0 ? 'ok' : '' ?>">
                        <?= $falta === 0 ? 'completa' : $falta . ' a lançar' ?>
                    </span>
                </a>
            <?php endforeach; ?>
            <a class="<?= $etapa === 'resultado' ? 'atual' : '' ?>"
               href="?page=dashboard&section=planilha&etapa=resultado"
               <?= $etapa === 'resultado' ? 'aria-current="page"' : '' ?>>
                <span class="ser-passo">★</span>
                <span class="ser-nome">Resultado</span>
                <span class="ser-falta <?= $pendencias['total'] === 0 ? 'ok' : '' ?>">
                    <?= $pendencias['total'] === 0 ? 'fechado' : 'parcial' ?>
                </span>
            </a>
        </nav>

        <?php if ($etapa === 'resultado'): ?>
            <?php render_ser_resultado($planilha, $pendencias); ?>
        <?php elseif ($etapa === 'individual'): ?>
            <?php render_ser_individual($planilha, $edicaoId); ?>
        <?php else: ?>
            <?php render_ser_coletiva($planilha, $etapa); ?>
        <?php endif; ?>
    </section>
    <?php
}

/** Etapa 1: três notas por turma, agrupadas por grupo. */
function render_ser_individual(array $planilha, int $edicaoId): void
{
    ?>
    <p class="ser-descricao"><?= h(SER_ETAPAS['individual']['descricao']) ?>
        A soma das turmas é a pontuação do grupo nesta etapa; as turmas não disputam entre si.</p>

    <?php foreach ($planilha['blocos'] as $bloco): ?>
        <div class="panel data-panel planilha-bloco" data-bloco="<?= (int)$bloco['id'] ?>">
            <div class="management-head compact">
                <h2><?= h($bloco['nome']) ?></h2>
                <span class="status-pill ser-selo <?= $bloco['faltando']['individual'] === 0 ? 'ativo' : 'pendente' ?>"
                      data-ser-total-individual="<?= (int)$bloco['id'] ?>">
                    <?= h(ser_numero_ou_vazio($bloco['total_individual']) ?: '0') ?>
                    de <?= h(ser_numero_ou_vazio($bloco['maximo_individual'])) ?>
                </span>
            </div>

            <div class="table-wrap">
                <table class="admin-table responsive-cards planilha-grade">
                    <thead>
                        <tr>
                            <th>Turma</th>
                            <th>País</th>
                            <?php foreach (SER_CRITERIOS as $rotulo): ?>
                                <th><?= h($rotulo) ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bloco['turmas'] as $turma): ?>
                        <tr>
                            <td data-label="Turma"><strong><?= h($turma['turma']) ?></strong></td>
                            <td data-label="País"><?= h($turma['pais']) ?></td>
                            <?php foreach (array_keys(SER_CRITERIOS) as $coluna): ?>
                                <td data-label="<?= h(SER_CRITERIOS[$coluna]) ?>">
                                    <input class="ser-nota" type="text" inputmode="decimal"
                                           value="<?= h(ser_numero_ou_vazio($turma[$coluna])) ?>"
                                           data-ser-celula="t<?= (int)$turma['id'] ?>-<?= h($coluna) ?>"
                                           data-alvo="turma"
                                           data-id="<?= (int)$turma['id'] ?>"
                                           data-campo="<?= h($coluna) ?>"
                                           aria-label="<?= h(SER_CRITERIOS[$coluna] . ' — ' . $turma['turma']) ?>"
                                           placeholder="0 a 10">
                                </td>
                            <?php endforeach; ?>
                            <td data-label="Total" class="ser-total"
                                data-ser-total-turma="<?= (int)$turma['id'] ?>">
                                <?= h(ser_numero_ou_vazio($turma['total']) ?: '0') ?>
                            </td>
                            <td data-label="Ações" class="table-actions">
                                <a class="button small"
                                   href="?page=dashboard&section=planilha&etapa=individual&ser_turma_edit=<?= (int)$turma['id'] ?>#turma-<?= (int)$turma['id'] ?>">Editar</a>
                                <form method="post" class="em-linha"
                                      onsubmit="return confirm('Remover a turma <?= h(addslashes($turma['turma'])) ?>? As notas dela serão perdidas.');">
                                    <input type="hidden" name="action" value="ser_excluir_turma">
                                    <input type="hidden" name="id" value="<?= (int)$turma['id'] ?>">
                                    <button class="button small" type="submit">Excluir</button>
                                </form>
                            </td>
                        </tr>

                        <?php if ($edicaoId === (int)$turma['id']): ?>
                            <tr class="ser-linha-edicao" id="turma-<?= (int)$turma['id'] ?>">
                                <td colspan="<?= 4 + count(SER_CRITERIOS) ?>">
                                    <form method="post" class="ser-form-turma">
                                        <input type="hidden" name="action" value="ser_salvar_turma">
                                        <input type="hidden" name="id" value="<?= (int)$turma['id'] ?>">
                                        <label>Turma
                                            <input name="turma" required maxlength="60" value="<?= h($turma['turma']) ?>">
                                        </label>
                                        <label>País
                                            <input name="pais" required maxlength="60" value="<?= h($turma['pais']) ?>">
                                        </label>
                                        <button class="button primary" type="submit">Salvar</button>
                                        <a class="button ghost"
                                           href="?page=dashboard&section=planilha&etapa=individual">Cancelar</a>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <details class="ser-nova-turma">
                <summary>Adicionar turma a este grupo</summary>
                <form method="post" class="ser-form-turma">
                    <input type="hidden" name="action" value="ser_criar_turma">
                    <input type="hidden" name="bloco_id" value="<?= (int)$bloco['id'] ?>">
                    <label>Turma <input name="turma" required maxlength="60" placeholder="5º ANO E"></label>
                    <label>País <input name="pais" required maxlength="60" placeholder="ITÁLIA"></label>
                    <button class="button primary" type="submit">Adicionar</button>
                </form>
            </details>
        </div>
    <?php endforeach; ?>

    <div class="panel">
        <div class="management-head compact"><h2>Importar do Excel</h2></div>
        <form method="post" enctype="multipart/form-data" class="ser-form-importar">
            <input type="hidden" name="action" value="ser_importar">
            <label>Arquivo .xlsx <input type="file" name="planilha" accept=".xlsx" required></label>
            <button class="button primary" type="submit">Importar</button>
        </form>
        <p class="dica">
            Lê a aba <strong>INDIVIDUAL</strong>: cada linha "CATEGORIA : ..." abre um grupo e,
            abaixo dela, a turma vem na coluna A e o país na B. Turma que ainda não existe é
            criada; nota só é sobrescrita quando o arquivo traz um número — reimportar o
            gabarito original, onde as células dizem "0 A 10", não apaga o que já foi lançado.
            Atenção: a grafia dos países vem do arquivo e substitui a que estiver na tela.
        </p>
    </div>
    <?php
}

/** Etapas 2 e 3: uma nota por grupo, todas na mesma tabela. */
function render_ser_coletiva(array $planilha, string $etapa): void
{
    $campo = SER_ETAPAS[$etapa]['campo'];
    ?>
    <p class="ser-descricao"><?= h(SER_ETAPAS[$etapa]['descricao']) ?></p>

    <div class="panel data-panel">
        <div class="table-wrap">
            <table class="admin-table responsive-cards planilha-grade">
                <thead>
                    <tr><th>Categoria</th><th>Turno</th><th><?= h(SER_ETAPAS[$etapa]['titulo']) ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($planilha['blocos'] as $bloco): ?>
                    <tr>
                        <td data-label="Categoria">
                            <strong><?= h($bloco['categoria'] !== '' ? $bloco['categoria'] : $bloco['nome']) ?></strong>
                        </td>
                        <td data-label="Turno"><?= h($bloco['turno']) ?></td>
                        <td data-label="<?= h(SER_ETAPAS[$etapa]['titulo']) ?>">
                            <input class="ser-nota" type="text" inputmode="decimal"
                                   value="<?= h(ser_numero_ou_vazio($bloco[$campo])) ?>"
                                   data-ser-celula="b<?= (int)$bloco['id'] ?>-<?= h($campo) ?>"
                                   data-alvo="bloco"
                                   data-id="<?= (int)$bloco['id'] ?>"
                                   data-campo="<?= h($campo) ?>"
                                   aria-label="<?= h(SER_ETAPAS[$etapa]['titulo'] . ' — ' . $bloco['nome']) ?>"
                                   placeholder="0 a 10">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/** Tela final: a disputa de cada categoria e a saída dos arquivos. */
function render_ser_resultado(array $planilha, array $pendencias): void
{
    $disputas = ser_disputas($planilha);
    ?>
    <?php if ($pendencias['total'] > 0): ?>
        <div class="panel">
            <div class="info-note aviso">
                <strong>Resultado parcial.</strong>
                Faltam <?= (int)$pendencias['total'] ?> nota(s):
                <?= (int)$pendencias['individual'] ?> na individual,
                <?= (int)$pendencias['danca'] ?> na dança e
                <?= (int)$pendencias['mosaico'] ?> no mosaico.
                Os arquivos podem ser baixados assim mesmo — sairão marcados como parciais.
            </div>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="info-note">
                <strong>Todas as notas lançadas.</strong> O resultado abaixo é final.
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($disputas as $disputa): ?>
        <div class="panel data-panel">
            <div class="management-head compact">
                <h2><?= h($disputa['categoria']) ?></h2>
                <?php if ($disputa['empate']): ?>
                    <span class="status-pill ser-selo pendente">Empate</span>
                <?php elseif ($disputa['vencedor'] !== null): ?>
                    <span class="status-pill ser-selo ativo">Vencedor: <?= h($disputa['vencedor']['turno']) ?></span>
                <?php else: ?>
                    <span class="status-pill ser-selo pendente">Em aberto — faltam <?= (int)$disputa['faltando'] ?></span>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="admin-table responsive-cards">
                    <thead>
                        <tr><th>Turno</th><th>Individual</th><th>Dança</th><th>Mosaico</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($disputa['grupos'] as $grupo): ?>
                        <?php $campeao = $disputa['vencedor'] !== null && $grupo['id'] === $disputa['vencedor']['id']; ?>
                        <tr class="<?= $campeao ? 'ser-campeao' : '' ?>">
                            <td data-label="Turno"><strong><?= h($grupo['turno']) ?></strong></td>
                            <td data-label="Individual">
                                <?= h(ser_numero_ou_vazio($grupo['total_individual']) ?: '0') ?>
                                <small>de <?= h(ser_numero_ou_vazio($grupo['maximo_individual'])) ?></small>
                            </td>
                            <td data-label="Dança"><?= h(ser_numero_ou_vazio($grupo['danca']) ?: '—') ?></td>
                            <td data-label="Mosaico"><?= h(ser_numero_ou_vazio($grupo['mosaico']) ?: '—') ?></td>
                            <td data-label="Total" class="ser-total">
                                <?= h(ser_numero_ou_vazio($grupo['total_geral']) ?: '0') ?>
                                <small>de <?= h(ser_numero_ou_vazio($grupo['maximo_geral'])) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="panel">
        <div class="management-head compact"><h2>Levar os dados embora</h2></div>
        <div class="ser-saidas">
            <a class="button primary" href="?page=dashboard&section=planilha&ser_exportar=pdf">Baixar PDF</a>
            <a class="button" href="?page=dashboard&section=planilha&ser_exportar=xlsx">Baixar .xlsx</a>
        </div>
        <p class="dica">
            O PDF traz o resultado de cada categoria e as três etapas detalhadas, pronto para
            arquivar ou assinar. O .xlsx traz as mesmas informações em cinco abas —
            RESULTADO, INDIVIDUAL, DANÇA, MOSAICO e TOTAL — para quem precisar continuar
            trabalhando os números no Excel. Os dois marcam no topo se o resultado é parcial.
        </p>
    </div>
    <?php
}

function render_login(string $type): void
{
    render_header('Login');
    ?>
    <section class="sesc-login">
        <div class="sesc-arc top"></div>
        <div class="login-hero">
            <div class="sesc-logo"><span>Sesc</span></div>
            <span class="gold-line"></span>
            <h1>Sistema de Notas de Jurados</h1>
            <p>Escolha seu tipo de acesso para continuar</p>
            <?php /* O selo "Status da integração" foi retirado: informava o
                     estado do banco a qualquer visitante, antes mesmo do
                     login. Não ajudava quem vai entrar e sinalizava a um
                     eventual atacante o momento em que o banco está fora.
                     O estado da infraestrutura se acompanha pelos logs. */ ?>
        </div>

        <?php /* Alternador de acesso.
                 Em telas estreitas os dois cartões empilhados não cabem na
                 altura da janela — e cortá-los deixaria o botão do segundo
                 inalcançável. Aqui só um aparece por vez.
                 Feito com radio + CSS, sem JavaScript: se o script falhar,
                 a escolha continua funcionando. Em telas largas os radios
                 somem e os dois cartões voltam lado a lado. */ ?>
        <input class="aba-radio" type="radio" name="tipo-acesso" id="aba-admin" checked>
        <input class="aba-radio" type="radio" name="tipo-acesso" id="aba-jurado">

        <div class="login-abas" role="tablist" aria-label="Tipo de acesso">
            <label for="aba-admin"><?= menu_icone('escudo') ?><span>Administrador</span></label>
            <label for="aba-jurado"><?= menu_icone('jurado') ?><span>Jurado</span></label>
        </div>

        <div class="login-options">
            <?php /* Os ícones eram os caracteres ☆ ○ 👤 ▣ — desenhados de um jeito
                     em cada sistema, e sem relação com o que representam. Agora
                     usam os mesmos SVG do resto do sistema. */ ?>
            <form class="access-card form-stack" method="post" autocomplete="on">
                <div class="access-icon"><?= menu_icone('escudo') ?></div>
                <h2>Administrador</h2>
                <p>Acesso da organização do festival</p>
                <input type="hidden" name="action" value="admin_login">
                <label class="icon-field">
                    <?= menu_icone('pessoa') ?>
                    <input required name="email" type="email" autocomplete="username"
                           placeholder="E-mail" aria-label="E-mail do administrador">
                </label>
                <label class="icon-field">
                    <?= menu_icone('cadeado') ?>
                    <input required name="password" type="password" autocomplete="current-password"
                           placeholder="Senha" aria-label="Senha do administrador">
                </label>
                <button class="button primary" type="submit">Entrar como administrador</button>
            </form>

            <form class="access-card form-stack" method="post" autocomplete="on">
                <div class="access-icon"><?= menu_icone('jurado') ?></div>
                <h2>Jurado</h2>
                <p>Acesso para lançar as notas</p>
                <input type="hidden" name="action" value="judge_login">
                <label class="icon-field">
                    <?= menu_icone('pessoa') ?>
                    <input required name="username" type="text" autocomplete="username"
                           placeholder="Usuário" aria-label="Usuário do jurado">
                </label>
                <label class="icon-field">
                    <?= menu_icone('cadeado') ?>
                    <input required name="password" type="password" autocomplete="current-password"
                           placeholder="Senha" aria-label="Senha do jurado">
                </label>
                <button class="button primary" type="submit">Entrar como jurado</button>
            </form>
        </div>

        <?php /* "Lembrar-me" e "Esqueci minha senha" foram retirados: o primeiro
                 não fazia nada e o segundo apontava para "#". Botão que não
                 funciona ensina o usuário a desconfiar da tela inteira — e, num
                 formulário de acesso, some com a confiança de quem entra. */ ?>
        <p class="login-ajuda">Esqueceu a senha? Procure a organização do festival.</p>

        <footer class="login-footer">
            <span class="gold-line"></span>
            <p><strong>Sesc</strong> · Festival de Calouros <?= date('Y') ?></p>
        </footer>
    </section>
    <?php
    render_footer();
}

function render_login_home(): void
{
    render_login('home');
}

/**
 * Troca da senha provisória.
 *
 * Reaproveita a moldura da tela de acesso de propósito: quem chega aqui
 * acabou de entrar, e uma tela com a mesma cara não parece erro do sistema.
 *
 * Sem menu e sem link de volta — a única saída daqui é trocar a senha ou
 * sair. Um atalho para o painel tornaria a exigência opcional.
 */
function render_trocar_senha(): void
{
    if (!is_admin()) {
        redirect_to('admin-login');
    }

    /* Quem já trocou não tem o que fazer nesta tela. */
    if (!precisa_trocar_senha()) {
        redirect_to('dashboard');
    }

    render_header('Trocar senha');
    ?>
    <section class="sesc-login">
        <div class="sesc-arc top"></div>
        <div class="login-hero">
            <div class="sesc-logo"><span>Sesc</span></div>
            <span class="gold-line"></span>
            <h1>Defina sua senha</h1>
            <p>A senha atual é provisória e precisa ser trocada antes do primeiro acesso.</p>
        </div>

        <div class="login-options">
            <form class="access-card form-stack" method="post" autocomplete="on">
                <div class="access-icon"><?= menu_icone('cadeado') ?></div>
                <h2><?= h((string)($_SESSION['admin_name'] ?? 'Administrador')) ?></h2>
                <p>Escolha uma senha de ao menos 8 caracteres</p>

                <input type="hidden" name="action" value="trocar_senha">

                <label class="icon-field">
                    <?= menu_icone('cadeado') ?>
                    <input required name="senha_atual" type="password" autocomplete="current-password"
                           placeholder="Senha atual" aria-label="Senha atual">
                </label>
                <label class="icon-field">
                    <?= menu_icone('cadeado') ?>
                    <input required name="senha_nova" type="password" minlength="8"
                           autocomplete="new-password"
                           placeholder="Nova senha" aria-label="Nova senha">
                </label>
                <label class="icon-field">
                    <?= menu_icone('cadeado') ?>
                    <input required name="senha_confirma" type="password" minlength="8"
                           autocomplete="new-password"
                           placeholder="Repita a nova senha" aria-label="Repita a nova senha">
                </label>

                <button class="button primary" type="submit">Salvar e entrar</button>
            </form>
        </div>

        <p class="login-ajuda">
            A senha não pode ser consultada depois — guarde-a com você.
        </p>

        <footer class="login-footer">
            <span class="gold-line"></span>
            <form method="post" class="ser-saidas">
                <input type="hidden" name="action" value="logout">
                <button class="button ghost small" type="submit">Sair sem trocar</button>
            </form>
        </footer>
    </section>
    <?php
    render_footer();
}

function render_home(): void
{
    render_header('Inicio');
    ?>
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow">Sistema de avaliação</p>
            <h1>Festival de Calouros</h1>
            <p>Cadastre eventos, distribua jurados, organize participantes e acompanhe o ranking em tempo real.</p>
            <div class="actions">
                <a class="button primary" href="?page=admin-login">Painel do administrador</a>
                <a class="button ghost" href="?page=judge-login">Entrada do jurado</a>
            </div>
        </div>
        <div class="score-preview">
            <span>Nota média</span>
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

    /* Duas rotas da planilha respondem antes de qualquer HTML: o download do
       .xlsx precisa mandar os próprios cabeçalhos, e a consulta de estado é
       chamada de poucos em poucos segundos — montar o painel inteiro para
       devolver um resumo seria desperdício a cada chamada. */
    if (($_GET['section'] ?? '') === 'planilha') {
        if (isset($_GET['ser_estado'])) {
            ser_responder_estado();
        }

        if (isset($_GET['ser_exportar'])) {
            ser_responder_download();
        }
    }

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
    $totalScoreRows = $eventId ? total_scores_for_event($db, $eventId) : [];
    $phaseAdvancers = $event ? phase_advancers_for_event($event) : [];
    $phaseBracket = $event ? phase_bracket_for_event($db, $event) : [];
    $phaseReportRows = $event ? phase_report_rows($phaseBracket) : [];
    $eventEditId = isset($_GET['event_edit']) ? (int)$_GET['event_edit'] : 0;
    $eventToEdit = $eventEditId ? find_by_id($events, $eventEditId) : null;
    $judgeEditId = isset($_GET['judge_edit']) ? (int)$_GET['judge_edit'] : 0;
    $judgeToEdit = $judgeEditId ? find_by_id($judges, $judgeEditId) : null;
    $criterionEditId = isset($_GET['criterion_edit']) ? (int)$_GET['criterion_edit'] : 0;
    $criterionToEdit = $criterionEditId ? find_by_id($criteria, $criterionEditId) : null;
    $participantViewId = isset($_GET['participant_view']) ? (int)$_GET['participant_view'] : 0;
    $participantEditId = isset($_GET['participant_edit']) ? (int)$_GET['participant_edit'] : 0;
    $participantToView = $participantViewId ? find_by_id($participants, $participantViewId) : null;
    $participantToEdit = $participantEditId ? find_by_id($participants, $participantEditId) : null;

    render_header('Painel do Administrador');
    ?>
    <?php
    $sufixo = $eventId ? '&event_id=' . $eventId : '';
    $itensAdmin = [
        ['painel',        'painel',       'Painel principal'],
        ['eventos',       'evento',       'Eventos'],
        ['jurados',       'jurado',       'Jurados'],
        ['participantes', 'participante', 'Participantes'],
        ['criterios',     'criterio',     'Critérios'],
        ['apuracao',      'apuracao',     'Apuração'],
        ['relatorios',    'relatorio',    'Relatórios'],
        ['placar',        'placar',       'Placar em tempo real'],
        ['planilha',      'planilha',     'Planilha SER SESC'],
        ['exportar',      'exportar',     'Exportar notas'],
        ['usuarios',      'pessoa',       'Usuários e senhas'],
        ['whatsapp',      'whatsapp',     'WhatsApp'],
        ['mensagens',     'mensagem',     'Mensagens'],
        ['configuracoes', 'configuracao', 'Configurações do evento'],
    ];
    ?>
    <div class="admin-shell">
        <button class="menu-overlay" type="button" data-menu-overlay hidden aria-label="Fechar menu"></button>

        <aside class="admin-sidebar" id="menu-lateral">
            <div class="sidebar-head">
                <div class="sidebar-logo">
                    <div class="sesc-logo small"><span>Sesc</span></div>
                    <small>Sistema de notas de jurados</small>
                </div>
                <?= menu_botao() ?>
            </div>
            <nav class="admin-menu" aria-label="Menu do administrador">
                <?php foreach ($itensAdmin as [$secao, $icone, $titulo]): ?>
                    <?= menu_item('?page=dashboard&section=' . $secao . $sufixo, $icone, $titulo, $section === $secao) ?>
                <?php endforeach; ?>
            </nav>
            <form method="post" class="sidebar-logout">
                <input type="hidden" name="action" value="logout">
                <button type="submit"><?= menu_icone('sair') ?><span class="menu-rotulo">Sair</span></button>
            </form>
        </aside>

        <section class="admin-content">
            <header class="admin-top">
                <?= menu_botao() ?>
                <?php
                /* Quem está logado. Levantado ANTES do cabeçalho porque a
                   saudação também usa o nome — antes ela dizia "Olá,
                   Administrador" para todo mundo, inclusive com o nome real
                   ali do lado, no menu da conta. */
                $euId = (int)($_SESSION['admin_id'] ?? 0);
                $euAdmin = null;
                foreach ($db['admins'] ?? [] as $a) {
                    if ((int)$a['id'] === $euId) {
                        $euAdmin = $a;
                        break;
                    }
                }
                $euNome = (string)($_SESSION['admin_name'] ?? ($euAdmin['name'] ?? 'Administrador'));
                $euEmail = (string)($euAdmin['email'] ?? '');
                ?>
                <div>
                    <h1>Olá, <?= h($euNome) ?></h1>
                    <p>Bem-vindo ao painel administrativo</p>
                </div>
                <?php
                $euTel = wa_telefone((string)($euAdmin['phone'] ?? ''));
                $urlPerfil = '?page=dashboard&section=usuarios' . $sufixo
                    . ($euId > 0 ? '&admin_edit=' . $euId : '') . '#form-admin';
                ?>
                <div class="admin-profile" data-perfil>
                    <button class="perfil-gatilho" type="button" data-perfil-botao
                            aria-haspopup="true" aria-expanded="false" aria-controls="menu-perfil">
                        <span class="avatar" aria-hidden="true"><?= h(perfil_iniciais($euNome)) ?></span>
                        <span class="perfil-quem">
                            <strong><?= h($euNome) ?></strong>
                            <small>Administrador</small>
                        </span>
                        <?= menu_icone('seta-baixo') ?>
                        <span class="sr-only">Abrir menu da conta</span>
                    </button>

                    <div class="perfil-menu" id="menu-perfil" hidden>
                        <div class="perfil-cabeca">
                            <span class="avatar" aria-hidden="true"><?= h(perfil_iniciais($euNome)) ?></span>
                            <div>
                                <strong><?= h($euNome) ?></strong>
                                <span class="status-pill ativo">Administrador</span>
                            </div>
                        </div>

                        <ul class="perfil-dados">
                            <li><?= menu_icone('pessoa') ?><span><?= $euEmail !== '' ? h($euEmail) : 'E-mail não informado' ?></span></li>
                            <li><?= menu_icone('telefone') ?><span><?= $euTel !== '' ? h(wa_telefone_exibicao($euTel)) : 'Telefone não informado' ?></span></li>
                        </ul>

                        <a class="perfil-acao" href="<?= h($urlPerfil) ?>"><?= menu_icone('configuracao') ?><span>Meus dados</span></a>

                        <form method="post" class="perfil-acao-form">
                            <input type="hidden" name="action" value="logout">
                            <button class="perfil-acao sair" type="submit"><?= menu_icone('sair') ?><span>Sair da conta</span></button>
                        </form>
                    </div>
                </div>
            </header>

            <div class="admin-inner">

    <?php /* Telas novas: gestão de usuários, integração e fila de mensagens. */ ?>
    <?php if ($section === 'usuarios'): ?>
        <?php render_secao_usuarios($db, $eventId); ?>
    <?php endif; ?>

    <?php if ($section === 'whatsapp'): ?>
        <?php render_secao_whatsapp($db, $eventId); ?>
    <?php endif; ?>

    <?php if ($section === 'mensagens'): ?>
        <?php render_secao_mensagens($db, $eventId); ?>
    <?php endif; ?>

    <?php if ($section === 'planilha'): ?>
        <?php render_secao_planilha(); ?>
    <?php endif; ?>

    <?php if ($section === 'painel'): ?>
        <?php /* Sem atualizacao automatica aqui de proposito.
                 O Painel principal e tela de TRABALHO: tem formularios e
                 atalhos. Ele recarregava sozinho a cada 5 segundos, o que
                 dava a impressao de a pagina estar presa num loop e apagava
                 o que estivesse sendo digitado. As telas que realmente
                 precisam de atualizacao ao vivo (Placar e Acompanhamento)
                 mantem o data-refresh-seconds. */ ?>
        <section class="panel-main">
            <div class="section-title compact">
                <h2>Resumo do Evento Atual</h2>
                <div class="actions">
                    <form class="inline-form" method="get">
                        <input type="hidden" name="page" value="dashboard">
                        <input type="hidden" name="section" value="painel">
                        <select name="event_id" onchange="this.form.submit()">
                            <option>Selecione um evento</option>
                            <?php foreach ($events as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $eventId === (int)$item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a class="button primary" href="?page=dashboard&section=eventos">+ Criar Evento</a>
                </div>
            </div>

            <section class="summary-strip">
                <div><?= card_icone('evento', 'blue') ?><strong><?= count($events) ?></strong><small>Eventos</small></div>
                <div><?= card_icone('jurado', 'green') ?><strong><?= count($judges) ?></strong><small>Jurados</small></div>
                <div><?= card_icone('participante', 'gold') ?><strong><?= count($participants) ?></strong><small>Participantes</small></div>
                <div><?= card_icone('criterio', 'purple') ?><strong><?= count($criteria) ?></strong><small>Critérios</small></div>
                <div><?= card_icone('apuracao', 'sky') ?><strong><?= $ranking ? number_format((float)$ranking[0]['score'], 1, ',', '.') : '-' ?></strong><small>Apuração</small></div>
            </section>

            <h2>Ações Rápidas</h2>
            <section class="quick-grid">
                <a class="quick-card" href="?page=dashboard&section=eventos"><?= card_icone('novo', 'blue') ?><strong>Criar Evento</strong><small>Cadastre um novo evento no sistema.</small><em>Criar</em></a>
                <a class="quick-card" href="?page=dashboard&section=jurados<?= $eventId ? '&event_id=' . $eventId : '' ?>"><?= card_icone('jurado', 'green') ?><strong>Gerenciar Jurados</strong><small>Cadastre e gerencie os jurados do evento.</small><em>Acessar</em></a>
                <a class="quick-card" href="?page=dashboard&section=participantes<?= $eventId ? '&event_id=' . $eventId : '' ?>"><?= card_icone('participante', 'gold') ?><strong>Gerenciar Participantes</strong><small>Cadastre e gerencie os participantes.</small><em>Acessar</em></a>
                <a class="quick-card" href="?page=dashboard&section=criterios<?= $eventId ? '&event_id=' . $eventId : '' ?>"><?= card_icone('criterio', 'purple') ?><strong>Definir Critérios</strong><small>Configure os critérios de avaliação.</small><em>Acessar</em></a>
                <a class="quick-card" href="?page=dashboard&section=apuracao<?= $eventId ? '&event_id=' . $eventId : '' ?>"><?= card_icone('apuracao', 'sky') ?><strong>Apuração</strong><small>Acompanhe e finalize a apuração das notas.</small><em>Acessar</em></a>
            </section>

            <?php if ($event): ?>
                <div class="panel data-panel">
                    <div class="management-head compact">
                        <h2>Status dos Jurados</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="admin-table responsive-cards">
                            <thead><tr><th>Jurado</th><th>Avaliou todos</th><th>Checklist final</th><th>Pronto</th></tr></thead>
                            <tbody>
                            <?php foreach ($judgeProgress as $row): ?>
                                <tr>
                                    <td data-label="Jurado"><?= h($row['judge']['name']) ?></td>
                                    <td data-label="Avaliou todos"><input class="admin-status-checkbox" type="checkbox" disabled <?= $row['all_participants_voted'] ? 'checked' : '' ?>></td>
                                    <td data-label="Checklist final"><input class="admin-status-checkbox" type="checkbox" disabled <?= $row['all_checklists_done'] ? 'checked' : '' ?>></td>
                                    <td data-label="Pronto"><input class="admin-status-checkbox" type="checkbox" disabled <?= $row['ready_checkbox'] ? 'checked' : '' ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$judgeProgress): ?>
                                <tr><td colspan="4">Nenhum jurado cadastrado para este evento.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <section class="grid two">
                <div class="panel">
                    <h2>Eventos Recentes</h2>
                    <div class="list">
                        <?php foreach ($events as $item): ?>
                            <a class="list-row" href="?page=dashboard&section=painel&event_id=<?= (int)$item['id'] ?>">
                                <span><?= h($item['name']) ?></span>
                                <small><?= h($item['date']) ?> · <?= h($item['status']) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$events): ?>
                        <div class="empty-center"><strong>Nenhum evento cadastrado.</strong><small>Crie um novo evento para comecar.</small><a class="button primary" href="?page=dashboard&section=eventos">Criar Evento</a></div>
                    <?php endif; ?>
                </div>
                <div class="panel settings-list">
                    <h2>Configurações do Evento</h2>
                    <a href="?page=dashboard&section=eventos<?= $eventId ? '&event_id=' . $eventId : '' ?>">Informações Gerais <span>›</span></a>
                    <a href="?page=dashboard&section=criterios<?= $eventId ? '&event_id=' . $eventId : '' ?>">Pesos dos Critérios <span>›</span></a>
                    <a href="?page=dashboard&section=apuracao<?= $eventId ? '&event_id=' . $eventId : '' ?>">Outras Configurações <span>›</span></a>
                </div>
            </section>
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
                    <table class="admin-table responsive-cards">
                        <thead><tr><th>Nome do Evento</th><th>Data</th><th>Local</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>
                        <?php if (!$events): ?>
                            <tr><td colspan="5">Nenhum evento cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($events as $item): ?>
                            <tr>
                                <td data-label="Nome do Evento"><a class="table-link" href="?page=dashboard&section=eventos&event_id=<?= (int)$item['id'] ?>"><?= h($item['name']) ?></a></td>
                                <td data-label="Data"><?= h($item['date']) ?></td>
                                <td data-label="Local"><?= h($item['description'] ?: 'Sesc Centro') ?></td>
                                <td data-label="Status"><span class="status-pill <?= h($item['status']) ?>"><?= h($item['status']) ?></span></td>
                                <td data-label="Ações">
                                    <div class="table-actions">
                                        <a class="icon-action" href="?page=dashboard&section=eventos&event_id=<?= (int)$item['id'] ?>&event_edit=<?= (int)$item['id'] ?>#novo-evento">Editar</a>
                                        <a class="icon-action" href="?page=dashboard&section=configuracoes&event_id=<?= (int)$item['id'] ?>">Configurar</a>
                                        <form method="post" class="inline-delete-form" onsubmit="return confirm('Deseja excluir este evento? Essa ação também remove jurados, participantes, critérios e notas vinculados.');">
                                            <input type="hidden" name="action" value="delete_event">
                                            <input type="hidden" name="event_id" value="<?= (int)$item['id'] ?>">
                                            <button type="submit" class="icon-action danger">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <form id="novo-evento" class="panel form-stack compact-form event-create-form" method="post">
                <h2><?= $eventToEdit ? 'Editar evento' : 'Novo evento' ?></h2>
                <input type="hidden" name="action" value="<?= $eventToEdit ? 'update_event' : 'create_event' ?>">
                <?php if ($eventToEdit): ?>
                    <input type="hidden" name="event_id" value="<?= (int)$eventToEdit['id'] ?>">
                <?php endif; ?>
                <label>Nome do evento <input required name="name" placeholder="Festival de Música Sesc 2024" value="<?= h($eventToEdit['name'] ?? '') ?>"></label>
                <label>Data <input required name="date" type="date" value="<?= h($eventToEdit['date'] ?? '') ?>"></label>
                <label>Status
                    <select name="status">
                        <option value="rascunho" <?= ($eventToEdit['status'] ?? '') === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                        <option value="aberto" <?= ($eventToEdit['status'] ?? '') === 'aberto' ? 'selected' : '' ?>>Em andamento</option>
                        <option value="encerrado" <?= ($eventToEdit['status'] ?? '') === 'encerrado' ? 'selected' : '' ?>>Encerrado</option>
                    </select>
                </label>
                <label>Local / descrição <textarea name="description" rows="3"><?= h($eventToEdit['description'] ?? '') ?></textarea></label>
                <label>Formato do evento
                    <select name="event_format" data-phase-toggle>
                        <option value="unica" <?= ($eventToEdit['event_format'] ?? 'unica') === 'unica' ? 'selected' : '' ?>>Etapa única</option>
                        <option value="fases" <?= ($eventToEdit['event_format'] ?? '') === 'fases' ? 'selected' : '' ?>>Classificatória, semifinal e final</option>
                    </select>
                </label>
                <div class="phase-fields" hidden>
                    <h3>Fases do evento</h3>
                    <label>Classificatória - Início <input name="class_start" type="datetime-local" value="<?= h($eventToEdit['periods']['classificatoria']['start'] ?? '') ?>"></label>
                    <label>Classificatória - Fim <input name="class_end" type="datetime-local" value="<?= h($eventToEdit['periods']['classificatoria']['end'] ?? '') ?>"></label>
                    <label>Semifinal - Início <input name="semi_start" type="datetime-local" value="<?= h($eventToEdit['periods']['semifinal']['start'] ?? '') ?>"></label>
                    <label>Semifinal - Fim <input name="semi_end" type="datetime-local" value="<?= h($eventToEdit['periods']['semifinal']['end'] ?? '') ?>"></label>
                    <label>Final - Início <input name="final_start" type="datetime-local" value="<?= h($eventToEdit['periods']['final']['start'] ?? '') ?>"></label>
                    <label>Final - Fim <input name="final_end" type="datetime-local" value="<?= h($eventToEdit['periods']['final']['end'] ?? '') ?>"></label>
                    <label>Classificados da Classificatória <input name="class_advancers" type="number" min="1" value="<?= h((string)($eventToEdit['phase_advancers']['classificatoria'] ?? 12)) ?>"></label>
                    <label>Classificados da Semifinal <input name="semi_advancers" type="number" min="1" value="<?= h((string)($eventToEdit['phase_advancers']['semifinal'] ?? 6)) ?>"></label>
                    <label>Finalistas <input name="final_advancers" type="number" min="1" value="<?= h((string)($eventToEdit['phase_advancers']['final'] ?? 3)) ?>"></label>
                </div>
                <div class="form-actions">
                    <?php if ($eventToEdit): ?>
                        <a class="button ghost" href="?page=dashboard&section=eventos&event_id=<?= (int)$eventToEdit['id'] ?>#novo-evento">Cancelar edição</a>
                    <?php endif; ?>
                    <button class="button primary" type="submit"><?= $eventToEdit ? 'Salvar alterações' : 'Salvar Evento' ?></button>
                </div>
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
            <a class="button ghost" href="?page=ranking&event_id=<?= $eventId ?>">Ver ranking público</a>
        </section>

        <section class="stats-grid">
            <div class="stat"><strong><?= count($participants) ?></strong><span>Participantes</span></div>
            <div class="stat"><strong><?= count($judges) ?></strong><span>Jurados</span></div>
            <div class="stat"><strong><?= count($criteria) ?></strong><span>Critérios</span></div>
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
                    <table class="admin-table responsive-cards">
                        <thead><tr><th>Nome</th><th>E-mail</th><th>Evento</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>
                        <?php if (!$judges): ?>
                            <tr><td colspan="5">Nenhum jurado cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($judges as $judge): ?>
                            <tr>
                                <td data-label="Nome"><?= h($judge['name']) ?></td>
                                <td data-label="E-mail"><?= h($judge['username']) ?></td>
                                <td data-label="Evento"><?= h($event['name']) ?></td>
                                <td data-label="Status"><span class="status-pill ativo">Ativo</span></td>
                                <td data-label="Ações">
                                    <div class="table-actions">
                                        <a class="icon-action" href="?page=dashboard&section=jurados&event_id=<?= $eventId ?>&judge_edit=<?= (int)$judge['id'] ?>#novo-jurado">Editar</a>
                                        <form method="post" class="inline-delete-form" onsubmit="return confirm('Deseja excluir este jurado?');">
                                            <input type="hidden" name="action" value="delete_judge">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="judge_id" value="<?= (int)$judge['id'] ?>">
                                            <button type="submit" class="icon-action danger">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <form id="novo-jurado" class="panel form-stack compact-form" method="post">
                <h2><?= $judgeToEdit ? 'Editar jurado' : 'Novo jurado' ?></h2>
                <input type="hidden" name="action" value="<?= $judgeToEdit ? 'update_judge' : 'create_judge' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($judgeToEdit): ?>
                    <input type="hidden" name="judge_id" value="<?= (int)$judgeToEdit['id'] ?>">
                <?php endif; ?>
                <label>Nome <input required name="name" value="<?= h($judgeToEdit['name'] ?? '') ?>"></label>
                <label>Usuário <input required name="username" value="<?= h($judgeToEdit['username'] ?? '') ?>"></label>
                <label>Senha <input name="password" type="password" <?= $judgeToEdit ? '' : 'required' ?> placeholder="<?= $judgeToEdit ? 'Deixe em branco para manter a senha atual' : '' ?>"></label>
                <div class="form-actions">
                    <?php if ($judgeToEdit): ?>
                        <a class="button ghost" href="?page=dashboard&section=jurados&event_id=<?= $eventId ?>#novo-jurado">Cancelar edição</a>
                    <?php endif; ?>
                    <button class="button primary" type="submit"><?= $judgeToEdit ? 'Salvar alterações' : 'Cadastrar jurado' ?></button>
                </div>
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
                    <table class="admin-table responsive-cards">
                        <thead><tr><th>Nome / Grupo</th><th>Categoria</th><th>Ordem</th><th>Evento</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>
                        <?php if (!$participants): ?>
                            <tr><td colspan="6">Nenhum participante cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td data-label="Nome / Grupo"><span class="participant-name-cell"><?= participant_photo_html($participant, 'thumb') ?><?= h($participant['name']) ?></span></td>
                                <td data-label="Categoria"><?= h($participant['category']) ?></td>
                                <td data-label="Ordem"><?= str_pad((string)(int)$participant['order'], 2, '0', STR_PAD_LEFT) ?></td>
                                <td data-label="Evento"><?= h($event['name']) ?></td>
                                <td data-label="Status"><span class="status-pill ativo">Ativo</span></td>
                                <td data-label="Ações">
                                    <div class="table-actions">
                                        <a class="icon-action" href="?page=dashboard&section=participantes&event_id=<?= $eventId ?>&participant_edit=<?= (int)$participant['id'] ?>#novo-participante">Editar</a>
                                        <a class="icon-action" href="?page=dashboard&section=participantes&event_id=<?= $eventId ?>&participant_view=<?= (int)$participant['id'] ?>#participant-detail">Visualizar</a>
                                        <form method="post" class="inline-delete-form" onsubmit="return confirm('Deseja excluir este participante?');">
                                            <input type="hidden" name="action" value="delete_participant">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="participant_id" value="<?= (int)$participant['id'] ?>">
                                            <button type="submit" class="icon-action danger">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($participantToView): ?>
                <section id="participant-detail" class="panel participant-detail-card">
                    <div class="participant-detail-media">
                        <?= participant_photo_html($participantToView, 'large') ?>
                    </div>
                    <div class="participant-detail-copy">
                        <h2><?= h($participantToView['name']) ?></h2>
                        <p><strong>Categoria:</strong> <?= h($participantToView['category'] ?: 'Nao informada') ?></p>
                        <p><strong>Música:</strong> <?= h($participantToView['song'] ?: 'Nao informada') ?></p>
                        <p><strong>Ordem:</strong> <?= str_pad((string)(int)$participantToView['order'], 2, '0', STR_PAD_LEFT) ?></p>
                        <div class="participant-detail-actions">
                            <a class="button ghost" href="?page=dashboard&section=participantes&event_id=<?= $eventId ?>">Fechar</a>
                            <a class="button primary" href="?page=dashboard&section=participantes&event_id=<?= $eventId ?>&participant_edit=<?= (int)$participantToView['id'] ?>#novo-participante">Editar participante</a>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
            <form id="novo-participante" class="panel form-stack compact-form" method="post" enctype="multipart/form-data">
                <h2><?= $participantToEdit ? 'Editar participante' : 'Novo participante' ?></h2>
                <input type="hidden" name="action" value="<?= $participantToEdit ? 'update_participant' : 'create_participant' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($participantToEdit): ?>
                    <input type="hidden" name="participant_id" value="<?= (int)$participantToEdit['id'] ?>">
                    <input type="hidden" name="existing_photo" value="<?= h($participantToEdit['photo'] ?? '') ?>">
                <?php endif; ?>
                <label>Nome <input required name="name" value="<?= h($participantToEdit['name'] ?? '') ?>"></label>
                <label>Categoria <input name="category" placeholder="Solo, grupo, instrumental" value="<?= h($participantToEdit['category'] ?? '') ?>"></label>
                <label>Música <input name="song" value="<?= h($participantToEdit['song'] ?? '') ?>"></label>
                <label>Ordem <input name="order" type="number" min="0" value="<?= h(isset($participantToEdit['order']) ? (string)$participantToEdit['order'] : '') ?>"></label>

                <?php
                /* Foto atual + envio. O campo "Ou URL da foto" foi retirado:
                   quem cadastra escolhe um arquivo, e não deveria precisar
                   saber que existe um caminho por trás. */
                $fotoAtual = (string)($participantToEdit['photo'] ?? '');
                $temFoto = $fotoAtual !== '' && is_file(__DIR__ . '/' . $fotoAtual);
                ?>

                <?php if ($temFoto): ?>
                    <div class="foto-atual">
                        <img src="<?= h($fotoAtual) ?>?v=<?= @filemtime(__DIR__ . '/' . $fotoAtual) ?: 0 ?>" alt="Foto de <?= h($participantToEdit['name'] ?? '') ?>">
                        <div>
                            <strong>Foto cadastrada</strong>
                            <label class="caixa">
                                <input type="checkbox" name="remover_foto" value="1">
                                Remover a foto
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <label>
                    <?= $temFoto ? 'Trocar a foto' : 'Foto do participante' ?>
                    <input name="photo" type="file" accept="image/png,image/jpeg,image/webp">
                </label>
                <p class="dica">
                    JPG, PNG ou WEBP, até 5 MB.
                    <?= $temFoto ? 'Deixe em branco para manter a foto atual.' : '' ?>
                </p>
                <div class="form-actions">
                    <?php if ($participantToEdit): ?>
                        <a class="button ghost" href="?page=dashboard&section=participantes&event_id=<?= $eventId ?>#novo-participante">Cancelar edição</a>
                    <?php endif; ?>
                    <button class="button primary" type="submit"><?= $participantToEdit ? 'Salvar alterações' : 'Cadastrar participante' ?></button>
                </div>
            </form>
        </section>
    <?php endif; ?>

<?php if ($event && $section === 'criterios'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Critérios</h2>
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
                    <a class="button primary" href="#novo-criterio">+ Novo Critério</a>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="table-wrap">
                    <table class="admin-table responsive-cards">
                        <thead><tr><th>Ordem</th><th>Critério</th><th>Peso (%)</th><th>Descrição</th><th>Ações</th></tr></thead>
                        <tbody>
                        <?php foreach ($criteria as $index => $criterion): ?>
                            <tr>
                                <td data-label="Ordem"><?= $index + 1 ?></td>
                                <td data-label="Critério"><?= h($criterion['name']) ?></td>
                                <td data-label="Peso (%)"><?= number_format((float)$criterion['weight'] * 20, 0, ',', '.') ?>%</td>
                                <td data-label="Descrição"><?= h(($criterion['description'] ?? '') !== '' ? $criterion['description'] : 'Avaliação do participante neste critério.') ?></td>
                                <td data-label="Ações">
                                    <div class="table-actions">
                                        <a class="icon-action" href="?page=dashboard&section=criterios&event_id=<?= $eventId ?>&criterion_edit=<?= (int)$criterion['id'] ?>#novo-criterio">Editar</a>
                                        <form method="post" class="inline-delete-form" onsubmit="return confirm('Deseja excluir este critério?');">
                                            <input type="hidden" name="action" value="delete_criterion">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="criterion_id" value="<?= (int)$criterion['id'] ?>">
                                            <button type="submit" class="icon-action danger">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td data-label="Resumo" colspan="2">Total dos Pesos</td><td data-label="Peso Total"><?= count($criteria) ? '100%' : '0%' ?></td><td colspan="2"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="info-note">A soma dos pesos dos critérios deve ser igual a 100%.</div>
            <form id="novo-criterio" class="panel form-stack compact-form" method="post">
                <h2><?= $criterionToEdit ? 'Editar critério' : 'Novo critério' ?></h2>
                <input type="hidden" name="action" value="<?= $criterionToEdit ? 'update_criterion' : 'create_criterion' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($criterionToEdit): ?>
                    <input type="hidden" name="criterion_id" value="<?= (int)$criterionToEdit['id'] ?>">
                <?php endif; ?>
                <label>Nome <input required name="name" placeholder="Originalidade" value="<?= h($criterionToEdit['name'] ?? '') ?>"></label>
                <label>Descrição
                    <textarea name="description" rows="2" maxlength="255"
                              placeholder="O que o jurado deve observar neste critério"><?= h($criterionToEdit['description'] ?? '') ?></textarea>
                </label>
                <p class="dica">Aparece para o jurado durante a votação. Até 255 caracteres.</p>
                <label>Peso <input required name="weight" type="number" min="0.1" step="0.1" value="<?= h(isset($criterionToEdit['weight']) ? (string)$criterionToEdit['weight'] : '1') ?>"></label>
                <div class="form-actions">
                    <?php if ($criterionToEdit): ?>
                        <a class="button ghost" href="?page=dashboard&section=criterios&event_id=<?= $eventId ?>#novo-criterio">Cancelar edição</a>
                    <?php endif; ?>
                    <button class="button primary" type="submit"><?= $criterionToEdit ? 'Salvar alterações' : 'Adicionar critério' ?></button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'apuracao'): ?>
        <section class="management-page">
            <div class="management-head">
                <h2>Apuração</h2>
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
                    <h2>Resumo da Apuração</h2>
                    <p><span>Participantes</span><strong><?= count($participants) ?></strong></p>
                    <p><span>Jurados</span><strong><?= count($judges) ?></strong></p>
                    <p><span>Critérios</span><strong><?= count($criteria) ?></strong></p>
                    <p><span>Notas lancadas</span><strong><?= $voteCount ?></strong></p>
                </div>
            </section>
            <div class="info-note">O resultado será exibido aos participantes somente após a finalização da apuração.</div>
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
                <h2>Relatórios</h2>
                <div class="management-actions">
                    <a class="button ghost" href="?page=dashboard&section=exportar&event_id=<?= $eventId ?>">Exportar PDF</a>
                    <a class="button primary" href="?page=dashboard&section=placar&event_id=<?= $eventId ?>">Abrir Placar</a>
                </div>
            </div>
            <section class="report-grid">
                <div class="panel report-card"><span><?= count($participants) ?></span><strong>Participantes inscritos</strong><small>Total no evento selecionado</small></div>
                <div class="panel report-card"><span><?= count($judges) ?></span><strong>Jurados cadastrados</strong><small>Equipe de avaliação</small></div>
                <div class="panel report-card"><span><?= $voteCount ?></span><strong>Notas lancadas</strong><small>Registros salvos neste evento</small></div>
                <div class="panel report-card"><span><?= $totalScoreRows ? number_format((float)$totalScoreRows[0]['total_score'], 1, ',', '.') : '-' ?></span><strong>Maior somatória</strong><small>Melhor total acumulado</small></div>
            </section>
            <div class="panel data-panel">
                <div class="management-head compact">
                    <h3>Classificação por somatória</h3>
                </div>
                <?= render_total_scores_table($totalScoreRows) ?>
            </div>
            <div class="panel data-panel">
                <div class="management-head compact">
                    <h3>Acompanhamento dos jurados</h3>
                </div>
                <div class="table-wrap">
                    <table class="admin-table responsive-cards">
                        <thead><tr><th>Jurado</th><th>Participantes avaliados</th><th>Checklist concluidos</th><th>Pronto</th><th>Notas lancadas</th><th>Pendentes</th><th>Ultima atualização</th></tr></thead>
                        <tbody>
                        <?php if (!$judgeProgress): ?>
                            <tr><td colspan="7">Nenhum jurado cadastrado para este evento.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($judgeProgress as $row): ?>
                            <tr>
                                <td data-label="Jurado"><?= h($row['judge']['name']) ?></td>
                                <td data-label="Participantes avaliados"><?= (int)$row['participants_done'] ?> / <?= (int)$row['participants_total'] ?></td>
                                <td data-label="Checklist concluidos"><?= (int)$row['checklists_done'] ?> / <?= (int)$row['participants_total'] ?></td>
                                <td data-label="Pronto"><input class="admin-status-checkbox" type="checkbox" disabled <?= $row['ready_checkbox'] ? 'checked' : '' ?>></td>
                                <td data-label="Notas lancadas"><?= (int)$row['notes_count'] ?></td>
                                <td data-label="Pendentes"><?= (int)$row['pending'] ?></td>
                                <td data-label="Ultima atualizacao"><?= $row['last_vote_at'] ? h(date('d/m/Y H:i', strtotime($row['last_vote_at']))) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="management-head compact">
                    <h3>Relatório detalhado de notas</h3>
                </div>
                <div class="table-wrap">
                    <table class="admin-table responsive-cards">
                        <thead><tr><th>Participante</th><th>Jurado</th><th>Critério</th><th>Nota</th><th>Somatória do jurado</th><th>Somatória do participante</th><th>Checklist</th><th>Assinatura</th><th>Observação</th><th>Atualizado em</th></tr></thead>
                        <tbody>
                        <?php if (!$detailedRows): ?>
                            <tr><td colspan="10">Ainda não existem notas detalhadas para este evento.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($detailedRows as $row): ?>
                            <tr>
                                <td data-label="Participante"><?= h($row['participant']['name']) ?></td>
                                <td data-label="Jurado"><?= h($row['judge']['name']) ?></td>
                                <td data-label="Critério"><?= h($row['criterion']['name']) ?></td>
                                <td data-label="Nota"><?= isset($row['vote']['score']) ? number_format((float)$row['vote']['score'], 1, ',', '.') : '-' ?></td>
                                <td data-label="Somatória do jurado"><?= number_format((float)$row['judge_total'], 2, ',', '.') ?></td>
                                <td data-label="Somatória do participante"><?= number_format((float)$row['participant_total'], 2, ',', '.') ?></td>
                                <td data-label="Checklist"><?= $row['checklist_done'] ? 'Concluido' : 'Pendente' ?></td>
                                <td data-label="Assinatura"><?= render_signature_markup($row['review'] ?? null) ?></td>
                                <td data-label="Observação"><?= $row['observation'] !== '' ? h($row['observation']) : '-' ?></td>
                                <td data-label="Atualizado em">
                                    <?php
                                    $updatedAt = $row['review_updated_at'] ?? $row['vote']['created_at'] ?? $row['observation_updated_at'] ?? '';
                                    echo $updatedAt ? h(date('d/m/Y H:i', strtotime($updatedAt))) : '-';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel data-panel">
                <div class="management-head compact">
                    <h3>Relatório de notas totais</h3>
                </div>
                <?= render_total_scores_table($totalScoreRows) ?>
            </div>
            <?php if (($event['event_format'] ?? 'unica') === 'fases'): ?>
                <div class="panel data-panel">
                    <div class="management-head compact">
                        <h3>Classificados por fase</h3>
                    </div>
                    <div class="table-wrap">
                        <table class="admin-table responsive-cards">
                            <thead><tr><th>Fase</th><th>Posição</th><th>Participante</th><th>Pontuação</th><th>Jurados</th></tr></thead>
                            <tbody>
                            <?php if (!$phaseReportRows): ?>
                                <tr><td colspan="5">Ainda não existem classificados por fase.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($phaseReportRows as $row): ?>
                                <tr>
                                    <td data-label="Fase"><?= h($row['phase_label']) ?></td>
                                    <td data-label="Posição"><?= (int)$row['position'] ?></td>
                                    <td data-label="Participante"><?= h($row['participant']['name']) ?></td>
                                    <td data-label="Pontuação"><?= number_format((float)$row['score'], 2, ',', '.') ?></td>
                                    <td data-label="Jurados"><?= (int)$row['judge_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
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
                <div class="scoreboard-row cabecalho">
                    <strong>#</strong>
                    <span>Participante</span>
                    <i>Jurados</i>
                    <b>Média</b>
                    <em>Total</em>
                </div>
                <?php foreach ($ranking as $index => $row): ?>
                    <div class="scoreboard-row<?= $index === 0 ? ' lider' : '' ?>">
                        <strong><?= $index + 1 ?>º</strong>
                        <span><?= h($row['participant']['name']) ?></span>
                        <i data-rotulo="Jurados"><?= (int)($row['judge_count'] ?? 0) ?></i>
                        <b data-rotulo="Média"><?= number_format((float)$row['score'], 2, ',', '.') ?></b>
                        <em data-rotulo="Total"><?= number_format((float)($row['total_points'] ?? 0), 2, ',', '.') ?></em>
                    </div>
                <?php endforeach; ?>
                <?php if (!$ranking): ?>
                    <div class="scoreboard-row"><strong>-</strong><span>Sem notas lançadas ainda</span><i>0</i><b>0,00</b><em>0,00</em></div>
                <?php endif; ?>
            </div>
            <p class="dica placar-legenda">
                <strong>Média</strong>: média ponderada de cada jurado, promediada entre os jurados — não cresce com mais jurados.
                <strong>Total</strong>: soma de nota × peso de todos os jurados — cresce a cada avaliação. A classificação segue a média.
            </p>
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
                        <h1>Relatório de Notas</h1>
                        <p><?= h($event['name']) ?> · <?= h($event['date']) ?></p>
                    </div>
                </div>
                <h2>Classificação por somatória</h2>
                <?= render_total_scores_table($totalScoreRows) ?>
                <h2>Notas por jurado</h2>
                <div class="table-wrap">
                    <table class="admin-table responsive-cards">
                        <thead><tr><th>Participante</th><th>Jurado</th><th>Critério</th><th>Nota</th><th>Somatória do jurado</th><th>Somatória do participante</th><th>Checklist</th><th>Assinatura</th><th>Observação</th><th>Atualizado em</th></tr></thead>
                        <tbody>
                        <?php foreach ($detailedRows as $row): ?>
                            <tr>
                                <td data-label="Participante"><?= h($row['participant']['name']) ?></td>
                                <td data-label="Jurado"><?= h($row['judge']['name']) ?></td>
                                <td data-label="Critério"><?= h($row['criterion']['name']) ?></td>
                                <td data-label="Nota"><?= isset($row['vote']['score']) ? number_format((float)$row['vote']['score'], 1, ',', '.') : '-' ?></td>
                                <td data-label="Somatória do jurado"><?= number_format((float)$row['judge_total'], 2, ',', '.') ?></td>
                                <td data-label="Somatória do participante"><?= number_format((float)$row['participant_total'], 2, ',', '.') ?></td>
                                <td data-label="Checklist"><?= $row['checklist_done'] ? 'Concluido' : 'Pendente' ?></td>
                                <td data-label="Assinatura"><?= render_signature_markup($row['review'] ?? null) ?></td>
                                <td data-label="Observação"><?= $row['observation'] !== '' ? h($row['observation']) : '-' ?></td>
                                <td data-label="Atualizado em">
                                    <?php
                                    $updatedAt = $row['review_updated_at'] ?? $row['vote']['created_at'] ?? $row['observation_updated_at'] ?? '';
                                    echo $updatedAt ? h(date('d/m/Y H:i', strtotime($updatedAt))) : '-';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (($event['event_format'] ?? 'unica') === 'fases'): ?>
                    <h2>Classificados por fase</h2>
                    <div class="table-wrap">
                        <table class="admin-table responsive-cards">
                            <thead><tr><th>Fase</th><th>Posição</th><th>Participante</th><th>Pontuação</th><th>Jurados</th></tr></thead>
                            <tbody>
                            <?php foreach ($phaseReportRows as $row): ?>
                                <tr>
                                    <td data-label="Fase"><?= h($row['phase_label']) ?></td>
                                    <td data-label="Posição"><?= (int)$row['position'] ?></td>
                                    <td data-label="Participante"><?= h($row['participant']['name']) ?></td>
                                    <td data-label="Pontuação"><?= number_format((float)$row['score'], 2, ',', '.') ?></td>
                                    <td data-label="Jurados"><?= (int)$row['judge_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$phaseReportRows): ?>
                                <tr><td colspan="5">Ainda não existem classificados por fase.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($event && $section === 'configuracoes'): ?>
        <?php
            $configTab = $_GET['config_tab'] ?? 'gerais';
            $configTabs = [
                'gerais' => 'Informações Gerais',
                'periodos' => 'Períodos de Avaliação',
                'pesos' => 'Pesos dos Critérios',
                'notificacoes' => 'Notificações',
                'publicacao' => 'Publicação de Resultados',
                'outras' => 'Outras Configurações',
            ];
            $periods = $event['periods'] ?? [];
            $notifications = $event['notifications'] ?? [];
            $publication = $event['publication'] ?? [];
            $advanced = $event['advanced'] ?? [];
        ?>
        <section class="management-page config-page">
            <div class="management-head">
                <div>
                    <h2>Configurações do Evento</h2>
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
                <small><?= h($event['date']) ?> · <?= h($event['location'] ?? 'Teatro Sesc Centro') ?> · <?= h($event['status']) ?></small>
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
                            <label>Descrição <textarea name="description" rows="4"><?= h($event['description']) ?></textarea></label>
                            <div class="grid two">
                                <label>Data de Início <input name="date" type="date" value="<?= h($event['date']) ?>"></label>
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
                            <label>Tempo de Avaliação por Jurado (minutos)
                                <input name="evaluation_minutes" type="number" min="1" value="<?= h((string)($event['evaluation_minutes'] ?? 136)) ?>">
                            </label>
                            <label>Formato do Evento
                                <select name="event_format">
                                    <option value="unica" <?= ($event['event_format'] ?? 'unica') === 'unica' ? 'selected' : '' ?>>Etapa única</option>
                                    <option value="fases" <?= ($event['event_format'] ?? 'unica') === 'fases' ? 'selected' : '' ?>>Classificatória, semifinal e final</option>
                                </select>
                            </label>
                            <button class="button primary" type="submit">Salvar Alterações</button>
                        </form>
                    <?php elseif ($configTab === 'periodos'): ?>
                        <form class="form-stack" method="post">
                            <input type="hidden" name="action" value="update_event_config">
                            <input type="hidden" name="config_tab" value="periodos">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label>Formato da avaliação
                                <select name="period_mode" data-period-mode>
                                    <option value="unica" <?= ($event['event_format'] ?? 'unica') === 'unica' ? 'selected' : '' ?>>Etapa única</option>
                                    <option value="fases" <?= ($event['event_format'] ?? 'unica') === 'fases' ? 'selected' : '' ?>>Classificatória, semifinal e final</option>
                                </select>
                            </label>
                            <div class="table-wrap">
                                <table class="admin-table responsive-cards">
                                    <thead><tr><th>Período</th><th>Início</th><th>Fim</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <tr data-period-row="unica"><td data-label="Período">Etapa única</td><td data-label="Início"><input name="single_start" type="datetime-local" value="<?= h($periods['unica']['start'] ?? '') ?>"></td><td data-label="Fim"><input name="single_end" type="datetime-local" value="<?= h($periods['unica']['end'] ?? '') ?>"></td><td data-label="Status"><span class="status-pill ativo">Ativo</span></td></tr>
                                        <tr data-period-row="fases"><td data-label="Período">Classificatória</td><td data-label="Início"><input name="class_start" type="datetime-local" value="<?= h($periods['classificatoria']['start'] ?? '') ?>"></td><td data-label="Fim"><input name="class_end" type="datetime-local" value="<?= h($periods['classificatoria']['end'] ?? '') ?>"></td><td data-label="Status"><select name="class_status"><option value="ativo" <?= ($periods['classificatoria']['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option><option value="programado" <?= ($periods['classificatoria']['status'] ?? '') === 'programado' ? 'selected' : '' ?>>Programado</option></select></td></tr>
                                        <tr data-period-row="fases"><td data-label="Período">Semifinal</td><td data-label="Início"><input name="semi_start" type="datetime-local" value="<?= h($periods['semifinal']['start'] ?? '') ?>"></td><td data-label="Fim"><input name="semi_end" type="datetime-local" value="<?= h($periods['semifinal']['end'] ?? '') ?>"></td><td data-label="Status"><select name="semi_status"><option value="programado" <?= ($periods['semifinal']['status'] ?? 'programado') === 'programado' ? 'selected' : '' ?>>Programado</option><option value="ativo" <?= ($periods['semifinal']['status'] ?? '') === 'ativo' ? 'selected' : '' ?>>Ativo</option></select></td></tr>
                                        <tr data-period-row="fases"><td data-label="Período">Final</td><td data-label="Início"><input name="final_start" type="datetime-local" value="<?= h($periods['final']['start'] ?? '') ?>"></td><td data-label="Fim"><input name="final_end" type="datetime-local" value="<?= h($periods['final']['end'] ?? '') ?>"></td><td data-label="Status"><select name="final_status"><option value="programado" <?= ($periods['final']['status'] ?? 'programado') === 'programado' ? 'selected' : '' ?>>Programado</option><option value="ativo" <?= ($periods['final']['status'] ?? '') === 'ativo' ? 'selected' : '' ?>>Ativo</option></select></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="phase-advance-grid" data-period-row="fases">
                                <label>Quantos passam da Classificatória
                                    <input name="class_advancers" type="number" min="1" value="<?= h((string)($phaseAdvancers['classificatoria'] ?? 12)) ?>">
                                </label>
                                <label>Quantos passam da Semifinal
                                    <input name="semi_advancers" type="number" min="1" value="<?= h((string)($phaseAdvancers['semifinal'] ?? 6)) ?>">
                                </label>
                                <label>Quantos chegam na Final
                                    <input name="final_advancers" type="number" min="1" value="<?= h((string)($phaseAdvancers['final'] ?? 3)) ?>">
                                </label>
                            </div>
                            <div class="info-note">Em etapa única, somente a linha Etapa única controla a avaliação. Em fases, apenas períodos com status ativo estarão disponíveis.</div>
                            <?php if (($event['event_format'] ?? 'unica') === 'fases'): ?>
                                <section class="panel phase-bracket">
                                    <div class="management-head compact">
                                        <h3>Chaveamento / Classificação Atual</h3>
                                    </div>
                                    <div class="phase-bracket-grid">
                                        <?php foreach (['classificatoria' => 'Classificatória', 'semifinal' => 'Semifinal', 'final' => 'Final'] as $phaseKey => $phaseLabel): ?>
                                            <div class="phase-column">
                                                <strong><?= h($phaseLabel) ?></strong>
                                                <small><?= count($phaseBracket[$phaseKey] ?? []) ?> classificado(s)</small>
                                                <div class="phase-list">
                                                    <?php foreach (($phaseBracket[$phaseKey] ?? []) as $index => $row): ?>
                                                        <div class="phase-card">
                                                            <span>#<?= $index + 1 ?></span>
                                                            <strong><?= h($row['participant']['name']) ?></strong>
                                                            <small><?= number_format((float)$row['score'], 2, ',', '.') ?> pts</small>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($phaseBracket[$phaseKey])): ?>
                                                        <div class="phase-card empty">
                                                            <strong>Sem classificados ainda</strong>
                                                            <small>Aguardando notas</small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endif; ?>
                            <button class="button primary" type="submit">Salvar Alterações</button>
                        </form>
                    <?php elseif ($configTab === 'pesos'): ?>
                        <div class="table-wrap">
                            <table class="admin-table responsive-cards">
                                <thead><tr><th>Ordem</th><th>Critério</th><th>Descrição</th><th>Peso (%)</th></tr></thead>
                                <tbody>
                                    <?php foreach ($criteria as $index => $criterion): ?>
                                        <tr><td data-label="Ordem"><?= $index + 1 ?></td><td data-label="Critério"><?= h($criterion['name']) ?></td><td data-label="Descrição"><?= h(($criterion['description'] ?? '') !== '' ? $criterion['description'] : 'Avaliação do participante neste critério.') ?></td><td data-label="Peso (%)"><?= number_format((float)$criterion['weight'] * 20, 0, ',', '.') ?>%</td></tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row"><td data-label="Resumo" colspan="3">Total dos Pesos</td><td data-label="Peso Total">100%</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <a class="button primary" href="?page=dashboard&section=criterios&event_id=<?= $eventId ?>">Editar Pesos nos Critérios</a>
                    <?php elseif ($configTab === 'notificacoes'): ?>
                        <form class="form-stack config-switches" method="post">
                            <input type="hidden" name="action" value="update_event_config"><input type="hidden" name="config_tab" value="notificacoes"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label><span>Notificar jurados sobre abertura de avaliações</span><input type="checkbox" name="judge_open" <?= ($notifications['judge_open'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar jurados sobre lembretes de avaliação</span><input type="checkbox" name="judge_reminder" <?= ($notifications['judge_reminder'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar administrador sobre avaliações concluidas</span><input type="checkbox" name="admin_complete" <?= ($notifications['admin_complete'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar participantes sobre resultados</span><input type="checkbox" name="participant_results" <?= ($notifications['participant_results'] ?? true) ? 'checked' : '' ?>></label>
                            <label><span>Notificar alterações no evento</span><input type="checkbox" name="event_changes" <?= ($notifications['event_changes'] ?? false) ? 'checked' : '' ?>></label>
                            <button class="button primary" type="submit">Salvar Alterações</button>
                        </form>
                    <?php elseif ($configTab === 'publicacao'): ?>
                        <form class="form-stack config-switches" method="post">
                            <input type="hidden" name="action" value="update_event_config"><input type="hidden" name="config_tab" value="publicacao"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label><span>Publicar resultados automaticamente</span><input type="checkbox" name="auto_publish" <?= ($publication['auto_publish'] ?? true) ? 'checked' : '' ?>></label>
                            <label>Data de início da publicação <input name="publish_date" type="datetime-local" value="<?= h($publication['publish_date'] ?? '') ?>"></label>
                            <label><span>Exibir notas individuais dos jurados</span><input type="checkbox" name="show_individual" <?= ($publication['show_individual'] ?? false) ? 'checked' : '' ?>></label>
                            <label><span>Exibir comentários dos jurados</span><input type="checkbox" name="show_comments" <?= ($publication['show_comments'] ?? false) ? 'checked' : '' ?>></label>
                            <label>Ordem de exibição no resultado <select name="publication_order"><option value="score_desc" <?= ($publication['order'] ?? 'score_desc') === 'score_desc' ? 'selected' : '' ?>>Por pontuação</option><option value="name" <?= ($publication['order'] ?? '') === 'name' ? 'selected' : '' ?>>Por nome</option></select></label>
                            <button class="button primary" type="submit">Salvar Alterações</button>
                        </form>
                    <?php else: ?>
                        <form class="form-stack config-switches" method="post">
                            <input type="hidden" name="action" value="update_event_config"><input type="hidden" name="config_tab" value="outras"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label><span>Permitir edição de notas pelos jurados</span><input type="checkbox" name="allow_edit_after_submit" <?= ($advanced['allow_edit_after_submit'] ?? false) ? 'checked' : '' ?>></label>
                            <label><span>Mostrar média parcial aos jurados</span><input type="checkbox" name="show_partial_average" <?= ($advanced['show_partial_average'] ?? false) ? 'checked' : '' ?>></label>
                            <label>Critério de desempate <select name="tie_breaker"><option value="highest_weight">Maior nota no critério de maior peso</option><option value="oldest_vote">Primeiro voto lancado</option></select></label>
                            <label>Quantidade de casas decimais <input name="decimal_places" type="number" min="0" max="3" value="<?= h((string)($advanced['decimal_places'] ?? 2)) ?>"></label>
                            <label><span>Impedir múltiplos acessos simultâneos</span><input type="checkbox" name="prevent_multi_login" <?= ($advanced['prevent_multi_login'] ?? true) ? 'checked' : '' ?>></label>
                            <button class="button primary" type="submit">Salvar Alterações</button>
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
        <table class="admin-table responsive-cards">
            <thead><tr><th>#</th><th>Participante</th><th>Nota</th><th>Jurados</th></tr></thead>
            <tbody>
            <?php if (!$ranking): ?>
                <tr><td colspan="4">Sem notas ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($ranking as $index => $row): ?>
                <tr>
                    <td data-label="#"><?= $index + 1 ?></td>
                    <td data-label="Participante"><?= h($row['participant']['name']) ?></td>
                    <td data-label="Nota"><?= number_format((float)$row['score'], 2, ',', '.') ?></td>
                    <td data-label="Jurados"><?= (int)$row['judge_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

function render_total_scores_table(array $rows): string
{
    ob_start();
    ?>
    <div class="table-wrap">
        <table class="admin-table responsive-cards">
            <thead><tr><th>#</th><th>Participante</th><th>Total de notas</th><th>Notas lancadas</th><th>Jurados</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5">Sem notas totais ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $index => $row): ?>
                <tr>
                    <td data-label="#"><?= $index + 1 ?></td>
                    <td data-label="Participante"><?= h($row['participant']['name']) ?></td>
                    <td data-label="Total de notas"><?= number_format((float)$row['total_score'], 2, ',', '.') ?></td>
                    <td data-label="Notas lancadas"><?= (int)$row['notes_count'] ?></td>
                    <td data-label="Jurados"><?= (int)$row['judge_count'] ?></td>
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
                        <small><?= h($item['date']) ?> · <?= h($item['status']) ?></small>
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
            <a class="button ghost" href="?page=ranking&event_id=<?= $eventId ?>">Ver ranking público</a>
        </section>

        <section class="stats-grid">
            <div class="stat"><strong><?= count($participants) ?></strong><span>Participantes</span></div>
            <div class="stat"><strong><?= count($judges) ?></strong><span>Jurados</span></div>
            <div class="stat"><strong><?= count($criteria) ?></strong><span>Critérios</span></div>
            <div class="stat"><strong><?= count($db['admins'] ?? []) ?></strong><span>Administradores</span></div>
        </section>

        <section class="grid three">
            <form class="panel form-stack" method="post">
                <h2>Participante</h2>
                <input type="hidden" name="action" value="create_participant">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name"></label>
                <label>Categoria <input name="category" placeholder="Solo, grupo, instrumental"></label>
                <label>Música <input name="song"></label>
                <label>Ordem <input name="order" type="number" min="0"></label>
                <button class="button primary" type="submit">Cadastrar</button>
            </form>

            <form class="panel form-stack" method="post">
                <h2>Jurado</h2>
                <input type="hidden" name="action" value="create_judge">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label>Nome <input required name="name"></label>
                <label>Usuário <input required name="username"></label>
                <label>Telefone (WhatsApp)
                    <input name="phone" inputmode="tel" placeholder="(92) 98888-7777">
                </label>
                <p class="dica">Com telefone informado e integração ativa, as credenciais são enviadas automaticamente.</p>
                <label>Senha
                    <input name="password" type="password" autocomplete="new-password" placeholder="deixe em branco para gerar">
                </label>
                <p class="dica">Em branco, o sistema gera uma senha e a mostra uma única vez.</p>
                <button class="button primary" type="submit">Cadastrar</button>
            </form>

            <form class="panel form-stack" method="post">
                <h2>Critério</h2>
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
                        <thead><tr><th>Ordem</th><th>Nome</th><th>Categoria</th><th>Música</th></tr></thead>
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
                <h2>Jurados e critérios</h2>
                <div class="chips">
                    <?php foreach ($judges as $judge): ?>
                        <span><?= h($judge['name']) ?> · <?= h($judge['username']) ?></span>
                    <?php endforeach; ?>
                </div>
                <hr>
                <div class="chips">
                    <?php foreach ($criteria as $criterion): ?>
                        <span><?= h($criterion['name']) ?> · peso <?= h((string)$criterion['weight']) ?></span>
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
    $selectedReview = judge_review_for($db, $eventId, $judgeId, $participantId);
    $selectedSignature = signature_payload_from_review($selectedReview);
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
    <?php
    $meusEventos = judge_eventos_disponiveis($db);

    $itensJurado = [];

    /* Com um evento só, a entrada "Eventos" não teria o que mostrar. */
    if (count($meusEventos) > 1) {
        $itensJurado[] = ['eventos', 'evento', 'Meus eventos'];
    }

    $itensJurado = array_merge($itensJurado, [
        ['votacao',       'votacao',      'Votação'],
        ['participantes', 'participante', 'Participantes'],
        ['criterios',     'criterio',     'Critérios'],
        ['resumo',        'resumo',       'Resumo de notas'],
        ['instrucoes',    'instrucao',    'Instruções'],
    ]);
    ?>
    <div class="judge-shell">
        <button class="menu-overlay" type="button" data-menu-overlay hidden aria-label="Fechar menu"></button>

        <aside class="admin-sidebar judge-sidebar" id="menu-lateral">
            <div class="sidebar-head">
                <div class="sidebar-logo">
                    <div class="sesc-logo small"><span>Sesc</span></div>
                    <small>Sistema de notas de jurados</small>
                </div>
                <?= menu_botao() ?>
            </div>
            <nav class="admin-menu" aria-label="Menu do jurado">
                <?php foreach ($itensJurado as [$secao, $icone, $titulo]): ?>
                    <?= menu_item('?page=judge-panel&section=' . $secao, $icone, $titulo, $section === $secao) ?>
                <?php endforeach; ?>
            </nav>
            <form method="post" class="sidebar-logout">
                <input type="hidden" name="action" value="logout">
                <button type="submit"><?= menu_icone('sair') ?><span class="menu-rotulo">Sair</span></button>
            </form>
        </aside>
        <section class="judge-content">
            <header class="judge-event-head">
                <?= menu_botao() ?>
                <div>
                    <span>Evento:</span>
                    <h1><?= h($event['name'] ?? 'Evento') ?></h1>
                    <p>▣ <?= h($event['date'] ?? '') ?> &nbsp;&nbsp; ◉ Teatro Sesc Centro
                        <?php /* Atalho no cabeçalho, além do menu: é a troca que
                                 mais acontece durante o dia do festival. */ ?>
                        <?php if (count($meusEventos) > 1): ?>
                            &nbsp;&nbsp;
                            <a class="judge-trocar" href="?page=judge-panel&section=eventos">trocar modalidade</a>
                        <?php endif; ?>
                    </p>
                </div>
                <?php $juradoNome = (string)($_SESSION['judge_name'] ?? 'Jurado'); ?>

                <div class="judge-timer">
                    <span>Tempo restante</span>
                    <strong id="judge-timer" data-deadline="<?= h(date('c', $deadline)) ?>"><?= h($timerText) ?></strong>
                </div>

                <?php /* Mesmo menu de conta do administrador. O círculo vazio que
                         estava aqui não clicava em nada e não dizia de quem era a
                         sessão — num tablet passado de mão em mão entre jurados,
                         é a primeira coisa que se precisa conferir. */ ?>
                <div class="admin-profile" data-perfil>
                    <button class="perfil-gatilho" type="button" data-perfil-botao
                            aria-haspopup="true" aria-expanded="false" aria-controls="menu-jurado">
                        <span class="avatar" aria-hidden="true"><?= h(perfil_iniciais($juradoNome)) ?></span>
                        <span class="perfil-quem">
                            <strong><?= h($juradoNome) ?></strong>
                            <small>Jurado</small>
                        </span>
                        <?= menu_icone('seta-baixo') ?>
                        <span class="sr-only">Abrir menu da conta</span>
                    </button>

                    <div class="perfil-menu" id="menu-jurado" hidden>
                        <div class="perfil-cabeca">
                            <span class="avatar" aria-hidden="true"><?= h(perfil_iniciais($juradoNome)) ?></span>
                            <div>
                                <strong><?= h($juradoNome) ?></strong>
                                <span class="status-pill ativo">Jurado</span>
                            </div>
                        </div>

                        <ul class="perfil-dados">
                            <li><?= menu_icone('evento') ?><span><?= h($event['name'] ?? 'Evento') ?></span></li>
                            <li><?= menu_icone('participante') ?><span><?= count($participants) ?> participante(s) · <?= count($criteria) ?> critério(s)</span></li>
                        </ul>

                        <?php if (count($meusEventos) > 1): ?>
                            <a class="perfil-acao" href="?page=judge-panel&section=eventos"><?= menu_icone('evento') ?><span>Trocar modalidade</span></a>
                        <?php endif; ?>

                        <a class="perfil-acao" href="?page=judge-panel&section=instrucoes"><?= menu_icone('instrucao') ?><span>Instruções</span></a>

                        <form method="post" class="perfil-acao-form">
                            <input type="hidden" name="action" value="logout">
                            <button class="perfil-acao sair" type="submit"><?= menu_icone('sair') ?><span>Sair da conta</span></button>
                        </form>
                    </div>
                </div>
            </header>

            <?php /* Escolha do evento: primeira tela de quem julga mais de uma
                     modalidade, e acessível o tempo todo pelo menu. As três
                     acontecem em sequência no mesmo dia. */ ?>
            <?php if ($section === 'eventos'): ?>
                <section class="judge-list-page">
                    <div class="management-head">
                        <h2>Meus eventos</h2>
                    </div>
                    <p class="dica">
                        Escolha a modalidade que está acontecendo agora. As notas ficam guardadas
                        separadamente em cada uma — dá para ir e voltar sem perder nada.
                    </p>

                    <div class="judge-eventos">
                        <?php foreach ($meusEventos as $item): ?>
                            <?php
                            $ev = $item['evento'];
                            $completo = $item['participantes'] > 0
                                && $item['avaliados'] >= $item['participantes'];
                            ?>
                            <form method="post" class="judge-evento <?= $item['atual'] ? 'atual' : '' ?>">
                                <input type="hidden" name="action" value="judge_trocar_evento">
                                <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">

                                <div class="judge-evento-topo">
                                    <h3><?= h($ev['name']) ?></h3>
                                    <?php if ($item['atual']): ?>
                                        <span class="status-pill ativo">em uso</span>
                                    <?php elseif (!$item['aberto']): ?>
                                        <span class="status-pill">fora do horário</span>
                                    <?php elseif ($completo): ?>
                                        <span class="status-pill ativo">concluído</span>
                                    <?php else: ?>
                                        <span class="status-pill pendente">a avaliar</span>
                                    <?php endif; ?>
                                </div>

                                <p class="dica">
                                    <?= (int)$item['avaliados'] ?> de <?= (int)$item['participantes'] ?>
                                    avaliados · <?= (int)$item['criterios'] ?> critério(s)
                                </p>

                                <button class="button <?= $item['atual'] ? '' : 'primary' ?>" type="submit"
                                        <?= $item['atual'] ? 'disabled' : '' ?>>
                                    <?= $item['atual'] ? 'Você está aqui' : 'Entrar nesta modalidade' ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($section === 'participantes'): ?>
                <section class="judge-list-page">
                    <div class="management-head">
                        <h2>Participantes</h2>
                        <input type="search" placeholder="Buscar participante...">
                    </div>
                    <div class="panel data-panel">
                        <div class="table-wrap">
                        <table class="admin-table responsive-cards">
                            <thead><tr><th>Ordem</th><th>Participante</th><th>Categoria</th><th>Situação</th><th>Ação</th></tr></thead>
                            <tbody>
                            <?php foreach ($participants as $participant): ?>
                                <?php $participantScores = array_filter($db['votes'] ?? [], fn($vote) => (int)$vote['judge_id'] === $judgeId && (int)$vote['participant_id'] === (int)$participant['id']); ?>
                                <?php $participantReview = judge_review_for($db, $eventId, $judgeId, (int)$participant['id']); ?>
                                <tr>
                                    <td data-label="Ordem"><?= str_pad((string)(int)$participant['order'], 2, '0', STR_PAD_LEFT) ?></td>
                                    <td data-label="Participante"><span class="participant-name-cell"><?= participant_photo_html($participant, 'thumb') ?><?= h($participant['name']) ?></span></td>
                                    <td data-label="Categoria"><?= h($participant['category']) ?></td>
                                    <td data-label="Situação"><span class="status-pill <?= ($participantReview['checklist_done'] ?? false) ? 'ativo' : ($participantScores ? 'pendente' : 'pendente') ?>"><?= ($participantReview['checklist_done'] ?? false) ? 'Checklist concluido' : ($participantScores ? 'Avaliado' : 'Pendente') ?></span></td>
                                    <td data-label="Ação"><a class="button ghost small" href="?page=judge-panel&section=votacao&participant_id=<?= (int)$participant['id'] ?>">Avaliar</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <div class="info-note">Toque em Avaliar para lançar suas notas para o participante.</div>
                </section>
            <?php elseif ($section === 'criterios'): ?>
                <?php
                /* O peso era exibido como `peso * 20`, o que dava 20% para
                   qualquer critério de peso 1 — e a nota de rodapé prometia
                   que a soma daria 100%. Com três critérios dava 60%; com um
                   só, 20%. Agora é a fatia real de cada um no total. */
                $somaPesos = 0.0;
                foreach ($criteria as $criterion) {
                    $somaPesos += (float)($criterion['weight'] ?? 1);
                }
                ?>
                <section class="judge-list-page">
                    <div class="management-head"><h2>Critérios de Avaliação</h2></div>
                    <div class="panel data-panel">
                        <div class="table-wrap">
                        <table class="admin-table responsive-cards">
                            <thead><tr><th>Ordem</th><th>Critério</th><th>O que avaliar</th><th>Escala</th><th>Peso</th></tr></thead>
                            <tbody>
                            <?php foreach ($criteria as $index => $criterion): ?>
                                <tr>
                                    <td data-label="Ordem"><?= $index + 1 ?></td>
                                    <td data-label="Critério"><strong><?= h($criterion['name']) ?></strong></td>
                                    <td data-label="O que avaliar">
                                        <?= h(($criterion['description'] ?? '') !== ''
                                            ? $criterion['description']
                                            : 'Avaliação do participante neste critério.') ?>
                                    </td>
                                    <td data-label="Escala">0,0 a 10,0</td>
                                    <td data-label="Peso"><?= $somaPesos > 0
                                        ? number_format((float)($criterion['weight'] ?? 1) / $somaPesos * 100, 0, ',', '.')
                                        : '0' ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <div class="info-note">
                        <?= count($criteria) === 1
                            ? 'Este evento tem um critério único: a nota que você lançar é a nota final do grupo.'
                            : 'A nota final é a soma das notas de todos os critérios.' ?>
                    </div>
                </section>
            <?php elseif ($section === 'resumo'): ?>
                <section class="judge-list-page">
                    <div class="management-head">
                        <h2>Resumo de Notas</h2>
                        <select><option>Todos os participantes</option></select>
                    </div>
                    <div class="panel data-panel">
                        <?= render_ranking_table(ranking_for_event($db, $eventId)) ?>
                    </div>
                    <div class="info-note">As notas só serão exibidas após o término do evento.</div>
                </section>
            <?php elseif ($section === 'instrucoes'): ?>
                <section class="judge-list-page">
                    <div class="management-head"><h2>Instruções para Avaliação</h2></div>
                    <div class="panel instructions-panel">
                        <div><strong>Como avaliar</strong><p>Para cada participante, atribua notas de 0,0 a 10,0 para cada critério de avaliação.</p></div>
                        <div><strong>Importante</strong><p>Suas notas são confidenciais e só serão usadas na apuração do evento.</p></div>
                        <div><strong>Critérios</strong><p>A avaliação deve considerar todos os critérios e pesos definidos pelo organizador.</p></div>
                        <div><strong>Tempo</strong><p>Fique atento ao tempo disponível para avaliar todos os participantes.</p></div>
                    </div>
                    <label class="confirm-instructions"><input type="checkbox"> Li e concordo com as instruções acima</label>
                    <a class="button primary" href="?page=judge-panel&section=votacao">Iniciar Avaliação</a>
                </section>
            <?php elseif (!$criteria): ?>
                <section class="panel empty-state">
                    <h2>Nenhum critério disponível</h2>
                    <p class="muted">O administrador precisa cadastrar critérios antes de iniciar a avaliação.</p>
                </section>
            <?php elseif (!$selected): ?>
                <section class="panel empty-state">
                    <h2>Nenhum participante cadastrado</h2>
                    <p class="muted">Aguarde o administrador adicionar participantes ao evento.</p>
                </section>
            <?php elseif (!$periodIsActive): ?>
                <section class="judge-list-page">
                    <div class="panel empty-state">
                        <h2>Período de avaliação indisponível</h2>
                        <p class="muted">O administrador ainda não abriu um período ativo para este evento.</p>
                        <a class="button primary" href="?page=judge-panel&section=criterios">Ver critérios</a>
                    </div>
                </section>
            <?php else: ?>
                <div class="judge-work-head">
                    <h2>Avaliar Participante</h2>
                    <div class="participant-switch">
                        <?php if ($prev): ?><a href="?page=judge-panel&participant_id=<?= (int)$prev['id'] ?>">‹</a><?php endif; ?>
                        <span>Participante <?= $selectedIndex + 1 ?> de <?= count($participants) ?></span>
                        <?php if ($next): ?><a href="?page=judge-panel&participant_id=<?= (int)$next['id'] ?>">›</a><?php endif; ?>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="finalize_evaluation">
                        <button class="button primary" type="submit"><?= $isFinished ? 'Avaliações Finalizadas' : 'Finalizar Avaliações' ?></button>
                    </form>
                </div>

                <section class="participant-hero-card">
                    <?= participant_photo_html($selected, 'large') ?>
                    <span class="order-badge photo-overlap"><?= str_pad((string)(int)$selected['order'], 2, '0', STR_PAD_LEFT) ?></span>
                    <div>
                        <h2><?= h($selected['name']) ?></h2>
                        <p>Categoria: <mark><?= h($selected['category'] ?: 'Geral') ?></mark></p>
                        <p>Ordem de apresentação: <?= str_pad((string)(int)$selected['order'], 2, '0', STR_PAD_LEFT) ?></p>
                    </div>
                    <div class="info-note">Avalie cada critério abaixo com notas de 0,0 a 10,0. Você pode alterar as notas até finalizar as avaliações.</div>
                </section>

                <section class="judge-tutorial">
                    <button class="tutorial-toggle" type="button" data-toggle-tutorial>Como votar nesta tela</button>
                    <div class="tutorial-content" data-tutorial-content hidden>
                        <p><strong>1.</strong> Toque primeiro na escala inteira de 0 a 10.</p>
                        <p><strong>2.</strong> Depois selecione os décimos exibidos logo abaixo, como 9,1 até 9,9.</p>
                        <p><strong>3.</strong> Escreva uma observação se quiser justificar a avaliação.</p>
                        <p><strong>4.</strong> Clique em Salvar Notas antes de ir para o próximo participante.</p>
                    </div>
                </section>

                <section class="judge-vote-grid">
                    <form class="panel criteria-vote-form" method="post" data-offline-form data-participant-id="<?= (int)$selected['id'] ?>" data-event-id="<?= $eventId ?>" data-judge-id="<?= $judgeId ?>">
                        <input type="hidden" name="action" value="save_votes">
                        <input type="hidden" name="participant_id" value="<?= (int)$selected['id'] ?>">
                        <input type="hidden" name="next_url" value="">
                        <input type="hidden" name="signature_touch" value="<?= h($selectedSignature['touch']) ?>" data-signature-output>
                        <div class="offline-status" data-offline-status hidden></div>
                        <div class="criteria-head"><strong>Critérios de Avaliação</strong><strong>Escala</strong><strong>Nota</strong></div>
                        <?php foreach ($criteria as $criterion): ?>
                            <?php $current = (string)($scores[(int)$criterion['id']] ?? ''); ?>
                            <div class="criterion-row">
                                <div class="criterion-name">
                                    <?= card_icone('estrela', 'blue') ?>
                                    <div><strong><?= h($criterion['name']) ?></strong><small><?= h(($criterion['description'] ?? '') !== '' ? $criterion['description'] : 'Avaliação do participante neste critério.') ?></small></div>
                                </div>
                                <div class="score-picker-wrap">
                                    <span class="score-label-mobile">Escala</span>
                                    <div class="score-picker">
                                        <?php for ($score = 0; $score <= 10; $score++): ?>
                                            <?php
                                                $currentFloat = $current !== '' ? (float)$current : null;
                                                $scoreSelected = $currentFloat !== null && (($score === 10 && $currentFloat === 10.0) || ($score < 10 && floor($currentFloat) === $score));
                                            ?>
                                            <label class="<?= $scoreSelected ? 'checked' : '' ?>">
                                                <input type="radio" name="score_buttons[<?= (int)$criterion['id'] ?>]" value="<?= $score ?>" <?= $scoreSelected ? 'checked' : '' ?> <?= $isFinished ? 'disabled' : '' ?>>
                                                <span><?= $score ?></span>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="decimal-picker" data-decimal-picker <?= $current === '' || ((float)$current === 10.0) ? 'hidden' : '' ?>></div>
                                </div>
                                <label class="score-input-wrap">
                                    <span class="score-label-mobile">Nota</span>
                                    <input class="score-box" required name="scores[<?= (int)$criterion['id'] ?>]" type="number" min="0" max="10" step="0.1" inputmode="decimal" value="<?= $current !== '' ? h((string)(float)$current) : '' ?>" placeholder="-" <?= $isFinished ? 'disabled' : '' ?>>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <label class="observations">Observações (opcional)<textarea name="observation" rows="3" placeholder="Descreva aqui seus comentários sobre a apresentação do participante..." <?= $isFinished ? 'disabled' : '' ?>><?= h($observation['text'] ?? '') ?></textarea></label>
                        <div class="review-meta-grid">
                            <div class="signature-block" data-signature-block>
                                <div class="signature-mode">
                                    <span>Assinatura do jurado</span>
                                    <label><input type="radio" name="signature_mode" value="text" <?= $selectedSignature['mode'] !== 'touch' ? 'checked' : '' ?> <?= $isFinished ? 'disabled' : '' ?>> Digitada</label>
                                    <label><input type="radio" name="signature_mode" value="touch" <?= $selectedSignature['mode'] === 'touch' ? 'checked' : '' ?> <?= $isFinished ? 'disabled' : '' ?>> Touch</label>
                                </div>
                                <label class="signature-text-field" data-signature-text>
                                    <span>Nome / assinatura digitada</span>
                                    <input name="signature" type="text" maxlength="120" placeholder="Digite seu nome ou assinatura" value="<?= h($selectedSignature['text'] !== '' ? $selectedSignature['text'] : ($_SESSION['judge_name'] ?? '')) ?>" <?= $isFinished ? 'disabled' : '' ?>>
                                </label>
                                <div class="signature-pad-wrap" data-signature-pad-wrap <?= $selectedSignature['mode'] === 'touch' ? '' : 'hidden' ?>>
                                    <span>Assinatura por toque</span>
                                    <canvas class="signature-pad" width="640" height="220" data-signature-pad></canvas>
                                    <div class="signature-pad-actions">
                                        <button class="button ghost small" type="button" data-signature-clear <?= $isFinished ? 'disabled' : '' ?>>Limpar assinatura</button>
                                    </div>
                                </div>
                            </div>
                            <label class="checklist-toggle">
                                <input type="checkbox" name="checklist_done" <?= ($selectedReview['checklist_done'] ?? false) ? 'checked' : '' ?> <?= $isFinished ? 'disabled' : '' ?>>
                                <span>Checklist concluído para esta candidata</span>
                            </label>
                        </div>
                        <div class="judge-nav">
                            <?php if ($prev): ?><a class="button ghost" href="?page=judge-panel&participant_id=<?= (int)$prev['id'] ?>">Participante Anterior</a><?php else: ?><span></span><?php endif; ?>
                            <button class="button primary" type="submit" data-next-url="" <?= $isFinished ? 'disabled' : '' ?>>Salvar Notas</button>
                            <?php if ($next): ?><button class="button primary" type="submit" data-next-url="?page=judge-panel&participant_id=<?= (int)$next['id'] ?>&section=votacao" <?= $isFinished ? 'disabled' : '' ?>>Salvar e Próximo Participante</button><?php endif; ?>
                        </div>
                    </form>
                    <aside class="panel evaluation-summary">
                        <h2>Resumo da Avaliação</h2>
                        <div class="average-ring"><strong><?= number_format($scoreAverage, 1, ',', '.') ?></strong><span>Média Geral</span></div>
                        <?php foreach ($criteria as $criterion): ?>
                            <p><span><?= h($criterion['name']) ?></span><strong><?= isset($scores[(int)$criterion['id']]) ? number_format((float)$scores[(int)$criterion['id']], 1, ',', '.') : '-' ?></strong></p>
                        <?php endforeach; ?>
                        <p class="summary-total"><span>Total</span><strong><?= number_format($scoreTotal, 1, ',', '.') ?></strong></p>
                        <p class="summary-average"><span>Média Geral</span><strong><?= number_format($scoreAverage, 1, ',', '.') ?></strong></p>
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
            <p class="muted">Quando o administrador adicionar participantes, os cards de votação aparecem aqui.</p>
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
                        <small><?= h($participant['category']) ?> <?= $participant['order'] ? '· ordem ' . (int)$participant['order'] : '' ?></small>
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
            <p class="eyebrow">Resultado público</p>
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
                <h2>Resultados ainda não publicados</h2>
                <p class="muted">A publicação dos resultados será liberada conforme a configuração do evento.</p>
            </div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}

function render_monitor_page(): void
{
    /* [SEGURANCA] Esta tela estava aberta a qualquer visitante e mostrava o
     * nome de cada jurado e o quanto cada um já avaliou. É informação da
     * organização, não da plateia — quem assiste ao festival acompanha pelo
     * Ranking (?page=ranking), que segue público.
     *
     * Se for projetada num telão, basta deixar a sessão do administrador
     * aberta naquela máquina. */
    require_admin();

    $db = db_read();
    $eventId = active_event_id($db);
    $event = $eventId ? find_by_id($db['events'] ?? [], $eventId) : null;
    $events = event_options($db);
    $matrix = $eventId ? participant_completion_matrix($db, $eventId) : ['judges' => [], 'rows' => []];
    $rows = $matrix['rows'] ?? [];
    $judges = $matrix['judges'] ?? [];
    $participantsDone = count(array_filter($rows, fn($row) => !empty($row['all_done'])));
    $participantsPending = max(0, count($rows) - $participantsDone);

    render_header('Acompanhamento ao Vivo');
    ?>
    <?php /* 15s: o acompanhamento e projetado e precisa acompanhar as notas,
             mas 5s recarregava rapido demais para dar tempo de ler. */ ?>
    <section class="live-scoreboard" data-refresh-seconds="15">
        <div class="scoreboard-title">
            <div>
                <span>Acompanhamento ao vivo</span>
                <h2><?= h($event['name'] ?? 'Selecione um evento') ?></h2>
            </div>
            <form class="inline-form" method="get">
                <input type="hidden" name="page" value="acompanhamento">
                <select name="event_id" onchange="this.form.submit()">
                    <?php foreach ($events as $item): ?>
                        <option value="<?= (int)$item['id'] ?>" <?= $eventId === (int)$item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <section class="monitor-stats">
            <div class="monitor-card">
                <strong><?= count($rows) ?></strong>
                <span>Candidatas</span>
            </div>
            <div class="monitor-card">
                <strong><?= count($judges) ?></strong>
                <span>Jurados</span>
            </div>
            <div class="monitor-card done">
                <strong><?= $participantsDone ?></strong>
                <span>Fechadas</span>
            </div>
            <div class="monitor-card">
                <strong><?= $participantsPending ?></strong>
                <span>Pendentes</span>
            </div>
        </section>

        <div class="monitor-legend">
            <span class="monitor-pill done">Concluído</span>
            <span class="monitor-pill partial">Checklist pendente</span>
            <span class="monitor-pill pending">Pendente</span>
            <small>Atualização automática a cada 5 segundos.</small>
        </div>

        <div class="panel data-panel">
            <div class="table-wrap">
                <table class="admin-table responsive-cards">
                    <thead>
                        <tr>
                            <th>Participante</th>
                            <?php foreach ($matrix['judges'] as $judge): ?>
                                <th><?= h($judge['name']) ?></th>
                            <?php endforeach; ?>
                            <th>Resumo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$matrix['rows']): ?>
                        <tr><td colspan="<?= max(2, count($matrix['judges']) + 2) ?>">Nenhum participante ou jurado cadastrado neste evento.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($matrix['rows'] as $row): ?>
                        <tr>
                            <td data-label="Participante">
                                <span class="participant-name-cell">
                                    <?= participant_photo_html($row['participant'], 'thumb') ?>
                                    <?= h($row['participant']['name']) ?>
                                </span>
                            </td>
                            <?php foreach ($row['judge_statuses'] as $judgeStatus): ?>
                                <td data-label="<?= h($judgeStatus['judge']['name']) ?>">
                                    <span class="monitor-pill <?= $judgeStatus['done'] ? 'done' : ($judgeStatus['has_all_scores'] ? 'partial' : 'pending') ?>">
                                        <?= $judgeStatus['done'] ? 'Concluido' : ($judgeStatus['has_all_scores'] ? 'Checklist pendente' : 'Pendente') ?>
                                    </span>
                                </td>
                            <?php endforeach; ?>
                            <td data-label="Resumo">
                                <span class="monitor-summary <?= $row['all_done'] ? 'done' : '' ?>">
                                    <?= (int)$row['completed_count'] ?>/<?= (int)$row['judges_total'] ?> concluidos
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
    'trocar-senha' => render_trocar_senha(),
    'dashboard' => render_dashboard(),
    'judge-panel' => render_judge_panel(),
    'ranking' => render_ranking_page(),
    'acompanhamento' => render_monitor_page(),
    default => render_login_home(),
};
