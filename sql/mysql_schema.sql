-- ============================================================================
-- Festival de Calouros v2 — schema MySQL 8 / MariaDB 10.6+
--
-- Portado de database_sql_server.sql + database_sql_server_complement.sql.
--
-- Equivalencias aplicadas:
--   IDENTITY(1,1)  -> AUTO_INCREMENT
--   NVARCHAR(n)    -> VARCHAR(n)          (o charset utf8mb4 ja cobre unicode)
--   NVARCHAR(MAX)  -> TEXT / LONGTEXT
--   DATETIME2      -> DATETIME
--   BIT            -> TINYINT(1)
--   SYSDATETIME()  -> CURRENT_TIMESTAMP
--   OUTER APPLY    -> subconsulta derivada (ver a view no fim do arquivo)
--
-- Uso:  mysql -u root -p < mysql_schema.sql
-- ============================================================================

CREATE DATABASE IF NOT EXISTS festival_v2
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE festival_v2;

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW  IF EXISTS vw_event_ranking;
DROP TABLE IF EXISTS judge_reviews, judge_observations, votes, criteria,
                     participants, judges, event_phase_advancers,
                     event_advanced_settings, event_publication,
                     event_notifications, event_periods, events, admins;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
CREATE TABLE admins (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(180) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE events (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(180) NOT NULL,
    description        TEXT         NULL,
    start_date         DATE         NOT NULL,
    end_date           DATE         NULL,
    location           VARCHAR(180) NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'rascunho',
    event_format       VARCHAR(20)  NOT NULL DEFAULT 'unica',
    evaluation_minutes INT          NOT NULL DEFAULT 136,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_events_status (status),
    CONSTRAINT chk_events_status  CHECK (status IN ('rascunho','aberto','encerrado')),
    CONSTRAINT chk_events_format  CHECK (event_format IN ('unica','fases')),
    CONSTRAINT chk_events_minutes CHECK (evaluation_minutes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE event_periods (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id   INT UNSIGNED NOT NULL,
    period_key VARCHAR(40)  NOT NULL,
    name       VARCHAR(80)  NOT NULL,
    starts_at  DATETIME     NULL,
    ends_at    DATETIME     NULL,
    status     VARCHAR(20)  NOT NULL DEFAULT 'programado',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_periods_key (event_id, period_key),
    CONSTRAINT fk_event_periods_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT chk_event_periods_status CHECK (status IN ('ativo','programado','encerrado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE event_notifications (
    event_id            INT UNSIGNED NOT NULL,
    judge_open          TINYINT(1) NOT NULL DEFAULT 1,
    judge_reminder      TINYINT(1) NOT NULL DEFAULT 1,
    admin_complete      TINYINT(1) NOT NULL DEFAULT 1,
    participant_results TINYINT(1) NOT NULL DEFAULT 1,
    event_changes       TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (event_id),
    CONSTRAINT fk_event_notifications_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE event_publication (
    event_id               INT UNSIGNED NOT NULL,
    auto_publish           TINYINT(1)  NOT NULL DEFAULT 1,
    publish_at             DATETIME    NULL,
    show_individual_scores TINYINT(1)  NOT NULL DEFAULT 0,
    show_judge_comments    TINYINT(1)  NOT NULL DEFAULT 0,
    result_order           VARCHAR(30) NOT NULL DEFAULT 'score_desc',
    PRIMARY KEY (event_id),
    CONSTRAINT fk_event_publication_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT chk_event_publication_order CHECK (result_order IN ('score_desc','name'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE event_advanced_settings (
    event_id                INT UNSIGNED NOT NULL,
    allow_edit_after_submit TINYINT(1)  NOT NULL DEFAULT 0,
    show_partial_average    TINYINT(1)  NOT NULL DEFAULT 0,
    tie_breaker             VARCHAR(40) NOT NULL DEFAULT 'highest_weight',
    decimal_places          TINYINT UNSIGNED NOT NULL DEFAULT 2,
    prevent_multi_login     TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (event_id),
    CONSTRAINT fk_event_advanced_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT chk_event_advanced_decimals CHECK (decimal_places BETWEEN 0 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE event_phase_advancers (
    event_id              INT UNSIGNED NOT NULL,
    classificatoria_count INT NOT NULL DEFAULT 12,
    semifinal_count       INT NOT NULL DEFAULT 6,
    final_count           INT NOT NULL DEFAULT 3,
    PRIMARY KEY (event_id),
    CONSTRAINT fk_event_phase_advancers_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT chk_phase_classificatoria CHECK (classificatoria_count >= 0),
    CONSTRAINT chk_phase_semifinal       CHECK (semifinal_count >= 0),
    CONSTRAINT chk_phase_final           CHECK (final_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE judges (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id      INT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    username      VARCHAR(180) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'ativo',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_judges_event_username (event_id, username),
    KEY ix_judges_event (event_id),
    CONSTRAINT fk_judges_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT chk_judges_status CHECK (status IN ('ativo','inativo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE participants (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    name               VARCHAR(160) NOT NULL,
    category           VARCHAR(100) NULL,
    song               VARCHAR(180) NULL,
    presentation_order INT          NOT NULL DEFAULT 0,
    photo_url          VARCHAR(500) NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'ativo',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_participants_event (event_id, presentation_order),
    CONSTRAINT fk_participants_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT chk_participants_status CHECK (status IN ('ativo','inativo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE criteria (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id      INT UNSIGNED NOT NULL,
    name          VARCHAR(120)  NOT NULL,
    description   VARCHAR(255)  NULL,
    weight        DECIMAL(8,2)  NOT NULL DEFAULT 1,
    display_order INT           NOT NULL DEFAULT 0,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_criteria_event (event_id, display_order),
    CONSTRAINT fk_criteria_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT chk_criteria_weight CHECK (weight > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- A chave unica abaixo e o que torna a escrita dirigida segura: dois jurados
-- gravando ao mesmo tempo nao se sobrescrevem, cada nota tem seu proprio
-- lugar e o INSERT ... ON DUPLICATE KEY UPDATE resolve o reenvio.
-- ---------------------------------------------------------------------------
CREATE TABLE votes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED NOT NULL,
    judge_id       INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    criterion_id   INT UNSIGNED NOT NULL,
    score          DECIMAL(4,1) NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votes_unique_score (event_id, judge_id, participant_id, criterion_id),
    KEY ix_votes_ranking (event_id, participant_id),
    KEY ix_votes_judge (event_id, judge_id),
    -- ON DELETE CASCADE aqui e obrigatorio, nao enfeite.
    --
    -- No SQL Server estas chaves eram RESTRICT, e funcionava por acidente: o
    -- codigo antigo apagava TODAS as notas antes de cada gravacao, entao a
    -- restricao nunca era testada. Com a escrita dirigida, excluir um jurado
    -- que ja votou passa a esbarrar na chave e a operacao inteira falha.
    --
    -- CASCADE tambem e o que a tela ja faz: excluir um jurado remove as notas
    -- dele. O banco agora garante isso mesmo que o codigo esqueca.
    CONSTRAINT fk_votes_event       FOREIGN KEY (event_id)       REFERENCES events(id)       ON DELETE CASCADE,
    CONSTRAINT fk_votes_judge       FOREIGN KEY (judge_id)       REFERENCES judges(id)       ON DELETE CASCADE,
    CONSTRAINT fk_votes_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_criterion   FOREIGN KEY (criterion_id)   REFERENCES criteria(id)     ON DELETE CASCADE,
    CONSTRAINT chk_votes_score CHECK (score BETWEEN 0 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE judge_observations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED NOT NULL,
    judge_id       INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    observation    VARCHAR(500) NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_judge_observations (event_id, judge_id, participant_id),
    -- Mesmo motivo das notas: excluir jurado ou participante leva junto as
    -- observacoes deles.
    CONSTRAINT fk_obs_event       FOREIGN KEY (event_id)       REFERENCES events(id)       ON DELETE CASCADE,
    CONSTRAINT fk_obs_judge       FOREIGN KEY (judge_id)       REFERENCES judges(id)       ON DELETE CASCADE,
    CONSTRAINT fk_obs_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE judge_reviews (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED NOT NULL,
    judge_id       INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    checklist_done TINYINT(1)   NOT NULL DEFAULT 0,
    signature_mode VARCHAR(20)  NOT NULL DEFAULT 'text',
    signature_text VARCHAR(255) NULL,
    signature_touch LONGTEXT    NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_judge_reviews (event_id, judge_id, participant_id),
    KEY ix_judge_reviews_event (event_id, judge_id, participant_id),
    CONSTRAINT fk_rev_event       FOREIGN KEY (event_id)       REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_judge       FOREIGN KEY (judge_id)       REFERENCES judges(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT chk_judge_reviews_signature_mode CHECK (signature_mode IN ('text','touch'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Ranking.
--
-- O T-SQL original usava OUTER APPLY, que o MySQL nao tem. A semantica foi
-- preservada exatamente: para cada jurado calcula-se a MEDIA PONDERADA das
-- notas daquele jurado, e a nota final do participante e a MEDIA dessas
-- medias entre os jurados. Nao e soma — confira se e a regra desejada.
-- ---------------------------------------------------------------------------
CREATE VIEW vw_event_ranking AS
SELECT
    p.event_id                       AS event_id,
    p.id                             AS participant_id,
    p.name                           AS participant_name,
    COALESCE(jc.judge_count, 0)      AS judge_count,
    ROUND(js.avg_weighted, 2)        AS final_score
FROM participants p
LEFT JOIN (
    SELECT event_id, participant_id, COUNT(DISTINCT judge_id) AS judge_count
    FROM votes
    GROUP BY event_id, participant_id
) jc ON jc.event_id = p.event_id AND jc.participant_id = p.id
LEFT JOIN (
    SELECT event_id, participant_id, AVG(weighted_average) AS avg_weighted
    FROM (
        SELECT
            v.event_id,
            v.participant_id,
            v.judge_id,
            SUM(v.score * c.weight) / NULLIF(SUM(c.weight), 0) AS weighted_average
        FROM votes v
        JOIN criteria c ON c.id = v.criterion_id
        GROUP BY v.event_id, v.participant_id, v.judge_id
    ) por_jurado
    GROUP BY event_id, participant_id
) js ON js.event_id = p.event_id AND js.participant_id = p.id;
