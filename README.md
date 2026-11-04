# Central de hijas

Vive SOLO en la madre tecnova (`/var/www/tecnovaplus/central`, repo privado `harosolution/central`).
No es parte del CRM y no llega a ninguna hija.

- El CRM de la madre la carga porque su `.env` dice `extensiones.rutas = /var/www/tecnovaplus/central`
  (`App\Libraries\Extensiones`). En las hijas esa línea no existe y no se carga nada.
- Base propia `central` (usuario `central`). Credenciales en `config.php`, que NO va a git.
- `agente/latido.php` es lo que se instala en cada hija (ver su cabecera).

## Qué hay (fase 1)
- `/central`: panel (super_admin de la empresa de la madre).
- `POST /central/api/latido`: cada hija avisa versiones y salud cada 10 min, con su llave.
- `GET /central/api/estado`: la ⚙️ de la hija pregunta si está al día.
- Última versión publicada = tag `vX.Y.Z` más alto de los repos de la madre (lo crea `publicar`).

## Fase 6: instalador de una línea
- En `/central/hijas`, a una hija sin señal: «Instalar en un servidor nuevo» (empresa, nombre y correo del admin; el
  dominio es el de la hija) → comando `curl -fsSL …/central/instalar/hi_… | sudo bash`. Vale 48 h y sirve hasta que
  una instalación termine bien. Cada canje rota la llave de la hija (tabla `instalaciones`, solo el sha256 del código).
- El script es `agente/instalar.sh` con los datos puestos por `Instalador::script()`. Instala lo que falte (apt), baja
  el paquete firmado, crea base/usuario propios con `deploy/instalar/esquema.sql` + `semilla.sql`, `.env` con secretos
  nuevos, admin, sitio Apache (+ certbot si el dominio apunta ahí) y el agente en `/opt/haro-agente` (cron de root).
  Si falla, deshace todo lo que creó.
- Chat y Robot (fase 6b, casilla marcada por defecto): `chat.<dominio>` (robot en `/tecnobot/`), servicios `haro-<hija>-chat|robot` como el usuario `haro`, Node 20 (el del sistema u oficial en /opt/haro-node), configuración del robot NEUTRA (`instalar/robot-inicial.json`, sin datos de TECNOVA, apagado, IA sin key). Paquetes: `empaquetar.php chat|robot X.Y.Z`. Ayudantes en la hija: `/opt/haro-agente/poner-ssl.sh <hija>` y `poner-tienda-ia.sh <hija> ria_…`.
- Solo se instala un paquete con `.instalable` (lo deja `herramientas/empaquetar.php`, que llama a `semilla.php` con sudo).

## Nunca
- Datos del negocio de una hija, llaves de IA, sesiones de WhatsApp: no pasan por aquí.
- `config.php` en git.
