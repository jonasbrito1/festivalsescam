# Festival de Calouros — Sistema de Notas de Jurados

Sistema web para conduzir festivais de música: cadastro de eventos, jurados,
participantes e critérios; lançamento de notas pelos jurados; apuração,
ranking e projeção ao vivo para o telão.

Feito para o **Sesc Amazonas**.

---

## Como funciona

Dois perfis de acesso:

| Perfil | Faz |
|---|---|
| **Administrador** | Cria eventos, cadastra jurados, participantes e critérios, acompanha a apuração, exporta resultados |
| **Jurado** | Lança as notas dos participantes do seu evento, registra observações e assina a ficha |

A nota final de cada participante é a **média ponderada por jurado, depois
promediada entre os jurados** — não é soma. Critérios têm peso, e o desempate
segue a ordem configurada no evento.

---

## Requisitos

- PHP 8.1 ou superior (`pdo_mysql`, `mbstring`, `gd`)
- MySQL 8 ou MariaDB 10.6+
- Nginx ou Apache

O sistema também opera com armazenamento local em arquivo (`data/db.json`),
útil para desenvolvimento sem banco.

---

## Instalação

```bash
git clone https://github.com/jonasbrito1/festivalsescam.git
cd festivalsescam
```

### 1. Banco de dados

```bash
mysql -u root -p < sql/mysql_schema.sql
mysql -u root -p festival_v2 < sql/mysql_02_cascade.sql
```

Crie um usuário sem privilégios de DDL — a aplicação só precisa manipular dados:

```sql
CREATE USER 'festival_v2'@'localhost' IDENTIFIED BY 'defina-uma-senha-forte';
GRANT SELECT, INSERT, UPDATE, DELETE ON `festival_v2`.* TO 'festival_v2'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Variáveis de ambiente

Definidas no pool do PHP-FPM (`env[...]`) ou no ambiente do servidor:

```ini
FESTIVAL_DB_MODO = primario     ; off | espelho | primario
FESTIVAL_DB_HOST = 127.0.0.1
FESTIVAL_DB_PORT = 3306
FESTIVAL_DB_NAME = festival_v2
FESTIVAL_DB_USER = festival_v2
FESTIVAL_DB_PASS = a-senha-definida-acima

; Sessões fora da pasta pública
FESTIVAL_SESSION_DIR = /caminho/do/projeto/storage/sessions
```

Os modos: `off` usa só o arquivo local; `espelho` grava nos dois e serve para
conferir a migração; `primario` faz do MySQL a fonte da verdade.

### 3. Primeiro acesso

Ao subir pela primeira vez sem banco populado, o sistema gera uma senha
aleatória e a grava em `data/PRIMEIRO_ACESSO.txt`. Leia o arquivo, entre,
**troque a senha e apague o arquivo**.

### 4. Servidor web

A raiz do site é a pasta do projeto (o `index.php` fica nela), mas
`data/`, `config/`, `lib/`, `sql/`, `tools/` e `storage/` **precisam ficar
inacessíveis pela web**. Exemplo em Nginx:

```nginx
location ^~ /data/    { deny all; return 404; }
location ^~ /config/  { deny all; return 404; }
location ^~ /lib/     { deny all; return 404; }
location ^~ /sql/     { deny all; return 404; }
location ^~ /tools/   { deny all; return 404; }
location ^~ /storage/ { deny all; return 404; }

location ~* \.(json|sql|md|log|env)$ { deny all; return 404; }

# Só o front controller executa
location = /index.php { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location ~ \.php$     { deny all; return 404; }

# Fotos servidas como arquivo estático, nunca executadas
location ^~ /public/uploads/ {
    location ~ \.(php|phtml|phar)$ { deny all; return 403; }
    try_files $uri =404;
}
```

---

## Estrutura

```
├── index.php                 front controller (único ponto de entrada)
├── lib/mysql.php             camada de dados: escrita dirigida, leitura, espelho
├── config/database.php       integração opcional com SQL Server (via env)
├── sql/                      schema MySQL e migrações
├── tools/                    utilitários de linha de comando
│   ├── importar_json_mysql.php    carga inicial do arquivo para o banco
│   ├── comparar_json_mysql.php    confere os dois lados durante o espelho
│   └── validar_leitura_mysql.php  compara a leitura campo a campo
├── public/assets/            css, js e imagens
├── data/                     armazenamento local (NÃO versionado)
└── mobile_pwa/               variante PWA
```

---

## Segurança

O sistema aplica:

- **CSRF** em todas as ações POST, com token injetado automaticamente nos formulários
- **Força bruta**: 5 tentativas por conta e 15 por IP, em janela de 15 minutos, contadas em arquivo (não na sessão)
- **Sessão**: cookie `HttpOnly` + `SameSite`, `secure` sob HTTPS, ID renovado no login, expiração por inatividade
- **Upload**: tipo verificado pelo cabeçalho binário do arquivo, não pela extensão do nome
- **Escrita dirigida** nas notas (`INSERT ... ON DUPLICATE KEY UPDATE`), para que jurados simultâneos não sobrescrevam uns aos outros
- **Escape de saída** em todo conteúdo vindo do usuário

### O que nunca deve ser versionado

`data/db.json` contém o banco inteiro — nomes de participantes (incluindo
menores de idade), jurados e hashes de senha. `public/uploads/participants/`
contém fotos de pessoas identificáveis. Ambos estão no `.gitignore` e devem
continuar assim.

---

## Licença

Uso interno do Sesc Amazonas.
