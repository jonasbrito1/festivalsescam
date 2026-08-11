-- ============================================================================
-- Regras de avaliação por evento
--
--   mysql festival_v2 < mysql_09_regras_evento.sql
--
-- Seguro rodar mais de uma vez.
--
-- ---------------------------------------------------------------------------
-- POR QUE ISTO EXISTE
-- ---------------------------------------------------------------------------
-- Até aqui o sistema tinha UMA regra de avaliação, embutida no código: nota de
-- 0 a 10, uma observação por participante, sem penalidades. Serviu para os
-- festivais de calouros e para o SER SESC.
--
-- O Concurso de Quadrilhas — Batalha de Terceirões do Festival Folclórico tem
-- outras, e elas não são detalhe:
--
--   nota de 9,0 a 10,0        (item 6.7 do regulamento)
--   justificativa obrigatória para nota abaixo de 10  (6.5)
--   nota esquecida vale 10    (6.6)
--   penalidades de 0,5 e 1,0 aplicadas pela organização  (3.5, 4.p.u., 7.3)
--
-- Embutir isso no código faria o próximo concurso exigir código de novo. As
-- regras passam a ser DADO do evento: cada um traz as suas, e um evento sem
-- registro aqui continua com o comportamento antigo de 0 a 10.
-- ============================================================================

USE festival_v2;

-- ---------------------------------------------------------------------------
-- Uma linha por evento que tem regra própria. Evento ausente = regra padrão.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evento_regras (
    event_id                INT UNSIGNED NOT NULL,

    nota_minima             DECIMAL(4,1) NOT NULL DEFAULT 0.0,
    nota_maxima             DECIMAL(4,1) NOT NULL DEFAULT 10.0,
    passo                   DECIMAL(4,2) NOT NULL DEFAULT 0.10,

    -- Abaixo desta nota a justificativa é obrigatória. NULL = nunca exigir.
    justificativa_abaixo_de DECIMAL(4,1) NULL,

    -- Nota atribuída ao critério deixado em branco quando o jurado finaliza.
    -- NULL = não preencher nada, como sempre foi.
    nota_ao_finalizar       DECIMAL(4,1) NULL,

    observacao              VARCHAR(400) NULL,
    atualizado              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (event_id),
    CONSTRAINT fk_evento_regras_evento FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT chk_evento_regras_faixa CHECK (nota_maxima > nota_minima)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Justificativa POR CRITÉRIO.
--
-- Já existia uma observação por participante (judge_observations), que é outra
-- coisa: um comentário geral sobre a apresentação. O regulamento pede a
-- justificativa da NOTA — uma por critério, e só quando a nota fica abaixo do
-- limite. Vai na própria linha do voto, que é onde a nota está.
--
-- `votes` é a única tabela gravada por escrita dirigida, nunca por snapshot,
-- então a coluna não corre o risco de ser apagada numa gravação de cadastro.
-- ---------------------------------------------------------------------------
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='votes'
      AND COLUMN_NAME='justificativa') = 0,
  'ALTER TABLE votes ADD COLUMN justificativa VARCHAR(600) NULL AFTER score',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Catálogo de penalidades do evento.
--
-- O valor fica aqui, e não no código, porque cada concurso tem os seus. O
-- regulamento da Batalha prevê quatro, de 0,5 e 1,0.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evento_penalidades (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id    INT UNSIGNED NOT NULL,
    nome        VARCHAR(120)  NOT NULL,
    descricao   VARCHAR(400)  NULL,
    valor       DECIMAL(4,2)  NOT NULL,
    ordem       INT UNSIGNED  NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_evento_penalidades (event_id, nome),
    CONSTRAINT fk_evento_penalidades_evento FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT chk_evento_penalidades_valor CHECK (valor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Penalidades efetivamente aplicadas a uma equipe.
--
-- Quem aplicou e quando ficam registrados: é um desconto na nota de uma
-- competição, e tem de haver a quem perguntar depois.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS participante_penalidades (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    penalidade_id  INT UNSIGNED NOT NULL,
    valor          DECIMAL(4,2) NOT NULL,   -- copiado na aplicação: se o catálogo
                                            -- mudar depois, o que foi aplicado fica
    motivo         VARCHAR(400) NULL,
    aplicada_por   VARCHAR(120) NULL,
    aplicada_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_part_pen_evento (event_id, participant_id),
    CONSTRAINT fk_part_pen_participante FOREIGN KEY (participant_id)
        REFERENCES participants (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_pen_penalidade FOREIGN KEY (penalidade_id)
        REFERENCES evento_penalidades (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
