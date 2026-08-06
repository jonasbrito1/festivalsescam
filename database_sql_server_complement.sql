USE FESTIVAL_CALOUROS;
GO

IF OBJECT_ID(N'dbo.judge_reviews', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.judge_reviews (
        id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        event_id INT NOT NULL,
        judge_id INT NOT NULL,
        participant_id INT NOT NULL,
        checklist_done BIT NOT NULL DEFAULT 0,
        signature_mode NVARCHAR(20) NOT NULL DEFAULT N'text',
        signature_text NVARCHAR(255) NULL,
        signature_touch NVARCHAR(MAX) NULL,
        created_at DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
        updated_at DATETIME2 NULL,
        CONSTRAINT uq_judge_reviews UNIQUE (event_id, judge_id, participant_id),
        CONSTRAINT fk_judge_reviews_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
        CONSTRAINT fk_judge_reviews_judge FOREIGN KEY (judge_id) REFERENCES dbo.judges(id) ON DELETE CASCADE,
        CONSTRAINT fk_judge_reviews_participant FOREIGN KEY (participant_id) REFERENCES dbo.participants(id) ON DELETE CASCADE,
        CONSTRAINT chk_judge_reviews_signature_mode CHECK (signature_mode IN (N'text', N'touch'))
    );
END;
GO

IF OBJECT_ID(N'dbo.event_phase_advancers', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.event_phase_advancers (
        event_id INT NOT NULL PRIMARY KEY,
        classificatoria_count INT NOT NULL DEFAULT 12,
        semifinal_count INT NOT NULL DEFAULT 6,
        final_count INT NOT NULL DEFAULT 3,
        CONSTRAINT fk_event_phase_advancers_event FOREIGN KEY (event_id) REFERENCES dbo.events(id) ON DELETE CASCADE,
        CONSTRAINT chk_event_phase_advancers_classificatoria CHECK (classificatoria_count >= 0),
        CONSTRAINT chk_event_phase_advancers_semifinal CHECK (semifinal_count >= 0),
        CONSTRAINT chk_event_phase_advancers_final CHECK (final_count >= 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'ix_judge_reviews_event'
      AND object_id = OBJECT_ID(N'dbo.judge_reviews')
)
BEGIN
    CREATE INDEX ix_judge_reviews_event ON dbo.judge_reviews(event_id, judge_id, participant_id);
END;
GO
