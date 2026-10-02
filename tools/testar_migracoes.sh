#!/usr/bin/env bash
# ============================================================================
# Monta o banco do zero (schema + todas as migracoes, em ordem) e roda as
# migracoes uma SEGUNDA vez, para provar que podem ser repetidas: a
# publicacao roda de novo a migracao cujo arquivo foi alterado.
#
#   tools/testar_migracoes.sh --apagar-banco-de-teste
#
# APAGA o banco festival_v2 do MySQL em que roda. Use so num MySQL de teste
# (a verificacao do GitHub sobe um vazio). Opcoes de conexao do cliente mysql
# em MYSQL_OPCOES, ex.: MYSQL_OPCOES="-h127.0.0.1 -uroot -psenha"
# ============================================================================
set -euo pipefail

[[ "${1:-}" == "--apagar-banco-de-teste" ]] || {
  echo "Este teste APAGA o banco festival_v2. Para confirmar: $0 --apagar-banco-de-teste" >&2
  exit 2
}
[[ -d /var/www/festival-v2 ]] && { echo "Isto parece o servidor de producao. Abortado." >&2; exit 2; }

cd "$(dirname "$0")/.."
read -r -a OPCOES <<< "${MYSQL_OPCOES:-}"
mysql_() { mysql "${OPCOES[@]}" "$@"; }

mysql_ -e 'DROP DATABASE IF EXISTS festival_v2'
mysql_ < sql/mysql_schema.sql

# 12 e 14 corrigem dados dos eventos 9 e 10 da producao: precisam que existam.
mysql_ festival_v2 -e "INSERT INTO events (id, name, start_date) VALUES
  (9, 'Teste evento 9', '2026-01-01'), (10, 'Teste evento 10', '2026-01-01')"

falhas=0
for passada in 1 2; do
  for f in $(ls sql/mysql_[0-9]*_*.sql | sort -V); do
    if ! saida=$(mysql_ festival_v2 < "$f" 2>&1 >/dev/null); then
      echo "::error file=$f::passada $passada: $saida"
      falhas=$((falhas + 1))
    fi
  done
  echo "Passada $passada concluida."
done

if (( falhas > 0 )); then
  echo "$falhas falha(s). Migracao com erro trava a publicacao no servidor."
  exit 1
fi
echo "Todas as migracoes rodaram duas vezes sem erro."
