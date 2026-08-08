-- ============================================================================
-- Vínculo entre a Planilha SER SESC e as notas dos jurados
--
--   mysql festival_v2 < mysql_07_ser_vinculo.sql
--
-- Seguro rodar mais de uma vez.
--
-- ---------------------------------------------------------------------------
-- POR QUE UMA TABELA SÓ PARA ISSO
-- ---------------------------------------------------------------------------
-- O caminho óbvio seria pendurar uma coluna em `criteria` dizendo qual campo
-- da planilha cada critério alimenta. Não dá: os cadastros (evento, jurado,
-- participante, critério) são gravados por SNAPSHOT — a tabela inteira é
-- reescrita a partir do array PHP a cada salvamento. Uma coluna nova ali seria
-- apagada na primeira vez que alguém editasse qualquer critério pela tela.
--
-- Numa tabela própria, que o snapshot não conhece, o vínculo sobrevive.
--
-- ---------------------------------------------------------------------------
-- O QUE É UM VÍNCULO
-- ---------------------------------------------------------------------------
-- Uma linha por CÉLULA da planilha que passa a ser preenchida pelos jurados:
--
--   turma #12, campo 'bandeira'  <-  evento 6, participante 70, critério 35
--
-- A nota que aparece na célula é a média dos jurados que lançaram nota
-- naquele (participante, critério). Célula sem vínculo continua digitável.
-- ============================================================================

USE festival_v2;

CREATE TABLE IF NOT EXISTS ser_vinculo (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alvo           VARCHAR(10)  NOT NULL,   -- 'turma' ou 'bloco'
    alvo_id        INT UNSIGNED NOT NULL,   -- ser_turmas.id ou ser_blocos.id
    campo          VARCHAR(20)  NOT NULL,   -- bandeira|mascote|caracterizacao|danca|mosaico
    event_id       INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    criterion_id   INT UNSIGNED NOT NULL,
    criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Uma célula tem no máximo uma origem. Sem isto, duas linhas apontando
    -- para a mesma célula fariam a nota exibida depender da ordem da leitura.
    UNIQUE KEY uq_ser_vinculo_celula (alvo, alvo_id, campo),
    KEY ix_ser_vinculo_origem (event_id, participant_id, criterion_id),

    CONSTRAINT chk_ser_vinculo_alvo CHECK (alvo IN ('turma','bloco'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
