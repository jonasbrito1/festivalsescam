# Publicação automática

Tudo que chega na `main` do GitHub vai ao ar em até **1 minuto**, sem ninguém
entrar no servidor.

O servidor busca a `main` sozinho, a cada minuto (`festival-publicar.timer`).
Não é o GitHub que empurra para o servidor — assim nenhuma chave de acesso ao
servidor fica guardada no GitHub e nenhuma porta precisa ser aberta.

```
git push  ──►  GitHub (main)  ◄── servidor confere a cada minuto
                    │                     │
                    │                     ├─ php -l em todos os .php ── erro? não publica
          Actions: "Verificar"            ├─ migrações novas de sql/ (backup antes) ── erro? não publica
          (php -l, migrações,             ├─ guarda cópia do código no ar
           autoria: aviso para quem       ├─ copia o código novo (dados intocados)
           enviou)                        ├─ permissões + reload do PHP-FPM
                                          └─ site respondeu? não → volta a cópia
```

## O que a publicação **não** toca

| Caminho | Por quê |
|---|---|
| `data/` | `db.json`, `PRIMEIRO_ACESSO.txt` |
| `storage/` | sessões e logs |
| `public/uploads/` | fotos de participantes e jurados |
| `.env`, `config/.env` | senhas |
| `deploy/`, `mobile_pwa/`, `mobile_capacitor/`, `migrate_json_to_sqlsrv.php` | estão no git, mas não são do site — nunca vão para o docroot |

Arquivo que existe no servidor e **não** existe no git é apagado — com exceção
da lista acima. Correção feita direto no servidor precisa ir para o git, senão
some na publicação seguinte.

---

## Instalação (uma vez, como root no servidor)

### 1. Instalar

```bash
apt install -y git rsync curl
cd /tmp && git clone https://github.com/jonasbrito1/festivalsescam.git festival-instalar
cd festival-instalar/deploy/publicacao

install -m 750 festival-publicar /usr/local/bin/festival-publicar
install -m 644 festival-publicar.service festival-publicar.timer /etc/systemd/system/
systemctl daemon-reload
```

### 2. Simular — antes de ligar

```bash
festival-publicar --simular
```

Mostra, arquivo por arquivo, o que mudaria no site. **Nada é alterado.**

- `>f` — arquivo que seria atualizado
- `*deleting` — arquivo que existe no servidor e não existe no git

Se aparecer `*deleting` em algo que é correção feita direto no servidor, leve
o arquivo para o git e faça `push` antes do passo 3.

### 3. Ligar

```bash
festival-publicar                                   # primeira publicação, acompanhando
systemctl enable --now festival-publicar.timer      # a partir daqui, automático
```

### 4. Conferir

```bash
systemctl list-timers festival-publicar.timer
festival-publicar --historico
```

Pelo navegador, o commit no ar:
<https://festival.sescam.online/public/assets/versao.txt>

---

## Operação

| Situação | Comando |
|---|---|
| Ver o que foi publicado | `festival-publicar --historico` |
| Ver o log da última tentativa | `journalctl -u festival-publicar -n 50` |
| Voltar para um commit anterior | `festival-publicar 9958b47` |
| Republicar a main | `festival-publicar --forcar` |
| Pausar (dia de festival, manutenção) | `systemctl stop festival-publicar.timer` |
| Retomar | `systemctl start festival-publicar.timer` |

Voltar versão pelo servidor resolve na hora, mas o timer publica a `main` de
novo assim que ela receber outro commit. A volta definitiva é `git revert` +
`push`.

**Estados no histórico:**

- `PUBLICADO` — no ar
- `BANCO` — migração aplicada
- `RECUSADO` — erro de sintaxe PHP ou migração que falhou; o código não foi
  ao ar e o site seguiu na versão anterior
- `REVERTIDO` — publicou, o site não respondeu, a versão anterior voltou

Um commit recusado ou revertido não é tentado de novo: o próximo `push` com a
correção é que destrava.

**Cópias do código:** as 10 últimas ficam em `/var/backups/festival-codigo/`
(só código; dados e fotos estão no `festival-backup`).

---

## Migrações do banco

Todo arquivo `sql/mysql_NN_descricao.sql` que ainda não rodou é aplicado
**antes** do código, em ordem de número, como root pelo socket do MySQL. O
usuário da aplicação continua sem permissão de alterar tabelas.

- **Controle:** tabela `migracoes_aplicadas` (arquivo + sha256 do conteúdo).
  Arquivo **alterado** roda de novo — por isso toda migração precisa poder
  rodar mais de uma vez (o padrão dos arquivos de `sql/`).
- **Linha de base:** na primeira execução, a tabela é criada e as migrações
  até a `mysql_17` são só registradas, sem rodar — já tinham sido aplicadas à
  mão.
- **Backup:** antes de cada lote, `mysqldump` em
  `/var/backups/festival-banco/` (10 últimos).
- **Erro:** a migração que falha trava a publicação. O código não vai ao ar,
  o histórico registra `RECUSADO` e a mensagem aponta o backup. MySQL não
  desfaz alteração de estrutura pela metade: confira o banco antes de
  corrigir e enviar de novo.
- **Voltar versão** (`festival-publicar <commit>`) mexe só no código.
  Migração aplicada fica — o código anterior precisa funcionar com ela.
- `--simular` lista as migrações que seriam aplicadas.
- `mysql_schema.sql` é o banco completo para instalação nova e nunca roda
  sozinho.

Restaurar um backup:

```bash
gunzip -c /var/backups/festival-banco/ARQUIVO.sql.gz | mysql festival_v2
```

### Atualizar o próprio script

Quando `festival-publicar` mudar no repositório:

```bash
cd /tmp/festival-instalar && git pull
install -m 750 deploy/publicacao/festival-publicar /usr/local/bin/festival-publicar
```
