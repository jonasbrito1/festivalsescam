-- ============================================================================
-- Planilha SER SESC — categoria e turno separados
--
--   mysql festival_v2 < mysql_05_ser_etapas.sql
--
-- Seguro rodar mais de uma vez.
--
-- ---------------------------------------------------------------------------
-- POR QUE SEPARAR
-- ---------------------------------------------------------------------------
-- O nome do bloco vinha inteiro: "INFANTIL 1 MÉXICO MATUTINO". Serve para
-- exibir, não para comparar.
--
-- A disputa é matutino contra vespertino DENTRO de cada categoria. É o que os
-- tetos do arquivo original mostram: 200 e 200 no Infantil 1, 140 e 140 no
-- Infantil 2, 110 e 110 no Juvenil. Entre categorias os tetos diferem, então
-- comparar Juvenil (110) com Infantil 1 (200) não diria nada.
--
-- Com categoria e turno em colunas próprias, o par sai de um GROUP BY em vez
-- de sair de adivinhação sobre o texto do nome.
-- ============================================================================

USE festival_v2;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='ser_blocos' AND COLUMN_NAME='categoria') = 0,
  'ALTER TABLE ser_blocos ADD COLUMN categoria VARCHAR(80) NOT NULL DEFAULT '''' AFTER nome',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='ser_blocos' AND COLUMN_NAME='turno') = 0,
  'ALTER TABLE ser_blocos ADD COLUMN turno VARCHAR(20) NOT NULL DEFAULT '''' AFTER categoria',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='ser_blocos' AND COLUMN_NAME='ordem_categoria') = 0,
  'ALTER TABLE ser_blocos ADD COLUMN ordem_categoria INT UNSIGNED NOT NULL DEFAULT 0 AFTER turno',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Preenche a partir dos seis blocos conhecidos. Um bloco criado depois pela
-- importação fica com categoria vazia e aparece na tela como "sem categoria" —
-- visível, e não silenciosamente fora da disputa.
-- ---------------------------------------------------------------------------
UPDATE ser_blocos SET categoria = 'INFANTIL 1 · MÉXICO', turno = 'MATUTINO',   ordem_categoria = 1
 WHERE nome = 'INFANTIL 1 MÉXICO MATUTINO';
UPDATE ser_blocos SET categoria = 'INFANTIL 1 · MÉXICO', turno = 'VESPERTINO', ordem_categoria = 1
 WHERE nome = 'INFANTIL 1 MÉXICO VESPERTINO';
UPDATE ser_blocos SET categoria = 'INFANTIL 2 · EUA',    turno = 'MATUTINO',   ordem_categoria = 2
 WHERE nome = 'INFANTIL 2 EUA MATUTINO';
UPDATE ser_blocos SET categoria = 'INFANTIL 2 · EUA',    turno = 'VESPERTINO', ordem_categoria = 2
 WHERE nome = 'INFANTIL 2 EUA VESPERTINO';
UPDATE ser_blocos SET categoria = 'JUVENIL · CANADÁ',    turno = 'MATUTINO',   ordem_categoria = 3
 WHERE nome = 'JUVENIL CANADÁ MATUTINO';
UPDATE ser_blocos SET categoria = 'JUVENIL · CANADÁ',    turno = 'VESPERTINO', ordem_categoria = 3
 WHERE nome = 'JUVENIL CANADÁ VESPERTINO';

-- Rede para blocos importados depois: deduz o turno do nome e usa o resto
-- como categoria. Não acerta sempre, mas é melhor do que deixar em branco.
UPDATE ser_blocos
   SET turno = CASE
           WHEN nome LIKE '%VESPERTINO%' THEN 'VESPERTINO'
           WHEN nome LIKE '%MATUTINO%'   THEN 'MATUTINO'
           ELSE 'ÚNICO'
       END,
       categoria = TRIM(REPLACE(REPLACE(nome, 'VESPERTINO', ''), 'MATUTINO', ''))
 WHERE categoria = '';

-- CREATE INDEX não aceita IF NOT EXISTS no MariaDB 10.11; daí o teste.
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA='festival_v2' AND TABLE_NAME='ser_blocos'
      AND INDEX_NAME='ix_ser_blocos_categoria') = 0,
  'CREATE INDEX ix_ser_blocos_categoria ON ser_blocos (ordem_categoria, turno)',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
