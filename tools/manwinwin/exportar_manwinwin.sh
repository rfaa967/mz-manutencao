#!/usr/bin/env bash
# Exporta as tabelas do ManWinWin (.mdb) usadas pela importação para um .zip de CSVs.
# Requer mdbtools e zip:  sudo apt install mdbtools zip   |   brew install mdbtools
# Uso: ./exportar_manwinwin.sh /caminho/ManWinWin.mdb [saida.zip]
set -euo pipefail
MDB="${1:?Uso: $0 ManWinWin.mdb [saida.zip]}"
SAIDA="${2:-manwinwin_$(date +%Y%m%d_%H%M).zip}"
command -v mdb-export >/dev/null || { echo "Instale o mdbtools (apt install mdbtools / brew install mdbtools)"; exit 1; }
command -v zip >/dev/null || { echo "Instale o zip"; exit 1; }
command -v python3 >/dev/null || { echo "Instale o python3"; exit 1; }
TABELAS="Objectos LocaisObj TipObjeto Forneced Especialidade Funcionario Cencusto Sistemas Fichas Calibra TipTrabalho PTs Causas MestreArtigos Artigos ARQ02"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
EXISTENTES="$(mdb-tables -1 "$MDB")"
for t in $TABELAS; do
  if grep -qx "$t" <<<"$EXISTENTES"; then
    mdb-export -T '%Y-%m-%d %H:%M:%S' -D '%Y-%m-%d %H:%M:%S' -b strip "$MDB" "$t" > "$TMP/$t.csv"
    # senhas e dados pessoais do ManWinWin não são exportados (a importação não usa)
    python3 - "$TMP/$t.csv" "$t" <<'PY'
import csv, re, sys, os
arq, tab = sys.argv[1], sys.argv[2]
func = {'CodFunCnr', 'NomFunCnr', 'Email_Fun', 'CodEspCld', 'CstEspCld', 'FlgActivo'}
with open(arq, encoding='utf-8', errors='replace', newline='') as f:
    linhas = list(csv.reader(f))
if linhas:
    manter = [i for i, c in enumerate(linhas[0]) if not re.match(r'(?i)(snh|senha|pass|pwd)', c) and (tab != 'Funcionario' or c in func)]
    with open(arq + '.tmp', 'w', encoding='utf-8', newline='') as f:
        csv.writer(f).writerows([[l[i] if i < len(l) else '' for i in manter] for l in linhas])
    os.replace(arq + '.tmp', arq)
PY
    echo "  $t: $(($(python3 -c "import csv,sys;print(sum(1 for _ in csv.reader(open(sys.argv[1],encoding='utf-8',errors='replace'))))" "$TMP/$t.csv" 2>/dev/null || echo 1)-1)) linha(s)"
  fi
done
SAIDA_ABS="$(cd "$(dirname "$SAIDA")" && pwd)/$(basename "$SAIDA")"
(cd "$TMP" && zip -q -9 "$SAIDA_ABS" ./*.csv)
echo "Gerado: $SAIDA_ABS"
