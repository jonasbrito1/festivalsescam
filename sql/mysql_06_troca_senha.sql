-- ============================================================================
-- Troca de senha obrigatória na primeira entrada
--
--   mysql festival_v2 < mysql_06_troca_senha.sql
--
-- Seguro rodar mais de uma vez.
--
-- ---------------------------------------------------------------------------
-- POR QUE
-- ---------------------------------------------------------------------------
-- Conta nova nasce com uma senha combinada por fora — dita no corredor,
-- mandada por mensagem, escrita num bilhete. Enquanto ela continuar valendo,
-- todo mundo que passou perto daquele canal tem acesso de administrador.
--
-- A marca abaixo faz o sistema exigir a troca antes de liberar qualquer tela.
-- Não é um lembrete: sem trocar, não se entra.
-- ============================================================================

USE festival_v2;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='admins'
      AND COLUMN_NAME='must_change_password') = 0,
  'ALTER TABLE admins ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
