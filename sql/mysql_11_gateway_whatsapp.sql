-- ---------------------------------------------------------------------------
-- Segundo caminho de saída para o WhatsApp
--
-- A Cloud API da Meta aceita a mensagem, devolve protocolo e NÃO ENTREGA
-- quando o destinatário não escreveu para o número da empresa nas últimas 24
-- horas. Fora dessa janela só passa modelo de mensagem aprovado. A coordenação
-- do festival nunca escreve para o sistema — ela só recebe —, então a janela
-- nunca está aberta e o resultado final nunca chegaria. Foi o que aconteceu no
-- teste de 12/08.
--
-- Estas chaves ligam o envio por uma sessão de WhatsApp comum (Evolution API),
-- que não tem essa restrição. A configuração da Meta continua gravada e volta
-- a valer trocando wa_provedor para 'meta'.
--
-- Idempotente: pode rodar quantas vezes for.
-- ---------------------------------------------------------------------------

INSERT INTO integracao_config (chave, valor, sigiloso) VALUES
    ('wa_provedor',      'meta', 0),   -- 'meta' | 'evolution'
    ('wa_evo_url',       '',     0),   -- ex.: http://127.0.0.1:8088
    ('wa_evo_instancia', '',     0),   -- nome da sessão no gateway
    ('wa_evo_chave',     '',     1)    -- sigilosa: nunca volta para a tela
ON DUPLICATE KEY UPDATE chave = chave;
