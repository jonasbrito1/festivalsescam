-- ============================================================================
-- Faixa de nota POR CRITÉRIO
--
--   mysql festival_v2 < mysql_16_faixa_nota_criterio.sql
--
-- Seguro rodar mais de uma vez. Aplicar ANTES do push do código que usa as
-- colunas novas (o código também funciona sem elas, mas aí a faixa própria
-- do critério não é aceita na tela de Critérios).
--
-- ---------------------------------------------------------------------------
-- POR QUE ISTO EXISTE
-- ---------------------------------------------------------------------------
-- Até aqui a faixa da nota era do EVENTO (evento_regras) ou o padrão 0 a 10.
-- O SESC Festival Music — Rising Stars pontua cada critério numa escala
-- diferente: Pronúncia vale 100 pontos, Criatividade 50, Presença de palco
-- 20, Organização 20, Cosplay 10. Com uma faixa só para o evento inteiro, o
-- jurado não tinha como lançar 85 em Pronúncia.
--
-- Agora o critério pode ter a sua própria faixa. NULL nas três colunas =
-- herda a regra do evento, que continua sendo o comportamento de sempre.
--
-- ---------------------------------------------------------------------------
-- O QUE MUDA EM `votes`
-- ---------------------------------------------------------------------------
-- 1. A CHECK chk_votes_score (score BETWEEN 0 AND 10) sai. Com ela, uma nota
--    85 num critério de 0 a 100 seria recusada pelo banco. A faixa continua
--    sendo conferida no servidor, ao gravar, critério a critério — que é onde
--    ela agora mora (lib/regras.php).
-- 2. score passa de DECIMAL(4,1) para DECIMAL(6,2): cabe até 9999,99 e
--    guarda centésimos, que o código já arredondava para duas casas.
-- ============================================================================

USE festival_v2;

-- ---------------------------------------------------------------------------
-- criteria: faixa própria (opcional)
-- ---------------------------------------------------------------------------
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'criteria'
      AND COLUMN_NAME = 'nota_minima') = 0,
  'ALTER TABLE criteria ADD COLUMN nota_minima DECIMAL(6,2) NULL AFTER weight',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'criteria'
      AND COLUMN_NAME = 'nota_maxima') = 0,
  'ALTER TABLE criteria ADD COLUMN nota_maxima DECIMAL(6,2) NULL AFTER nota_minima',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'criteria'
      AND COLUMN_NAME = 'passo') = 0,
  'ALTER TABLE criteria ADD COLUMN passo DECIMAL(6,2) NULL AFTER nota_maxima',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- votes: tira o teto de 10 e alarga a coluna
-- ---------------------------------------------------------------------------
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'festival_v2' AND TABLE_NAME = 'votes'
      AND CONSTRAINT_NAME = 'chk_votes_score') > 0,
  'ALTER TABLE votes DROP CONSTRAINT chk_votes_score',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE votes MODIFY score DECIMAL(6,2) NOT NULL;

-- Nota negativa continua impossível; o teto é de cada critério.
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'festival_v2' AND TABLE_NAME = 'votes'
      AND CONSTRAINT_NAME = 'chk_votes_score_min') = 0,
  'ALTER TABLE votes ADD CONSTRAINT chk_votes_score_min CHECK (score >= 0)',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
