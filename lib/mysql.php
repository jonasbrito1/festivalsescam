<?php

/**
 * Camada MySQL do Festival de Calouros v2.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ESTE ARQUIVO EXISTE
 * ---------------------------------------------------------------------------
 * O sistema guarda tudo em data/db.json e, quando havia SQL Server, gravava
 * assim: apagava TODAS as linhas das 13 tabelas e reinseria o array PHP
 * inteiro, a cada salvamento.
 *
 * Isso causa perda de voto com jurados simultaneos:
 *
 *   1. Jurado A abre o painel  -> carrega o snapshot completo
 *   2. Jurado B abre o painel  -> carrega o snapshot completo
 *   3. A salva -> apaga tudo e grava o snapshot de A
 *   4. B salva -> apaga tudo e grava o snapshot de B, lido ANTES do passo 3
 *   5. As notas de A sumiram
 *
 * Aqui as rotas de alta concorrencia (notas, observacoes e fichas dos
 * jurados) passam a gravar apenas a propria linha, com
 * INSERT ... ON DUPLICATE KEY UPDATE apoiado nas chaves unicas do schema.
 * Dois jurados gravando ao mesmo tempo nao se atropelam.
 *
 * Os cadastros administrativos (evento, jurado, participante, criterio)
 * continuam por snapshot: sao feitos por um administrador de cada vez, sem
 * disputa, e converte-los agora ampliaria o risco sem ganho pratico.
 *
 * ---------------------------------------------------------------------------
 * MODO DE OPERACAO
 * ---------------------------------------------------------------------------
 * Controlado por FESTIVAL_DB_MODO:
 *
 *   'off'      (padrao) nada acontece; o sistema opera so com o db.json
 *   'espelho'  o db.json continua sendo a fonte da verdade e o MySQL recebe
 *              as mesmas gravacoes em paralelo, para conferencia
 *   'primario' o MySQL passa a ser a fonte da verdade
 *
 * A virada e so trocar essa variavel — sem reimplantar codigo.
 */

declare(strict_types=1);

function mysql_modo(): string
{
    $modo = getenv('FESTIVAL_DB_MODO') ?: 'off';

    return in_array($modo, ['off', 'espelho', 'primario'], true) ? $modo : 'off';
}

function mysql_ativo(): bool
{
    return mysql_modo() !== 'off';
}

/**
 * Conexao unica. Devolve null (em vez de estourar) se algo faltar: no modo
 * espelho, uma falha do MySQL nao pode derrubar o site, que ainda roda sobre
 * o db.json.
 */
function mysql_conexao(): ?PDO
{
    static $pdo = null;
    static $tentou = false;

    if ($tentou) {
        return $pdo;
    }

    $tentou = true;

    if (!mysql_ativo()) {
        return null;
    }

    $host = getenv('FESTIVAL_DB_HOST') ?: '127.0.0.1';
    $porta = getenv('FESTIVAL_DB_PORT') ?: '3306';
    $nome = getenv('FESTIVAL_DB_NAME') ?: 'festival_v2';
    $usuario = getenv('FESTIVAL_DB_USER') ?: '';
    $senha = getenv('FESTIVAL_DB_PASS') ?: '';

    if ($usuario === '') {
        error_log('MySQL: FESTIVAL_DB_USER nao definido.');
        return null;
    }

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $porta, $nome),
            $usuario,
            $senha,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (Throwable $e) {
        error_log('MySQL indisponivel: ' . $e->getMessage());
        $pdo = null;
    }

    return $pdo;
}

/* ===========================================================================
 * ESCRITA DIRIGIDA — as rotas onde dois jurados disputam ao mesmo tempo
 * ======================================================================== */

/**
 * Grava as notas de UM jurado para UM participante.
 *
 * @param array<int,float|string> $notas  [criterion_id => score]
 */
function mysql_salvar_votos(int $eventoId, int $juradoId, int $participanteId, array $notas): bool
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        $sql = $pdo->prepare(
            'INSERT INTO votes (event_id, judge_id, participant_id, criterion_id, score)
             VALUES (:evento, :jurado, :participante, :criterio, :nota)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($notas as $criterioId => $nota) {
            if ($nota === '' || $nota === null) {
                continue;
            }

            $sql->execute([
                ':evento'       => $eventoId,
                ':jurado'       => $juradoId,
                ':participante' => $participanteId,
                ':criterio'     => (int) $criterioId,
                ':nota'         => round((float) $nota, 1),
            ]);
        }

        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('MySQL salvar_votos: ' . $e->getMessage());

        return false;
    }
}

function mysql_salvar_observacao(int $eventoId, int $juradoId, int $participanteId, string $texto): bool
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return false;
    }

    try {
        // Observacao apagada na tela: a linha tem de sair do banco tambem,
        // senao o texto antigo reaparece quando o MySQL virar primario.
        if (trim($texto) === '') {
            $pdo->prepare(
                'DELETE FROM judge_observations
                 WHERE event_id = :evento AND judge_id = :jurado AND participant_id = :participante'
            )->execute([
                ':evento'       => $eventoId,
                ':jurado'       => $juradoId,
                ':participante' => $participanteId,
            ]);

            return true;
        }

        $sql = $pdo->prepare(
            'INSERT INTO judge_observations (event_id, judge_id, participant_id, observation)
             VALUES (:evento, :jurado, :participante, :texto)
             ON DUPLICATE KEY UPDATE observation = VALUES(observation), updated_at = CURRENT_TIMESTAMP'
        );
        $sql->execute([
            ':evento'       => $eventoId,
            ':jurado'       => $juradoId,
            ':participante' => $participanteId,
            ':texto'        => mb_substr($texto, 0, 500),
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('MySQL salvar_observacao: ' . $e->getMessage());

        return false;
    }
}

function mysql_salvar_ficha(
    int $eventoId,
    int $juradoId,
    int $participanteId,
    bool $checklistFeito,
    string $modoAssinatura,
    string $assinaturaTexto,
    string $assinaturaTraco
): bool {
    $pdo = mysql_conexao();
    if (!$pdo) {
        return false;
    }

    try {
        $sql = $pdo->prepare(
            'INSERT INTO judge_reviews
                (event_id, judge_id, participant_id, checklist_done,
                 signature_mode, signature_text, signature_touch)
             VALUES (:evento, :jurado, :participante, :checklist, :modo, :texto, :traco)
             ON DUPLICATE KEY UPDATE
                checklist_done  = VALUES(checklist_done),
                signature_mode  = VALUES(signature_mode),
                signature_text  = VALUES(signature_text),
                signature_touch = VALUES(signature_touch),
                updated_at      = CURRENT_TIMESTAMP'
        );
        $sql->execute([
            ':evento'       => $eventoId,
            ':jurado'       => $juradoId,
            ':participante' => $participanteId,
            ':checklist'    => $checklistFeito ? 1 : 0,
            ':modo'         => in_array($modoAssinatura, ['text', 'touch'], true) ? $modoAssinatura : 'text',
            ':texto'        => mb_substr($assinaturaTexto, 0, 255),
            ':traco'        => $assinaturaTraco !== '' ? $assinaturaTraco : null,
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('MySQL salvar_ficha: ' . $e->getMessage());

        return false;
    }
}

/* ===========================================================================
 * SNAPSHOT — cadastros administrativos (baixa concorrencia)
 * ======================================================================== */

/**
 * Sincroniza os cadastros a partir do array completo.
 *
 * Diferenca importante em relacao ao codigo antigo: as tabelas de VOTOS,
 * OBSERVACOES e FICHAS nao sao tocadas aqui. Elas so mudam pela escrita
 * dirigida acima — e por isso um cadastro administrativo salvo no meio do
 * evento nao apaga mais as notas ja lancadas.
 */
function mysql_sincronizar_cadastros(array $db): bool
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        mysql_sync_admins($pdo, $db['admins'] ?? []);
        mysql_sync_events($pdo, $db['events'] ?? []);
        mysql_sync_judges($pdo, $db['judges'] ?? []);
        mysql_sync_participants($pdo, $db['participants'] ?? []);
        mysql_sync_criteria($pdo, $db['criteria'] ?? []);

        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('MySQL sincronizar_cadastros: ' . $e->getMessage());

        return false;
    }
}

/** Remove do banco as linhas cujo id sumiu do array (exclusoes feitas na UI). */
function mysql_remover_ausentes(PDO $pdo, string $tabela, array $idsPresentes): void
{
    if ($idsPresentes === []) {
        $pdo->exec("DELETE FROM `{$tabela}`");
        return;
    }

    $marcadores = implode(',', array_fill(0, count($idsPresentes), '?'));
    $sql = $pdo->prepare("DELETE FROM `{$tabela}` WHERE id NOT IN ({$marcadores})");
    $sql->execute(array_values($idsPresentes));
}

function mysql_data(?string $valor): ?string
{
    if (!$valor) {
        return null;
    }

    $ts = strtotime($valor);

    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function mysql_sync_admins(PDO $pdo, array $itens): void
{
    $ids = [];
    $sql = $pdo->prepare(
        'INSERT INTO admins (id, name, email, phone, password_hash, must_change_password, created_at)
         VALUES (:id, :nome, :email, :fone, :senha, :trocar, :criado)
         ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email),
                                 phone = VALUES(phone), password_hash = VALUES(password_hash),
                                 must_change_password = VALUES(must_change_password)'
    );

    foreach ($itens as $a) {
        $ids[] = (int) $a['id'];
        $sql->execute([
            ':id'     => (int) $a['id'],
            ':nome'   => (string) $a['name'],
            ':email'  => (string) $a['email'],
            ':fone'   => (string) ($a['phone'] ?? ''),
            ':senha'  => (string) $a['password'],
            /* A marca precisa ir junto no snapshot. Sem isto, qualquer
               gravacao de cadastro apagaria a exigencia de troca e a senha
               provisoria voltaria a valer para sempre. */
            ':trocar' => !empty($a['must_change_password']) ? 1 : 0,
            ':criado' => mysql_data($a['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
    }

    mysql_remover_ausentes($pdo, 'admins', $ids);
}

function mysql_sync_events(PDO $pdo, array $itens): void
{
    $ids = [];

    $sqlEvento = $pdo->prepare(
        'INSERT INTO events (id, name, description, start_date, end_date, location,
                             status, event_format, evaluation_minutes, created_at, updated_at)
         VALUES (:id, :nome, :desc, :inicio, :fim, :local, :status, :formato, :minutos, :criado, :atualizado)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
             start_date = VALUES(start_date), end_date = VALUES(end_date),
             location = VALUES(location), status = VALUES(status),
             event_format = VALUES(event_format), evaluation_minutes = VALUES(evaluation_minutes),
             updated_at = VALUES(updated_at)'
    );

    foreach ($itens as $e) {
        $id = (int) $e['id'];
        $ids[] = $id;

        $inicio = !empty($e['date']) ? date('Y-m-d', (int) strtotime($e['date'])) : date('Y-m-d');
        $fim = !empty($e['end_date']) ? date('Y-m-d', (int) strtotime($e['end_date'])) : $inicio;

        $sqlEvento->execute([
            ':id'         => $id,
            ':nome'       => (string) $e['name'],
            ':desc'       => (string) ($e['description'] ?? ''),
            ':inicio'     => $inicio,
            ':fim'        => $fim,
            ':local'      => (string) ($e['location'] ?? ''),
            ':status'     => in_array($e['status'] ?? '', ['rascunho', 'aberto', 'encerrado'], true) ? $e['status'] : 'rascunho',
            ':formato'    => in_array($e['event_format'] ?? '', ['unica', 'fases'], true) ? $e['event_format'] : 'unica',
            ':minutos'    => max(1, (int) ($e['evaluation_minutes'] ?? 136)),
            ':criado'     => mysql_data($e['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
            ':atualizado' => mysql_data($e['updated_at'] ?? null),
        ]);

        mysql_sync_config_evento($pdo, $id, $e);
    }

    mysql_remover_ausentes($pdo, 'events', $ids);
}

/** Tabelas 1-para-1 penduradas no evento. */
function mysql_sync_config_evento(PDO $pdo, int $eventoId, array $e): void
{
    $av = $e['advanced'] ?? [];
    $pdo->prepare(
        'INSERT INTO event_advanced_settings
            (event_id, allow_edit_after_submit, show_partial_average, tie_breaker,
             decimal_places, prevent_multi_login)
         VALUES (:id, :edit, :parcial, :desempate, :casas, :multi)
         ON DUPLICATE KEY UPDATE allow_edit_after_submit = VALUES(allow_edit_after_submit),
             show_partial_average = VALUES(show_partial_average),
             tie_breaker = VALUES(tie_breaker), decimal_places = VALUES(decimal_places),
             prevent_multi_login = VALUES(prevent_multi_login)'
    )->execute([
        ':id'        => $eventoId,
        ':edit'      => !empty($av['allow_edit_after_submit']) ? 1 : 0,
        ':parcial'   => !empty($av['show_partial_average']) ? 1 : 0,
        ':desempate' => (string) ($av['tie_breaker'] ?? 'highest_weight'),
        ':casas'     => min(3, max(0, (int) ($av['decimal_places'] ?? 2))),
        ':multi'     => !empty($av['prevent_multi_login']) ? 1 : 0,
    ]);

    $pub = $e['publication'] ?? [];
    $ordem = ($pub['order'] ?? 'score_desc') === 'name' ? 'name' : 'score_desc';
    $pdo->prepare(
        'INSERT INTO event_publication
            (event_id, auto_publish, publish_at, show_individual_scores,
             show_judge_comments, result_order)
         VALUES (:id, :auto, :quando, :individual, :comentarios, :ordem)
         ON DUPLICATE KEY UPDATE auto_publish = VALUES(auto_publish),
             publish_at = VALUES(publish_at),
             show_individual_scores = VALUES(show_individual_scores),
             show_judge_comments = VALUES(show_judge_comments),
             result_order = VALUES(result_order)'
    )->execute([
        ':id'          => $eventoId,
        ':auto'        => !empty($pub['auto_publish']) ? 1 : 0,
        ':quando'      => mysql_data($pub['publish_date'] ?? null),
        ':individual'  => !empty($pub['show_individual']) ? 1 : 0,
        ':comentarios' => !empty($pub['show_comments']) ? 1 : 0,
        ':ordem'       => $ordem,
    ]);

    $nt = $e['notifications'] ?? [];
    $pdo->prepare(
        'INSERT INTO event_notifications
            (event_id, judge_open, judge_reminder, admin_complete,
             participant_results, event_changes)
         VALUES (:id, :abre, :lembra, :completo, :resultados, :mudancas)
         ON DUPLICATE KEY UPDATE judge_open = VALUES(judge_open),
             judge_reminder = VALUES(judge_reminder), admin_complete = VALUES(admin_complete),
             participant_results = VALUES(participant_results), event_changes = VALUES(event_changes)'
    )->execute([
        ':id'         => $eventoId,
        ':abre'       => !empty($nt['judge_open']) ? 1 : 0,
        ':lembra'     => !empty($nt['judge_reminder']) ? 1 : 0,
        ':completo'   => !empty($nt['admin_complete']) ? 1 : 0,
        ':resultados' => !empty($nt['participant_results']) ? 1 : 0,
        ':mudancas'   => !empty($nt['event_changes']) ? 1 : 0,
    ]);

    $fa = $e['phase_advancers'] ?? [];
    $pdo->prepare(
        'INSERT INTO event_phase_advancers
            (event_id, classificatoria_count, semifinal_count, final_count)
         VALUES (:id, :cl, :se, :fi)
         ON DUPLICATE KEY UPDATE classificatoria_count = VALUES(classificatoria_count),
             semifinal_count = VALUES(semifinal_count), final_count = VALUES(final_count)'
    )->execute([
        ':id' => $eventoId,
        ':cl' => max(0, (int) ($fa['classificatoria'] ?? 12)),
        ':se' => max(0, (int) ($fa['semifinal'] ?? 6)),
        ':fi' => max(0, (int) ($fa['final'] ?? 3)),
    ]);

    // Periodos: o JSON guarda um objeto com chave livre (ex.: 'unica', 'final')
    $periodos = $e['periods'] ?? [];
    if (is_array($periodos)) {
        $sql = $pdo->prepare(
            'INSERT INTO event_periods (event_id, period_key, name, starts_at, ends_at, status)
             VALUES (:id, :chave, :nome, :inicio, :fim, :status)
             ON DUPLICATE KEY UPDATE name = VALUES(name), starts_at = VALUES(starts_at),
                 ends_at = VALUES(ends_at), status = VALUES(status)'
        );

        foreach ($periodos as $chave => $p) {
            $p = is_array($p) ? $p : [];
            $status = $p['status'] ?? 'programado';

            $sql->execute([
                ':id'     => $eventoId,
                ':chave'  => mb_substr((string) $chave, 0, 40),
                ':nome'   => mb_substr((string) ($p['name'] ?? $chave), 0, 80),
                ':inicio' => mysql_data($p['starts_at'] ?? null),
                ':fim'    => mysql_data($p['ends_at'] ?? null),
                ':status' => in_array($status, ['ativo', 'programado', 'encerrado'], true) ? $status : 'programado',
            ]);
        }
    }
}

function mysql_sync_judges(PDO $pdo, array $itens): void
{
    $ids = [];
    $sql = $pdo->prepare(
        'INSERT INTO judges (id, event_id, name, username, phone, password_hash, status, created_at)
         VALUES (:id, :evento, :nome, :usuario, :fone, :senha, :status, :criado)
         ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), name = VALUES(name),
             username = VALUES(username), phone = VALUES(phone),
             password_hash = VALUES(password_hash), status = VALUES(status)'
    );

    foreach ($itens as $j) {
        $ids[] = (int) $j['id'];
        $sql->execute([
            ':id'      => (int) $j['id'],
            ':evento'  => (int) $j['event_id'],
            ':nome'    => (string) $j['name'],
            ':usuario' => (string) $j['username'],
            ':fone'    => (string) ($j['phone'] ?? ''),
            ':senha'   => (string) $j['password'],
            ':status'  => ($j['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo',
            ':criado'  => mysql_data($j['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
    }

    mysql_remover_ausentes($pdo, 'judges', $ids);
}

function mysql_sync_participants(PDO $pdo, array $itens): void
{
    $ids = [];
    $sql = $pdo->prepare(
        'INSERT INTO participants
            (id, event_id, name, category, song, presentation_order, photo_url, status, created_at)
         VALUES (:id, :evento, :nome, :categoria, :musica, :ordem, :foto, :status, :criado)
         ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), name = VALUES(name),
             category = VALUES(category), song = VALUES(song),
             presentation_order = VALUES(presentation_order), photo_url = VALUES(photo_url),
             status = VALUES(status)'
    );

    foreach ($itens as $p) {
        $ids[] = (int) $p['id'];
        $sql->execute([
            ':id'        => (int) $p['id'],
            ':evento'    => (int) $p['event_id'],
            ':nome'      => (string) $p['name'],
            ':categoria' => (string) ($p['category'] ?? ''),
            ':musica'    => (string) ($p['song'] ?? ''),
            ':ordem'     => (int) ($p['order'] ?? 0),
            ':foto'      => (string) ($p['photo'] ?? ''),
            ':status'    => ($p['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo',
            ':criado'    => mysql_data($p['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
    }

    mysql_remover_ausentes($pdo, 'participants', $ids);
}

function mysql_sync_criteria(PDO $pdo, array $itens): void
{
    $ids = [];
    $sql = $pdo->prepare(
        'INSERT INTO criteria (id, event_id, name, description, weight, display_order, created_at)
         VALUES (:id, :evento, :nome, :desc, :peso, :ordem, :criado)
         ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), name = VALUES(name),
             description = VALUES(description), weight = VALUES(weight),
             display_order = VALUES(display_order)'
    );

    foreach ($itens as $c) {
        $ids[] = (int) $c['id'];
        $peso = (float) ($c['weight'] ?? 1);

        $sql->execute([
            ':id'     => (int) $c['id'],
            ':evento' => (int) $c['event_id'],
            ':nome'   => (string) $c['name'],
            ':desc'   => (string) ($c['description'] ?? ''),
            // O schema exige weight > 0; peso zerado no JSON viraria erro.
            ':peso'   => $peso > 0 ? $peso : 1,
            ':ordem'  => (int) ($c['display_order'] ?? 0),
            ':criado' => mysql_data($c['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);
    }

    mysql_remover_ausentes($pdo, 'criteria', $ids);
}

/* ===========================================================================
 * LEITURA — usada quando o modo virar 'primario'
 * ======================================================================== */

/**
 * Monta o array completo do sistema a partir do MySQL, no MESMO formato que
 * o data/db.json sempre teve.
 *
 * Isso e proposital: as 3.500 linhas do index.php leem esse formato. Trocar
 * a origem sem trocar o formato mantem a mudanca contida nesta funcao, em
 * vez de espalha-la por toda a aplicacao.
 *
 * Devolve null se o MySQL estiver fora — quem chama decide o que fazer.
 */
function mysql_ler_banco(): ?array
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return null;
    }

    try {
        $db = [
            'admins'        => [],
            'events'        => [],
            'criteria'      => [],
            'judges'        => [],
            'participants'  => [],
            'votes'         => [],
            'observations'  => [],
            'judge_reviews' => [],
        ];

        foreach ($pdo->query('SELECT * FROM admins ORDER BY id') as $r) {
            $db['admins'][] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'email'      => (string) $r['email'],
                'phone'      => (string) ($r['phone'] ?? ''),
                'password'   => (string) $r['password_hash'],
                'must_change_password' => !empty($r['must_change_password']),
                'created_at' => mysql_iso($r['created_at']),
            ];
        }

        /* --- Configuracoes 1-para-1, indexadas por evento --- */
        $avancado = mysql_indexar($pdo, 'event_advanced_settings');
        $publicacao = mysql_indexar($pdo, 'event_publication');
        $notificacoes = mysql_indexar($pdo, 'event_notifications');
        $fases = mysql_indexar($pdo, 'event_phase_advancers');

        $periodos = [];
        foreach ($pdo->query('SELECT * FROM event_periods ORDER BY id') as $p) {
            $periodos[(int) $p['event_id']][(string) $p['period_key']] = [
                'name'      => (string) $p['name'],
                'starts_at' => mysql_iso($p['starts_at']),
                'ends_at'   => mysql_iso($p['ends_at']),
                'status'    => (string) $p['status'],
            ];
        }

        foreach ($pdo->query('SELECT * FROM events ORDER BY id') as $e) {
            $id = (int) $e['id'];
            $av = $avancado[$id] ?? [];
            $pb = $publicacao[$id] ?? [];
            $nt = $notificacoes[$id] ?? [];
            $fa = $fases[$id] ?? [];

            $db['events'][] = [
                'id'                 => $id,
                'name'               => (string) $e['name'],
                // No JSON o campo chama 'date'; no schema, 'start_date'.
                'date'               => (string) $e['start_date'],
                'end_date'           => (string) ($e['end_date'] ?? ''),
                'status'             => (string) $e['status'],
                'description'        => (string) ($e['description'] ?? ''),
                'location'           => (string) ($e['location'] ?? ''),
                'evaluation_minutes' => (int) $e['evaluation_minutes'],
                'event_format'       => (string) $e['event_format'],
                'created_at'         => mysql_iso($e['created_at']),
                'updated_at'         => mysql_iso($e['updated_at']),
                'advanced' => [
                    'allow_edit_after_submit' => (bool) ($av['allow_edit_after_submit'] ?? false),
                    'show_partial_average'    => (bool) ($av['show_partial_average'] ?? false),
                    'tie_breaker'             => (string) ($av['tie_breaker'] ?? 'highest_weight'),
                    'decimal_places'          => (int) ($av['decimal_places'] ?? 2),
                    'prevent_multi_login'     => (bool) ($av['prevent_multi_login'] ?? true),
                ],
                'publication' => [
                    'auto_publish'    => (bool) ($pb['auto_publish'] ?? true),
                    // 'publish_at' no schema, 'publish_date' no JSON.
                    'publish_date'    => mysql_iso($pb['publish_at'] ?? null),
                    'show_individual' => (bool) ($pb['show_individual_scores'] ?? false),
                    'show_comments'   => (bool) ($pb['show_judge_comments'] ?? false),
                    'order'           => (string) ($pb['result_order'] ?? 'score_desc'),
                ],
                'notifications' => [
                    'judge_open'          => (bool) ($nt['judge_open'] ?? true),
                    'judge_reminder'      => (bool) ($nt['judge_reminder'] ?? true),
                    'admin_complete'      => (bool) ($nt['admin_complete'] ?? true),
                    'participant_results' => (bool) ($nt['participant_results'] ?? true),
                    'event_changes'       => (bool) ($nt['event_changes'] ?? false),
                ],
                'phase_advancers' => [
                    'classificatoria' => (int) ($fa['classificatoria_count'] ?? 12),
                    'semifinal'       => (int) ($fa['semifinal_count'] ?? 6),
                    'final'           => (int) ($fa['final_count'] ?? 3),
                ],
                'periods' => $periodos[$id] ?? [],
            ];
        }

        foreach ($pdo->query('SELECT * FROM judges ORDER BY id') as $r) {
            $db['judges'][] = [
                'id'         => (int) $r['id'],
                'event_id'   => (int) $r['event_id'],
                'name'       => (string) $r['name'],
                'username'   => (string) $r['username'],
                'phone'      => (string) ($r['phone'] ?? ''),
                'password'   => (string) $r['password_hash'],
                'status'     => (string) $r['status'],
                'created_at' => mysql_iso($r['created_at']),
            ];
        }

        foreach ($pdo->query('SELECT * FROM participants ORDER BY id') as $r) {
            $db['participants'][] = [
                'id'         => (int) $r['id'],
                'event_id'   => (int) $r['event_id'],
                'name'       => (string) $r['name'],
                'category'   => (string) ($r['category'] ?? ''),
                'song'       => (string) ($r['song'] ?? ''),
                // 'presentation_order' no schema, 'order' no JSON.
                'order'      => (int) $r['presentation_order'],
                'photo'      => (string) ($r['photo_url'] ?? ''),
                'status'     => (string) $r['status'],
                'created_at' => mysql_iso($r['created_at']),
            ];
        }

        foreach ($pdo->query('SELECT * FROM criteria ORDER BY id') as $r) {
            $db['criteria'][] = [
                'id'            => (int) $r['id'],
                'event_id'      => (int) $r['event_id'],
                'name'          => (string) $r['name'],
                'description'   => (string) ($r['description'] ?? ''),
                // DECIMAL volta como string do driver; a aplicacao espera numero.
                'weight'        => (float) $r['weight'],
                'display_order' => (int) $r['display_order'],
                'created_at'    => mysql_iso($r['created_at']),
            ];
        }

        foreach ($pdo->query('SELECT * FROM votes ORDER BY id') as $r) {
            $db['votes'][] = [
                'id'             => (int) $r['id'],
                'event_id'       => (int) $r['event_id'],
                'judge_id'       => (int) $r['judge_id'],
                'participant_id' => (int) $r['participant_id'],
                'criterion_id'   => (int) $r['criterion_id'],
                'score'          => (float) $r['score'],
                'created_at'     => mysql_iso($r['created_at']),
                'updated_at'     => mysql_iso($r['updated_at']),
            ];
        }

        // Observacoes e fichas nunca tiveram 'id' no JSON — mantido assim.
        foreach ($pdo->query('SELECT * FROM judge_observations ORDER BY id') as $r) {
            $db['observations'][] = [
                'event_id'       => (int) $r['event_id'],
                'judge_id'       => (int) $r['judge_id'],
                'participant_id' => (int) $r['participant_id'],
                'text'           => (string) ($r['observation'] ?? ''),
                'created_at'     => mysql_iso($r['created_at']),
                'updated_at'     => mysql_iso($r['updated_at']),
            ];
        }

        foreach ($pdo->query('SELECT * FROM judge_reviews ORDER BY id') as $r) {
            $modo = (string) $r['signature_mode'];
            $texto = (string) ($r['signature_text'] ?? '');

            $db['judge_reviews'][] = [
                'event_id'        => (int) $r['event_id'],
                'judge_id'        => (int) $r['judge_id'],
                'participant_id'  => (int) $r['participant_id'],
                // Campo derivado, reproduzido como o index.php sempre gravou.
                'signature'       => $modo === 'touch' ? 'Assinatura touch' : $texto,
                'signature_mode'  => $modo,
                'signature_text'  => $texto,
                'signature_touch' => (string) ($r['signature_touch'] ?? ''),
                'checklist_done'  => (bool) $r['checklist_done'],
                'created_at'      => mysql_iso($r['created_at']),
                'updated_at'      => mysql_iso($r['updated_at']),
            ];
        }

        return $db;
    } catch (Throwable $e) {
        error_log('MySQL ler_banco: ' . $e->getMessage());

        return null;
    }
}

/** Indexa uma tabela 1-para-1 por event_id. */
function mysql_indexar(PDO $pdo, string $tabela): array
{
    $saida = [];
    foreach ($pdo->query("SELECT * FROM `{$tabela}`") as $linha) {
        $saida[(int) $linha['event_id']] = $linha;
    }

    return $saida;
}

/** DATETIME do banco -> string ISO, formato que o JSON usa. */
function mysql_iso($valor): string
{
    if (!$valor) {
        return '';
    }

    $ts = strtotime((string) $valor);

    return $ts ? date('c', $ts) : '';
}

function mysql_contar(): array
{
    $pdo = mysql_conexao();
    if (!$pdo) {
        return [];
    }

    $saida = [];
    foreach (['admins', 'events', 'judges', 'participants', 'criteria',
              'votes', 'judge_observations', 'judge_reviews'] as $t) {
        try {
            $saida[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        } catch (Throwable $e) {
            $saida[$t] = -1;
        }
    }

    return $saida;
}
