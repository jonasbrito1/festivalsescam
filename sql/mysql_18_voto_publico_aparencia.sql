USE festival_v2;

-- ============================================================================
-- Votação do público: voto único por evento + aparência da página
--
--   mysql festival_v2 < mysql_18_voto_publico_aparencia.sql
--
-- Seguro rodar mais de uma vez. Depende da 17.
--
-- ---------------------------------------------------------------------------
-- O QUE MUDA
-- ---------------------------------------------------------------------------
-- 1. A votação do público passou a ser de ESCOLHA ÚNICA: cada pessoa escolhe
--    UMA apresentação, e cada escolha é um voto. Antes, cada pessoa podia
--    dar nota a todos os participantes.
--
--    As chaves únicas da 17 eram por (evento, participante, aparelho) — um
--    voto por participante. Agora passam a ser por (evento, aparelho) e por
--    (evento, IP travado): um voto por evento. O código já confere isso ao
--    gravar; a chave única é a garantia contra dois toques simultâneos.
--
--    Esta migração NÃO apaga nada. Se já houver votos de teste no formato
--    antigo (o mesmo aparelho votando em vários participantes), a chave não
--    é criada — o código continua barrando o segundo voto sozinho, com uma
--    leitura travada (SELECT ... FOR UPDATE) antes de gravar. Para ter a
--    chave também: apague os votos de teste pelo painel (Voto do público >
--    "Apagar todos os votos do público") e rode este arquivo de novo.
--
--    publico_criterios e publico_notas não são mais usados. Ficam no banco,
--    vazios de uso, para não apagar nada às cegas.
--
-- 2. publico_aparencia: pergunta, cor de fundo, cor de destaque e quanto a
--    cor cobre a imagem de fundo, em JSON. As imagens (capa e fundo) não
--    precisam de coluna: ficam em public/uploads/votacao/.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Aparência da página
-- ---------------------------------------------------------------------------
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'evento_apuracao'
      AND COLUMN_NAME = 'publico_aparencia') = 0,
  'ALTER TABLE evento_apuracao ADD COLUMN publico_aparencia TEXT NULL AFTER publico_pontos',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Um voto por evento: chave única, só se não houver voto duplicado
-- ---------------------------------------------------------------------------
SET @dup_dispositivo = (SELECT COUNT(*) FROM (
  SELECT 1 FROM publico_cedulas GROUP BY event_id, dispositivo HAVING COUNT(*) > 1) AS d);

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'publico_cedulas'
      AND INDEX_NAME = 'uq_publico_voto_dispositivo') = 0 AND @dup_dispositivo = 0,
  'ALTER TABLE publico_cedulas ADD UNIQUE KEY uq_publico_voto_dispositivo (event_id, dispositivo)',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @dup_ip = (SELECT COUNT(*) FROM (
  SELECT 1 FROM publico_cedulas WHERE ip_trava IS NOT NULL
   GROUP BY event_id, ip_trava HAVING COUNT(*) > 1) AS d);

SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'festival_v2' AND TABLE_NAME = 'publico_cedulas'
      AND INDEX_NAME = 'uq_publico_voto_ip') = 0 AND @dup_ip = 0,
  'ALTER TABLE publico_cedulas ADD UNIQUE KEY uq_publico_voto_ip (event_id, ip_trava)',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
