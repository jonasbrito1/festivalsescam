# Configuração do servidor

Arquivos que vivem **fora** da pasta da aplicação, mas fazem parte da
instalação. Guardados aqui para que uma reconstrução do servidor não perca o
que já foi ajustado.

---

## Backup automático

`festival-backup` grava banco, fotos dos participantes e a cópia `db.json`.
Mantém 14 dias.

```bash
sudo install -m 750 deploy/festival-backup /usr/local/bin/festival-backup
sudo /usr/local/bin/festival-backup          # roda uma vez para conferir
ls -lh /var/backups/festival/
```

Agendamento de hora em hora (`crontab -e` como root):

```cron
0 * * * * /usr/local/bin/festival-backup >/dev/null 2>&1
```

De hora em hora e não uma vez por dia porque, durante o festival, perder uma
hora de notas lançadas já seria grave.

**Restaurar:**

```bash
gunzip -c /var/backups/festival/db-AAAAMMDD-HHMM.sql.gz | mysql festival_v2
tar -xzf /var/backups/festival/uploads-AAAAMMDD-HHMM.tar.gz \
    -C /var/www/festival-v2/public/uploads
```

---

## Rotação de logs

Sem isto, `storage/logs/*.log` cresce sem limite.

```bash
sudo install -m 644 deploy/logrotate-festival /etc/logrotate.d/festival
sudo logrotate -d /etc/logrotate.d/festival   # -d apenas simula
```

---

## Permissões

A aplicação só precisa escrever em três lugares. Todo o resto — inclusive o
CSS e o JS — deve pertencer ao root.

```bash
D=/var/www/festival-v2
chown -R root:festival $D
find $D -type d -exec chmod 750 {} \;
find $D -type f -exec chmod 640 {} \;

# Únicos diretórios graváveis pela aplicação
chown -R festival:festival $D/data $D/storage $D/public/uploads
chmod -R 770 $D/data $D/storage

# Servidos ao navegador, mas NÃO graváveis pelo PHP
chown -R root:festival $D/public/assets
find $D/public/assets -type d -exec chmod 755 {} \;
find $D/public/assets -type f -exec chmod 644 {} \;
chmod 755 $D $D/public
```

O último bloco importa mais do que parece: com `public/assets` gravável pelo
usuário do PHP, uma falha na aplicação permitiria reescrever o `app.js` e
capturar a senha dos jurados na própria tela de login.

---

## Variáveis de ambiente

No pool do PHP-FPM (`/etc/php/8.3/fpm/pool.d/festival.conf`):

```ini
env[FESTIVAL_SESSION_DIR] = /var/www/festival-v2/storage/sessions
env[FESTIVAL_DB_MODO]     = primario
env[FESTIVAL_DB_HOST]     = 127.0.0.1
env[FESTIVAL_DB_NAME]     = festival_v2
env[FESTIVAL_DB_USER]     = festival_v2
env[FESTIVAL_DB_PASS]     = ...

; Opcional, e preferível ao token gravado no banco: assim ele não entra
; em dump nem em backup.
env[FESTIVAL_WA_TOKEN]    = ...
```

Depois de alterar: `systemctl reload php8.3-fpm`.

---

## Acesso ao banco

O MySQL escuta apenas em `127.0.0.1` — não há acesso remoto direto.

```bash
ssh -p PORTA usuario@servidor
mysql -u festival_v2 -p festival_v2
```

Em clientes gráficos (DBeaver e afins), use túnel SSH e aponte para
`127.0.0.1:3306`.
