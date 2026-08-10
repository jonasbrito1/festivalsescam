-- ============================================================================
-- Arquivamento de eventos
--
--   mysql festival_v2 < mysql_08_arquivar_evento.sql
--
-- Seguro rodar mais de uma vez.
--
-- ---------------------------------------------------------------------------
-- POR QUE NÃO É O `status`
-- ---------------------------------------------------------------------------
-- O `status` já diz em que ponto o evento está: rascunho, aberto, encerrado.
-- São estados do CICLO DE VIDA, e a organização precisa deles mesmo depois do
-- evento — um festival encerrado continua encerrado, e seus resultados
-- continuam sendo consultados.
--
-- Arquivar é outra coisa: é dizer "não me mostre mais isto no dia a dia".
-- Um evento arquivado some do painel do jurado e dos seletores, mas mantém
-- notas, participantes e relatórios intactos. Misturar as duas ideias numa
-- coluna só obrigaria a escolher entre perder o estado real do evento e
-- continuar com a tela entulhada.
--
-- ---------------------------------------------------------------------------
-- POR QUE NÃO É APAGAR
-- ---------------------------------------------------------------------------
-- Excluir um evento leva junto jurados, participantes, critérios e notas, por
-- causa do ON DELETE CASCADE. Depois de um festival realizado, isso é destruir
-- o registro do resultado. Arquivar resolve o incômodo sem esse preço.
-- ============================================================================

USE festival_v2;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='events'
      AND COLUMN_NAME='arquivado') = 0,
  'ALTER TABLE events ADD COLUMN arquivado TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='events'
      AND COLUMN_NAME='arquivado_em') = 0,
  'ALTER TABLE events ADD COLUMN arquivado_em DATETIME NULL AFTER arquivado',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='events'
      AND INDEX_NAME='ix_events_arquivado') = 0,
  'CREATE INDEX ix_events_arquivado ON events (arquivado, start_date)',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
