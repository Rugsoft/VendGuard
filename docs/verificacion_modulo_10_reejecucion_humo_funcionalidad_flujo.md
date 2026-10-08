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

**Qué ocurre.** `php tests/Manual/E2EVerificationRunner.php` termina con **exit 1: 43/44 aserciones**, y la
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
lleva en rojo desde T-COM-05 sin que nadie lo notara porque `tests/run_all.php` **no descubre** las suites de
`tests/Manual/`: sólo las recorre el guardián de disciplina de limpieza. Un runner rojo que nadie ejecuta es
peor que no tenerlo: al re-ejecutar el flujo (como en esta verificación) aparece como ruido y erosiona la
confianza en la evidencia.

**Recomendación.** Dos pasos independientes: (1) alinear la aserción 1.13 con el contrato aprobado
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

**Conclusión.** El módulo 10 **no presenta ningún defecto de producto** en humo, funcionalidad ni flujo: los
25 conjuntos de pruebas del módulo, las 68 comprobaciones de caja negra y las 209 suites de la batería global
pasan sin un solo fallo, con las semillas intactas. Los dos hallazgos están **en el borde del módulo con su
documentación y su evidencia**: un contrato de API sin enmendar desde T-COM-05 (F-1) y una aserción de flujo
obsoleta en un runner que la batería global no ejecuta (F-2). Ninguno afecta a la seguridad de la conversación
ni a los datos, y ambos exigen una decisión de especificación antes de tocar código.

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

### 7.1 Deriva hermana detectada en el mismo barrido (pendiente de decisión)

**§4.5 · Comentarios de coordinación: mismo desfase, no enmendado.** Medido en el mismo servicio:

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

**Contradicción menor:** `specs/10-incident-comments/plan.md` §2.2.A documenta `413 Payload Too Large` para fotografías de
más de 5 MB, mientras el servicio responde `422 FILE_TOO_LARGE` (coherente con §3.2 y con la §3.3 recién enmendada).

Las tres son correcciones de documentación del mismo tipo que F-1 y quedan fuera de la enmienda autorizada de §3.3.
