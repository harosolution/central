#!/usr/bin/env bash
# =============================================================================
#  INSTALADOR DE UNA LÍNEA · HARO CRM  (fase 6 de la Central de hijas, 4-nov-2026)
#
#  La central lo arma para UNA hija, con un código de un solo uso que vale 48 h:
#      curl -fsSL __CENTRAL__/instalar/<código> | sudo bash
#  Con opciones:
#      curl -fsSL __CENTRAL__/instalar/<código> | sudo bash -s -- --sin-ssl
#
#    --carpeta DIR    dónde va el CRM (por defecto /var/www/<hija>-crm)
#    --sin-paquetes   no instala nada con apt: si falta algo, se detiene y dice qué
#    --sin-apache     no crea el sitio web (la prueba usa el servidor de PHP)
#    --sin-ssl        no pide el certificado (queda por http)
#    --sin-chat       solo el CRM, aunque el instalador traiga Chat y Robot
#
#  Qué hace: revisa el servidor → canjea el código (la central le da a la hija su llave) → baja el CRM y comprueba
#  la firma de la madre → base y usuario propios (estructura + roles y catálogos genéricos) → .env con secretos
#  NUEVOS → administrador → sitio en Apache (+ SSL si el dominio ya apunta aquí) → (si se pidió) Chat en
#  chat.<dominio> y Robot en chat.<dominio>/tecnobot/, enlazados entre sí y con el CRM → agente con latido cada 10 min.
#  NUNCA trae datos de otra empresa: ni ventas, ni clientes, ni llaves de IA, ni sesión de WhatsApp, ni píxel.
#  El Robot nace APAGADO y sin key de IA; el Chat, sin WhatsApp vinculado (se escanea el QR en chat.<dominio>/admin).
#  No pisa nada: si la carpeta, la base o el sitio ya existen, se detiene sin tocar. Si algo falla a la mitad,
#  deshace lo que creó y se puede volver a pegar el mismo comando.
#  Necesita Ubuntu o Debian con sudo. Sirve igual en un servidor que ya tiene otras hijas.
# =============================================================================
set -Eeuo pipefail
umask 022

CENTRAL='__CENTRAL__'
CODIGO='__CODIGO__'
HIJA='__HIJA__'
DOMINIO='__DOMINIO__'
VERSION='__VERSION__'
LLAVE_PUBLICA='__LLAVE_PUBLICA__'
AGENTE_DIR='/opt/haro-agente'
WEB_USER='www-data'

CARPETA=''; SIN_PAQ=0; SIN_APACHE=0; SIN_SSL=0
DB=''; DBPASS=''; SITIO=''; LLAVE=''; TMP=''; LOG='/dev/null'; MOTIVO=''; TERMINADO=0; CLAVE=''
CREADO_DIR=0; CREADO_DB=0; CREADO_SITIO=0; CREADO_AGENTE=0; CREADO_AGENTE_DIR=0; CREADO_CRON=0
EMPRESA=''; ADMIN_NOMBRE=''; ADMIN_EMAIL=''; IP_VISTA=''; ESQUEMA='http'
# PHP: el CRM pide 8.2 o más (public/index.php). Si el sistema trae uno viejo (Ubuntu 22.04 = 8.1), se pone el 8.3.
PHP_MIN='8.2'; PHP_NUEVO='8.3'; PHP_PV=''; PHP_PPA=0
# Chat y Robot (fase 6b). Node 20 = el de la madre: los módulos nativos del paquete (better-sqlite3) van compilados
# para él. Si el servidor no tiene Node 20, se pone el oficial de nodejs.org en /opt/haro-node (no toca otro Node).
CON_CHAT='__CON_CHAT__'; DOMINIO_CHAT='__DOMINIO_CHAT__'; VERSION_CHAT='__VERSION_CHAT__'; VERSION_ROBOT='__VERSION_ROBOT__'
NODE_VER='20.20.2'; NODE_BIN=''; RUN_USER='haro'; TIENDA_IA_URL='https://streamy.website/v2'
CHAT_DIR=''; ROBOT_DIR=''; P_CHAT=''; P_ROBOT=''; SITIO_CHAT=''; UNIDAD_CHAT=''; UNIDAD_ROBOT=''
NOMBRE_ROBOT=''; ESQUEMA_CHAT='http'; BOTKEY=''; CRM_KEY=''
CREADO_CHAT=0; CREADO_ROBOT=0; CREADO_SITIO_CHAT=0; CREADO_UNIDADES=0
# Fase 6c: MODO=agregar = poner Chat y Robot a una hija YA instalada (su CRM no se toca, salvo el enlace con el chat).
MODO='__MODO__'; DBUSER=''; ENV_RESPALDO=''; JSON_RESPALDO=''

paso(){ printf '\n\033[1m%s\033[0m\n' "$*"; printf '\n== %s\n' "$*" >>"$LOG"; }
log(){ printf '   %s\n' "$*"; printf '%s %s\n' "$(date +%T)" "$*" >>"$LOG"; }
aviso(){ printf '   \033[33m⚠ %s\033[0m\n' "$*"; printf '%s AVISO %s\n' "$(date +%T)" "$*" >>"$LOG"; }
die(){ MOTIVO="$*"; printf '\n\033[31mERROR: %s\033[0m\n' "$*" >&2; printf '%s ERROR %s\n' "$(date +%T)" "$*" >>"$LOG" 2>/dev/null || true; exit 1; }

# Solo para probar que deshace todo: HARO_INSTALAR_FALLAR_EN=salud|fin hace fallar a propósito en ese punto.
probar_fallo(){ [ "${HARO_INSTALAR_FALLAR_EN:-}" != "$1" ] || die "Falla de prueba en «$1» (HARO_INSTALAR_FALLAR_EN)."; }

# Un campo de una respuesta JSON (texto; true → 1).
jcampo(){ printf '%s' "$1" | php -r '$j = json_decode(stream_get_contents(STDIN), true); $v = is_array($j) ? ($j[$argv[1]] ?? "") : ""; echo is_bool($v) ? ($v ? "1" : "0") : (is_scalar($v) ? $v : "");' "$2"; }

# Le cuenta a la central cómo terminó (con la llave recién canjeada).
reportar(){
  [ -n "$LLAVE" ] || return 0
  local cuerpo
  cuerpo="$(OK="$1" MSG="$2" VER="$VERSION" php -r 'echo json_encode(["ok" => getenv("OK") === "true", "mensaje" => mb_substr((string) getenv("MSG"), 0, 480), "version" => getenv("VER")], JSON_UNESCAPED_UNICODE);' </dev/null 2>/dev/null || true)"
  curl -sS -m 20 -o /dev/null -X POST -H 'Content-Type: application/json' -H "Authorization: Bearer $LLAVE" \
       --data "$cuerpo" "$CENTRAL/api/instalacion" </dev/null >/dev/null 2>&1 || true
}

# Antes de deshacer: la página que respondió, los registros del CRM y de Apache, y las versiones (sin claves).
guardar_diagnostico(){
  local d="${LOG%.log}-diagnostico"
  [ "$LOG" != /dev/null ] || return 0
  [ ! -d "$d" ] || return 0
  mkdir -p "$d" 2>/dev/null || return 0
  [ -z "$TMP" ] || cp "$TMP/salud.html" "$d/pagina.html" 2>/dev/null || true
  [ -z "$CARPETA" ] || cp -r "$CARPETA/writable/logs" "$d/crm-logs" 2>/dev/null || true
  [ -z "$SITIO" ] || tail -n 80 "/var/log/apache2/$SITIO-error.log" >"$d/apache-error.log" 2>/dev/null || true
  { echo "php consola: $(php_cli)"; echo "php apache: $(php_apache)"; echo "node: ${NODE_BIN:-ninguno}"; apache2ctl -M 2>/dev/null | grep -iE 'php|mpm|rewrite|fcgi|proxy'; } >"$d/versiones.txt" 2>/dev/null || true
  [ -z "$UNIDAD_CHAT" ] || journalctl -u "$UNIDAD_CHAT" --no-pager -n 60 >"$d/chat.log" 2>/dev/null || true
  [ -z "$UNIDAD_ROBOT" ] || journalctl -u "$UNIDAD_ROBOT" --no-pager -n 60 >"$d/robot.log" 2>/dev/null || true
  [ -z "$SITIO_CHAT" ] || tail -n 60 "/var/log/apache2/$SITIO_CHAT-error.log" >"$d/apache-chat-error.log" 2>/dev/null || true
  chmod -R go-rwx "$d" 2>/dev/null || true
}

# Si algo falla: se deshace TODO lo que este instalador creó (y nada más).
al_salir(){
  local rc=$?
  if [ "$rc" -eq 0 ] || [ "$TERMINADO" = 1 ]; then [ -z "$TMP" ] || rm -rf "$TMP"; return 0; fi
  trap - ERR
  set +e
  [ -n "$MOTIVO" ] || MOTIVO="falló un paso (código $rc)"
  { [ "$CREADO_DIR" = 1 ] || [ "$CREADO_CHAT" = 1 ]; } && guardar_diagnostico
  printf '\nDeshaciendo lo que alcancé a crear…\n'
  # Agregar Chat y Robot: el CRM vuelve EXACTO a como estaba (su .env y los dos ajustes del chat), y el agente también.
  if [ -n "$ENV_RESPALDO" ] && [ -f "$ENV_RESPALDO" ]; then
    cat "$ENV_RESPALDO" >"$CARPETA/.env" && rm -f "$ENV_RESPALDO" && echo "   .env del CRM devuelto a como estaba"
  fi
  if [ -n "$TMP" ] && [ -f "$TMP/ajustes-antes.json" ]; then
    DB="$DB" ARCH="$TMP/ajustes-antes.json" php -r '
      $a = json_decode(file_get_contents(getenv("ARCH")), true) ?: [];
      $m = new mysqli("localhost", "root", "", getenv("DB"));
      foreach (["chatbot_admin_url", "chatbot_sso_secret"] as $k) {
        $kk = $m->real_escape_string($k);
        if (array_key_exists($k, $a)) { $vv = $m->real_escape_string((string) $a[$k]); $m->query("UPDATE system_settings SET v = \x27$vv\x27 WHERE company_id = 1 AND k = \x27$kk\x27"); }
        else { $m->query("DELETE FROM system_settings WHERE company_id = 1 AND k = \x27$kk\x27"); }
      }' </dev/null >/dev/null 2>&1 && echo "   ajustes del chat en el CRM devueltos"
  fi
  if [ -n "$JSON_RESPALDO" ] && [ -f "$JSON_RESPALDO" ]; then
    cat "$JSON_RESPALDO" >"$AGENTE_DIR/hijas/$HIJA.json" && rm -f "$JSON_RESPALDO"
  fi
  if [ "$CREADO_UNIDADES" = 1 ]; then
    systemctl disable --now "$UNIDAD_CHAT" "$UNIDAD_ROBOT" >/dev/null 2>&1
    rm -f "/etc/systemd/system/$UNIDAD_CHAT.service" "/etc/systemd/system/$UNIDAD_ROBOT.service"
    systemctl daemon-reload >/dev/null 2>&1
    echo "   servicios $UNIDAD_CHAT y $UNIDAD_ROBOT quitados"
  fi
  if [ "$CREADO_SITIO_CHAT" = 1 ]; then
    a2dissite -q "$SITIO_CHAT" "$SITIO_CHAT-le-ssl" >/dev/null 2>&1
    rm -f "/etc/apache2/sites-available/$SITIO_CHAT.conf" "/etc/apache2/sites-available/$SITIO_CHAT-le-ssl.conf"
    echo "   sitio del chat $SITIO_CHAT quitado"
  fi
  [ "$CREADO_CHAT" = 1 ] && [ -n "$CHAT_DIR" ] && rm -rf --one-file-system "$CHAT_DIR" && echo "   carpeta $CHAT_DIR borrada"
  [ "$CREADO_ROBOT" = 1 ] && [ -n "$ROBOT_DIR" ] && rm -rf --one-file-system "$ROBOT_DIR" && echo "   carpeta $ROBOT_DIR borrada"
  if [ "$CREADO_SITIO" = 1 ]; then
    a2dissite -q "$SITIO" "$SITIO-le-ssl" >/dev/null 2>&1
    rm -f "/etc/apache2/sites-available/$SITIO.conf" "/etc/apache2/sites-available/$SITIO-le-ssl.conf"
    apache2ctl configtest >/dev/null 2>&1 && systemctl reload apache2 >/dev/null 2>&1
    echo "   sitio web $SITIO quitado"
  fi
  if [ "$CREADO_DB" = 1 ]; then
    mysql -e "DROP DATABASE IF EXISTS \`$DB\`; DROP USER IF EXISTS '$DB'@'localhost';" </dev/null >/dev/null 2>&1
    echo "   base $DB borrada"
  fi
  if [ "$CREADO_DIR" = 1 ] && [ -n "$CARPETA" ] && [ "$CARPETA" != "/" ]; then
    rm -rf --one-file-system "$CARPETA"
    echo "   carpeta $CARPETA borrada"
  fi
  [ "$CREADO_AGENTE" = 1 ] && rm -f "$AGENTE_DIR/hijas/$HIJA.json"
  [ "$CREADO_CRON" = 1 ] && rm -f /etc/cron.d/haro-agente
  [ "$CREADO_AGENTE_DIR" = 1 ] && rm -rf "$AGENTE_DIR"
  [ -z "$TMP" ] || rm -rf "$TMP"
  reportar false "$MOTIVO"
  printf '\nNo quedó nada a medias. Arregla lo de arriba y vuelve a pegar el MISMO comando (el código sigue sirviendo).\nDetalle: %s\n' "$LOG"
}

# Nombre del paquete apt (con PHP nuevo: php8.3-…).
apt_nombre(){
  local p="$1"
  case "$p" in php-mysqli) p=php-mysql;; php-dom) p=php-xml;; esac
  if [ -n "$PHP_PV" ]; then
    case "$p" in libapache2-mod-php) p="libapache2-mod-php$PHP_PV";; php-*) p="php$PHP_PV-${p#php-}";; esac
  fi
  echo "$p"
}
# ¿$1 >= $2 (por defecto PHP_MIN)? Sin depender de PHP.
ver_ok(){ local v="$1" min="${2:-$PHP_MIN}"; [ -n "$v" ] && [ "$(printf '%s\n%s\n' "$min" "$v" | sort -V | head -1)" = "$min" ]; }
php_cli(){ php -r 'echo PHP_VERSION;' </dev/null 2>/dev/null || true; }
php_apache(){ ls /etc/apache2/mods-enabled/ 2>/dev/null | sed -nE 's/^php([0-9]+\.[0-9]+)\.load$/\1/p' | sort -V | tail -1 || true; }

# PHP viejo: en Ubuntu < 24.04 se pone el 8.3 del repositorio ondrej/php (el de siempre para Ubuntu; tecnovaplus lo
# usa). Nunca en un servidor con otros sitios: cambiar PHP los afectaría.
plan_php(){
  local id='' ver='' otros actual
  actual="$(php_cli)"; actual="${actual:-ninguno}"
  if [ -r /etc/os-release ]; then id="$(. /etc/os-release; echo "${ID:-}")"; ver="$(. /etc/os-release; echo "${VERSION_ID:-}")"; fi
  if [ "$SIN_APACHE" = 0 ] && [ -d /etc/apache2/sites-enabled ]; then
    otros="$(ls /etc/apache2/sites-enabled 2>/dev/null | grep -vE '^(000-default|default-ssl)\.conf$' | tr '\n' ' ' || true)"
    [ -z "${otros// /}" ] || die "El CRM necesita PHP $PHP_MIN o más y este servidor tiene PHP $actual con otros sitios (${otros% }). Cambiar PHP los afectaría: actualízalo a mano o usa un servidor limpio."
  fi
  case "$id" in
    ubuntu) if ver_ok "$ver" 24.04; then PHP_PV=''; else PHP_PV="$PHP_NUEVO"; PHP_PPA=1; fi;;
    debian) ver_ok "$ver" 12 || die "Debian $ver trae PHP viejo: usa Debian 12 o Ubuntu 24.04, o instala PHP $PHP_MIN+ a mano."; PHP_PV='';;
    *) die "El CRM necesita PHP $PHP_MIN o más (este servidor: $actual). Instálalo y vuelve a pegar el comando.";;
  esac
  log "PHP $actual es viejo para el CRM (pide $PHP_MIN o más): pondré PHP ${PHP_PV:-del sistema}$([ "$PHP_PPA" = 1 ] && echo ' desde el repositorio ondrej/php')."
}

# Deja el PHP nuevo como el de la consola y el de Apache.
cambiar_php(){
  [ -n "$PHP_PV" ] || return 0
  local m
  update-alternatives --set php "/usr/bin/php$PHP_PV" >>"$LOG" 2>&1 || true
  if [ "$SIN_APACHE" = 0 ] && command -v a2enmod >/dev/null 2>&1; then
    for m in $(ls /etc/apache2/mods-enabled/ 2>/dev/null | sed -nE 's/^(php[0-9]+\.[0-9]+)\.load$/\1/p'); do
      [ "$m" = "php$PHP_PV" ] || a2dismod -q "$m" >>"$LOG" 2>&1 || true
    done
    a2dismod -q mpm_event mpm_worker >>"$LOG" 2>&1 || true
    a2enmod -q mpm_prefork "php$PHP_PV" >>"$LOG" 2>&1 || die "No pude activar PHP $PHP_PV en Apache (ver $LOG)."
    systemctl restart apache2 </dev/null >>"$LOG" 2>&1 || die "Apache no arrancó con PHP $PHP_PV (ver $LOG)."
  fi
  log "PHP $PHP_PV activo$([ "$SIN_APACHE" = 1 ] || echo ' en la consola y en Apache')"
}

requisitos(){
  local segunda="${1:-0}" faltan=() m mods salida apv php_viejo=0
  [ "$segunda" = 1 ] || paso "1/9  Revisando el servidor"
  command -v curl >/dev/null 2>&1    || faltan+=(curl)
  command -v openssl >/dev/null 2>&1 || faltan+=(openssl)
  command -v tar >/dev/null 2>&1     || faltan+=(tar)
  command -v gzip >/dev/null 2>&1    || faltan+=(gzip)
  if command -v php >/dev/null 2>&1 && ver_ok "$(php_cli)"; then
    mods="$(php -m 2>/dev/null </dev/null | tr 'A-Z' 'a-z')"
    for m in mysqli mbstring intl curl dom zip gd; do
      grep -qx "$m" <<<"$mods" || faltan+=("php-$m")
    done
    for m in sodium json openssl fileinfo; do
      grep -qx "$m" <<<"$mods" || die "A PHP le falta la extensión «$m», que viene con PHP: reinstala php-cli."
    done
  else
    php_viejo=1
  fi
  if [ "$SIN_APACHE" = 0 ]; then
    apv="$(php_apache)"
    if [ -n "$apv" ] && ! ver_ok "$apv"; then php_viejo=1; fi
  fi
  if [ "$php_viejo" = 1 ]; then
    [ "$segunda" = 0 ] || die "PHP sigue siendo menor que $PHP_MIN (consola $(php_cli), Apache $(php_apache)) después de instalar (ver $LOG)."
    plan_php
    faltan+=(php-cli php-mysqli php-mbstring php-intl php-curl php-dom php-zip php-gd)
    [ "$SIN_APACHE" = 1 ] || faltan+=(libapache2-mod-php)
  fi
  if ! command -v mysql >/dev/null 2>&1; then
    faltan+=(mariadb-server mariadb-client)
  elif ! mysql -N -e 'SELECT 1' </dev/null >/dev/null 2>&1; then
    salida="$(systemctl list-unit-files 2>/dev/null || true)"
    if grep -qE '^(mariadb|mysql)\.service' <<<"$salida"; then
      die "No puedo entrar a la base de datos como root. Si root tiene clave:  curl -fsSL … | sudo MYSQL_PWD='la-clave' bash"
    fi
    faltan+=(mariadb-server)
  fi
  if [ "$SIN_APACHE" = 0 ]; then
    if ! command -v apache2ctl >/dev/null 2>&1; then
      systemctl is-active --quiet nginx 2>/dev/null && die "Este servidor usa nginx: corre con --sin-apache y configura el sitio a mano."
      faltan+=(apache2 libapache2-mod-php)
    else
      salida="$(apache2ctl -M 2>/dev/null || true)"
      grep -qE 'php[0-9._]*_module|proxy_fcgi_module' <<<"$salida" || faltan+=(libapache2-mod-php)
    fi
  fi
  if [ "$CON_CHAT" = 1 ]; then
    [ "$(uname -m)" = x86_64 ] || die "El Chat y el Robot necesitan un servidor x86_64 (este es $(uname -m)). Para solo el CRM: --sin-chat."
    local glibc
    glibc="$(ldd --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+$' || true)"
    ver_ok "$glibc" 2.34 || die "El Chat necesita glibc 2.34 o más (Ubuntu 22.04+, Debian 12+); este servidor tiene ${glibc:-?}. Para solo el CRM: --sin-chat."
    command -v xz >/dev/null 2>&1 || faltan+=(xz-utils)
  fi
  local libre minimo=1500
  [ "$CON_CHAT" = 1 ] && minimo=3000
  libre="$(df -Pm /var 2>/dev/null | awk 'NR==2 {print $4}')"
  [ "${libre:-0}" -ge "$minimo" ] || die "Hay poco disco libre en /var (${libre:-?} MB): hacen falta al menos $minimo MB."

  if [ ${#faltan[@]} -gt 0 ]; then
    [ "$segunda" = 0 ] || die "Sigue faltando: ${faltan[*]} (ver $LOG)."
    local pk=() f
    for f in "${faltan[@]}"; do pk+=("$(apt_nombre "$f")"); done
    mapfile -t pk < <(printf '%s\n' "${pk[@]}" | sort -u)
    if [ "$SIN_PAQ" = 1 ] || ! command -v apt-get >/dev/null 2>&1; then
      die "Faltan: ${pk[*]}. Instálalos y vuelve a pegar el comando."
    fi
    log "Instalando lo que falta: ${pk[*]} (unos minutos)…"
    if [ "$PHP_PPA" = 1 ]; then
      DEBIAN_FRONTEND=noninteractive apt-get install -y -qq software-properties-common </dev/null >>"$LOG" 2>&1 || die "No pude instalar software-properties-common (ver $LOG)."
      add-apt-repository -y ppa:ondrej/php </dev/null >>"$LOG" 2>&1 || die "No pude agregar el repositorio de PHP ondrej/php (ver $LOG)."
    fi
    DEBIAN_FRONTEND=noninteractive apt-get update -qq </dev/null >>"$LOG" 2>&1 || die "apt-get update falló (ver $LOG)."
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${pk[@]}" </dev/null >>"$LOG" 2>&1 || die "No se pudo instalar: ${pk[*]} (ver $LOG)."
    systemctl enable --now mariadb </dev/null >>"$LOG" 2>&1 || true
    [ "$SIN_APACHE" = 1 ] || systemctl enable --now apache2 </dev/null >>"$LOG" 2>&1 || true
    cambiar_php
    requisitos 1
    return 0
  fi
  id "$WEB_USER" >/dev/null 2>&1 || die "No existe el usuario $WEB_USER (el de Apache)."
  local ver_db
  ver_db="$(mysql -N -e 'SELECT VERSION()' </dev/null 2>/dev/null || echo '?')"
  log "PHP $(php -r 'echo PHP_VERSION;' </dev/null) · base de datos $ver_db · ${libre} MB libres$([ "$SIN_APACHE" = 1 ] || echo ' · Apache')"
}

nombres(){
  paso "2/9  Revisando que no exista nada con el mismo nombre"
  [[ "$HIJA" =~ ^[a-z0-9][a-z0-9-]{1,23}$ ]] || die "Nombre de hija inválido: $HIJA"
  [[ "$DOMINIO" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "Dominio inválido: $DOMINIO"
  [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Versión inválida: $VERSION"
  [ -n "$CARPETA" ] || CARPETA="/var/www/$HIJA-crm"
  [[ "$CARPETA" = /* ]] || die "--carpeta debe ser una ruta completa (que empiece con /)."
  CARPETA="${CARPETA%/}"
  DB="haro_${HIJA//-/_}"
  SITIO="haro-$HIJA"
  [ ! -e "$CARPETA" ] || die "Ya existe $CARPETA. No piso nada: usa --carpeta con otra ruta."
  [ -z "$(mysql -N -e "SHOW DATABASES LIKE '$DB'" </dev/null)" ] || die "Ya existe la base $DB."
  [ -z "$(mysql -N -e "SELECT 1 FROM mysql.user WHERE user = '$DB'" </dev/null)" ] || die "Ya existe el usuario de base $DB."
  if [ "$SIN_APACHE" = 0 ]; then
    [ ! -e "/etc/apache2/sites-available/$SITIO.conf" ] || die "Ya existe el sitio /etc/apache2/sites-available/$SITIO.conf."
    local vh
    vh="$(apache2ctl -S 2>/dev/null || true)"
    ! grep -qiE "(namevhost|alias) ${DOMINIO//./\\.}( |$)" <<<"$vh" || die "Apache ya tiene un sitio con el dominio $DOMINIO."
  fi
  [ ! -e "$AGENTE_DIR/hijas/$HIJA.json" ] || die "Ya hay un agente para «$HIJA» en $AGENTE_DIR."
  log "carpeta $CARPETA · base $DB · sitio $SITIO"
  [ "$CON_CHAT" = 1 ] && nombres_chat
  return 0
}

# Que no exista nada del Chat y el Robot de esta hija; elige sus puertos.
nombres_chat(){
  local x vh
  [[ "$DOMINIO_CHAT" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] && [ "$DOMINIO_CHAT" != "$DOMINIO" ] || die "Dominio del chat inválido: $DOMINIO_CHAT"
  [[ "$VERSION_CHAT" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && [[ "$VERSION_ROBOT" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Versiones del Chat o del Robot inválidas."
  CHAT_DIR="/var/www/$HIJA-chat"; ROBOT_DIR="/var/www/$HIJA-robot"
  UNIDAD_CHAT="haro-$HIJA-chat"; UNIDAD_ROBOT="haro-$HIJA-robot"; SITIO_CHAT="haro-$HIJA-chat"
  for x in "$CHAT_DIR" "$ROBOT_DIR" "/etc/systemd/system/$UNIDAD_CHAT.service" "/etc/systemd/system/$UNIDAD_ROBOT.service" \
           "/etc/apache2/sites-available/$SITIO_CHAT.conf"; do
    [ ! -e "$x" ] || die "Ya existe $x. No piso nada."
  done
  vh="$(apache2ctl -S 2>/dev/null || true)"
  ! grep -qiE "(namevhost|alias) ${DOMINIO_CHAT//./\\.}( |$)" <<<"$vh" || die "Apache ya tiene un sitio con el dominio $DOMINIO_CHAT."
  P_CHAT="$(puerto_libre 3200)"
  P_ROBOT="$(puerto_libre $((P_CHAT + 1)) "$P_CHAT")"
  log "chat $CHAT_DIR (puerto $P_CHAT) · robot $ROBOT_DIR (puerto $P_ROBOT) · sitio $SITIO_CHAT para $DOMINIO_CHAT"
}

# Primer puerto libre desde $1 (sin repetir $2), mirando lo que escucha y lo que ya tienen asignado las otras hijas.
puerto_libre(){
  local p="$1" usados
  usados="$( { ss -Htln 2>/dev/null | awk '{print $4}' | sed -E 's/.*:([0-9]+)$/\1/' || true
              grep -hsE '^(PORT|PUERTO)=' /var/www/*/panel.env /var/www/*/.env /var/www/*/datos/*/cuenta.env | sed -E 's/^[^=]*=//' || true; } | sort -u || true)"
  while grep -qx "$p" <<<"$usados" || [ "$p" = "${2:-}" ]; do p=$((p + 1)); done
  echo "$p"
}

canjear(){
  paso "3/9  Pidiendo a la central la llave de esta hija"
  local host resp code v
  host="$(hostname -f 2>/dev/null || hostname)"
  host="$(printf '%s' "$host" | tr -cd 'A-Za-z0-9._-')"
  resp="$(curl -sS -m 30 -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' \
          --data "{\"codigo\":\"$CODIGO\",\"servidor\":\"$host\"}" "$CENTRAL/api/instalar" </dev/null 2>>"$LOG")" \
    || die "No pude conectar con la central ($CENTRAL). ¿Este servidor tiene salida a internet?"
  code="${resp##*$'\n'}"
  resp="${resp%$'\n'*}"
  if [ "$code" != 200 ] || [ "$(jcampo "$resp" ok)" != 1 ]; then
    die "La central no aceptó el código: $(jcampo "$resp" error) (HTTP $code)"
  fi
  LLAVE="$(jcampo "$resp" llave)"
  [[ "$LLAVE" =~ ^hc_[0-9a-f]{48}$ ]] || { LLAVE=''; die "La central respondió sin llave."; }
  [ "$(jcampo "$resp" hija)" = "$HIJA" ] || die "La central respondió con otra hija."
  v="$(jcampo "$resp" version)"
  [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && VERSION="$v"
  EMPRESA="$(jcampo "$resp" empresa)"
  ADMIN_NOMBRE="$(jcampo "$resp" admin_nombre)"
  ADMIN_EMAIL="$(jcampo "$resp" admin_email)"
  IP_VISTA="$(jcampo "$resp" ip)"
  [ -n "$EMPRESA" ] && [ -n "$ADMIN_EMAIL" ] || die "La central no mandó la empresa o el administrador."
  if [ "$CON_CHAT" = 1 ]; then
    if [ "$(jcampo "$resp" con_chat)" != 1 ]; then
      CON_CHAT=0
    else
      NOMBRE_ROBOT="$(jcampo "$resp" nombre_robot)"; NOMBRE_ROBOT="${NOMBRE_ROBOT:-Asistente}"
      v="$(jcampo "$resp" version_chat)"; [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && VERSION_CHAT="$v"
      v="$(jcampo "$resp" version_robot)"; [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && VERSION_ROBOT="$v"
    fi
  fi
  log "$(jcampo "$resp" nombre) · empresa «$EMPRESA» · CRM $VERSION$([ "$CON_CHAT" = 1 ] && echo " · Chat $VERSION_CHAT · Robot $VERSION_ROBOT («$NOMBRE_ROBOT»)") · la central ve a este servidor como ${IP_VISTA:-?}"
}

# Baja un paquete firmado de la central y comprueba sha256 + firma ed25519 de la madre. $1 pieza, $2 versión, $3 archivo.
bajar_pieza(){
  local pieza="$1" ver="$2" dst="$3" http sha firma
  curl -sS -m 1800 -D "$TMP/cab-$pieza" -o "$dst" -H "Authorization: Bearer $LLAVE" \
       "$CENTRAL/api/paquete?pieza=$pieza&ver=$ver" </dev/null 2>>"$LOG" || die "No pude bajar el paquete del $pieza."
  http="$(awk 'toupper($1) ~ /^HTTP\// {c = $2} END {print c}' "$TMP/cab-$pieza")"
  [ "$http" = 200 ] || die "La central no entregó el paquete del $pieza (HTTP $http)."
  sha="$(tr -d '\r' <"$TMP/cab-$pieza" | awk 'tolower($1) == "x-sha256:" {print tolower($2)}' | tail -1)"
  firma="$(tr -d '\r' <"$TMP/cab-$pieza" | awk 'tolower($1) == "x-firma:" {print tolower($2)}' | tail -1)"
  php -r '
    $sha = hash_file("sha256", $argv[1]);
    if (! preg_match("/^[0-9a-f]{64}$/", $argv[2]) || ! hash_equals($argv[2], $sha)) { exit(2); }
    if (! preg_match("/^[0-9a-f]{128}$/", $argv[3])) { exit(3); }
    exit(sodium_crypto_sign_verify_detached(sodium_hex2bin($argv[3]), $sha, sodium_hex2bin($argv[4])) ? 0 : 3);
  ' "$dst" "$sha" "$firma" "$LLAVE_PUBLICA" </dev/null \
    || die "FIRMA INVÁLIDA o paquete dañado ($pieza): no lo firmó la madre. No instalo nada."
  log "$pieza $ver: $(du -m "$dst" | cut -f1) MB · sha256 y firma ed25519 de la madre comprobados"
}

bajar(){
  paso "4/9  Bajando $([ "$CON_CHAT" = 1 ] && echo 'CRM, Chat y Robot' || echo "el CRM $VERSION") y comprobando la firma de la madre"
  bajar_pieza crm "$VERSION" "$TMP/crm.tgz"
  if [ "$CON_CHAT" = 1 ]; then
    bajar_pieza chat "$VERSION_CHAT" "$TMP/chat.tgz"
    bajar_pieza robot "$VERSION_ROBOT" "$TMP/robot.tgz"
  fi
}

archivos(){
  paso "5/9  Copiando el CRM a $CARPETA"
  local f d
  mkdir -p "$CARPETA"
  CREADO_DIR=1
  tar xzf "$TMP/crm.tgz" -C "$CARPETA" --no-same-owner >>"$LOG" 2>&1 || die "No se pudo descomprimir el paquete."
  for f in spark public/index.php vendor/autoload.php deploy/version.json deploy/aplicar-migraciones.sh \
           deploy/instalar/esquema.sql deploy/instalar/semilla.sql; do
    [ -e "$CARPETA/$f" ] || die "Al paquete le falta $f: no se puede instalar con él."
  done
  rm -f "$CARPETA/.env"
  local minp
  minp="$(grep -oE "minPhpVersion = '[0-9.]+'" "$CARPETA/public/index.php" | grep -oE '[0-9][0-9.]*' | head -1 || true)"
  if [ -n "$minp" ]; then
    ver_ok "$(php_cli)" "$minp" || die "Este CRM pide PHP $minp y la consola tiene $(php_cli)."
    [ "$SIN_APACHE" = 1 ] || [ -z "$(php_apache)" ] || ver_ok "$(php_apache)" "$minp" || die "Este CRM pide PHP $minp y Apache usa PHP $(php_apache)."
  fi
  for d in cache logs session uploads debugbar; do
    mkdir -p "$CARPETA/writable/$d"
    printf '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>\n' >"$CARPETA/writable/$d/index.html"
  done
  chown -R "$WEB_USER:$WEB_USER" "$CARPETA"
  find "$CARPETA" -type d -exec chmod 755 {} +
  find "$CARPETA" -type f -exec chmod 644 {} +
  chmod 755 "$CARPETA/spark"
  log "$(find "$CARPETA" -type f | wc -l) archivos · dueño $WEB_USER"
}

base(){
  paso "6/9  Creando la base $DB (vacía: estructura, roles y catálogos genéricos)"
  DBPASS="$(openssl rand -hex 20)"
  mysql -e "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" </dev/null >>"$LOG" 2>&1 || die "No pude crear la base $DB."
  CREADO_DB=1
  mysql -e "CREATE USER '$DB'@'localhost' IDENTIFIED BY '$DBPASS'; GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DB'@'localhost'; FLUSH PRIVILEGES;" \
    </dev/null >>"$LOG" 2>&1 || die "No pude crear el usuario de la base."
  mysql "$DB" <"$CARPETA/deploy/instalar/esquema.sql" >>"$LOG" 2>&1 || die "No se pudo cargar la estructura (ver $LOG)."
  mysql "$DB" <"$CARPETA/deploy/instalar/semilla.sql" >>"$LOG" 2>&1 || die "No se pudo cargar la semilla (ver $LOG)."
  CLAVE="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | cut -c1-14)"
  cat >"$TMP/primeros.php" <<'PHP'
<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m = new mysqli('localhost', getenv('DB'), getenv('DBPASS'), getenv('DB'));
$m->set_charset('utf8mb4');
$empresa = (string) getenv('EMPRESA');
$st = $m->prepare('UPDATE companies SET name = ? WHERE id = 1');
$st->bind_param('s', $empresa);
$st->execute();
$rol = (int) ($m->query("SELECT id FROM roles WHERE name = 'super_admin' LIMIT 1")->fetch_row()[0] ?? 0);
if ($rol < 1) { fwrite(STDERR, "La semilla no trae el rol super_admin\n"); exit(1); }
$nombre = (string) getenv('ADMIN_NOMBRE');
$email = (string) getenv('ADMIN_EMAIL');
$hash = password_hash((string) getenv('CLAVE'), PASSWORD_BCRYPT);
$st = $m->prepare('INSERT INTO users (company_id, role_id, full_name, email, password_hash, is_active, created_at, updated_at) VALUES (1, ?, ?, ?, ?, 1, NOW(), NOW())');
$st->bind_param('isss', $rol, $nombre, $email, $hash);
$st->execute();
$st = $m->prepare('INSERT INTO system_settings (company_id, k, v) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
foreach (['business_name', 'business_brand', 'business_legal_name'] as $k) {
    $st->bind_param('ss', $k, $empresa);
    $st->execute();
}
echo "empresa y administrador creados\n";
PHP
  DB="$DB" DBPASS="$DBPASS" EMPRESA="$EMPRESA" ADMIN_NOMBRE="$ADMIN_NOMBRE" ADMIN_EMAIL="$ADMIN_EMAIL" CLAVE="$CLAVE" \
    php "$TMP/primeros.php" </dev/null >>"$LOG" 2>&1 || die "No pude crear la empresa y el administrador (ver $LOG)."
  log "$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB'" </dev/null) tablas · empresa «$EMPRESA» · administrador $ADMIN_EMAIL"
}

entorno(){
  paso "7/9  Configuración propia (.env) y migraciones"
  local vapid pub='' priv=''
  vapid="$(cd "$CARPETA" && php -r 'require "vendor/autoload.php"; if (class_exists("Minishlink\\WebPush\\VAPID")) { try { $k = Minishlink\WebPush\VAPID::createVapidKeys(); echo $k["publicKey"], " ", $k["privateKey"]; } catch (Throwable $e) {} }' </dev/null 2>/dev/null || true)"
  if [[ "$vapid" == *" "* ]]; then pub="${vapid%% *}"; priv="${vapid#* }"; fi
  BOTKEY="$(openssl rand -hex 24)"   # la usa el Chat y el Robot para hablar con el CRM (HARO_KEY)
  cat >"$CARPETA/.env" <<EOF
# HARO CRM «$HIJA» — creado por el instalador de la central el $(date '+%Y-%m-%d %H:%M').
# Todos los secretos de este archivo son NUEVOS y solo de esta instalación: no se copian a otra.
CI_ENVIRONMENT = production
app.baseURL = 'http://$DOMINIO/'
app.forceGlobalSecureRequests = false

database.default.hostname = localhost
database.default.database = $DB
database.default.username = $DB
database.default.password = $DBPASS
database.default.DBDriver = MySQLi
database.default.port = 3306

encryption.key = hex2bin:$(openssl rand -hex 32)
auth.jwtSecret = $(openssl rand -hex 32)
BOT_API_KEY = $BOTKEY
SHALOM_UPLOAD_KEY = $(openssl rand -hex 24)
vapid.public = $pub
vapid.private = $priv
vapid.subject = 'mailto:$ADMIN_EMAIL'

crm.urlPrincipal = 'http://$DOMINIO'
# Tienda propia (opcional, apagada):  tienda.dominio = tienda.miempresa.com
# Chat (cuando se instale):           chatbot.adminUrl = https://chat.miempresa.com

# Central de hijas: versión, licencia y actualizaciones (solo cuando las pidan Joe o el cliente).
central.url = '$CENTRAL'
central.token = $LLAVE
EOF
  chown "$WEB_USER:$WEB_USER" "$CARPETA/.env"
  chmod 640 "$CARPETA/.env"
  HARO_APP_DIR="$CARPETA" bash "$CARPETA/deploy/aplicar-migraciones.sh" </dev/null >>"$LOG" 2>&1 || die "Las migraciones fallaron (ver $LOG)."
  log "secretos nuevos (cifrado, sesiones, robot, avisos push) · migraciones al día"
}

# Certificado SSL para un dominio (0 = emitido). Solo si el dominio ya apunta a este servidor; si no, avisa.
certificado(){
  local dom="$1" resuelve mias apunta=0 ip
  if [ "$SIN_SSL" = 1 ]; then aviso "SSL de $dom omitido (--sin-ssl). Para ponerlo:  sudo bash $AGENTE_DIR/poner-ssl.sh $HIJA"; return 1; fi
  resuelve="$(getent ahostsv4 "$dom" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true)"
  mias=" $(hostname -I 2>/dev/null || true) $IP_VISTA "
  for ip in $resuelve; do case "$mias" in *" $ip "*) apunta=1;; esac; done
  if [ -z "${resuelve// /}" ]; then
    aviso "$dom todavía no apunta a ningún lado: queda por http. Cuando apunte a ${IP_VISTA:-este servidor}:  sudo bash $AGENTE_DIR/poner-ssl.sh $HIJA"
    return 1
  fi
  if [ "$apunta" = 0 ]; then
    aviso "$dom apunta a ${resuelve% } y este servidor es ${IP_VISTA:-?}: queda por http. Con Cloudflare, pon la nube en gris y luego:  sudo bash $AGENTE_DIR/poner-ssl.sh $HIJA"
    return 1
  fi
  if ! command -v certbot >/dev/null 2>&1; then
    if [ "$SIN_PAQ" = 1 ] || ! command -v apt-get >/dev/null 2>&1; then aviso "No hay certbot: $dom queda por http."; return 1; fi
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot python3-certbot-apache </dev/null >>"$LOG" 2>&1 \
      || { aviso "No pude instalar certbot: $dom queda por http."; return 1; }
  fi
  : >"$TMP/certbot-$dom.log"
  if certbot --apache -d "$dom" --non-interactive --agree-tos --register-unsafely-without-email --redirect </dev/null >"$TMP/certbot-$dom.log" 2>&1; then
    cat "$TMP/certbot-$dom.log" >>"$LOG"
    log "certificado SSL emitido: https://$dom"
    return 0
  fi
  cat "$TMP/certbot-$dom.log" >>"$LOG"
  local det nat=''
  det="$(grep -m1 -E '^ *Detail:' "$TMP/certbot-$dom.log" | sed -E 's/^ *Detail: *//' | cut -c1-180 || true)"
  case " $(hostname -I 2>/dev/null || true) " in *" $IP_VISTA "*) ;; *) nat=" Este servidor está detrás de NAT (su IP interna no es $IP_VISTA): el puerto 80 de $IP_VISTA tiene que llegar hasta aquí, o el certificado lo pone el proveedor.";; esac
  aviso "certbot no pudo emitir el certificado de $dom${det:+: $det}. Queda por http.$nat Reintenta:  sudo bash $AGENTE_DIR/poner-ssl.sh $HIJA"
  return 1
}

ssl(){
  if certificado "$DOMINIO"; then
    sed -i -E "s#^app\.baseURL = .*#app.baseURL = 'https://$DOMINIO/'#; s#^crm\.urlPrincipal = .*#crm.urlPrincipal = 'https://$DOMINIO'#" "$CARPETA/.env"
    ESQUEMA=https
  fi
  return 0
}

sitio(){
  if [ "$SIN_APACHE" = 1 ]; then paso "8/9  Sitio web: omitido (--sin-apache)"; return 0; fi
  paso "8/9  Sitio web en Apache para $DOMINIO"
  local conf="/etc/apache2/sites-available/$SITIO.conf" mods reiniciar=0
  cat >"$conf" <<EOF
# HARO CRM «$HIJA» — creado por el instalador de la central el $(date '+%Y-%m-%d %H:%M').
<VirtualHost *:80>
    ServerName $DOMINIO
    DocumentRoot $CARPETA/public
    <Directory $CARPETA/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/$SITIO-error.log
    CustomLog \${APACHE_LOG_DIR}/$SITIO-access.log combined
</VirtualHost>
EOF
  CREADO_SITIO=1
  mods="$(apache2ctl -M 2>/dev/null || true)"
  if ! grep -q rewrite_module <<<"$mods"; then a2enmod -q rewrite >>"$LOG" 2>&1; reiniciar=1; fi
  a2ensite -q "$SITIO" >>"$LOG" 2>&1 || die "No pude activar el sitio $SITIO."
  apache2ctl configtest >>"$LOG" 2>&1 || die "La configuración de Apache no pasó la prueba (ver $LOG). Apache sigue como estaba."
  if [ "$reiniciar" = 1 ]; then systemctl restart apache2; else systemctl reload apache2; fi
  log "sitio $SITIO activo"
  ssl
}

salud(){
  paso "9/9  Probando que el CRM responda"
  local cod pid='' puerto
  sleep 2
  if [ "$SIN_APACHE" = 1 ]; then
    puerto=$((20000 + RANDOM % 20000))
    (cd "$CARPETA" && exec runuser -u "$WEB_USER" -- php -S "127.0.0.1:$puerto" -t public vendor/codeigniter4/framework/system/rewrite.php) </dev/null >>"$LOG" 2>&1 &
    pid=$!
    sleep 2
    cod="$(curl -s -o "$TMP/salud.html" -w '%{http_code}' -m 40 -L --connect-to "$DOMINIO:80:127.0.0.1:$puerto" "http://$DOMINIO/admin/dashboard" </dev/null || true)"
    kill "$pid" 2>/dev/null || true
  else
    cod="$(curl -s -k -o "$TMP/salud.html" -w '%{http_code}' -m 40 -L --resolve "$DOMINIO:80:127.0.0.1" --resolve "$DOMINIO:443:127.0.0.1" \
           "$ESQUEMA://$DOMINIO/admin/dashboard" </dev/null || true)"
  fi
  if [ "$cod" != 200 ] || grep -qiE 'Whoops|ErrorException|Fatal error|Parse error' "$TMP/salud.html"; then
    local texto
    texto="$(sed -e 's/<[^>]*>/ /g' "$TMP/salud.html" 2>/dev/null | tr -s ' \r\n\t' ' ' | cut -c1-200 || true)"
    guardar_diagnostico
    die "El CRM no respondió bien (HTTP $cod): ${texto:-sin texto}. Diagnóstico en ${LOG%.log}-diagnostico/"
  fi
  log "responde: HTTP 200 en la pantalla de entrada"
  probar_fallo salud
}

# ── Chat y Robot (fase 6b) ───────────────────────────────────────────────────────────────────────────────────────────
# Node 20: el del sistema si ya es 20; si no, el oficial de nodejs.org (sha256 comprobado) en /opt/haro-node.
asegurar_node(){
  local v dir="/opt/haro-node/node-v$NODE_VER-linux-x64" f="node-v$NODE_VER-linux-x64.tar.xz" esperado
  if command -v node >/dev/null 2>&1; then
    v="$(node -v 2>/dev/null | tr -d v || true)"
    if [ "${v%%.*}" = 20 ]; then NODE_BIN="$(command -v node)"; log "Node $v del sistema"; return 0; fi
  fi
  if [ -x "$dir/bin/node" ]; then NODE_BIN="$dir/bin/node"; log "Node $NODE_VER en /opt/haro-node"; return 0; fi
  log "Poniendo Node $NODE_VER (el oficial de nodejs.org, solo para el Chat y el Robot)…"
  curl -fsSL -m 900 -o "$TMP/$f" "https://nodejs.org/dist/v$NODE_VER/$f" </dev/null 2>>"$LOG" || die "No pude bajar Node $NODE_VER de nodejs.org."
  curl -fsSL -m 60 -o "$TMP/SHASUMS256.txt" "https://nodejs.org/dist/v$NODE_VER/SHASUMS256.txt" </dev/null 2>>"$LOG" || die "No pude bajar las sumas de Node."
  esperado="$(awk -v f="$f" '$2 == f {print $1}' "$TMP/SHASUMS256.txt")"
  [ -n "$esperado" ] && [ "$(sha256sum "$TMP/$f" | cut -d' ' -f1)" = "$esperado" ] || die "Node llegó dañado (sha256 no coincide)."
  mkdir -p /opt/haro-node
  tar -xJf "$TMP/$f" -C /opt/haro-node --no-same-owner || die "No pude descomprimir Node."
  NODE_BIN="$dir/bin/node"
  "$NODE_BIN" -v >/dev/null || die "Node $NODE_VER no arranca en este servidor."
}

# Pone o cambia «CLAVE = valor» (o «CLAVE=valor» con $3 = sin_espacios) en un archivo de configuración.
env_poner(){
  local f="$1" k="$2" v="$3" sep="${4:- = }" tmp
  tmp="$(mktemp)"
  K="$k" V="$v" S="$sep" awk 'BEGIN { k = ENVIRON["K"]; v = ENVIRON["V"]; s = ENVIRON["S"]; hecho = 0 }
    { linea = $0; sub(/^[ \t]+/, "", linea)
      if (index(linea, k) == 1 && substr(linea, length(k) + 1) ~ /^[ \t]*=/) { if (!hecho) print k s v; hecho = 1; next } }
    { print } END { if (!hecho) print k s v }' "$f" >"$tmp"
  cat "$tmp" >"$f"
  rm -f "$tmp"
}

# Espera hasta $3 segundos a que una URL responda $2.
esperar_http(){
  local url="$1" quiero="$2" max="${3:-40}" n=0 cod
  while [ "$n" -lt "$max" ]; do
    cod="$(curl -s -o /dev/null -w '%{http_code}' -m 5 "$url" </dev/null || true)"
    [ "$cod" = "$quiero" ] && return 0
    sleep 2; n=$((n + 2))
  done
  return 1
}

chat_robot(){
  [ "$CON_CHAT" = 1 ] || return 0
  paso "Chat y Robot en $DOMINIO_CHAT"
  local crm_url chat_url admin_id cookie tkey dev
  asegurar_node
  if ! command -v ffmpeg >/dev/null 2>&1; then
    if [ "$SIN_PAQ" = 0 ] && command -v apt-get >/dev/null 2>&1; then
      DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ffmpeg </dev/null >>"$LOG" 2>&1 || aviso "No pude instalar ffmpeg: las notas de voz del chat irán sin convertir."
    else
      aviso "No hay ffmpeg: las notas de voz del chat irán sin convertir (sudo apt-get install ffmpeg)."
    fi
  fi
  id "$RUN_USER" >/dev/null 2>&1 || useradd --system --home-dir /var/lib/haro --create-home --shell /usr/sbin/nologin "$RUN_USER" \
    || die "No pude crear el usuario $RUN_USER (el que corre el Chat y el Robot)."

  # Archivos (paquetes firmados ya comprobados)
  mkdir -p "$CHAT_DIR"; CREADO_CHAT=1
  tar xzf "$TMP/chat.tgz" -C "$CHAT_DIR" --no-same-owner >>"$LOG" 2>&1 || die "No se pudo descomprimir el Chat."
  mkdir -p "$ROBOT_DIR"; CREADO_ROBOT=1
  tar xzf "$TMP/robot.tgz" -C "$ROBOT_DIR" --no-same-owner >>"$LOG" 2>&1 || die "No se pudo descomprimir el Robot."
  [ -f "$CHAT_DIR/servidor.js" ] && [ -d "$CHAT_DIR/node_modules" ] || die "Al paquete del Chat le falta servidor.js o node_modules."
  [ -f "$ROBOT_DIR/server.js" ] && [ -f "$ROBOT_DIR/instalar/robot-inicial.json" ] || die "Al paquete del Robot le falta server.js o su configuración inicial."
  # Los módulos nativos van compilados en la madre: ¿cargan aquí?
  "$NODE_BIN" -e "require('$CHAT_DIR/node_modules/better-sqlite3'); require('$CHAT_DIR/node_modules/sharp')" </dev/null >>"$LOG" 2>&1 \
    || die "Los módulos del Chat no cargan en este servidor (better-sqlite3/sharp). Ver $LOG."

  crm_url="$ESQUEMA://$DOMINIO"
  # Sitio del chat (antes de escribir la configuración: con SSL las direcciones ya salen en https)
  cat >"/etc/apache2/sites-available/$SITIO_CHAT.conf" <<EOF
# Chat y Robot de «$HIJA» — creado por el instalador de la central el $(date '+%Y-%m-%d %H:%M').
# El robot va en /tecnobot/ (puerto $P_ROBOT); el chat en la raíz (puerto $P_CHAT). Sin websockets: SSE en /eventos.
<VirtualHost *:80>
    ServerName $DOMINIO_CHAT
    ProxyPreserveHost On
    ProxyTimeout 300
    RewriteEngine On
    ProxyPass        /.well-known/acme-challenge/ !
    RewriteRule ^/tecnobot\$ /tecnobot/ [R=301,L]
    ProxyPass        /tecnobot/ http://127.0.0.1:$P_ROBOT/tecnobot/ timeout=90
    ProxyPassReverse /tecnobot/ http://127.0.0.1:$P_ROBOT/tecnobot/
    RewriteRule ^/c/([a-z0-9-]+)\$ /c/\$1/ [R=301,L]
    ProxyPass        /c/$HIJA/ http://127.0.0.1:$P_CHAT/ flushpackets=on
    ProxyPassReverse /c/$HIJA/ http://127.0.0.1:$P_CHAT/
    ProxyPass        / http://127.0.0.1:$P_CHAT/ flushpackets=on
    ProxyPassReverse / http://127.0.0.1:$P_CHAT/
    ErrorLog  \${APACHE_LOG_DIR}/$SITIO_CHAT-error.log
    CustomLog \${APACHE_LOG_DIR}/$SITIO_CHAT-access.log combined
</VirtualHost>
EOF
  CREADO_SITIO_CHAT=1
  a2enmod -q proxy proxy_http rewrite >>"$LOG" 2>&1 || die "No pude activar los módulos proxy de Apache."
  a2ensite -q "$SITIO_CHAT" >>"$LOG" 2>&1 || die "No pude activar el sitio $SITIO_CHAT."
  apache2ctl configtest >>"$LOG" 2>&1 || die "La configuración de Apache del chat no pasó la prueba (ver $LOG)."
  systemctl reload apache2
  if certificado "$DOMINIO_CHAT"; then ESQUEMA_CHAT=https; fi
  chat_url="$ESQUEMA_CHAT://$DOMINIO_CHAT"

  # Secretos NUEVOS de esta instalación
  cookie="$(openssl rand -hex 32)"; CRM_KEY="$(openssl rand -hex 32)"; tkey="$(openssl rand -hex 24)"; dev="$(openssl rand -hex 16)"
  umask 077
  cat >"$CHAT_DIR/panel.env" <<EOF
# Chat (bandeja de WhatsApp) de «$HIJA» — creado por el instalador de la central el $(date '+%Y-%m-%d %H:%M').
# Secretos NUEVOS y solo de esta instalación. Lo propio de este chat (este archivo, usuarios.json, cuentas.json,
# datos/, sesion/ y mensajes.db) nunca lo pisa una actualización.
PORT=$P_CHAT
HOST=127.0.0.1
COOKIE_SECRET=$cookie
PAIS=51
GRUPOS=0
DIAG=0
HARO_URL=$crm_url
HARO_KEY=$BOTKEY
CRM_KEY=$CRM_KEY
CRM_ORIGEN=$crm_url
EOF
  mkdir -p "$CHAT_DIR/datos/$HIJA"
  cat >"$CHAT_DIR/datos/$HIJA/cuenta.env" <<EOF
PORT=$P_CHAT
HISTORIAL=0
VOZ_COMO_ARCHIVO=1

# Robot de esta cuenta (mismo servidor: el robot rechaza pedidos que vengan por el proxy)
TECNOBOT_URL=http://127.0.0.1:$P_ROBOT/tecnobot
TECNOBOT_KEY=$tkey
TECNOBOT_MEDIA=$ROBOT_DIR/datos/$HIJA/media

# La barra del CRM (CRM · Chat · Robot) muestra esta bandeja dentro.
CRM_ORIGEN=$crm_url
EOF
  admin_id="$(mysql -N -e "SELECT id FROM users WHERE email = '$(printf '%s' "$ADMIN_EMAIL" | sed "s/'/''/g")' LIMIT 1" "$DB" </dev/null 2>/dev/null || true)"
  EMPRESA="$EMPRESA" CUENTA="$HIJA" CLAVE="$CLAVE" UID_CRM="${admin_id:-0}" "$NODE_BIN" -e '
    const c = require("crypto"), fs = require("fs"), d = process.argv[1];
    const hoy = new Date().toISOString().slice(0, 10);
    fs.writeFileSync(d + "/cuentas.json", JSON.stringify([{ id: process.env.CUENTA, nombre: process.env.EMPRESA, color: "#60a5fa", activa: true, creada: hoy }], null, 2) + "\n");
    const sal = c.randomBytes(16).toString("hex");
    const u = { nombre: "admin", sal, huella: c.scryptSync(process.env.CLAVE, sal, 32).toString("hex"), color: "#60a5fa", admin: true, activo: true, cuentas: [process.env.CUENTA] };
    if (Number(process.env.UID_CRM) > 0) u.crm_user_id = Number(process.env.UID_CRM);
    fs.writeFileSync(d + "/usuarios.json", JSON.stringify([u], null, 2) + "\n");
  ' "$CHAT_DIR" </dev/null || die "No pude crear el usuario del chat."

  cat >"$ROBOT_DIR/.env" <<EOF
# Robot (vendedora IA) de «$HIJA» — creado por el instalador de la central el $(date '+%Y-%m-%d %H:%M').
# Nace APAGADO y sin key de IA: se compra en la Tienda IA (TIENDA_IA_TOKEN) o se pega una key en su panel.
PUERTO=$P_ROBOT
BANDEJA_DIR=$CHAT_DIR
CUENTA=$HIJA
BANDEJA_KEY=$tkey
DEV_TOKEN=$dev
CRM_URL=$crm_url/admin/dashboard
PANEL_ORIGENES=$crm_url,$chat_url
TIENDA_IA_URL=$TIENDA_IA_URL
TIENDA_IA_TOKEN=
EOF
  mkdir -p "$ROBOT_DIR/datos/$HIJA/media" "$ROBOT_DIR/datos/.rodobot"
  EMPRESA="$EMPRESA" AGENTE="$NOMBRE_ROBOT" "$NODE_BIN" -e '
    const fs = require("fs");
    const r = (s) => s.split("__EMPRESA__").join(process.env.EMPRESA).split("__AGENTE__").join(process.env.AGENTE);
    const andar = (o) => typeof o === "string" ? r(o) : Array.isArray(o) ? o.map(andar)
      : (o && typeof o === "object") ? Object.fromEntries(Object.entries(o).map(([k, v]) => [k, andar(v)])) : o;
    const cfg = andar(JSON.parse(fs.readFileSync(process.argv[1], "utf8")));
    const txt = JSON.stringify(cfg, null, 2) + "\n";
    fs.writeFileSync(process.argv[2] + "/publicado.json", txt);
    fs.writeFileSync(process.argv[2] + "/borrador.json", txt);
  ' "$ROBOT_DIR/instalar/robot-inicial.json" "$ROBOT_DIR/datos/$HIJA" </dev/null || die "No pude escribir la configuración inicial del robot."
  umask 022
  chown -R "$RUN_USER:$RUN_USER" "$CHAT_DIR" "$ROBOT_DIR"
  chmod 750 "$CHAT_DIR" "$ROBOT_DIR" "$ROBOT_DIR/datos/.rodobot"
  chmod 600 "$CHAT_DIR/panel.env" "$CHAT_DIR/datos/$HIJA/cuenta.env" "$CHAT_DIR/usuarios.json" "$ROBOT_DIR/.env"

  # Servicios
  cat >"/etc/systemd/system/$UNIDAD_CHAT.service" <<EOF
[Unit]
Description=Chat (bandeja de WhatsApp) de $HIJA ($DOMINIO_CHAT) — instalado por la central
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$CHAT_DIR
EnvironmentFile=$CHAT_DIR/panel.env
EnvironmentFile=$CHAT_DIR/datos/$HIJA/cuenta.env
Environment=CUENTA=$HIJA
ExecStart=$NODE_BIN $CHAT_DIR/servidor.js
Restart=always
RestartSec=20
StartLimitIntervalSec=600
StartLimitBurst=3
MemoryMax=1500M
LimitNOFILE=65535
KillSignal=SIGTERM
TimeoutStopSec=20

[Install]
WantedBy=multi-user.target
EOF
  cat >"/etc/systemd/system/$UNIDAD_ROBOT.service" <<EOF
[Unit]
Description=Robot (vendedora IA) de $HIJA ($DOMINIO_CHAT/tecnobot) — instalado por la central
After=network.target $UNIDAD_CHAT.service

[Service]
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$ROBOT_DIR
ExecStart=$NODE_BIN $ROBOT_DIR/server.js
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
  CREADO_UNIDADES=1
  systemctl daemon-reload
  systemctl enable --now "$UNIDAD_CHAT" >>"$LOG" 2>&1 || die "El servicio del chat no arrancó."
  esperar_http "http://127.0.0.1:$P_CHAT/login" 200 40 || die "El chat no responde en el puerto $P_CHAT (ver journalctl -u $UNIDAD_CHAT)."
  systemctl enable --now "$UNIDAD_ROBOT" >>"$LOG" 2>&1 || die "El servicio del robot no arrancó."
  esperar_http "http://127.0.0.1:$P_ROBOT/tecnobot/api/equipo" 200 40 || die "El robot no responde en el puerto $P_ROBOT (ver journalctl -u $UNIDAD_ROBOT)."
  local n=0 jl
  while :; do
    jl="$(journalctl -u "$UNIDAD_ROBOT" --no-pager -n 50 2>/dev/null || true)"
    grep -q "cuenta $HIJA .*sesión bandeja OK" <<<"$jl" && break
    n=$((n + 1)); [ "$n" -le 15 ] || die "El robot arrancó pero no se enganchó al chat de $HIJA (ver journalctl -u $UNIDAD_ROBOT)."
    sleep 1
  done
  log "chat (puerto $P_CHAT) y robot (puerto $P_ROBOT) corriendo como $RUN_USER; el robot ya ve al chat"

  # Por el sitio web
  local cod
  cod="$(curl -s -k -o /dev/null -w '%{http_code}' -m 20 -L --resolve "$DOMINIO_CHAT:80:127.0.0.1" --resolve "$DOMINIO_CHAT:443:127.0.0.1" "$chat_url/login" </dev/null || true)"
  [ "$cod" = 200 ] || die "El chat no responde por $chat_url (HTTP $cod)."

  # Enlace con el CRM: entrar al chat desde el CRM (SSO), mandar WhatsApp desde el CRM y la barra CRM · Chat · Robot.
  if [ "$MODO" = agregar ]; then
    # Hija ya instalada: respaldo EXACTO de lo que se toca (si algo falla, al_salir lo devuelve).
    ENV_RESPALDO="$CARPETA/.env.antes-chat-$(date +%Y%m%d%H%M%S)"
    cp -a "$CARPETA/.env" "$ENV_RESPALDO"
    DB="$DB" php -r '
      $m = new mysqli("localhost", "root", "", getenv("DB"));
      $a = [];
      foreach ($m->query("SELECT k, v FROM system_settings WHERE company_id = 1 AND k IN (\x27chatbot_admin_url\x27, \x27chatbot_sso_secret\x27)") as $r) { $a[$r["k"]] = $r["v"]; }
      echo json_encode($a);' </dev/null >"$TMP/ajustes-antes.json" || die "No pude respaldar los ajustes del CRM."
    # Si el CRM ya va por https y su .env todavía dice http (SSL puesto a mano), se corrige aquí.
    if [ "$ESQUEMA" = https ] && grep -qE "^app\.baseURL[[:space:]]*=[[:space:]]*'?http://" "$CARPETA/.env"; then
      sed -i -E "s#^app\.baseURL[[:space:]]*=.*#app.baseURL = 'https://$DOMINIO/'#; s#^crm\.urlPrincipal[[:space:]]*=.*#crm.urlPrincipal = 'https://$DOMINIO'#" "$CARPETA/.env"
      log ".env del CRM pasado a https (el certificado ya estaba puesto)"
    fi
  fi
  env_poner "$CARPETA/.env" chatbot.adminUrl "'$chat_url'"
  env_poner "$CARPETA/.env" chatbot.localUrl "'http://127.0.0.1:$P_CHAT'"
  env_poner "$CARPETA/.env" chatbot.ssoSecret "$CRM_KEY"
  DB="$DB" DBUSER="${DBUSER:-$DB}" DBPASS="$DBPASS" URL="$chat_url" SSO="$CRM_KEY" php -r '
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $m = new mysqli("localhost", getenv("DBUSER"), getenv("DBPASS"), getenv("DB"));
    $st = $m->prepare("INSERT INTO system_settings (company_id, k, v) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach (["chatbot_admin_url" => getenv("URL"), "chatbot_sso_secret" => getenv("SSO")] as $k => $v) { $st->bind_param("ss", $k, $v); $st->execute(); }
  ' </dev/null >>"$LOG" 2>&1 || die "No pude enlazar el CRM con el chat (ver $LOG)."
  # ¿El CRM llega al chat con su clave? (lo que usan los avisos por WhatsApp)
  cod="$(curl -s -o /dev/null -w '%{http_code}' -m 10 -H "x-bot-key: $CRM_KEY" "http://127.0.0.1:$P_CHAT/estado" </dev/null || true)"
  [ "$cod" = 200 ] || die "El chat no aceptó la clave del CRM (HTTP $cod)."
  log "CRM enlazado: entra al chat sin otra clave, manda WhatsApp por él y muestra el Robot en su barra"
  probar_fallo chat
}

poner_agente(){   # $1 = latido | actualizar   $2 = el archivo en base64
  local dst="$AGENTE_DIR/$1.php" nuevo="$TMP/$1.php" vn va
  printf '%s' "$2" | base64 -d >"$nuevo"
  php -l "$nuevo" </dev/null >/dev/null 2>&1 || die "El agente $1.php llegó dañado."
  if [ -f "$dst" ]; then
    vn="$(grep -oE "AGENTE_VER = '[0-9.]+'" "$nuevo" | grep -oE '[0-9][0-9.]*' || true)"
    va="$(grep -oE "AGENTE_VER = '[0-9.]+'" "$dst" | grep -oE '[0-9][0-9.]*' || true)"
    if [ -z "$va" ] || php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "${vn:-0}" "$va" </dev/null; then cp "$nuevo" "$dst"; fi
  else
    cp "$nuevo" "$dst"
  fi
  chmod 600 "$dst"
}

# ── Modo «agregar» (fase 6c): Chat y Robot a una hija YA instalada ───────────────────────────────────────────────────
# Lee lo que ya tiene la hija: su agente (llave), su CRM (.env, base) y si el CRM ya va por https.
leer_hija(){
  paso "2/6  Leyendo la hija instalada «$HIJA»"
  local f="$AGENTE_DIR/hijas/$HIJA.json" dom cod
  [ -f "$f" ] || die "No encuentro el agente de «$HIJA» en $AGENTE_DIR: este comando es para el servidor de esa hija (instalada con el instalador de una línea)."
  jf(){ php -r '$j = json_decode(file_get_contents($argv[1]), true); echo is_array($j) ? ($j[$argv[2]] ?? "") : "";' "$f" "$1" </dev/null; }
  [ -z "$(jf chat)" ] || die "«$HIJA» ya tiene Chat ($(jf chat))."
  LLAVE="$(jf llave)"; CARPETA="$(jf crm)"; dom="$(jf dominio)"
  [[ "$LLAVE" =~ ^hc_[0-9a-f]{48}$ ]] || { LLAVE=''; die "El agente de «$HIJA» no tiene una llave válida."; }
  [ "$dom" = "$DOMINIO" ] || { LLAVE=''; die "El agente dice que el dominio es $dom y la central dice $DOMINIO."; }
  [ -f "$CARPETA/.env" ] || { LLAVE=''; die "No encuentro el CRM de «$HIJA» ($CARPETA)."; }
  ev(){ grep -E "^[[:space:]]*$1[[:space:]]*=" "$CARPETA/.env" | tail -1 | sed -E "s/^[^=]*=[[:space:]]*//; s/^['\"]//; s/['\"][[:space:]]*$//" || true; }
  DB="$(ev 'database\.default\.database')"; DBUSER="$(ev 'database\.default\.username')"; DBPASS="$(ev 'database\.default\.password')"
  BOTKEY="$(ev BOT_API_KEY)"
  [ -n "$DB" ] && [ -n "$DBUSER" ] && [ -n "$BOTKEY" ] || { LLAVE=''; die "Al .env del CRM le falta la base o BOT_API_KEY."; }
  EMPRESA="$(mysql -N "$DB" -e "SELECT COALESCE(NULLIF((SELECT v FROM system_settings WHERE company_id = 1 AND k = 'business_name'), ''), (SELECT name FROM companies WHERE id = 1))" </dev/null 2>/dev/null || true)"
  EMPRESA="${EMPRESA:-$HIJA}"
  ADMIN_EMAIL="$(mysql -N "$DB" -e "SELECT u.email FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'super_admin' AND u.company_id = 1 AND u.is_active = 1 ORDER BY u.id LIMIT 1" </dev/null 2>/dev/null || true)"
  [ -n "$ADMIN_EMAIL" ] || { LLAVE=''; die "El CRM no tiene un super_admin activo para enlazarlo al chat."; }
  # El .env puede decir http aunque el certificado ya esté puesto (SSL a mano): se mira el sitio de verdad, con el
  # certificado VALIDADO para este dominio (sin -k: en un servidor con varios sitios respondería el de otro).
  cod="$(curl -s -o /dev/null -m 15 --resolve "$DOMINIO:443:127.0.0.1" -w '%{http_code}' "https://$DOMINIO/admin/" </dev/null || true)"
  if [[ "$cod" =~ ^(200|301|302)$ ]]; then ESQUEMA=https; else ESQUEMA=http; fi
  CLAVE="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | cut -c1-14)"   # la del usuario admin del CHAT (desde el CRM entra sin clave)
  log "CRM $CARPETA ($ESQUEMA://$DOMINIO) · base $DB · empresa «$EMPRESA» · administrador $ADMIN_EMAIL"
}

# Pide a la central el permiso (el código + la llave del agente de ESTA hija). La llave NO cambia.
canjear_chat(){
  paso "3/6  Pidiendo a la central el permiso para agregar Chat y Robot"
  local host resp code v
  host="$(hostname -f 2>/dev/null || hostname)"
  host="$(printf '%s' "$host" | tr -cd 'A-Za-z0-9._-')"
  resp="$(curl -sS -m 30 -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' -H "Authorization: Bearer $LLAVE" \
          --data "{\"codigo\":\"$CODIGO\",\"servidor\":\"$host\"}" "$CENTRAL/api/instalar" </dev/null 2>>"$LOG")" \
    || die "No pude conectar con la central ($CENTRAL)."
  code="${resp##*$'\n'}"
  resp="${resp%$'\n'*}"
  if [ "$code" != 200 ] || [ "$(jcampo "$resp" ok)" != 1 ]; then
    die "La central no aceptó el comando: $(jcampo "$resp" error) (HTTP $code)"
  fi
  [ "$(jcampo "$resp" tipo)" = chat ] || die "Este código no es para agregar Chat y Robot."
  [ "$(jcampo "$resp" hija)" = "$HIJA" ] || die "La central respondió con otra hija."
  NOMBRE_ROBOT="$(jcampo "$resp" nombre_robot)"; NOMBRE_ROBOT="${NOMBRE_ROBOT:-Asistente}"
  v="$(jcampo "$resp" version_chat)"; [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && VERSION_CHAT="$v"
  v="$(jcampo "$resp" version_robot)"; [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && VERSION_ROBOT="$v"
  IP_VISTA="$(jcampo "$resp" ip)"
  log "permiso OK · Chat $VERSION_CHAT · Robot $VERSION_ROBOT («$NOMBRE_ROBOT») · la central ve a este servidor como ${IP_VISTA:-?}"
}

# El agente de la hija ahora también conoce el Chat y el Robot (el latido informa sus versiones).
agente_agregar(){
  paso "Agente de la central"
  local salida
  JSON_RESPALDO="$AGENTE_DIR/hijas/.$HIJA.json.antes-chat"
  cp -a "$AGENTE_DIR/hijas/$HIJA.json" "$JSON_RESPALDO"
  poner_agente latido '__LATIDO_B64__'
  poner_agente actualizar '__ACTUALIZAR_B64__'
  poner_ayudantes
  F="$AGENTE_DIR/hijas/$HIJA.json" CHAT_DIR="$CHAT_DIR" ROBOT_DIR="$ROBOT_DIR" DOMINIO_CHAT="$DOMINIO_CHAT" UC="$UNIDAD_CHAT" UR="$UNIDAD_ROBOT" php -r '
    $f = getenv("F"); $j = json_decode(file_get_contents($f), true);
    if (! is_array($j)) { exit(1); }
    $j += ["chat" => getenv("CHAT_DIR"), "robot" => getenv("ROBOT_DIR"), "chat_dominio" => getenv("DOMINIO_CHAT"),
      "chat_servicio" => getenv("UC"), "robot_servicio" => getenv("UR")];
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' </dev/null || die "No pude actualizar el agente."
  chmod 600 "$AGENTE_DIR/hijas/$HIJA.json"
  salida="$(php "$AGENTE_DIR/latido.php" -v </dev/null 2>&1 || true)"
  printf '%s\n' "$salida" >>"$LOG"
  grep -q "^\[$HIJA\] OK" <<<"$salida" || die "El latido no llegó a la central: $(grep "\[$HIJA\]" <<<"$salida" | head -1 || true)"
  log "latido con Chat $VERSION_CHAT y Robot $VERSION_ROBOT recibido por la central"
  probar_fallo fin
  rm -f "$JSON_RESPALDO"; JSON_RESPALDO=''
}

resumen_chat(){
  printf '\n  Chat:      %s://%s/   (usuario admin; desde el CRM entra sin clave)\n' "$ESQUEMA_CHAT" "$DOMINIO_CHAT"
  printf '             WhatsApp SIN vincular: escanea el QR en %s://%s/admin con el celular de la empresa.\n' "$ESQUEMA_CHAT" "$DOMINIO_CHAT"
  printf '  Robot:     %s://%s/tecnobot/   «%s», APAGADO y sin key de IA.\n' "$ESQUEMA_CHAT" "$DOMINIO_CHAT" "$NOMBRE_ROBOT"
  printf '             Para que compre IA: crea su token en streamy (Recargas IA → Crear cuenta y clave) y corre\n'
  printf '                 sudo bash %s/poner-tienda-ia.sh %s ria_…\n' "$AGENTE_DIR" "$HIJA"
  printf '  Servicios: %s (puerto %s) · %s (puerto %s), como el usuario %s\n' "$UNIDAD_CHAT" "$P_CHAT" "$UNIDAD_ROBOT" "$P_ROBOT" "$RUN_USER"
  if [ "$ESQUEMA_CHAT" = http ]; then
    printf '\n  \033[33mOJO: sin https NO se puede entrar al chat ni al robot (su sesión es solo https). Cuando %s apunte aquí:\033[0m\n' "$DOMINIO_CHAT"
    printf '      sudo bash %s/poner-ssl.sh %s\n' "$AGENTE_DIR" "$HIJA"
  fi
}

resumen_agregar(){
  printf '\n\033[1;32m====================================================================\033[0m\n'
  printf '  Chat y Robot agregados a «%s»\n' "$EMPRESA"
  resumen_chat
  printf '\n  Clave del usuario admin del CHAT: %s\n' "$CLAVE"
  printf '             ↑ solo para entrar al chat directo; anótala. No se vuelve a mostrar.\n'
  printf '\n  El CRM quedó enlazado (respaldo de su .env: %s).\n' "$(basename "$ENV_RESPALDO")"
  printf '  Registro (sin claves): %s\n' "$LOG"
  printf '  Pendiente en el Robot: productos, medios de pago y encenderlo.\n'
  printf '\033[1;32m====================================================================\033[0m\n'
}

agregar_main(){
  CON_CHAT=1
  requisitos
  leer_hija
  paso "    Revisando que no exista nada del Chat y el Robot"
  nombres_chat
  canjear_chat
  paso "4/6  Bajando Chat y Robot y comprobando la firma de la madre"
  bajar_pieza chat "$VERSION_CHAT" "$TMP/chat.tgz"
  bajar_pieza robot "$VERSION_ROBOT" "$TMP/robot.tgz"
  chat_robot
  agente_agregar
  TERMINADO=1
  reportar true "Chat y Robot agregados en $(hostname) · $ESQUEMA_CHAT://$DOMINIO_CHAT"
  resumen_agregar
}

# Los ayudantes de la hija (SSL y Tienda IA). Se escriben siempre: así una hija vieja recibe la versión nueva.
poner_ayudantes(){
  # Ayudante para poner el SSL después: certbot + TODA la configuración a https (CRM y, si tiene, chat y robot).
  cat >"$AGENTE_DIR/poner-ssl.sh" <<'SSL'
#!/usr/bin/env bash
# Pone el certificado SSL a una hija instalada con el instalador de una línea (su CRM y, si tiene, su chat) y pasa
# toda su configuración a https: .env del CRM, del chat y del robot, y el ajuste del CRM con la dirección del chat.
# Uso:  sudo bash /opt/haro-agente/poner-ssl.sh <hija>      (sirve también si el certificado ya está puesto)
set -euo pipefail
B="$(cd "$(dirname "$0")" && pwd)"; H="${1:-}"; F="$B/hijas/$H.json"
if [ -z "$H" ] || [ ! -f "$F" ]; then echo "Uso: sudo bash $0 <hija>   (hijas: $(ls "$B/hijas" 2>/dev/null | sed 's/\.json$//' | tr '\n' ' '))"; exit 1; fi
[ "$(id -u)" -eq 0 ] || { echo "Córrelo con sudo."; exit 1; }
j(){ php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j[$argv[2]] ?? "";' "$F" "$1"; }
D="$(j dominio)"; C="$(j crm)"; DC="$(j chat_dominio)"; CH="$(j chat)"; RB="$(j robot)"; UC="$(j chat_servicio)"; UR="$(j robot_servicio)"
[ -n "$D" ] && [ -f "$C/.env" ] || { echo "No encuentro el dominio o el .env de $H."; exit 1; }
command -v certbot >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot python3-certbot-apache </dev/null
DOMS=("$D"); [ -z "$DC" ] || DOMS+=("$DC")
for dom in "${DOMS[@]}"; do
  certbot --apache -d "$dom" --non-interactive --agree-tos --register-unsafely-without-email --redirect </dev/null \
    || { echo "certbot no pudo con $dom: revisa que apunte a este servidor y que el puerto 80 llegue aquí."; exit 1; }
done
ts="$(date +%Y%m%d%H%M%S)"
ARCH=("$C/.env")
[ -z "$CH" ] || ARCH+=("$CH/panel.env" "$CH/datos/$H/cuenta.env")
[ -z "$RB" ] || ARCH+=("$RB/.env")
for f in "${ARCH[@]}"; do
  [ -f "$f" ] || continue
  cp -a "$f" "$f.antes-ssl-$ts"
  for dom in "${DOMS[@]}"; do sed -i "s#http://$dom#https://$dom#g" "$f"; done
done
if [ -n "$DC" ]; then
  db="$(grep -E '^database\.default\.database' "$C/.env" | sed -E "s/^[^=]*=[[:space:]]*//; s/['\"]//g")"
  mysql "$db" -e "UPDATE system_settings SET v = REPLACE(v, 'http://$DC', 'https://$DC') WHERE company_id = 1 AND k = 'chatbot_admin_url'"
  systemctl restart "$UC" "$UR"
fi
systemctl reload apache2
echo "Listo: https://$D${DC:+ y https://$DC} (toda la configuración ya dice https)."
SSL
  chmod 700 "$AGENTE_DIR/poner-ssl.sh"
  # Ayudante para darle al Robot su token de la Tienda IA (Recargas IA de streamy), para comprar créditos de IA.
  cat >"$AGENTE_DIR/poner-tienda-ia.sh" <<'TIENDA'
#!/usr/bin/env bash
# Le da al Robot de una hija su token de la Tienda IA (en streamy: Recargas IA → «Crear cuenta y clave»), para que
# compre créditos de IA desde su panel. Uso:  sudo bash /opt/haro-agente/poner-tienda-ia.sh <hija> ria_…
set -euo pipefail
B="$(cd "$(dirname "$0")" && pwd)"; H="${1:-}"; T="${2:-}"; F="$B/hijas/$H.json"
[ -n "$H" ] && [ -f "$F" ] && [[ "$T" =~ ^ria_[A-Za-z0-9_-]{8,}$ ]] || { echo "Uso: sudo bash $0 <hija> ria_…"; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo "Córrelo con sudo."; exit 1; }
j(){ php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j[$argv[2]] ?? "";' "$F" "$1"; }
RB="$(j robot)"; UR="$(j robot_servicio)"
[ -n "$RB" ] && [ -f "$RB/.env" ] || { echo "La hija $H no tiene Robot."; exit 1; }
cp -a "$RB/.env" "$RB/.env.antes-tienda-$(date +%Y%m%d%H%M%S)"
if grep -q '^TIENDA_IA_TOKEN=' "$RB/.env"; then sed -i "s#^TIENDA_IA_TOKEN=.*#TIENDA_IA_TOKEN=$T#" "$RB/.env"; else echo "TIENDA_IA_TOKEN=$T" >>"$RB/.env"; fi
systemctl restart "$UR"
echo "Listo: el robot de $H ya puede comprar IA en la Tienda IA desde su panel."
TIENDA
  chmod 700 "$AGENTE_DIR/poner-tienda-ia.sh"
}

agente(){
  paso "Agente de la central (latido cada 10 min)"
  local salida
  if [ ! -d "$AGENTE_DIR" ]; then CREADO_AGENTE_DIR=1; fi
  mkdir -p "$AGENTE_DIR/hijas"
  chmod 700 "$AGENTE_DIR" "$AGENTE_DIR/hijas"
  poner_agente latido '__LATIDO_B64__'
  poner_agente actualizar '__ACTUALIZAR_B64__'
  poner_ayudantes
  CENTRAL="$CENTRAL" LLAVE="$LLAVE" CARPETA="$CARPETA" DOMINIO="$DOMINIO" DUENO="$WEB_USER:$WEB_USER" CON_CHAT="$CON_CHAT" \
  CHAT_DIR="$CHAT_DIR" ROBOT_DIR="$ROBOT_DIR" DOMINIO_CHAT="$DOMINIO_CHAT" UC="$UNIDAD_CHAT" UR="$UNIDAD_ROBOT" php -r '
    $j = ["central" => getenv("CENTRAL"), "llave" => getenv("LLAVE"), "crm" => getenv("CARPETA"), "dominio" => getenv("DOMINIO"),
      "dueno" => getenv("DUENO"), "instalada" => date("c")];
    if (getenv("CON_CHAT") === "1") {
      $j += ["chat" => getenv("CHAT_DIR"), "robot" => getenv("ROBOT_DIR"), "chat_dominio" => getenv("DOMINIO_CHAT"),
        "chat_servicio" => getenv("UC"), "robot_servicio" => getenv("UR")];
    }
    echo json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
  ' </dev/null >"$AGENTE_DIR/hijas/$HIJA.json"
  chmod 600 "$AGENTE_DIR/hijas/$HIJA.json"
  CREADO_AGENTE=1
  if [ ! -f /etc/cron.d/haro-agente ]; then
    printf 'SHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n# Agente de la Central de hijas: latido cada 10 min; actualiza SOLO si lo piden Joe o el cliente.\n*/10 * * * * root php %s/latido.php >/dev/null 2>&1\n' "$AGENTE_DIR" >/etc/cron.d/haro-agente
    chmod 644 /etc/cron.d/haro-agente
    CREADO_CRON=1
  fi
  salida="$(php "$AGENTE_DIR/latido.php" -v </dev/null 2>&1 || true)"
  printf '%s\n' "$salida" >>"$LOG"
  grep -q "^\[$HIJA\] OK" <<<"$salida" || die "El primer latido no llegó a la central: $(grep "\[$HIJA\]" <<<"$salida" | head -1 || true)"
  log "primer latido recibido: la hija ya aparece en la central"
  probar_fallo fin
}

resumen(){
  printf '\n\033[1;32m====================================================================\033[0m\n'
  printf '  HARO CRM instalado para «%s»\n\n' "$EMPRESA"
  printf '  Entrar:    %s://%s/admin\n' "$ESQUEMA" "$DOMINIO"
  printf '  Usuario:   %s\n' "$ADMIN_EMAIL"
  printf '  Clave:     %s\n' "$CLAVE"
  printf '             ↑ anótala y cámbiala al entrar (Mi perfil). No se vuelve a mostrar.\n\n'
  printf '  Carpeta %s · base %s · sitio %s\n' "$CARPETA" "$DB" "$([ "$SIN_APACHE" = 1 ] && echo 'no creado' || echo "$SITIO")"
  printf '  Agente  %s (latido cada 10 min; actualiza solo si lo piden Joe o el cliente)\n' "$AGENTE_DIR"
  printf '  Registro de la instalación (sin claves): %s\n' "$LOG"
  if [ "$CON_CHAT" = 1 ]; then
    printf '\n  Chat:      %s://%s/   (usuario admin, la MISMA clave; desde el CRM entra sin clave)\n' "$ESQUEMA_CHAT" "$DOMINIO_CHAT"
    printf '             WhatsApp SIN vincular: escanea el QR en %s://%s/admin con el celular de la empresa.\n' "$ESQUEMA_CHAT" "$DOMINIO_CHAT"
    printf '  Robot:     %s://%s/tecnobot/   «%s», APAGADO y sin key de IA.\n' "$ESQUEMA_CHAT" "$DOMINIO_CHAT" "$NOMBRE_ROBOT"
    printf '             Para que compre IA: crea su token en streamy (Recargas IA → Crear cuenta y clave) y corre\n'
    printf '                 sudo bash %s/poner-tienda-ia.sh %s ria_…\n' "$AGENTE_DIR" "$HIJA"
    printf '  Servicios: %s (puerto %s) · %s (puerto %s), como el usuario %s\n' "$UNIDAD_CHAT" "$P_CHAT" "$UNIDAD_ROBOT" "$P_ROBOT" "$RUN_USER"
    if [ "$ESQUEMA_CHAT" = http ]; then
      printf '\n  \033[33mOJO: sin https NO se puede entrar al chat ni al robot (su sesión es solo https). Pon el SSL apenas el dominio apunte.\033[0m\n'
    fi
  fi
  if { [ "$ESQUEMA" = http ] || { [ "$CON_CHAT" = 1 ] && [ "$ESQUEMA_CHAT" = http ]; }; } && [ "$SIN_APACHE" = 0 ]; then
    printf '\n  Falta el SSL. Cuando %s, corre (pone el certificado Y pasa todo a https):\n' "$([ "$CON_CHAT" = 1 ] && echo "$DOMINIO y $DOMINIO_CHAT apunten a este servidor" || echo "$DOMINIO apunte a este servidor")"
    printf '      sudo bash %s/poner-ssl.sh %s\n' "$AGENTE_DIR" "$HIJA"
  fi
  printf '\n  Pendiente dentro del CRM: facturación, couriers y cuentas de pago%s.\n' "$([ "$CON_CHAT" = 1 ] && echo '; en el Robot: productos, pagos y encenderlo' || echo ', WhatsApp y Robot')"
  printf '\033[1;32m====================================================================\033[0m\n'
}

instalar_main(){
  while [ $# -gt 0 ]; do
    case "$1" in
      --carpeta)      CARPETA="${2:-}"; shift 2 || die "--carpeta necesita una ruta.";;
      --sin-paquetes) SIN_PAQ=1; shift;;
      --sin-apache)   SIN_APACHE=1; shift;;
      --sin-ssl)      SIN_SSL=1; shift;;
      --sin-chat)     CON_CHAT=0; shift;;
      *) die "Opción desconocida: $1 (valen --carpeta DIR, --sin-paquetes, --sin-apache, --sin-ssl, --sin-chat)";;
    esac
  done
  [ "$CON_CHAT" = 1 ] || CON_CHAT=0
  if [ "$CON_CHAT" = 1 ] && [ "$SIN_APACHE" = 1 ]; then
    printf 'AVISO: el Chat y el Robot necesitan Apache; con --sin-apache se instala solo el CRM.\n'
    CON_CHAT=0
  fi
  if [ "$(id -u)" -ne 0 ]; then
    printf 'ERROR: corre el instalador con sudo:  curl -fsSL …/instalar/<código> | sudo bash\n' >&2
    exit 1
  fi
  LOG="/root/haro-instalar-$HIJA-$(date +%Y%m%d-%H%M%S).log"
  : >"$LOG"
  chmod 600 "$LOG"
  TMP="$(mktemp -d /tmp/haro-instalar.XXXXXX)"
  trap 'MOTIVO="${MOTIVO:-línea $LINENO: $BASH_COMMAND}"' ERR
  trap al_salir EXIT
  if [ "$MODO" = agregar ]; then
    printf '\n\033[1mHARO · agregando Chat %s y Robot %s a la hija «%s» en %s\033[0m\n' "$VERSION_CHAT" "$VERSION_ROBOT" "$HIJA" "$DOMINIO_CHAT"
    agregar_main
    return 0
  fi
  printf '\n\033[1mHARO CRM · instalando la hija «%s» en %s (CRM %s%s)\033[0m\n' "$HIJA" "$DOMINIO" "$VERSION" \
    "$([ "$CON_CHAT" = 1 ] && echo " + Chat $VERSION_CHAT y Robot $VERSION_ROBOT en $DOMINIO_CHAT")"
  requisitos
  nombres
  canjear
  bajar
  archivos
  base
  entorno
  sitio
  salud
  chat_robot
  agente
  TERMINADO=1
  reportar true "Instalada en $(hostname) · $CARPETA · CRM $VERSION · $ESQUEMA://$DOMINIO$([ "$CON_CHAT" = 1 ] && echo " · Chat y Robot en $ESQUEMA_CHAT://$DOMINIO_CHAT")"
  resumen
}

# Todo lo de arriba son funciones: bash lee el script COMPLETO antes de ejecutar esta línea, así un corte de red a
# media descarga no deja nada a medio correr.
instalar_main "$@"
