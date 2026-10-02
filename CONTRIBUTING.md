# Como enviar ajustes

> **A `main` é a produção.** Tudo que chega nela vai ao ar em
> <https://festival.sescam.online> em até 1 minuto, automaticamente.

Detalhes da publicação: [`deploy/publicacao/`](deploy/publicacao/).

---

## Primeira vez

1. Aceite o convite de colaborador do repositório (enviado pelo Jonas).
2. Clone e configure:

```bash
git clone https://github.com/jonasbrito1/festivalsescam.git
cd festivalsescam

git config user.name  "Seu Nome"
git config user.email "seu-email-do-github"
git config core.hooksPath .githooks
```

O último comando ativa o gancho que mantém a autoria do commit só com quem o
faz (ver [Autoria](#autoria)).

---

## Dia a dia

### Ajuste pequeno — direto na `main`

Correção de texto, de estilo, de um bug localizado:

```bash
git pull --rebase           # antes de começar
# ... altera, testa ...
git add arquivo1 arquivo2   # só o que faz parte do ajuste
git commit -m "Corrige o arredondamento da média no placar"
git pull --rebase           # pega o que o outro enviou enquanto você trabalhava
git push                    # vai ao ar em até 1 minuto
```

### Mudança maior — por ramo e pull request

Tela nova, mudança de regra de cálculo, qualquer coisa que mexa em várias
partes:

```bash
git switch -c ajuste-ficha-jurado
# ... commits ...
git push -u origin ajuste-ficha-jurado
```

Abra o pull request no GitHub. A verificação roda no PR; o ar só muda quando
o PR for incorporado na `main`.

### Dois mexendo ao mesmo tempo

- Sempre `git pull --rebase` antes do `push`. Se der conflito, resolva,
  `git rebase --continue` e só então `push`.
- **Nunca** `git push --force` na `main`.
- Avise no grupo quando for mexer em `index.php` em trecho grande — é o arquivo
  que os dois mais tocam.

---

## Depois do `push`

1. Aba **Actions** do GitHub: a verificação **Verificar** precisa ficar verde.
2. Em até 1 minuto, confira o commit no ar:
   <https://festival.sescam.online/public/assets/versao.txt>
3. Abra o site e confira o ajuste.

**Se a verificação ficar vermelha** por erro de sintaxe PHP: o servidor
**recusa** o commit e o site segue na versão anterior. Corrija e faça outro
`push` — a correção destrava a publicação.

**Se algo foi ao ar quebrado** (o site respondeu, mas a tela está errada):

```bash
git revert HEAD     # desfaz o último commit criando um commit novo
git push
```

---

## Banco de dados

A publicação **não** altera o banco. Mudança de estrutura:

1. Crie o arquivo em `sql/`, seguindo a numeração dos que já existem.
2. Combine com o Jonas: ele aplica no banco de produção **antes** do `push`
   do código que depende da mudança.
3. Se possível, faça o código funcionar com o banco antigo e o novo — assim a
   ordem não importa.

---

## Dias de festival

Durante o evento, jurados estão lançando notas ao vivo. **Não envie nada para
a `main` sem combinar** — a publicação é imediata. Se precisar travar de vez,
o Jonas pausa a publicação no servidor.

---

## Nunca versionar

- `data/db.json` e qualquer `data/*.json` — banco inteiro, com dados de menores
  e hashes de senha
- fotos em `public/uploads/`
- `.env`, senhas, tokens, chaves

Estão no `.gitignore`. Confira o `git status` antes de cada commit e use
`git add` com o nome dos arquivos, não `git add .`.

---

## Autoria

- Commit em português, dizendo **o que** muda e **por quê**.
- Autor é quem faz o commit, com nome real. Mensagens **sem** linha
  `Co-Authored-By:` — o gancho de `.githooks/` remove essa linha, e a
  verificação **Autoria dos commits** acusa se alguma passar.
