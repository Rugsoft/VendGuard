# Propuesta de Decisión y Especificación Técnica · Hallazgo S-3 — Rate Limiting y Bloqueo de Cuenta en Login Interno

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Fecha:** 8 de octubre de 2026 · **Rama:** `incident-comments`  
**Estado:** 🟡 **Pendiente de Aprobación Humana (Gate SDD Fase 2)**  
**Ámbito:** RF-04 y HU-04 ([`specs/functional/mvp_functional_spec.md`](../specs/functional/mvp_functional_spec.md)), `POST /api/auth/login`, `AuthService::loginInternal`, [`specs/technical/api_contracts.md`](../specs/technical/api_contracts.md)  
**Referencias:** S-3 en [`specs/technical/auditoria_arquitectura_triage.md`](../specs/technical/auditoria_arquitectura_triage.md) §1/§3, precedent en migración `013_refund_pickup_pin_lockout.sql` y `014_location_access_code.sql`  

---

## 1. Contexto y Problema

En la auditoría de arquitectura se detectó el hallazgo **S-3 (Crítica)**:
- El endpoint `POST /api/auth/login` permite intentos ilimitados de fuerza bruta contra las cuentas de coordinadores y técnicos (`users`).
- Tras resolver **S-4** (donde se implementó un freno de 5 intentos fallidos con bloqueo de 15 minutos en `locations`), el login interno quedó como la única puerta desprotegida contra ataques de diccionario o fuerza bruta.
- El objetivo es implementar un mecanismo de freno a nivel de cuenta (`users`), alineado con las convenciones ya aprobadas en S-4 y el PIN de reintegros, garantizando autodeterminación y auto-recuperación temporal.

---

## 2. Decisiones de Diseño Propuestas

1. **Granularidad del Freno (A nivel de cuenta/usuario):**
   - El contador de intentos fallidos pertenece a la cuenta atacada en la tabla `users` (`login_attempts`, `login_locked_until`), siguiendo el mismo diseño que `locations` (S-4) y `refund_requests` (PIN).
   - No requiere una tabla externa de sesiones ni almacenamiento volátil en memoria (Dogma Vanilla, persistencia relacional pura en MariaDB/MySQL).

2. **Parámetros y Umbrales:**
   - **Intentos máximos fallidos consecutivos:** 5 (`INTERNAL_LOGIN_MAX_ATTEMPTS = 5`).
   - **Tiempo de bloqueo temporal:** 15 minutos (`INTERNAL_LOGIN_LOCK_SECONDS = 900`).
   - **Auto-recuperación:** Transcurridos los 15 minutos, el bloqueo expira automáticamente (`login_locked_until <= NOW()`), permitiendo nuevos intentos sin intervención manual.
   - **Reinicio:** Un login exitoso reinicia inmediatamente `login_attempts = 0` y `login_locked_until = NULL`.

3. **Prevención de Oráculos de Enumeración:**
   - Si se introduce un correo electrónico que **no existe** en la base de datos, el sistema responde con el `401 INVALID_CREDENTIALS` habitual, sin alterar contadores y sin revelar la inexistencia del correo.
   - Si la cuenta existe y está bloqueada (`is_locked`), el sistema responde `423 Locked` (`ACCOUNT_LOCKED`) con el mensaje:
     *«Cuenta temporalmente bloqueada por demasiados intentos fallidos. Inténtelo de nuevo en unos minutos.»*
   - Si las credenciales son incorrectas antes de llegar al umbral de bloqueo, responde `401 INVALID_CREDENTIALS`.
   - Al registrar el 5º intento fallido consecutivo, la respuesta inmediata es `423 Locked` (`ACCOUNT_LOCKED`).

4. **Auditoría y Trazabilidad (Art. III):**
   - Al bloquearse la cuenta, se emite un evento inmutable en `audit_log`:
     `USER_LOGIN_LOCKED` (`entity_type = USER`, `entity_id = user.id`, `metadata: { reason: "MAX_FAILED_ATTEMPTS", locked_until: "..." }`).
   - Ninguna contraseña o dato sensible viaja a los logs de auditoría.

---

## 3. Enmienda de Especificación Funcional (RF-04)

### En `specs/functional/mvp_functional_spec.md`:

**EARS 4.2 (Excepción) — Actualización:**
> Si las credenciales son incorrectas, entonces el sistema deberá rechazar el acceso con un mensaje genérico de error y no revelar si el fallo reside en el usuario o en la clave (`401 INVALID_CREDENTIALS`), incrementando el contador de intentos fallidos de la cuenta si el usuario existe.

**EARS 4.5 (Excepción/Bloqueo) — Nuevo:**
> Si se superan 5 intentos fallidos consecutivos en una cuenta interna, el sistema deberá bloquear temporalmente el acceso a dicha cuenta durante 15 minutos (`423 ACCOUNT_LOCKED`), registrando el evento de bloqueo en la auditoría inmutable. Trascurrido el periodo de penalización, el bloqueo expirará automáticamente.

---

## 4. Contrato Técnico API

### En `specs/technical/api_contracts.md`:

```markdown
### 2.2 `POST /api/auth/login` (Acceso Personal Interno)
Inicio de sesión para Coordinadores y Técnicos de Campo (RF-04).

* **Request Body:**
```json
{
  "email": "jordi.ruta@vendguard.internal",
  "password": "Password123!"
}
```

* **Respuestas de Error:**
  - `400 Bad Request` (`MISSING_CREDENTIALS`): Correo o contraseña vacíos.
  - `401 Unauthorized` (`INVALID_CREDENTIALS`): Correo o contraseña incorrectos.
  - `423 Locked` (`ACCOUNT_LOCKED`): Cuenta temporalmente bloqueada tras 5 intentos fallidos.
    Headers opcionales: `Retry-After: 900`.
```

---

## 5. Cambios Estructurales y Base de Datos

1. **Migración `015_user_login_lockout.sql`:**
   - Añade a `users`:
     - `login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active`
     - `login_locked_until DATETIME NULL DEFAULT NULL AFTER login_attempts`
   - Espejo idéntico en `database/cloud_init.sql` para paridad con TiDB/despliegues cloud.
2. **Modelo y Dominio (`User.php`, `UserRepositoryInterface.php`, `PdoUserRepository.php`):**
   - Incorporar métodos de estado de acceso o actualizar `UserRepositoryInterface` para registrar fallos y reinicios (`findLoginState`, `registerFailedLogin`, `resetLoginAttempts`).
3. **Servicio (`AuthService.php`):**
   - Actualizar `loginInternal(string $email, string $plainPassword, ?string $clientIp = null)` para gestionar el contador y evaluar el bloqueo.
4. **Controlador (`AuthController.php`):**
   - Mapear el estado `ACCOUNT_LOCKED` a respuesta HTTP `423`.
