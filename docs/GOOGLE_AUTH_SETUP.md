# Google Identity Services - Zapatería Angelita

## Objetivo

La compra continúa siendo invitada. Después de que Openpay confirma el pago, Zapatería Angelita crea o vincula la cuenta del cliente, inicia sesión automáticamente y redirige a `Mis Compras`. Desde esa sesión el cliente puede vincular Google para futuros accesos sin contraseña.

El acceso con Google **no reemplaza** la contraseña temporal enviada por correo. Ambos métodos quedan disponibles.

## 1. Crear credencial OAuth 2.0 en Google Cloud

Crear un **OAuth Client ID** de tipo **Web application** y registrar como orígenes JavaScript autorizados:

- `https://zapateriangelita.com`
- `https://www.zapateriangelita.com` si se utiliza ese host
- Para desarrollo, opcional: `http://localhost:5173`

La integración usa Google Identity Services en modo popup, por lo que el frontend recibe un ID token y lo envía al backend para validación.

## 2. Configurar backend

Agregar al `.env` del backend:

```env
GOOGLE_CLIENT_ID=xxxxxxxxxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com
```

No se requiere `GOOGLE_CLIENT_SECRET` para este flujo de ID token.

Si Laravel tiene configuración cacheada y no existe terminal, desde el File Manager de cPanel eliminar únicamente `bootstrap/cache/config.php` si existe. No eliminar `.gitignore` ni otros archivos del directorio.

## 3. Base de datos

### Con terminal

```bash
php artisan migrate
```

### Sin terminal

Ejecutar una sola vez en phpMyAdmin:

`database/manual/2026_09_16_google_identity_users.sql`

Antes de ejecutarlo, realizar respaldo de la tabla `users`.

## 4. Flujo funcional

1. Cliente compra como invitado.
2. Openpay + 3D Secure confirma el pago.
3. Backend crea la cuenta cliente si no existe.
4. Backend devuelve `auth_token` y el frontend inicia sesión automáticamente.
5. Cliente aterriza en `/profile/purchases`.
6. Si todavía no vinculó Google, ve la tarjeta **Vincular Google**.
7. Google devuelve un ID token firmado.
8. Backend valida firma RS256, `aud`, `iss`, expiración, `sub` y correo verificado.
9. El backend guarda `google_sub` como identificador estable.
10. En futuros accesos, el cliente puede usar **Continuar con Google** en Login.

## 5. Reglas de seguridad

- Nunca se vincula Google únicamente porque el usuario escribió un correo durante checkout.
- `google_sub` es el identificador estable de Google y se mantiene oculto en las respuestas JSON.
- Para una vinculación desde `Mis Compras`, el correo Google debe coincidir con el correo de la cuenta autenticada.
- Para auto-vinculación durante login, solo se permite por correo cuando Google es autoridad del correo (Gmail o Google Workspace). Otros dominios deben iniciar sesión primero y vincular desde la cuenta.
- Las cuentas `admin` no se auto-vinculan por correo durante login.
- El backend valida localmente la firma JWT con los certificados públicos de Google y los cachea; no depende del endpoint `tokeninfo` de depuración.

## 6. Endpoints

- `GET /api/auth/google/config`
- `POST /api/auth/google/login`
- `POST /api/auth/google/link` (requiere Sanctum)
