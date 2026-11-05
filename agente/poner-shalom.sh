#!/bin/bash
# API de SHALOM de una hija (5-nov-2026). Una sola vez por hija, con sudo:
#   sudo bash poner-shalom.sh <hija>          ej.:  sudo bash poner-shalom.sh siete-deseos
#
# Por qué: el CRM busca las AGENCIAS y emite las guías a través de SU PROPIA API de SHALOM (shalom-integracion/, viaja
# con el CRM). En la madre cada CRM tiene la suya corriendo (8000 MIOX, 8001 JULBANY, 8002 TECNOVA); el instalador de
# hijas no la levantaba, así que en la hija el buscador de agencias salía vacío y el indicador SHALOM en rojo.
#
# Qué hace:
#   1) Instala python3-venv si falta y arma el Python de la API FUERA del CRM (/opt/haro-shalom/<hija>), para que las
#      actualizaciones y los respaldos del CRM no lo arrastren ni lo pisen.
#   2) shalom-integracion/.env con un API_TOKEN nuevo. SIN cuenta ni clave de SHALOM: la sesión la sube la extensión.
#   3) Servicio haro-<hija>-shalom, solo en 127.0.0.1 (primer puerto libre desde 8000). Prueba que sirva el catálogo.
#   4) Recién ahí apunta el CRM a esa API (system_settings de la empresa 1: shalom_use_local_api, shalom_local_url,
#      shalom_local_token), con respaldo de los ajustes de SHALOM de antes, y borra la caché de agencias.
#   5) Se asegura de que el CRM tenga SHALOM_UPLOAD_KEY (la llave con la que la extensión sube la sesión).
# Resultado: las AGENCIAS cargan desde ya (copia del catálogo, indicador ámbar). Para EMITIR guías falta la sesión de
# SHALOM de la hija: alguien de la hija entra a pro.shalom.pe con SU cuenta y la sube con la extensión «Shalom Connect»
# (CRM → Perfil → Extensión SHALOM). Nunca imprime el token ni claves. Si falla antes del paso 4, el CRM no se tocó.
# Se puede volver a correr: conserva el token y el puerto que ya tenga.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Corre esto con sudo:  sudo bash $0 <hija>" >&2; exit 1; }
HIJA="${1:-}"
[[ "$HIJA" =~ ^[a-z0-9][a-z0-9-]*$ ]] || { echo "Uso: sudo bash $0 <hija>   (ej.: siete-deseos)" >&2; exit 1; }
CRM="${CRM_DIR:-/var/www/$HIJA-crm}"
SH="$CRM/shalom-integracion"
VENV="/opt/haro-shalom/$HIJA"
SVC="haro-$HIJA-shalom.service"
UNIT="/etc/systemd/system/$SVC"
U=www-data
TS="$(date +%Y%m%d-%H%M%S)"
say() { echo "· $*"; }
trap 'echo "Falló en la línea $LINENO. Lo de arriba dice dónde." >&2' ERR
TMPS=()
limpiar() { rm -f "${TMPS[@]}" 2>/dev/null || true; }
trap limpiar EXIT

[ -f "$CRM/spark" ] && [ -f "$CRM/.env" ] || { echo "No encuentro el CRM en $CRM (usa CRM_DIR=/ruta si está en otro lado)." >&2; exit 1; }
[ -f "$SH/app/main.py" ] && [ -f "$SH/requirements.txt" ] || { echo "El CRM no trae shalom-integracion/: actualiza el CRM primero." >&2; exit 1; }

# Puerto: el que ya tenga el servicio; si no, el primero libre desde 8000.
PUERTO=""
[ -f "$UNIT" ] && PUERTO="$(grep -oE -- '--port [0-9]+' "$UNIT" | awk '{print $2}' | head -1 || true)"
if [ -z "$PUERTO" ]; then
  for p in $(seq 8000 8020); do
    if [ -z "$(ss -Hltn "sport = :$p" 2>/dev/null)" ]; then PUERTO=$p; break; fi
  done
fi
[ -n "$PUERTO" ] || { echo "No hay puerto libre entre 8000 y 8020." >&2; exit 1; }

# 1) Python de la API
if ! python3 -c 'import ensurepip' >/dev/null 2>&1; then
  say "Instalando python3-venv…"
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-venv >/dev/null
fi
mkdir -p "$VENV"
[ -x "$VENV/bin/python" ] || python3 -m venv "$VENV"
say "Instalando las librerías de la API (1 a 3 minutos)…"
"$VENV/bin/pip" install -q --no-cache-dir --disable-pip-version-check --upgrade pip >/dev/null
"$VENV/bin/pip" install -q --no-cache-dir --disable-pip-version-check -r "$SH/requirements.txt"

# 2) .env de la API: solo el token (sin cuenta de SHALOM)
if [ ! -f "$SH/.env" ]; then
  ( umask 077; printf 'API_TOKEN=%s\nPLAYWRIGHT_HEADLESS=true\nSHALOM_BASE_URL=https://pro.shalom.pe\n' "$(openssl rand -hex 24)" > "$SH/.env" )
elif ! grep -qE '^API_TOKEN=.{20,}' "$SH/.env"; then
  cp -p "$SH/.env" "$SH/.env.antes-$TS"
  printf '\nAPI_TOKEN=%s\n' "$(openssl rand -hex 24)" >> "$SH/.env"
else
  say "La API ya tenía su .env: se conserva su token."
fi
chown "$U:$U" "$SH/.env"; chmod 600 "$SH/.env"
TOK="$(sed -nE 's/^API_TOKEN=//p' "$SH/.env" | tail -1 | tr -d '\r ')"
[ ${#TOK} -ge 20 ] || { echo "No pude leer el API_TOKEN del .env de la API." >&2; exit 1; }
HDR="$(mktemp)"; TMPS+=("$HDR"); chmod 600 "$HDR"
printf 'X-Api-Token: %s\n' "$TOK" > "$HDR"

# 3) Servicio
cat > "$UNIT" <<EOF
[Unit]
Description=API de SHALOM de $HIJA (127.0.0.1:$PUERTO) — solo la usa el CRM de esta hija; puesta con poner-shalom.sh
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$U
Group=$U
WorkingDirectory=$SH
Environment=HOME=$SH
Environment=PYTHONUNBUFFERED=1
Environment=PYTHONPATH=$CRM
ExecStart=$VENV/bin/python -m uvicorn app.main:app --host 127.0.0.1 --port $PUERTO
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable "$SVC" >/dev/null 2>&1
systemctl restart "$SVC"
ok=0
for _ in $(seq 1 40); do
  if curl -fsS -m 2 "http://127.0.0.1:$PUERTO/health" >/dev/null 2>&1; then ok=1; break; fi
  sleep 1
done
if [ "$ok" != 1 ]; then
  journalctl -u "$SVC" -n 30 --no-pager >&2 || true
  echo "La API no arrancó (arriba su registro). El CRM no se tocó." >&2; exit 1
fi
N="$(curl -fsS -m 30 -H @"$HDR" "http://127.0.0.1:$PUERTO/terminals?source=local" \
     | python3 -c 'import sys,json; print(len(json.load(sys.stdin).get("Map",[])))' 2>/dev/null || echo 0)"
[ "${N:-0}" -gt 100 ] || { echo "La API arrancó pero no sirve el catálogo de agencias ($N). El CRM no se tocó." >&2; exit 1; }
say "API de SHALOM andando en 127.0.0.1:$PUERTO · catálogo de $N agencias."

# 4) Apuntar el CRM a la API
envv() { sed -nE "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$CRM/.env" | tail -1 | tr -d "\r" | sed -E "s/^['\"](.*)['\"][[:space:]]*$/\1/; s/[[:space:]]+$//"; }
DB="$(envv 'database\.default\.database')"
[ -n "$DB" ] || { echo "No encuentro database.default.database en $CRM/.env. La API quedó andando; el CRM no se tocó." >&2; exit 1; }
MY=(mysql); DUMP=(mysqldump)
if ! mysql -N -e 'SELECT 1' "$DB" >/dev/null 2>&1; then
  CNF="$(mktemp)"; TMPS+=("$CNF"); chmod 600 "$CNF"
  DP="$(envv 'database\.default\.password')"; DP="${DP//\\/\\\\}"; DP="${DP//\"/\\\"}"
  printf '[client]\nuser="%s"\npassword="%s"\nhost="%s"\n' "$(envv 'database\.default\.username')" "$DP" "$(envv 'database\.default\.hostname')" > "$CNF"
  MY=(mysql --defaults-extra-file="$CNF"); DUMP=(mysqldump --defaults-extra-file="$CNF")
fi
"${MY[@]}" -N -e 'SELECT 1 FROM system_settings LIMIT 1' "$DB" >/dev/null || { echo "No pude leer system_settings de $DB. La API quedó andando; el CRM no se tocó." >&2; exit 1; }
RESP="/var/www/$HIJA-respaldos"; [ -d "$RESP" ] || RESP=/root
( umask 077; "${DUMP[@]}" --no-create-info --skip-extended-insert --where="k LIKE '%shalom%'" "$DB" system_settings > "$RESP/shalom-ajustes-antes-$TS.sql" )
printf "INSERT INTO system_settings (company_id,k,v) VALUES (1,'shalom_use_local_api','1'),(1,'shalom_local_url','http://127.0.0.1:%s'),(1,'shalom_local_token','%s') ON DUPLICATE KEY UPDATE v=VALUES(v);\n" "$PUERTO" "$TOK" | "${MY[@]}" "$DB"
find "$CRM/writable/cache" -maxdepth 1 -name '*shalom*' -type f -delete 2>/dev/null || true
OTRAS="$("${MY[@]}" -N -e "SELECT COUNT(DISTINCT company_id) FROM system_settings WHERE company_id<>1" "$DB" 2>/dev/null || echo 0)"
say "CRM apuntado a su API (empresa 1). Ajustes de antes respaldados en $RESP/shalom-ajustes-antes-$TS.sql"
[ "${OTRAS:-0}" -gt 0 ] && say "OJO: hay $OTRAS empresa(s) más en este CRM; a esas no se les cambió SHALOM."

# 5) Llave para que la extensión suba la sesión
if grep -qE '^[[:space:]]*SHALOM_UPLOAD_KEY[[:space:]]*=[[:space:]]*[^[:space:]]{16,}' "$CRM/.env"; then
  say "La llave de subida de sesión (SHALOM_UPLOAD_KEY) ya estaba en el CRM."
else
  cp -p "$CRM/.env" "$CRM/.env.antes-shalom-$TS"
  printf '\nSHALOM_UPLOAD_KEY = %s\n' "$(openssl rand -hex 24)" >> "$CRM/.env"
  say "Puse SHALOM_UPLOAD_KEY en el CRM (respaldo: .env.antes-shalom-$TS)."
fi

# Estado final
EST="$(curl -fsS -m 30 -H @"$HDR" "http://127.0.0.1:$PUERTO/session/live" 2>/dev/null || echo '{}')"
if echo "$EST" | grep -q '"live":true'; then
  say "SHALOM: VERDE — hay sesión viva, ya puede emitir guías."
else
  say "SHALOM: ÁMBAR — las agencias ya cargan. Para emitir guías falta subir la sesión de SHALOM de la hija con la extensión."
fi
echo "LISTO: servicio $SVC activo ($(systemctl is-active "$SVC"))."
case "$0" in /home/*|/root/*) rm -f -- "$0" ;; esac
