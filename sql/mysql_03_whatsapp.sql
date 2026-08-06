-- ============================================================================
-- Gestão de usuários + integração WhatsApp
--
--   mysql festival_v2 < mysql_03_whatsapp.sql
--
-- Seguro rodar mais de uma vez.
-- ============================================================================

USE festival_v2;

-- ---------------------------------------------------------------------------
-- Telefone nos cadastros — destino das notificações.
-- ---------------------------------------------------------------------------
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='judges' AND COLUMN_NAME='phone') = 0,
  'ALTER TABLE judges ADD COLUMN phone VARCHAR(20) NULL AFTER username',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='admins' AND COLUMN_NAME='phone') = 0,
  'ALTER TABLE admins ADD COLUMN phone VARCHAR(20) NULL AFTER email',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Configuração da integração.
--
-- Fica em tabela própria, e não no data/db.json, de propósito: o token de
-- acesso do WhatsApp é uma credencial viva. O db.json é copiado em backups e
-- pode ser lido por qualquer processo com acesso ao disco; a tabela exige as
-- credenciais do banco.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS integracao_config (
    chave      VARCHAR(60)  NOT NULL,
    valor      TEXT         NULL,
    -- Marca os campos que nunca devem voltar para a tela (token, segredos).
    sigiloso   TINYINT(1)   NOT NULL DEFAULT 0,
    atualizado DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Fila e histórico de mensagens.
--
-- Cada destinatário gera uma linha própria, com status, tentativas e erro.
-- É o que permite a tela de confirmação e o botão de reenviar.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mensagens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED NULL,
    judge_id       INT UNSIGNED NULL,
    participant_id INT UNSIGNED NULL,

    tipo           VARCHAR(40)  NOT NULL,   -- credenciais | voto_registrado | avulsa
    destinatario   VARCHAR(120) NOT NULL,   -- nome de quem recebe
    telefone       VARCHAR(20)  NOT NULL,   -- somente dígitos, com DDI
    mensagem       TEXT         NOT NULL,

    status         VARCHAR(20)  NOT NULL DEFAULT 'pendente',
    tentativas     INT UNSIGNED NOT NULL DEFAULT 0,
    erro           VARCHAR(500) NULL,
    id_provedor    VARCHAR(120) NULL,       -- id devolvido pelo WhatsApp

    criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em     DATETIME     NULL,

    PRIMARY KEY (id),
    KEY ix_mensagens_status (status, criado_em),
    KEY ix_mensagens_evento (event_id, criado_em),
    KEY ix_mensagens_jurado (judge_id),
    CONSTRAINT chk_mensagens_status CHECK (status IN ('pendente','enviado','erro','cancelado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Valores iniciais (o token fica vazio; é preenchido pela tela).
-- ---------------------------------------------------------------------------
INSERT INTO integracao_config (chave, valor, sigiloso) VALUES
    ('wa_ativo',            '0',      0),
    ('wa_numero_saida',     '',       0),
    ('wa_phone_number_id',  '',       0),
    ('wa_business_id',      '',       0),
    ('wa_token',            '',       1),
    ('wa_versao_api',       'v20.0',  0),
    ('wa_endpoint',         '',       0),
    ('wa_notificar_voto',   '1',      0),
    ('wa_ddi_padrao',       '55',     0)
ON DUPLICATE KEY UPDATE chave = chave;
