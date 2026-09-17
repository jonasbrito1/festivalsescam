# Subir o gateway de WhatsApp no VPS

Passo a passo para pôr a Evolution API no ar em `festival.sescam.online` e
ligar o disparo de mensagens do sistema.

O código da aplicação já está pronto — [`lib/evolution.php`](../../lib/evolution.php)
e [`lib/whatsapp.php`](../../lib/whatsapp.php) fazem fila, envio, reenvio e
histórico. O que falta é só o gateway existir.

**Por que não a Cloud API da Meta:** fora da janela de 24h desde a última
mensagem *da pessoa*, a Meta só entrega modelo aprovado. A coordenação nunca
escreve para o sistema, só recebe — a janela nunca abre. No teste de 12/08 a
Meta aceitou, devolveu protocolo, e a mensagem não chegou. O detalhe está no
cabeçalho de `lib/evolution.php`.

**O que isso custa:** a Evolution mantém uma sessão de WhatsApp comum, a mesma
do WhatsApp Web. É uma conexão **não oficial** — o WhatsApp não a suporta e
pode banir o número que a usa. O risco real está em disparo em massa para
desconhecidos. Aqui são poucas mensagens por festival, para telefones da
própria organização, que esperam recebê-las. Use um chip institucional, nunca
o celular pessoal de alguém.

---

## 1. Ver se o servidor aguenta

```bash
free -m          # memória
df -h /          # disco
docker --version # já existe?
```

Evolution + Postgres + Redis pedem cerca de **700 MB a 1 GB** de RAM, além do
que MySQL, PHP-FPM e nginx já consomem. Em um VPS de 2 GB fica apertado, e o
primeiro a morrer sob pressão é o MySQL — que é onde estão as notas do
festival. Se `free -m` mostrar menos de 1 GB livre, **pare aqui** e trate de
memória (ou swap) antes de continuar.

Disco: reserve ~2 GB para as imagens e volumes.

---

## 2. Instalar o Docker

Pule se o passo 1 já mostrou uma versão.

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo systemctl enable --now docker
docker compose version
```

---

## 3. Levar os arquivos para o servidor

Da sua máquina, na raiz do projeto:

```bash
scp -P PORTA -r FESTIVAL_CALOUROS2/deploy/evolution USUARIO@festival.sescam.online:/tmp/
```

(`PORTA` e `USUARIO` estão no `.env` da raiz do projeto.)

No servidor:

```bash
sudo mkdir -p /opt/evolution
sudo cp /tmp/evolution/docker-compose.yml /opt/evolution/
sudo cp /tmp/evolution/.env.example /opt/evolution/.env
sudo chmod 600 /opt/evolution/.env
cd /opt/evolution
```

O `.env` vai com `chmod 600` porque guarda a chave que permite mandar mensagem
pelo número do festival.

---

## 4. Gerar os dois segredos

```bash
openssl rand -hex 32   # AUTHENTICATION_API_KEY
openssl rand -hex 16   # POSTGRES_PASSWORD
```

Edite `/opt/evolution/.env` e substitua os dois `TROQUE_ISTO`.

**Guarde a `AUTHENTICATION_API_KEY`** — ela vai na tela de Configurações do
sistema no passo 7. A senha do Postgres você não precisa anotar em lugar
nenhum; ela só vive nesse arquivo.

---

## 5. Subir

```bash
cd /opt/evolution
sudo docker compose up -d
sudo docker compose ps
```

Os três contêineres devem aparecer como `running` (o `postgres` com
`healthy`). O primeiro start demora um pouco: a Evolution roda as migrações do
banco antes de responder.

Confira que respondeu:

```bash
curl -s http://127.0.0.1:8088/ | head
```

E confira que **não** responde de fora — este é o teste que importa:

```bash
curl -s --max-time 5 http://SEU_IP_PUBLICO:8088/ && echo "EXPOSTO — PARE" || echo "fechado, ok"
```

Se aparecer `EXPOSTO`, revise o `ports:` do `docker-compose.yml`: tem de ser
`127.0.0.1:8088:8080`. Docker publica porta *contornando* o UFW — não confie
no firewall para isso, confie no bind.

Se algo não subiu:

```bash
sudo docker compose logs --tail=50 evolution
```

---

## 6. Aplicar a migração no MySQL

Cria as quatro chaves de configuração do gateway. É idempotente.

```bash
mysql -u festival_v2 -p festival_v2 < /var/www/festival-v2/sql/mysql_11_gateway_whatsapp.sql
```

---

## 7. Configurar na tela

Entre como administrador → **Configurações → WhatsApp**:

| Campo | Valor |
|---|---|
| Provedor | `evolution` |
| Endereço do gateway | `http://127.0.0.1:8088` |
| Instância | `festival` |
| Chave do gateway | a `AUTHENTICATION_API_KEY` do passo 4 |
| Integração ativa | ligada |

Salve. A chave é gravada como sigilosa (`sigiloso=1`) e nunca volta para a
tela nem aparece em mensagem de erro — `evo_higienizar()` cuida disso.

O nome da instância pode ser qualquer um, desde que igual aqui e no gateway.
A instância é criada sozinha no próximo passo.

---

## 8. Ler o QR Code

Ainda em Configurações, clique em **Conectar WhatsApp**. O QR aparece na tela.

No celular do chip institucional: WhatsApp → **Aparelhos conectados** →
**Conectar um aparelho** → aponte para o QR.

O estado deve virar `open`. Vai aparecer como "Festival de Calouros" na lista
de aparelhos conectados do celular.

O QR expira em segundos. Se perder, clique em Conectar de novo.

---

## 9. Testar

Botão **Testar envio** na mesma tela, para um número seu. Deve chegar na hora.

Depois confira o registro em **Acompanhamento**: toda mensagem é gravada na
tabela `mensagens` antes de sair, com status `pendente` → `enviado` ou `erro`.

Erros comuns, já traduzidos por `evo_explicar()`:

| Mensagem | Causa |
|---|---|
| `a chave do gateway está errada` | `AUTHENTICATION_API_KEY` diferente da que está na tela |
| `a sessão não existe no gateway` | nome da instância diferente, ou QR nunca lido |
| `o WhatsApp está desconectado` | sessão caiu — leia o QR de novo |
| `este número não tem WhatsApp` | número errado ou sem conta |
| `Falha de conexão` | contêiner parado, ou endereço/porta errados |

---

## 10. Depois que estiver no ar

**Telefones.** Hoje `judges.phone` e `admins.phone` existem mas estão vazios,
e `participants` não tem coluna de telefone. Sem preencher, tudo entra na fila
como `erro: Telefone inválido`. O destino do resultado final é a chave
`wa_resultado_para` (números separados por vírgula).

**Reinício do servidor.** `restart: unless-stopped` traz os contêineres de
volta sozinhos, e a sessão sobrevive porque mora no volume
`evolution_instances`. Não precisa ler o QR de novo.

**Quando a sessão cai.** Se o celular ficar dias sem internet, a sessão morre e
é preciso reler o QR. A tela de Configurações mostra o estado — confira no dia
anterior ao festival, não na hora.

**Logs:**

```bash
cd /opt/evolution && sudo docker compose logs -f --tail=100 evolution
```

**Atualizar** (a tag está fixa no compose de propósito — atualização é decisão,
não surpresa):

```bash
cd /opt/evolution
sudo docker compose pull && sudo docker compose up -d
```

**Backup.** O `festival-backup` não cobre isto, e tudo bem: se os volumes se
perderem, o custo é reler o QR Code. Não há nada insubstituível aqui — as
mensagens ficam no MySQL do festival, não no Postgres da Evolution.

---

## Limite conhecido: o envio trava a página

`wa_enviar()` roda **dentro do request web**, com timeout de 25s por mensagem
(`evo_enviar_texto()`). Para os 2 ou 3 destinatários de hoje, funciona.

Para disparo a dezenas de participantes, a tela vai expirar antes de terminar.
Aí a tabela `mensagens` precisa virar fila de verdade, drenada por um cron —
ela já tem `status`, `tentativas` e `erro` para isso; falta só o worker.
