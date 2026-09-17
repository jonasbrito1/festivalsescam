-- ============================================================================
-- Foto do jurado
--
--   mysql festival_v2 < mysql_15_foto_jurado.sql
--
-- Seguro rodar mais de uma vez.
--
-- O participante já tinha foto (participants.photo_url); o jurado não. A
-- coluna segue o mesmo nome e o mesmo formato: caminho RELATIVO à raiz do
-- projeto, como 'public/uploads/judges/jurado-42.jpg'. Nunca URL externa —
-- foto de pessoa não vai depender de servidor de terceiro para carregar no
-- meio de uma apuração.
--
-- Vazio significa sem foto; a tela mostra as iniciais no lugar.
-- ============================================================================

USE festival_v2;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'judges'
      AND COLUMN_NAME = 'photo_url') = 0,
  'ALTER TABLE judges ADD COLUMN photo_url VARCHAR(255) NULL AFTER phone',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
