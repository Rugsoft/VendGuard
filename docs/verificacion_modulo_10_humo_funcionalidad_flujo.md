# Verificación del Módulo 10 · Humo, funcionalidad y flujo del hilo de comentarios

**Módulo:** 10 · Hilo de comentarios bidireccional (T-COM-01 … T-COM-20)
**Tipo de prueba:** caja negra sobre la API real (humo + funcionalidad + flujo de trabajo multi-actor)
**Fecha:** 07/10/2026 · **Rama:** `incident-comments` · **Servidor:** `router.php` en `http://127.0.0.1:8000`
**Alcance:** 58 comprobaciones contra el servicio en marcha (no contra dobles de prueba)

---

## 1. Objetivo y método

Se ha ejecutado una batería de caja negra que ataca el módulo de comentarios **a través de HTTP**, con los mismos
actores y credenciales del sistema real (dos sedes, dos técnicos, un coordinador), y con acceso directo a MariaDB
únicamente para **preparar escenarios y comprobar persistencia** (contadores, `audit_log`, `incident_history`),
nunca para sustituir una respuesta de la API.

La batería no vive en el repositorio: es un script temporal de verificación que se elimina al cerrar el trabajo.
La evidencia permanente son las suites del repositorio y este informe.

### Fases

| Fase | Contenido | Comprobaciones |
|---|---|---|
| **FASE 0 · Humo** | Servicio vivo, aplicación servida, componente del hilo servido, autenticación exigida, estado canónico de la BD, catálogo de máquinas, lectura de un expediente sellado | 8 |
| **FASE 1 · Funcionalidad** | RF-01 (recuentos segregados y orden), RF-02 (máscara del técnico), RF-03 (validación de texto y confidencialidad), RF-04 (evidencias), RF-05 (sellado y garantía), RF-06 (auditoría) y RF-01.3 (paginación por cursor) | 35 |
| **FASE 2 · Flujo de trabajo** | Ciclo de vida completo: reporte → asignación → inicio → conversación → resolución → garantía → sellado → reapertura → reasignación → auditoría | 15 |

### Resultado global

```
Comprobaciones: 58 · correctas: 57 · fallos: 1
BD restaurada: incidents=13 comments=0 · residuos en uploads: ninguno
```

Las 25 suites del módulo (16 PHP + 9 ESM) se reejecutaron además con el directorio `public/uploads/` **vacío**,
todas en verde (exit 0), confirmando que las suites no dependen de residuos previos.

---

## 2. Lista de fallos encontrados, ordenados por importancia

### F-1 · Severidad MEDIA · La reapertura deja al técnico sin acceso al hilo, en lectura **y** escritura

> **CERRADO (07/10/2026).** Se aprobó conceder **lectura histórica** al técnico con antecedentes de intervención (RF-05.4 nuevo en `specs/10-incident-comments/spec.md` + caso límite 7), con la publicación cerrada hasta la reasignación. Evidencia de cierre, contrato del DTO y limitaciones en [verificacion_cierre_hallazgos_f1_f2_f3.md](verificacion_cierre_hallazgos_f1_f2_f3.md).

**Qué ocurre.** `PdoIncidentRepository::reopen()` (`src/Infrastructure/Repository/PdoIncidentRepository.php:1300-1310`)
ejecuta `assigned_technician_id = NULL, assigned_at = NULL, resolved_at = NULL`. A partir de ese instante, el técnico
que había atendido la avería:

| Actor | Lectura del hilo (`GET`) | Publicación (`POST`) |
|---|---|---|
| Sede | 200 | **201** |
| Coordinación | 200 | **201** |
| Técnico (desasignado) | **403** | **403 `NOT_ASSIGNED_TO_TECHNICIAN`** |
| Técnico (tras reasignar el coordinador) | 200 | 201 |

**Evidencia medida.** Traza del flujo real del escenario `W7` (expediente reportado, asignado a *Operador #02*,
resuelto, reabierto por la sede, reasignado):

```
INFO  W7.3b · lectura del hilo por el técnico desasignado tras la reapertura = 403
PASS  W7.3  assigned_technician_id tras reabrir = NULL
PASS  W7.4  tecnico_sin_asignar=403 NOT_ASSIGNED_TO_TECHNICIAN · post tras reasignar=201 · total=7 · historico_intacto=true
```

**Por qué es un fallo y no una regla mal implementada.** Desasignar en la reapertura es la regla de negocio correcta
(la reapertura devuelve el expediente a triaje de coordinación) y así está documentado. El defecto está en el
**efecto colateral sobre el hilo**: el técnico deja de poder *leer* una conversación en la que participó y que sigue
abierta, y **nadie se lo indica**. La máscara de acceso del canal técnico (`NOT_ASSIGNED_TO_TECHNICIAN`) está pensada
para impedir que un técnico ajeno se meta en un expediente que no es suyo, pero también expulsa al técnico legítimo
en cuanto el expediente se reabre.

**Impacto operativo.** En el escenario más probable de este módulo — avería reparada, el cliente reabre a las pocas
horas, el técnico quiere anotar «vuelvo a revisar el muelle mañana» — el técnico recibe un 403 sin explicación y no
puede consultar lo que ya se habló. La sede y coordinación sí pueden, así que la conversación continúa **sin la voz
del técnico** hasta que un coordinador lo reasigne manualmente.

**Recomendación.** Decidir y documentar explícitamente una de estas dos vías antes de tocar código:

1. **Conservar la visibilidad histórica:** permitir al técnico que figuraba como último responsable leer el hilo de
   un expediente `REOPENED` (sólo lectura) hasta que se reasigne; o
2. **Hacer visible la fricción:** si el 403 se mantiene, la interfaz del técnico debe retirar el acceso al hilo con
   un mensaje claro («expediente reabierto, pendiente de reasignación») en lugar de dejar una acción que devuelve 403.

La opción 1 es la que mejor encaja con el artículo de inmutabilidad y trazabilidad del hilo (la conversación es
histórico, no propiedad del asignado actual), pero **es un cambio de regla de acceso y requiere aprobación de la
especificación** (Art. V.5 de la Constitución).

---

### F-2 · Severidad MEDIA-BAJA · La reapertura no deja rastro en `audit_log`

> **CERRADO (07/10/2026).** Era un incumplimiento de EARS 5.1.2, no una decisión de diseño: el controlador de sede emite ahora el evento inmutable `REOPEN_TICKET` (`user_role = SITE_MANAGER`), que además da vida al filtro «Reapertura de Ticket» del visor de auditoría. Evidencia en [verificacion_cierre_hallazgos_f1_f2_f3.md](verificacion_cierre_hallazgos_f1_f2_f3.md).

**Qué ocurre.** La reapertura escribe su transición en `incident_history`
(`from_status='RESOLVED' → to_status='REOPENED'`, `PdoIncidentRepository.php:1319-1325`) pero **no inserta ninguna
fila en `audit_log`**. `AuditLogger` documenta `'REOPEN_TICKET'` como ejemplo de acción válida
(`src/Application/Service/AuditLogger.php:38`), y ese evento no llega a producirse en ningún punto del camino de
reapertura.

**Evidencia medida.** Agregado de `audit_log` para el expediente reabierto del escenario de flujo:

```
INFO  W8 · audit_log(TICKET 6622) = [INCIDENT_ASSIGNED=2, INCIDENT_COMMENT_ADDED=7]
          · evento de reapertura en audit_log = NO
          · incident_history(→REOPENED) = [{"from_status":"RESOLVED","to_status":"REOPENED"}]
```

Los 7 comentarios sí generan sus 7 eventos `INCIDENT_COMMENT_ADDED` (RF-06.3 correcto). La reapertura, que es un
cambio de estado con efecto sobre quién puede escribir en el hilo (F-1), no aparece en el registro de auditoría.

**Por qué importa.** El módulo de comentarios delega la trazabilidad *de la conversación* en `audit_log`; si la
transición de estado que **reabre** esa conversación sólo queda en `incident_history`, dos registros de auditoría
del mismo expediente cuentan historias distintas. Un auditor que consulte `audit_log` ve comentarios publicados
sobre un expediente que nunca consta como reabierto.

**Recomendación.** Emitir el evento `REOPEN_TICKET` desde el servicio/caso de uso de reapertura (la reapertura ya
corre en transacción propia, así que la auditoría entra en el mismo commit) y añadir la aserción correspondiente a
la suite de flujo. Si por el contrario el contrato aprobado decide que la reapertura se audita **sólo** en
`incident_history`, conviene dejarlo escrito en `specs/` para que no parezca una omisión.

---

### F-3 · Severidad BAJA · El contrato de reapertura devuelve una etiqueta donde el resto de la API devuelve el estado canónico

> **CERRADO (07/10/2026).** Contrato normalizado con consentimiento (Art. V.5): `status` devuelve el enum canónico `REOPENED`, la etiqueta viaja en `status_label` y `status_canonical` se conserva como alias deprecado una versión. Enmienda en `specs/technical/api_contracts.md` §3.4 y 5 suites actualizadas. Evidencia en [verificacion_cierre_hallazgos_f1_f2_f3.md](verificacion_cierre_hallazgos_f1_f2_f3.md).

**Qué ocurre.** `LocationPortalController::reopenIncident()` responde con `data.status = "REABIERTA"` (etiqueta en
castellano) mientras que **todos** los demás endpoints devuelven `status` como enumerado canónico y reservan la
etiqueta legible para `status_label` (`REGISTERED`/«Registrada», `ASSIGNED`/«Asignada», …). La respuesta incluye
además `status_canonical: "REOPENED"` y `status_label` no existe.

**Evidencia medida.**

```
FAIL  W7.2b · data.status='REABIERTA'
       (el resto de endpoints devuelven el enum canónico en 'status' y la etiqueta en 'status_label')
```

**Por qué importa.** Cualquier cliente que lea `data.status` de forma uniforme (el patrón que siguen el portal de
sede, la ruta del técnico y el triaje) recibe aquí una cadena que **no pertenece al dominio de estados** y no puede
compararla contra los valores que conoce. El módulo tiene además una segunda fuente de verdad para el mismo texto
(`IncidentStateMachine` mapea la etiqueta `'REABIERTA'` al estado `REOPENED`, `src/Core/Service/IncidentStateMachine.php:42`),
de modo que el valor no es inventado, pero sí queda fuera de contrato.

**Mitigación actual.** `status_canonical` permite a un cliente ya adaptado no romperse, y de hecho es lo que usa el
frontend. Es un fallo de contrato, no de comportamiento.

**Recomendación.** Unificar el payload de reapertura con el resto de la API (`status` canónico + `status_label`
opcional) manteniendo `status_canonical` durante una versión para no romper clientes que ya lo consumen.

---

## 3. Comprobaciones que **no** son fallos (errores de la propia sonda)

Durante la puesta a punto de la batería aparecieron cinco discrepancias que resultaron ser **defectos de la sonda,
no del producto**. Se documentan para que nadie las persiga otra vez:

| # | Lo que la sonda afirmaba | Comportamiento real | Veredicto |
|---|---|---|---|
| D-1 | El aviso recién creado devuelve `status = REPORTED` | Devuelve **`REGISTERED`** («Registrada») | Sonda corregida. El estado inicial documentado del proyecto es `REGISTERED` |
| D-2 | La sede 2 debería reabrir un expediente cerrado de la sede 1 | **403 `SITE_MISMATCH`** | Correcto y exigido por segregación de sedes |
| D-3 | Un técnico debería poder resolver sin iniciar intervención | **422 `INVALID_STATUS_FOR_RESOLUTION`** | Correcto: la resolución exige `IN_PROGRESS` |
| D-4 | `incident_history` usa `transition_from` / `transition_to` | El esquema real es **`from_status` / `to_status`** | Sonda corregida contra `database/schema.sql` |
| D-5 | La reapertura devuelve `status = 'REOPENED'` | Devuelve `status='REABIERTA'` + `status_canonical='REOPENED'` | Reclasificado como **F-3**, el único fallo real de los cinco |

---

## 4. Cobertura confirmada en verde (resumen de las 57 comprobaciones correctas)

**Humo:** salud del servicio, aplicación servida, componente con `capture="environment"`, 401 sin token y con token
falsificado, BD canónica (13 expedientes / 0 comentarios), cinco inicios de sesión, expediente cerrado legible con
`is_sealed=true`, catálogo de máquinas de dos sedes.

**Funcionalidad:** recuentos segregados sede 2 / técnico 4 / coordinación 4 y coherencia con `comments_count` de la
parada del técnico, del triaje y `public_comments_count` de la tarjeta de sede; la sede **no recibe `is_internal`**
ni los textos de las notas internas; el técnico va enmascarado ante la sede («Servicio Técnico Oficial (Operador
#02)») y con nombre real en los canales internos; orden cronológico; límites de texto (4 → 422, 1.001 → 422, en
blanco → 400/422, 5 → 201, 1.000 → 201); la sede no puede forzar `is_internal` ni por JSON **ni por multipart**, y
la nota interna del técnico por multipart sigue siendo invisible para la sede y visible para coordinación; evidencia
válida almacenada con nombre hash; `%PDF` declarado como JPEG → 422 **sin residuos**; más de 5 MB → 422 **sin
residuos**; el mismo binario dos veces produce dos hashes coexistentes; la evidencia se sirve como `image/*`;
paginación 50 + 10 sin duplicados ni huecos con `has_more_before` correcto; cursor inválido → 400; expediente
`CLOSED` rechaza publicar en los tres canales con 403 `CONVERSATION_SEALED` mientras la lectura sigue en 200;
ventana de garantía de 48 h respetada en ambos sentidos; sede y técnico ajenos bloqueados en lectura y escritura;
60 eventos `INCIDENT_COMMENT_ADDED` para 60 mensajes.

**Flujo de trabajo:** estado inicial `REGISTERED` sin mensajes; el aviso sin asignar no aparece en ninguna ruta; la
asignación mete la parada en la ruta con contador 0; `start` → `IN_PROGRESS`; una nota interna no altera el contador
público; el mensaje público con evidencia llega a la sede con foto y técnico enmascarado; recuento segregado
coherente tras las respuestas cruzadas; los mensajes previos permanecen **íntegros** (id, texto y `created_at`) tras
la resolución y tras la reapertura; sellado correcto fuera de garantía; reapertura fuera de plazo → 4xx
`REOPEN_WINDOW_EXPIRED`; reapertura dentro de plazo → 200 con `REOPENED`, sede 201, sello levantado; reasignación →
el técnico vuelve a publicar.

---

## 5. Limitaciones declaradas de esta verificación

1. **Sin capturas de pantalla nuevas.** El *webview* de previsualización dejó de componer fotogramas
   (`document.visibilityState === 'hidden'`) durante esta sesión, por lo que no hay evidencia gráfica de esta
   tanda; las pruebas de interfaz se apoyan en sondas sobre el DOM y en las suites ESM del componente. Las
   limitaciones gráficas ya están declaradas en [verificacion_h2_rnf03_usabilidad_modal_hilo.md](verificacion_h2_rnf03_usabilidad_modal_hilo.md)
   y [verificacion_h4_rf04_1_captura_camara.md](verificacion_h4_rf04_1_captura_camara.md).
2. **Sin pruebas de carga concurrente.** La batería es secuencial. El comportamiento de dos actores publicando en el
   mismo hilo en el mismo instante (orden garantizado por `created_at` con resolución de segundo) no se ha medido
   aquí; la suite `IncidentCommentsPerformanceAndSecurityTest.php` cubre la latencia en aislamiento.
3. **Datos de demostración.** Los escenarios se construyen sobre las semillas del proyecto; no se ha probado contra
   un parque de máquinas realista en volumen.
4. **`public/uploads/` se ha saneado.** El directorio acumulaba 8 `.jpg` sin ninguna referencia en la BD
   (residuos de ejecuciones repetidas de suites, ficheros ignorados por Git). Se han eliminado; las suites del
   módulo se han reejecutado con el directorio vacío y siguen en verde.

---

## 6. Trazabilidad

| Artefacto | Resultado |
|---|---|
| Batería de caja negra (58 comprobaciones, temporal) | 57 correctas · 1 fallo declarado (F-3) · 2 hallazgos informativos (F-1, F-2) |
| Suites del módulo (25: 16 PHP + 9 ESM) con `public/uploads/` vacío | **25/25 exit 0** |
| Suites del módulo con la BD canónica restaurada | **25/25 exit 0** |
| `php tests/run_all.php` (tras los cambios de código H-2/H-4) | **207/207 suites · 7.874 aserciones · 0 fallos** |
| Estado final de la BD | `incidents=13 · incident_comments=0` |
| Estado final de `public/uploads/` | sin residuos |

**Nota de actualización (07/10/2026):** los tres hallazgos de este informe están cerrados; véase [verificacion_cierre_hallazgos_f1_f2_f3.md](verificacion_cierre_hallazgos_f1_f2_f3.md). Se mantiene el resto del análisis y la lista de falsos positivos de la sonda tal y como se documentó.

**Conclusión.** El módulo 10 no presenta defectos de implementación en su superficie funcional: segregación de la
información, máscara del técnico, validación de texto, política de evidencias, sellado, paginación y auditoría de
comentarios se comportan según la especificación. Los tres hallazgos están **en la frontera del módulo con la
reapertura** (hito W7): uno de acceso (F-1), uno de trazabilidad (F-2) y uno de contrato de API (F-3). Ninguno
degrada los datos ni la seguridad de la conversación, y los tres requieren decisión de especificación antes de
tocar código.
