# ESPECIFICACIÓN FUNCIONAL: MIGRACIÓN DE PERSISTENCIA A BASE DE DATOS EN LA NUBE (ENFOQUE HÍBRIDO)

**Documento:** `specs/12-hybrid-supabase-migration/spec.md`  
**Referencia de Módulo:** Módulo 12 (`12-hybrid-supabase-migration`)  
**Estado:** Especificación Formal Ratificada y Blindada tras Auditoría Técnica Adversarial (`docs/auditoria_plan_migracion_supabase_hibrida.md`)  
**Enmienda propuesta (2026-10-15, pendiente de aprobación):** el módulo [`13-single-engine-supabase`](../13-single-engine-supabase/spec.md) propone retirar el camino MariaDB/MySQL del sistema. Al aprobarse, los requisitos que presuponen **dos** motores (EARS-01.1, EARS-01.3, EARS-01.5, EARS-01.6, EARS-02.1, EARS-02.2, EARS-02.4 y los criterios 1, 2, 6 y 7 de §8) quedan sustituidos por el contrato del motor único, con la tabla de enmiendas de §4.1 de su especificación; RNF-04 se conserva en su sustancia (batería sin red, ahora contra el motor PostgreSQL local). Mientras la puerta de aprobación de ese módulo no se abra, **este documento rige sin cambios** (AGENTS.md §5.5).  
**Fecha:** Octubre 2026  
**Proyecto:** VendGuard · Gestor de Incidencias de Vending  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII, especialmente Art. I.3, Art. II, Art. III.1, Art. V.1, Art. V.2, Art. V.6 y Art. VI.1)  

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
VendGuard gestiona el ciclo de vida completo de incidencias correctivas, órdenes de mantenimiento preventivo, rutas logísticas de técnicos, trazabilidad de piezas sustituidas, reembolsos económicos por importe retenido e hilos de comentarios bidireccionales. Hasta ahora, el sistema ha dependido de una base de datos relacional ejecutada en entornos locales o contenedores tradicionales con motores MySQL/MariaDB o TiDB.

Para dotar al sistema de persistencia administrada en la nube con coste operativo cero (aprovechando el plan gratuito de Supabase PostgreSQL), se define la migración de la capa de datos bajo una **estrategia híbrida** (Backend PHP 8.2+ Clean Architecture + Frontend Vanilla Vue 3 ESM). La auditoría adversarial de arquitectura ([`docs/auditoria_plan_migracion_supabase_hibrida.md`](../../docs/auditoria_plan_migracion_supabase_hibrida.md)) constató que el plan gratuito no incluye PITR (*Point-In-Time-Recovery*) ni copias continuas automatizadas descargables, por lo que la resiliencia del sistema se asienta en la política de exportación periódica (*logical dump*) y en el desacoplamiento total de dependencias propietarias.

### 1.2. Objetivo
Definir los requisitos funcionales, operacionales y de blindaje normativo para **migrar la persistencia de datos a PostgreSQL administrado en la nube (Supabase)**, asegurando:
1. **Transparencia funcional absoluta para el usuario:** Cero alteraciones en las vistas de técnicos, sedes y ciudadanos, conservando intactos todos los flujos de trabajo de los 11 módulos previos.
2. **Compatibilidad dual transparente (Agnosticismo de persistencia):** El sistema debe operar indistintamente contra PostgreSQL en la nube (vía agrupador transaccional Supavisor en puerto 6543) o contra MariaDB/MySQL local (entornos de desarrollo y ejecución de pruebas offline sin conexión a internet).
3. **Arranque en limpio (*Fresh Start*) y preservación de fixtures:** Inicialización formal del catálogo maestro (sedes, máquinas, usuarios autorizados, catálogo de repuestos y rutas) en producción sin arrastre de expedientes antiguos, garantizando al mismo tiempo que el arnés de pruebas disponga de los fixtures necesarios para certificar la suite al 100% en verde.
4. **Prevención proactiva de suspensión (*Keep-Alive*) y restauración de servicio:** Un latido automático diario (*keep-alive*) evita que el servicio en la nube se suspenda por inactividad tras 7 días. Si el proyecto entrase en suspensión, el flujo incorpora un mecanismo de auto-restauración mediante la API de gestión de Supabase (`POST /v1/projects/{ref}/restore`), descartando la ficción de que una consulta de base de datos despierte un proyecto pausado.
5. **Transaccionalidad compensatoria de archivos:** Las evidencias fotográficas se mantienen en el almacenamiento del servidor de aplicaciones, implementando la reversión y purgado atómico de archivos huérfanos si la inserción en la base de datos remota se interrumpe por fallos de red.
6. **Blindaje constitucional integral y ruidoso en el motor de persistencia:** Protección anti-borrado físico (`DELETE FROM`) mediante disparadores `BEFORE DELETE` que emiten activamente excepción constitucional `SQLSTATE '45000'` (Art. III.1), con vía de escape de ámbito de sesión y **una forma por motor** —parámetro de sesión `vendguard.purge = on` en PostgreSQL, variable de usuario `@vendguard_purge` en MariaDB— declarada por un único ayudante consciente del driver en [`TestDataCleaner.php`](../../tests/Support/TestDataCleaner.php); detección preventiva obligatoria de duplicados complementada con índice atómico parcial que incluye `RESOLVED` durante la ventana de 48 horas de garantía (Art. V.2 y V.6); justificación obligatoria $\ge 20$ caracteres (Art. V.1); anonimización ciudadana (Art. V.4); y unicidad de logins y códigos preservada mediante **claves canónicas calculadas en la aplicación** (`email_key`, `site_code_key`) con índice único sobre la clave, de modo que ambos motores apliquen exactamente la misma regla; y autoridad canónica única del servicio de dominio PHP para la cuarentena biológica a las 4 horas (Art. II y Art. I.3).

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Impacto Visual de la Migración | Responsabilidades Operativas | Comportamiento ante Fallo de Enlace |
| :--- | :--- | :--- | :--- |
| **📱 Técnico de Ruta** | **Cero cambios visuales.** Mismas pantallas de paradas, checklists, repuestos y pausas. | Ejecuta intervenciones in situ, declara piezas y pausas como siempre. | Si pierde cobertura o vence el timeout ordinario (5,0s), el borrador del formulario se retiene íntegro en memoria. Si la máquina entró en cuarentena sobrevenida, el sistema conserva su informe y le exige checklist térmico antes de cerrar. |
| **🏢 Responsable de Sede** | **Cero cambios visuales.** Mismo portal de sedes con estado de parque y conversación. | Consulta avisos, valida llaves de acceso y aporta respuestas aclaratorias. | La aplicación no muestra volcados de error técnico; presenta un aviso flotante temporal indicando que el servicio está reconectando con botón táctil de reintento. |
| **📊 Coordinador de Operaciones** | **Cero cambios en operativa diaria + Diagnóstico.** Mismo cuadro de mandos, triaje y mapa. | Supervisión de flota, asignación de averías, gestión de SLAs y reintegros. | Cuenta con el endpoint de diagnóstico de salud (`GET /api/status`) con telemetría técnica detallada accesible únicamente con credenciales autorizadas, sin alterar el contrato del endpoint ligero `/api/health`. |
| **👥 Ciudadano / Usuario QR** | **Cero cambios visuales.** Mismo reporte público y seguimiento anónimo. | Escanea el código QR de la máquina, reporta anomalías o solicita reintegros. | En caso de desconexión puntual, visualiza un mensaje neutral y amable invitando a reintentar la acción en unos instantes. |
| **🛠️ Administrador de Sistemas / QA** | **Herramientas de entorno.** Configuración y diagnóstico del enlace. | Inicialización de datos maestros, verificación de salud y ejecución de pruebas. | Puede conmutar la aplicación para ejecutar pruebas 100% en local sin internet o validar contra la nube con paridad de resultados. |

---

## 3. Historias de Usuario

### HU-01: Continuidad Técnica Absoluta en Campo
> **Como** Técnico de Campo en ruta,  
> **quiero** gestionar mis órdenes de trabajo, registrar sustitución de piezas y tramitar pausas por falta de acceso con la misma interfaz e inmediatez que hasta ahora,  
> **para** cumplir mis paradas diarias sin sufrir fricciones, cambios de diseño ni retrasos provocados por la infraestructura del sistema.

### HU-02: Garantía Inviolable de Seguridad Alimentaria sin Duplicidad de Reglas (Art. II y Art. I.3)
> **Como** Responsable Sanitario y Coordinador de Operaciones,  
> **quiero** que la detección de rotura de cadena de frío a las 4 horas naturales en máquinas de perecederos se ejecute exclusivamente a través del servicio canónico de dominio en PHP tanto en la nube como en local,  
> **para** evitar divergencias algorítmicas entre procedimientos SQL no certificados y el código de aplicación que protege la salud pública.

### HU-03: Desarrollo y Certificación de Pruebas sin Conexión a Internet
> **Como** Ingeniero de Calidad (QA) o Desarrollador,  
> **quiero** ejecutar la batería completa de pruebas automatizadas en mi equipo local desconectado y obtener exactamente los mismos resultados que contra el entorno en la nube,  
> **para** certificar que no existen regresiones de código de forma ágil, autónoma y sin depender de la disponibilidad de un servicio externo.

### HU-04: Resiliencia ante Desconexión y Protección de Borradores Técnicos
> **Como** Técnico de Campo que trabaja en sótanos o zonas con cobertura intermitente,  
> **quiero** que si el enlace con la base de datos remota se interrumpe o vence el timeout de 5 segundos durante el guardado de mi intervención, el sistema conserve intacto el texto de diagnóstico y repuestos redactados y me permita reintentar el envío al recuperar señal,  
> **para** no perder mi tiempo de trabajo administrativo ni verme obligado a redactar de nuevo los detalles de la reparación.

### HU-05: Prevención de Suspensión y Restauración Desatendida (Art. VI.1)
> **Como** Administrador del Sistema,  
> **quiero** que un latido automático diario impida la suspensión de la base de datos en la nube y, en caso de suspensión excepcional, invoque la restauración automática,  
> **para** asegurar que el sistema esté operativo 24/7 sin haber tenido que desarrollar paneles complejos que desvíen el foco del MVP.

---

## 4. Requisitos Funcionales (RF) con Criterios de Aceptación (Notación EARS)

### RF-01: Persistencia Dual Transparente y Agnosticismo de Motor
El sistema deberá ser plenamente agnóstico respecto a la plataforma relacional, operando de forma indistinta contra PostgreSQL en la nube o contra MariaDB/MySQL local.

* **EARS-01.1 (Ubicuo):** El sistema siempre deberá garantizar la integridad referencial, el aislamiento transaccional y la exactitud de los cálculos temporales de SLA y MTTR con independencia del motor relacional activo. La portabilidad se resuelve con SQL estándar allá donde exista forma común y, para los constructos que **carecen** de equivalente estándar (upsert `ON CONFLICT` / `ON DUPLICATE KEY UPDATE`, lectura de JSON, aritmética de intervalos y diferencias temporales), mediante un **adaptador de dialecto único y explícito** por operación, nunca mediante copias divergentes de la regla de negocio.
* **EARS-01.2 (Dirigido por Estado):** MIENTRAS el sistema esté configurado para operar contra PostgreSQL en Supabase, el sistema deberá canalizar las operaciones a través del agrupador transaccional Supavisor (puerto 6543) con emulación de sentencias preparadas activada (`PDO::ATTR_EMULATE_PREPARES => true`) para evitar errores de sentencias preparadas en modo transacción.
* **EARS-01.3 (Dirigido por Estado):** MIENTRAS el sistema esté configurado para operar en el entorno local desconectado, el sistema deberá ejecutar todas las consultas y pruebas sin emitir ninguna llamada de red hacia el exterior.
* **EARS-01.4 (Dirigido por Evento):** CUANDO se modifique la variable de configuración `DATABASE_URL` del entorno, el sistema deberá autodeterminar el driver (`pdo_pgsql` vs `pdo_mysql`) y conmutar su operativa sin requerir cambios en la lógica de dominio ni en el frontend.
* **EARS-01.5 (Ubicuo):** El sistema siempre deberá concentrar las diferencias de dialecto en un único punto de traducción por operación, de modo que exista **una sola definición de cada regla de negocio** y dos traducciones de su sintaxis, y que una prueba automatizada certifique que ningún repositorio introduce SQL privativo fuera de ese punto.
* **EARS-01.6 (Ubicuo):** La política de credenciales del proyecto (hallazgo S-5: en producción se exige una fuente explícita y se rechaza el acceso administrativo sin contraseña) deberá aplicarse **de forma idéntica a los dos drivers** y resolverse **antes** de intentar cualquier conexión, sin valores por defecto silenciosos en la rama PostgreSQL.

---

### RF-02: Inicialización en Limpio (*Fresh Start*) y Paridad de Fixtures de Prueba
El sistema deberá proveer mecanismos diferenciados de inicialización: carga en limpio de catálogo maestro para producción y sembrado de fixtures para la certificación de pruebas.

* **EARS-02.1 (Dirigido por Evento):** CUANDO se inicialice el entorno de producción en una base de datos vacía, el sistema deberá registrar el catálogo maestro completo (sedes, máquinas, usuarios autorizados, catálogo de repuestos y configuraciones de ruta) mediante la operación de upsert que resuelve el adaptador de dialecto —`ON CONFLICT ... DO UPDATE` en PostgreSQL, `ON DUPLICATE KEY UPDATE` en MariaDB— con una sola definición de la regla (EARS-01.5), sin incidencias activas ni historiales previos **en ese paso**: el histórico de demostración del despliegue entra después y por defecto, conforme a EARS-02.6.
* **EARS-02.2 (Dirigido por Evento):** CUANDO se inicialice el entorno de ejecución de pruebas automatizadas (tanto en local como en la nube), el sistema deberá sembrar el arnés de datos y fixtures históricos necesarios para que la suite de pruebas completa certifique el ciclo de vida sin discrepancias.
* **EARS-02.3 (Ubicuo):** El sistema siempre deberá inicializar las credenciales de usuarios maestros con contraseñas seguras por defecto validadas mediante algoritmo de hash criptográfico estándar (`password_hash`), permitiendo su gestión posterior mediante el módulo de administración existente.
* **EARS-02.4 (Ubicuo):** El esquema desplegado siempre deberá **converger** al esquema del repositorio mediante un ejecutor de migraciones del motor activo, y no limitarse a la creación inicial: una base que ya existe debe recibir cada migración posterior. Se prohíbe el despliegue cuyo único mecanismo de esquema sea un DDL base de creación, por ser precisamente la asimetría que provocó el incidente de esquema desincronizado del 2026-10-03 (H-12).
* **EARS-02.5 (No Deseado / Excepción):** SI el motor de persistencia no está disponible en el arranque (proyecto suspendido o red caída), ENTONCES el inicializador deberá reintentar con espera progresiva un mínimo de tres veces y **no dejar el servicio sirviendo con un esquema no verificado**; y tras una restauración de proyecto, el flujo desatendido deberá provocar la re-inicialización y verificación del esquema antes de declarar el servicio recuperado.
* **EARS-02.6 (Dirigido por Evento · Enmienda del Product Owner, ratificada el 2026-10-15):** CUANDO arranque el servicio desplegado, el sistema deberá cargar —y **reafirmar**— el histórico de demostración (las diecisiete averías `INC-DEMO-*` con su traza de estados y su rastro de auditoría) **sin depender de ninguna declaración de entorno**: cada redespliegue lo devuelve a su sitio. Solo una declaración explícita de «no» (la variable `VENDGUARD_DEMO_HISTORY` con valor `0`, `false`, `off` o `no`) dejará el arranque en limpio estricto. La reafirmación de un expediente que ya existe **no podrá borrar ni sustituir ninguna fila** (Art. III.1): desplazará su traza y su auditoría al esquema temporal vigente mediante `UPDATE`, fila a fila y conservando la identidad de cada fila, y sólo sobre el expediente cuya traza almacenada sea **exactamente** la del fixture —mismo número de eventos, mismas transiciones con los mismos actores y mismas acciones de auditoría, en el mismo orden—. Un expediente cuya traza ya no sea la del fixture (la sede lo reabrió, el coordinador lo canceló, el fixture cambió de forma) no se tocará y se declarará en la salida; y una avería **viva** del fixture cuya máquina esté ocupada por otro aviso vivo se omitirá declarándolo, de modo que un aviso en garantía no pueda impedir el arranque del servicio (`uq_machine_active_ticket`, Art. V.2 y V.6). La ventana de purga del arnés (`vendguard.purge`) queda expresamente fuera de este camino: es del arnés y la aplicación nunca la activa (EARS-01.3, T-HYBRID-12 y T-HYBRID-26).

---

### RF-03: Prevención Activa de Suspensión por Inactividad (*Keep-Alive*) y Auto-Restauración
El sistema deberá evitar de forma proactiva que la base de datos en la nube entre en suspensión tras 7 días sin tráfico, proveyendo auto-restauración en caso de contingencia.

* **EARS-03.1 (Ubicuo):** El sistema siempre deberá contar con un mecanismo de latido periódico desatendido orquestado externamente (mediante flujo automatizado diario a las 06:00 UTC) que consulte el endpoint técnico de diagnóstico con verificación real de base de datos (`GET /api/status`), dejando intacto el contrato del endpoint ligero preexistente (`GET /api/health`).
* **EARS-03.2 (Dirigido por Evento):** CUANDO el mecanismo de latido se ejecute, el sistema deberá realizar una consulta **sobre una tabla real del dominio** (no un `SELECT 1` sintético) que registre actividad efectiva ante el proveedor de la nube.
* **EARS-03.3 (No Deseado / Excepción):** SI el latido constata, **consultando el estado del proyecto por la API de gestión** (y no deduciéndolo del código HTTP del endpoint de la aplicación, que no puede observar el `540`), que el proyecto está suspendido, ENTONCES el flujo automatizado deberá invocar la restauración (`POST /v1/projects/{ref}/restore`) con un token de gestión custodiado como secreto del entorno, esperar a que el estado sea activo, re-inicializar y verificar el esquema (EARS-02.5) y notificar el resultado por un canal que no sea un log que nadie lee.

---

### RF-04: Degradación Visual Controlada, Resiliencia de Red y Protección de Borradores
La interfaz de usuario deberá responder con resiliencia ante cortes de red o saturación temporal, protegiendo los datos introducidos por los operarios.

* **EARS-04.1 (Dirigido por Estado):** MIENTRAS se curse cualquier petición HTTP contra el backend, el sistema aplicará un tiempo de espera (*timeout*) ordinario estricto de **5,0 segundos** tanto en el cliente (`AbortSignal.timeout(5000)`) como en el tiempo de conexión del driver PDO (`ATTR_TIMEOUT => 5`), garantizando coherencia temporal extremo a extremo.
  * **Excepción acotada al cuerpo `multipart/form-data` (Módulo 24):** las peticiones que transportan una evidencia fotográfica de hasta 5 MB usan un plazo de cliente propio de **30,0 segundos** (`UPLOAD_TIMEOUT_MS`), porque el temporizador mide la transferencia completa y 5,0 s no alcanzan para ese volumen sobre cobertura móvil débil. El tiempo de conexión del driver PDO sigue en 5 s: mide la ejecución de la consulta, no la recepción del archivo, así que el techo del servidor no se relaja. Cualquier otra petición, incluidas las multipart sin archivo, conserva los 5,0 s.
* **EARS-04.2 (Dirigido por Evento):** CUANDO se pulse el botón de envío de un formulario, el sistema deberá deshabilitarlo temporalmente y mostrar un indicador de carga para impedir peticiones duplicadas accidentales.
* **EARS-04.3 (No Deseado / Excepción):** SI una petición agota el tiempo de espera de 5,0 segundos o sufre una interrupción física de conectividad, ENTONCES el sistema deberá mostrar un banner flotante global no intrusivo (`ConnectionStateBanner.js` en estado `disconnected`) preservando íntegramente los datos y textos redactados en el formulario en memoria reactiva.
* **EARS-04.4 (Dirigido por Evento):** CUANDO se presente el banner de desconexión, el sistema deberá ofrecer un botón táctil accesible ($\ge 44\text{ px}$) para reintentar el envío de forma inmediata sin recargar la página ni perder el borrador.

---

### RF-05: Acotamiento de Datos Relacionales y Transaccionalidad Compensatoria de Archivos
La migración de persistencia se circunscribe a los datos tabulares, manteniendo las evidencias multimedia en el servidor de aplicaciones y evitando fugas de archivos huérfanos.

* **EARS-05.1 (Ubicuo):** El sistema siempre deberá almacenar en la base de datos en la nube únicamente datos estructurados, cadenas de texto, cifras y marcas temporales, quedando expresamente prohibido el almacenamiento de imágenes binarias en columnas de la base de datos.
* **EARS-05.2 (Dirigido por Evento):** CUANDO un usuario adjunte una fotografía o evidencia gráfica, el sistema deberá guardar físicamente el archivo en el almacenamiento local del servidor de aplicaciones e insertar la ruta relativa en la base de datos.
* **EARS-05.3 (No Deseado / Excepción):** SI tras guardar físicamente el archivo en el servidor, la inserción del registro en la base de datos remota falla por error de red o timeout, ENTONCES el servicio compensatorio ([`FileStorageCompensator.php`](../../src/Infrastructure/Storage/FileStorageCompensator.php)) deberá eliminar inmediatamente el archivo recién creado delegando el borrado físico en el mismo componente que lo escribió ([`LocalFileUploader::delete()`](../../src/Infrastructure/Storage/LocalFileUploader.php)), a partir de la **ruta pública** que devolvió la subida. Toda ruta de la aplicación que suba una evidencia antes de persistir deberá invocarlo, y ninguna reimplementará su propio `unlink()`.
* **EARS-05.4 (Ubicuo):** El sistema siempre deberá servir las imágenes a través del servidor web propio, impidiendo que la entrega de fotografías consuma la cuota de transferencia saliente (*egress*) de la base de datos en la nube.

---

### RF-06: Automatización del Barrido Sanitario con Fuente Canónica Única (Art. II y Art. I.3)
La inspección biológica y la activación automática de cuarentena sanitaria a las 4 horas en máquinas de perecederos deberán regirse por una única lógica canónica idéntica en todos los entornos, prohibiendo terminantemente la duplicación de reglas en procedimientos almacenados SQL.

* **EARS-06.1 (Ubicuo):** El sistema siempre deberá concentrar el 100% de la lógica y reglas de cálculo del barrido sanitario en el servicio de aplicación [`SanitaryClockSweepService.php`](../../src/Application/Service/SanitaryClockSweepService.php) y políticas de escalado como única fuente de verdad (Art. I.3), prohibiendo divergencias de cálculo entre procedimientos almacenados de base de datos y código de aplicación.
* **EARS-06.2 (Dirigido por Estado):** MIENTRAS la aplicación opere en el entorno de nube, el reloj canónico será el del **servidor de aplicaciones**, declarado en el artefacto versionado [`deploy/cron/vendguard-sanitary-sweep`](../../deploy/cron/vendguard-sanitary-sweep) —crontab de busybox `crond` instalado en la imagen—, que invocará cada 15 minutos el servicio canónico por el CLI ya existente [`bin/sanitary_clock_sweep.php`](../../bin/sanitary_clock_sweep.php) con el entorno inyectado desde `/etc/vendguard/cron.env`. El **despertador y vigilante externo** ([`.github/workflows/sanitary-clock.yml`](../../.github/workflows/sanitary-clock.yml)) cubre la suspensión del contenedor y la ventana perdida: despierta la aplicación, comprueba `sanitary_clock.last_pass_at` y **solo si la ventana se perdió** invoca el mismo servicio por `POST /api/cron/sanitary-clock-sweep`, autenticándose con la credencial de proceso batch ya existente (`CRON_SECRET` por `X-Cron-Secret` o `Authorization: Bearer`). Ninguna de las dos piezas implementa reglas propias (Art. I.3).
* **EARS-06.3 (Dirigido por Evento):** CUANDO el barrido detecte una máquina de perecederos que alcance las 4 horas naturales continuas (14.400 segundos) sin resolución, el sistema deberá declarar de inmediato el estado `QUARANTINE` en la máquina, registrar la fecha de inicio de cuarentena y asentar un evento inmutable en el historial y en las tablas de auditoría de barrido.
* **EARS-06.4 (Dirigido por Estado):** MIENTRAS la aplicación opere en el entorno local desconectado, el sistema deberá mantener la invocación periódica de este mismo servicio mediante el comando binario CLI habitual (`bin/sanitary_clock_sweep.php`), garantizando exactamente el mismo resultado y la misma auditoría forense.
* **EARS-06.5 (No Deseado / Excepción):** El programador periódico deberá reintentar la invocación fallida con espera progresiva, registrar cada pasada con resultado y **alertar cuando un barrido no se haya ejecutado** en la ventana esperada (tolerancia declarada de 35 minutos). La alerta viajará por un canal que exige atención —la ejecución fallida del flujo programado, que el repositorio notifica a sus responsables, más un webhook de canal real cuando esté configurado— y la intervención del vigilante externo se registrará como **anomalía** en lugar de silenciarse. Art. II no admite un fallo silencioso del reloj biológico.
* **EARS-06.6 (Dirigido por Estado):** MIENTRAS se utilice el programador interno del motor (`pg_cron` con `pg_net`) como orquestador alternativo, la credencial del endpoint deberá obtenerse de un almacén de secretos del proveedor (Vault) en tiempo de ejecución; queda expresamente prohibido incorporar el secreto al DDL versionado o dejar el endpoint accesible sin autenticación.

---

### RF-07: Comprobación Técnica de Salud del Enlace (*Health Check*, Art. VI.1 y Art. V.4)
El sistema deberá proveer un mecanismo de verificación de conectividad y latencia sin exponer topología interna a actores no autorizados, y **sin alterar el contrato del endpoint ligero preexistente** del que dependen los consumidores actuales (el servidor de frontend y las sondas de arranque de las suites).

* **EARS-07.1 (Dirigido por Evento):** CUANDO un monitor externo o el latido emita una petición `GET /api/status`, el sistema deberá verificar la conectividad activa contra el motor de persistencia mediante una consulta sobre una tabla real y responder en menos de 1,0 segundo.
* **EARS-07.2 (Ubicuo):** Ante accesos públicos no autenticados, la respuesta de salud únicamente informará de campos mínimos (`status: "HEALTHY" | "DEGRADED"`, `timestamp_utc`), prohibiendo la exposición pública de nombres de driver, agrupadores, nombres de host o latencias exactas para cumplir con la no fuga de información técnica (Art. V.4).
* **EARS-07.3 (Dirigido por Estado):** MIENTRAS la petición presente la credencial interna de proceso batch ya existente (`X-Cron-Secret` o `Authorization: Bearer` con `CRON_SECRET`) o una sesión válida de Coordinador, el sistema incluirá en la respuesta la telemetría detallada (`database_driver`, `connection_pooler`, `response_time_ms` y `sanitary_clock.last_pass_at`, que es la señal con la que el vigilante externo detecta una ventana perdida). La comprobación será **no fatal** —la ausencia de credencial no rechaza la petición, solo reduce la respuesta—, con una única implementación de la comparación reutilizada por el middleware de proceso batch y por el controlador. Queda prohibida la creación de un mecanismo de credencial paralelo o de una segunda comparación del secreto.
* **EARS-07.4 (No Deseado / Excepción):** SI la comprobación no puede conectar con la base de datos activa, ENTONCES el sistema deberá devolver un código HTTP 503 con indicación de servicio no disponible, absteniéndose de exponer credenciales, contraseñas, URLs internas o volcados de excepciones técnicas.
* **EARS-07.5 (Ubicuo):** El endpoint ligero preexistente `GET /api/health` conservará sin cambios su contrato actual —envoltura JSON con la identificación del servicio y servicio del documento de la aplicación ante peticiones HTML—, de modo que ninguna sonda ni consumidor existente se vea afectado por la incorporación del diagnóstico con base de datos.

---

### RF-08: Blindaje Constitucional Integral y Ruidoso en el Motor de Persistencia
El motor de datos deberá aplicar de forma activa e inviolable las reglas de la Constitución de VendGuard.

* **EARS-08.1 (Ubicuo):** El motor de datos siempre deberá impedir mediante disparadores `BEFORE DELETE` cualquier sentencia destructiva (`DELETE FROM`) sobre **todas** las entidades maestras y operativas (máquinas, sedes, usuarios, incidencias, repuestos, reintegros, historial y auditoría), emitiendo de forma ruidosa una excepción con `SQLSTATE '45000'` y mensaje constitucional (Art. III.1). El disparador cederá únicamente ante la señal explícita y, por defecto, **de ámbito de sesión** —`vendguard.purge = on` en PostgreSQL y `@vendguard_purge` en MariaDB, que son una misma regla con dos formas técnicas—, que el arnés de pruebas declara al abrir su conexión mediante un único ayudante consciente del driver y que la aplicación **nunca** activa. Toda estructura de limpieza del arnés —el limpiador compartido y las suites que retiran sus propias filas maestras— deberá canalizar sus borrados a través de esa señal, de modo que la regla sea absoluta para la aplicación y practicable para las pruebas ([`TestDataCleaner.php`](../../tests/Support/TestDataCleaner.php)).
* **EARS-08.2 (Ubicuo):** El sistema siempre deberá comprobar preventivamente en la capa de aplicación y advertir al usuario si una máquina ya cuenta con una incidencia abierta (Art. V.2); como red de seguridad final ante concurrencia, el motor de datos rechazará atómicamente la inserción si ya existe un ticket donde el estado sea distinto de `CLOSED` y `CANCELLED` mediante índice único parcial `uq_machine_active_ticket` (abarcando el estado `RESOLVED` durante la ventana de 48 horas de garantía según Art. V.6).
* **EARS-08.3 (Ubicuo):** El motor de datos siempre deberá rechazar a nivel de restricción `CHECK` cualquier intento de actualizar una incidencia al estado `RESOLVED` o declarar una pausa `PENDING_INFO` si el texto de justificación o diagnóstico cuenta con menos de 20 caracteres reales (Art. V.1).
* **EARS-08.4 (Dirigido por Evento):** CUANDO un ciudadano consulte el estado público de una máquina mediante su código QR, el sistema siempre proyectará una vista neutral y anonimizada que muestre únicamente *"En proceso de atención técnica"*, sin revelar motivos de pausa, identidades de operarios ni notas de taller (Art. V.4).
* **EARS-08.5 (Ubicuo):** El sistema siempre deberá preservar en ambos motores la **misma** semántica de unicidad en los campos clave (`email` en `users`, `site_code` en `locations`, `ticket_code` en `incidents`) mediante claves canónicas calculadas en la aplicación y un índice único sobre ellas, evitando colisiones de identidad en el login y sedes o tickets duplicados. Se prohíbe apoyar la regla en una colación de motor, porque los dos motores no la implementan con la misma fuerza y la prueba certificaría una semántica distinta de la que sirve producción.

---

### RF-09: Normalización Horaria Estricta en UTC y Presupuestos de Espera
El sistema deberá garantizar la coherencia absoluta en los cómputos temporales y tiempos de bloqueo transaccionales.

* **EARS-09.1 (Ubicuo):** El motor de datos siempre deberá almacenar todas las marcas temporales (`created_at`, `paused_at`, `resolved_at`, etc.) en formato estandarizado con zona horaria UTC (`TIMESTAMPTZ`).
* **EARS-09.2 (Dirigido por Evento):** CUANDO el sistema deba evaluar el horario hábil comercial de una sede o computar las 4 horas biológicas del reloj de frío, el sistema normalizará la marca temporal UTC a la zona horaria oficial del parque de máquinas (`Europe/Madrid`), contemplando automáticamente las transiciones entre horario de invierno (CET, UTC+1) y horario de verano (CEST, UTC+2).
* **EARS-09.3 (Ubicuo):** El adaptador de espera de bloqueo ([`PdoLockWaitBudget.php`](../../src/Infrastructure/Database/PdoLockWaitBudget.php)) detectará de forma transparente el driver en uso: en MariaDB utilizará `innodb_lock_wait_timeout`, y en PostgreSQL utilizará `SET LOCAL lock_timeout` dentro de la transacción, garantizando la compatibilidad con el agrupador transaccional.

---

### RF-10: Salvaguarda del Parte Técnico ante Cuarentena Biológica Sobrevenida (Art. II)
El sistema deberá gestionar los casos en los que una máquina entre en cuarentena mientras el técnico trabaja sin cobertura in situ, priorizando la salud pública sin destruir su trabajo administrativo.

* **EARS-10.1 (No Deseado / Excepción):** SI un técnico intenta registrar la resolución ordinaria de una avería pero la máquina entró en cuarentena sanitaria biológica en la central mientras trabajaba sin cobertura, ENTONCES el sistema deberá suspender el cierre definitivo, almacenar en borrador seguro el diagnóstico y repuestos ya redactados, y notificar al técnico mediante error HTTP 422 (`SANITARY_QUARANTINE_OVERTAKEN`) que la máquina requiere el protocolo obligatorio de higienización y comprobación térmica (Art. II).
* **EARS-10.2 (Dirigido por Evento):** CUANDO el técnico complete y firme la declaración sanitaria obligatoria, el sistema deberá consolidar el cierre definitivo y registrar el certificado correspondiente sin forzarle a redactar de nuevo los datos técnicos previos.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Latencia Transaccional):** Las operaciones habituales de lectura y escritura a través del agrupador de conexiones Supavisor deberán resolverse con una mediana inferior a **250 ms**, medida **contra el motor remoto por el camino real** (enrutador, middleware, servicio y PDO) con un mínimo de 20 muestras por operación representativa, publicando mediana y percentil 95. Las cifras de latencia medidas contra el motor local no son evidencia válida para este requisito y no pueden heredarse.
* **RNF-02 (Ajuste al Límite Gratuito de Almacenamiento):** El volumen total ocupado por los datos tabulares de las 20 tablas no deberá exceder de **100 MB** (20% del límite de 500 MB de Supabase Free) para una flota de 10.000 expedientes completos.
* **RNF-03 (Consumo de Transferencia Saliente):** La transferencia mensual generada por las consultas de datos entre la nube y el servidor de aplicaciones no deberá superar **1 GB al mes** (20% del presupuesto de 5 GB total compartido de la organización en Supabase Free).
* **RNF-04 (Independencia de Red en la Batería Hermética):** La batería hermética (`php tests/run_all.php`) deberá poder ejecutarse al 100% en modo desconectado contra el motor local, certificando exactamente las mismas reglas de negocio sin requerir conexión a internet. Las suites que exigen el motor nuevo (PostgreSQL) o el motor remoto **no forman parte** de esa batería: constituyen la **certificación de contrato del motor nuevo**, con su propia categoría, su propio canal de ejecución y su propio informe, y se ejecutan solo cuando el motor está disponible. Ninguna suite se omite en silencio de la categoría a la que pertenece.
* **RNF-05 (Seguridad en Tránsito):** Toda conexión entre el servidor de aplicaciones y la base de datos en la nube deberá cifrarse obligatoriamente mediante TLS/SSL **y verificar la identidad del servidor**: `sslmode=verify-full` con la CA de confianza declarada explícitamente (`sslrootcert` sobre el almacén de certificados que ya instala la imagen). Queda prohibido degradar el modo a `require`, `prefer`, `allow` o `disable`; si la cadena del proveedor no validase contra el almacén de la imagen, se resuelve antes de desplegar y el criterio no se degrada.
* **RNF-06 (Ergonomía del Banner de Desconexión):** El banner flotante de desconexión deberá cumplir con las normas de contraste y accesibilidad vigentes del proyecto, y su botón de reintento deberá contar con una altura táctil mínima de 44 px para uso ergonómico en movilidad.

---

## 6. Casos Límite y Comportamientos Excepcionales

1. **Suspensión por inactividad tras 7 días en Supabase Free:**  
   Si el proyecto de Supabase se suspende por falta de actividad, las consultas de base de datos fallan (HTTP 540 o error de conexión). El flujo automatizado en GitHub Actions detecta el estado y ejecuta la restauración mediante la API de gestión (`POST /v1/projects/{ref}/restore`). El cliente web no intenta despertar el motor con reintentos mágicos; muestra el banner de desconexión con botón de reintento.
2. **Fallo de conectividad tras almacenar físicamente una fotografía:**  
   Si una imagen se guarda en el disco del servidor pero la inserción en la base de datos remota agota el timeout de 5 segundos, la ruta que persiste la evidencia ejecuta la compensación ([`FileStorageCompensator.php`](../../src/Infrastructure/Storage/FileStorageCompensator.php)), que delega el borrado físico en [`LocalFileUploader::delete()`](../../src/Infrastructure/Storage/LocalFileUploader.php) sobre la ruta pública devuelta por la subida, y notifica al usuario del fallo para reintentar la subida completa, impidiendo fugas de espacio.
3. **Reporte ciudadano concurrente durante la ventana de garantía de 48 horas (`RESOLVED`):**  
   Si una máquina tiene una avería en `RESOLVED` y un usuario escanea el QR reportando un nuevo fallo, el índice único parcial `uq_machine_active_ticket` impide duplicados a nivel de motor, mientras la aplicación asocia la información como reapertura justificada o aviso de garantía sobre el expediente preexistente.
4. **Saturación puntual del agrupador de conexiones (*Supavisor*):**  
   Ante una ráfaga simultánea de peticiones que supere temporalmente las conexiones del pool, el backend aplica hasta 2 reintentos con retraso progresivo (*backoff* y *jitter*) antes de emitir un error 503 controlado.
5. **Intento de borrado masivo por script accidental:**  
   Cualquier sentencia de eliminación física (`DELETE FROM machines`, `DELETE FROM incidents`, etc.) es rechazada en voz alta por los disparadores `BEFORE DELETE` con `SQLSTATE '45000'`, manteniendo la integridad histórica inalterable (Art. III.1) salvo que el arnés de pruebas declare explícitamente la señal de sesión en la forma de su motor —`set_config('vendguard.purge', 'on', false)` en PostgreSQL, `SET @vendguard_purge = 1` en MariaDB— al abrir su conexión, sin que la aplicación la active nunca y sin que exista una tercera forma.

---

## 7. Fuera de Alcance (*Out of Scope*)

* **Trasvase de incidencias e historiales previos de producción:** Los expedientes cerrados, comentarios y pistas forenses de la base de datos anterior no se migran; quedan archivados en el sistema previo como registro histórico de consulta (*Fresh Start*).
* **Migración de evidencias fotográficas a servicios de almacenamiento de objetos en la nube:** Las imágenes continúan residiendo en el servidor de aplicaciones para preservar la cuota de transferencia saliente de la base de datos.
* **Rediseño de pantallas de usuario:** Ningún modal, cabecera ni vista existente de técnico, sede o ciudadano sufre modificaciones estéticas. La interfaz se mantiene 100% idéntica, añadiéndose únicamente el banner global no intrusivo de desconexión y el endpoint técnico de salud.
* **Eliminación del motor relacional local:** Se mantiene de forma permanente la compatibilidad dual para garantizar el desarrollo, CI/CD y testeo desconectado sin internet.

---

## 8. Criterios de Aceptación y Finalización

La especificación se considerará formalmente satisfecha cuando se cumplan todos los siguientes criterios objetivos:

1. **Certificación al 100% en Verde, en Dos Categorías Declaradas:** La **batería hermética** —todas las suites que se ejecutan sin red contra el motor local— pasa al completo mediante `php tests/run_all.php`, sin un solo fallo, con las cifras documentadas debidamente actualizadas en [`README.md`](../../README.md) según exige [`DocMetricsGuard.php`](../../tests/Support/DocMetricsGuard.php). Por separado, la **certificación de contrato del motor nuevo** (PostgreSQL) se ejecuta en su propia categoría y canal, con su propio informe, y debe estar igualmente en verde antes de declarar cerrado el módulo. Ninguna suite se salta en silencio: o pertenece a la batería hermética, o se declara en la categoría del motor nuevo (RNF-04, EARS-01.3).
2. **Verificación de Blindaje Constitucional en Base de Datos:** Se demuestra mediante pruebas automatizadas que el motor de persistencia:
   * Rechaza con excepción ruidosa `SQLSTATE '45000'` sentencias `DELETE FROM` en todas las tablas maestras y operativas (Art. III.1), respetando la única vía de escape: la señal de ámbito de sesión **en la forma del motor activo** (`vendguard.purge` en PostgreSQL, `@vendguard_purge` en MariaDB) que declara el arnés de pruebas mediante un único ayudante consciente del driver —el limpiador compartido y las suites que retiran sus propias filas maestras— y que la aplicación nunca activa. Se certifican **las dos** formas, no una.
   * Impide la creación de más de una avería activa simultánea por máquina física, abarcando `RESOLVED` (Art. V.2 y V.6).
   * Rechaza diagnósticos de resolución con menos de 20 caracteres reales (Art. V.1).
   * Devuelve respuestas anonimizadas ante consultas ciudadanas no autenticadas (Art. V.4).
   * Preserva la unicidad de emails, sedes y códigos de ticket con la misma semántica en ambos motores, mediante claves canónicas calculadas en la aplicación (EARS-08.5).
3. **Verificación del Barrido Sanitario Autónomo:** La invocación periódica en la nube ejecuta la misma lógica canónica del servicio de dominio [`SanitaryClockSweepService.php`](../../src/Application/Service/SanitaryClockSweepService.php) (Art. II y Art. I.3), y su existencia es verificable desde el repositorio: el artefacto del reloj está versionado e instalado en la imagen, la telemetría autenticada publica `sanitary_clock.last_pass_at`, el historial de pasadas registra una pasada por ventana, y el vigilante externo demuestra que muerde cuando la ventana se pierde (plan §7.2).
4. **Verificación del Latido Preventivo (*Keep-Alive*) y Auto-Restauración:** El flujo desatendido diario consulta el endpoint técnico `GET /api/status`, que ejecuta una consulta sobre tabla real del dominio para registrar actividad efectiva ante el proveedor, sin alterar el contrato de `GET /api/health`; determina el estado del proyecto por la API de gestión —no por el código HTTP de la aplicación— y cuenta con capacidad de restauración por API ante suspensión, con re-inicialización y verificación del esquema después de restaurar.
5. **Verificación de Resiliencia ante Desconexión y Archivos Huérfanos:**
   * Se simula una caída de red o timeout de 5 segundos y se comprueba que el banner accesible aparece, que los formularios retienen sus datos en memoria y que se recuperan en 1 clic.
   * Se simula un fallo de inserción de base de datos tras subida de imagen y se certifica que el archivo en disco **ya no existe**: la compensación la invoca la ruta que persiste la evidencia y el borrado físico lo ejecuta el componente que escribió el fichero. Se certifica además que ninguna ruta de subida deja el fichero huérfano.
6. **Verificación de Despliegue en Contenedor:** La imagen en [`Dockerfile`](../../Dockerfile) instala `pdo_pgsql` junto a `pdo_mysql`, y el script de arranque [`bin/init_cloud_db.php`](../../bin/init_cloud_db.php) detecta el driver activo, aplica el DDL base **y el conjunto de migraciones del motor**, reintenta con espera progresiva y no deja el servicio sirviendo con un esquema no verificado (EARS-02.4 y EARS-02.5), y aplica la política de credenciales del proyecto de forma idéntica en los dos drivers (EARS-01.6).
7. **Verificación de Convergencia y de Correspondencia de Esquema:** Una prueba automatizada demuestra que una base desplegada **converge** al esquema del repositorio al aplicarse una migración posterior (no se queda congelada en su creación inicial). Además, el esquema PostgreSQL y el esquema MariaDB se contrastan mediante un **contrato de correspondencia declarado**: qué tablas, columnas e índices deben coincidir uno a uno y qué diferencias se aceptan por diseño (tipos nativos, identidad de fila frente a `AUTO_INCREMENT`, columna generada con índice compuesto frente a índice único parcial, y el número de disparadores anti-borrado por motor). Una columna añadida en un motor y olvidada en el otro hace fallar la suite; una diferencia declarada y aceptada, no. Queda prohibido exigir igualdad literal de inventarios, porque el diseño la impide a propósito. El objetivo sigue siendo que la asimetría «esquema creado frente a esquema migrado» del incidente del 2026-10-03 no pueda reaparecer en el motor nuevo. La correspondencia obligatoria y las diferencias aceptadas están **declaradas** en [`schema_correspondence_contract.md`](../technical/schema_correspondence_contract.md), que es el contrato que esa prueba consume y al que el DDL se escribe conforme.

---

## 9. Dudas Abiertas

* *Las decisiones ratificadas son firmes: el programador periódico canónico vive en el servidor de aplicaciones (EARS-06.2) y el orquestador interno del motor solo se admite con credencial en Vault (EARS-06.6); el esquema desplegado converge mediante migraciones (EARS-02.4 y §8, criterio 7); la unicidad se resuelve con claves canónicas en la aplicación (EARS-08.5); las diferencias de dialecto se concentran en un adaptador explícito por operación (EARS-01.1, EARS-01.5 y EARS-01.6); y el reloj del barrido vive en el contenedor de aplicaciones con su despertador y vigilante externo, declarado en artefactos versionados (EARS-06.2, plan §7); y el motor local que exige la categoría de certificación del motor nuevo se declara y se aprovisiona con un artefacto versionado, con contrato de entorno sin valores por defecto y sonda que falla en cerrado (plan §8, `deploy/local/postgres/`).*
* **Dudas abiertas que esta especificación no puede cerrar sola** (tercera pasada, [`docs/auditoria_plan_migracion_supabase_hibrida_revision3.md`](../../docs/auditoria_plan_migracion_supabase_hibrida_revision3.md)):
  1. **Cómo se aprovisiona el motor remoto para la medición de RNF-01.** La mitad local de esta duda queda cerrada y materializada: el motor de la ejecución ordinaria está declarado, aprovisionado y comprobado por `deploy/local/postgres/` (plan §8). Lo que sigue pendiente es la medición de latencia contra el motor remoto, que exige un proyecto Supabase con credenciales custodiadas (RNF-01, T-HYBRID-27).
  2. **Qué ocurre si el DDL no puede cumplir el contrato declarado.** La parte declarativa queda cerrada: la correspondencia obligatoria y las diferencias aceptadas —con su forma exacta en cada motor— están en [`schema_correspondence_contract.md`](../technical/schema_correspondence_contract.md) (criterio 7), verificado contra el esquema desplegado. Lo que sigue abierto es su **materialización**: al escribir el DDL (T-HYBRID-01/02/23), si aparece una divergencia que no encaja en lo declarado, se enmienda **primero** el contrato y en la misma revisión, nunca el DDL en silencio (AGENTS.md §5.5).
