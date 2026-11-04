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
#
#  Qué hace: revisa el servidor → canjea el código (la central le da a la hija su llave) → baja el CRM y comprueba
#  la firma de la madre → base y usuario propios (estructura + roles y catálogos genéricos) → .env con secretos
#  NUEVOS → administrador → sitio en Apache (+ SSL si el dominio ya apunta aquí) → agente con latido cada 10 min.
#  NUNCA trae datos de otra empresa: ni ventas, ni clientes, ni llaves de IA, ni sesión de WhatsApp, ni píxel.
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

# Si algo falla: se deshace TODO lo que este instalador creó (y nada más).
al_salir(){
  local rc=$?
  [ -z "$TMP" ] || rm -rf "$TMP"
  if [ "$rc" -eq 0 ] || [ "$TERMINADO" = 1 ]; then return 0; fi
  trap - ERR
  set +e
  [ -n "$MOTIVO" ] || MOTIVO="falló un paso (código $rc)"
  printf '\nDeshaciendo lo que alcancé a crear…\n'
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
  reportar false "$MOTIVO"
  printf '\nNo quedó nada a medias. Arregla lo de arriba y vuelve a pegar el MISMO comando (el código sigue sirviendo).\nDetalle: %s\n' "$LOG"
}

apt_nombre(){ case "$1" in php-mysqli) echo php-mysql;; php-dom) echo php-xml;; *) echo "$1";; esac; }

requisitos(){
  local segunda="${1:-0}" faltan=() m mods salida
  [ "$segunda" = 1 ] || paso "1/9  Revisando el servidor"
  command -v curl >/dev/null 2>&1    || faltan+=(curl)
  command -v openssl >/dev/null 2>&1 || faltan+=(openssl)
  command -v tar >/dev/null 2>&1     || faltan+=(tar)
  command -v gzip >/dev/null 2>&1    || faltan+=(gzip)
  if command -v php >/dev/null 2>&1; then
    php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' </dev/null \
      || die "Este servidor tiene PHP $(php -r 'echo PHP_VERSION;' </dev/null) y el CRM necesita 8.1 o más nuevo."
    mods="$(php -m 2>/dev/null </dev/null | tr 'A-Z' 'a-z')"
    for m in mysqli mbstring intl curl dom zip gd; do
      grep -qx "$m" <<<"$mods" || faltan+=("php-$m")
    done
    for m in sodium json openssl fileinfo; do
      grep -qx "$m" <<<"$mods" || die "A PHP le falta la extensión «$m», que viene con PHP: reinstala php-cli."
    done
  else
    faltan+=(php-cli php-mysqli php-mbstring php-intl php-curl php-dom php-zip php-gd)
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
  local libre
  libre="$(df -Pm /var 2>/dev/null | awk 'NR==2 {print $4}')"
  [ "${libre:-0}" -ge 1500 ] || die "Hay poco disco libre en /var (${libre:-?} MB): el CRM necesita al menos 1.5 GB."

  if [ ${#faltan[@]} -gt 0 ]; then
    [ "$segunda" = 0 ] || die "Sigue faltando: ${faltan[*]} (ver $LOG)."
    local pk=() f
    for f in "${faltan[@]}"; do pk+=("$(apt_nombre "$f")"); done
    mapfile -t pk < <(printf '%s\n' "${pk[@]}" | sort -u)
    if [ "$SIN_PAQ" = 1 ] || ! command -v apt-get >/dev/null 2>&1; then
      die "Faltan: ${pk[*]}. Instálalos y vuelve a pegar el comando."
    fi
    log "Instalando lo que falta: ${pk[*]} (unos minutos)…"
    DEBIAN_FRONTEND=noninteractive apt-get update -qq </dev/null >>"$LOG" 2>&1 || die "apt-get update falló (ver $LOG)."
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${pk[@]}" </dev/null >>"$LOG" 2>&1 || die "No se pudo instalar: ${pk[*]} (ver $LOG)."
    systemctl enable --now mariadb </dev/null >>"$LOG" 2>&1 || true
    [ "$SIN_APACHE" = 1 ] || systemctl enable --now apache2 </dev/null >>"$LOG" 2>&1 || true
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
  log "$(jcampo "$resp" nombre) · empresa «$EMPRESA» · CRM $VERSION · la central ve a este servidor como ${IP_VISTA:-?}"
}

bajar(){
  paso "4/9  Bajando el CRM $VERSION y comprobando la firma de la madre"
  local http sha firma
  curl -sS -m 1800 -D "$TMP/cab" -o "$TMP/crm.tgz" -H "Authorization: Bearer $LLAVE" \
       "$CENTRAL/api/paquete?pieza=crm&ver=$VERSION" </dev/null 2>>"$LOG" || die "No pude bajar el paquete."
  http="$(awk 'toupper($1) ~ /^HTTP\// {c = $2} END {print c}' "$TMP/cab")"
  [ "$http" = 200 ] || die "La central no entregó el paquete (HTTP $http)."
  sha="$(tr -d '\r' <"$TMP/cab" | awk 'tolower($1) == "x-sha256:" {print tolower($2)}' | tail -1)"
  firma="$(tr -d '\r' <"$TMP/cab" | awk 'tolower($1) == "x-firma:" {print tolower($2)}' | tail -1)"
  php -r '
    $sha = hash_file("sha256", $argv[1]);
    if (! preg_match("/^[0-9a-f]{64}$/", $argv[2]) || ! hash_equals($argv[2], $sha)) { exit(2); }
    if (! preg_match("/^[0-9a-f]{128}$/", $argv[3])) { exit(3); }
    exit(sodium_crypto_sign_verify_detached(sodium_hex2bin($argv[3]), $sha, sodium_hex2bin($argv[4])) ? 0 : 3);
  ' "$TMP/crm.tgz" "$sha" "$firma" "$LLAVE_PUBLICA" </dev/null \
    || die "FIRMA INVÁLIDA o paquete dañado: no lo firmó la madre. No instalo nada."
  log "$(du -m "$TMP/crm.tgz" | cut -f1) MB · sha256 y firma ed25519 de la madre comprobados"
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
BOT_API_KEY = $(openssl rand -hex 24)
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

ssl(){
  if [ "$SIN_SSL" = 1 ]; then aviso "SSL omitido (--sin-ssl). Para ponerlo:  sudo certbot --apache -d $DOMINIO"; return 0; fi
  local resuelve mias apunta=0 ip
  resuelve="$(getent ahostsv4 "$DOMINIO" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true)"
  mias=" $(hostname -I 2>/dev/null || true) $IP_VISTA "
  for ip in $resuelve; do case "$mias" in *" $ip "*) apunta=1;; esac; done
  if [ -z "${resuelve// /}" ]; then
    aviso "$DOMINIO todavía no apunta a ningún lado: queda por http. Cuando apunte a ${IP_VISTA:-este servidor}:  sudo certbot --apache -d $DOMINIO"
    return 0
  fi
  if [ "$apunta" = 0 ]; then
    aviso "$DOMINIO apunta a ${resuelve% } y este servidor es ${IP_VISTA:-?}: queda por http. Con Cloudflare, pon la nube en gris y luego:  sudo certbot --apache -d $DOMINIO"
    return 0
  fi
  if ! command -v certbot >/dev/null 2>&1; then
    if [ "$SIN_PAQ" = 1 ] || ! command -v apt-get >/dev/null 2>&1; then aviso "No hay certbot: queda por http."; return 0; fi
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot python3-certbot-apache </dev/null >>"$LOG" 2>&1 \
      || { aviso "No pude instalar certbot: queda por http."; return 0; }
  fi
  if certbot --apache -d "$DOMINIO" --non-interactive --agree-tos --register-unsafely-without-email --redirect </dev/null >>"$LOG" 2>&1; then
    sed -i -E "s#^app\.baseURL = .*#app.baseURL = 'https://$DOMINIO/'#; s#^crm\.urlPrincipal = .*#crm.urlPrincipal = 'https://$DOMINIO'#" "$CARPETA/.env"
    ESQUEMA=https
    log "certificado SSL emitido: https://$DOMINIO"
  else
    aviso "certbot no pudo emitir el certificado (ver $LOG): queda por http. Reintenta:  sudo certbot --apache -d $DOMINIO"
  fi
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
    cp "$TMP/salud.html" "${LOG%.log}-salud.html" 2>/dev/null || true
    die "El CRM no respondió bien (HTTP $cod). Mira $CARPETA/writable/logs/ y ${LOG%.log}-salud.html"
  fi
  log "responde: HTTP 200 en la pantalla de entrada"
  probar_fallo salud
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

agente(){
  paso "Agente de la central (latido cada 10 min)"
  local salida
  if [ ! -d "$AGENTE_DIR" ]; then CREADO_AGENTE_DIR=1; fi
  mkdir -p "$AGENTE_DIR/hijas"
  chmod 700 "$AGENTE_DIR" "$AGENTE_DIR/hijas"
  poner_agente latido '__LATIDO_B64__'
  poner_agente actualizar '__ACTUALIZAR_B64__'
  CENTRAL="$CENTRAL" LLAVE="$LLAVE" CARPETA="$CARPETA" DOMINIO="$DOMINIO" DUENO="$WEB_USER:$WEB_USER" php -r '
    echo json_encode(["central" => getenv("CENTRAL"), "llave" => getenv("LLAVE"), "crm" => getenv("CARPETA"), "dominio" => getenv("DOMINIO"),
      "dueno" => getenv("DUENO"), "instalada" => date("c")], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
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
  if [ "$ESQUEMA" = http ] && [ "$SIN_APACHE" = 0 ]; then
    printf '\n  Falta el SSL: cuando %s apunte a este servidor, corre  sudo certbot --apache -d %s\n' "$DOMINIO" "$DOMINIO"
  fi
  printf '\n  Pendiente dentro del CRM: facturación, couriers, cuentas de pago, WhatsApp y Robot.\n'
  printf '\033[1;32m====================================================================\033[0m\n'
}

instalar_main(){
  while [ $# -gt 0 ]; do
    case "$1" in
      --carpeta)      CARPETA="${2:-}"; shift 2 || die "--carpeta necesita una ruta.";;
      --sin-paquetes) SIN_PAQ=1; shift;;
      --sin-apache)   SIN_APACHE=1; shift;;
      --sin-ssl)      SIN_SSL=1; shift;;
      *) die "Opción desconocida: $1 (valen --carpeta DIR, --sin-paquetes, --sin-apache, --sin-ssl)";;
    esac
  done
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
  printf '\n\033[1mHARO CRM · instalando la hija «%s» en %s (CRM %s)\033[0m\n' "$HIJA" "$DOMINIO" "$VERSION"
  requisitos
  nombres
  canjear
  bajar
  archivos
  base
  entorno
  sitio
  salud
  agente
  TERMINADO=1
  reportar true "Instalada en $(hostname) · $CARPETA · CRM $VERSION · $ESQUEMA://$DOMINIO"
  resumen
}

# Todo lo de arriba son funciones: bash lee el script COMPLETO antes de ejecutar esta línea, así un corte de red a
# media descarga no deja nada a medio correr.
instalar_main "$@"
