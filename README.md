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

## Nunca
- Datos del negocio de una hija, llaves de IA, sesiones de WhatsApp: no pasan por aquí.
- `config.php` en git.
