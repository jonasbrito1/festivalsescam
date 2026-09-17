-- ============================================================================
-- Festival Folclórico 2026: banca nos dois concursos e justificativa só na Junina
--
--   mysql festival_v2 < mysql_13_folclorico_banca.sql
--
-- Seguro rodar mais de uma vez.
-- ============================================================================

USE festival_v2;

-- ---------------------------------------------------------------------------
-- 1. A MESMA BANCA NOS DOIS CONCURSOS
--
-- Os avaliadores foram cadastrados só na Batalha de Terceirões (evento 9) e
-- julgam também a Quadrilha Junina Tradicional (evento 10).
--
-- A tabela guarda uma linha por (jurado, evento) — a chave única é
-- (event_id, username), não o username sozinho. O login recolhe TODAS as
-- linhas que conferem com o usuário e a senha, e o jurado troca de evento
-- pelo painel. Por isso a linha nova leva o MESMO username e o MESMO
-- password_hash: é uma credencial só, servindo aos dois concursos.
--
-- JURADO TESTE fica de fora de propósito: já existe no evento 10 e não é
-- banca de verdade.
--
-- A tabela de origem entra como subconsulta materializada porque o MySQL
-- recusa referenciar a tabela alvo diretamente no NOT EXISTS de um
-- INSERT ... SELECT.
-- ---------------------------------------------------------------------------
INSERT INTO judges (event_id, name, username, password_hash, status)
SELECT 10, banca.name, banca.username, banca.password_hash, banca.status
  FROM (
        SELECT name, username, password_hash, status
          FROM judges
         WHERE event_id = 9
           AND username <> 'jurado.teste'
       ) AS banca
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------------
-- 2. JUSTIFICATIVA OBRIGATÓRIA SÓ NA QUADRILHA JUNINA
--
-- Decisão da coordenação: exigir justificativa apenas no Concurso de
-- Quadrilha Junina Tradicional (evento 10). A Batalha de Terceirões (evento 9)
-- passa a aceitar nota abaixo de 10 sem texto.
--
-- ATENÇÃO — isto diverge do regulamento da Batalha, item 6.5: "Para as notas
-- abaixo de 10, o avaliador deverá obrigatoriamente aplicar a justificativa da
-- sua nota." A mudança foi pedida pela coordenação do festival e está
-- registrada aqui para que ninguém a interprete depois como engano do sistema.
-- Para reverter: devolver justificativa_abaixo_de = 10.0 no evento 9.
--
-- NULL desliga a exigência e também esconde o campo na ficha do jurado — o
-- textarea de justificativa só é renderizado quando o evento tem limite.
-- ---------------------------------------------------------------------------
UPDATE evento_regras
   SET justificativa_abaixo_de = NULL,
       observacao = 'Regulamento do Concurso de Quadrilhas - Batalha de Terceirões, Festival Folclórico 2026: nota de 9,0 a 10,0 (6.7); nota esquecida vale 10 (6.6); banca de 4 avaliadores (6.1). A justificativa obrigatória do item 6.5 foi dispensada por decisão da coordenação - vale somente na Quadrilha Junina.'
 WHERE event_id = 9;
