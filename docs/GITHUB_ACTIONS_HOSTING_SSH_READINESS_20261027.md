# Zapatería Angelita — siguiente control de despliegue por GitHub Actions

Fecha: 27/10/2026. **Estado operativo: NO-GO para deploy real.**

## Qué implementa este PR

`.github/workflows/ssh-readonly-readiness.yml` comprueba la sintaxis y los tests
del script de control en Pull Requests. En un futuro, **una vez integrado en
la rama predeterminada `main`**, podrá ejecutarse manualmente para probar si
un runner oficial de GitHub Actions puede autenticar por SSH con una cuenta de
**solo lectura** al hosting que aloja el backend de Zapatería Angelita.

El comando remoto está fijado en el código y únicamente usa `printf`,
`command -v` y `test -x` para identificar PHP, utilidad de dump MySQL,
`tar` y `gzip`. No transmite contraseñas ni consulta `.env`, BD, productos
o clientes. No usa FTP, SSH interactivo, cPanel manual ni Desktop Commander.
No hace deploy, backup, migraciones ni rollback.

**IMPORTANTE:** un resultado `SUCCESS` del SSH-readonly gate únicamente
acredita conectividad/capacidades mínimas. No acredita lectura/escritura de
archivos de producción ni una copia de seguridad válida.

## GitHub Environment (requiere configuración explícita, aún no verificada)

En el repositorio del backend, abrir:

`Settings → Environments → New environment → angelita-production-readonly`

Antes de ejecutar el primer workflow, configurar y verificar:

1. **Required reviewers** con una persona o equipo responsable del lanzamiento;
   activar `Prevent self-review` cuando sea apropiado.
2. Restricción de ramas de deployment: permitir exclusivamente `main`.
3. Guardar en *Environment secrets*, nunca en el repositorio o chat:
   - `ANGELITA_READONLY_SSH_HOST`: nombre DNS/IP del servicio SSH autorizado.
   - `ANGELITA_READONLY_SSH_USER`: usuario restringido de solo lectura.
   - `ANGELITA_READONLY_SSH_PRIVATE_KEY`: clave privada dedicada al diagnóstico,
     almacenada únicamente en GitHub, no reutilizar la clave de producción.
   - `ANGELITA_READONLY_SSH_KNOWN_HOSTS`: línea(s) de clave pública del host
     verificada(s) por un canal confiable, NO obtenerla con `ssh-keyscan`
     automáticamente sin verificar su huella.
4. Opcionalmente, registrar *Environment variable*:
   - `ANGELITA_READONLY_SSH_PORT` (predeterminado: 22).

Las reglas del Environment NO se crean con este YAML. Si el nombre del
Environment no existía, GitHub podría crearlo sin protecciones. Por eso se
deben establecer y verificar las reglas antes del primer disparo manual.

La identidad SSH debe ser temporal/restringida y estar autorizada por el
titular del hosting; una llave con permisos de escritura sería inapropiada
para esta fase. No se ha confirmado que el plan GoDaddy/cPanel permita acceso
SSH. Si no lo admite, NO habilitar SSH inseguro ni abrir nuevos puertos por
comodidad: se necesita diseñar una alternativa específica y aprobada para
GitHub Actions, como una API limitada y autenticada.

## Ejecución futura manual

Cuando el workflow esté integrado específicamente a `main` y configurado:

1. GitHub → Actions → **Angelita SSH readiness - read only**.
2. Run workflow → rama `main` → confirmación `READ_ONLY_PROBE`.
3. Esperar las revisiones del Environment, si están habilitadas.
4. Inspeccionar `static-security-validation` y `probe-ssh`.

La comprobación remota no se ejecuta en el evento pull_request.
Si faltan secretos, huella del servidor, autenticación o utilidades, falla
cerrado. No imprime secretos, hostname, usuario, clave ni stderr remoto.

## Pendientes obligatorios después de un SSH SUCCESS

- Copia de seguridad consistente MySQL/MariaDB + verificación de restauración.
- Copia de `.env`, `storage`, imágenes y archivos actuales, fuera de webroot.
- Control de integridad y procedencia de artefactos frontend/backend.
- Plan de rollback aprobado y probado sin pérdida de órdenes nuevas.
- Comprobar runtime PHP, `vendor`, `migrate:status`, scheduler y permisos.
- UAT con pruebas de login, recuperación de contraseña, variantes, inventario,
  carrito, checkout sin cobro y SMTP real.
- Despliegue mediante **otro workflow protegido**, inexistente por ahora,
  con ambiente de producción, controles previos y autorización expresa.

No fusionar este flujo a `main` automáticamente ni confundir aprobación
de Pull Request con autorización de despliegue.

Documentación GitHub: https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments
