# Re-ejecución del Módulo 10 · Humo, funcionalidad y flujo (08/10/2026)

**Módulo:** 10 · Hilo de comentarios bidireccional (T-COM-01 … T-COM-20)
**Rama:** `incident-comments` · **HEAD:** `06adb4d` · **Árbol de trabajo:** limpio
**Servidor:** `php -S 127.0.0.1:8000 -t public router.php` (temporal, detenido al cerrar)
**Antecedente:** [verificacion_modulo_10_humo_funcionalidad_flujo.md](verificacion_modulo_10_humo_funcionalidad_flujo.md) (07/10/2026)
**Resultado global:** suites del módulo **25/25 en verde** · batería de caja negra **68/68** · suite global **209/209** · **1 fallo real** (en la evidencia de flujo, no en el producto)

---

## 1. Método de esta re-ejecución

Se han vuelto a ejecutar las tres fases pedidas, conservando el estado real de cada comando
(`exit code`) y sin omitir ni debilitar ninguna aserción:

| Fase | Qué se ejecutó | Comando |
|---|---|---|
| **Humo** | Servicio vivo, aplicación servida, componente del hilo servido, autenticación exigida, BD canónica, inicios de sesión de los cinco actores | Sonda de caja negra (H1–H12) |
| **Funcionalidad** | Suites del repositorio del módulo 10: segregación, máscara, longitudes, evidencias, paginación, sellado, auditoría, RBAC y contadores | `php tests/unit/*Comment*.php` · `node tests/unit/*Comment*.mjs` · `php tests/integration/*Comment*.php` |
| **Funcionalidad (servicio real)** | 43 comprobaciones de caja negra contra la API en marcha (RF-01 … RF-06) y preparación/verificación en MariaDB | Sonda de caja negra (F1–F43) |
| **Flujo de trabajo** | Ciclo de vida multi-actor por HTTP: reporte → triaje → inicio → conversación → resolución → garantía → reapertura → reasignación | `php tests/Manual/E2EVerificationRunner.php` + sonda (W1–W10) |
| **Humo global / regresión** | Toda la batería del proyecto con reinicio de semillas | `php tests/run_all.php` |

---

## 2. Resultado por fase

### 2.1 Suites del módulo 10 · 25/25 exit 0 (878 aserciones)

```
PHP Unit (9)      CoordinatorIncidentCommentControllerTest 50 · CoordinatorTriageCommentsCounterTest 13
                  IncidentCommentRoutesVerificationTest 31 · IncidentCommentServiceTest 42
                  IncidentCommentThreadDtoTest 42 · LocationCommentEndpointTest 37
                  LocationMachinesCommentsCounterTest 13 · TechnicianCommentEndpointTest 59
                  TechnicianRouteCommentsCounterTest 13                                        = 300
JavaScript ESM (9) CoordinatorTriageCommentsBadgeTest 41 · FrontendApiCommentsClientTest 20
                  IncidentCommentThreadModalFormTest 49 · IncidentCommentThreadModalScaffoldTest 49
                  IncidentCommentThreadModalSealedGuardTest 32 · IncidentCommentThreadModalTest 59
                  IncidentCommentThreadModalViewerTest 41 · MachineCardCommentsBadgeTest 35
                  TechnicianStopCommentsBadgeTest 31                                          = 357
Integración (7)   AddIncidentCommentEndpointTest 34 · CoordinatorCommentsApiTest 43
                  IncidentCommentsConstitutionalTest 30 · IncidentCommentsPagedRepositoryTest 17
                  IncidentCommentsPerformanceAndSecurityTest 28 · LocationCommentsApiTest 30
                  TechnicianCommentsApiTest 39                                                = 221
```

### 2.2 Sonda de caja negra sobre el servicio en marcha · 68/68 exit 0

**Humo (12):** servicio vivo con 401 sin token, aplicación HTML servida, componente del hilo servido como
JavaScript, token falsificado rechazado, lectura del hilo sin token rechazada, cinco inicios de sesión
(sede 1, sede 2, coordinación y dos técnicos) y catálogos de las dos sedes.

**Funcionalidad (43):** alta de expedientes en `REGISTERED`; asignación e inicio de intervención;
nota interna persistida con `is_internal = 1` y visible con candado en el canal interno; **cero fugas hacia
la sede** (ni texto de la nota interna, ni el campo `is_internal`, ni el nombre real del técnico, que llega
enmascarado como `Servicio Técnico Oficial (Operador #NN)`); límites de texto 4 → 422, 1.001 → 422, blanco →
400/422, 5 → 201, 1.000 → 201; la sede **no puede** forzar confidencialidad por JSON ni por multipart;
evidencia válida almacenada, servida como `image/*` y con nombre saneado; `%PDF` disfrazado de JPEG → 422
**sin residuos**; fichero > 5 MB → 422 **sin residuos**; paginación 50 + 10 sin duplicados ni huecos, orden
cronológico y cursor inválido → 400; sede ajena → 403 `SITE_MISMATCH`; técnico ajeno → 403 en lectura y
escritura; un evento `INCIDENT_COMMENT_ADDED` por mensaje; expediente `CLOSED` legible con `is_sealed = true`
y **los tres canales** rechazando publicar con 403 `CONVERSATION_SEALED`; coherencia de los tres contadores
(parada de ruta, triaje y tarjeta pública de sede, este último solo con públicos).

**Flujo de trabajo (10):** aviso sin asignar ausente de toda ruta; asignación con parada y contador 0; la nota
interna no altera el contador público; el mensaje público llega a la sede con foto y técnico enmascarado;
resolución documentada → `RESOLVED` con reloj de garantía; **historial de mensajes íntegro** (ids, textos y
marcas de tiempo) tras la reapertura; evento `REOPEN_TICKET` en `audit_log`; reasignación y publicación
posterior del técnico.

### 2.3 Suite global · 209/209 suites · 7.977 aserciones · 0 fallos · exit 0

```
Suites PHP Unit: 93/93 · JS Unit: 53/53 · Integración: 63/63 · Fallos: 0
Base de datos restablecida: SÍ (semillas intactas)
```

---

## 3. Fallos encontrados, ordenados por importancia

### F-1 · Severidad MEDIA · El contrato publicado del comentario de sede quedó desfasado tras T-COM-05

> **CERRADO (08/10/2026).** §3.3 de `specs/technical/api_contracts.md` enmendada con el DTO del hilo y la decisión de
> contrato registrada, con consentimiento explícito del responsable del producto (Art. V.5). Evidencia de la enmienda y
> deriva hermana pendiente en el **apartado 7** de este informe.

**Qué ocurre.** `POST /api/incidents/{ticket_code}/comments` (y su alias
`/api/location/incidents/{id}/comments`) devuelve hoy el DTO del hilo —`data.incident`, `data.pagination`,
`data.comments`—, tal y como exige T-COM-05 (`GET`/`POST` con `IncidentCommentThreadDto`). Sin embargo
`specs/technical/api_contracts.md` §3.3 sigue documentando el payload heredado (`data.id`, `data.incident_id`,
`data.author_name`, `data.comment_text`, `data.created_at`), que el endpoint dejó de emitir en el commit
`ca63417` (T-COM-05). Es decir: la especificación técnica describe un cuerpo que el servicio ya no produce.

**Evidencia medida** (respuesta real del servicio en marcha, `201 Created`):

```json
{"success":true,"data":{"incident":{"id":7600,"ticket_code":"INC-2026-5ADC", "...":"..."},
 "pagination":{"total_comments":2,"loaded_count":2,"has_more_before":false,"oldest_id":195214,"latest_id":195215},
 "comments":[{"id":195214,"author_type":"REPORTER","author_name":"Responsable de Sede · …","is_own_message":true}]}}
```

`git log -S "Añadir Comentario/Evidencia" -- specs/technical/api_contracts.md` sólo devuelve `a0cd7ca`
(spec inicial): §3.3 **nunca se ha enmendado** desde entonces.

**Por qué importa.** Cualquier cliente (o integrador) que implemente el portal de sede leyendo §3.3 esperará
un objeto plano de comentario y encontrará un hilo paginado. Además, un cambio de contrato sin enmienda de la
especificación es exactamente el supuesto que la línea roja nº 5 de `AGENTS.md` obliga a consensuar antes de
tocar código, y la práctica del proyecto ya existe: §3.4 se enmendó para normalizar la reapertura (F-3 del
informe anterior).

**Impacto en el producto verificado: nulo.** El único consumidor real (`IncidentReportModal.js`) ignora el
payload y el `onCommentAdded()` del portal de sede recarga el parque; las 9 suites del modal y de la capa
`api.js` ya asertan la forma nueva. Es un defecto de **documentación/contrato**, no de comportamiento.

**Recomendación.** Enmendar §3.3 con la forma del `IncidentCommentThreadDto` (o, si se quiere compatibilidad,
documentar el alias y su campo `ticket_code`), dejando constancia de la decisión en `specs/` antes de tocar
código.

---

### F-2 · Severidad BAJA-MEDIA · La suite de flujo del repositorio está en rojo y nada la vigila

> **CERRADA (08/10/2026).** Los dos pasos de la recomendación se ejecutaron —aserción alineada con el contrato
> real y suite incorporada a la batería global—; véase el apartado 7.5.

**Qué ocurría.** `php tests/Manual/E2EVerificationRunner.php` terminaba con **exit 1: 43/44 aserciones**, y la
fallida es la 1.13, que comprueba `data.ticket_code` en la respuesta del comentario de sede — un campo que
existía en el payload heredado (`IncidentComment::toArray()` lo añadía) y que el DTO del hilo ya no expone en
la raíz. La aserción quedó desfasada en el mismo commit que F-1 y no se ha vuelto a revisar.

**Evidencia medida** (dos ejecuciones consecutivas, mismo resultado):

```
[FAIL] 1.13 Comentario anexado correctamente al ticket
 Total Aserciones E2E Evaluadas : 44
 Fallos Detectados               : 1
```

**Por qué importa.** Es la única evidencia automatizada del **flujo de trabajo completo de tres perfiles**, y
llevaba en rojo desde T-COM-05 sin que nadie lo notara porque `tests/run_all.php` **no descubría** las suites de
`tests/Manual/`: sólo las recorre el guardián de disciplina de limpieza. Un runner rojo que nadie ejecuta es
peor que no tenerlo: al re-ejecutar el flujo (como en esta verificación) aparece como ruido y erosiona la
confianza en la evidencia.

**Recomendación (ejecutada el 08/10/2026, apartado 7.5).** Dos pasos independientes: (1) alinear la aserción 1.13 con el contrato aprobado
(`data.incident.ticket_code`), y (2) incorporar `tests/Manual/*.php` a la batería global (o un script de
verificación de flujo propio) para que una regresión de flujo no vuelva a pasar desapercibida.

---

### Observación (no es fallo) · Orden de guardas en el canal del técnico

Un técnico **no asignado** que intenta publicar en un expediente sellado recibe
403 `NOT_ASSIGNED_TO_TECHNICIAN`, no 403 `CONVERSATION_SEALED`; el sello sólo se manifiesta al técnico
asignado. Es una decisión defendible (no revelar el estado del expediente a quien no tiene acceso) y así se
comporta el producto, pero conviene dejarlo escrito en `specs/` porque la formulación «los tres canales
rechazan con `CONVERSATION_SEALED`» sólo es cierta para el técnico responsable.

---

## 4. Discrepancias de la sonda (falsos positivos, no defectos)

La primera pasada de la batería de caja negra arrojó 12 marcas rojas que resultaron ser **hipótesis erróneas
de la sonda**, ya corregidas en la segunda pasada (68/68):

| # | Lo que la sonda afirmaba | Comportamiento real | Veredicto |
|---|---|---|---|
| S-1 | La sede ofrece ≥ 3 máquinas libres para montar los tres escenarios | `SEDE-BCN-01` tiene **2 máquinas**; la tercera hay que tomarla de otra sede | Sonda reescrita para repartir los escenarios entre dos sedes |
| S-2 | Los expedientes W3–W10 (flujo) fallan con 404 | El tercer escenario no pudo crearse (409 por avería activa en la máquina repetida) y todas las comprobaciones encadenadas apuntaban a un id 0 | Cascada de S-1: no había ningún fallo de producto |
| S-3 | El técnico debe recibir `CONVERSATION_SEALED` en un expediente que no es suyo | Recibe `NOT_ASSIGNED_TO_TECHNICIAN` (la guarda de asignación evalúa antes) | Reclasificado como observación |
| S-4 | `public/uploads/` debe cerrar en 0 ficheros | La evidencia **legítima** de una publicación correcta queda en disco aunque la purga borre su fila | Sonda corregida: se sanean sólo huérfanos; los rechazos (F28/F30) no dejan residuos |

---

## 5. Limitaciones declaradas

1. **Sin capturas de pantalla.** La batería y las suites verifican API, DOM y suites ESM; no hay evidencia
   gráfica de esta tanda (la misma limitación declarada en los informes de H-2 y H-4).
2. **Sin carga concurrente.** Todo es secuencial; el orden de dos publicaciones simultáneas en el mismo hilo
   no se ha medido (la suite `IncidentCommentsPerformanceAndSecurityTest.php` cubre latencia en aislamiento).
3. **Datos de demostración.** Los tres escenarios se montan sobre las semillas del proyecto (parque pequeño:
   2 + 1 máquinas libres entre las dos sedes usadas).
4. **La sonda no vive en el repositorio.** Como en la verificación anterior, es un script temporal de
   verificación (eliminado al cerrar); la evidencia permanente son las 25 suites, el runner E2E y este informe.

---

## 6. Estado final del entorno

```
incidents = 13 · incident_comments = 0 · public/uploads/ = 0 residuos
Servidor temporal 8000 detenido · Código sin tocar (HEAD 06adb4d) · Único cambio en el árbol: este informe (sin commitear)
```

> **Nota (08/10/2026):** esta es la fotografía del momento de la corrida. Los cambios posteriores —enmiendas de contrato,
> correcciones documentales, la guarda anti-deriva y el cierre de F-2— se documentan en el apartado 7, con sus mediciones
> y sus commits.

**Conclusión.** El módulo 10 **no presenta ningún defecto de producto** en humo, funcionalidad ni flujo: los
25 conjuntos de pruebas del módulo, las 68 comprobaciones de caja negra y las 209 suites de la batería global
pasan sin un solo fallo, con las semillas intactas. Los dos hallazgos están **en el borde del módulo con su
documentación y su evidencia**: un contrato de API sin enmendar desde T-COM-05 (F-1) y una aserción de flujo
obsoleta en un runner que la batería global no ejecutaba (F-2). Ninguno afecta a la seguridad de la conversación
ni a los datos, y **ambos quedaron cerrados el 08/10/2026** (apartados 7.1 a 7.5): la especificación se enmendó en los
tres canales, la guarda anti-deriva vigila ya el contrato y la suite de flujo corre dentro de la batería global.

---

## 7 · Enmienda de contrato ejecutada el 08/10/2026 (§3.3)

**Decisión registrada (con consentimiento del responsable del producto, Art. V.5):** los endpoints de comentarios de la
sede hablan el **DTO de hilo del módulo 10** (`data.incident` + `data.pagination` + `data.comments[]`); el payload plano
de la versión inicial queda retirado **sin alias de compatibilidad**, porque el único consumidor de frontend ignora el
cuerpo de la respuesta, la segregación de notas internas exige que el payload sea el hilo ya filtrado y ningún cliente
dependía del objeto plano. `specs/technical/api_contracts.md` §3.3 se ha reescrito con:

* rutas canónicas y alias, autenticación y enlace al contrato propietario (`specs/10-incident-comments/plan.md` §2.1.A / §2.2.A);
* **§3.3.1 consulta:** parámetros `limit` (defecto 50, tope 100) y `before_id`; forma completa de `incident`, `pagination`
  y `comments`; reglas de segregación y de enmascaramiento (`Servicio Técnico Oficial (Operador #NN)`, `Coordinación
  Central de Operaciones`, `Responsable de Sede · <ubicación>`); lectura permitida en expediente sellado;
* **§3.3.2 publicación:** campos aceptados (`comment_text` con alias, `photo` con alias), `author_name` **ignorado** e
  `is_internal` **forzado a 0**, y `201` devolviendo **el hilo completo actualizado**;
* catálogo de errores medido (400 `MISSING_COMMENT_TEXT` / `INVALID_LIMIT` / `INVALID_BEFORE_ID`, 401 `UNAUTHORIZED`,
  403 `SITE_MISMATCH` / `CONVERSATION_SEALED`, 404 `INCIDENT_NOT_FOUND`, 422 `INVALID_COMMENT_LENGTH` / `FILE_TOO_LARGE` /
  `INVALID_FILE_TYPE` con eco de `details.form_data`), y la retirada explícita de los códigos `COMMENT_TOO_SHORT` e
  `INCIDENT_NOT_ACTIVE`;
* nota de decisión de contrato, con la instrucción de migración para clientes que leían `data.id`.

**Evidencia que sustenta cada dato** (sondas temporales contra el servicio en marcha con semillas canónicas; entorno
restaurado al terminar, sin residuos):

| Comprobación | Resultado medido |
|---|---|
| `GET` hilo vacío y poblado | Cabecera de 10 campos, `pagination` con 5 cursores, item de sede con `is_own_message` y **sin** `is_internal` |
| Fuga de confidencialidad hacia la sede | Centinela interno ausente; `is_internal` ausente; nombre real del técnico ausente |
| Máscara de identidad | `Servicio Técnico Oficial (Operador #02)` |
| `limit` | Defecto 50 · `10`→10 · `100`→100 · `101` y `999`→**100** (tope) · `0` y `abc`→400 `INVALID_LIMIT` |
| `before_id` | `abc` y `0`→400 `INVALID_BEFORE_ID` |
| `POST` | 201 con el hilo completo (4 mensajes con 3 previos) y el nuevo como último elemento |
| `author_name` en el cuerpo | Ignorado: el valor falseado no aparece en la respuesta |
| `is_internal = true` desde la sede | Ignorado: persistido `0` y campo ausente en el payload |
| Expediente `CLOSED` | Lectura 200 con `is_sealed=true`, `can_comment=false`, `read_only_reason=null` · publicación 403 `CONVERSATION_SEALED` |
| Archivos | `%PDF` como `.jpg`→422 `INVALID_FILE_TYPE` · > 5 MB→422 `FILE_TOO_LARGE` · ambos sin residuos en `uploads/` |
| Identificador inexistente | 404 `INCIDENT_NOT_FOUND` en `GET` y `POST` |

### 7.1 Deriva hermana detectada en el mismo barrido

> **CERRADA (08/10/2026).** §4.5 enmendada y §5.5 creada con la misma disciplina de medición; véase el apartado 7.2.
> Único punto entonces abierto, ya corregido el mismo día: la contradicción `413`/`422` de `plan.md` §2.2.A
> (véase el apartado 7.4).

**§4.5 · Comentarios de coordinación: mismo desfase, entonces sin enmendar.** Medido en el mismo servicio:

| Documentado hoy en §4.5 | Comportamiento real medido |
|---|---|
| `data.id`, `data.incident_id`, `data.user_id`, `data.ticket_code` | `data.incident` + `data.pagination` + `data.comments[]` |
| Item con `photo_path`, sin `is_own_message` | Item con `photo_url` y `is_own_message` |
| `422 COMMENT_WINDOW_CLOSED` | `403 CONVERSATION_SEALED` |
| `422 MISSING_COMMENT_TEXT` | `400 MISSING_COMMENT_TEXT` |
| `422 COMMENT_TOO_SHORT` | `422 INVALID_COMMENT_LENGTH` |
| `422 INVALID_IS_INTERNAL` | `422 INVALID_IS_INTERNAL` ✓ (correcto) |

**Canal de técnico:** `api_contracts.md` §5 no documenta los endpoints de comentarios (solo `my-route`, `start`,
`pause` y `resolve`), aunque `plan.md` §2.2.B los define y están enrutados bajo `TechnicianAuthMiddleware`.

**Contradicción menor (corregida el 08/10/2026, apartado 7.4):** `specs/10-incident-comments/plan.md` §2.2.A documentaba
`413 Payload Too Large` para fotografías de más de 5 MB, mientras el servicio responde `422 FILE_TOO_LARGE` (coherente
con §3.2 y con la §3.3 recién enmendada).

Las tres eran correcciones de documentación del mismo tipo que F-1 y excedían la enmienda autorizada de §3.3.

### 7.2 Enmienda de los canales internos ejecutada el 08/10/2026 (§4.5 y §5.5)

**Decisión registrada (con consentimiento del responsable del producto, Art. V.5):** el contrato de comentarios de los dos
canales internos se documenta también con la forma real del DTO del hilo, incluidos los apartados que faltaban.
`specs/technical/api_contracts.md` queda así:

* **§4.5 reescrita** como `GET|POST /api/coordinator/incidents/{id}/comments`: resolución del identificador (ID, código
de ticket o `#`+código), visibilidad total con `is_internal` y nombres nominales, `total_comments` sobre la totalidad del
expediente, `limit`/`before_id`, `is_internal` **por defecto `true`** (solo un valor explícito la desmarca), eco de
`details.form_data` para el reintento y payload real del evento de auditoría.
* **§5.5 nueva** como `GET|POST /api/technician/incidents/{id}/comments`: alcance de lectura (asignado; antecedentes
acreditados en `incident_history` sobre un expediente `REOPENED` sin reasignar en modo solo lectura con
`read_only_reason = REOPENED_AWAITING_REASSIGNMENT`; técnico ajeno → 403 `NOT_ASSIGNED_TO_TECHNICIAN`), escisión entre
lectura y escritura (el técnico con antecedentes lee pero no publica hasta la reasignación) y fail-safe de privacidad
(`is_internal` por defecto `true`).

**Dos afirmaciones falsas del apartado 4.5 antiguo, además del payload plano:** documentaba `is_internal` por defecto
`false` (el servicio y `plan.md` §2.2.C aplican **`true`**: la nota interna viene preseleccionada, RF-03.3) y afirmaba que
`metadata.visibility` registra `PUBLIC`/`INTERNAL` en el evento de auditoría (el registro se escribe con `metadata = null`
y `new_state = { comment_id, ticket_code, is_internal, has_photo }`; el texto del mensaje no se copia, lo que además
evitaba duplicar contenido confidencial fuera del hilo). Ambas se corrigieron con la medición.

**Evidencia medida que sustenta §4.5 y §5.5** (sondas temporales contra el servicio en marcha, semillas canónicas,
entorno restaurado):

| Comprobación | Resultado medido |
|---|---|
| Coordinación: identificador | ID, código de ticket y `#`+código → 200 · `id=0` y `@@%` → 400 `INVALID_INCIDENT_IDENTIFIER` · inexistente → 404 |
| Coordinación: rol y sesión | token de técnico → 403 `FORBIDDEN` · sin token → 401 `UNAUTHORIZED` |
| Coordinación: visibilidad | 127 mensajes = 121 públicos + 6 internos en `total_comments`; nombres reales; `is_own_message` por `user_id` |
| Coordinación: `is_internal` | ausente → 1 (**interno**) · `false` → 0 · `"1"` → 1 · `"quizas"` → 422 `INVALID_IS_INTERNAL` |
| Coordinación: texto | vacío → 400 `MISSING_COMMENT_TEXT` (con `form_data`) · 4 y 1.001 caracteres → 422 `INVALID_COMMENT_LENGTH` (con `form_data`) · 5 → 201 |
| Coordinación: archivo | multipart con foto → 201 con `photo_url` · > 5 MB → 422 `FILE_TOO_LARGE` sin residuos, con `form_data` (`comment_text`, `is_internal`) |
| Coordinación: sellado | lectura 200 (`is_sealed=true`, `can_comment=false`) · publicación 403 `CONVERSATION_SEALED` con `form_data` · bandera inválida en sellado → 422 (la guarda de la bandera precede al sellado) |
| Coordinación: auditoría | un evento `INCIDENT_COMMENT_ADDED` por mensaje, con `user_id`, `user_role = COORDINATOR`, `user_name`, `new_state = {comment_id, ticket_code, is_internal, has_photo}` y `metadata = null` |
| Técnico: lectura | asignado → 200 · no asignado → 403 `NOT_ASSIGNED_TO_TECHNICIAN` · token de coordinación → 403 · sin token → 401 · por código de ticket → 200 |
| Técnico: solo lectura histórica | tras resolver y reabrir sin asignar: 200 con `can_comment=false` y `read_only_reason=REOPENED_AWAITING_REASSIGNMENT`; publicación 403 `NOT_ASSIGNED_TO_TECHNICIAN`; tras la reasignación, `can_comment=true` y 201 |
| Técnico: privacidad | sin `is_internal` → 1 (**fail-safe interno**) · `false` → 0 · `"quizas"` → 422 |
| Técnico: archivo | multipart con foto → 201 con `photo_url` e `is_internal` respetado · > 5 MB → 422 `FILE_TOO_LARGE` sin residuos, con `form_data` (`ticket_code`, `comment_text`, `is_internal`) |
| Mensajes de respuesta | `"Nota interna registrada en el hilo de conversación"` vs `"Comentario publicado en el hilo de conversación"` según visibilidad, en ambos canales |

**Pendientes declarados, cerrados el mismo día (apartado 7.4):** `plan.md` §2.2.A describía `413 Payload Too Large` para
fotografías > 5 MB mientras el servicio responde `422 FILE_TOO_LARGE` (coherente con §3.2, §3.3 y §4.5/§5.5), y el doble
`CoordinatorIncidentDetailModalTest.mjs` simulaba el sellado con el código obsoleto `COMMENT_WINDOW_CLOSED`. Ambos se
corrigieron tras la autorización expresa: el `413` era una línea de otro documento de especificación y el doble pasaba
en verde porque el modal propaga el mensaje del servidor, no porque reflejara el contrato real.

### 7.3 Guarda anti-deriva de contrato (08/10/2026)

Para que esta clase de defecto no vuelva a colarse —y para que una enmienda futura no pueda «arreglarse» borrando
campos de la especificación— se incorpora `tests/unit/ApiContractDriftGuardTest.php` (17 aserciones), descubierta por
`tests/run_all.php` como una suite más: **210/210 suites · 7.994 aserciones · 0 fallos** en la batería global
(antes 209/7.977), con las semillas restablecidas.

**Qué certifica** (el vocabulario se deriva de los propios emisores, nunca de una segunda lista mantenida a mano):

| Bloque | Cobertura |
|---|---|
| Códigos de error | **36 códigos documentados** en `api_contracts.md` ⊆ **301 emitibles** extraídos de `src/` con el tokenizer de PHP (`Response::error(...)`, `throw new XException('CODE'…)`, `errorCode =` / `:`, `'code' =>`). Cero fantasmas |
| Aislamiento del módulo | Los **14 códigos** documentados en §3.3/§4.5/§5.5 los emite el propio módulo de comentarios (los 8 ficheros del hilo, cuya existencia también se asevera) |
| Campos del payload | Ejemplos JSON de los tres apartados ⊆ claves reales: sobre (`incident`/`pagination`/`comments`), cabecera (10 campos), paginación (5) y mensaje (8). Cero campos fantasma |
| Contrato obligatorio | Los campos del mensaje sin exclusión se documentan en los tres canales y la sede **no** documenta `is_internal`, regla leída del propio `siteExcludedKeys()` del DTO (RNF-01 / Art. V.4) |
| Anti-vacuidad | Suelos de extracción (>= 30 códigos documentados, >= 250 emitibles, >= 12 en los apartados del hilo, >= 4 ejemplos JSON): una extracción vacía falla en vez de pasar en verde |
| Mordida | Tres aserciones con fixtures que reproducen los fantasmas históricos (`COMMENT_WINDOW_CLOSED`, `COMMENT_TOO_SHORT`, `user_id`/`photo_path`/`ticket_code`) y comprueban que se detectan sin marcar los reales |

**Verificación de que la guarda muerde sobre el artefacto real:** se inyectaron temporalmente `COMMENT_WINDOW_CLOSED`
(§5.5) y `user_id` (§4.5) en la especificación y la guarda falló en 2.1, 2.2 y 4.3 con exit 1; restaurada la copia
(md5 idéntico) volvió a 17/17 exit 0.

**Alcance declarado:** la guarda cubre los códigos y campos de `specs/technical/api_contracts.md` (con foco en los tres
apartados del hilo). No cubre `specs/10-incident-comments/plan.md` —ya sin contradicciones conocidas tras el apartado
7.4— ni los códigos que emite el código sin estar documentados (67 en el recuento actual), porque el documento es una
especificación por endpoint y no un catálogo exhaustivo: la dirección exigible es que la especificación no prometa nada
que el código no emita.

### 7.4 Cierre de los pendientes documentales y del doble de test (08/10/2026)

Autorizada la corrección de los puntos que quedaban abiertos en los apartados 7.1 y 7.2, se cerraron ambos sin tocar
código de producción:

| Pendiente | Corrección aplicada |
|---|---|
| `specs/10-incident-comments/plan.md` §2.2.A: `413 Payload Too Large` | Sustituido por `422 Unprocessable Content` (`FILE_TOO_LARGE`), y el `422` de longitud y tipo de archivo nombra ahora sus códigos (`INVALID_COMMENT_LENGTH`, `INVALID_FILE_TYPE`). Coincide con el comportamiento medido en los tres canales |
| `tests/unit/CoordinatorIncidentDetailModalTest.mjs` (aserción 13.5): `422 COMMENT_WINDOW_CLOSED` | El doble lanza el error real de sellado (`403 CONVERSATION_SEALED` con el mensaje del servicio) y la aserción comprueba que el modal propaga ese texto |

**Verificación:** `tests/unit/ApiContractDriftGuardTest.php` 17/17 exit 0 (36 códigos documentados ⊆ 301 emitibles, sin
deriva), `tests/unit/CoordinatorIncidentDetailModalTest.mjs` en verde con el doble corregido y batería global
210/210 suites · 7.994 aserciones · 0 fallos con las semillas restablecidas. No queda ninguna mención a `413 Payload`
en el repositorio fuera de este informe.

---

### 7.5 Cierre de F-2: la suite de flujo entra en la batería global (08/10/2026)

Autorizada la recomendación de F-2, se cierran sus dos pasos independientes sin tocar código de producción:

| Paso | Cambio aplicado |
|---|---|
| Aserción 1.13 | Deja de leer `data.ticket_code` (campo del payload plano retirado por T-COM-05) y lee el contrato real del hilo: `data.incident.ticket_code` y el texto publicado dentro de `data.comments[]` |
| Descubrimiento | `tests/run_all.php` incorpora una **Fase 4** propia que ejecuta `tests/Manual/*.php` con el servidor HTTP todavía en marcha, con su contador `Suites E2E Manuales (T-40)` integrado en los totales y en la lista de fallos |

**Decisión de descubrimiento:** la carpeta se recorre con el mismo patrón que `unit/` e `integration/`
(`glob('Manual/*.php')`), en último lugar y antes de cerrar el servidor, porque sus guiones son no interactivos,
autosuficientes y purgan y re-siembran por su cuenta; la guardia de la Fase 0 ya auditaba esa carpeta contra
borrados ingenuos. El contrato de la carpeta queda escrito en el propio ejecutor. Se añade además un patrón de
extracción específico para el resumen `Total Aserciones E2E Evaluadas : N`, sin tocar el que ya usan las demás
suites (`Total Aserciones: N`), para que el recuento global siga siendo exacto.

**Verificación:** `php tests/Manual/E2EVerificationRunner.php` → **44/44 aserciones, exit 0** (antes 43/44, exit 1);
`php tests/run_all.php` → **211/211 suites · 8.038 aserciones · 0 fallos · exit 0** (antes 210/7.994), con las
semillas canónicas restablecidas (13 incidencias, 0 comentarios) y sin residuos en `public/uploads/`.
