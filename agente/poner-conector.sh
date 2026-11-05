#!/bin/bash
# Conector de Claude (MCP) para el robot de una hija instalada por la central — 4-nov-2026, pedido de Joe (SIETE DESEOS).
#
# Igual que en TECNOVA y JULBANY: una «puerta» aparte = copia del código del robot con Camila APAGADA, bandeja falsa
# y CONECTOR_SOLO=1 (lo que llega por Apache solo toca el conector). Audita y edita SOLO el BORRADOR del robot de
# verdad (CONECTOR_DATOS); nunca publica ni responde por WhatsApp (CONECTOR_RESPONDER no se pone).
#
# Uso:  sudo bash poner-conector.sh <hija> [--nombre Joe]
#   - La primera vez crea la puerta, su servicio y la ruta /claude/ del sitio del chat.
#   - Otra vez con --nombre Ana: le agrega una clave propia a Ana (una por persona; el registro dice quién cambió qué).
#   - Otra vez sin cambios: vuelve a copiar el código del robot (tras actualizar el robot) y conserva .env, claves
#     y los permisos que Claude ya tiene (datos/<hija>/conector.json).
# En Claude: Configuración → Conectores → Agregar conector personalizado → https://chat.<dominio>/claude/tecnobot/mcp
set -euo pipefail

HIJA="${1:-}"; shift || true
NOMBRE="Joe"
while [ $# -gt 0 ]; do
  case "$1" in
    --nombre) NOMBRE="${2:-}"; shift 2 ;;
    *) echo "Opción desconocida: $1" >&2; exit 2 ;;
  esac
done

log(){ printf '   %s\n' "$*"; }
aviso(){ printf '   \033[33m! %s\033[0m\n' "$*"; }
die(){ printf '\n\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "Corre esto con sudo:  sudo bash $0 <hija>"
[[ "$HIJA" =~ ^[a-z0-9-]+$ ]] || die "Falta la hija (ej.  sudo bash $0 siete-deseos)"
[[ "$NOMBRE" =~ ^[A-Za-z0-9_-]{1,30}$ ]] || die "--nombre: solo letras, números, - y _ (máx. 30)"

UR="haro-$HIJA-robot.service"; UC="haro-$HIJA-chat.service"; UP="haro-$HIJA-conector.service"
systemctl cat "$UR" >/dev/null 2>&1 || die "No encuentro el robot ($UR). ¿Se instaló con la central?"
ROBOT_DIR="$(systemctl show -p WorkingDirectory --value "$UR")"
RUN_USER="$(systemctl show -p User --value "$UR")"
NODE_BIN="$(systemctl cat "$UR" | sed -n 's/^ExecStart=\([^ ]*\).*/\1/p' | tail -1)"
CHAT_DIR="$(systemctl show -p WorkingDirectory --value "$UC" 2>/dev/null || true)"
SITIO="/etc/apache2/sites-available/haro-$HIJA-chat"
[ -f "$ROBOT_DIR/server.js" ] && [ -f "$ROBOT_DIR/lib/conector.js" ] || die "El robot de $ROBOT_DIR no trae el conector (lib/conector.js)."
[ -f "$ROBOT_DIR/.env" ] && [ -d "$ROBOT_DIR/datos/$HIJA" ] || die "Al robot le falta su .env o datos/$HIJA."
[ -n "$RUN_USER" ] && id "$RUN_USER" >/dev/null 2>&1 || die "El robot no tiene usuario (User=) en $UR."
[ -x "$NODE_BIN" ] || die "No encuentro node ($NODE_BIN)."
[ -f "$SITIO.conf" ] || die "No encuentro el sitio del chat ($SITIO.conf)."
DOMINIO="$(sed -n 's/^[[:space:]]*ServerName[[:space:]]\+\([^[:space:]]\+\).*/\1/p' "$SITIO.conf" | head -1)"
[ -n "$DOMINIO" ] || die "El sitio $SITIO.conf no tiene ServerName."

PUERTA="/var/www/$HIJA-conector"; CODIGO="$PUERTA/tecnobot"; FALSA="$PUERTA/bandeja-falsa"; ENV="$CODIGO/.env"
NUEVA=0; [ -f "$ENV" ] || NUEVA=1
UNIDAD_NUEVA=0; [ -f "/etc/systemd/system/$UP" ] || UNIDAD_NUEVA=1
TS="$(date +%Y%m%d-%H%M%S)"; RESPALDOS=()

# Si algo falla: Apache vuelve como estaba y, si la puerta era nueva, se quita entera.
al_salir(){
  local rc=$?; [ $rc = 0 ] && return 0
  printf '\n   Deshaciendo…\n' >&2
  local par
  for par in "${RESPALDOS[@]}"; do cp -p "${par%%|*}" "${par#*|}" || true; done
  if [ ${#RESPALDOS[@]} -gt 0 ]; then apache2ctl configtest >/dev/null 2>&1 && systemctl reload apache2 || true; fi
  if [ "$UNIDAD_NUEVA" = 1 ] && [ -f "/etc/systemd/system/$UP" ]; then
    systemctl disable --now "$UP" >/dev/null 2>&1 || true; rm -f "/etc/systemd/system/$UP"; systemctl daemon-reload || true
  fi
  if [ "$NUEVA" = 1 ] && [ -d "$PUERTA" ]; then rm -rf "$PUERTA"; fi
  return $rc
}
trap al_salir EXIT

puerto_libre(){ local p; for p in $(seq 3202 3299); do ss -ltnH "( sport = :$p )" | grep -q . || { echo "$p"; return 0; }; done; return 1; }
clave_nueva(){ local k=""; while [ ${#k} -lt 24 ]; do k="$k$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9')"; done; printf '%s' "${k:0:24}"; }

printf '\n\033[1mConector de Claude para el robot de «%s»\033[0m\n' "$HIJA"
log "robot $ROBOT_DIR (usuario $RUN_USER) · sitio $DOMINIO"

# 1) La puerta: código del robot (sin su .env ni sus datos), datos propios con Camila APAGADA y bandeja falsa
mkdir -p "$CODIGO" "$FALSA/datos/$HIJA"
tar -C "$ROBOT_DIR" --exclude=./datos --exclude=./.env -cf - . | tar -C "$CODIGO" -xf - --no-same-owner
mkdir -p "$CODIGO/datos/$HIJA/media" "$CODIGO/datos/.rodobot"
# Siempre apagada: es la garantía de que esta copia no conteste ni avise a nadie.
printf '{"modo":"apagada","numeros_prueba":[],"pruebas":[],"cobertura":100,"tomar_existentes":false,"silencio_humano_horas":0,"cambiado_por":"poner-conector","cambiado_en":"%s"}\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"$CODIGO/datos/$HIJA/activacion.json"
for f in publicado.json borrador.json; do
  [ -f "$CODIGO/datos/$HIJA/$f" ] || [ ! -f "$ROBOT_DIR/datos/$HIJA/publicado.json" ] || cp -p "$ROBOT_DIR/datos/$HIJA/publicado.json" "$CODIGO/datos/$HIJA/$f"
done
umask 077
[ -f "$FALSA/panel.env" ] || printf 'COOKIE_SECRET=%s\nPORT=9\n' "$(openssl rand -hex 32)" >"$FALSA/panel.env"
[ -f "$FALSA/usuarios.json" ] || printf '[]\n' >"$FALSA/usuarios.json"
[ -z "$CHAT_DIR" ] || [ -e "$FALSA/datos/$HIJA/bot_media" ] || ln -s "$CHAT_DIR/datos/$HIJA/bot_media" "$FALSA/datos/$HIJA/bot_media"

if [ "$NUEVA" = 1 ]; then
  P="$(puerto_libre)" || die "No hay un puerto libre entre 3202 y 3299."
  CLAVE="$(clave_nueva)"
  {
    printf '# Puerta del conector de Claude (MCP) del robot de «%s» — creada por poner-conector.sh el %s.\n' "$HIJA" "$(date '+%Y-%m-%d %H:%M')"
    printf '# Camila está APAGADA aquí (datos/%s/activacion.json) y la bandeja es falsa (PORT=9): esta copia no contesta ni avisa.\n' "$HIJA"
    printf '# Audita y edita SOLO el borrador del robot de verdad (CONECTOR_DATOS); nunca publica.\n'
    printf '# Una clave por persona: CONECTOR_CLAVES=Joe:xxxx,socio:yyyy (mín. 12). Quitar una y reiniciar anula sus permisos.\n'
    printf 'CUENTA=%s\nPUERTO=%s\nCONECTOR_ACTIVO=1\nCONECTOR_SOLO=1\n' "$HIJA" "$P"
    printf 'BANDEJA_DIR=%s\nBANDEJA_KEY=%s\n' "$FALSA" "$(openssl rand -hex 24)"
    printf 'CONECTOR_URL=https://%s/claude\nCONECTOR_DATOS=%s\n' "$DOMINIO" "$ROBOT_DIR/datos/$HIJA"
    printf 'CONECTOR_CLAVES=%s:%s\n' "$NOMBRE" "$CLAVE"
  } >"$ENV"
  log "puerta nueva en $PUERTA (puerto $P) con la clave de «$NOMBRE»"
else
  P="$(sed -n 's/^PUERTO=//p' "$ENV" | tail -1)"
  [[ "$P" =~ ^[0-9]+$ ]] || die "El .env de la puerta no tiene PUERTO."
  ACTUAL="$(sed -n 's/^CONECTOR_CLAVES=//p' "$ENV" | tail -1)"
  if printf ',%s' "$ACTUAL" | grep -q ",$NOMBRE:"; then
    log "puerta ya existía (puerto $P): código del robot copiado de nuevo; «$NOMBRE» ya tenía clave y no se cambia"
  else
    CLAVE="$(clave_nueva)"
    if grep -q '^CONECTOR_CLAVES=' "$ENV"; then sed -i "s|^CONECTOR_CLAVES=.*|CONECTOR_CLAVES=${ACTUAL:+$ACTUAL,}$NOMBRE:$CLAVE|" "$ENV"
    else printf 'CONECTOR_CLAVES=%s:%s\n' "$NOMBRE" "$CLAVE" >>"$ENV"; fi
    log "puerta ya existía (puerto $P): código copiado de nuevo y clave nueva para «$NOMBRE»"
  fi
fi
umask 022
chown -R "$RUN_USER:$RUN_USER" "$PUERTA"
chmod 750 "$PUERTA" "$CODIGO" "$CODIGO/datos/.rodobot"
chmod 600 "$ENV" "$FALSA/panel.env" "$FALSA/usuarios.json"

# 2) Servicio (mismo usuario que el robot: escribe en su borrador)
cat >"/etc/systemd/system/$UP" <<EOF
[Unit]
Description=Conector de Claude (MCP) del robot de $HIJA ($DOMINIO/claude) — puerta aparte con Camila apagada; edita solo el borrador
After=network.target $UR

[Service]
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$CODIGO
ExecStart=$NODE_BIN $CODIGO/server.js
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable -q "$UP"
systemctl restart "$UP"
ok=0
for _ in $(seq 1 40); do
  curl -fsS -m 3 "http://127.0.0.1:$P/.well-known/oauth-protected-resource/tecnobot/mcp" 2>/dev/null | grep -q '"resource"' && { ok=1; break; }
  sleep 1
done
[ "$ok" = 1 ] || die "La puerta no responde en el puerto $P (ver: journalctl -u $UP -n 50)."
log "servicio $UP corriendo en 127.0.0.1:$P"

# 3) Apache: /claude/ y sus /.well-known (OAuth) en el sitio del chat, ANTES de «ProxyPass /» (gana el primero que coincide)
BLOQUE="    # Conector de Claude (MCP), $(date +%F): puerta aparte $UP en :$P. Va ANTES de «ProxyPass /».
    ProxyPass        /claude/ http://127.0.0.1:$P/ timeout=90
    ProxyPassReverse /claude/ http://127.0.0.1:$P/
    ProxyPass        /.well-known/oauth-authorization-server/claude/ http://127.0.0.1:$P/.well-known/oauth-authorization-server/
    ProxyPass        /.well-known/openid-configuration/claude/ http://127.0.0.1:$P/.well-known/openid-configuration/
    ProxyPass        /.well-known/oauth-protected-resource/claude/ http://127.0.0.1:$P/.well-known/oauth-protected-resource/"
mkdir -p "$PUERTA/respaldo-apache"
cambio=0
for conf in "$SITIO.conf" "$SITIO-le-ssl.conf"; do
  [ -f "$conf" ] || continue
  if grep -q '^[[:space:]]*ProxyPass[[:space:]]\+/claude/' "$conf"; then
    grep -q "127.0.0.1:$P/" <(grep 'claude/' "$conf") && continue
    r="$PUERTA/respaldo-apache/$(basename "$conf").$TS"; cp -p "$conf" "$r"; RESPALDOS+=("$r|$conf")
    sed -i "/claude\//s|127\.0\.0\.1:[0-9]\+/|127.0.0.1:$P/|" "$conf"; cambio=1
    continue
  fi
  grep -q '^[[:space:]]*ProxyPass[[:space:]]\+/[[:space:]]' "$conf" || die "No encuentro «ProxyPass /» en $conf."
  r="$PUERTA/respaldo-apache/$(basename "$conf").$TS"; cp -p "$conf" "$r"; RESPALDOS+=("$r|$conf")
  BLOQUE="$BLOQUE" awk 'BEGIN{b=ENVIRON["BLOQUE"]} !h && /^[[:space:]]*ProxyPass[[:space:]]+\/[[:space:]]/ {print b; h=1} {print}' "$r" >"$conf"
  cambio=1
done
if [ "$cambio" = 1 ]; then
  apache2ctl configtest >/dev/null 2>&1 || die "Apache no aceptó la ruta /claude/ (se deja como estaba)."
  systemctl reload apache2
  log "ruta /claude/ agregada al sitio del chat"
else
  log "la ruta /claude/ ya estaba en el sitio del chat"
fi

# 4) ¿Llega por Apache? (local, por el nombre del sitio) y ¿por la dirección pública?
RUTA="/.well-known/oauth-protected-resource/claude/tecnobot/mcp"
ok=0
for _ in 1 2 3 4 5; do
  curl -sS -m 5 -H "Host: $DOMINIO" "http://127.0.0.1$RUTA" 2>/dev/null | grep -q '"resource"' && { ok=1; break; }
  curl -sSk -m 5 --resolve "$DOMINIO:443:127.0.0.1" "https://$DOMINIO$RUTA" 2>/dev/null | grep -q '"resource"' && { ok=1; break; }
  sleep 1
done
[ "$ok" = 1 ] || die "Apache no entrega la ruta del conector ($RUTA)."
if curl -sS -m 15 "https://$DOMINIO$RUTA" 2>/dev/null | grep -q "\"https://$DOMINIO/claude/tecnobot/mcp\""; then
  log "https://$DOMINIO/claude/ responde desde afuera"
else
  aviso "Desde aquí no pude comprobar https://$DOMINIO$RUTA (puede ser el proxy del proveedor). Ábrela en tu navegador: debe mostrar un JSON."
fi
systemctl is-active -q "$UR" || aviso "OJO: el robot ($UR) no está activo; el conector igual edita su borrador."

trap - EXIT
printf '\n\033[32mListo: conector de Claude para «%s».\033[0m\n' "$HIJA"
printf '  En Claude: Configuración → Conectores → Agregar conector personalizado\n'
printf '    URL:   https://%s/claude/tecnobot/mcp\n' "$DOMINIO"
printf '  Al pulsar Conectar pide la clave de «%s». Para verla (no la pegues en ningún chat):\n' "$NOMBRE"
printf "    sudo grep '^CONECTOR_CLAVES' %s\n" "$ENV"
printf '  Servicio: %s (puerto %s) · Camila apagada en la puerta · solo edita el BORRADOR; se publica en el panel.\n' "$UP" "$P"
printf '  Otra persona con su propia clave:  sudo bash %s %s --nombre Ana\n' "$0" "$HIJA"
printf '  Tras actualizar el robot, corre esto otra vez para que la puerta tenga el mismo código.\n'
