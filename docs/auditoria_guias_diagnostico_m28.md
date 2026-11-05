# Auditoría de la feature de Guías de Diagnóstico (Módulo 28)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `docs/auditoria_guias_diagnostico_m28.md`  
**Fecha:** 5 de noviembre de 2026  
**Rama revisada:** `troubleshooting-guides`  
**Commit de referencia:** `e8d8806` (`fix(guides): collapse technician troubleshooting guides`)  
**PR:** [#14](https://github.com/Rugsoft/VendGuard/pull/14)  
**Naturaleza:** revisión experta con corrección posterior de los seis hallazgos, ya comprometida en la rama `troubleshooting-guides`. **No se ha modificado la base de datos.**  
**Objeto:** evaluar la feature de guías rápidas de diagnóstico, conservar los seis hallazgos detectados y documentar su tratamiento.

---

## Resumen ejecutivo

La feature tiene una base sólida: separa dominio, servicio, repositorio y presentación; mantiene el aislamiento por rol; no expone importes económicos; contempla la copia offline; y valida el contenido en servidor. No se ha detectado un fallo grave de autorización ni de seguridad.

Se conservan **seis hallazgos** priorizados. Los dos primeros afectan directamente a la pantalla del técnico; el tercero puede modificar datos del catálogo sin que Coordinación lo advierta. Los tres restantes son de calidad, semántica y rendimiento.

| ID | Severidad | Hallazgo | Estado |
| :--- | :--- | :--- | :--- |
| H-28-01 | Alta | La alerta sanitaria queda oculta con el plegado y desaparece si no hay guía | Resuelto en la rama `troubleshooting-guides` |
| H-28-02 | Alta | IDs DOM duplicados entre las tarjetas de la ruta del técnico | Resuelto en la rama `troubleshooting-guides` |
| H-28-03 | Media | Editar una guía puede borrar repuestos inactivos sin avisar | Resuelto en la rama `troubleshooting-guides` |
| H-28-04 | Media | Un resultado de búsqueda se etiqueta como «Guía sugerida» | Resuelto en la rama `troubleshooting-guides` |
| H-28-05 | Media-baja | Consultas repetidas por repuesto y por parada | Resuelto en la rama `troubleshooting-guides` |
| H-28-06 | Baja | Los filtros del catálogo de Coordinación no usan el servidor | Resuelto en la rama `troubleshooting-guides` |

---

## H-28-01 · La alerta sanitaria queda oculta con el plegado y desaparece si no hay guía

**Severidad:** Alta  
**Ámbito:** Técnico de campo / superficie de guías  
**Referencias normativas:** Art. II de la Constitución, `RF-01.3`, `EARS 01.3`

### Evidencia

- El banner sanitario vive dentro de `.vg-guide-accordion__body`:
  - `public/assets/js/components/TroubleshootingGuideAccordion.js:403-410`
- El técnico activa `collapsible`, que renderiza un `<details>` sin `open` por defecto:
  - `public/assets/js/views/TechnicianRouteView.js:4245-4251`
- El CSS oculta el cuerpo cuando el `<details>` está cerrado:
  - `public/assets/css/styles.css:1019-1021`
- Además, el acordeón solo se monta si hay al menos una guía:
  - `public/assets/js/views/TechnicianRouteView.js:4246`

### Impacto

En una avería de frío o en una máquina de perecederos, el técnico no ve la «Alerta sanitaria prioritaria» hasta que abre manualmente la guía. El resumen solo muestra el número de guías y el chevron, sin ninguna señal de riesgo sanitario.

Si la máquina perecedera no tiene guía, el componente ni siquiera se monta y el aviso desaparece por completo, aunque el servidor devuelva `sanitary_cold_warning_for_machine = true`. En Coordinación, la sección sí se muestra siempre que exista modelo de máquina, por lo que el comportamiento es inconsistente entre superficies.

### Recomendación

- Mantener el plegado abierto por defecto cuando exista riesgo sanitario (`sanitaryWarning`, `PERISHABLE_FOOD` o `TEMPERATURE_COLD`), o mover el banner fuera del cuerpo plegable.
- Incluir una señal visible en el `<summary>` cuando el riesgo esté activo.
- Renderizar el aviso sanitario aunque no existan guías, junto al estado «Sin guía de diagnóstico para este modelo todavía».
- Añadir una prueba de regresión que compruebe que el aviso es visible en la pantalla del técnico, no solo que el computed devuelve `true`.

### Tratamiento aplicado (5 de noviembre de 2026)

Se movió el banner fuera del bloque plegable, de modo que sigue visible con la guía cerrada y también en la superficie de Coordinación. En la ruta del técnico, el acordeón se monta ahora cuando existen guías **o** aviso sanitario, y la nueva prop `showEmpty` evita duplicar el estado vacío que la tarjeta ya pinta por su cuenta.

- `public/assets/js/components/TroubleshootingGuideAccordion.js`: banner movido antes del `<details>`; prop `showEmpty` con valor por defecto `true`; el estado vacío pasa a `v-else-if="showEmpty"`.
- `public/assets/js/views/TechnicianRouteView.js`: condición `guías > 0 || troubleshootingGuideWarningFor(incident)` y `:show-empty="troubleshootingGuidesFor(incident).length > 0"`.
- `tests/unit/TroubleshootingGuideAccordionTest.mjs`: aserción 8.1 (banner antes del bloque plegable).
- `tests/unit/TroubleshootingGuideUiContractTest.mjs`: aserción 5.1 (montaje con guías o aviso y `show-empty`).

---

## H-28-02 · IDs DOM duplicados entre las tarjetas de la ruta del técnico

**Severidad:** Alta  
**Ámbito:** Técnico de campo / accesibilidad y semántica HTML

### Evidencia

El buscador usa siempre el mismo identificador:

```html
<label for="guide-error-search">
<input id="guide-error-search">
```

`public/assets/js/components/TroubleshootingGuideAccordion.js:450-457`

La ruta renderiza una instancia del acordeón por incidencia:

- `public/assets/js/views/TechnicianRouteView.js:4017` (`v-for="incident in filteredIncidents"`)
- `public/assets/js/views/TechnicianRouteView.js:4245` (`<TroubleshootingGuideAccordion ...>`)

### Impacto

Con dos o más paradas hay varios elementos con `id="guide-error-search"`. Todos los `<label for>` apuntan al primer input del documento: al pulsar la etiqueta de la segunda tarjeta, el foco se desplaza al buscador de la primera. El HTML deja de ser válido y la asociación accesible es incorrecta.

### Recomendación

Generar un identificador único por instancia, por ejemplo a partir de `incidentId`, y usarlo tanto en `for` como en `id`. Alternativa más sencilla: eliminar el `for` y dejar el input dentro del `<label>`, que ya lo envuelve.

### Tratamiento aplicado (5 de noviembre de 2026)

Cada instancia recibe un identificador propio generado por un contador de módulo, usado tanto en el `for` de la etiqueta como en el `id` del input.

- `public/assets/js/components/TroubleshootingGuideAccordion.js`: `accordionInstanceSequence`, `searchInputId` en `data()` y bindings `:for` / `:id`.
- `tests/unit/TroubleshootingGuideAccordionTest.mjs`: aserciones 8.2 (no hay literal fijo) y 8.3 (dos instancias generan IDs distintos).

---

## H-28-03 · Editar una guía puede borrar repuestos inactivos sin avisar

**Severidad:** Media  
**Ámbito:** Coordinación / editor de guías  
**Referencias:** `C2`

### Evidencia

En `public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js`:

- `openEdit()` conserva en el formulario todos los IDs, incluidos los inactivos (`:327-351`).
- El editor solo pinta repuestos activos (`:852`).
- Al guardar, `selectedPartIds` filtra los IDs a los que siguen activos (`:448-450`).
- Después compara esa lista filtrada con `originalSparePartIds`, que incluye los inactivos (`:476`).

### Impacto

Si una guía tenía un repuesto que fue desactivado posteriormente, **cualquier edición**, incluso cambiar solo el título, envía `recommended_spare_part_ids` sin ese repuesto y lo elimina de la guía. El usuario no lo ve, no puede deseleccionarlo y no recibe ningún aviso.

El backend está preparado para no bloquear la edición: si el campo no se envía, conserva la asociación. Pero el frontend siempre lo envía porque la comparación detecta una diferencia que en realidad no ha provocado el usuario. El camino tolerante del servidor queda así inutilizado desde la interfaz.

### Recomendación

- Si el usuario no toca la lista de repuestos, omitir `recommended_spare_part_ids` del payload.
- O mostrar los repuestos inactivos asociados con una marca «Inactiva» y permitir quitarlos explícitamente.
- La segunda opción es más transparente: Coordinación ve lo que la guía conserva y decide si lo mantiene o lo retira.

### Tratamiento aplicado (5 de noviembre de 2026)

Se combinaron ambas recomendaciones. El editor muestra las piezas inactivas asociadas con una advertencia y un botón de retirada explícita, y solo envía `recommended_spare_part_ids` cuando Coordinación ha tocado la lista. Si no la toca, el campo se omite y el servidor conserva las asociaciones inactivas.

- `public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js`: `partsTouched`, computed `inactiveRecommendedParts`, método `removeInactivePart()` y omisión del campo en `saveGuide()`.
- `public/assets/css/styles.css`: variante tokenizada `.vg-guides-tab__alert--warning`, sin hexadecimales ni sombras.
- `tests/unit/TroubleshootingGuideEditorPartsTest.mjs`: suite nueva con 5 aserciones (detección de inactivas, retirada explícita, omisión al no tocar la lista, envío al tocarla y aviso declarado en plantilla).

---

## H-28-04 · Un resultado de búsqueda se etiqueta como «Guía sugerida»

**Severidad:** Media  
**Ámbito:** Técnico de campo y Coordinación / semántica de la búsqueda

### Evidencia

`visibleGuides` busca en todas las guías del modelo cuando hay texto en el buscador:

`public/assets/js/components/TroubleshootingGuideAccordion.js:176-183`

Sin embargo, la etiqueta de cabecera solo comprueba `hasMultipleGuides && !showAllGuides`:

`public/assets/js/components/TroubleshootingGuideAccordion.js:480-481`

### Impacto

Si el técnico busca un código que pertenece a otra categoría, la guía encontrada se muestra bajo el rótulo «Guía sugerida para esta avería», aunque no sea la guía de la categoría reportada. Si la búsqueda devuelve varias, todas llevan el mismo rótulo en singular.

Esto puede hacer que el técnico interprete una coincidencia de búsqueda como la recomendación del sistema para su avería.

### Recomendación

- Cuando `normalizedSearch !== ''`, usar «Resultado de búsqueda» o «Guías que coinciden con el código».
- Reservar «Guía sugerida» para la guía seleccionada por categoría.
- Indicar el número de resultados cuando la búsqueda devuelva más de uno.

### Tratamiento aplicado (5 de noviembre de 2026)

La tarjeta ya no usa el rótulo de recomendación cuando hay texto en el buscador. «Guía sugerida para esta avería» queda reservado a la preselección por categoría, y la búsqueda se rotula como resultado con su recuento.

- `public/assets/js/components/TroubleshootingGuideAccordion.js`: computed `searchResultsLabel` («Resultado de búsqueda» / «Resultados de búsqueda (N)») y rama `v-if="normalizedSearch !== ''"` en la cabecera de cada tarjeta.
- `tests/unit/TroubleshootingGuideAccordionTest.mjs`: aserciones 9.1 (plantilla), 9.2 (singular) y 9.3 (plural con recuento).

---

## H-28-05 · Consultas repetidas por repuesto y por parada

**Severidad:** Media-baja  
**Ámbito:** Backend / rendimiento de lectura

### Evidencia

En `TroubleshootingGuideService::enrichGuide()` se ejecuta `sparePartRepository->findById($partId)` dentro de un bucle, una vez por cada repuesto recomendado:

`src/Application/Service/TroubleshootingGuideService.php:428-445`

`getGuidesForModel()` vuelve a cargar el catálogo activo de causas y soluciones en cada consulta (`:161-177`), y la ruta del técnico resuelve las guías parada a parada, de forma secuencial (`TechnicianRouteView.js:1358-1396`).

### Impacto

Con el catálogo actual, de ocho guías, es tolerable. Pero el límite permite diez repuestos por guía y el coste crece con el número de guías y de paradas. No se ha medido latencia en esta revisión, por lo que se registra como deuda técnica previsible, no como un fallo funcional actual.

### Recomendación

- Añadir un `findByIds()` al repositorio de repuestos y resolver todos los IDs de una guía en una sola consulta.
- Reutilizar el catálogo de causas dentro de la misma petición.
- Valorar cargar las guías de todas las paradas en una sola llamada o en paralelo si el número de paradas crece.
- Medir antes y después de cualquier cambio para no afirmar una mejora sin evidencia.

### Tratamiento aplicado (5 de noviembre de 2026)

Los repuestos de todas las guías de una consulta se resuelven en una sola operación `findByIds()`, que además carga las compatibilidades en lote. El catálogo activo de causas se memoiza por petición, de modo que varias paradas de la misma ruta no lo vuelven a leer. La validación de altas y ediciones también usa la lectura en lote.

- `src/Core/Domain/Repository/SparePartRepositoryInterface.php`: nuevo contrato `findByIds(array $ids): array`.
- `src/Infrastructure/Repository/PdoSparePartRepository.php`: una consulta `IN (...)` más `fetchBatchCompatibleModels()`, con deduplicación y omisión de ausentes.
- `src/Application/Service/TroubleshootingGuideService.php`: acopio de IDs antes de enriquecer, `enrichGuide()` recibe el mapa resuelto, `activeRootCauseCatalog()` memoiza el catálogo y `assertActiveSpareParts()` valida en lote.
- `tests/integration/PdoSparePartRepositoryTest.php`: caso 10 con tres aserciones (mapa, deduplicación y ausencias). **No ejecutado en este entorno** por falta de motor de base de datos.
- No se ha medido latencia: el cambio elimina consultas repetidas por construcción, pero no se afirma una mejora porcentual sin medición.
- La carga de todas las paradas en una sola llamada sigue pendiente; el servicio ya no repite catálogo ni repuestos por parada, pero la ruta mantiene una petición por incidencia.

---

## H-28-06 · Los filtros del catálogo de Coordinación no usan el servidor

**Severidad:** Baja  
**Ámbito:** Coordinación / escalabilidad del catálogo

### Evidencia

`CoordinatorTroubleshootingGuidesTab::loadCatalog()` llama a la API sin filtros:

`public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js:285`

Después, `filteredGuides` filtra modelo, categoría, estado y texto en el navegador:

`public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js:186-223`

El backend ya tiene filtros y búsqueda probados en `PdoMachineTroubleshootingGuideRepository::findFilteredForCoordinator()` y en la suite de integración.

### Impacto

Con ocho guías no es un problema funcional. Si el catálogo crece, la interfaz descarga todo el catálogo y duplica la lógica de búsqueda; además, no hay paginación.

### Recomendación

Mover los filtros al servidor y añadir paginación cuando el catálogo deje de ser pequeño. Mientras tanto, puede mantenerse el filtrado local, pero conviene documentar que es una decisión consciente para el MVP.

### Tratamiento aplicado (5 de noviembre de 2026)

Modelo, categoría, estado y búsqueda viajan ya al servidor, que dispone de filtros y búsqueda probados. El navegador no reimplementa el índice textual: la tabla pinta las filas que devuelve la API. La búsqueda se espera 250 ms para no lanzar una petición por tecla y las respuestas obsoletas se descartan con una secuencia de petición. El selector de modelos conserva las opciones vistas en cualquier respuesta, aunque el filtro activo reduzca la tabla.

- `public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js`: `catalogFilters`, `loadGuides()`, `scheduleGuideReload()`, `knownGuideModels`, watchers de filtro y `filteredGuides` como mera proyección de `guides`.
- `tests/unit/TroubleshootingGuideCoverageUiTest.mjs`: 18 aserciones; las secciones 3 y 4 certifican el payload enviado al servidor y la ausencia de filtrado local.
- **Paginación:** sigue sin implementarse. Se mantiene como decisión consciente de MVP: con el catálogo actual (ocho guías) la vista «Todas» descarga el catálogo completo, y la paginación se abordará cuando el catálogo crezca. El filtrado ya no se duplica.

---

## Cobertura de pruebas observada

Se ejecutaron las cinco suites frontend de la feature sobre el commit revisado y de nuevo tras las correcciones:

| Suite | Antes (`e8d8806`) | Después |
| :--- | :--- | :--- |
| `tests/unit/TroubleshootingGuideAccordionTest.mjs` | 45/45 | 51/51 |
| `tests/unit/TroubleshootingGuideSurfacesGuardTest.mjs` | 18/18 | 18/18 |
| `tests/unit/TroubleshootingGuideCoverageUiTest.mjs` | 17/17 | 18/18 |
| `tests/unit/TroubleshootingGuideUiContractTest.mjs` | 16/16 | 17/17 |
| `tests/unit/TroubleshootingGuideEditorPartsTest.mjs` | — | 5/5 |
| **Total** | **96/96** | **109/109** |

Además, `tests/integration/PdoSparePartRepositoryTest.php` incorpora tres aserciones para `findByIds()` que no se han podido ejecutar aquí por falta de motor de base de datos.

Las suites del acordeón no montan el componente: ejercitan métodos y computed, y algunas comprueban el código fuente. Tras las correcciones cubren el banner fuera del plegado, los IDs únicos y el rótulo de búsqueda. Sigue sin verificarse el estado abierto/cerrado real del `<details>`.

Los seis hallazgos tienen ahora cobertura de regresión: los cinco primeros en suites ESM y el de lectura en lote también en la suite de integración del repositorio de repuestos. La prueba de plegado real del `<details>` sigue pendiente y no forma parte de ningún hallazgo.

---

## Límites de la revisión

- No se arrancó la aplicación completa: `.env.local` apunta a un PostgreSQL local en `127.0.0.1:6543` que no estaba disponible en el entorno de revisión.
- La revisión es estática sobre código, contratos y suites frontend; no se ejecutaron las pruebas de integración PHP ni una comprobación visual nueva.
- Los números de línea son orientativos sobre `e8d8806` y pueden desplazarse con cambios posteriores.
- **Correcciones aplicadas:** los seis hallazgos están resueltos y comprometidos en la rama `troubleshooting-guides`.
- La batería global (`php tests/run_all.php`) no se ha ejecutado tras las correcciones: requiere un motor de base de datos no disponible en este entorno. La verificación se limita a las cinco suites frontend enfocadas (109/109), `node --check`, `php -l` y `git diff --check`.
- El caso 10 de `PdoSparePartRepositoryTest.php` y las suites PHP que construyen controladores con conexión real no se han podido ejecutar aquí.
