<?php

/**
 * Apuração configurável por evento e votação do público.
 *
 * ---------------------------------------------------------------------------
 * O QUE ESTE ARQUIVO RESOLVE
 * ---------------------------------------------------------------------------
 * 1. COMO A NOTA FINAL É CALCULADA, evento a evento:
 *
 *      soma         todos os pontos de todos os jurados somados
 *      soma_media   cada jurado soma os critérios; o resultado é a média
 *                   desses totais entre os jurados (não depende de quantos
 *                   jurados avaliaram)
 *      media        média ponderada por jurado, promediada entre os
 *                   jurados — o cálculo de sempre, que os regulamentos do
 *                   Festival Folclórico pedem
 *
 *    Evento sem configuração continua em 'media': nenhum resultado já
 *    apurado muda sozinho. Evento criado daqui para frente nasce em 'soma'.
 *
 * 2. VOTAÇÃO DO PÚBLICO, opcional por evento: critérios próprios e um link
 *    aberto (?page=votar&t=...). O voto do público NÃO entra como nota: ele
 *    forma uma CLASSIFICAÇÃO do público, e cada colocação vale os pontos que
 *    o administrador definiu (ex.: 1º = 30, 2º = 20, 3º = 10). Esses pontos
 *    são somados aos dos jurados. Quem fica abaixo da última colocação
 *    pontuada, ou não recebe voto nenhum, ganha 0 do público.
 *
 *    A classificação do público pode ser por:
 *      soma    total de pontos que o público deu (mais votos e notas mais
 *              altas sobem) — o "mais votado"
 *      media   média das notas (cada pessoa pesa igual, não importa quantas
 *              votaram em cada participante)
 *
 *    Empate divide a colocação: dois empatados em 1º levam os pontos do 1º,
 *    e o seguinte é 3º — como no resultado final (lib/resultado.php).
 *
 * ---------------------------------------------------------------------------
 * UM VOTO POR APARELHO
 * ---------------------------------------------------------------------------
 * Cada cédula (um voto do público em UM participante) guarda:
 *   - dispositivo: um identificador aleatório gravado num cookie de um ano.
 *     Sempre conferido. Barra o voto repetido no mesmo navegador.
 *   - ip_trava: o IP (em hash) quando o evento restringe por IP. Barra o
 *     voto repetido de qualquer navegador na mesma conexão.
 * As duas travas são chaves únicas no banco: dois cliques simultâneos não
 * passam juntos.
 *
 * Restringir por IP tem custo: num teatro, centenas de pessoas no mesmo
 * Wi-Fi saem pelo MESMO IP, e só a primeira conseguiria votar. A tela de
 * configuração avisa.
 *
 * ---------------------------------------------------------------------------
 * ONDE OS DADOS FICAM
 * ---------------------------------------------------------------------------
 * Modo MySQL primário: tabelas de sql/mysql_17_voto_publico.sql.
 * Demais modos: data/voto_publico.json, com trava de arquivo.
 *
 * Nada aqui passa por db_write(): o voto do público é escrita dirigida, como
 * a nota do jurado. Centenas de pessoas votando ao mesmo tempo não podem
 * disputar a regravação do cadastro inteiro.
 */

declare(strict_types=1);

const VP_MODOS_CALCULO = [
    'soma'       => 'Soma de todos os pontos',
    'soma_media' => 'Soma dos critérios, média entre os jurados',
    'media'      => 'Média ponderada (cálculo antigo)',
];

const VP_COOKIE = 'festival_vp';

function vp_config_padrao(): array
{
    return [
        'modo_calculo'          => 'media',
        'publico_ativo'         => false,
        'publico_aberto'        => false,
        'publico_token'         => null,
        'publico_restringir_ip' => true,
        // Pontos por colocação no voto do público: [1º, 2º, 3º, ...]
        'publico_pontos'        => [],
        'publico_classificar'   => 'soma',
    ];
}

const VP_CLASSIFICAR = [
    'soma'  => 'Total de pontos recebidos (o mais votado)',
    'media' => 'Média das notas (cada pessoa pesa igual)',
];

/** "30,20,10" (banco) ⇄ [30.0, 20.0, 10.0]. Zeros à direita saem. */
function vp_pontos_de_texto(?string $texto): array
{
    $lista = [];
    foreach (explode(',', (string)$texto) as $parte) {
        $parte = trim($parte);
        if ($parte !== '' && is_numeric($parte)) {
            $lista[] = max(0.0, (float)$parte);
        }
    }

    while ($lista !== [] && end($lista) == 0.0) {
        array_pop($lista);
    }

    return $lista;
}

function vp_pontos_para_texto(array $lista): string
{
    return implode(',', array_map(static fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'), $lista));
}

/* ===========================================================================
 * ARMAZENAMENTO
 * ======================================================================== */

/** PDO quando o MySQL é a fonte da verdade; null = arquivo local. */
function vp_pdo(): ?PDO
{
    if (mysql_modo() !== 'primario') {
        return null;
    }

    return mysql_conexao();
}

/** As tabelas da migração 17 existem? */
function vp_tabelas_ok(): bool
{
    static $ok = null;

    if ($ok !== null) {
        return $ok;
    }

    $pdo = vp_pdo();
    if (!$pdo) {
        return $ok = mysql_modo() !== 'primario';
    }

    try {
        $n = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('evento_apuracao', 'publico_criterios', 'publico_cedulas', 'publico_notas')"
        )->fetchColumn();
        /* As duas colunas da pontuação por colocação entraram depois na mesma
           migração: um banco que rodou a versão anterior dela precisa rodar
           de novo (é segura de repetir). */
        $colunas = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_apuracao'
                AND COLUMN_NAME IN ('publico_pontos', 'publico_classificar')"
        )->fetchColumn();
        $ok = (int)$n === 4 && (int)$colunas === 2;
    } catch (Throwable $e) {
        error_log('vp_tabelas_ok: ' . $e->getMessage());
        $ok = false;
    }

    return $ok;
}

function vp_arquivo(): string
{
    return DATA_DIR . '/voto_publico.json';
}

function vp_arquivo_vazio(): array
{
    return ['config' => [], 'criterios' => [], 'cedulas' => [], 'seq' => ['criterios' => 0, 'cedulas' => 0]];
}

/** Leitura simples do arquivo (sem trava de escrita). */
function vp_arquivo_ler(): array
{
    $caminho = vp_arquivo();

    if (!is_file($caminho)) {
        return vp_arquivo_vazio();
    }

    $dados = json_decode((string)file_get_contents($caminho), true);

    return is_array($dados) ? $dados + vp_arquivo_vazio() : vp_arquivo_vazio();
}

/**
 * Lê, altera e grava o arquivo sob trava exclusiva. O callback recebe os
 * dados por referência e devolve o resultado da operação.
 */
function vp_arquivo_transacao(callable $operacao)
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    $fp = fopen(vp_arquivo(), 'c+');
    if (!$fp) {
        throw new RuntimeException('Não foi possível abrir ' . vp_arquivo());
    }

    try {
        flock($fp, LOCK_EX);
        $bruto = stream_get_contents($fp);
        $dados = json_decode((string)$bruto, true);
        $dados = is_array($dados) ? $dados + vp_arquivo_vazio() : vp_arquivo_vazio();

        $resultado = $operacao($dados);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);

        return $resultado;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/* ===========================================================================
 * CONFIGURAÇÃO DO EVENTO
 * ======================================================================== */

function vp_config(int $eventId): array
{
    return cache_lembrar('vp:config:' . $eventId, static function () use ($eventId): array {
        $padrao = vp_config_padrao();

        if (!vp_tabelas_ok()) {
            return $padrao;
        }

        $pdo = vp_pdo();

        if ($pdo) {
            try {
                $sql = $pdo->prepare('SELECT * FROM evento_apuracao WHERE event_id = ?');
                $sql->execute([$eventId]);
                $r = $sql->fetch();
            } catch (Throwable $e) {
                error_log('vp_config: ' . $e->getMessage());

                return $padrao;
            }

            if (!$r) {
                return $padrao;
            }

            return [
                'modo_calculo'          => isset(VP_MODOS_CALCULO[$r['modo_calculo']]) ? (string)$r['modo_calculo'] : 'media',
                'publico_ativo'         => (bool)$r['publico_ativo'],
                'publico_aberto'        => (bool)$r['publico_aberto'],
                'publico_token'         => $r['publico_token'] !== null ? (string)$r['publico_token'] : null,
                'publico_restringir_ip' => (bool)$r['publico_restringir_ip'],
                'publico_pontos'        => vp_pontos_de_texto($r['publico_pontos'] ?? ''),
                'publico_classificar'   => isset(VP_CLASSIFICAR[$r['publico_classificar'] ?? '']) ? (string)$r['publico_classificar'] : 'soma',
            ];
        }

        $guardado = vp_arquivo_ler()['config'][(string)$eventId] ?? [];

        return array_intersect_key($guardado, $padrao) + $padrao;
    });
}

/** Grava só as chaves informadas; o resto fica como estava. */
function vp_salvar_config(int $eventId, array $mudancas): bool
{
    if (!vp_tabelas_ok()) {
        return false;
    }

    $nova = array_intersect_key($mudancas, vp_config_padrao()) + vp_config($eventId);

    if (!isset(VP_MODOS_CALCULO[$nova['modo_calculo']])) {
        $nova['modo_calculo'] = 'media';
    }
    if (!isset(VP_CLASSIFICAR[$nova['publico_classificar']])) {
        $nova['publico_classificar'] = 'soma';
    }
    $nova['publico_pontos'] = vp_pontos_de_texto(vp_pontos_para_texto((array)$nova['publico_pontos']));

    $pdo = vp_pdo();

    try {
        if ($pdo) {
            $pdo->prepare(
                'INSERT INTO evento_apuracao
                    (event_id, modo_calculo, publico_ativo, publico_aberto, publico_token, publico_restringir_ip,
                     publico_pontos, publico_classificar)
                 VALUES (:id, :modo, :ativo, :aberto, :token, :ip, :pontos, :classificar)
                 ON DUPLICATE KEY UPDATE modo_calculo = VALUES(modo_calculo),
                     publico_ativo = VALUES(publico_ativo), publico_aberto = VALUES(publico_aberto),
                     publico_token = VALUES(publico_token),
                     publico_restringir_ip = VALUES(publico_restringir_ip),
                     publico_pontos = VALUES(publico_pontos),
                     publico_classificar = VALUES(publico_classificar)'
            )->execute([
                ':id'     => $eventId,
                ':modo'   => $nova['modo_calculo'],
                ':ativo'  => $nova['publico_ativo'] ? 1 : 0,
                ':aberto' => $nova['publico_aberto'] ? 1 : 0,
                ':token'  => $nova['publico_token'],
                ':ip'     => $nova['publico_restringir_ip'] ? 1 : 0,
                ':pontos' => vp_pontos_para_texto($nova['publico_pontos']),
                ':classificar' => $nova['publico_classificar'],
            ]);
        } else {
            vp_arquivo_transacao(static function (array &$d) use ($eventId, $nova): void {
                $d['config'][(string)$eventId] = $nova;
            });
        }
    } catch (Throwable $e) {
        error_log('vp_salvar_config: ' . $e->getMessage());

        return false;
    }

    cache_esquecer('vp');

    return true;
}

/** Token novo para o link do público. O antigo para de funcionar. */
function vp_novo_token(int $eventId): bool
{
    return vp_salvar_config($eventId, ['publico_token' => bin2hex(random_bytes(16))]);
}

function vp_evento_do_token(string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !vp_tabelas_ok()) {
        return null;
    }

    $pdo = vp_pdo();

    if ($pdo) {
        try {
            $sql = $pdo->prepare('SELECT event_id FROM evento_apuracao WHERE publico_token = ?');
            $sql->execute([$token]);
            $id = $sql->fetchColumn();

            return $id === false ? null : (int)$id;
        } catch (Throwable $e) {
            error_log('vp_evento_do_token: ' . $e->getMessage());

            return null;
        }
    }

    foreach (vp_arquivo_ler()['config'] as $eventId => $cfg) {
        if (($cfg['publico_token'] ?? null) !== null && hash_equals((string)$cfg['publico_token'], $token)) {
            return (int)$eventId;
        }
    }

    return null;
}

/** O público entra na nota final deste evento? */
function vp_publico_em_uso(int $eventId): bool
{
    return vp_config($eventId)['publico_ativo'];
}

/** Rótulo curto do número que ordena a classificação. */
function vp_rotulo_nota(int $eventId): string
{
    return vp_config($eventId)['modo_calculo'] === 'media' ? 'Média' : 'Pontos';
}

/* ===========================================================================
 * CRITÉRIOS DO PÚBLICO
 * ======================================================================== */

function vp_criterios(int $eventId): array
{
    return cache_lembrar('vp:criterios:' . $eventId, static function () use ($eventId): array {
        if (!vp_tabelas_ok()) {
            return [];
        }

        $pdo = vp_pdo();
        $lista = [];

        if ($pdo) {
            try {
                $sql = $pdo->prepare('SELECT * FROM publico_criterios WHERE event_id = ? ORDER BY ordem, id');
                $sql->execute([$eventId]);
                $lista = $sql->fetchAll();
            } catch (Throwable $e) {
                error_log('vp_criterios: ' . $e->getMessage());

                return [];
            }
        } else {
            foreach (vp_arquivo_ler()['criterios'] as $c) {
                if ((int)$c['event_id'] === $eventId) {
                    $lista[] = $c;
                }
            }
            usort($lista, static fn($a, $b) => ((int)$a['ordem'] <=> (int)$b['ordem']) ?: ((int)$a['id'] <=> (int)$b['id']));
        }

        return array_map(static fn(array $c): array => [
            'id'          => (int)$c['id'],
            'event_id'    => (int)$c['event_id'],
            'nome'        => (string)$c['nome'],
            'descricao'   => (string)($c['descricao'] ?? ''),
            'nota_minima' => (float)$c['nota_minima'],
            'nota_maxima' => (float)$c['nota_maxima'],
            'passo'       => (float)$c['passo'],
            'ordem'       => (int)$c['ordem'],
        ], $lista);
    });
}

/**
 * Cria (id null) ou altera um critério do público.
 *
 * @return string|null mensagem de erro, ou null se gravou
 */
function vp_criterio_gravar(int $eventId, ?int $id, array $c): ?string
{
    if (!vp_tabelas_ok()) {
        return 'O banco ainda não tem as tabelas da votação do público. Aplique sql/mysql_17_voto_publico.sql.';
    }

    $nome = trim((string)($c['nome'] ?? ''));
    if ($nome === '') {
        return 'Dê um nome ao critério.';
    }

    $erro = criterio_faixa_erro((float)$c['nota_minima'], (float)$c['nota_maxima'], (float)$c['passo']);
    if ($erro !== null) {
        return $erro;
    }

    $linha = [
        'event_id'    => $eventId,
        'nome'        => mb_substr($nome, 0, 120),
        'descricao'   => mb_substr(trim((string)($c['descricao'] ?? '')), 0, 255),
        'nota_minima' => (float)$c['nota_minima'],
        'nota_maxima' => (float)$c['nota_maxima'],
        'passo'       => (float)$c['passo'],
        'ordem'       => (int)($c['ordem'] ?? 0),
    ];

    $pdo = vp_pdo();

    try {
        if ($pdo) {
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO publico_criterios (event_id, nome, descricao, nota_minima, nota_maxima, passo, ordem)
                     VALUES (:event_id, :nome, :descricao, :nota_minima, :nota_maxima, :passo, :ordem)'
                )->execute($linha);
            } else {
                $pdo->prepare(
                    'UPDATE publico_criterios SET nome = :nome, descricao = :descricao,
                            nota_minima = :nota_minima, nota_maxima = :nota_maxima,
                            passo = :passo, ordem = :ordem
                      WHERE id = :id AND event_id = :event_id'
                )->execute($linha + ['id' => $id]);
            }
        } else {
            vp_arquivo_transacao(static function (array &$d) use ($id, $linha): void {
                if ($id === null) {
                    $d['seq']['criterios'] = (int)($d['seq']['criterios'] ?? 0) + 1;
                    $d['criterios'][] = ['id' => $d['seq']['criterios']] + $linha;

                    return;
                }

                foreach ($d['criterios'] as $i => $c) {
                    if ((int)$c['id'] === $id && (int)$c['event_id'] === $linha['event_id']) {
                        $d['criterios'][$i] = ['id' => $id] + $linha;
                    }
                }
            });
        }
    } catch (Throwable $e) {
        error_log('vp_criterio_gravar: ' . $e->getMessage());

        return 'Falha ao gravar o critério.';
    }

    cache_esquecer('vp');

    return null;
}

function vp_criterio_excluir(int $eventId, int $id): bool
{
    if (!vp_tabelas_ok()) {
        return false;
    }

    $pdo = vp_pdo();

    try {
        if ($pdo) {
            // As notas deste critério saem junto (ON DELETE CASCADE).
            $pdo->prepare('DELETE FROM publico_criterios WHERE id = ? AND event_id = ?')->execute([$id, $eventId]);
        } else {
            vp_arquivo_transacao(static function (array &$d) use ($eventId, $id): void {
                $d['criterios'] = array_values(array_filter(
                    $d['criterios'],
                    static fn($c) => !((int)$c['id'] === $id && (int)$c['event_id'] === $eventId)
                ));
                foreach ($d['cedulas'] as $i => $c) {
                    unset($d['cedulas'][$i]['notas'][(string)$id]);
                }
            });
        }
    } catch (Throwable $e) {
        error_log('vp_criterio_excluir: ' . $e->getMessage());

        return false;
    }

    cache_esquecer('vp');

    return true;
}

/* ===========================================================================
 * VOTO
 * ======================================================================== */

/** Identificador do navegador, num cookie de um ano. Cria se não houver. */
function vp_dispositivo(): string
{
    $atual = (string)($_COOKIE[VP_COOKIE] ?? '');

    if (preg_match('/^[a-f0-9]{32}$/', $atual)) {
        return $atual;
    }

    $novo = bin2hex(random_bytes(16));

    if (!headers_sent()) {
        setcookie(VP_COOKIE, $novo, [
            'expires'  => time() + 365 * 86400,
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $_COOKIE[VP_COOKIE] = $novo;

    return $novo;
}

/**
 * Hash do IP. O IP em si não é guardado: para travar o voto repetido basta
 * saber se é o mesmo, não qual é. A chave fica em data/ (fora do git e fora
 * da web), para que o hash não possa ser revertido testando os IPs um a um.
 */
function vp_hash_ip(int $eventId, string $ip): string
{
    $arquivo = DATA_DIR . '/voto_publico.chave';

    if (!is_file($arquivo)) {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0775, true);
        }
        @file_put_contents($arquivo, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($arquivo, 0600);
    }

    $chave = trim((string)@file_get_contents($arquivo));

    return hash_hmac('sha256', $eventId . '|' . $ip, $chave !== '' ? $chave : 'festival');
}

/**
 * Participantes em que este aparelho (ou este IP, se o evento restringe) já
 * votou.
 *
 * @return array<int,true>
 */
function vp_ja_votados(int $eventId, string $dispositivo, string $ipHash): array
{
    if (!vp_tabelas_ok()) {
        return [];
    }

    $restringirIp = vp_config($eventId)['publico_restringir_ip'];
    $pdo = vp_pdo();
    $saida = [];

    if ($pdo) {
        try {
            $sql = $pdo->prepare(
                'SELECT DISTINCT participant_id FROM publico_cedulas
                  WHERE event_id = :e AND (dispositivo = :d OR (:restringe = 1 AND ip_trava = :ip))'
            );
            $sql->execute([':e' => $eventId, ':d' => $dispositivo, ':restringe' => $restringirIp ? 1 : 0, ':ip' => $ipHash]);
            foreach ($sql->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $saida[(int)$pid] = true;
            }
        } catch (Throwable $e) {
            error_log('vp_ja_votados: ' . $e->getMessage());
        }

        return $saida;
    }

    foreach (vp_arquivo_ler()['cedulas'] as $c) {
        if ((int)$c['event_id'] !== $eventId) {
            continue;
        }
        if ($c['dispositivo'] === $dispositivo || ($restringirIp && ($c['ip_trava'] ?? null) === $ipHash)) {
            $saida[(int)$c['participant_id']] = true;
        }
    }

    return $saida;
}

/**
 * Registra o voto do público em UM participante.
 *
 * @param array<int|string,mixed> $notasBrutas [criterio_id => nota digitada]
 * @return array{ok:bool, mensagem:string}
 */
function vp_votar(int $eventId, int $participantId, array $notasBrutas, string $dispositivo, string $ipHash): array
{
    $cfg = vp_config($eventId);

    if (!$cfg['publico_ativo'] || !$cfg['publico_aberto']) {
        return ['ok' => false, 'mensagem' => 'A votação do público está encerrada.'];
    }

    $criterios = vp_criterios($eventId);
    if ($criterios === []) {
        return ['ok' => false, 'mensagem' => 'A votação ainda não tem critérios.'];
    }

    /* Todos os critérios são obrigatórios e conferidos na faixa de cada um:
       o formulário pode ser contornado; o que vale é o que entra no banco. */
    $notas = [];
    foreach ($criterios as $c) {
        $bruto = trim(str_replace(',', '.', (string)($notasBrutas[$c['id']] ?? '')));

        if ($bruto === '' || !is_numeric($bruto)) {
            return ['ok' => false, 'mensagem' => 'Dê uma nota em "' . $c['nome'] . '".'];
        }

        $nota = (float)$bruto;
        $folga = max($c['passo'], 0.01) / 2;

        if ($nota < $c['nota_minima'] - $folga || $nota > $c['nota_maxima'] + $folga) {
            return ['ok' => false, 'mensagem' => 'A nota de "' . $c['nome'] . '" vai de '
                . numero_pt($c['nota_minima']) . ' a ' . numero_pt($c['nota_maxima']) . '.'];
        }

        $notas[$c['id']] = round($nota, 2);
    }

    $ipTrava = $cfg['publico_restringir_ip'] ? $ipHash : null;
    $jaVotou = ['ok' => false, 'mensagem' => 'Já existe um voto deste aparelho (ou desta rede) para este participante. Obrigado!'];
    $pdo = vp_pdo();

    try {
        if ($pdo) {
            $pdo->beginTransaction();

            try {
                $pdo->prepare(
                    'INSERT INTO publico_cedulas (event_id, participant_id, dispositivo, ip_hash, ip_trava)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$eventId, $participantId, $dispositivo, $ipHash, $ipTrava]);
            } catch (PDOException $e) {
                $pdo->rollBack();

                // 1062 = chave única: este aparelho/IP já votou neste participante.
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    return $jaVotou;
                }

                throw $e;
            }

            $cedula = (int)$pdo->lastInsertId();
            $sql = $pdo->prepare('INSERT INTO publico_notas (cedula_id, criterio_id, score) VALUES (?, ?, ?)');
            foreach ($notas as $criterioId => $nota) {
                $sql->execute([$cedula, $criterioId, $nota]);
            }

            $pdo->commit();
        } else {
            $gravou = vp_arquivo_transacao(static function (array &$d) use ($eventId, $participantId, $dispositivo, $ipHash, $ipTrava, $notas): bool {
                foreach ($d['cedulas'] as $c) {
                    if ((int)$c['event_id'] === $eventId && (int)$c['participant_id'] === $participantId
                        && ($c['dispositivo'] === $dispositivo || ($ipTrava !== null && ($c['ip_trava'] ?? null) === $ipTrava))) {
                        return false;
                    }
                }

                $d['seq']['cedulas'] = (int)($d['seq']['cedulas'] ?? 0) + 1;
                $d['cedulas'][] = [
                    'id'             => $d['seq']['cedulas'],
                    'event_id'       => $eventId,
                    'participant_id' => $participantId,
                    'dispositivo'    => $dispositivo,
                    'ip_hash'        => $ipHash,
                    'ip_trava'       => $ipTrava,
                    'criado'         => date('c'),
                    'notas'          => array_combine(array_map('strval', array_keys($notas)), array_values($notas)),
                ];

                return true;
            });

            if (!$gravou) {
                return $jaVotou;
            }
        }
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('vp_votar: ' . $e->getMessage());

        return ['ok' => false, 'mensagem' => 'Não foi possível registrar o voto agora. Tente de novo.'];
    }

    cache_esquecer('vp');

    return ['ok' => true, 'mensagem' => 'Voto registrado. Obrigado!'];
}

/** Apaga todos os votos do público do evento (critérios e link ficam). */
function vp_zerar_votos(int $eventId): bool
{
    if (!vp_tabelas_ok()) {
        return false;
    }

    $pdo = vp_pdo();

    try {
        if ($pdo) {
            $pdo->prepare('DELETE FROM publico_cedulas WHERE event_id = ?')->execute([$eventId]);
        } else {
            vp_arquivo_transacao(static function (array &$d) use ($eventId): void {
                $d['cedulas'] = array_values(array_filter($d['cedulas'], static fn($c) => (int)$c['event_id'] !== $eventId));
            });
        }
    } catch (Throwable $e) {
        error_log('vp_zerar_votos: ' . $e->getMessage());

        return false;
    }

    cache_esquecer('vp');

    return true;
}

/* ===========================================================================
 * APURAÇÃO DO PÚBLICO
 * ======================================================================== */

/**
 * Por participante: quantos votaram, a média de cada critério, a colocação
 * no voto do público e os pontos que essa colocação vale.
 *
 * @return array<int, array{votos:int, medias:array<int,float>, total:float,
 *                          media:float, metrica:float, posicao:int, pontos:float}>
 */
function vp_resultado(int $eventId): array
{
    return cache_lembrar('vp:resultado:' . $eventId, static function () use ($eventId): array {
        $criterios = vp_criterios($eventId);
        if ($criterios === []) {
            return [];
        }

        $validos = array_flip(array_column($criterios, 'id'));
        $somas = [];     // [pid][cid] => soma
        $contas = [];    // [pid][cid] => quantas notas
        $votos = [];     // [pid] => cédulas
        $pdo = vp_pdo();

        if ($pdo) {
            try {
                $sql = $pdo->prepare(
                    'SELECT c.participant_id, n.criterio_id, SUM(n.score) AS soma, COUNT(*) AS qtd
                       FROM publico_cedulas c
                       JOIN publico_notas n ON n.cedula_id = c.id
                      WHERE c.event_id = ?
                      GROUP BY c.participant_id, n.criterio_id'
                );
                $sql->execute([$eventId]);
                foreach ($sql->fetchAll() as $r) {
                    $somas[(int)$r['participant_id']][(int)$r['criterio_id']] = (float)$r['soma'];
                    $contas[(int)$r['participant_id']][(int)$r['criterio_id']] = (int)$r['qtd'];
                }

                $sql = $pdo->prepare('SELECT participant_id, COUNT(*) FROM publico_cedulas WHERE event_id = ? GROUP BY participant_id');
                $sql->execute([$eventId]);
                foreach ($sql->fetchAll(PDO::FETCH_NUM) as [$pid, $qtd]) {
                    $votos[(int)$pid] = (int)$qtd;
                }
            } catch (Throwable $e) {
                error_log('vp_resultado: ' . $e->getMessage());

                return [];
            }
        } else {
            foreach (vp_arquivo_ler()['cedulas'] as $c) {
                if ((int)$c['event_id'] !== $eventId) {
                    continue;
                }
                $pid = (int)$c['participant_id'];
                $votos[$pid] = ($votos[$pid] ?? 0) + 1;
                foreach (($c['notas'] ?? []) as $cid => $nota) {
                    $somas[$pid][(int)$cid] = ($somas[$pid][(int)$cid] ?? 0.0) + (float)$nota;
                    $contas[$pid][(int)$cid] = ($contas[$pid][(int)$cid] ?? 0) + 1;
                }
            }
        }

        /* Só conta quem ainda é participante ativo do evento: no modo arquivo
           não há chave estrangeira, e um participante excluído não pode
           ocupar uma colocação. */
        $ativos = null;
        if (function_exists('db_read')) {
            $ativos = [];
            foreach (db_read()['participants'] ?? [] as $p) {
                if ((int)$p['event_id'] === $eventId && ($p['status'] ?? 'ativo') !== 'inativo') {
                    $ativos[(int)$p['id']] = true;
                }
            }
        }

        $cfg = vp_config($eventId);
        $saida = [];
        foreach ($votos as $pid => $qtd) {
            if ($ativos !== null && !isset($ativos[$pid])) {
                continue;
            }

            $medias = [];
            $total = 0.0;
            foreach ($somas[$pid] ?? [] as $cid => $soma) {
                if (isset($validos[$cid]) && ($contas[$pid][$cid] ?? 0) > 0) {
                    $medias[$cid] = $soma / $contas[$pid][$cid];
                    $total += $soma;
                }
            }

            $saida[$pid] = [
                'votos'    => $qtd,
                'medias'   => $medias,
                'total'    => $total,              // soma de tudo que o público deu
                'media'    => array_sum($medias),  // soma das médias por critério
                'metrica'  => $cfg['publico_classificar'] === 'media' ? array_sum($medias) : $total,
                'posicao'  => 0,
                'pontos'   => 0.0,
            ];
        }

        /* Classificação do público e os pontos de cada colocação. Empate
           divide a colocação; o seguinte pula as posições ocupadas. */
        uasort($saida, static fn($a, $b) => $b['metrica'] <=> $a['metrica']);
        $posicao = 0;
        $iguais = 0;
        $anterior = null;
        foreach ($saida as $pid => $linha) {
            if ($anterior !== null && abs($linha['metrica'] - $anterior) < 0.005) {
                $iguais++;
            } else {
                $posicao += 1 + $iguais;
                $iguais = 0;
            }
            $anterior = $linha['metrica'];

            $saida[$pid]['posicao'] = $posicao;
            $saida[$pid]['pontos'] = (float)($cfg['publico_pontos'][$posicao - 1] ?? 0.0);
        }

        return $saida;
    });
}

/* ===========================================================================
 * IMAGEM DE CAPA DA VOTAÇÃO
 *
 * Uma imagem por evento, no topo da página do público. Fica como arquivo em
 * public/uploads/votacao/ — pasta que a publicação automática não toca e o
 * festival-backup já copia — com nome fixo por evento. Por isso não precisa
 * de coluna no banco: o arquivo existir é a configuração.
 * ======================================================================== */

const VP_CAPA_DIR = __DIR__ . '/../public/uploads/votacao';
const VP_CAPA_LARGURA_MAX = 1800;
const VP_CAPA_TIPOS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

/** Caminho relativo da capa do evento (com ?v= para trocar sem cache), ou null. */
function vp_capa_url(int $eventId): ?string
{
    foreach (VP_CAPA_TIPOS as $ext) {
        $arquivo = VP_CAPA_DIR . '/capa-evento-' . $eventId . '.' . $ext;
        if (is_file($arquivo)) {
            return 'public/uploads/votacao/capa-evento-' . $eventId . '.' . $ext . '?v=' . filemtime($arquivo);
        }
    }

    return null;
}

/**
 * Grava a capa enviada no campo $campo.
 *
 * O tipo vem do cabeçalho binário (getimagesize), nunca do nome do arquivo.
 * A imagem é sempre regravada pelo GD: isso tira metadados (EXIF com GPS do
 * celular de quem fotografou) e reduz imagens grandes para no máximo
 * 1800 px de largura — quem abre o link está no 4G da plateia.
 *
 * @return string|null mensagem de erro, ou null se gravou
 */
function vp_capa_salvar(int $eventId, string $campo = 'capa'): ?string
{
    $f = $_FILES[$campo] ?? null;

    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Escolha uma imagem.';
    }
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return 'O envio da imagem falhou. Tente de novo (até 8 MB).';
    }
    if (($f['size'] ?? 0) > 8 * 1024 * 1024) {
        return 'A imagem passa de 8 MB. Reduza e envie de novo.';
    }

    $info = @getimagesize($f['tmp_name']);
    if ($info === false || !isset(VP_CAPA_TIPOS[$info[2]])) {
        return 'Envie uma imagem JPG, PNG ou WEBP.';
    }

    $origem = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($f['tmp_name']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false,
        default        => false,
    };
    if (!$origem) {
        return 'Não foi possível ler a imagem. Tente salvar como JPG e envie de novo.';
    }

    [$largura, $altura] = [imagesx($origem), imagesy($origem)];
    if ($largura > VP_CAPA_LARGURA_MAX) {
        $novaAltura = (int)round($altura * VP_CAPA_LARGURA_MAX / $largura);
        $reduzida = imagecreatetruecolor(VP_CAPA_LARGURA_MAX, $novaAltura);
        imagealphablending($reduzida, false);
        imagesavealpha($reduzida, true);
        imagecopyresampled($reduzida, $origem, 0, 0, 0, 0, VP_CAPA_LARGURA_MAX, $novaAltura, $largura, $altura);
        imagedestroy($origem);
        $origem = $reduzida;
    }

    if (!is_dir(VP_CAPA_DIR)) {
        mkdir(VP_CAPA_DIR, 0775, true);
    }

    // PNG continua PNG (pode ter transparência); o resto vira JPG.
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $destino = VP_CAPA_DIR . '/capa-evento-' . $eventId . '.' . $ext;
    $ok = $ext === 'png' ? imagepng($origem, $destino, 7) : imagejpeg($origem, $destino, 84);
    imagedestroy($origem);

    if (!$ok) {
        return 'Não foi possível gravar a imagem no servidor.';
    }
    @chmod($destino, 0644);

    foreach (VP_CAPA_TIPOS as $outra) {
        if ($outra !== $ext) {
            @unlink(VP_CAPA_DIR . '/capa-evento-' . $eventId . '.' . $outra);
        }
    }

    return null;
}

function vp_capa_remover(int $eventId): void
{
    foreach (VP_CAPA_TIPOS as $ext) {
        @unlink(VP_CAPA_DIR . '/capa-evento-' . $eventId . '.' . $ext);
    }
}
