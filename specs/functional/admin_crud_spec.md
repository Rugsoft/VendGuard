# ESPECIFICACIÓN FUNCIONAL · PANEL CRUD DE ADMINISTRACIÓN INTEGRAL (SEDES, MÁQUINAS Y PERSONAL INTERNO)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `04-admin-crud`  
**Documento:** `specs/functional/admin_crud_spec.md`  
**Estado:** Especificación Formal Consolidada y Auditada por QA (Aprobada para Diseño Técnico)  
**Metodología:** SDD (Specification-Driven Development) · Notación EARS  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.3, 5.4) y VI de la Constitución de VendGuard  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
VendGuard gestiona operativamente el ciclo de vida de incidencias en máquinas de vending, la trazabilidad sanitaria de la cadena de frío (Art. II), la generación física de códigos QR y el análisis de métricas de servicio (MTTR) con auditoría inmutable.

Sin embargo, el aprovisionamiento y mantenimiento del catálogo maestro (Sedes de clientes, Parque de Máquinas y Personal Interno: Técnicos y Coordinadores) se encontraba acotado a cargas estáticas o semillas preconfiguradas. En la operativa industrial real:
1. **Las sedes evolucionan:** Se incorporan nuevos clientes o edificios, se actualizan direcciones físicas, personas de contacto y teléfonos de aviso para la entrega de producto y acceso a instalaciones.
2. **El parque de máquinas es dinámico:** Se adquieren nuevos modelos, se reubican máquinas dentro de un edificio (cambio de planta o ala), se trasladan máquinas entre sedes o se adaptan para dispensar alimentos frescos perecederos con requisitos de frío.
3. **El personal rota:** Se incorporan nuevos técnicos de ruta y coordinadores, se actualizan teléfonos de guardia, se resetean contraseñas olvidadas y se tramitan bajas laborales sin comprometer las averías actualmente en curso ni perder el histórico inmutable de intervenciones.

Para preservar la integridad constitucional de VendGuard, toda gestión administrativa de estas entidades maestras se somete a preceptos innegociables:
- **Prohibición absoluta del borrado físico (*Hard Delete*, Art. III.1):** Ninguna sede, máquina o usuario puede ser eliminado con sentencias destructivas; toda baja es lógica (*Soft Delete* mediante `is_active = false` y `deleted_at`).
- **Control estricto de dependencias e integridad referencial:** Una sede no puede desactivarse si mantiene máquinas activas, una máquina no puede desactivarse ni trasladarse si tiene averías abiertas o en periodo de garantía de 48 horas (`RESOLVED`), y un técnico no puede darse de baja si tiene órdenes de trabajo activas asignadas a su ruta.
- **Blindaje Sanitario e Histórico (Art. II):** El cambio de tipología de una máquina nunca podrá degradar retrospectivamente la criticidad ni el cálculo de MTTR de incidencias pasadas.

### 1.2 Objetivo
Definir los requisitos funcionales y de negocio para un **Panel de Administración Integral (CRUD)** integrado en el entorno de Coordinación, que permita:
1. **Administrar Sedes de Clientes:** Altas con código normalizado, modificaciones de contacto/dirección, bajas lógicas con bloqueo preventivo y reactivación contextual.
2. **Administrar el Parque de Máquinas:** Altas con tipología sanitaria, traslados entre sedes y plantas con preservación estricta de la sede histórica de averías pasadas, bajas lógicas condicionadas y reactivación asistida.
3. **Administrar Personal Interno (Técnicos y Coordinadores):** Altas con credenciales seguras, actualización de datos de contacto, reseteo administrativo de contraseñas, reasignación obligatoria de carga activa previa a la baja y protección contra auto-desactivación.
4. **Garantizar la Auditoría Permanente (*Audit Log*):** Registrar cronológicamente en el log inmutable cada alta, cambio maestro, traslado, reseteo, baja o reactivación con su estado anterior y nuevo.

---

## 2. Usuarios y Roles

* **Coordinador del Servicio:** Único usuario autorizado para acceder a este panel de administración integral. Responsable de la creación, supervisión, modificación de datos maestros, traslados, bajas lógicas, reseteos de credenciales y reactivación de sedes, máquinas y personal interno.
* **Técnico de Ruta:** Usuario interno del sistema. No tiene acceso de edición sobre el catálogo de sedes ni máquinas, ni puede administrar a otros técnicos ni coordinadores. Es el destinatario de las credenciales de acceso a "Mi Ruta" y de las asignaciones de averías.
* **Responsable de Sede (Cliente):** Usuario externo. Accede a su portal mediante el código único de sede (`site_code`) gestionado en este panel. No puede modificar datos del parque ni crear máquinas.
* **Usuario Final (Consumidor):** Usuario ciudadano que escanea códigos QR físicos en las máquinas. Si escanea una máquina dada de baja lógica, el sistema le muestra un estado informativo bloqueando el reporte.

---

## 3. Historias de Usuario

* **HU-ADM-01 (Alta y Mantenimiento de Sedes):** *Como* Coordinador, *quiero* dar de alta nuevas sedes con código normalizado y actualizar sus datos de contacto y dirección *para* mantener al día la información operativa de los clientes y permitir que los responsables de sede accedan con sus códigos.
* **HU-ADM-02 (Baja y Reactivación Controlada de Sedes):** *Como* Coordinador, *quiero* desactivar sedes que hayan finalizado contrato asegurando que no queden máquinas activas, y poder reactivarlas si renuevan *para* evitar errores operativos sin perder el historial.
* **HU-ADM-03 (Alta y Catalogación Sanitaria de Máquinas):** *Como* Coordinador, *quiero* registrar nuevas máquinas vinculándolas a una sede activa y clasificándolas según su tipología (especialmente alimentos perecederos) *para* integrarlas en la operativa y generar sus etiquetas QR.
* **HU-ADM-04 (Traslado y Reubicación de Máquinas):** *Como* Coordinador, *quiero* trasladar una máquina de una sede a otra o cambiarla de planta/ala cuando no tenga averías activas ni en garantía *para* reflejar su ubicación física real sin distorsionar el historial de tickets pasados.
* **HU-ADM-05 (Baja y Reactivación de Máquinas con Salvaguarda):** *Como* Coordinador, *quiero* dar de baja máquinas obsoletas de forma lógica impidiendo la baja si hay avisos abiertos o en garantía de 48h *para* proteger la integridad de las rutas técnicas y reactivarlas si vuelven de taller.
* **HU-ADM-06 (Gestión Integral de Personal Interno y Credenciales):** *Como* Coordinador, *quiero* crear nuevos técnicos de campo o coordinadores, actualizar sus teléfonos y resetear sus contraseñas en caso de bloqueo *para* asegurar la continuidad del servicio.
* **HU-ADM-07 (Baja Segura de Técnicos sin Averías Huérfanas):** *Como* Coordinador, *quiero* que el sistema me impida desactivar a un técnico si tiene averías pendientes asignadas, pudiendo reasignar sus averías activas desde el panel de triaje *para* evitar que queden averías desatendidas.
* **HU-ADM-08 (Auditoría Inmutable de Cambios Maestros):** *Como* Coordinador, *quiero* que cualquier cambio en sedes, máquinas o personal quede reflejado en el registro de auditoría *para* garantizar el cumplimiento del Artículo III constitucional.

---

## 4. Requisitos Funcionales y Criterios de Aceptación (Notación EARS)

### RF-01: Gestión Integral de Sedes de Clientes (CRUD Sedes)

* **EARS 1.1 (Alta de Sede con Formato Normalizado):** Cuando el Coordinador envíe el formulario de alta de sede con código único (`site_code`), nombre de la sede, dirección postal, nombre del contacto y teléfono de contacto válidos, el sistema deberá registrar la nueva sede en estado activo (`is_active = true`), habilitando inmediatamente el acceso al portal de sede con dicho código.
* **EARS 1.2 (Validación de Formato e Inmutabilidad de `site_code`):** El sistema siempre deberá validar que el `site_code` cumpla con la expresión regular `^[A-Z0-9-]{3,32}$` (mayúsculas, números y guiones, entre 3 y 32 caracteres). Una vez creada la sede, el sistema no deberá permitir la modificación de su `site_code` en ninguna circunstancia.
* **EARS 1.3 (Detección de Colisión con Sede Inactiva y Reactivación Contextual):** Si el Coordinador intenta registrar una sede con un `site_code` que ya existe en estado inactivo (*Soft-Deleted*), entonces el sistema deberá advertir que el código pertenece a una sede dada de baja previa y ofrecer un botón directo de confirmación para reactivar la sede existente restaurando su ficha.
* **EARS 1.4 (Edición de Atributos de Sede):** Cuando el Coordinador edite una sede existente, el sistema deberá permitir la actualización del nombre, dirección física, persona de contacto y teléfono de aviso, validando que ninguno de los campos obligatorios quede en blanco.
* **EARS 1.5 (Bloqueo Estricto en Baja de Sede con Máquinas Activas):** Si el Coordinador solicita dar de baja (desactivar) una sede que contiene una o más máquinas activas asociadas (`machines.deleted_at IS NULL`), entonces el sistema deberá bloquear la baja, impidiendo la operación y mostrando la lista de máquinas activas que deben ser dadas de baja o trasladadas previamente.
* **EARS 1.6 (Baja Lógica de Sede Sin Máquinas Activas):** Cuando el Coordinador confirme la baja de una sede que no tiene máquinas activas asociadas, el sistema deberá aplicar un borrado lógico (*Soft Delete*, `is_active = false`, `deleted_at = timestamp`), impidiendo nuevos accesos al portal de dicha sede pero preservando intacto todo su historial de máquinas pasadas y averías.
* **EARS 1.7 (Reactivación de Sede Inactiva):** Cuando el Coordinador solicite reactivar una sede dada de baja previa, el sistema deberá restaurar su estado activo (`is_active = true`, `deleted_at = null`), reanudando la posibilidad de asignarle máquinas y acceder a su portal.
* **EARS 1.8 (Filtro y Búsqueda de Sedes):** Mientras el Coordinador consulte el catálogo de sedes, el sistema deberá permitir alternar entre los filtros "Activas", "Inactivas" y "Todas", y buscar en tiempo real por código, nombre, dirección o persona de contacto.

---

### RF-02: Gestión Integral del Parque de Máquinas (CRUD Máquinas)

* **EARS 2.1 (Alta de Máquina con Formato Normalizado):** Cuando el Coordinador registre una nueva máquina indicando código único de máquina (validado por `^[A-Z0-9-]{3,32}$`), sede vinculada (que debe estar en estado activo), modelo técnico, tipología (`HOT_DRINKS`, `COLD_DRINKS`, `SNACKS`, `PERISHABLE_FOOD`, `COMBO`), planta/ala de ubicación y notas opcionales, el sistema deberá dar de alta la máquina en estado activo y operativo.
* **EARS 2.2 (Detección de Colisión con Máquina Inactiva y Reactivación Contextual):** Si el Coordinador introduce un código de máquina que ya existe en estado inactivo (*Soft-Deleted*), entonces el sistema deberá informar de que la máquina existe dada de baja previa y ofrecer un enlace para reactivar la máquina existente.
* **EARS 2.3 (Alerta Sanitaria en Tipología Perecedera - Art. II):** Cuando el Coordinador seleccione la tipología `PERISHABLE_FOOD` (alimentos perecederos) durante el alta o edición de una máquina, el sistema deberá mostrar un distintivo informativo destacado recordando la obligatoriedad constitucional del SLA $\le$ 4.0 horas (Art. II) para averías de refrigeración en dicha unidad.
* **EARS 2.4 (Edición de Atributos Físicos e Inmutabilidad de Código):** Cuando el Coordinador edite una máquina existente, el sistema deberá permitir actualizar el modelo técnico, la planta/ala de instalación y las notas descriptivas, manteniendo invariable el código de máquina.
* **EARS 2.5 (Bloqueo de Cambio de Tipología con Averías Abiertas o en Garantía):** Si el Coordinador intenta modificar la tipología de una máquina (`machine_type`) mientras ésta tiene una incidencia en cualquier estado activo o en garantía de 48h (`REGISTERED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `REOPENED`, `RESOLVED`), entonces el sistema deberá bloquear terminantemente el cambio de tipología hasta que todos los tickets de la máquina alcancen el estado `CLOSED` o `CANCELLED`.
* **EARS 2.6 (Preservación Histórica de Tipología en Incidencias Pasadas - Art. II):** El sistema siempre deberá registrar la tipología de máquina de forma inmutable en cada incidencia creada (`incidents.machine_type_snapshot`), de modo que un cambio de tipología en la máquina nunca recalcule ni altere retroactivamente el cálculo del MTTR ni las auditorías de SLA de averías pasadas.
* **EARS 2.7 (Traslado de Máquina entre Sedes - Máquina Operativa):** Cuando el Coordinador modifique la sede de asignación de una máquina que se encuentra operativa y sin averías activas ni en garantía de 48h (cero tickets en `REGISTERED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `REOPENED`, `RESOLVED`), el sistema deberá actualizar la sede actual y planta/ala de la máquina, emitiendo un evento `MACHINE_TRANSFERRED` en `audit_log`.
* **EARS 2.8 (Bloqueo Estricto de Traslado con Averías Abiertas o en Garantía):** Si el Coordinador intenta trasladar una máquina de sede mientras ésta tiene una incidencia en estado activo o en garantía de 48h (`REGISTERED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `REOPENED`, `RESOLVED`), entonces el sistema deberá bloquear terminantemente el traslado, informando que la avería o el periodo de garantía de 48h deben haber concluido con el cierre definitivo (`CLOSED`) antes de mover físicamente la máquina.
* **EARS 2.9 (Preservación Inmutable de Sede Histórica en Averías Anteriores):** Cuando una máquina haya sido trasladada de sede, el sistema deberá garantizar que todas las incidencias históricas resueltas o cerradas previamente conserven la referencia inmutable a la sede en la que ocurrieron originalmente, asegurando que las métricas y reportes de auditoría de cada sede no se distorsionen.
* **EARS 2.10 (Bloqueo Estricto de Baja de Máquina con Averías Abiertas o en Garantía):** Si el Coordinador solicita dar de baja (desactivar) una máquina que cuenta con una incidencia en estado activo o en garantía (`REGISTERED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `REOPENED`, `RESOLVED`), entonces el sistema deberá impedir la baja lógica, indicando el código del ticket activo o en garantía que impide la baja.
* **EARS 2.11 (Baja Lógica de Máquina Sin Averías Pendientes):** Cuando el Coordinador confirme la baja de una máquina operativa sin averías pendientes ni en garantía, el sistema deberá marcar la máquina como inactiva (*Soft Delete*, `is_active = false`, `deleted_at = timestamp`).
* **EARS 2.12 (Respuesta Ciudadana ante Escaneo QR de Máquina Inactiva):** Si un ciudadano o usuario escanea el código QR físico de una máquina que ha sido dada de baja lógica, el sistema deberá presentar una pantalla informativa de advertencia: *"Máquina fuera de servicio / retirada del parque"*, bloqueando terminantemente el formulario de reporte de avería.
* **EARS 2.13 (Reactivación Asistida de Máquina con Sede Inactiva):** Cuando el Coordinador solicite reactivar una máquina dada de baja previa cuya sede asociada se encuentre actualmente inactiva, el sistema deberá exigir en el mismo diálogo modal la selección obligatoria de una sede activa receptora y una nueva planta/ala, registrando conjuntamente la reactivación y el traslado en `audit_log`.
* **EARS 2.14 (Acceso Integrado a Código QR y Estado Operativo):** Mientras el Coordinador visualice el catálogo de máquinas, el sistema deberá mostrar el estado operativo actual (Operativa, Avería Activa o En Garantía), el distintivo sanitario si es de alimentos perecederos, y botones directos para previsualizar/imprimir su etiqueta QR o gestionar su ficha.

---

### RF-03: Gestión Integral de Personal Interno (Técnicos y Coordinadores)

* **EARS 3.1 (Alta de Personal Interno con Rol Asignado):** Cuando el Coordinador registre un nuevo usuario indicando nombre completo, correo electrónico corporativo único, teléfono de contacto, rol (`TECHNICIAN` o `COORDINATOR`) y contraseña de acceso (mínimo 8 caracteres), el sistema deberá dar de alta al usuario en estado activo (`is_active = true`), cifrando la contraseña mediante algoritmo de derivación seguro (*hash* Bcrypt con factor de coste $\ge 10$).
* **EARS 3.2 (Detección de Colisión con Usuario Inactivo y Reactivación Contextual):** Si el Coordinador intenta registrar a un usuario con un correo electrónico que ya existe en estado inactivo (*Soft-Deleted*), entonces el sistema deberá advertir que el correo pertenece a un usuario dado de baja y ofrecer un enlace para reactivar la cuenta existente.
* **EARS 3.3 (Edición de Datos de Personal Interno):** Cuando el Coordinador modifique la ficha de un usuario existente, el sistema deberá permitir actualizar su nombre, su teléfono de contacto y su correo electrónico (validando que no colisione con otro usuario). El rol de un usuario existente se mantendrá inmutable para prevenir escaladas accidentales de privilegios.
* **EARS 3.4 (Reseteo Administrativo de Contraseña):** Cuando el Coordinador solicite restablecer la contraseña de un técnico o coordinador, el sistema deberá exigir la introducción de la nueva contraseña con longitud mínima de 8 caracteres, actualizando su credencial de forma segura e invalidando el token de sesión previo para forzar reautenticación en su siguiente petición.
* **EARS 3.5 (Bloqueo de Auto-Desactivación del Coordinador en Sesión):** Si el Coordinador en sesión intenta dar de baja (desactivar) su propia cuenta de usuario, el sistema deberá bloquear la operación con error 403 Forbidden, impidiendo que el coordinador se bloquee a sí mismo su acceso.
* **EARS 3.6 (Garantía de Servicio Mínimo en Bajas):** Si el Coordinador intenta dar de baja al único usuario activo que queda en el sistema con el rol `TECHNICIAN` o con el rol `COORDINATOR`, entonces el sistema deberá bloquear la baja, advirtiendo que debe existir al menos un técnico y un coordinador activos para garantizar la continuidad del servicio.
* **EARS 3.7 (Bloqueo Estricto de Baja de Técnico con Averías Asignadas):** Si el Coordinador intenta dar de baja a un técnico que cuenta con una o más averías asignadas pendientes en su ruta (`ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS`), entonces el sistema deberá bloquear la desactivación, mostrando el listado de tickets activos y advirtiendo que el Coordinador debe reasignarlos a otro técnico antes de tramitar la baja.
* **EARS 3.8 (Desbloqueo Operativo: Reasignación Forzosa de Averías en Curso):** Mientras una avería se encuentre en estado `IN_PROGRESS` o `PENDING_PARTS` asignada a un técnico que va a causar baja, el sistema deberá permitir al Coordinador desde el panel de triaje reasignar forzosamente dicha incidencia a otro técnico activo; al reasignarla, el ticket cambiará su estado a `ASSIGNED` a nombre del nuevo técnico responsable, registrando la nota justificativa en `audit_log` y liberando la carga del técnico saliente.
* **EARS 3.9 (Baja Lógica de Personal Interno):** Cuando el Coordinador confirme la baja de un técnico o coordinador que cumple con todas las condiciones de desbloqueo, el sistema deberá desactivar la cuenta (*Soft Delete*, `is_active = false`, `deleted_at = timestamp`), impidiéndole autenticarse en el sistema y excluyéndolo de la lista de técnicos seleccionables para nuevas asignaciones de triaje.
* **EARS 3.10 (Reactivación de Personal Interno Inactivo):** Cuando el Coordinador solicite reactivar a un técnico o coordinador dado de baja previa, el sistema deberá restaurar su cuenta activa (`is_active = true`, `deleted_at = null`), permitiéndole volver a autenticarse y recibir asignaciones de trabajo.
* **EARS 3.11 (Filtro y Búsqueda de Personal Interno):** Mientras el Coordinador consulte el catálogo de personal, el sistema deberá permitir alternar entre los filtros "Activos", "Inactivos" y "Todos", filtrar por rol (`TECHNICIAN` / `COORDINATOR`), y buscar en tiempo real por nombre o correo electrónico.

---

### RF-04: Auditoría Inmutable de Cambios Administrativos (*Audit Log*)

* **EARS 4.1 (Registro de Creación de Entidades Maestras):** Cuando se dé de alta una nueva Sede, Máquina o Usuario interno, el sistema deberá generar inmediatamente un evento inmutable en el registro de auditoría (`audit_log`), indicando el tipo de entidad (`LOCATION`, `MACHINE`, `USER`), su identificador, la acción realizada (`LOCATION_CREATED`, `MACHINE_CREATED`, `USER_CREATED`), el usuario coordinador actuante y el payload completo del estado creado.
* **EARS 4.2 (Registro de Modificaciones Maestras Diferenciales):** Cuando se actualicen los datos de una Sede, Máquina o Usuario, el sistema deberá registrar un evento en `audit_log` (`LOCATION_UPDATED`, `MACHINE_UPDATED`, `USER_UPDATED`), capturando el estado previo y el nuevo estado para permitir la inspección diferencial de campos modificados.
* **EARS 4.3 (Registro de Traslado Físico de Máquinas):** Cuando una máquina sea trasladada de sede o de planta/ala, el sistema deberá registrar un evento específico `MACHINE_TRANSFERRED` en `audit_log`, detallando la sede de origen, la sede de destino y la nueva ubicación física.
* **EARS 4.4 (Registro de Bajas y Reactivaciones):** Cuando se dé de baja o se reactive una Sede, Máquina o Usuario, el sistema deberá registrar el evento correspondiente (`DEACTIVATED` / `REACTIVATED`) en `audit_log`, asegurando la trazabilidad de los periodos de inactividad de las entidades.
* **EARS 4.5 (Registro de Reseteo de Contraseñas sin Exposición de Credenciales):** Cuando el Coordinador restablezca la contraseña de un usuario, el sistema deberá registrar el evento `USER_PASSWORD_RESET` en `audit_log`, indicando el coordinador emisor pero omitiendo estrictamente cualquier representación de la contraseña en texto claro ni su hash.

---

### RF-05: Ergonomía e Integración Visual en el Panel de Coordinación

* **EARS 5.1 (Navegación Unificada por Subpestañas):** Mientras el Coordinador acceda a la sección de administración del parque, el sistema deberá presentar una navegación fluida estructurada en tres subpestañas operativas:
  - **🏢 Sedes:** Catálogo de centros clientes con conteo de máquinas activas/totales, estado de servicio y acciones de alta, edición, baja lógica y reactivación.
  - **🎰 Parque de Máquinas:** Catálogo general filtrable con selector de sede, estado operativo, indicadores sanitarios, acciones de alta, edición, traslado entre sedes, baja lógica, reactivación y generación de etiquetas QR.
  - **🧑‍🔧 Personal Interno:** Directorio de técnicos y coordinadores con datos de contacto, averías asignadas, reseteo de claves, control de estado y reactivación.
* **EARS 5.2 (Formularios Modales con Validación Previa):** Cuando el Coordinador pulse las acciones de alta o edición, el sistema deberá presentar formularios en ventanas modales con validación inmediata de campos obligatorios, formatos de correo, expresiones regulares de códigos y longitudes mínimas antes del envío.
* **EARS 5.3 (Diálogos de Confirmación con Explicación de Bloqueos):** Si una acción de baja lógica está permitida, el sistema deberá solicitar confirmación explícita previa; si la acción de baja se encuentra bloqueada por incidencias o dependencias activas (según EARS 1.5, 2.8, 2.10, 3.5, 3.6 y 3.7), el sistema deberá presentar un modal explicativo con el motivo exacto del bloqueo y las acciones correctivas requeridas sin permitir forzar el borrado.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Seguridad y Control de Acceso RBAC - Art. V.4):** Todas las operaciones de creación, edición, baja, traslado y reactivación estarán estrictamente restringidas al rol de `COORDINATOR` autenticado. Cualquier intento de acceso anónimo o por parte de roles técnicos o responsables de sede será rechazado con código HTTP 401 Unauthorized o 403 Forbidden.
* **RNF-02 (Rendimiento en Respuestas Administrativas):** Las operaciones de consulta, guardado o validación de dependencias del catálogo administrativo deberán completarse en un tiempo no superior a **500 milisegundos** bajo condiciones normales de red.
* **RNF-03 (Inviolabilidad de la Base de Datos - Art. III.1):** Ninguna operación del panel CRUD ejecutará sentencias destructivas (`DELETE FROM`). La persistencia garantizará que los datos históricos, relaciones de tickets cerrados y eventos de auditoría permanezcan intactos.
* **RNF-04 (Protección de Credenciales de Usuarios):** Las contraseñas de los usuarios nunca se almacenarán ni transmitirán en texto plano. En el almacenamiento se utilizará un algoritmo de *hashing* seguro (Bcrypt con factor de coste $\ge 10$), y en los eventos de auditoría las contraseñas quedarán permanentemente redactadas/ocultas.
* **RNF-05 (Consistencia de Diseño con Docker Tokens):** La interfaz visual de las tres subpestañas, modales, tablas y botones seguirá rigurosamente los tokens de diseño de Docker establecidos en el proyecto (`--color-primary: #2560ff`, `--radius-interactive: 4px`, `--radius-card: 8px`, tipografía `DM Sans` e `Inter`).

---

## 6. Casos Límite y Comportamiento ante Errores

1. **Intento de dar de baja una Sede con máquinas inactivas vs activas:** Si una sede tiene máquinas históricas pero todas están dadas de baja lógica (`is_active = false`), la sede **sí** puede darse de baja, ya que no tiene máquinas activas en explotación.
2. **Máquina con avería en estado `PENDING_PARTS` o en garantía `RESOLVED`:** Ambos estados bloquean terminantemente tanto la baja lógica de la máquina como su traslado de sede, para preservar la integridad del servicio técnico y la ventana de garantía de 48h (Art. V.6).
3. **Reactivación de máquina cuyo código existe inactivo:** Si se solicita la reactivación, se reactiva el registro inmutable original restaurando su identificador sin riesgo de colisión.
4. **Traslado de máquina de alimentos perecederos (`PERISHABLE_FOOD`) a otra sede:** El traslado actualiza la sede de la máquina manteniendo la tipología perecedera y todas las alertas de SLA asociadas a dicha máquina en su nuevo destino.
5. **Reactivación de una Sede con máquinas previamente dadas de baja:** Al reactivar la sede, sus máquinas asociadas que hubieran sido dadas de baja lógica **no** se reactivan automáticamente en masa; deben ser reactivadas de forma individual y deliberada por el Coordinador tras inspección física.
6. **Técnico que pierde su contraseña mientras tiene una avería en curso:** El Coordinador puede resetear su contraseña desde el panel de técnicos sin alterar el estado de la incidencia asignada.
7. **Peticiones concurrentes de baja y asignación:** Si un coordinador intenta dar de baja a un técnico en el mismo instante en que se le asigna un ticket, el sistema evalúa atómicamente la condición antes de persistir la baja, rechazándola con error de bloqueo si existen tickets asignados.
8. **Intento de auto-desactivación del Coordinador activo:** El sistema devuelve error 403 Forbidden impidiendo que el usuario desactive su propio usuario en sesión.
9. **Intento de baja del último técnico o último coordinador:** El sistema bloquea la baja con mensaje explicativo de guardia mínima (debe existir al menos 1 técnico y 1 coordinador activos).
10. **Lectura de QR en máquina dada de baja:** El sistema muestra una vista pública informativa notificando que la máquina está retirada o fuera de servicio, bloqueando la creación de incidencias.

---

## 7. Fuera de Alcance (Out of Scope - Anti-Feature Creep, Art. VI)

* **Creación de roles personalizados:** No se permite crear nuevos roles ni modificar los privilegios fijados por la Constitución (`COORDINATOR`, `TECHNICIAN`, `LOCATION_MANAGER`).
* **Gestión de contraseñas para Sedes:** Los responsables de ubicación continúan accediendo mediante su código de sede (`site_code`), sin contraseñas individuales ni portales multi-usuario por sede.
* **Importación/Exportación masiva de parques vía Excel/CSV:** El alta masiva mediante hojas de cálculo o procesos ETL por lotes queda excluida de esta fase y reservada a fases posteriores.
* **Geolocalización GPS automática o cálculo de rutas por mapa:** No se implementará cartografía GPS ni cálculo de tráfico para los traslados de máquinas o rutas de técnicos.
* **Inventario de piezas o stock de almacén por técnico:** El control logístico de piezas y stock de furgoneta se mantiene explícitamente fuera de alcance según el Artículo VI de la Constitución.

---

## 8. Criterios de Finalización (Definition of Done)

El Módulo de Administración Integral (CRUD) se considerará formalmente completado cuando:
1. **Especificación y Contratos aprobados:** Existan los contratos técnicos de API en `specs/` derivados de esta especificación funcional consolidada.
2. **Operaciones CRUD de Sedes operativas:** El Coordinador pueda crear, listar, editar, filtrar, dar de baja lógica (con bloqueo si tiene máquinas activas) y reactivar sedes.
3. **Operaciones CRUD de Máquinas operativas:** El Coordinador pueda crear máquinas con tipología sanitaria, editarlas, trasladarlas entre sedes (con bloqueo si tienen averías activas o en garantía `RESOLVED`), darlas de baja lógica y reactivarlas.
4. **Operaciones CRUD de Personal Interno operativas:** El Coordinador pueda dar de alta técnicos y coordinadores con contraseña, editar sus perfiles, resetear sus claves, reasignar averías activas para desbloquear bajas, darlos de baja lógica (con bloqueo de auto-desactivación y guardia mínima) y reactivarlos.
5. **Respuesta Ciudadana QR para Máquinas Inactivas:** La lectura del código QR de una máquina dada de baja presente la pantalla informativa de máquina retirada bloqueando el reporte.
6. **Auditoría inmutable al 100%:** Todas las operaciones de altas, modificaciones, traslados, bajas y reactivaciones generen su correspondiente evento en `audit_log` comprobable en el visor de auditoría.
7. **Batería de Pruebas en Verde:** Todas las pruebas unitarias, de integración y de frontend automatizadas pasen al 100% sin regresiones en las 64 suites preexistentes.
8. **Certificación Constitucional:** Cero sentencias destructivas `DELETE FROM` en el código de producción (`src/`) y tipado estricto en todos los ficheros nuevos.

---

## 9. Dudas Abiertas
*No existen dudas abiertas pendientes. Todas las ambigüedades, contradicciones, casos límite y conflictos constitucionales han sido resueltos formalmente y blindados en esta especificación funcional.*
