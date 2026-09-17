-- ============================================================================
-- Festival Folclórico 2026: critérios e penalidades dos dois concursos
--
--   mysql festival_v2 < mysql_12_folclorico.sql
--
-- Seguro rodar mais de uma vez. As remoções só acontecem se o evento ainda não
-- tiver nenhum voto — depois que a banca começa a pontuar, nada aqui mexe.
--
-- ---------------------------------------------------------------------------
-- POR QUE ISTO EXISTE
-- ---------------------------------------------------------------------------
-- O Festival Folclórico tem DOIS concursos distintos, com regulamentos
-- próprios, cadastrados como eventos 9 e 10:
--
--   9  — Concurso de Quadrilhas: Batalha de Terceirões (alunos da 3ª série)
--   10 — 1º Concurso de Quadrilha Junina Tradicional (grupos do Amazonas)
--
-- A configuração inicial copiou para o evento 10 os 5 critérios e as 4
-- penalidades da Batalha. Está errado: a Quadrilha Junina Tradicional tem 8
-- critérios próprios (item 7.1) e outras penalidades (seção 8). Avaliar a
-- Quadrilha com a ficha da Batalha produziria um resultado sem relação com o
-- regulamento que os grupos aceitaram ao se inscrever.
--
-- A faixa de nota (9,0 a 10,0), a justificativa obrigatória abaixo de 10 e a
-- nota 10 ao esquecer coincidem nos dois regulamentos e já estão corretas em
-- evento_regras — este arquivo não as toca, apenas corrige a citação dos itens
-- do evento 10, que apontava para o regulamento errado.
-- ============================================================================

USE festival_v2;

-- ---------------------------------------------------------------------------
-- EVENTO 9 — BATALHA DE TERCEIRÕES
--
-- Os 5 critérios já estão certos (item 5.2), mas todos com display_order = 0:
-- a ficha do jurado sairia em ordem arbitrária. O regulamento lista uma ordem,
-- e o desempate da Batalha (9.2) se apoia nela.
-- ---------------------------------------------------------------------------
UPDATE criteria SET display_order = 1 WHERE event_id = 9 AND name = 'CRIATIVIDADE';
UPDATE criteria SET display_order = 2 WHERE event_id = 9 AND name = 'ANIMAÇÃO';
UPDATE criteria SET display_order = 3 WHERE event_id = 9 AND name = 'FIGURINO';
UPDATE criteria SET display_order = 4 WHERE event_id = 9 AND name = 'COREOGRAFIA';
UPDATE criteria SET display_order = 5 WHERE event_id = 9 AND name = 'INTERPRETAÇÃO';

-- ---------------------------------------------------------------------------
-- EVENTO 10 — QUADRILHA JUNINA TRADICIONAL: critérios
--
-- Saem os 5 herdados da Batalha; entram os 8 do item 7.1, na ordem do
-- regulamento — que é também a ordem do desempate (item 5.2).
--
-- A remoção é condicionada à ausência de votos. Se a banca já tiver pontuado,
-- o DELETE não remove nada e os INSERT não duplicam: o arquivo vira inócuo.
-- ---------------------------------------------------------------------------
DELETE FROM criteria
 WHERE event_id = 10
   AND (SELECT COUNT(*) FROM votes WHERE event_id = 10) = 0;

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10 AS a, 'CASAL DE NOIVOS' AS b, 'Performance cênica, interpretação e harmonia do casal na encenação do casamento: desenvoltura, expressividade, entrosamento, figurino adequado ao tema, fidelidade à narrativa e interação com o grupo e o público. (7.1, I)' AS c, 1.00 AS d, 1 AS e) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'CASAL DE NOIVOS');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'CASAMENTO', 'Encenação do casamento como elemento tradicional: desenvoltura cênica, clareza da narrativa, coerência com a proposta do grupo, fidelidade aos elementos juninos, criatividade na condução e interação entre os personagens. (7.1, II)', 1.00, 2) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'CASAMENTO');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'FIGURINO JUNINO', 'Adequação, coerência e harmonia dos figurinos ao tema: criatividade, acabamento, riqueza de detalhes, unidade estética e valorização da cultura junina. Avalia-se também conforto, mobilidade e segurança nos movimentos. (7.1, III)', 1.00, 3) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'FIGURINO JUNINO');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'MARCADOR', 'Capacidade de dirigir e conduzir o grupo: presença de palco, comunicação com integrantes e público, ritmo e coerência nas marcações, modulação vocal (clareza, entonação, projeção) e contribuição para a fluidez. (7.1, IV)', 1.00, 4) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'MARCADOR');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'ANIMAÇÃO', 'Energia, entusiasmo e expressividade dos integrantes durante toda a apresentação: alegria, dinamismo, espírito festivo, interação entre os componentes, engajamento coletivo e capacidade de envolver o público. (7.1, V)', 1.00, 5) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'ANIMAÇÃO');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'ORIGINALIDADE', 'Criatividade e capacidade de apresentar elementos inovadores respeitando a essência da tradição junina: desenvolvimento da temática, encenações, recursos cênicos e soluções coreográficas que diferenciem a apresentação. (7.1, VI)', 1.00, 6) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'ORIGINALIDADE');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'EVOLUÇÃO', 'Desenvolvimento da apresentação ao longo da performance: progressão das formações, deslocamentos e mudanças de figuras coreográficas, fluidez nas transições, ocupação do espaço e continuidade dinâmica. (7.1, VII)', 1.00, 7) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'EVOLUÇÃO');

INSERT INTO criteria (event_id, name, description, weight, display_order)
SELECT * FROM (SELECT 10, 'COREOGRAFIA', 'Qualidade, organização e desenvolvimento das sequências coreográficas: criatividade dos movimentos, variedade de formações, transições, ocupação do espaço cênico, precisão, domínio corporal e uniformidade na execução. (7.1, VIII)', 1.00, 8) AS novo
 WHERE NOT EXISTS (SELECT 1 FROM criteria WHERE event_id = 10 AND name = 'COREOGRAFIA');

-- ---------------------------------------------------------------------------
-- EVENTO 10 — QUADRILHA JUNINA TRADICIONAL: penalidades
--
-- Saem as 4 da Batalha (conduta antidesportiva, tema/gesto, acrobacia,
-- figurino); entram as da seção 8, que são outras.
--
-- Entram só as que a seção 8 PRECIFICA em pontos. Os itens 8.3, 8.4, 8.5, 8.7
-- e 8.8 preveem DESCLASSIFICAÇÃO, não desconto — o sistema não tem esse
-- conceito, e transformá-los em desconto suavizaria a sanção do regulamento.
-- Desclassificar continua sendo ato da Comissão Organizadora, fora daqui.
--
-- Nota sobre o item 4.10 (animais e veículos): o 8.1 o inclui na perda de 1,0,
-- mas o próprio 4.10 diz que implica desclassificação imediata. É uma
-- contradição do regulamento. Fica fora do catálogo pela mesma razão acima; se
-- a Organização decidir tratá-lo como desconto, é uma linha a acrescentar.
-- ---------------------------------------------------------------------------
DELETE FROM evento_penalidades
 WHERE event_id = 10
   AND (SELECT COUNT(*) FROM participante_penalidades WHERE event_id = 10) = 0;

INSERT INTO evento_penalidades (event_id, nome, descricao, valor, ordem) VALUES
    (10, 'Fogos ou pirotecnia',
         'Uso de fogos, pirotecnia, material inflamável ou de combustão. Na fase final admitem-se apenas fogos frios e fumaça de efeito (itens 4.9 e 8.1).',
         1.00, 1),
    (10, 'Tempo de apresentação excedido',
         'Descumprimento do tempo máximo: 15 minutos nas eliminatórias, 30 minutos na fase final (itens 4.7 e 8.2).',
         1.00, 2),
    (10, 'Número de pares em desacordo',
         'Apresentação fora do número de pares exigido: 8 pares nas eliminatórias, no máximo 14 na fase final (itens 4.5, 4.6 e 8.6).',
         0.50, 3)
ON DUPLICATE KEY UPDATE
    descricao = VALUES(descricao), valor = VALUES(valor), ordem = VALUES(ordem);

-- ---------------------------------------------------------------------------
-- CITAÇÃO CORRETA DO REGULAMENTO EM CADA EVENTO
--
-- A observação do evento 10 apontava para os itens da Batalha (6.5/6.6/6.7).
-- Os artigos equivalentes na Quadrilha Junina são outros. Esse texto aparece
-- para quem confere as regras; apontar para o regulamento errado convida ao
-- erro justamente na hora de checar.
-- ---------------------------------------------------------------------------
UPDATE evento_regras
   SET observacao = 'Regulamento do 1º Concurso de Quadrilha Junina Tradicional do Sesc Amazonas 2026: nota de 9,0 a 10,0 (6.13); justificativa obrigatória abaixo de 10 (6.6.e); nota esquecida vale 10 (6.6.f). Banca de 4 avaliadores (6.2).'
 WHERE event_id = 10;

UPDATE evento_regras
   SET observacao = 'Regulamento do Concurso de Quadrilhas - Batalha de Terceirões, Festival Folclórico 2026: nota de 9,0 a 10,0 (6.7); justificativa obrigatória abaixo de 10 (6.5); nota esquecida vale 10 (6.6). Banca de 4 avaliadores (6.1).'
 WHERE event_id = 9;
