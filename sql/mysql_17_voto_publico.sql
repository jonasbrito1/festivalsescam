-- ============================================================================
-- Apuração configurável por evento + votação do público
--
--   mysql festival_v2 < mysql_17_voto_publico.sql
--
-- Seguro rodar mais de uma vez. Só CRIA tabelas e colunas: nada existente é
-- alterado. Quem rodou uma versão anterior deste arquivo deve rodá-lo de novo
-- (entram publico_pontos e publico_classificar).
--
-- Sem estas tabelas o sistema segue funcionando como antes (nota por média,
-- sem votação do público); as telas novas avisam que falta a migração.
--
-- ---------------------------------------------------------------------------
-- evento_apuracao      como a nota final é calculada e se o público vota
-- publico_criterios    os critérios que o público avalia (não são os dos
--                      jurados: o jurado nunca vê estes, e o público nunca
--                      vê os dele)
-- publico_cedulas      um voto do público em UM participante. As duas chaves
--                      únicas são o "um voto por aparelho": o mesmo navegador
--                      (dispositivo) ou, se o evento restringe, a mesma
--                      conexão (ip_trava) não votam duas vezes no mesmo
--                      participante. ip_trava fica NULL quando o evento não
--                      restringe por IP — e NULL não colide em chave única.
-- publico_notas        as notas daquela cédula, uma por critério
--
-- O IP nunca é guardado em claro: só o hash (HMAC com chave local).
--
-- O voto do público não entra como nota: forma uma classificação do público,
-- e cada colocação vale os pontos de publico_pontos ("30,20,10" = 1º leva 30,
-- 2º leva 20, 3º leva 10, demais 0), somados aos pontos dos jurados.
-- ============================================================================

USE festival_v2;

CREATE TABLE IF NOT EXISTS evento_apuracao (
    event_id               INT UNSIGNED NOT NULL,

    -- soma | soma_media | media  (ver lib/voto_publico.php)
    modo_calculo           VARCHAR(20)  NOT NULL DEFAULT 'media',

    publico_ativo          TINYINT(1)   NOT NULL DEFAULT 0,  -- público entra na nota final
    publico_aberto         TINYINT(1)   NOT NULL DEFAULT 0,  -- o link aceita votos agora
    publico_token          CHAR(32)     NULL,                -- o segredo do link
    publico_restringir_ip  TINYINT(1)   NOT NULL DEFAULT 1,
    publico_pontos         VARCHAR(200) NULL,                  -- pontos por colocação: "30,20,10"
    publico_classificar    VARCHAR(10)  NOT NULL DEFAULT 'soma', -- soma | media

    atualizado             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (event_id),
    UNIQUE KEY uq_evento_apuracao_token (publico_token),
    CONSTRAINT fk_evento_apuracao_evento FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publico_criterios (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id     INT UNSIGNED  NOT NULL,
    nome         VARCHAR(120)  NOT NULL,
    descricao    VARCHAR(255)  NULL,
    nota_minima  DECIMAL(6,2)  NOT NULL DEFAULT 0,
    nota_maxima  DECIMAL(6,2)  NOT NULL DEFAULT 10,
    passo        DECIMAL(6,2)  NOT NULL DEFAULT 1,
    ordem        INT           NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    KEY ix_publico_criterios_evento (event_id, ordem),
    CONSTRAINT fk_publico_criterios_evento FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT chk_publico_criterios_faixa CHECK (nota_maxima > nota_minima)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publico_cedulas (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    participant_id  INT UNSIGNED NOT NULL,
    dispositivo     CHAR(32)     NOT NULL,
    ip_hash         CHAR(64)     NOT NULL,
    ip_trava        CHAR(64)     NULL,
    criado          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_publico_cedula_dispositivo (event_id, participant_id, dispositivo),
    UNIQUE KEY uq_publico_cedula_ip (event_id, participant_id, ip_trava),
    KEY ix_publico_cedulas_dispositivo (event_id, dispositivo),
    CONSTRAINT fk_publico_cedulas_evento FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_publico_cedulas_participante FOREIGN KEY (participant_id)
        REFERENCES participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publico_notas (
    cedula_id    INT UNSIGNED  NOT NULL,
    criterio_id  INT UNSIGNED  NOT NULL,
    score        DECIMAL(6,2)  NOT NULL,

    PRIMARY KEY (cedula_id, criterio_id),
    KEY ix_publico_notas_criterio (criterio_id),
    CONSTRAINT fk_publico_notas_cedula FOREIGN KEY (cedula_id)
        REFERENCES publico_cedulas (id) ON DELETE CASCADE,
    CONSTRAINT fk_publico_notas_criterio FOREIGN KEY (criterio_id)
        REFERENCES publico_criterios (id) ON DELETE CASCADE,
    CONSTRAINT chk_publico_notas_score CHECK (score >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Para quem rodou a primeira versão deste arquivo, sem as duas colunas.
-- ---------------------------------------------------------------------------
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'evento_apuracao'
      AND COLUMN_NAME = 'publico_pontos') = 0,
  'ALTER TABLE evento_apuracao ADD COLUMN publico_pontos VARCHAR(200) NULL AFTER publico_restringir_ip',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'evento_apuracao'
      AND COLUMN_NAME = 'publico_classificar') = 0,
  'ALTER TABLE evento_apuracao ADD COLUMN publico_classificar VARCHAR(10) NOT NULL DEFAULT ''soma'' AFTER publico_pontos',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
