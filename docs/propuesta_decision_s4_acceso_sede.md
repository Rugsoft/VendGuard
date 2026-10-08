# Propuesta de Decisión de Producto y Enmienda de RF-01 · Hallazgo S-4 — Acceso de Sede sin Credencial

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Fecha:** 8 de octubre de 2026 · **Rama:** `incident-comments`
**Estado:** ✅ **Decisión aprobada y aplicada el 2026-10-08** (protocolo `AGENTS.md` §2, Fase 2: Puerta de Aprobación superada). El Product Owner resolvió las cinco decisiones de §8 en el sentido recomendado —opción A, freno por sede en la misma tanda, fallo en cerrado, solo huella y sin tocar el parámetro QR— y la enmienda de §5 se aplicó a las especificaciones y al código ese mismo día. El documento conserva su redacción original y añade la resolución en §11 para trazabilidad.
**Ámbito:** RF-01 y HU-01 ([`specs/functional/mvp_functional_spec.md`](../specs/functional/mvp_functional_spec.md)), `POST /api/auth/site-login`, `SiteAuthMiddleware`, avisos de seguridad de [`api_contracts.md`](../specs/technical/api_contracts.md) y [`refunds_contracts.md`](../specs/technical/refunds_contracts.md)
**Referencias:** S-4 en [`auditoria_arquitectura_triage.md`](../specs/technical/auditoria_arquitectura_triage.md) §1/§3 · escalada a 🔴 en [`analisis_sexta_tanda.md`](../specs/08-refunds/analisis_sexta_tanda.md) §2 (H-1) y §5 · [`constitution.md`](../constitution.md) Art. III y V
**Decide:** el Product Owner (AGENTS.md §5.5). **No decide:** el rate-limiting de login (S-3), que mantiene su carril propio aunque aquí se declare su dependencia.

---

## 1. La decisión en una página

1. Hoy el **código de sede es a la vez el identificador y la credencial**: `POST /api/auth/site-login` emite el token a cambio solo del código, y la cabecera `X-Site-Code` abre las mismas rutas sin token. Son la misma puerta.
2. Ese código es **público por construcción**: viaja en la URL codificada en la etiqueta QR de cada máquina (`/?qr=VEND-0101&site=SEDE-BCN-01`) y los códigos semilla son correlativos (`SEDE-BCN-01` … `SEDE-BCN-10`).
3. Con él se **lee** (reintegros, certificados sanitarios) y se **escribe** (`POST /api/incidents` devuelve `201` con fila real) sin haber pisado el edificio. Esa escritura es lo que escaló el hallazgo de 🟡 a 🔴.
4. Hay dos familias de arreglo:
   * **A — clave de centro emitida en el alta y entregada en mano.** Una credencial que no está impresa en ninguna etiqueta. **Recomendada.**
   * **B — emparejamiento en la primera visita.** Solo cierra S-4 si el emparejamiento exige a su vez un código emitido por coordinación; en ese caso es «A + dispositivo recordado», con dos tablas más y más modos de fallo. Como mejora de experiencia futura encaja; como sustituto de A, no.
5. Recomendación: **A + retirada de `X-Site-Code`** (una sola puerta: el token de 24 h) y **S-3 como decisión compañera**: sin freno de intentos, una clave de 10 caracteres sigue siendo atacable en línea.
6. Coste estimado de A: **~1 jornada de código y pruebas** más la logística de entrega de las sedes existentes. No cambia la sesión de 24 h, ni el flujo público del QR, ni el modelo de datos de negocio.

---

## 2. Hechos verificados (por qué hace falta una credencial)

### 2.1 Las dos puertas y el alcance de escritura
* `AuthService::loginSite()` busca la sede por `site_code` y devuelve `generateSiteToken($location)`: **la credencial que se presenta y la que se devuelve son el mismo código**; no hay nada más que verificar.
* `SiteAuthMiddleware` acepta dos caminos: bearer `site_token_…` **o** cabecera `X-Site-Code` (paso 3, sin token). Retirar uno no cerraba el otro: hay que cerrar los dos a la vez.
* Alcance medido en la sexta tanda sobre endpoints reales (2026-10-02): `GET /api/location/refunds` **200** con importes y estados; certificados sanitarios de sede **200**; `POST /api/incidents` **201** con fila real creada en `incidents`. La última fila es la que convirtió el hallazgo en crítico: no es solo lectura.

### 2.2 El código no es un secreto
* `QrCodeData::getTargetUrl()` compone `{base}/?qr={machineCode}&site={siteCode}` y `QrLabelService` codifica ese URL en la matriz de cada etiqueta. Cualquier móvil con cámara lee el código de la sede.
* Los códigos semilla son correlativos ([`database/seeds.sql`](../database/seeds.sql)): adivinar el de al lado no requiere ni una etiqueta.
* Conclusión: el eslabón débil no es la etiqueta física, es que **no exista nada más que el código**.

### 2.3 Qué protege de verdad una credencial nueva
* **No** protege contra quien ya tiene acceso físico a la conserjería: si la clave vive allí, la tiene.
* **Sí** protege contra el atacante remoto: el que fotografió un código, el que prueba `SEDE-BCN-03` porque el patrón es evidente y el que, desde cualquier sitio, quiere leer expedientes de reintegros o sembrar averías en otra sede.
* Efecto lateral buscado: con un error genérico, el login deja de ser un oráculo de existencia de sedes. La enumeración pierde valor aunque S-3 siga pendiente para el login interno.

### 2.4 Qué fija hoy el comportamiento (para no romperlo por descuido)
* `SiteManagerRefundDataSegregationTest` 4.6 — «GET sede acepta X-Site-Code según el contrato» (200).
* `AuthMiddlewareTest` 1.3/1.4 — cabecera errónea `401` / válida `200`; `IncidentCommentRoutesVerificationTest` 2.9 — el `401` de sede cita `X-Site-Code`; `SiteSanitaryApiTest` bloque 7; `LocationPortalControllerTest`; `LocationRefundDeliveryTest` 7.4; `SiteManagerPartsDataSegregationTest`; `LocationPortalReopenAuditTest`; `SiteSanitaryControllerTest` 4.2.
* `FrontendApiStoreTest.php` 2.3 y `FrontendApiStoreTest.mjs` 1.5 — `api.js` inyecta la cabecera automáticamente.
* `RefundsModuleClosureTest` 7.5 — guarda documental del hallazgo.

---

## 3. Opciones

### 3.1 Opción A — Clave de centro emitida en el alta (recomendada)

* **Emisión.** Al dar de alta una sede (`AdminLocationService::createLocation`) el servidor genera una clave aleatoria. Formato propuesto: **10 caracteres** de alfabeto sin ambigüedades (sin `0/O`, `1/I/L`), presentada como `XXXXX-XXXXX`; se normaliza a mayúsculas y sin separadores antes de verificar.
* **Custodia.** Se guarda **solo la huella** (`password_hash`, bcrypt nativo ya usado por los usuarios internos) y la fecha de emisión. La clave se muestra **una sola vez** al coordinador y no vuelve a ser recuperable. Divergencia deliberada con el PIN de recogida —que sí se relee porque debe mostrarse al consumidor en cada consulta del resguardo—: la clave de centro no necesita leerse nunca más, así que un volcado de base de datos no la entrega.
* **Entrega.** Por mano, con la documentación del centro. **Nunca en la etiqueta QR** ni por canales que dejen la clave en un histórico (correo o chat). El arte de la etiqueta no cambia.
* **Acceso.** `site_code` + `access_code` → el mismo token de sede de 24 h (EARS 1.3 intacto). Error único `401 INVALID_SITE_CREDENTIALS` («Código o clave no reconocidos…»), sin distinguir sede inexistente, inactiva, clave incorrecta o clave no emitida.
* **Rotación.** Acción de coordinación `POST /api/coordinator/locations/{id}/access-code`: emite una clave nueva, invalida la anterior al instante y deja rastro en `audit_log` (Art. III) **sin registrar la clave en claro**.
* **Una sola puerta.** `X-Site-Code` se retira en el mismo cambio: el middleware, los controladores y el cliente dejan de aceptarla/inyectarla.
* **Fallo en cerrado.** Una sede sin clave emitida no puede iniciar sesión; el listado de administración la marca como «pendiente de entrega» para que coordinación la resuelva.
* **Coste.** Migración aditiva de dos columnas, servicio y controlador de administración, dos superficies de UI (alta/reemisión en coordinación; segundo campo en el portal) y la actualización de las suites listadas en §6.2.

### 3.2 Opción B — Emparejamiento en la primera visita

Dos variantes que conviene no mezclar:

* **B1 · «la primera visita gana» (descartada).** El responsable entra con el código y el dispositivo recibe un token de larga duración. **No cierra S-4**: el primero que llega puede ser el atacante; y crea un fallo nuevo que hoy no existe —la sede legítima puede quedar fuera de su propio panel porque «ya está emparejada»—. Es peor que A en seguridad y en modos de fallo.
* **B2 · código de un único uso emitido por coordinación (evolución futura).** El primer acceso exige un código de emparejamiento emitido por coordinación; después, el dispositivo recuerda la sesión. Es **A más una capa**: la seguridad la sigue dando el código emitido —con la misma logística de entrega— y lo que añade es no teclear la clave en cada dispositivo. Suma dos tablas (`location_pairing_codes`, `location_devices`), pantalla de dispositivos, revocación por dispositivo y el soporte de siempre para cuando el navegador borra el almacenamiento. Tiene sentido **después** de A, como mejora de experiencia con su propia especificación; como vehículo para cerrar S-4 es más maquinaria para el mismo resultado.

### 3.3 Comparativa

| Criterio | A · Clave de centro | B1 · Primera visita gana | B2 · Código de un uso + dispositivo |
| :--- | :--- | :--- | :--- |
| ¿Cierra S-4? | **Sí** | **No** (el primero que llega es el atacante) | Sí |
| Credencial | clave emitida y entregada en mano | ninguna (el código de sede) | código de un uso + recuerdo del dispositivo |
| ¿Impresa en la etiqueta QR? | no | el propio código de sede | el propio código de sede |
| Estado nuevo en BD | 2 columnas en `locations` | tabla de dispositivos | 2 tablas |
| UX diaria | teclear código + clave | nada nuevo | nada tras el primer emparejamiento |
| Pérdida / revocación | reemisión central (afecta a toda la sede) | — | reemisión o revocación por dispositivo |
| Fuerza bruta | clave de 10 caracteres (**necesita S-3**) | el espacio entero de códigos | código/clave (**necesita S-3**) |
| Coste aproximado | ~1 jornada + entrega | ~1–2 jornadas, y no cierra | ~2–3 jornadas + soporte de dispositivos |

*Las estimaciones son de orden de magnitud para decidir, no compromisos de calendario.*

---

## 4. Recomendación

1. **Adoptar A** y retirar `X-Site-Code` en el mismo cambio.
2. **Tratar S-3 como decisión compañera** (mismo carril inmediato): la clave sin freno de intentos es una promesa a medias, y el precedente ya existe —el PIN de recogida tiene su freno en la migración `013_refund_pickup_pin_lockout.sql`, que es el modelo a copiar para el contador por sede—.
3. **No adoptar B1.** Dejar B2 documentada como mejora futura de experiencia una vez A esté en producción; no es requisito para cerrar S-4.
4. **Mantener la sesión de sede en 24 h** (decisión V-6) y el flujo público del QR: reportar una avería por QR no requiere credencial alguna, de modo que ninguna sede se queda sin canal de reporte aunque no tenga su clave a mano (§7).

---

## 5. Lo que cambia en RF-01 (textos exactos propuestos)

### 5.1 HU-01

**Antes:**
> **HU-01:** *Como* Responsable de Ubicación, *quiero* acceder rápidamente introduciendo el código alfanumérico de mi edificio *para* ver solo las máquinas de mi centro sin tener que recordar contraseñas.

**Después:**
> **HU-01:** *Como* Responsable de Ubicación, *quiero* acceder rápidamente introduciendo el código alfanumérico de mi edificio y la clave de centro entregada en mano *para* ver solo las máquinas de mi centro sin contraseñas personales que memorizar.

### 5.2 RF-01, título y actor

* Título: «RF-01: Acceso por Código de Sede» → «**RF-01: Acceso por Código de Sede y Clave de Centro**».
* Entradilla: «El sistema permitirá a los responsables de ubicación identificarse mediante un identificador alfanumérico propio de su edificio.» → «El sistema permitirá a los responsables de ubicación identificarse mediante un identificador alfanumérico propio de su edificio **y una clave de centro emitida en el alta y entregada en mano**.»
* Actor (§2): «Accede mediante un **Código de Sede** único.» → «Accede mediante el **Código de Sede** único y la **clave de centro** entregada físicamente en el alta; ninguna de las dos es una contraseña personal que deba memorizar.»

### 5.3 Reglas EARS

**EARS 1.1 (Evento) — antes:**
> Cuando el usuario introduce un código de sede válido en formato alfanumérico (ej: `SEDE-BCN-01`), el sistema deberá autenticar la sesión y mostrar la vista del portal de sede correspondiente.

**EARS 1.1 (Evento) — después:**
> Cuando el usuario introduce en formato alfanumérico el código de sede (ej: `SEDE-BCN-01`) y la clave de centro vigente de esa sede, el sistema deberá autenticar la sesión y mostrar la vista del portal de sede correspondiente.

**EARS 1.2 (Excepción) — antes:**
> Si el usuario introduce un código de sede inexistente o inactivo, entonces el sistema deberá denegar el acceso y mostrar el mensaje de error: *"Código de sede no reconocido. Contacte con el servicio técnico."*

**EARS 1.2 (Excepción) — después:**
> Si el código de sede no existe, está inactivo, no tiene clave emitida o la clave no es la vigente, entonces el sistema deberá denegar el acceso con un mensaje **único y genérico** —«Código o clave no reconocidos. Contacte con el servicio técnico.»— sin revelar cuál de los dos factores ha fallado, y señalará en el panel de coordinación las sedes pendientes de entrega.

**EARS 1.4 (Evento) — nuevo:**
> Cuando el coordinador dé de alta una sede, el sistema deberá generar una clave de centro aleatoria, almacenar únicamente su huella criptográfica y mostrarla una sola vez para su entrega física; la clave no se imprimirá en las etiquetas QR ni se expondrá en ninguna respuesta posterior.

**EARS 1.5 (Excepción) — nuevo:**
> Si la clave de centro se pierde, se filtra o la sede cambia de responsable, cuando el coordinador emita una nueva clave, el sistema deberá invalidar de inmediato la anterior, registrar la rotación en la auditoría inmutable y mostrarla una sola vez.

**EARS 1.3 (Estado) — sin cambios**, con nota de vigencia:
> La sesión sigue siendo de **24 horas continuadas** (V-6) y la visibilidad sigue restringida a la sede. La clave no altera el token ni su TTL.

### 5.4 Contratos técnicos y documentación

| Documento | Cambio propuesto |
| :--- | :--- |
| `specs/technical/api_contracts.md` §2.1 | Cuerpo `{ site_code, access_code }`; error `401 INVALID_SITE_CREDENTIALS` genérico; nota «la clave nunca viaja en URL, logs ni respuestas posteriores». |
| `specs/technical/api_contracts.md` §3.1 | Autenticación: solo token de sesión; el «Aviso de seguridad» pasa de describir un hallazgo abierto a declarar el cierre (con puntero a este documento). |
| `specs/technical/refunds_contracts.md` §4.3.1 | Misma sustitución del aviso; autenticación `Bearer` únicamente. |
| `specs/technical/preventive_maintenance_contracts.md` §4.3 | Autenticación de sede: `Bearer` únicamente. |
| [`README.md`](../README.md) | Fila de `site-login` (cuerpo y semántica) y credenciales de la tabla de perfiles. |
| `tests/Manual/E2EVerificationRunner.php` y [`manual_e2e_verification.md`](manual_e2e_verification.md) | El paso de login añade la clave de desarrollo. |

### 5.5 Lo que NO cambia

* EARS 1.3 (24 h) y el formato/firma del token de sede.
* El scoping por sede de todas las rutas de `SiteAuthMiddleware` y la segregación de datos (RNF-04).
* El flujo público del QR: `POST /api/qr/report` sigue registrado sin middleware; reportar no exige credencial.
* El contrato de la etiqueta QR: ni su arte ni su URL cambian (la decisión 5 de §8 es opcional y separada).
* Nada del dominio: estados, SLA, ventana de 48 h ni reintegros.

---

## 6. Plan de migración (si se aprueba)

### 6.1 Fases

| Fase | Contenido | Hecho cuando |
| :--- | :--- | :--- |
| **0 · Decisiones** | El PO resuelve §8 | Decisiones 1–4 registradas en este documento |
| **1 · Enmienda** | Aplicar §5 a la especificación y a los contratos | `mvp_functional_spec.md` y contratos coherentes; sin código aún |
| **2 · Contratos de prueba** | Nueva suite de cierre (login con/sin clave, rotación, cabecera retirada, fallo en cerrado, auditoría sin clave en claro) y actualización de las suites de §6.2 | Suites en rojo sobre el código actual por los motivos correctos |
| **3 · Backend** | Migración `014_location_access_code.sql` (+ espejo en `database/cloud_init.sql`), huella y verificación en `AuthService`, emisión/reemisión en administración, `SiteAuthMiddleware` solo-token, retirada de los respaldos `getHeader('X-Site-Code') ??` de los controladores | Suite de la fase 2 en verde; `CloudDeploySchemaParityTest` sigue verde |
| **4 · Frontend y administración** | Segundo campo en el portal, modal de emisión/«mostrar una vez» + listado «pendiente de entrega» en administración, retirada de la inyección de cabecera en `api.js` | Suites ESM verdes; verificación en navegador del alta y del login |
| **5 · Despliegue y entrega** | Emitir y entregar las claves de las sedes existentes conforme a la decisión 3; listado de pendientes a cero | Batería global en verde y checklist de entrega completada |
| **6 · Cierre documental** | Actualizar triaje (S-4 → CERRADO), sexta tanda (H-1 → nota de cierre), handoff y avisos de seguridad | Documentación sin referencias a S-4 como hallazgo abierto |

### 6.2 Suites que cambian

| Suite | Por qué |
| :--- | :--- |
| Nueva `SiteAccessCodeTest` (nombre propuesto) | Contrato completo de la clave: emisión, acceso, rotación, genérico, fail-closed, auditoría sin secreto |
| `AuthControllerTest` | `site-login` exige el segundo parámetro |
| `AuthMiddlewareTest` | Se retiran los casos de `X-Site-Code` (1.3/1.4) y se añade el rechazo de la cabecera retirada |
| `SiteManagerRefundDataSegregationTest` 4.6 | Deja de fijar la cabecera como contrato |
| `IncidentCommentRoutesVerificationTest` 2.9 | El mensaje del `401` deja de citar `X-Site-Code` |
| `FrontendApiStoreTest.php` 2.3 / `.mjs` 1.5 | `api.js` deja de inyectar la cabecera |
| `LocationPortalControllerTest`, `LocationRefundDeliveryTest` 7.4, `LocationPortalReopenAuditTest`, `SiteManagerPartsDataSegregationTest`, `SiteSanitaryControllerTest` 4.2, `SiteSanitaryApiTest` bloque 7 | Usaban la cabecera como vía de autenticación |
| `tests/Manual/E2EVerificationRunner.php` | El login de sede incorpora la clave de desarrollo |

### 6.3 Datos, semillas y entornos

* **Migración aditiva**: `access_code_hash VARCHAR(255) NULL` y `access_code_issued_at DATETIME NULL` en `locations`. Sin borrado ni reescritura de datos (Art. III).
* **Semillas de desarrollo**: las diez sedes semilla reciben claves de desarrollo conocidas y documentadas en el README, de modo que la batería y el runner E2E siguen siendo reproducibles. En la nube, `database/cloud_init.sql` refleja la misma estructura (lo audita `CloudDeploySchemaParityTest`).
* **Sedes existentes en el despliegue**: no hay backfill en claro posible —solo se guarda huella—, así que la transición es **fallo en cerrado + reemisión guiada**: el panel marca las sedes sin clave y coordinación emite y entrega una por una. Es una tarea operativa acotada (diez sedes en el parque semilla), no un cambio de datos.
* **Sin bandera de compatibilidad**: no se propone un interruptor que desactive la exigencia de clave; sería la clase de configuración que se queda apagada para siempre.

### 6.4 Secuencia y esfuerzo

A (~1 jornada de código y pruebas) puede ir en una sola tanda; S-3 (contador de intentos por sede, siguiendo el precedente del PIN) puede ir en la misma tanda o en la inmediatamente siguiente, pero **no debe quedar indefinidamente detrás**: sin él, la clave es una barrera sin freno.

---

## 7. Fallback si la clave se pierde o se filtra

1. **Reemisión central.** Cualquier coordinador puede emitir una clave nueva desde el panel de la sede (`POST /api/coordinator/locations/{id}/access-code`): la anterior queda invalidada al instante, la nueva se muestra una sola vez y la operación queda auditada con su actor.
2. **La sede nunca se queda sin reportar.** Aunque el portal espere a la carta nueva, `POST /api/qr/report` es público: escanear el QR de la máquina sigue registrando la avería. El portal es supervisión, comentarios, reapertura y reintegros; el canal de aviso no depende de la clave.
3. **Cambio de responsable.** Misma reemisión; recomendación operativa de rotar en cada relevo de conserjería.
4. **Filtración (foto de la carta).** Rotar. No se propone caducidad automática —rompería el servicio cada X meses sin que nadie lo pida—; la rotación es la respuesta y es inmediata.
5. **Entrega.** Por mano; nunca por correo o chat. Al guardarse solo la huella, **no existe recuperación de la clave existente**: la única operación posible es emitir otra, y eso es deliberado.
6. **Lo que no se propone**: autoservicio del responsable, recuperación por email/SMS, segundo factor ni caducidad programada. Fuera del MVP (AGENTS.md §5.1) y sin requisito que lo exija.

---

## 8. Decisiones que corresponden al Product Owner

| # | Decisión | Opciones | Recomendación |
| :--- | :--- | :--- | :--- |
| 1 | **Vía de cierre de S-4** | A · B2 · mantener aplazado | **A** (clave de centro), con B2 declarada como mejora futura |
| 2 | **Freno de intentos (S-3)** | Misma tanda · tanda inmediata · aplazado | Completar A + S-3 en el mismo carril |
| 3 | **Transición de las sedes existentes** | Fallo en cerrado + reemisión guiada · periodo de gracia con aviso | **Fallo en cerrado** + reemisión guiada (sin bandera de compatibilidad) |
| 4 | **Formato y entrega** | 10 caracteres `XXXXX-XXXXX`, carta física · otra longitud/alfabeto · otro canal | **10 caracteres** y entrega en mano |
| 5 | **Opcional**: retirar `site=` de la URL del QR | Sí (cambia `QrCodeData` y la vista QR) · No | **No ahora**: con A, el código deja de ser credencial y la exposición pierde valor |

Aprobada la decisión 1, este documento se convierte en la enmienda de RF-01 (§5) y habilita las fases de §6. Sin ella, no se toca código (AGENTS.md §2, Fase 2).

---

## 9. Lo que este documento no hace

* No implementa nada, no modifica especificaciones aprobadas ni pruebas, y no adelanta la puerta de aprobación.
* No decide S-3, ni H-4, ni ningún otro pendiente del triaje.
* No cambia el flujo público del QR, la sesión de 24 h, el scoping por sede ni el contrato de la etiqueta.
* No toca el material ajeno no versionado (`specs/11-*`, `pending_info_sla_pause_spec.md`).

---

## 10. Trazabilidad

| Artefacto | Estado actual | Tras la aprobación |
| :--- | :--- | :--- |
| [`auditoria_arquitectura_triage.md`](../specs/technical/auditoria_arquitectura_triage.md) §1/§3 | S-4 «DIFERIDO (decisión de PO)» | S-4 → **CERRADO** con puntero a este documento y a la enmienda |
| [`analisis_sexta_tanda.md`](../specs/08-refunds/analisis_sexta_tanda.md) §2/§4 | H-1 «Aparcado y documentado» | Nota de cierre con la decisión y su fecha |
| Avisos de seguridad en `api_contracts.md` y `refunds_contracts.md` | Describen el hallazgo abierto | Declaran el cierre y la credencial vigente |
| [`handoff_rama_incident-comments.md`](handoff_rama_incident-comments.md) §6 | «S-3 y S-4 siguen abiertos» | S-4 cerrado; S-3 según la decisión 2 |
| Este documento | Fase 1, pendiente de aprobación | Conserva su redacción y declara la decisión aprobada en §11, como en la [`propuesta_enmienda_copy_consolidacion_territorial.md`](propuesta_enmienda_copy_consolidacion_territorial.md) |

---

## 11. Resolución aprobada (2026-10-08)

El Product Owner resolvió las decisiones de §8 en el sentido recomendado:

1. **Vía de cierre:** **opción A** — clave de centro emitida en el alta, entregada en mano, con B2 (emparejamiento con dispositivo recordado) declarada como mejora futura de experiencia.
2. **Freno de intentos:** contador y bloqueo **por sede en la misma tanda** (5 fallos consecutivos → 15 minutos), siguiendo el precedente del PIN de reintegros.
3. **Transición:** **fallo en cerrado** para las sedes sin clave, con reemisión guiada desde el panel de coordinación; sin bandera de compatibilidad.
4. **Custodia:** **solo huella bcrypt**, mostrada una vez; la pérdida se resuelve reemitiendo.
5. **QR:** el parámetro `site=` **no se toca** en esta tanda.

La enmienda de §5 pasó a `specs/functional/mvp_functional_spec.md` (HU-01, actor, RF-01 y EARS 1.1/1.2/1.4/1.5, Resolución QA 7) y a los contratos técnicos, y el plan de §6 se ejecutó con las fases 1–6. Los avisos de seguridad de `api_contracts.md` y `refunds_contracts.md` dejan de describir un hallazgo abierto.
