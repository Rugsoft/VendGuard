# ESPECIFICACIÓN FUNCIONAL · PANEL CRUD DE ADMINISTRACIÓN INTEGRAL (SEDES, MÁQUINAS Y TÉCNICOS)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `04-admin-crud`  
**Documento:** `specs/functional/admin_crud_spec.md`  
**Estado:** Especificación Formal Consolidada (Pendiente de Aprobación Técnica)  
**Metodología:** SDD (Specification-Driven Development) · Notación EARS  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.3, 5.4) y VI de la Constitución de VendGuard  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
VendGuard gestiona operativamente el ciclo de vida de incidencias en máquinas de vending, la trazabilidad sanitaria de la cadena de frío, la generación física de códigos QR y el análisis de métricas de servicio (MTTR) con auditoría inmutable.

Sin embargo, el aprovisionamiento de las entidades maestras del negocio (Sedes de clientes, Parque de Máquinas y Personal Técnico de Ruta) se encontraba acotado a cargas estáticas de base de datos o semillas preconfiguradas. En el día a día operativo de una empresa de vending:
1. **Las sedes crecen o cambian:** Se firman contratos con nuevos hospitales, universidades o complejos de oficinas, y se actualizan interlocutores y teléfonos de contacto.
2. **El parque de máquinas es dinámico:** Se adquieren nuevos modelos, se reubican máquinas dentro de un edificio (cambio de planta o ala), se trasladan máquinas entre diferentes sedes o se adaptan temporalmente sus bandejas para dispensar alimentos frescos perecederos con requisitos de frío.
3. **El equipo técnico rota:** Se incorporan nuevos técnicos de ruta, se actualizan teléfonos de guardia, se resetean contraseñas olvidadas y se tramitan bajas laborales o de personal sin comprometer las averías actualmente en curso ni perder el historial histórico de intervenciones.

Para preservar la integridad constitucional de VendGuard, toda gestión administrativa de estas entidades maestras debe someterse a dos reglas inviolables:
- **Prohibición absoluta del borrado físico (*Hard Delete*, Art. III.1):** Ninguna sede, máquina o técnico puede ser eliminado con sentencias destructivas; toda baja es lógica (*Soft Delete*).
- **Control estricto de dependencias e integridad referencial:** Una sede no puede desactivarse si mantiene máquinas activas, una máquina no puede desactivarse ni trasladarse si tiene averías abiertas en curso, y un técnico no puede darse de baja si tiene órdenes de trabajo activas asignadas a su ruta.

### 1.2 Objetivo
Definir los requisitos funcionales y de negocio para un **Panel de Administración Integral (CRUD)** integrado en el entorno de Coordinación, que permita:
1. **Administrar Sedes de Clientes:** Altas, modificaciones de contacto/dirección, bajas lógicas con bloqueo preventivo y reactivación de sedes.
2. **Administrar el Parque de Máquinas:** Altas con tipología sanitaria, traslados entre sedes y plantas con preservación del histórico de averías pasadas, bajas lógicas condicionadas y reactivación.
3. **Administrar el Personal Técnico:** Altas con credenciales seguras, actualización de datos de contacto, reseteo administrativo de contraseñas, bajas lógicas con control de tareas pendientes y reactivación.
4. **Garantizar la Auditoría Permanente (*Audit Log*):** Registrar cronológicamente en el log inmutable cada alta, cambio maestro, traslado, reseteo, baja o reactivación con su estado anterior y nuevo.

---

## 2. Usuarios y Roles

* **Coordinador del Servicio:** Único usuario autorizado para acceder a este panel de administración integral. Es responsable de la creación, supervisión, modificación de datos maestros, traslados, bajas lógicas, reseteos de credenciales y reactivación de sedes, máquinas y técnicos.
* **Técnico de Ruta:** Usuario afectado por la administración de usuarios. No tiene acceso de edición sobre el catálogo de sedes ni máquinas, ni puede administrar a otros técnicos. Es el destinatario de las credenciales y las asignaciones de averías.
* **Responsable de Sede (Cliente):** Usuario afectado por la administración de sedes. Accede a su portal mediante el código único de sede (`site_code`) gestionado en este panel. No puede modificar datos del parque ni crear máquinas.
* **Usuario Final (Consumidor):** Sin acceso alguno a funciones de administración.

---

## 3. Historias de Usuario

* **HU-ADM-01 (Alta y Mantenimiento de Sedes):** *Como* Coordinador, *quiero* dar de alta nuevas sedes y actualizar los datos de contacto y dirección de las existentes *para* mantener al día la información operativa de los clientes y permitir que los responsables de sede accedan con sus códigos.
* **HU-ADM-02 (Baja y Reactivación Controlada de Sedes):** *Como* Coordinador, *quiero* desactivar sedes que hayan finalizado contrato asegurando que no queden máquinas activas, y poder reactivarlas si renuevan *para* evitar errores operativos sin perder el historial.
* **HU-ADM-03 (Alta y Catalogación Sanitaria de Máquinas):** *Como* Coordinador, *quiero* registrar nuevas máquinas vinculándolas a una sede y clasificándolas según su tipología (especialmente alimentos perecederos) *para* integrarlas en la operativa y generar sus etiquetas QR.
* **HU-ADM-04 (Traslado y Reubicación de Máquinas):** *Como* Coordinador, *quiero* trasladar una máquina de una sede a otra o cambiarla de planta/ala cuando no tenga averías pendientes *para* reflejar su ubicación física real sin distorsionar el historial de tickets pasados.
* **HU-ADM-05 (Baja y Reactivación de Máquinas con Salvaguarda):** *Como* Coordinador, *quiero* dar de baja máquinas obsoletas o averiadas de forma lógica impidiendo la baja si hay avisos abiertos *para* proteger la integridad de las rutas técnicas y reactivarlas si vuelven de taller.
* **HU-ADM-06 (Gestión Integral de Técnicos y Credenciales):** *Como* Coordinador, *quiero* crear nuevos técnicos de campo, actualizar sus teléfonos y resetear sus contraseñas en caso de bloqueo *para* asegurar la continuidad del servicio en ruta.
* **HU-ADM-07 (Baja Segura de Técnicos sin Pérdida de Carga):** *Como* Coordinador, *quiero* que el sistema me impida desactivar a un técnico si tiene averías pendientes asignadas *para* obligarme a reasignarlas previamente y evitar que queden averías huérfanas.
* **HU-ADM-08 (Auditoría Inmutable de Cambios Maestros):** *Como* Coordinador, *quiero* que cualquier cambio en sedes, máquinas o técnicos quede reflejado en el registro de auditoría *para* garantizar el cumplimiento del Artículo III constitucional.

---

## 4. Requisitos Funcionales y Criterios de Aceptación (Notación EARS)

### RF-01: Gestión Integral de Sedes de Clientes (CRUD Sedes)

* **EARS 1.1 (Alta de Sede):** Cuando el Coordinador envíe el formulario de alta de sede con código único (`site_code`), nombre de la sede, dirección postal, nombre del contacto y teléfono de contacto válidos, el sistema deberá registrar la nueva sede en estado activo (`is_active = true`), habilitando inmediatamente el acceso al portal de sede con dicho código.
* **EARS 1.2 (Validación de Código Único de Sede):** Si el Coordinador intenta registrar una sede con un `site_code` que ya existe en el sistema (incluso si está dada de baja lógica), entonces el sistema deberá rechazar la operación informando del conflicto y exigiendo un código identificador único.
* **EARS 1.3 (Inmutabilidad del Identificador de Sede):** Cuando el Coordinador edite una sede existente, el sistema no deberá permitir la modificación de su `site_code` original, permitiendo exclusivamente la actualización del nombre, dirección física, persona de contacto y teléfono de aviso.
* **EARS 1.4 (Bloqueo Estricto en Baja de Sede con Máquinas Activas):** Si el Coordinador solicita dar de baja (desactivar) una sede que contiene una o más máquinas activas asociadas, entonces el sistema deberá bloquear la baja, impidiendo la operación y mostrando la lista de máquinas que deben ser dadas de baja o trasladadas previamente.
* **EARS 1.5 (Baja Lógica de Sede Sin Máquinas Activas):** Cuando el Coordinador confirme la baja de una sede que no tiene máquinas activas asociadas, el sistema deberá aplicar un borrado lógico (*Soft Delete*), marcando la sede como inactiva (`is_active = false`, `deleted_at = timestamp`), impidiendo nuevos accesos al portal de dicha sede pero preservando todo su historial.
* **EARS 1.6 (Reactivación de Sede Inactiva):** Cuando el Coordinador solicite reactivar una sede dada de baja lógica previa, el sistema deberá restaurar su estado activo (`is_active = true`, `deleted_at = null`), reanudando la posibilidad de asignarle máquinas y acceder a su portal.
* **EARS 1.7 (Filtro y Búsqueda de Sedes):** Mientras el Coordinador consulte el catálogo de sedes, el sistema deberá permitir alternar entre los filtros "Activas", "Inactivas" y "Todas", y buscar en tiempo real por código, nombre, dirección o persona de contacto.

---

### RF-02: Gestión Integral del Parque de Máquinas (CRUD Máquinas)

* **EARS 2.1 (Alta de Máquina):** Cuando el Coordinador registre una nueva máquina indicando código único de máquina (ej. `VEND-0103`), sede vinculada (que debe estar en estado activo), modelo técnico, tipología (`HOT_DRINKS`, `COLD_DRINKS`, `SNACKS`, `PERISHABLE_FOOD`, `COMBO`), planta/ala de ubicación y notas opcionales, el sistema deberá dar de alta la máquina en estado activo y operativo.
* **EARS 2.2 (Validación de Código Único de Máquina):** Si el Coordinador introduce un código de máquina que ya existe en el sistema (activo o inactivo), entonces el sistema deberá impedir el guardado indicando que el código ya se encuentra en uso.
* **EARS 2.3 (Alerta Sanitaria en Tipología Perecedera):** Cuando el Coordinador seleccione la tipología `PERISHABLE_FOOD` (alimentos perecederos) durante el alta o edición de una máquina, el sistema deberá mostrar un distintivo informativo destacado recordando la obligatoriedad constitucional del SLA $\le$ 4 horas (Art. II) para averías de refrigeración en dicha unidad.
* **EARS 2.4 (Edición de Atributos Físicos de Máquina):** Cuando el Coordinador edite una máquina existente, el sistema deberá permitir actualizar el modelo técnico, la tipología de máquina, la planta/ala de instalación y las notas descriptivas, manteniendo invariable el código de máquina.
* **EARS 2.5 (Traslado de Máquina entre Sedes - Máquina Operativa):** Cuando el Coordinador modifique la sede de asignación de una máquina que se encuentra operativa y sin averías activas abiertas, el sistema deberá actualizar la sede actual de la máquina y registrar el traslado físico.
* **EARS 2.6 (Bloqueo de Traslado de Máquina con Averías Abiertas):** Si el Coordinador intenta trasladar una máquina de sede mientras ésta tiene una incidencia activa en curso o pendiente (`REGISTERED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `REOPENED`), entonces el sistema deberá bloquear terminantemente el traslado, informando que la avería en curso debe ser resuelta o cancelada antes de mover la máquina de sede.
* **EARS 2.7 (Integridad Histórica de Averías en Traslados):** Cuando una máquina haya sido trasladada de sede, el sistema deberá garantizar que todas las incidencias históricas resueltas o cerradas previamente conserven la referencia inmutable a la sede en la que ocurrieron originalmente, asegurando que las métricas y auditorías históricas no se distorsionen.
* **EARS 2.8 (Bloqueo Estricto de Baja de Máquina con Avería Activa):** Si el Coordinador solicita dar de baja (desactivar) una máquina que cuenta con una incidencia activa abierta, entonces el sistema deberá impedir la baja lógica, indicando el código del ticket activo que debe gestionarse previamente.
* **EARS 2.9 (Baja Lógica de Máquina Sin Averías Abiertas):** Cuando el Coordinador confirme la baja de una máquina operativa sin averías pendientes, el sistema deberá marcar la máquina como inactiva (*Soft Delete*, `is_active = false`, `deleted_at = timestamp`), impidiendo que los usuarios o ciudadanos puedan reportar nuevas averías sobre ella.
* **EARS 2.10 (Reactivación de Máquina Inactiva):** Cuando el Coordinador solicite reactivar una máquina dada de baja previa, el sistema deberá comprobar que la sede a la que pertenece se encuentra activa; si la sede está activa, el sistema restaurará la máquina al servicio (`is_active = true`, `deleted_at = null`). Si la sede asociada estuviera inactiva, el sistema deberá exigir seleccionar una sede activa receptora para completar la reactivación.
* **EARS 2.11 (Acceso Integrado a Código QR y Estado Operativo):** Mientras el Coordinador visualice el catálogo de máquinas, el sistema deberá mostrar el estado operativo actual (Operativa, Avería Activa o En Garantía), el distintivo sanitario si es de alimentos perecederos, y botones directos para previsualizar/imprimir su etiqueta QR o gestionar su ficha.

---

### RF-03: Gestión Integral de Técnicos de Campo (CRUD Técnicos)

* **EARS 3.1 (Alta de Técnico de Ruta):** Cuando el Coordinador registre un nuevo técnico indicando nombre completo, correo electrónico corporativo único, teléfono de contacto y contraseña de acceso, el sistema deberá dar de alta al usuario con el rol inmutable de Técnico de Campo (`TECHNICIAN`), cifrando la contraseña mediante algoritmo de derivación seguro (*hash* unidireccional) y activando su cuenta.
* **EARS 3.2 (Validación de Correo Corporativo Único):** Si el Coordinador intenta registrar a un técnico con una dirección de correo electrónico que ya pertenece a otro usuario registrado, entonces el sistema deberá rechazar la creación indicando la colisión del correo.
* **EARS 3.3 (Edición de Datos de Técnico):** Cuando el Coordinador modifique la ficha de un técnico existente, el sistema deberá permitir actualizar su nombre, su teléfono de contacto y su correo electrónico (comprobando que no colisione con otro usuario).
* **EARS 3.4 (Reseteo Administrativo de Contraseña):** Cuando el Coordinador solicite restablecer la contraseña de un técnico, el sistema deberá exigir la introducción de la nueva contraseña con longitud mínima de 8 caracteres, actualizando su credencial de forma segura e invalidando sesiones previas del técnico.
* **EARS 3.5 (Bloqueo Estricto de Baja de Técnico con Averías Asignadas):** Si el Coordinador intenta dar de baja (desactivar) a un técnico que cuenta con una o más averías asignadas pendientes en su ruta (`ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS`), entonces el sistema deberá bloquear la desactivación, mostrando el listado de tickets activos y advirtiendo que el Coordinador debe reasignarlos a otro técnico antes de tramitar la baja.
* **EARS 3.6 (Baja Lógica de Técnico Sin Averías Pendientes):** Cuando el Coordinador confirme la baja de un técnico que no tiene averías activas asignadas, el sistema deberá desactivar la cuenta del técnico (*Soft Delete*, `is_active = false`, `deleted_at = timestamp`), impidiéndole iniciar sesión en "Mi Ruta" y excluyéndolo de la lista de técnicos seleccionables para nuevas asignaciones de triaje.
* **EARS 3.7 (Reactivación de Técnico Inactivo):** Cuando el Coordinador solicite reactivar a un técnico dado de baja previa, el sistema deberá restaurar su cuenta activa (`is_active = true`, `deleted_at = null`), permitiéndole volver a autenticarse y recibir asignaciones de trabajo.
* **EARS 3.8 (Filtro y Búsqueda de Personal Técnico):** Mientras el Coordinador consulte el catálogo de técnicos, el sistema deberá permitir alternar entre los filtros "Activos", "Inactivos" y "Todos", mostrando el total de averías históricas resueltas por cada uno y un buscador por nombre o correo electrónico.

---

### RF-04: Auditoría Inmutable de Cambios Administrativos (*Audit Log*)

* **EARS 4.1 (Registro de Creación de Entidades Maestras):** Cuando se dé de alta una nueva Sede, Máquina o Técnico, el sistema deberá generar inmediatamente un evento inmutable en el registro de auditoría (`audit_log`), indicando el tipo de entidad, su identificador, la acción realizada (`LOCATION_CREATED`, `MACHINE_CREATED`, `TECHNICIAN_CREATED`), el usuario coordinador actuante y el payload completo del estado creado.
* **EARS 4.2 (Registro de Modificaciones Maestras y Diferenciales):** Cuando se actualicen los datos de una Sede, Máquina o Técnico, el sistema deberá registrar un evento en `audit_log` (`LOCATION_UPDATED`, `MACHINE_UPDATED`, `TECHNICIAN_UPDATED`), capturando el estado previo y el nuevo estado para permitir la inspección diferencial de campos modificados.
* **EARS 4.3 (Registro de Traslado Físico de Máquinas):** Cuando una máquina sea trasladada de sede o de planta/ala, el sistema deberá registrar un evento específico `MACHINE_TRANSFERRED` en `audit_log`, detallando la sede de origen, la sede de destino y la nueva ubicación física.
* **EARS 4.4 (Registro de Bajas y Reactivaciones):** Cuando se dé de baja o se reactive una Sede, Máquina o Técnico, el sistema deberá registrar el evento correspondiente (`DEACTIVATED` / `REACTIVATED`) en `audit_log`, asegurando la trazabilidad de los periodos de inactividad de las entidades.
* **EARS 4.5 (Registro de Reseteo de Contraseñas sin Exposición de Credenciales):** Cuando el Coordinador restablezca la contraseña de un técnico, el sistema deberá registrar el evento `TECHNICIAN_PASSWORD_RESET` en `audit_log`, indicando el coordinador emisor pero omitiendo estrictamente cualquier representación de la contraseña en texto claro ni su hash.

---

### RF-05: Ergonomía e Integración Visual en el Panel de Coordinación

* **EARS 5.1 (Navegación Unificada por Subpestañas):** Mientras el Coordinador acceda a la sección de administración del parque, el sistema deberá presentar una navegación fluida estructurada en tres subpestañas operativas:
  - **🏢 Sedes:** Catálogo de centros clientes con conteo de máquinas, estado de servicio y acciones de alta, edición y baja.
  - **🎰 Parque de Máquinas:** Catálogo general filtrable con selector de sede, estado operativo, indicadores sanitarios, acciones de alta, edición, traslado, baja y generación de etiquetas QR.
  - **🧑‍🔧 Personal Técnico:** Directorio de técnicos de campo con datos de contacto, averías asignadas, reseteo de claves y control de estado.
* **EARS 5.2 (Formularios Modales con Validación Previa):** Cuando el Coordinador pulse las acciones de "Nueva Sede", "Nueva Máquina" o "Nuevo Técnico" (o sus respectivas ediciones), el sistema deberá presentar formularios en ventanas modales con validación inmediata de campos obligatorios, formatos de correo y longitudes mínimas antes del envío.
* **EARS 5.3 (Diálogos de Confirmación con Explicación de Bloqueos):** Si una acción de baja lógica está permitida, el sistema deberá solicitar confirmación explícita previa; si la acción de baja se encuentra bloqueada por incidencias o dependencias activas (según EARS 1.4, 2.8 y 3.5), el sistema deberá presentar un modal explicativo con el motivo exacto del bloqueo y las acciones correctivas requeridas sin permitir forzar el borrado.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Seguridad y Control de Acceso RBAC - Art. V.4):** Todas las operaciones de creación, edición, baja, traslado y reactivación estarán estrictamente restringidas al rol de `COORDINATOR` autenticado. Cualquier intento de acceso anónimo o por parte de roles técnicos o responsables de sede será rechazado con código HTTP 401 Unauthorized o 403 Forbidden.
* **RNF-02 (Rendimiento en Respuestas Administrativas):** Las operaciones de consulta, guardado o validación de dependencias del catálogo administrativo deberán completarse en un tiempo no superior a **500 milisegundos** bajo condiciones normales de red.
* **RNF-03 (Inviolabilidad de la Base de Datos - Art. III.1):** Ninguna operación del panel CRUD ejecutará sentencias destructivas (`DELETE FROM`). La persistencia garantizará que los datos históricos, relaciones de tickets cerrados y eventos de auditoría permanezcan intactos.
* **RNF-04 (Protección de Credenciales de Técnicos):** Las contraseñas de los técnicos nunca se almacenarán ni transmitirán en texto plano. En el almacenamiento se utilizará un algoritmo de *hashing* seguro (Bcrypt con factor de coste $\ge 10$), y en los eventos de auditoría las contraseñas quedarán permanentemente redactadas/ocultas.
* **RNF-05 (Consistencia de Diseño con Docker Tokens):** La interfaz visual de las tres subpestañas, modales, tablas y botones seguirá rigurosamente los tokens de diseño de Docker establecidos en el proyecto (`--color-primary: #2560ff`, `--radius-interactive: 4px`, `--radius-card: 8px`, tipografía `DM Sans` e `Inter`).

---

## 6. Casos Límite y Comportamiento ante Errores

1. **Intento de dar de baja una Sede con máquinas inactivas vs activas:** Si una sede tiene máquinas históricas pero todas están dadas de baja lógica (`is_active = false`), la sede **sí** puede darse de baja, ya que no tiene máquinas activas en explotación.
2. **Máquina con avería en estado `PENDING_PARTS` (Pendiente de Repuesto):** Se considera formalmente avería activa; por tanto, el sistema bloquea tanto la baja lógica de la máquina como su traslado de sede y la desactivación del técnico asignado a ella.
3. **Reactivación de máquina cuyo código colisiona con otra máquina creada posteriormente:** Si durante el tiempo de inactividad se hubiese intentado reutilizar el código (lo cual está impedido por el índice único), la reactivación se ejecuta sobre el identificador inmutable original sin riesgo de colisión.
4. **Traslado de máquina de alimentos perecederos (`PERISHABLE_FOOD`) a otra sede:** El traslado actualiza la sede de la máquina manteniendo la tipología perecedera y todas las alertas de SLA asociadas a dicha máquina en su nuevo destino.
5. **Reactivación de una Sede cuya empresa fue dada de baja:** Al reactivar la sede, sus máquinas asociadas que hubieran sido dadas de baja lógica **no** se reactivan automáticamente en masa; deben ser reactivadas de forma individual y deliberada por el Coordinador tras inspección física.
6. **Técnico que pierde su contraseña mientras tiene una avería en curso:** El Coordinador puede resetear su contraseña desde el panel de técnicos sin necesidad de desasignar la avería ni alterar el estado de la incidencia.
7. **Peticiones concurrentes de baja y asignación:** Si un coordinador intenta dar de baja a un técnico en el mismo instante en que otro coordinador le asigna un ticket, el sistema evalúa atómicamente la condición antes de persistir la baja, rechazándola con error de bloqueo si existen tickets asignados.

---

## 7. Fuera de Alcance (Out of Scope - Anti-Feature Creep, Art. VI)

* **Gestión de roles o permisos personalizados:** No se permite crear nuevos roles ni modificar los privilegios fijados por la Constitución (`COORDINATOR`, `TECHNICIAN`, `LOCATION_MANAGER`).
* **Gestión de contraseñas para Sedes:** Los responsables de ubicación continúan accediendo mediante su código de sede (`site_code`), sin contraseñas individuales ni portales multi-usuario por sede.
* **Importación/Exportación masiva de parques vía Excel/CSV:** El alta masiva mediante hojas de cálculo o procesos ETL por lotes queda excluida de esta fase y reservada a fases posteriores.
* **Geolocalización GPS automática o cálculo de rutas por mapa:** No se implementará cartografía GPS ni cálculo de tráfico para los traslados de máquinas o rutas de técnicos.
* **Inventario de piezas o stock de almacén por técnico:** El control logístico de piezas y stock de furgoneta se mantiene explícitamente fuera de alcance según el Artículo VI de la Constitución.

---

## 8. Criterios de Finalización (Definition of Done)

El Módulo de Administración Integral (CRUD) se considerará formalmente completado cuando:
1. **Especificación y Contratos aprobados:** Existan los contratos técnicos de API en `specs/` derivados de esta especificación funcional.
2. **Operaciones CRUD de Sedes operativas:** El Coordinador pueda crear, listar, editar, filtrar, dar de baja lógica (con bloqueo si tiene máquinas activas) y reactivar sedes.
3. **Operaciones CRUD de Máquinas operativas:** El Coordinador pueda crear máquinas con tipología sanitaria, editarlas, trasladarlas entre sedes (con bloqueo si tienen averías abiertas), darlas de baja lógica y reactivarlas.
4. **Operaciones CRUD de Técnicos operativas:** El Coordinador pueda dar de alta técnicos con contraseña, editar sus perfiles, resetear sus claves, darlos de baja lógica (con bloqueo si tienen averías asignadas) y reactivarlos.
5. **Auditoría inmutable al 100%:** Todas las operaciones de altas, modificaciones, traslados, bajas y reactivaciones generen su correspondiente evento en `audit_log` comprobable en el visor de auditoría.
6. **Batería de Pruebas en Verde:** Todas las pruebas unitarias, de integración y de frontend automatizadas pasen al 100% sin regresiones en las 64 suites preexistentes.
7. **Certificación Constitucional:** Cero sentencias destructivas `DELETE FROM` en el código de producción (`src/`) y tipado estricto en todos los ficheros nuevos.

---

## 9. Dudas Abiertas
*No existen dudas abiertas pendientes. Todas las ambigüedades respecto a reglas de bloqueo, baja lógica, traslados, contraseñas, reactivación y auditoría fueron resueltas y alineadas unánimemente con el Product Owner.*
