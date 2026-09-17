-- ============================================================================
-- Critério TORCIDA nos dois concursos do Festival Folclórico
--
--   mysql festival_v2 < mysql_14_criterio_torcida.sql
--
-- Seguro rodar mais de uma vez.
--
-- ---------------------------------------------------------------------------
-- ATENÇÃO: ISTO NÃO ESTÁ NOS REGULAMENTOS
-- ---------------------------------------------------------------------------
-- Nenhum dos dois regulamentos lista TORCIDA entre os critérios de avaliação:
-- a Batalha de Terceirões tem 5 (item 5.2) e a Quadrilha Junina tem 8
-- (item 7.1). A inclusão foi pedida pela coordenação e está registrada aqui
-- para que ninguém a leia depois como engano do sistema.
--
-- O que isso muda, dito claramente: a nota final é a média dos critérios, e
-- cada critério novo dilui os demais. Na Junina, a torcida passa a valer 1/9
-- da nota — mais do que CASAMENTO ou MARCADOR isoladamente valiam antes, e o
-- regulamento trata "melhor torcida" como PRÊMIO À PARTE (Anexo IV), não como
-- item de julgamento da apresentação.
--
-- Se a intenção for premiar a torcida separadamente, sem mexer na
-- classificação do concurso, o caminho certo é um evento próprio — e não uma
-- linha a mais na ficha. Fica o registro; a decisão é da coordenação.
--
-- Para reverter: DELETE FROM criteria WHERE event_id IN (9,10) AND name = 'TORCIDA';
-- ============================================================================

USE festival_v2;

-- Entra por último na ficha dos dois eventos: 6 na Batalha, 9 na Junina.
INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 9 AS a, 'TORCIDA' AS b, 'Participação, animação e organização da torcida que acompanha a equipe.' AS c, 1.00 AS d, 6 AS e) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 9 AND name = 'TORCIDA');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'TORCIDA', 'Participação, animação e organização da torcida que acompanha a Quadrilha Junina.', 1.00, 9) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'TORCIDA');
