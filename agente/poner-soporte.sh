#!/bin/bash
# Acceso de soporte HARO a una tienda (5-nov-2026, Joe: «imagínate cuando tenga 500 empresas»). Una sola vez, con root:
#   sudo bash poner-soporte.sh <hija>              ej.:  sudo bash poner-soporte.sh siete-deseos
#   sudo bash poner-soporte.sh <hija> --quitar     (le quita el acceso a esa tienda; si no queda otra, borra el usuario)
#        bash poner-soporte.sh <hija> --ensayo     (muestra el permiso que daría y lo valida; no toca nada)
#
# Crea el usuario haro-soporte, que SOLO entra con la llave de soporte de HARO (no tiene contraseña que sirva) y no toca
# las cuentas del cliente. Le da, sin contraseña, el permiso JUSTO para dar soporte a ESTA tienda — no es sudo completo:
#   · ver, reiniciar, arrancar y parar sus servicios (haro-<hija>-chat, -robot, -conector, -shalom)
#   · recargar Apache y probar su configuración
#   · correr el agente (actualizar.php <hija> …, latido.php) y sus ayudantes (poner-ssl/shalom/conector/tienda-ia <hija>)
#   · leer registros: journalctl (grupo systemd-journal), /var/log (grupo adm) y los del CRM (writable/logs, solo lectura
#     por ACL: NO puede leer el .env ni ninguna clave), sin sudo
# La idea (Joe): entrar a MIRAR y encontrar el error rápido; lo que haya que cambiar se cambia en la MADRE y llega con
# «Actualizar». Por eso no hay permiso para editar archivos de la tienda.
# Varias tiendas en el mismo servidor comparten el usuario; cada una tiene su propio archivo en /etc/sudoers.d.
# Se puede volver a correr (p. ej. después de «Agregar Chat y Robot»): rehace el permiso con los servicios que haya.
set -euo pipefail
# Llaves PÚBLICAS que pueden entrar (agregar una = sumar una línea y volver a correr): la PC de soporte y la de Joe.
LLAVES=(
  'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAZRVKbRH6BegVw6k3TELofMyZrVuEWlhkrBoYQyHQJG haro-soporte'
  'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINHIi2sMUt4bLCttVEA8nS/hfd9TGM60U9OZW6Uhi7He joe-rodo-pc'
)
U=haro-soporte
HIJA="${1:-}"
# --ensayo: muestra el permiso que daría y lo pasa por visudo, SIN crear usuario ni tocar nada (sirve sin sudo, aunque
# sin root no puede mirar dentro de la carpeta del agente y entonces lo deja fuera de la lista).
ENSAYO=0; [ "${2:-}" = "--ensayo" ] && ENSAYO=1
[ "$ENSAYO" = 1 ] || [ "$(id -u)" = 0 ] || { echo "Corre esto con sudo:  sudo bash $0 <hija>" >&2; exit 1; }
[[ "$HIJA" =~ ^[a-z0-9][a-z0-9-]*$ ]] || { echo "Uso: sudo bash $0 <hija> [--quitar | --ensayo]" >&2; exit 1; }
F="/etc/sudoers.d/haro-soporte-$HIJA"
AG="${AGENTE_DIR:-/opt/haro-agente}"
say() { echo "· $*"; }

if [ "${2:-}" = "--quitar" ]; then
  rm -f "$F"
  CRM="${CRM_DIR:-/var/www/$HIJA-crm}"
  if command -v setfacl >/dev/null 2>&1 && id "$U" >/dev/null 2>&1; then
    setfacl -R -x "u:$U" "$CRM/writable/logs" 2>/dev/null || true
    setfacl -d -x "u:$U" "$CRM/writable/logs" 2>/dev/null || true
    setfacl -x "u:$U" "$CRM" "$CRM/writable" 2>/dev/null || true
  fi
  say "Permiso de soporte para $HIJA quitado."
  if ! ls /etc/sudoers.d/haro-soporte-* >/dev/null 2>&1 && id "$U" >/dev/null 2>&1; then
    pkill -u "$U" 2>/dev/null || true
    userdel -r "$U" 2>/dev/null || userdel "$U" || true
    say "No le quedaba otra tienda en este servidor: usuario $U borrado."
  fi
  exit 0
fi

# 1) Usuario (sin contraseña que sirva: «*», no «!», que algunos sshd toman como cuenta bloqueada y no dejan entrar ni con llave)
if [ "$ENSAYO" = 0 ]; then
if ! id "$U" >/dev/null 2>&1; then
  useradd -m -s /bin/bash -c "Soporte HARO (solo con llave)" "$U"
  say "Usuario $U creado."
fi
usermod -p '*' "$U"
for g in adm systemd-journal; do
  if getent group "$g" >/dev/null; then usermod -aG "$g" "$U"; fi
done
H="$(getent passwd "$U" | cut -d: -f6)"
install -d -m 700 -o "$U" -g "$U" "$H/.ssh"
AK="$H/.ssh/authorized_keys"
[ -f "$AK" ] || install -m 600 -o "$U" -g "$U" /dev/null "$AK"
for LL in "${LLAVES[@]}"; do
  CUERPO="$(echo "$LL" | awk '{print $2}')"
  if ! grep -qF "$CUERPO" "$AK"; then
    echo "no-agent-forwarding,no-X11-forwarding $LL" >> "$AK"
    say "Llave puesta: $(echo "$LL" | awk '{print $3}')."
  fi
done
chown "$U:$U" "$AK"; chmod 600 "$AK"

# 1b) Registros del CRM: solo lectura por ACL (también los que se creen después). El .env y lo demás, no.
CRM="${CRM_DIR:-/var/www/$HIJA-crm}"
if [ -d "$CRM/writable/logs" ]; then
  if command -v setfacl >/dev/null 2>&1; then
    for d in "$CRM" "$CRM/writable"; do setfacl -m "u:$U:x" "$d"; done      # solo pasar, sin listar
    setfacl -R -m "u:$U:rX" "$CRM/writable/logs"
    setfacl -d -m "u:$U:rX" "$CRM/writable/logs"
    say "Registros del CRM legibles para $U (solo lectura): $CRM/writable/logs"
  else
    say "OJO: falta setfacl (paquete acl): $U no podrá leer los registros del CRM."
  fi
fi
fi   # fin de lo que no se hace en --ensayo

# 2) Permiso justo (se arma la lista con lo que HOY tiene esta tienda)
SC="$(command -v systemctl)"
C=()
for s in chat robot conector shalom; do
  u="haro-$HIJA-$s.service"
  [ -f "/etc/systemd/system/$u" ] || continue
  for a in status restart start stop; do C+=("$SC $a $u"); done
done
C+=("$SC reload apache2" "$SC status apache2")
A2="$(command -v apache2ctl || true)"; [ -z "$A2" ] || C+=("$A2 configtest")
# El agente y sus ayudantes SOLO si su carpeta es de root y nadie más puede escribir en ella (si no, sería una puerta a root).
AGOK=0
# (si find no puede mirar adentro —ensayo sin root—, la condición falla y el agente queda fuera: nunca se da a ciegas)
if [ -d "$AG" ] && [ "$(stat -c %u "$AG")" = 0 ] && W="$(find "$AG" -maxdepth 1 \( -perm -g+w -o -perm -o+w \) -print -quit 2>/dev/null)" && [ -z "$W" ]; then
  AGOK=1
  PHPB="$(command -v php || true)"; BB="$(command -v bash)"
  if [ -n "$PHPB" ] && [ -f "$AG/actualizar.php" ]; then
    C+=("$PHPB $AG/actualizar.php $HIJA" "$PHPB $AG/actualizar.php $HIJA *" "$PHPB $AG/latido.php" "$PHPB $AG/latido.php *")
  fi
  for h in poner-ssl poner-shalom poner-conector poner-tienda-ia; do
    [ -f "$AG/$h.sh" ] && C+=("$BB $AG/$h.sh $HIJA" "$BB $AG/$h.sh $HIJA *")
  done
fi
# Los nombres de alias de sudoers van en MAYÚSCULAS (con minúsculas, visudo lo rechaza).
AL="HARO_SOPORTE_$(echo "${HIJA//-/_}" | tr a-z A-Z)"
T="$(mktemp)"; trap 'rm -f "$T"' EXIT
{
  echo "# Soporte HARO para la tienda $HIJA — puesto con poner-soporte.sh el $(date '+%F %R'). Quitar: sudo bash $AG/poner-soporte.sh $HIJA --quitar"
  echo "Cmnd_Alias $AL = \\"
  n=${#C[@]}; i=0
  for c in "${C[@]}"; do
    i=$((i + 1))
    e="${c//,/\\,}"; e="${e//:/\\:}"
    if [ "$i" -lt "$n" ]; then echo "    $e, \\"; else echo "    $e"; fi
  done
  echo "$U ALL=(root) NOPASSWD: $AL"
} > "$T"
VS="$(command -v visudo || echo /usr/sbin/visudo)"
"$VS" -cf "$T" >/dev/null || { echo "El permiso no pasó la revisión de visudo: no se tocó sudoers." >&2; exit 1; }
if [ "$ENSAYO" = 1 ]; then
  cat "$T"
  echo "ENSAYO: ese es el permiso que daría a $U (${#C[@]} órdenes) y pasó la revisión de visudo. No se tocó nada."
  exit 0
fi
install -m 440 -o root -g root "$T" "$F"
visudo -c >/dev/null || { rm -f "$F"; echo "sudoers quedó mal: lo quité." >&2; exit 1; }
say "Permiso justo para $HIJA: ${#C[@]} órdenes$([ "$AGOK" = 1 ] || echo " (sin el agente: $AG no es solo de root)")."

# 3) ¿El SSH de este servidor lo dejará entrar?
P="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')"; P="${P:-22}"
AU="$(sshd -T 2>/dev/null | awk '/^allowusers /{$1=""; print}')"
AGR="$(sshd -T 2>/dev/null | awk '/^allowgroups /{$1=""; print}')"
PK="$(sshd -T 2>/dev/null | awk '/^pubkeyauthentication /{print $2}')"
[ "$PK" != "no" ] || say "OJO: este SSH no acepta llaves (PubkeyAuthentication no): $U no podrá entrar."
if [ -n "$AU" ] && ! grep -qw "$U" <<<"$AU"; then say "OJO: este SSH solo deja entrar a:$AU — agrega $U a AllowUsers para que funcione."; fi
if [ -n "$AGR" ] && ! id -nG "$U" | tr ' ' '\n' | grep -qxF -f <(tr ' ' '\n' <<<"$AGR" | sed '/^$/d'); then say "OJO: este SSH solo deja entrar a los grupos:$AGR"; fi
# Corrido desde una copia suelta (p. ej. ~/poner-soporte.sh en una hija vieja): queda guardado junto al agente, para
# volver a correrlo o quitarlo después; la copia suelta se borra.
if [ "$AGOK" = 1 ] && [ "$(readlink -f "$0")" != "$(readlink -f "$AG/poner-soporte.sh")" ]; then
  install -m 700 -o root -g root "$0" "$AG/poner-soporte.sh"
  case "$(readlink -f "$0")" in /home/*|/root/*) rm -f -- "$0" ;; esac
fi
echo "LISTO: soporte de $HIJA · usuario $U · puerto SSH del servidor $P"
