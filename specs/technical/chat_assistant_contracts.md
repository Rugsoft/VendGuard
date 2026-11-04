# ESPECIFICACIÓN TÉCNICA · INTEGRACIÓN ASISTENTE CHATBOT (n8n)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/chat_assistant_contracts.md`  
**Protocolo:** JWT (RFC 7519) · HS256 · Webhook n8n  
**Metodología:** SDD (Specification-Driven Development) · Contratos Técnicos  

---

## 1. Propósito y Alcance

Permitir la asistencia contextual e interactiva a personal interno (Coordinadores y Técnicos de Ruta) mediante el widget conversacional de **n8n**, asegurando que:
1. El chatbot solo esté visible y operativo para usuarios autenticados.
2. Cada mensaje transmita la identidad criptográficamente verificada del usuario (`sub`, `name`, `role`) a través de los metadatos del widget.
3. Se conserve intacto el sistema de sesión existente de VendGuard (`auth_token_...`), evitando regresiones en la API y el MVP.

---

## 2. Contrato de Criptografía y Token JWT

### 2.1 Especificación del Token (`chat_token`)
El backend emitirá un JSON Web Token (JWT) conforme al estándar **RFC 7519**, firmado con algoritmo simétrico **HMAC-SHA256 (HS256)**.

* **Secreto de firma:** La misma clave resuelta por `SecretProvider::authSecret()` (`SECRET_KEY` en producción, fallback dev en local).
* **Estructura (3 partes en base64url separadas por puntos):**
  `header.payload.signature`

#### Header:
```json
{
  "alg": "HS256",
  "typ": "JWT"
}
```

#### Payload (Claims requeridos por el flujo de n8n):
```json
{
  "sub": "12",
  "name": "Carlos García",
  "email": "carlos@vendguard.com",
  "role": "coordinador",
  "iat": 1730712000,
  "exp": 1730740800
}
```
* `sub`: Identificador único del usuario (cadena numérica o entera correspondiente a `User::getId()`).
* `name`: Nombre completo del usuario (`User::getName()`).
* `email`: Correo del usuario (`User::getEmail()`).
* `role`: Rol del usuario en minúsculas (`coordinador` o `tecnico`), derivado de `UserRole`.
* `iat`: Timestamp Unix de emisión.
* `exp`: Timestamp Unix de expiración (vigencia estándar de 8 horas = 28.800 segundos).

---

## 3. Contratos de la API REST

### 3.1 Respuesta de Login Interno (`POST /api/auth/login`)
Se extiende el payload de respuesta exitosa (`200 OK`) añadiendo el campo `chat_token`. Los campos `token` y `user` permanecen 100% inalterados.

```json
{
  "success": true,
  "data": {
    "token": "auth_token_eyJhbGciOi...",
    "chat_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMiIsIm5hbWUiOiJDYXJsb3MgR2FyY8OtYSIsInJvbGUiOiJjb29yZGluYWRvciIsImV4cCI6MTczMDc0MDgwMH0.signature...",
    "user": {
      "id": 12,
      "name": "Carlos García",
      "email": "carlos@vendguard.com",
      "role": "COORDINATOR",
      "phone": "+34600112233"
    }
  },
  "message": "Sesión iniciada correctamente"
}
```

---

## 4. Integración en el Frontend (Vue 3 SPA)

### 4.1 Gestión de Estado (`public/assets/js/store.js`)
* Se agrega `chatToken` al estado global reactivo (`state.chatToken`).
* Al ejecutar `setInternalSession(user, token, chatToken)`:
  * Se almacena `chatToken` en el estado y en `localStorage` (dentro de `vendguard_internal_session`).
* Al ejecutar `logout()`:
  * Se limpia `chatToken` del estado y del almacenamiento local.
  * Se dispara la destrucción o limpieza del widget de n8n del DOM.

### 4.2 Carga y Montaje del Widget (`public/assets/js/components/ChatWidget.js` o integración en `app.js`)
* **Condición de renderizado:** Solo se instancia si `state.user` está activo y existe `state.chatToken` vigente.
* **Webhook destino:** `https://rugsoft.app.n8n.cloud/webhook/a6c77576-7554-4138-8605-ea2f72c0505e/chat`.
* **Configuración del cliente n8n:**
  ```javascript
  createChat({
    webhookUrl: 'https://rugsoft.app.n8n.cloud/webhook/a6c77576-7554-4138-8605-ea2f72c0505e/chat',
    metadata: {
      token: state.chatToken
    }
  });
  ```
* **Comportamiento en Logout:** Al cerrar sesión, el elemento contenedor `#n8n-chat` o los nodos inyectados por el script se remueven del DOM para evitar sesiones huérfanas.

---

## 5. Criterios de Aceptación y Validación (Fase 2 Gate)

| ID | Criterio | Verificación |
| :--- | :--- | :--- |
| **CA-01** | `AuthService::generateChatToken(User $user)` emite un JWT válido con 3 segmentos separados por punto. | Test unitario verificando estructura `base64(header).base64(payload).base64(hmac)`. |
| **CA-02** | Los claims `sub`, `name`, `role`, `exp` se corresponden fielmente con la entidad `User`. | Test unitario de decodificación y aserción de claims. |
| **CA-03** | `POST /api/auth/login` devuelve tanto `token` tradicional como `chat_token`. | Test de integración en `AuthControllerTest.php`. |
| **CA-04** | El secreto HMAC empleado por el backend coincide con `SecretProvider::authSecret()`. | Test criptográfico de verificación cruzada con `hash_hmac`. |
| **CA-05** | El frontend monta el widget únicamente ante login interno exitoso y lo remueve en logout. | Verificación de ciclo de vida en `store.js` / interfaz web. |
