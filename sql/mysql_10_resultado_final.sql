-- ---------------------------------------------------------------------------
-- Resultado final por WhatsApp quando os jurados terminam
--
-- Duas coisas faltavam para isso ser possível:
--
--   1. O sistema não sabia, de um dia para o outro, quem já tinha finalizado.
--      "Finalizar Avaliações" só marcava a SESSÃO do jurado: ele saía do
--      sistema, entrava de novo e voltava a aparecer como se não tivesse
--      terminado. Para disparar algo quando o ÚLTIMO jurado termina, isso
--      precisa estar no banco.
--
--   2. Não havia registro de que o resultado já foi enviado. Sem isso,
--      qualquer nova finalização (ou um F5 na hora errada) mandaria o
--      resultado de novo para os mesmos telefones.
--
-- Idempotente: pode rodar quantas vezes for.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS jurado_finalizou (
    event_id       INT UNSIGNED NOT NULL,
    judge_id       INT UNSIGNED NOT NULL,
    finalizado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, judge_id),
    KEY idx_jf_evento (event_id),
    CONSTRAINT fk_jf_evento FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_jf_jurado FOREIGN KEY (judge_id) REFERENCES judges (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Uma linha por evento: existe = o resultado final já saiu.
CREATE TABLE IF NOT EXISTS evento_resultado_enviado (
    event_id       INT UNSIGNED NOT NULL,
    enviado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    destinatarios  VARCHAR(400) NOT NULL DEFAULT '',
    -- 'automatico' quando o último jurado finalizou; 'manual' quando a
    -- organização mandou de novo pelo painel.
    origem         VARCHAR(20)  NOT NULL DEFAULT 'automatico',
    PRIMARY KEY (event_id),
    CONSTRAINT fk_res_evento FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Para quem vai o resultado. Fica na configuração, e não no código, porque
-- muda de festival para festival — e quem muda é a organização, não o
-- programador. Vários números separados por vírgula.
INSERT INTO integracao_config (chave, valor, sigiloso)
     VALUES ('wa_resultado_para', '', 0)
ON DUPLICATE KEY UPDATE chave = chave;

-- Liga ou desliga o disparo automático sem mexer nos números.
INSERT INTO integracao_config (chave, valor, sigiloso)
     VALUES ('wa_resultado_ativo', '1', 0)
ON DUPLICATE KEY UPDATE chave = chave;
