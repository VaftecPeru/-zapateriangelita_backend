# Zapatería Angelita — publicación controlada exclusivamente desde GitHub Actions

**Fecha de planificación:** 2026-10-27. **Estado:** NO-GO para despliegue.

Este documento es el contrato técnico para construir un flujo de despliegue seguro.
El código actual solamente obtiene evidencias: **no configura cPanel, no realiza backup,
no migra datos, no cambia producción y no ejecuta rollback**. Nunca considerar CI verde
como autorización de producción.

## Identificación del release

- Frontend de main, commit conocido: `b2ed5e1180408f2f405eb0636b052864752d14af`.
- Backend de main, commit conocido: `443f5f8681c569bfa7a937f5181147a38d94e99b`.
- Backend en develop contiene adicionalmente migración idempotente para
  `password_reset_tokens` y preflight REST, y **no se encuentra en main todavía**.
- Artefacto frontend generado por `Build dist final verified`:
  `zapateriangelita-dist-final-verificado`.
- Artefacto backend generado por `Package cPanel backend (no deploy)`:
  `angelita-backend-main-cpanel-vendor` (contiene ZIP y `SHA256SUMS.txt`).
- Requisitos de sistema: backend PHP ^8.2, Laravel 12, base MySQL/MariaDB.

## Etapa A: auditoría desde GitHub Actions (implementada en PR)

`.github/workflows/release-evidence.yml`:

1. Verificar que los dos SHAs propuestos siguen siendo exactamente el HEAD de
   `main` de sus repositorios. Si avanzó alguna rama, detener y revisar el release.
2. Verificar que los cuatro workflows de validación y empaquetado sean
   `completed/success` para esos SHAs; no aceptar un éxito antiguo de otro commit.
3. Verificar que los dos artefactos existen, tienen tamaño > 0 y no están vencidos.
4. Publicar `release-evidence.json` y GitHub Step Summary con los resultados,
   marcando siempre `production_approval=false` y `deployment_decision=NO-GO`.

Límites: el chequeo de artefactos lee **metadatos de GitHub**, no descarga ni
verifica todavía la integridad binaria del ZIP. No usa secretos ni credenciales
de cPanel. En caso de GitHub API inaccesible, falla bloqueando la liberación.

### Disparo manual del control

Cuando este workflow esté disponible en la rama predeterminada, abrir
**GitHub Actions → Angelita release evidence - GitHub only → Run workflow**.
Confirmar los dos SHAs en el formulario. Un resultado PASS solamente
comprueba la evidencia de GitHub, no autoriza el servidor.

## Etapa B: preparación del futuro despliegue (AÚN NO IMPLEMENTADA)

Se requiere diseñar y aprobar un workflow `workflow_dispatch` separado que:

1. Use un GitHub **Environment de producción**, con **required reviewers**,
   rama restringida a `main` y prevención de self-approval cuando sea posible.
   El archivo YAML por sí solo NO configura esas reglas.
2. Requiera confirmación explícita del identificador de release y los SHAs
   verificables de ambos repositorios antes de obtener secretos.
3. Use credenciales técnicas de mínimo privilegio guardadas en
   **GitHub Environment secrets** y huella conocida del host (p. ej. SSH/SFTP
   o una API segura habilitada por el proveedor). No colocar credenciales,
   tokens, rutas privadas o contraseñas en el repositorio, logs ni artefactos.
   El tipo de conexión depende de lo que soporte el alojamiento; no se ha
   confirmado todavía SSH/SFTP en este hosting.
4. Detecte rutas reales del document root frontend, directorio backend Laravel,
   directorios de imágenes fuera/debajo de public_html y ubicación segura
   externa al document root para los respaldos. Sin asumir rutas por nombre.
5. Ejecute **antes de modificar archivos**: backup consistente de MySQL,
   copia protegida de `.env`, `storage`, imágenes y archivos actuales,
   manifest SHA256, inventario del contenido del backup, prueba no destructiva
   de restaurabilidad y verificación de capacidad de almacenamiento. Si
   alguno falla, no publicar.
6. Descargue los dos artefactos identificados por SHA de commit/workflow,
   valide ZIP contra contenido esperado, detecte rutas peligrosas al extraer,
   verifique SHA256 firmado o procedencia del build y prepare un directorio
   temporal de staging.
7. Sincronice sólo archivos de aplicación permitidos; jamás sobrescribir
   `.env`, imágenes de cliente, `storage`, sesiones, secretos o datos
   persistidos. Eliminar archivos antiguos únicamente después de una
   comparación explícita, no usando un borrado amplio.
8. Ejecute `php artisan migrate:status` y comprobaciones de PHP/vendor,
   OPcache, permisos y cron. Una migración que toque producción requiere
   backup verificado, preflight de impacto y aprobación independiente.
   Prohibido `migrate:fresh`, `db:wipe`, seeders destructivos y
   restablecimiento de llaves/usuarios.
9. Revalide health JSON, CORS, login con cuenta de QA, variantes/fotografías,
   checkout sin cobro real y comportamiento del servidor SMTP.
10. Si falla un control posterior al despliegue, habilite procedimiento de
    rollback probado sobre **el mismo release identificado** y restaure
    archivos/datos solo según un plan explícito aprobado; una recuperación
    de base de datos puede perder órdenes nuevas y exige una decisión humana.
11. Publique resumen de ejecución y auditoría sin adjuntar archivos .env,
    imágenes privadas, dump MySQL, claves ni tokens como artefactos públicos.

## Restricciones que mantienen el NO-GO

- No se dispone de una conexión a hosting desde Actions verificada.
- No están configurados/validados secretos del Environment ni sus revisores.
- No existe backup MySQL/restauración y rollback **probados desde Actions**.
- No hay verificación de PHP/vendor/migraciones actuales en cPanel desde Actions.
- `smtp.gmail.com:587` mostró timeouts repetidos; el cambio de latencia del
  backend no restablece por sí solo la salida SMTP.
- El despliegue de frontend y backend debe validarse de manera coordinada,
  con especial atención a catálogo/inventario/importación y OpenPay.
- El incidente previo de JavaScript challenge antibot debe seguir monitoreado;
  la disponibilidad en un cliente no garantiza disponibilidad general.
- `main` y la producción deben permanecer estables hasta que el responsable
  autorice explícitamente el lanzamiento.

## Comprobaciones de seguridad antes de habilitar un futuro workflow de despliegue

- [ ] GitHub Environment de producción con revisores y ramas restringidas.
- [ ] Mecanismo seguro de conexión hosting probado desde runner de Actions.
- [ ] Respaldos integrales con checksums y prueba de restaurabilidad.
- [ ] Plan de recuperación probado sin borrar datos recientes.
- [ ] Verificación binaria de paquetes y correspondencia con SHA de código.
- [ ] Preflight de migración, cambios PHP y persistencia de imágenes.
- [ ] UAT credenciales de QA, checkout sin cobro, SMTP y health.
- [ ] Autorización final de publicación consignada en GitHub.

**Fuentes de seguimiento:** issues #92 y #93. La automatización no desplegará
hasta que las condiciones anteriores estén demostradas y aprobadas.
