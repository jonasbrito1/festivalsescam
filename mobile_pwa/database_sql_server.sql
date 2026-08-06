USE FESTIVAL_CALOUROS;
GO

IF OBJECT_ID(N'dbo.judge_observations', N'U') IS NOT NULL DROP TABLE dbo.judge_observations;
IF OBJECT_ID(N'dbo.votes', N'U') IS NOT NULL DROP TABLE dbo.votes;
IF OBJECT_ID(N'dbo.criteria', N'U') IS NOT NULL DROP TABLE dbo.criteria;
IF OBJECT_ID(N'dbo.participants', N'U') IS NOT NULL DROP TABLE dbo.participants;
IF OBJECT_ID(N'dbo.judges', N'U') IS NOT NULL DROP TABLE dbo.judges;
IF OBJECT_ID(N'dbo.event_advanced_settings', N'U') IS NOT NULL DROP TABLE dbo.event_advanced_settings;
IF OBJECT_ID(N'dbo.event_publication', N'U') IS NOT NULL DROP TABLE dbo.event_publication;
IF OBJECT_ID(N'dbo.event_notifications', N'U') IS NOT NULL DROP TABLE dbo.event_notifications;
IF OBJECT_ID(N'dbo.event_periods', N'U') IS NOT NULL DROP TABLE dbo.event_periods;
IF OBJECT_ID(N'dbo.events', N'U') IS NOT NULL DROP TABLE dbo.events;
IF OBJECT_ID(N'dbo.admins', N'U') IS NOT NULL DROP TABLE dbo.admins;
IF OBJECT_ID(N'dbo.vw_event_ranking', N'V') IS NOT NULL DROP VIEW dbo.vw_event_ranking;
GO

CREATE TABLE dbo.admins (
    id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(120) NOT NULL,
    email NVARCHAR(180) NOT NULL UNIQUE,
    password_hash NVARCHAR(255) NOT NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO

CREATE TABLE dbo.events (
    id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(180) NOT NULL,
    description NVARCHAR(MAX) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    location NVARCHAR(180) NULL,
    status NVARCHAR(20) NOT NULL DEFAULT N'rascunho',
    event_format NVARCHAR(20) NOT NULL DEFAULT N'unica',
    evaluation_minutes INT NOT NULL DEFAULT 136,
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    updated_at DATETIME2 NULL,
    CONSTRAINT chk_events_status CHECK (status IN (N'rascunho', N'aberto', N'encerrado')),
    CONSTRAINT chk_events_format CHECK (event_format IN (N'unica', N'fases')),
    CONSTRAINT chk_events_evaluation_minutes CHECK (evaluation_minutes > 0)
);
GO

CREATE TABLE dbo.event_periods (
    id INT IDENTITY(1,1) PRIMARY KEY,
    event_id INT NOT NULL,
    period_key NVARCHAR(40) NOT NULL,
    name NVARCHAR(80) NOT NULL,
    starts_at DATETIME2 NULL,
    ends_at DATETIME2 NULL,
    status NVARCHAR(20) NOT NULL DEFAULT N'programado',
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT fk_event_periods_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
    CONSTRAINT uq_event_periods_key UNIQUE (event_id, period_key),
    CONSTRAINT chk_event_periods_status CHECK (status IN (N'ativo', N'programado', N'encerrado'))
);
GO

CREATE TABLE dbo.event_notifications (
    event_id INT PRIMARY KEY,
    judge_open BIT NOT NULL DEFAULT 1,
    judge_reminder BIT NOT NULL DEFAULT 1,
    admin_complete BIT NOT NULL DEFAULT 1,
    participant_results BIT NOT NULL DEFAULT 1,
    event_changes BIT NOT NULL DEFAULT 0,
    CONSTRAINT fk_event_notifications_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE
);
GO

CREATE TABLE dbo.event_publication (
    event_id INT PRIMARY KEY,
    auto_publish BIT NOT NULL DEFAULT 1,
    publish_at DATETIME2 NULL,
    show_individual_scores BIT NOT NULL DEFAULT 0,
    show_judge_comments BIT NOT NULL DEFAULT 0,
    result_order NVARCHAR(30) NOT NULL DEFAULT N'score_desc',
    CONSTRAINT fk_event_publication_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
    CONSTRAINT chk_event_publication_order CHECK (result_order IN (N'score_desc', N'name'))
);
GO

CREATE TABLE dbo.event_advanced_settings (
    event_id INT PRIMARY KEY,
    allow_edit_after_submit BIT NOT NULL DEFAULT 0,
    show_partial_average BIT NOT NULL DEFAULT 0,
    tie_breaker NVARCHAR(40) NOT NULL DEFAULT N'highest_weight',
    decimal_places TINYINT NOT NULL DEFAULT 2,
    prevent_multi_login BIT NOT NULL DEFAULT 1,
    CONSTRAINT fk_event_advanced_settings_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
    CONSTRAINT chk_event_advanced_decimal_places CHECK (decimal_places BETWEEN 0 AND 3)
);
GO

CREATE TABLE dbo.judges (
    id INT IDENTITY(1,1) PRIMARY KEY,
    event_id INT NOT NULL,
    name NVARCHAR(120) NOT NULL,
    username NVARCHAR(180) NOT NULL,
    password_hash NVARCHAR(255) NOT NULL,
    status NVARCHAR(20) NOT NULL DEFAULT N'ativo',
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT fk_judges_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
    CONSTRAINT uq_judges_event_username UNIQUE (event_id, username),
    CONSTRAINT chk_judges_status CHECK (status IN (N'ativo', N'inativo'))
);
GO

CREATE TABLE dbo.participants (
    id INT IDENTITY(1,1) PRIMARY KEY,
    event_id INT NOT NULL,
    name NVARCHAR(160) NOT NULL,
    category NVARCHAR(100) NULL,
    song NVARCHAR(180) NULL,
    presentation_order INT NOT NULL DEFAULT 0,
    photo_url NVARCHAR(500) NULL,
    status NVARCHAR(20) NOT NULL DEFAULT N'ativo',
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT fk_participants_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
    CONSTRAINT chk_participants_status CHECK (status IN (N'ativo', N'inativo'))
);
GO

CREATE TABLE dbo.criteria (
    id INT IDENTITY(1,1) PRIMARY KEY,
    event_id INT NOT NULL,
    name NVARCHAR(120) NOT NULL,
    description NVARCHAR(255) NULL,
    weight DECIMAL(8,2) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT fk_criteria_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
    CONSTRAINT chk_criteria_weight CHECK (weight > 0)
);
GO

CREATE TABLE dbo.votes (
    id INT IDENTITY(1,1) PRIMARY KEY,
    event_id INT NOT NULL,
    judge_id INT NOT NULL,
    participant_id INT NOT NULL,
    criterion_id INT NOT NULL,
    score DECIMAL(4,1) NOT NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    updated_at DATETIME2 NULL,
    CONSTRAINT fk_votes_event FOREIGN KEY (event_id) REFERENCES dbo.events(id),
    CONSTRAINT fk_votes_judge FOREIGN KEY (judge_id) REFERENCES dbo.judges(id),
    CONSTRAINT fk_votes_participant FOREIGN KEY (participant_id) REFERENCES dbo.participants(id),
    CONSTRAINT fk_votes_criterion FOREIGN KEY (criterion_id) REFERENCES dbo.criteria(id),
    CONSTRAINT uq_votes_unique_score UNIQUE (event_id, judge_id, participant_id, criterion_id),
    CONSTRAINT chk_votes_score CHECK (score BETWEEN 0 AND 10)
);
GO

CREATE TABLE dbo.judge_observations (
    id INT IDENTITY(1,1) PRIMARY KEY,
    event_id INT NOT NULL,
    judge_id INT NOT NULL,
    participant_id INT NOT NULL,
    observation NVARCHAR(500) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    updated_at DATETIME2 NULL,
    CONSTRAINT fk_judge_observations_event FOREIGN KEY (event_id) REFERENCES dbo.events(id),
    CONSTRAINT fk_judge_observations_judge FOREIGN KEY (judge_id) REFERENCES dbo.judges(id),
    CONSTRAINT fk_judge_observations_participant FOREIGN KEY (participant_id) REFERENCES dbo.participants(id),
    CONSTRAINT uq_judge_observations UNIQUE (event_id, judge_id, participant_id)
);
GO

CREATE INDEX ix_events_status ON dbo.events(status);
CREATE INDEX ix_judges_event ON dbo.judges(event_id);
CREATE INDEX ix_participants_event ON dbo.participants(event_id, presentation_order);
CREATE INDEX ix_criteria_event ON dbo.criteria(event_id, display_order);
CREATE INDEX ix_votes_ranking ON dbo.votes(event_id, participant_id);
CREATE INDEX ix_votes_judge ON dbo.votes(event_id, judge_id);
GO

CREATE VIEW dbo.vw_event_ranking AS
SELECT
    p.event_id,
    p.id AS participant_id,
    p.name AS participant_name,
    COUNT(DISTINCT v.judge_id) AS judge_count,
    CAST(AVG(CAST(judge_scores.weighted_average AS DECIMAL(10,4))) AS DECIMAL(10,2)) AS final_score
FROM dbo.participants p
OUTER APPLY (
    SELECT
        v2.judge_id,
        SUM(v2.score * c.weight) / NULLIF(SUM(c.weight), 0) AS weighted_average
    FROM dbo.votes v2
    INNER JOIN dbo.criteria c ON c.id = v2.criterion_id
    WHERE v2.event_id = p.event_id
      AND v2.participant_id = p.id
    GROUP BY v2.judge_id
) judge_scores
LEFT JOIN dbo.votes v ON v.event_id = p.event_id AND v.participant_id = p.id
GROUP BY p.event_id, p.id, p.name;
GO

INSERT INTO dbo.admins (name, email, password_hash)
VALUES (
    N'Administrador',
    N'admin@festival.local',
    N'COLOQUE_AQUI_O_HASH_GERADO'
);
GO

-- Gere o hash com: php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT);"
