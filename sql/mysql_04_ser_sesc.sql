-- ============================================================================
-- Planilha SER SESC — versão online da "PROJETO SER SESC.xlsx"
--
--   mysql festival_v2 < mysql_04_ser_sesc.sql
--
-- Seguro rodar mais de uma vez: só cria o que falta e a carga inicial não
-- sobrescreve nota já lançada.
--
-- ---------------------------------------------------------------------------
-- POR QUE TABELAS PRÓPRIAS
-- ---------------------------------------------------------------------------
-- A planilha tem estrutura própria — turmas representando países, três
-- critérios fixos, e uma nota de dança e outra de mosaico por bloco, não por
-- turma. Encaixá-la em events/participants/criteria exigiria distorcer as
-- duas coisas. Fica ao lado, sem tocar em nada que já funciona.
--
-- ---------------------------------------------------------------------------
-- POR QUE OS TOTAIS NÃO SÃO GUARDADOS
-- ---------------------------------------------------------------------------
-- Total de turma, de bloco e geral são sempre calculados na leitura. Guardar
-- um total é criar duas fontes para o mesmo número, e mais cedo ou mais tarde
-- elas divergem — foi o que aconteceu na planilha original, onde a coluna
-- TOTAL era digitada à mão.
-- ============================================================================

USE festival_v2;

-- ---------------------------------------------------------------------------
-- Blocos: categoria + turno. Guardam a nota de dança e a de mosaico, que na
-- planilha valem para o bloco inteiro e não para cada turma.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ser_blocos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome           VARCHAR(120)  NOT NULL,
    ordem          INT UNSIGNED  NOT NULL DEFAULT 0,
    danca          DECIMAL(5,2)  NULL,
    mosaico        DECIMAL(5,2)  NULL,
    atualizado     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    atualizado_por VARCHAR(120)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ser_blocos_nome (nome),
    KEY ix_ser_blocos_ordem (ordem),
    CONSTRAINT chk_ser_blocos_danca   CHECK (danca   IS NULL OR (danca   >= 0 AND danca   <= 10)),
    CONSTRAINT chk_ser_blocos_mosaico CHECK (mosaico IS NULL OR (mosaico >= 0 AND mosaico <= 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Turmas: uma linha da aba INDIVIDUAL.
--
-- As três notas ficam em colunas separadas de propósito. Cada célula da tela
-- grava só a própria coluna da própria linha, então dois administradores
-- lançando notas ao mesmo tempo não se atropelam — mesmo princípio já usado
-- nos votos dos jurados.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ser_turmas (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bloco_id       INT UNSIGNED NOT NULL,
    turma          VARCHAR(60)   NOT NULL,
    pais           VARCHAR(60)   NOT NULL,
    ordem          INT UNSIGNED  NOT NULL DEFAULT 0,
    bandeira       DECIMAL(5,2)  NULL,
    mascote        DECIMAL(5,2)  NULL,
    caracterizacao DECIMAL(5,2)  NULL,
    atualizado     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    atualizado_por VARCHAR(120)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ser_turmas (bloco_id, turma),
    KEY ix_ser_turmas_ordem (bloco_id, ordem),
    CONSTRAINT fk_ser_turmas_bloco FOREIGN KEY (bloco_id)
        REFERENCES ser_blocos (id) ON DELETE CASCADE,
    CONSTRAINT chk_ser_turmas_bandeira CHECK (bandeira       IS NULL OR (bandeira       >= 0 AND bandeira       <= 10)),
    CONSTRAINT chk_ser_turmas_mascote  CHECK (mascote        IS NULL OR (mascote        >= 0 AND mascote        <= 10)),
    CONSTRAINT chk_ser_turmas_carac    CHECK (caracterizacao IS NULL OR (caracterizacao >= 0 AND caracterizacao <= 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Carga inicial, tirada da PROJETO SER SESC.xlsx.
--
-- Os nomes de país vêm corrigidos na acentuação: o arquivo trazia JAPAO,
-- CROACIA, AFRICA DOSUL, SUIÇA, COLOMBIA e AUSTRALIA. São editáveis na tela,
-- caso a grafia do arquivo seja a desejada.
--
-- O ON DUPLICATE toca apenas em ordem e país: rodar de novo reorganiza o
-- cadastro sem apagar nota já lançada.
-- ---------------------------------------------------------------------------
INSERT INTO ser_blocos (nome, ordem) VALUES
    ('INFANTIL 1 MÉXICO MATUTINO',   1),
    ('INFANTIL 1 MÉXICO VESPERTINO', 2),
    ('INFANTIL 2 EUA MATUTINO',      3),
    ('INFANTIL 2 EUA VESPERTINO',    4),
    ('JUVENIL CANADÁ MATUTINO',      5),
    ('JUVENIL CANADÁ VESPERTINO',    6)
ON DUPLICATE KEY UPDATE ordem = VALUES(ordem);

INSERT INTO ser_turmas (bloco_id, turma, pais, ordem)
SELECT b.id, d.turma, d.pais, d.ordem
FROM (
    SELECT 'INFANTIL 1 MÉXICO MATUTINO' AS bloco, '5º ANO A' AS turma, 'ALEMANHA'       AS pais, 1 AS ordem UNION ALL
    SELECT 'INFANTIL 1 MÉXICO MATUTINO',           '5º ANO B',          'JAPÃO',              2 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO MATUTINO',           '6º ANO A',          'PORTUGAL',           3 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO MATUTINO',           '6º ANO B',          'CROÁCIA',            4 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO MATUTINO',           '7º ANO A',          'SUÉCIA',             5 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO MATUTINO',           '7º ANO B',          'EGITO',              6 UNION ALL

    SELECT 'INFANTIL 1 MÉXICO VESPERTINO',         '5º ANO C',          'ÁFRICA DO SUL',      1 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO VESPERTINO',         '5º ANO D',          'EUA',                2 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO VESPERTINO',         '6º ANO C',          'CATAR',              3 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO VESPERTINO',         '6º ANO D',          'GANA',               4 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO VESPERTINO',         '7º ANO C',          'COREIA DO SUL',      5 UNION ALL
    SELECT 'INFANTIL 1 MÉXICO VESPERTINO',         '7º ANO D',          'MÉXICO',             6 UNION ALL

    SELECT 'INFANTIL 2 EUA MATUTINO',              '8º ANO A',          'ARGENTINA',          1 UNION ALL
    SELECT 'INFANTIL 2 EUA MATUTINO',              '8º ANO B',          'MARROCOS',           2 UNION ALL
    SELECT 'INFANTIL 2 EUA MATUTINO',              '9º ANO A',          'CANADÁ',             3 UNION ALL
    SELECT 'INFANTIL 2 EUA MATUTINO',              '9º ANO B',          'HONDURAS',           4 UNION ALL

    SELECT 'INFANTIL 2 EUA VESPERTINO',            '8º ANO C',          'HOLANDA',            1 UNION ALL
    SELECT 'INFANTIL 2 EUA VESPERTINO',            '8º ANO D',          'FRANÇA',             2 UNION ALL
    SELECT 'INFANTIL 2 EUA VESPERTINO',            '9º ANO C',          'PARAGUAI',           3 UNION ALL
    SELECT 'INFANTIL 2 EUA VESPERTINO',            '9º ANO D',          'AUSTRÁLIA',          4 UNION ALL

    SELECT 'JUVENIL CANADÁ MATUTINO',              '1ª A/B',            'SUÍÇA',              1 UNION ALL
    SELECT 'JUVENIL CANADÁ MATUTINO',              '2ª A/B/C',          'COSTA DO MARFIM',    2 UNION ALL
    SELECT 'JUVENIL CANADÁ MATUTINO',              '3ª A/B',            'CABO VERDE',         3 UNION ALL

    SELECT 'JUVENIL CANADÁ VESPERTINO',            '1ª C/D',            'COLÔMBIA',           1 UNION ALL
    SELECT 'JUVENIL CANADÁ VESPERTINO',            '2ª D/E',            'URUGUAI',            2 UNION ALL
    SELECT 'JUVENIL CANADÁ VESPERTINO',            '3ª C/D',            'BRASIL',             3
) AS d
JOIN ser_blocos b ON b.nome = d.bloco
ON DUPLICATE KEY UPDATE
    pais  = VALUES(pais),
    ordem = VALUES(ordem);
