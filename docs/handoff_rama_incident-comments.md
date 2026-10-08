# Handoff · Rama `incident-comments`

**Fecha:** 2026-10-08 · **HEAD:** `c7ba4ad` · **Estado:** publicada (`origin/incident-comments` == HEAD), árbol de trabajo limpio
**Distancia respecto a `origin/main`:** 46 commits por delante (sin fusionar)
**Batería:** `php tests/run_all.php` → **batería global: 211 suites · 8.060 aserciones** · 0 fallos · exit 0 · base restaurada a semillas
*(la propia batería audita esta cifra: un desfase documental la pone en rojo vía `tests/Support/DocMetricsGuard.php`)*

---

## 1. Qué contiene la rama

Acumula el MVP Fase 1 completo, módulo a módulo, con su especificación en `specs/`: 02 códigos QR, 03 métricas y auditoría, 04 CRUD de administración, 05 mantenimiento preventivo y certificados sanitarios, 06 repuestos, 07 mapa de rutas y mapa territorial, 08 reintegros, 09 ficha integral de detalle y 10 hilo de comentarios, más los contratos técnicos transversales.

Estado del cierre por módulos: todas las tareas de `specs/*/tasks.md` están marcadas como hechas salvo **`T-QR-20`** (verificación global de regresión y validación física con Android), que sigue abierta en [specs/02-qr-codes/tasks.md](../specs/02-qr-codes/tasks.md).

## 2. Qué se ha corregido en la última tanda (2026-10-08)

| Commit | Tema | Qué cierra |
| :--- | :--- | :--- |
| `17acf02` | **Defecto real de consolidación** | El flujo masivo del mapa llamaba a la API con 4 argumentos y nunca enviaba `reassignment_reason`, así que cada incidencia ya asignada de un lote mixto respondía 422 `MISSING_REASSIGNMENT_REASON` y la consolidación acababa en «asignación parcial». Ahora valida el lote completo antes de cualquier petición, envía el motivo sólo en las filas reasignadas y ofrece el campo obligatorio cuando el lote las contiene (RF-07.3, RF-MAP-09) |
| `b648849` | **Blindaje de última línea** | Las guardas de `PdoIncidentRepository::assign()` (motivo obligatorio, mismo técnico, estados terminales) eran inalcanzables vía HTTP y no tenían prueba directa: el caso 7 las invoca saltándose el controlador |
| `134fade` | **Auditoría de reasignación** | Inventario de los cinco caminos que escriben el técnico responsable, con sus exigencias de motivo, su rastro de auditoría y las dos exenciones normativas justificadas |
| `c7ba4ad` | **Propuesta de enmienda** | Copy state-aware del modal de consolidación, redactada en fase SDD 1 y pendiente de aprobación |
| `c3d20a8` | Rótulo preventivo | La fila de orden preventiva muestra «Reasignar» cuando ya hay técnico vivo (T-PREV-18) |
| `16ea2ba` | Guarda del mapa | La acción de asignación territorial queda fijada a sedes con trabajo sin asignar (T-MAP-15) |

Evidencia de verificación de la última tanda: suite del dashboard 97/97 (grupo 14 nuevo, con emulación fiel de las reglas del backend), `AssignTechnicianEndpointTest` con el caso 7 en verde, batería global 8.060 aserciones sin fallos, prueba de mutación que demuestra que sin el quinto argumento el backend emulado rechaza, y comprobación en el navegador sobre el servidor local (el lote mixto muestra el campo y confirmar sin motivo pinta el error sin emitir ninguna petición).

## 3. Pendiente de aprobación humana (puerta SDD)

1. **Copy del flujo de consolidación** — [propuesta_enmienda_copy_consolidacion_territorial.md](propuesta_enmienda_copy_consolidacion_territorial.md): título, entradilla, chip por fila y botón state-aware, con texto exacto antes/después para siete documentos y tres decisiones abiertas (rotulación exacta, dónde se pide el motivo y si entra la ampliación de alcance `T-MAP-25`).
2. **Consolidación alcanzable en sedes multi-técnico sin trabajo sin asignar** (`T-MAP-25`): hoy el botón se oculta por `has_unassigned = false`, de modo que el caso que motiva el EARS de multi-técnico no se puede ejecutar desde la interfaz. Es cambio de comportamiento y requiere su propio gate.
3. **Motivo obligatorio en la reasignación preventiva**: la especificación no lo exige y no existe EARS de reasignación preventiva; hacerlo sin enmienda sería inventar requisito (documentado en la auditoría).
4. **Criterios de aceptación sin marcar**: `mvp_functional_spec.md` tiene 8 casillas y `preventive_maintenance_spec.md` 10 sin marcar. Su cierre formal es el acta de fin del MVP Fase 1, no un defecto de código.

## 4. Riesgos abiertos

1. **Preselección del técnico en el lote:** el modal propone siempre `technicians[0]`; si ese técnico ya es el responsable de alguna fila, el backend responde `TECHNICIAN_ALREADY_ASSIGNED` y esa fila queda como error parcial. Rechazo correcto, experiencia mejorable.
2. **Hallazgos de seguridad de la auditoría de arquitectura (`docs/auditoria_arquitectura.md`, 30-sep-2026):** el informe **no está versionado** (`.gitignore:20`), así que sus hallazgos no viajan con el repositorio. Comprobado hoy que **S-2 sigue vigente** (`POST /api/cron/auto-close` registrado sin middleware en `AppRouter.php:219`) y que `AuthService` no lee `SECRET_KEY` de entorno (S-1). No se ha re-auditado el resto del informe en este handoff.
3. **Documentación desfasada:** `docs/features_pendientes.md` declara «165 suites y 6.263 aserciones» (hoy 211 y 8.060) y el informe constitucional previo está señalado como desfasado por el propio informe de arquitectura.
4. **Expectativa de interfaz:** mientras la enmienda de copy no se apruebe, el modal de consolidación sigue rotulando «Asignar» y «pendiente(s)», lo que puede inducir a error al coordinador aunque el comportamiento ya sea correcto.

## 5. Cómo verificar

```bash
# Batería completa (requiere MariaDB local; reutiliza o arranca el servidor en 127.0.0.1:8000)
php tests/run_all.php          # esperado: 211/211 suites, 8.060 aserciones, 0 fallos, exit 0

# Suites concretas de la última tanda
node tests/unit/CoordinatorDashboardViewTest.mjs        # 97/97
php tests/integration/AssignTechnicianEndpointTest.php  # incluye el caso 7
php tests/Manual/E2EVerificationRunner.php              # recorrido E2E de los 3 perfiles
```

La batería restablece la base a semillas al terminar, así que no deja datos de prueba.

## 6. Convenciones que no conviene romper

* **SDD:** ninguna línea de código sin especificación aprobada; los cambios de comportamiento pasan por enmienda y puerta de aprobación (AGENTS.md §2 y §5).
* **Idioma:** documentación, specs e interfaz en español; código, tests y mensajes de Git en inglés (Conventional Commits con scope y código de tarea); cuerpo de commit sin acentos.
* **Dogma Vanilla:** cero dependencias (sin Composer, npm, bundlers en runtime); PHP 8 puro con `strict_types` y Vue 3 en ES Modules.
* **Constitución:** nada de borrado físico, `audit_log` append-only, motivo obligatorio en reasignaciones de averías y ventana de garantía de 48 h.

## 7. Siguientes pasos sugeridos (por valor)

1. Aprobar o ajustar la enmienda de copy y aplicarla (cierra la última incoherencia visible del flujo de consolidación).
2. Decidir `T-MAP-25` para que la consolidación sea alcanzable en sedes multi-técnico sin trabajo sin asignar.
3. Resolver la preselección del técnico en el lote (evitar el 422 fila a fila).
4. Triaje de los hallazgos S-1..S-4 / H-1..H-4: decidir cuáles entran en la Fase 1 y versionar o archivar el informe de arquitectura.
5. Completar `T-QR-20` (validación física en Android) y marcar las casillas de aceptación de los dos pliegos.
