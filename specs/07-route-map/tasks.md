# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO M4: MAPA INTERACTIVO DE RUTAS Y GEORREFERENCIACIÓN (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `07-route-map` (Opción D: Logística de Campo)  
**Documento:** `specs/07-route-map/tasks.md`  
**Referencia Funcional:** [`specs/functional/route_map_spec.md`](../functional/route_map_spec.md) (RF-MAP-01 a RF-MAP-10, RNF-MAP-01 a RNF-MAP-06)  
**Contratos Técnicos y DDL:** [`specs/technical/route_map_contracts.md`](../technical/route_map_contracts.md)  
**Plan Técnico:** [`specs/07-route-map/plan.md`](plan.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Inspiración Docker: Azul eléctrico `#2560ff`, radio binario 4px/8px, fondo canvas `#f9fafb`, alertas `#f8b60f` y crítico `#e02424`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos, Modelos y Excepciones de Dominio

- [x] **T-MAP-01: Migración DDL idempotente y semillas normativas (`007_field_logistics_map.sql`, `cloud_init.sql`, `SeedRunner.php`)**
  * **Requisitos:** RF-MAP-01, RF-MAP-02, Constitución Art. III (Inviolabilidad de datos y soft delete)
  * **Dependencias:** Ninguna
  * **Hecho cuando:** La ejecución de `php bin/migrate.php` aplica con éxito `007_field_logistics_map.sql`, agregando las columnas `latitude` y `longitude` a `locations`, creando el índice `idx_locations_lat_lng` y la tabla `route_settings`; `database/cloud_init.sql` y `SeedRunner.php` incorporan coordenadas reales validadas para las sedes de prueba en Barcelona (`SEDE-BCN-01` y `SEDE-BCN-02`) y la fila singleton de Base Central.

- [x] **T-MAP-02: Implementar Enums y Modelos de Dominio (`StopStatus`, `StopPriority`, actualización de `Location`, `RouteStop`, `TechnicianRouteMap`, `RouteSettings`)**
  * **Requisitos:** RF-MAP-01, RF-MAP-02, RF-MAP-03, RF-MAP-04, RF-MAP-08, Constitución Art. VI (Anti-feature creep)
  * **Dependencias:** T-MAP-01
  * **Hecho cuando:** Existen en `src/Core/Domain/Model/` los enums tipados (`StopStatus`, `StopPriority`), la entidad `Location` actualizada con `latitude` y `longitude`, y las entidades inmutables con tipado estricto PHP 8.2+ (`RouteStop`, `TechnicianRouteMap`, `RouteSettings`) con validaciones de invariantes de dominio.

- [x] **T-MAP-03: Implementar Excepciones de Dominio y suite unitaria (`RouteExceptionsTest.php`, `RouteDomainModelsTest.php`)**
  * **Requisitos:** RF-MAP-01, RF-MAP-03, RF-MAP-04, RF-MAP-10, Constitución Art. V.4
  * **Dependencias:** T-MAP-02
  * **Hecho cuando:** Existen en `src/Core/Domain/Exception/` las excepciones tipadas (`InvalidCoordinatesException`, `CoordinatesOutOfBoundsException`, `SiteRouteDataForbiddenException`), y `php tests/unit/RouteExceptionsTest.php` junto con `php tests/unit/RouteDomainModelsTest.php` pasan al 100% en verde evaluando códigos HTTP (`403`, `422`), mensajes en castellano, progreso de tareas y herencia de prioridad crítica.

---

## Fase 2: Repositorios PDO y Servicios de Aplicación

- [x] **T-MAP-04: Implementar `RouteSettingsRepositoryInterface`, `PdoRouteSettingsRepository.php` y actualizar `PdoLocationRepository.php`**
  * **Requisitos:** RF-MAP-01, RF-MAP-02, RNF-MAP-05, Constitución Art. III
  * **Dependencias:** T-MAP-01, T-MAP-02, T-MAP-03
  * **Hecho cuando:** `PdoRouteSettingsRepository` implementa lectura y actualización de la configuración singleton de Base Central; `PdoLocationRepository` persiste y recupera `latitude` y `longitude` en todas las consultas; y `php tests/integration/PdoRouteSettingsRepositoryTest.php` pasa al 100% en verde.

- [x] **T-MAP-05: Implementar `GeoDistanceService.php` y suite unitaria (`GeoDistanceServiceTest.php`)**
  * **Requisitos:** RF-MAP-01, RF-MAP-05, RNF-MAP-01
  * **Dependencias:** T-MAP-02
  * **Hecho cuando:** `GeoDistanceService` implementa la fórmula trigonométrica pura de Haversine (`calculateDistanceKm`) y la validación de marco territorial (`isInsideOperationalArea`); `php tests/unit/GeoDistanceServiceTest.php` pasa al 100% en verde verificando distancias geodésicas conocidas, distancia cero sobre el mismo punto y rechazo de coordenadas fuera de rango territorial o invertidas.

- [x] **T-MAP-06: Implementar `RouteSettingsService.php` y suite unitaria (`RouteSettingsServiceTest.php`)**
  * **Requisitos:** RF-MAP-02
  * **Dependencias:** T-MAP-04, T-MAP-05
  * **Hecho cuando:** `RouteSettingsService` permite obtener y actualizar los datos de la Base Central validando que las coordenadas se encuentren dentro del área operativa; `php tests/unit/RouteSettingsServiceTest.php` pasa al 100% en verde.

- [x] **T-MAP-07: Implementar `RouteOptimizationService.php` y suite unitaria (`RouteOptimizationServiceTest.php`)**
  * **Requisitos:** RF-MAP-03, RF-MAP-04, RF-MAP-05, RF-MAP-06, RF-MAP-08, RNF-MAP-01, RNF-MAP-03, Constitución Art. II
  * **Dependencias:** T-MAP-04, T-MAP-05, T-MAP-06
  * **Hecho cuando:** `RouteOptimizationService` ejecuta la secuenciación híbrida de 4 fases (parada `IN_PROGRESS` como #1 inamovible, paradas críticas de perecederos ordenadas por SLA inminente, paradas ordinarias por Vecino Más Cercano y exclusión de `PENDING_PARTS`), generando URLs universales de Google Maps; `php tests/unit/RouteOptimizationServiceTest.php` pasa al 100% en verde con tiempo de ejecución $< 50\text{ ms}$.

---

## Fase 3: Controladores REST y Enrutamiento HTTP

- [x] **T-MAP-08: Implementar `TechnicianRouteMapController.php` (`GET /api/technician/route/map`)**
  * **Requisitos:** RF-MAP-03, RF-MAP-04, RF-MAP-05, RF-MAP-06, RF-MAP-07, RF-MAP-08, RF-MAP-10, Constitución Art. V.4
  * **Dependencias:** T-MAP-07
  * **Hecho cuando:** El endpoint responde `200 OK` con el JSON de origen (GPS o Base Central fallback), paradas agrupadas correlativas, desglose correctivo/preventivo y URLs de Google Maps, rechazando usuarios no técnicos (`403`) y peticiones no autenticadas (`401`).

- [x] **T-MAP-09: Implementar `CoordinatorRouteMapController.php` (`GET /api/coordinator/map/active-incidents`, `GET/PUT /api/coordinator/route/settings`)**
  * **Requisitos:** RF-MAP-02, RF-MAP-09, RF-MAP-10
  * **Dependencias:** T-MAP-06, T-MAP-07
  * **Hecho cuando:** Los endpoints responden `200 OK` devolviendo la matriz territorial de sedes activas, severidad máxima, técnicos asignados y bandera `is_multi_technician`, y permiten consultar/actualizar la configuración de la Base Central.

- [x] **T-MAP-10: Actualizar `CoordinatorAdminController.php` con validación de coordenadas obligatorias**
  * **Requisitos:** RF-MAP-01
  * **Dependencias:** T-MAP-04, T-MAP-05
  * **Hecho cuando:** `POST /api/coordinator/locations` y `PUT /api/coordinator/locations/{id}` exigen obligatoriamente `latitude` y `longitude` numéricas y dentro del marco territorial operativo, respondiendo `422 Unprocessable Entity` si faltan o son inválidas.

- [x] **T-MAP-11: Registrar rutas en `AppRouter.php` y verificar enrutamiento**
  * **Requisitos:** RF-MAP-01, RF-MAP-02, RF-MAP-07, RF-MAP-09
  * **Dependencias:** T-MAP-08, T-MAP-09, T-MAP-10
  * **Hecho cuando:** Las nuevas rutas `/api/technician/route/map`, `/api/coordinator/map/active-incidents` y `/api/coordinator/route/settings` están registradas y protegidas por RBAC en `AppRouter.php`, y los tests unitarios de enrutamiento pasan al 100% en verde.

---

## Fase 4: Componentes Frontend Vanilla (Vue.js 3 ES Modules)

- [x] **T-MAP-12: Actualizar `AdminLocationsTab.js` con campos de coordenadas y geocodificación asistida**
  * **Requisitos:** RF-MAP-01, RNF-MAP-06
  * **Dependencias:** T-MAP-10
  * **Hecho cuando:** El formulario de alta y edición de Sedes incluye inputs numéricos para latitud y longitud, botón de "Geocodificar dirección" que sugiere coordenadas automáticamente y visualización previa de confirmación.

- [ ] **T-MAP-13: Implementar componente `TechnicianRouteMapModal.js` y suite unitaria frontend (`TechnicianRouteMapModalTest.mjs`)**
  * **Requisitos:** RF-MAP-06, RF-MAP-07, RF-MAP-08, RNF-MAP-02, RNF-MAP-03, RNF-MAP-06
  * **Dependencias:** T-MAP-08
  * **Hecho cuando:** El componente modal muestra el mapa responsive móvil con marcadores circulares numerados (1, 2, 3...), colores semánticos (rojo para críticos, azul para ordinarios, ámbar para en progreso, gris para completados), ficha de parada al tocar y botones de navegación; `node tests/unit/TechnicianRouteMapModalTest.mjs` pasa al 100% en verde.

- [ ] **T-MAP-14: Integrar apertura de mapa y navegación en `TechnicianRouteView.js`**
  * **Requisitos:** RF-MAP-07, RF-MAP-08
  * **Dependencias:** T-MAP-13
  * **Hecho cuando:** La vista móvil del técnico incluye el botón prominente de "Ver Mapa de Ruta", enlace general a Google Maps con waypoints, y cada tarjeta de parada dispone de un botón directo "Navegar con GPS".

- [ ] **T-MAP-15: Implementar componente `CoordinatorTerritorialMapTab.js` y suite unitaria frontend (`CoordinatorTerritorialMapTabTest.mjs`)**
  * **Requisitos:** RF-MAP-09, RNF-MAP-02, RNF-MAP-06
  * **Dependencias:** T-MAP-09
  * **Hecho cuando:** El componente renderiza el mapa territorial global para coordinación, agrupando sedes, mostrando insignias con recuento de averías, destacando sedes multi-técnico y ofreciendo filtros reactivos por técnico y severidad; `node tests/unit/CoordinatorTerritorialMapTabTest.mjs` pasa al 100% en verde.

- [ ] **T-MAP-16: Integrar pestaña del mapa territorial en la navegación de Coordinación (`AppNavbar.js`, `CoordinatorDashboardView.js`, `app.js`)**
  * **Requisitos:** RF-MAP-09
  * **Dependencias:** T-MAP-15
  * **Hecho cuando:** La barra de navegación del coordinador incluye la pestaña "Mapa Territorial" accesible desde el dashboard de triaje con alternancia fluida de pestañas.

---

## Fase 5: Pruebas de Integración HTTP, Blindaje Constitucional y Cierre

- [ ] **T-MAP-17: Pruebas de integración HTTP de Coordinación (`CoordinatorRouteMapApiTest.php`)**
  * **Requisitos:** RF-MAP-01, RF-MAP-02, RF-MAP-09
  * **Dependencias:** T-MAP-09, T-MAP-10, T-MAP-11
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `CoordinatorRouteMapApiTest.php` contra MariaDB real pasando al 100% en verde en flujos de consulta de averías territoriales, detección multi-técnico, consulta/edición de Base Central y validación obligatoria de coordenadas en sedes.

- [ ] **T-MAP-18: Pruebas de integración HTTP de Técnico (`TechnicianRouteMapApiTest.php`)**
  * **Requisitos:** RF-MAP-03, RF-MAP-04, RF-MAP-05, RF-MAP-06, RF-MAP-08, RNF-MAP-01, RNF-MAP-03, RNF-MAP-04
  * **Dependencias:** T-MAP-08, T-MAP-11
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `TechnicianRouteMapApiTest.php` contra MariaDB real pasando al 100% en verde en flujos de parada `#1` para `IN_PROGRESS`, priorización absoluta de averías perecederas (SLA < 4h), ordenación Nearest Neighbor, exclusión de `PENDING_PARTS`, fallback a Base Central y URLs universales de Google Maps.

- [ ] **T-MAP-19: Pruebas de blindaje constitucional y segregación de sede (`SiteManagerRouteDataSegregationTest.php`)**
  * **Requisitos:** RF-MAP-10, Constitución Art. V.4
  * **Dependencias:** T-MAP-11
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `SiteManagerRouteDataSegregationTest.php` pasando al 100% en verde, certificando que ningún endpoint de ruta o mapa es accesible por el rol `LOCATION_MANAGER` (403 Forbidden) y que no existe rastreo personal continuo del smartphone de los técnicos.

- [ ] **T-MAP-20: Verificación global de regresión, Dogma Vanilla y cierre de módulo**
  * **Requisitos:** Todos (RF-MAP-01 a RF-MAP-10, RNF-MAP-01 a RNF-MAP-06, Constitución Art. I a VII)
  * **Dependencias:** T-MAP-01 a T-MAP-19
  * **Hecho cuando:** La ejecución de `php tests/run_all.php` completa todas las suites unitarias PHP, unitarias reactivas frontend e integración con 0 fallos y 0 errores; se verifica la ausencia de dependencias externas npm/composer y el cumplimiento estricto del Dualismo Lingüístico.
