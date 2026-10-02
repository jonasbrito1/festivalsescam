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
 *    O padrão é 'soma', inclusive para evento sem configuração gravada.
 *    'media' só vale onde o administrador escolher (regulamento que exija).
 *
 * 2. VOTAÇÃO DO PÚBLICO, opcional por evento, por um link aberto
 *    (?page=votar&t=...). Cada pessoa escolhe UM participante — cada escolha
 *    é um voto. Os votos formam o RANKING DO PÚBLICO, e cada colocação vale
 *    os pontos que o administrador definiu (ex.: 1º = 10, 2º = 5, 3º = 2),
 *    somados aos dos jurados. Quem fica abaixo da última colocação pontuada,
 *    ou não recebe voto nenhum, ganha 0 do público.
 *
 *    Empate em número de votos divide a colocação: dois empatados em 1º
 *    levam os pontos do 1º, e o seguinte é 3º — como no resultado final
 *    (lib/resultado.php).
 *
 * ---------------------------------------------------------------------------
 * UM VOTO POR APARELHO
 * ---------------------------------------------------------------------------
 * Cada cédula (o voto de uma pessoa no evento) guarda:
 *   - dispositivo: um identificador aleatório gravado num cookie de um ano.
 *     Sempre conferido. Barra o segundo voto no mesmo navegador.
 *   - ip_trava: o IP (em hash) quando o evento restringe por IP. Barra o
 *     segundo voto de qualquer navegador na mesma conexão.
 * A partir da migração 18 as duas travas são chaves únicas por evento no
 * banco: dois toques simultâneos não passam juntos.
 *
 * Restringir por IP tem custo: num teatro, centenas de pessoas no mesmo
 * Wi-Fi saem pelo MESMO IP, e só a primeira conseguiria votar. A tela de
 * configuração avisa.
 *
 * ---------------------------------------------------------------------------
 * ONDE OS DADOS FICAM
 * ---------------------------------------------------------------------------
 * Modo MySQL primário: tabelas de sql/mysql_17 e sql/mysql_18.
 * Demais modos: data/voto_publico.json, com trava de arquivo.
 * Imagens (capa e fundo): public/uploads/votacao/, uma de cada por evento.
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

/** Aparência padrão da página do público: as cores do Sesc. */
const VP_APARENCIA_PADRAO = [
    'pergunta'     => 'Qual foi a melhor apresentação?',
    'cor_fundo'    => '#001a52',
    'cor_destaque' => '#ffc400',
    // Quanto a cor de fundo cobre a imagem de fundo (0 = imagem pura, 90 = quase só a cor).
    'sobreposicao' => 65,
];

function vp_config_padrao(): array
{
    return [
        /* Soma é o padrão: notas dos jurados somadas, mais os pontos do
           público. 'media' continua disponível por evento, para regulamento
           que exija (Configurações do evento > Cálculo da nota). */
        'modo_calculo'          => 'soma',
        'publico_ativo'         => false,
        'publico_aberto'        => false,
        'publico_token'         => null,
        'publico_restringir_ip' => false,
        // Pontos por colocação no ranking do público: [1º, 2º, 3º, ...]
        'publico_pontos'        => [],
        'publico_aparencia'     => VP_APARENCIA_PADRAO,
    ];
}

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

/** Aparência validada: cor só em #rrggbb, pergunta curta, sobreposição 0–90. */
function vp_aparencia_normalizar($dados): array
{
    $dados = is_array($dados) ? $dados : [];
    $cor = static fn($v, string $padrao): string => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $padrao;
    $pergunta = trim((string)($dados['pergunta'] ?? ''));

    return [
        'pergunta'     => $pergunta !== '' ? mb_substr($pergunta, 0, 160) : VP_APARENCIA_PADRAO['pergunta'],
        'cor_fundo'    => $cor($dados['cor_fundo'] ?? null, VP_APARENCIA_PADRAO['cor_fundo']),
        'cor_destaque' => $cor($dados['cor_destaque'] ?? null, VP_APARENCIA_PADRAO['cor_destaque']),
        'sobreposicao' => max(0, min(90, (int)($dados['sobreposicao'] ?? VP_APARENCIA_PADRAO['sobreposicao']))),
    ];
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
                AND TABLE_NAME IN ('evento_apuracao', 'publico_cedulas')"
        )->fetchColumn();
        $colunas = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_apuracao'
                AND COLUMN_NAME = 'publico_pontos'"
        )->fetchColumn();
        $ok = (int)$n === 2 && (int)$colunas === 1;
    } catch (Throwable $e) {
        error_log('vp_tabelas_ok: ' . $e->getMessage());
        $ok = false;
    }

    return $ok;
}

/**
 * A migração 18 (aparência da página) já foi aplicada?
 *
 * Separada da checagem acima de propósito: sem a 18, o cálculo da nota e a
 * votação continuam funcionando; só a aparência personalizada não é gravada.
 */
function vp_aparencia_ok(): bool
{
    static $ok = null;

    if ($ok !== null) {
        return $ok;
    }

    $pdo = vp_pdo();
    if (!$pdo) {
        return $ok = vp_tabelas_ok();
    }

    try {
        $ok = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_apuracao'
                AND COLUMN_NAME = 'publico_aparencia'"
        )->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('vp_aparencia_ok: ' . $e->getMessage());
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
    return ['config' => [], 'cedulas' => [], 'seq' => ['cedulas' => 0]];
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
                'modo_calculo'          => isset(VP_MODOS_CALCULO[$r['modo_calculo']]) ? (string)$r['modo_calculo'] : 'soma',
                'publico_ativo'         => (bool)$r['publico_ativo'],
                'publico_aberto'        => (bool)$r['publico_aberto'],
                'publico_token'         => $r['publico_token'] !== null ? (string)$r['publico_token'] : null,
                'publico_restringir_ip' => (bool)$r['publico_restringir_ip'],
                'publico_pontos'        => vp_pontos_de_texto($r['publico_pontos'] ?? ''),
                'publico_aparencia'     => vp_aparencia_normalizar(json_decode((string)($r['publico_aparencia'] ?? ''), true)),
            ];
        }

        $guardado = vp_arquivo_ler()['config'][(string)$eventId] ?? [];
        $cfg = array_intersect_key($guardado, $padrao) + $padrao;
        $cfg['publico_aparencia'] = vp_aparencia_normalizar($cfg['publico_aparencia']);

        return $cfg;
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
        $nova['modo_calculo'] = 'soma';
    }
    $nova['publico_pontos'] = vp_pontos_de_texto(vp_pontos_para_texto((array)$nova['publico_pontos']));
    $nova['publico_aparencia'] = vp_aparencia_normalizar($nova['publico_aparencia']);

    $pdo = vp_pdo();

    try {
        if ($pdo) {
            $comAparencia = vp_aparencia_ok();
            $params = [
                ':id'     => $eventId,
                ':modo'   => $nova['modo_calculo'],
                ':ativo'  => $nova['publico_ativo'] ? 1 : 0,
                ':aberto' => $nova['publico_aberto'] ? 1 : 0,
                ':token'  => $nova['publico_token'],
                ':ip'     => $nova['publico_restringir_ip'] ? 1 : 0,
                ':pontos' => vp_pontos_para_texto($nova['publico_pontos']),
            ];
            if ($comAparencia) {
                $params[':aparencia'] = json_encode($nova['publico_aparencia'], JSON_UNESCAPED_UNICODE);
            }

            $pdo->prepare(
                'INSERT INTO evento_apuracao
                    (event_id, modo_calculo, publico_ativo, publico_aberto, publico_token, publico_restringir_ip,
                     publico_pontos' . ($comAparencia ? ', publico_aparencia' : '') . ')
                 VALUES (:id, :modo, :ativo, :aberto, :token, :ip, :pontos' . ($comAparencia ? ', :aparencia' : '') . ')
                 ON DUPLICATE KEY UPDATE modo_calculo = VALUES(modo_calculo),
                     publico_ativo = VALUES(publico_ativo), publico_aberto = VALUES(publico_aberto),
                     publico_token = VALUES(publico_token),
                     publico_restringir_ip = VALUES(publico_restringir_ip),
                     publico_pontos = VALUES(publico_pontos)'
                     . ($comAparencia ? ', publico_aparencia = VALUES(publico_aparencia)' : '')
            )->execute($params);
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
 * Em quem este aparelho (ou este IP, se o evento restringe) já votou neste
 * evento. null = ainda não votou.
 */
function vp_voto_do_aparelho(int $eventId, string $dispositivo, string $ipHash): ?int
{
    if (!vp_tabelas_ok()) {
        return null;
    }

    $restringirIp = vp_config($eventId)['publico_restringir_ip'];
    $pdo = vp_pdo();

    if ($pdo) {
        try {
            $sql = $pdo->prepare(
                'SELECT participant_id FROM publico_cedulas
                  WHERE event_id = :e AND (dispositivo = :d OR (:restringe = 1 AND ip_trava = :ip))
                  ORDER BY id LIMIT 1'
            );
            $sql->execute([':e' => $eventId, ':d' => $dispositivo, ':restringe' => $restringirIp ? 1 : 0, ':ip' => $ipHash]);
            $pid = $sql->fetchColumn();

            return $pid === false ? null : (int)$pid;
        } catch (Throwable $e) {
            error_log('vp_voto_do_aparelho: ' . $e->getMessage());

            return null;
        }
    }

    foreach (vp_arquivo_ler()['cedulas'] as $c) {
        if ((int)$c['event_id'] === $eventId
            && ($c['dispositivo'] === $dispositivo || ($restringirIp && ($c['ip_trava'] ?? null) === $ipHash))) {
            return (int)$c['participant_id'];
        }
    }

    return null;
}

/**
 * Registra o voto de uma pessoa: UM participante por evento.
 *
 * @return array{ok:bool, mensagem:string}
 */
function vp_votar(int $eventId, int $participantId, string $dispositivo, string $ipHash): array
{
    $cfg = vp_config($eventId);

    if (!$cfg['publico_ativo'] || !$cfg['publico_aberto']) {
        return ['ok' => false, 'mensagem' => 'A votação do público está fechada.'];
    }

    $ipTrava = $cfg['publico_restringir_ip'] ? $ipHash : null;
    $jaVotou = ['ok' => false, 'mensagem' => 'Este celular já votou neste evento. Obrigado!'];
    $pdo = vp_pdo();

    try {
        if ($pdo) {
            $pdo->beginTransaction();

            /* Antes da migração 18 não há chave única por evento; esta leitura
               com trava é o que segura o segundo voto. Depois dela, a chave
               única é a garantia, e esta leitura só poupa um erro. */
            $sql = $pdo->prepare(
                'SELECT id FROM publico_cedulas
                  WHERE event_id = :e AND (dispositivo = :d OR (:restringe = 1 AND ip_trava = :ip))
                  LIMIT 1 FOR UPDATE'
            );
            $sql->execute([':e' => $eventId, ':d' => $dispositivo, ':restringe' => $ipTrava !== null ? 1 : 0, ':ip' => (string)$ipTrava]);
            if ($sql->fetchColumn() !== false) {
                $pdo->rollBack();

                return $jaVotou;
            }

            try {
                $pdo->prepare(
                    'INSERT INTO publico_cedulas (event_id, participant_id, dispositivo, ip_hash, ip_trava)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$eventId, $participantId, $dispositivo, $ipHash, $ipTrava]);
            } catch (PDOException $e) {
                $pdo->rollBack();

                // 1062 = chave única: este aparelho/IP já votou.
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    return $jaVotou;
                }

                throw $e;
            }

            $pdo->commit();
        } else {
            $gravou = vp_arquivo_transacao(static function (array &$d) use ($eventId, $participantId, $dispositivo, $ipHash, $ipTrava): bool {
                foreach ($d['cedulas'] as $c) {
                    if ((int)$c['event_id'] === $eventId
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

/** Apaga todos os votos do público do evento (configuração e link ficam). */
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
 * RANKING DO PÚBLICO
 * ======================================================================== */

/**
 * Por participante: votos recebidos, a colocação no ranking do público e os
 * pontos que essa colocação vale. Só entra quem recebeu ao menos um voto.
 *
 * @return array<int, array{votos:int, percentual:float, posicao:int, pontos:float}>
 */
function vp_resultado(int $eventId): array
{
    return cache_lembrar('vp:resultado:' . $eventId, static function () use ($eventId): array {
        if (!vp_tabelas_ok()) {
            return [];
        }

        $votos = [];
        $pdo = vp_pdo();

        if ($pdo) {
            try {
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
                if ((int)$c['event_id'] === $eventId) {
                    $pid = (int)$c['participant_id'];
                    $votos[$pid] = ($votos[$pid] ?? 0) + 1;
                }
            }
        }

        /* Só conta quem ainda é participante ativo do evento: no modo arquivo
           não há chave estrangeira, e um participante excluído não pode
           ocupar uma colocação. */
        if (function_exists('db_read')) {
            $ativos = [];
            foreach (db_read()['participants'] ?? [] as $p) {
                if ((int)$p['event_id'] === $eventId && ($p['status'] ?? 'ativo') !== 'inativo') {
                    $ativos[(int)$p['id']] = true;
                }
            }
            $votos = array_intersect_key($votos, $ativos);
        }

        arsort($votos);
        $total = array_sum($votos);
        $pontos = vp_config($eventId)['publico_pontos'];

        $saida = [];
        $posicao = 0;
        $iguais = 0;
        $anterior = null;
        foreach ($votos as $pid => $qtd) {
            if ($anterior !== null && $qtd === $anterior) {
                $iguais++;
            } else {
                $posicao += 1 + $iguais;
                $iguais = 0;
            }
            $anterior = $qtd;

            $saida[$pid] = [
                'votos'      => $qtd,
                'percentual' => $total > 0 ? $qtd / $total * 100 : 0.0,
                'posicao'    => $posicao,
                'pontos'     => (float)($pontos[$posicao - 1] ?? 0.0),
            ];
        }

        return $saida;
    });
}

/* ===========================================================================
 * IMAGENS DA PÁGINA DO PÚBLICO: CAPA E FUNDO
 *
 * Uma de cada por evento, em public/uploads/votacao/ — pasta que a publicação
 * automática não toca e o festival-backup já copia — com nome fixo por
 * evento. Por isso não precisa de coluna no banco: o arquivo existir é a
 * configuração.
 * ======================================================================== */

const VP_IMG_DIR = __DIR__ . '/../public/uploads/votacao';
const VP_IMG_TIPOS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

/** capa = arte no topo da página; fundo = imagem atrás de tudo. */
const VP_IMG_USOS = [
    'capa'  => ['prefixo' => 'capa-evento-',  'largura' => 1800],
    'fundo' => ['prefixo' => 'fundo-evento-', 'largura' => 1600],
];

/** Caminho relativo da imagem (com ?v= para trocar sem cache), ou null. */
function vp_imagem_url(int $eventId, string $uso): ?string
{
    $prefixo = VP_IMG_USOS[$uso]['prefixo'] ?? null;
    if ($prefixo === null) {
        return null;
    }

    foreach (VP_IMG_TIPOS as $ext) {
        $arquivo = VP_IMG_DIR . '/' . $prefixo . $eventId . '.' . $ext;
        if (is_file($arquivo)) {
            return 'public/uploads/votacao/' . $prefixo . $eventId . '.' . $ext . '?v=' . filemtime($arquivo);
        }
    }

    return null;
}

/**
 * Grava a imagem enviada no campo $campo.
 *
 * O tipo vem do cabeçalho binário (getimagesize), nunca do nome do arquivo.
 * A imagem é sempre regravada pelo GD: isso tira metadados (EXIF com GPS do
 * celular de quem fotografou) e reduz imagens grandes — quem abre o link
 * está no 4G da plateia.
 *
 * @return string|null mensagem de erro, ou null se gravou
 */
function vp_imagem_salvar(int $eventId, string $uso, string $campo = 'imagem'): ?string
{
    $config = VP_IMG_USOS[$uso] ?? null;
    if ($config === null) {
        return 'Tipo de imagem desconhecido.';
    }

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
    if ($info === false || !isset(VP_IMG_TIPOS[$info[2]])) {
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

    $max = (int)$config['largura'];
    [$largura, $altura] = [imagesx($origem), imagesy($origem)];
    if ($largura > $max) {
        $novaAltura = (int)round($altura * $max / $largura);
        $reduzida = imagecreatetruecolor($max, $novaAltura);
        imagealphablending($reduzida, false);
        imagesavealpha($reduzida, true);
        imagecopyresampled($reduzida, $origem, 0, 0, 0, 0, $max, $novaAltura, $largura, $altura);
        imagedestroy($origem);
        $origem = $reduzida;
    }

    if (!is_dir(VP_IMG_DIR)) {
        mkdir(VP_IMG_DIR, 0775, true);
    }

    // PNG continua PNG (pode ter transparência); o resto vira JPG.
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $base = VP_IMG_DIR . '/' . $config['prefixo'] . $eventId;
    $ok = $ext === 'png' ? imagepng($origem, $base . '.png', 7) : imagejpeg($origem, $base . '.jpg', 84);
    imagedestroy($origem);

    if (!$ok) {
        return 'Não foi possível gravar a imagem no servidor.';
    }
    @chmod($base . '.' . $ext, 0644);

    foreach (VP_IMG_TIPOS as $outra) {
        if ($outra !== $ext) {
            @unlink($base . '.' . $outra);
        }
    }

    return null;
}

function vp_imagem_remover(int $eventId, string $uso): void
{
    $prefixo = VP_IMG_USOS[$uso]['prefixo'] ?? null;
    if ($prefixo === null) {
        return;
    }

    foreach (VP_IMG_TIPOS as $ext) {
        @unlink(VP_IMG_DIR . '/' . $prefixo . $eventId . '.' . $ext);
    }
}

/* ===========================================================================
 * CORES DA PÁGINA
 * ======================================================================== */

/**
 * Caminho absoluto (a partir da raiz do site) para usar dentro de url() no
 * CSS. Um caminho relativo numa variável CSS é resolvido a partir da pasta
 * da folha de estilo (public/assets/css/), não da página — a imagem não
 * apareceria.
 */
function vp_url_absoluta(string $relativo): string
{
    $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');

    return $base . '/' . ltrim($relativo, '/');
}

/** Luminância relativa (WCAG) de uma cor #rrggbb, de 0 (preto) a 1 (branco). */
function vp_luminancia(string $hex): float
{
    $canal = static function (int $v): float {
        $c = $v / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $canal((int)hexdec(substr($hex, 1, 2)))
         + 0.7152 * $canal((int)hexdec(substr($hex, 3, 2)))
         + 0.0722 * $canal((int)hexdec(substr($hex, 5, 2)));
}

/** "#001a52" → "0, 26, 82", para usar em rgba(). */
function vp_rgb(string $hex): string
{
    return hexdec(substr($hex, 1, 2)) . ', ' . hexdec(substr($hex, 3, 2)) . ', ' . hexdec(substr($hex, 5, 2));
}

/**
 * As variáveis CSS da página a partir das duas cores escolhidas.
 *
 * O texto e os cartões se ajustam sozinhos ao fundo: fundo escuro ganha
 * texto claro e cartão translúcido; fundo claro, o contrário. O texto sobre
 * a cor de destaque também é escolhido pelo contraste. Assim, qualquer cor
 * que o administrador escolha continua legível.
 */
function vp_tema_css(array $aparencia): string
{
    $fundo = $aparencia['cor_fundo'];
    $destaque = $aparencia['cor_destaque'];
    $fundoClaro = vp_luminancia($fundo) > 0.4;

    $vars = [
        '--vp-noite'          => $fundo,
        '--vp-noite-rgb'      => vp_rgb($fundo),
        '--vp-foco'           => $destaque,
        '--vp-foco-rgb'       => vp_rgb($destaque),
        '--vp-sobre-foco'     => vp_luminancia($destaque) > 0.4 ? '#0b1736' : '#ffffff',
        '--vp-texto'          => $fundoClaro ? '#0b1736' : '#f3f6ff',
        '--vp-suave'          => $fundoClaro ? '#4b5876' : '#b5c2e3',
        '--vp-cartao'         => $fundoClaro ? 'rgba(255, 255, 255, .82)' : 'rgba(255, 255, 255, .07)',
        '--vp-cartao-forte'   => $fundoClaro ? 'rgba(255, 255, 255, .95)' : 'rgba(255, 255, 255, .12)',
        '--vp-borda'          => $fundoClaro ? 'rgba(11, 23, 54, .14)' : 'rgba(255, 255, 255, .14)',
        '--vp-sobreposicao'   => (string)($aparencia['sobreposicao'] / 100),
    ];

    $css = '';
    foreach ($vars as $nome => $valor) {
        $css .= $nome . ': ' . $valor . '; ';
    }

    return trim($css);
}
