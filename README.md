# VendGuard · Gestor de Incidencias de Vending

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B%20Vanilla%20POO-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net/)
[![Frontend](https://img.shields.io/badge/Vue.js%203-ES%20Modules%20(No%20Bundler)-4FC08D?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![Database](https://img.shields.io/badge/Database-PostgreSQL%2016%2B%20%7C%20Supabase-3ecf8e?style=flat-square&logo=postgresql&logoColor=white)](https://supabase.com/)
[![Design System](https://img.shields.io/badge/Design%20System-Docker%20Tokens%20(%232560ff)-2496ED?style=flat-square&logo=docker&logoColor=white)](docs/design.md)
[![Tests Status](https://img.shields.io/badge/Tests-441%20Suites%20%7C%2016.993%20Pass%20(100%25)-38bd7d?style=flat-square)](tests/)
[![Constitutional Status](https://img.shields.io/badge/Constitution-Audited%20%26%20Certified-003db5?style=flat-square)](constitution.md)

**VendGuard** es una plataforma web integral de nivel industrial para la gestión, triaje, intervención técnica, métricas de SLA y auditoría inmutable de averías en parques de máquinas de vending (bebidas calientes, frías, snacks y comida perecedera).

El sistema erradica de raíz los problemas críticos del sector vending mediante siete pilares:
1. **Preservación de la cadena de frío (Art. II Constitución):** Detección inmediata y forzado de prioridad máxima (**CRÍTICA / Innegociable**) en máquinas con alimentos perecederos con objetivo estricto de SLA < 4.0 horas.
2. **Prevención atómica de duplicados:** Índice atómico en base de datos (`uq_machine_active_ticket`) que imposibilita la creación de tickets concurrentes para una misma máquina.
3. **Justificación obligatoria de intervenciones (Art. V.1):** Prohibición terminante de resolver incidencias sin registrar diagnóstico técnico real ($\ge 20$ caracteres) y acción correctiva demostrable ($\ge 20$ caracteres).
4. **Trazabilidad y auditoría permanente (Art. III):** Borrado físico estrictamente prohibido (*Soft Delete* obligatorio) y registro inmutable *Append-Only* (`audit_log`) con ventana de garantía de 48 horas y detección de averías crónicas.
5. **Trazabilidad económica de repuestos (Módulo M2):** Catálogo maestro por modelo de máquina con *snapshot* inmutable de coste en cada intervención, clasificación cerrada de destino (`DESGUACE`/`TALLER`) y segregación estricta de datos de piezas y costes para el Responsable de Sede (Art. V.4).
6. **Cartografía territorial y optimización de rutas (Módulo M4):** Proyección pura Web Mercator y secuenciación heurística en 4 fases sin librerías de terceros ni claves de API privadas (Dogma Vanilla), con visión unificada para Coordinación y navegación GPS móvil asistida para Técnicos de Campo, garantizando estricta segregación de datos frente a clientes (Art. V.4).
7. **Análisis de causas raíz y Diagrama de Pareto (Módulo 25):** Catálogo maestro normalizado de causas y soluciones estandarizadas que convierte el diagnóstico libre en una clasificación medible; el Pareto 80/20 cruza frecuencia, tiempo de reparación y coste de repuestos por fabricante, modelo y categoría, y exporta un CSV de dos secciones para reclamaciones de garantía (Art. III.3 y V.4).

---

## 🏛️ Filosofía de Ingeniería: Dogma Vanilla & Dualismo Lingüístico

El desarrollo se rige incondicionalmente por la [Constitución del Proyecto](constitution.md) y [AGENTS.md](AGENTS.md):

* **Dogma Vanilla (Cero Bloatware):**
  * **Backend:** PHP 8.2+ moderno puro orientado a objetos (*Clean Architecture*), tipado estricto en el 100% de ficheros (`declare(strict_types=1);`), persistencia nativa con **PDO** y generación algorítmica de códigos QR en SVG sin extensiones externas (`imagick` no requerida). Cero dependencias externas (`composer.json` / `vendor/` ausentes en runtime).
  * **Frontend:** Vue.js 3 estándar modularizado mediante **ES Modules** nativos (`type="module"`), consumiendo directamente la API REST con `fetch()`. Cero empaquetadores o dependencias pesadas (`node_modules/` ausente en runtime). La única librería de terceros es **Chart.js 4.x**, copiada en `public/assets/js/vendor/` y precacheada por el Service Worker: sin CDN, sin `npm` y sin red externa (Art. IV.3).
* **Dualismo Lingüístico Estricto:**
  * **Inglés:** Clases, métodos, variables, contratos de API, base de datos, suites de pruebas y commits (*Conventional Commits*).
  * **Castellano:** Documentación de negocio, análisis funcional, mensajes de validación y diálogo de cara al usuario final.

---

## 🧩 Módulos del Sistema

### 1. Núcleo Operativo de Incidencias (MVP)
* **Portal de Responsable de Sede:** Catálogo visual de máquinas del centro, reporte guiado de averías con subida de imágenes, bitácora de evidencias y reapertura justificada dentro de garantía de 48h.
* **Panel de Triaje y Coordinación 24/7:** Bandeja global en tiempo real con banner de advertencia visual para averías críticas sin asignar (> 60 min), asignación técnica con justificación de urgencia y descarte lógico (*Soft Delete*).
* **Modal de Detalle Integral en Triaje:** Ficha completa de solo lectura de cada avería (máquina y sede, bitácora de evidencias, piezas, SLA y cronología de auditoría con atribución) conforme a la especificación [`specs/09-incident-detail-modal/`](specs/09-incident-detail-modal/spec.md).
* **Vista Móvil "Mi Ruta" para Técnicos:** Interfaz vertical optimizada para smartphone (uso con una sola mano), inicio de intervención in situ, pausa estructurada por repuestos, resolución técnica justificada con declaración de piezas sustituidas y consulta de métricas individuales.
* **Cron Automatizado de Garantía:** Cierre definitivo de tickets resueltos transcurridas 48h sin reclamaciones (`/api/cron/auto-close`).
* **Barrido Automático de Cuarentena Sanitaria:** Consumo de fondo del Reloj Sanitario Biológico cada 15 min (`/api/cron/sanitary-clock-sweep` y `bin/sanitary_clock_sweep.php`): activa la cuarentena de las máquinas de frío con más de 4 h naturales sin frío confirmado **sin** esperar a un intento de cierre (RF-03.5.1, Art. II). La contención de fila frente al cierre técnico se publica como **aplazamiento** (`contended`), no como fallo: informa, no alerta, y se reintenta en la pasada siguiente. La pasada corre además con una **cota de espera de bloqueo de 2 s** —medida contra la transacción real del cierre (~787× su p95)— de modo que un tenedor que se alarga aparece como aplazamiento visible en lugar de dejar el lote parado.

### 2. Ecosistema de Códigos QR Físicos
* **Generador Nativo de QR en SVG:** Renderizado vectorial puro conforme a la especificación ISO/IEC 18004 con corrección de errores Reed-Solomon (Nivel M/Q).
* **Diseño e Impresión de Etiquetas Industriales:** Modales de previsualización e impresión de etiquetas resistentes para máquinas (formatos compacto, estándar y grande), con indicación de zona de máquina, aviso sanitario de perecederos y teléfono de asistencia.
* **Impresión Masiva por Sede (*Batch Print*):** Generación en lote de todas las etiquetas de un centro para su pegado en ruta.
* **Escaneo y Reporte Ciudadano:** Escaneo directo con cámara móvil (`?qr=...` o `?code=...`) que precarga la máquina y permite reportar incidencias en < 30 segundos sin necesidad de login previo.

### 3. Métricas de Rendimiento, SLAs y Auditoría Inmutable (Módulo 03)
* **Cuadro de Mando de KPIs Globales:**
  * **MTTR Promedio Global** (tiempo medio de resolución 24/7 en minutos y horas) con cálculo de variación de tendencia porcentual frente al periodo equivalente anterior.
  * **Monitor de Alimentos Perecederos (SLA 4h, Art. II):** Evaluación en tiempo real de cumplimiento contractual (*Compliant* vs *Breached*).
  * **Tasa de Resolución Formal:** Porcentaje de incidencias resueltas frente a creadas y control de *backlog* activo.
* **Desglose Multidimensional Combinable:** Análisis cruzado por Sede, Técnico resolutor, Tipología de máquina y Categoría de avería con detección de entidades inactivas. Incluye el indicador de **reintervenciones por garantía 48 h** por técnico (EARS 2.3.1): averías reabiertas dentro de la ventana del Art. V.6, atribuidas al técnico que firmó la resolución efectiva, como evidencia de reincidencia para auditoría de calidad (no es carga pendiente ni altera el MTTR original, EARS 1.3).
* **Autoconsulta Segregada del Técnico (Principio de Mínimo Privilegio, Art. V.4):**
  * Consulta protegida de MTTR personal, averías resueltas en el período, incidencias actualmente en curso y tiempo medio de primera respuesta técnica. Bloqueo estricto de acceso a métricas globales o de otros compañeros.
* **Visor del Registro Inmutable de Auditoría (*Append-Only*):**
  * Tabla `audit_log` con trazabilidad permanente de creación, asignación, inicio, repuestos y resoluciones.
  * Visor cronológico con filtros reactivos (entidad, acción, usuario, fechas), paginación y modal de inspección del payload JSON completo.
* **Exportación y Reportería Ejecutiva:**
  * **Descarga CSV con UTF-8 BOM:** Exportación instantánea de métricas agregadas y registro de auditoría (acotado por seguridad a 10.000 filas).
  * **Informe Resumen Ejecutivo Imprimible:** Maquetación lista para impresión física o PDF en formato A4 (`metrics-print.css`), limpia de menús y barras laterales.

### 4. Administración Integral del Parque (Módulo 04)
* **CRUD de Sedes Clientes:** Alta, edición, baja lógica y reactivación de centros con aislamiento de datos por sede.
* **CRUD de Máquinas:** Gestión del parque con tipologías normativas, planta/ala de ubicación y transferencias entre sedes auditadas.
* **CRUD de Personal Interno:** Altas de coordinadores y técnicos con operador oficial, reseteo de contraseñas y bajas lógicas con triple salvaguarda. La carga del directorio reporta solo averías pendientes de trabajo (`ASSIGNED`/`IN_PROGRESS`/`PENDING_PARTS`, Decisión QA 2) junto a un contador informativo de averías en garantía 48 h (solo lectura, no bloquea la baja); el bloqueo de baja comparte el mismo criterio.

### 5. Mantenimiento Preventivo y Certificación Sanitaria (Módulo M1)
* **Configuración de frecuencias normativas por tipología de máquina** (perecederos cada 15 días, Art. II) con alertas de vencimiento y generación automática de órdenes.
* **Ciclo completo de órdenes preventivas:** creación, asignación, reclamo técnico, inspección con checklist normativo por puntos de control, reinspección y cierre.
* **Dictamen de inspección con cuarentena sanitaria automática** para máquinas no conformes (sin levantamiento automático: exige certificado conforme).
* **Certificados Sanitarios Oficiales Individual y Global de Sede**, imprimibles en A4, con semáforo higiénico y Código de Operador Técnico Oficial (Art. V.4).

### 6. Catálogo de Repuestos y Trazabilidad de Piezas (Módulo M2)
* **Catálogo Maestro por Modelo de Máquina:** códigos únicos, categorías técnicas, fabricante, coste de referencia y compatibilidades múltiples con baja lógica preservando histórico (Art. III).
* **Pausa técnica estructurada:** selección de piezas compatibles con el modelo intervenido (sin texto libre) o pieza fuera de catálogo con justificación mínima de 20 caracteres.
* **Resolución con declaración obligatoria de sustitución (Sí/No):** registro de piezas instaladas, cantidades (1–50), destino reglamentario `DESGUACE`/`TALLER` y **congelación del coste unitario (*snapshot* inmutable)**.
* **Coexistencia con preventivos (M1):** consumo de piezas en inspecciones sin levantar cuarentenas sanitarias (Art. II).
* **Analítica de taller:** ranking de piezas, costes acumulados por modelo/sede, alertas de fallo crónico (> 3 sustituciones en 90 días en la misma máquina), bandeja de homologación de piezas fuera de catálogo y exportación CSV.
* **Blindaje constitucional Art. V.4 certificado por suite automática** (`SiteManagerPartsDataSegregationTest`).

### 7. Mapa Interactivo de Rutas y Georreferenciación Territorial (Módulo M4)
* **Proyección Cartográfica Web Mercator Pura (EPSG:3857):** Renderizado de teselas estándar (OpenStreetMap / IGN) mediante matemática vectorial pura en JavaScript ESM (`Mercator.js`), con encuadre dinámico adaptativo (*bounding box*) y controlador gestual fluido (`MapZoomPan.js`) de rueda, doble clic, arrastre táctil y *pinch-to-zoom* con elección de tesela *pixel-aware* para evitar distorsiones en pantallas panorámicas.
* **Optimización Heurística de Ruta de Campo en 4 Fases:** Secuenciación determinista sin servicios externos de pago de enrutamiento:
  1. Parada actualmente en curso (`IN_PROGRESS`) inamovible como primera parada (`#1`).
  2. Paradas críticas de alimentos perecederos ordenadas por proximidad al vencimiento de SLA (< 4h).
  3. Paradas ordinarias optimizadas mediante heurística geométrica de *Nearest Neighbor* (Vecino Más Cercano) calculada con trigonometría esférica de Haversine (`GeoDistanceService.php`).
  4. Exclusión automática de incidencias en espera de repuestos (`PENDING_PARTS`).
* **Modal Cartográfico Móvil para Técnico:** Visualización interactiva en smartphone con marcadores circulares numerados correlativos (1, 2, 3...), códigos semánticos de color (crítico, ordinario, en curso, preventivo), ficha contextual al tocar el marcador o la lista, recentrado reactivo (`focusOn`), y botones de navegación GPS directa mediante enlaces universales de Google Maps.
* **Cuadro Territorial de Coordinación:** Pestaña "Mapa Territorial" con vista panorámica de centros en ruta, matriz de averías activas por severidad, detección de concurrencia multi-técnico y reencuadre interactivo.
* **Geocodificación Asistida de Sedes:** Selector y validación de coordenadas latitud/longitud en la administración de sedes con geocodificación abierta para sugerir ubicaciones automáticamente.
* **Blindaje Constitucional de Privacidad (Art. V.4):** Imposibilidad estricta de acceso al mapa y a la posición de las rutas por parte de los Responsables de Sede, y prohibición de rastreo continuo en segundo plano del personal técnico (`SiteManagerRouteDataSegregationTest`).

### 8. Gestión de Reintegros e Importe Retenido / Dinero Tragado (Módulo M5)
* **Implementación Completa Certificada:** 8 suites específicas en verde (flujo del coordinador, entrega en sede, inspección técnica, seguimiento público, segregación Art. V.4, repositorio y extremo a extremo) sobre la especificación formal [`specs/functional/refunds_spec.md`](specs/functional/refunds_spec.md), los contratos técnicos [`specs/technical/refunds_contracts.md`](specs/technical/refunds_contracts.md), el plan de arquitectura [`specs/08-refunds/plan.md`](specs/08-refunds/plan.md) y el desglose de tareas [`specs/08-refunds/tasks.md`](specs/08-refunds/tasks.md).
* **Protocolo de Reclamación Ciudadana y PIN Secreto:** Solicitud opcional de reintegro en el reporte QR por fallo de pago o dinero tragado, con generación de PIN secreto de 4 dígitos para entrega presencial en conserjería o transferencia digital (Bizum/IBAN validado nativamente mediante Módulo 97 ISO 7064). Seguimiento ciudadano anónimo por token con rectificación de datos de cobro, y freno antifuerza del PIN: bloqueo temporal del expediente (`423 PICKUP_PIN_LOCKED`) tras cinco intentos fallidos de entrega.
* **Custodia y Desacoplamiento (Art. II y III):** Dictamen económico desacoplado de la resolución técnica del ticket, custodia central forzada para importes $> 10,00\ \text{€}$ y bandeja de coordinación para liquidación administrativa con registro inmutable.

### 9. Hilo de Conversación del Expediente y Evidencias Selladas (Módulo 10)
* **Conversación bidireccional sobre el propio expediente:** sede, técnico y coordinación dialogan en un único hilo auditable, sin canales paralelos, con contadores de conversación segregados por rol y paginación por cursor para históricos extensos (RF-01).
* **Privacidad del técnico y notas internas (Art. V.4):** el portal de sede **jamás** recibe notas internas de taller ni la identidad del técnico; el técnico y la coordinación ven el hilo íntegro, y la interfaz móvil preselecciona «Nota Interna de Taller» para las anotaciones de campo.
* **Evidencias fotográficas endurecidas (Art. III y V.5):** el servidor valida la cabecera real del archivo y el límite de **5 MB**; un fichero que no sea un gráfico válido se rechaza con `422` **sin dejar residuo en disco**.
* **Sellado y trazabilidad (Art. III):** cada publicación deja su evento inmutable `INCIDENT_COMMENT_ADDED` con autoría, y el hilo de un expediente cerrado queda sellado a nuevas publicaciones conservando la ventana de garantía de 48 h.
* **Señalización visual y resiliencia:** los contadores de conversación activa aparecen en la tarjeta de máquina y en el triaje sin filtrar contenido, y un envío fallido avisa y conserva el borrador en pantalla (RF-07).

### 10. Pausa Contractual de Espera de Sede (Módulo 11)
* **Estado operativo `PENDING_INFO` («Pendiente de información»):** el técnico o el coordinador aparcan la avería cuando falta acceso al inmueble o información de la sede, con causa tipificada y motivo de ≥ 20 caracteres. La pausa **congela el reloj contractual de SLA** y su vencimiento se desplaza exactamente los segundos naturales de la espera (24/7).
* **Reanudación siempre humana y coherente:** la reactivación automática por comentario de sede quedó derogada; sin técnico asignado el expediente vuelve a `REGISTERED` (cola de asignación) y **nunca** a `ASSIGNED`/`IN_PROGRESS`, para no declarar un responsable inexistente.
* **Cancelación supervisada por inactividad:** superadas **72 horas hábiles** de silencio, la coordinación puede cancelar el expediente de forma justificada sin que la máquina vuelva a figurar como «Operativa», desvinculando con seguridad los reintegros pendientes (Art. V.1 y V.2).
* **Doble reloj sanitario (Art. II):** sobre máquinas perecederas y mixtas corre en paralelo el Reloj Sanitario Biológico — **4 horas naturales** sin frío confirmado activan la cuarentena y bloquean el cierre hasta declarar temperatura real, retirada y destrucción del stock y checklist de higienización.
* **Barrido de fondo y su superficie operativa:** la activación automática no espera a un intento de cierre (`php bin/sanitary_clock_sweep.php` o `POST /api/cron/sanitary-clock-sweep` cada 15 minutos, con cota de espera de bloqueo de 2 s y la contención publicada como aplazamiento, nunca como fallo); la pestaña *🩺 Barrido Sanitario* publica el latido, la tendencia de 24 h y el **escalado cuando el aplazamiento persiste** (RF-07).
* **Segregación pública y transición prohibida (Art. V.4 y V.1):** el escaneo ciudadano muestra un estado neutro («En proceso de atención técnica») y **nunca** el motivo de la pausa, y queda prohibido cerrar directamente desde `PENDING_INFO` sin registrar la intervención física.

### 11. Persistencia Cloud Administrada en PostgreSQL / Supabase (Módulos 12 y 13)
* **Arquitectura Desacoplada con Motor Único (Coste 0 €/mes):** El backend puro PHP 8.2+ y el frontend reactivo Vanilla Vue 3 operan de forma homogénea contra PostgreSQL 16+ administrado en Supabase a través del agrupador transaccional Supavisor (puerto 6543) bajo TLS `verify-full`, y en local contra el motor PostgreSQL del arnés de pruebas sin dependencias externas.
* **Blindaje Constitucional Nativo en PostgreSQL:** Disparadores `BEFORE DELETE` con emisión ruidosa de `SQLSTATE 45000` (`ART_III_1_VIOLATION`, Art. III.1); índice único parcial `uq_machine_active_ticket` que bloquea duplicados concurrentes e incidencias en garantía (Art. V.2 y V.6); restricciones `CHECK` de justificación mínima $\ge 20$ caracteres (Art. V.1); y claves canónicas de identidad (`email_key`, `site_code_key`) para unificación de colaciones (Art. V.4).
* **Rendimiento Transaccional Certificado (RNF-01):** Optimización integral de round-trips mediante consultas CTE combinadas en inserción y actualización, reducción de consultas redundantes y lectura bloqueada en un solo salto (`FOR UPDATE OF i`), garantizando medianas inferiores al presupuesto estricto de 250 ms en todas las operaciones del sistema sobre enlaces de red transcontinentales.
* **Resiliencia Operativa y Keep-Alive Diario:** Flujo de mantenimiento automatizado para prevenir la suspensión por inactividad tras 7 días en planes gratuitos y restauración desatendida mediante API de administración.

### 12. Centro de Notificaciones Transaccionales y Alertas de Emergencia (Módulo 14)
* **Bandeja de Salida Transaccional (*Transactional Outbox Pattern*):** Cola persistente y auditable en PostgreSQL (`notification_outbox`) con seis estados canónicos (`PENDING`, `PROCESSING`, `PENDING_RETRY`, `SENT`, `FAILED_PERMANENT`, `CANCELLED_OBSOLETE`), prioridades normativas (`CRITICAL_SANITARY`, `HIGH`, `STANDARD`) y política de obsolescencia automática (RF-01 a RF-03).
* **Despachador y Política de Reintentos Progresiva:** Entrega desacoplada mediante worker CLI (`bin/send_notifications.php`) o endpoint HTTP protegido (`POST /api/cron/notifications-dispatch`), con reintentos exponenciales acotados a 5 intentos (1 min, 5 min, 15 min, 60 min), rescate automático de envíos atascados (> 5 min) y salvaguarda de SLA sanitario (alertas automáticas a las 2h sin asignar y 3h sin iniciar).
* **Canal Corporativo de Emergencias (Webhook con Validación Anti-SSRF):** Notificaciones instantáneas a plataformas de mensajería (Slack, Discord, Telegram) para averías críticas perecederas (Art. II). Validación estricta de URLs públicas mediante `WebhookUrlSecurityValidator` (prohibición de IPs privadas, loopback, link-local y resolución DNS segura contra ataques SSRF y DNS Rebinding).
* **Panel de Coordinación y Control Operativo:** Modal reactivo con filtros multidimensionales, KPIs de entrega en vivo, prueba interactiva de Webhook (timeout 5 s) y reintento/cancelación manual con confirmación contextual en línea (RF-06).

### 13. Alertas de Asignación a Técnicos vía Telegram (Módulo 15)
* **Aviso Personal de Asignación en Tiempo Real:** Notificación directa al smartphone del técnico de campo mediante Bot de Telegram (`POST https://api.telegram.org/bot{TOKEN}/sendMessage`) en cuanto se le asigna o reasigna una avería, sumándose al correo electrónico sin sustituirlo ni bloquear la asignación si no está configurado (RF-TG-01 a RF-TG-03).
* **Enlace Canónico Unificado a la Avería (*Deep Linking*):** Resolución centralizada de URLs absolutas (`AppPublicUrl`) que incluye botón interactivo inline en el mensaje de Telegram dirigiendo al técnico directamente a la ficha de la avería en su vista móvil (`?tech=ID&ticket=CODIGO`).
* **Vinculación Segura y Mínimo Privilegio (Art. V.4):** Campo `telegram_chat_id` gestionable exclusivamente para rol `TECHNICIAN` desde el panel de administración de usuarios, validado con regex numérico (`^-?[0-9]{5,20}$`), con unicidad estricta y enmascaramiento en auditoría (`***1234`).
* **Relé de Webhook Opcional en Cloudflare Workers:** Infraestructura serverless gratuita de relé en edge (`deploy/cloudflare/telegram-webhook-relay.js`) para transformar webhooks salientes genéricos de emergencia en mensajes formateados de Telegram sin costes ni servidores dedicados.

### 14. Avería Oportunista In Situ y Reingreso en Ruta (Módulo 16)
* **Atención In Situ de Averías No Programadas (Flujos A y B):** Permite al técnico en ruta gestionar avisos sobre la marcha cuando visita una sede:
  * **Flujo A (`NEW` — "Oportunista In Situ · Nueva"):** Alta de avería detectada in situ o comunicada verbalmente por el personal del centro (`opportunistic_origin_reason`: `TECHNICIAN_DETECTION` o `SITE_VERBAL_NOTICE`), naciendo directamente en estado `IN_PROGRESS` asignada al técnico que la reporta, con descripción técnica obligatoria ($\ge 10$ caracteres) y subida opcional de foto de evidencia ($\le 5\text{ MB}$, validada por magic bytes, Art. V.5).
  * **Flujo B (`ASSUMED` — "Oportunista In Situ · Asumida"):** Asunción directa de expedientes ya registrados pendientes en la sede (`REGISTERED`, `REOPENED`, `ASSIGNED`, `PENDING_PARTS`, `PENDING_INFO`), traspaso formal con nota interna de taller confidencial (`is_internal = TRUE`, Art. V.4), o reapertura in situ en garantía (< 48h) ante reclamaciones presenciales del centro.
* **Sustitución Atómica de 3.ª Reincidencia ("Avería Crónica", RF-05.4.2):** Al reabrir por tercera vez consecutiva una avería en garantía, el sistema cierra atómicamente el expediente anterior a `CLOSED` con la nota formal `[AVERÍA CRÓNICA]` (liberando el índice único parcial `uq_machine_active_ticket`), emite la alerta `CHRONIC_BREAKDOWN_ALERT` a Coordinación y genera un expediente sucesor limpio en `IN_PROGRESS` en la misma transacción (`T-OPP-13` con reintento ante colisión de código).
* **Salvaguarda Sanitaria de Cadena de Frío en Ruta (Art. II):** Si el técnico tiene en cola paradas pendientes críticas o de alimentos perecederos en otras sedes, el sistema exige confirmación expresa de anulación voluntaria (`confirm_cold_chain_override = true`) y encola de inmediato una alerta de emergencia sanitaria prioritaria (`OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT`) hacia el cuadro de mando de Coordinación y canal Webhook corporativo.
* **Ventana de Gracia de 30 Minutos y Experiencia en $\le 3$ Toques (RNF-02):** La operativa in situ está habilitada mientras el técnico tenga una parada activa en la sede, o durante una ventana de cortesía de **30 minutos** (+5 min con formulario abierto) tras resolver o pausar la última intervención. La vista móvil renderiza la tarjeta temporal **"🏢 Sede recién visitada (30 min)"** y un catálogo filtrado de máquinas para completar la intervención en menos de tres toques.
* **Coexistencia en Parada #1 y Blindaje de Privacidad (Art. V.4):** Las intervenciones simultáneas `IN_PROGRESS` de la misma sede conviven agrupadas en la Parada #1 de la ruta ordenadas por criticidad y SLA (prohibiendo iniciar en sedes distintas con error `409`). Se prohíbe terminantemente recabar datos bancarios (IBAN/Bizum) en la ficha técnica derivando al usuario al código QR público (`422 CASH_REFUND_PRIVACY_VIOLATION`), y se enmascara la identidad técnica en el portal de sede bajo el código oficial de operador.
* **Retorno de Inversión y Métricas de Eficiencia:** Cuadro de mando para Coordinación con registro de desplazamientos evitados (`opportunistic_interventions`), reduciendo la huella de carbono y el coste logístico de segundas visitas.

### 15. Zonificación Operativa y Asignación Asistida (Módulo 17)
* **Demarcaciones por Código Postal con Herencia Automática:** Zonas operativas con nombre, código y color corporativo `#RRGGBB`, delimitadas por códigos postales de cinco dígitos. Una sede hereda la zona de su CP sin intervención manual; la excepción manual (`is_manual_override`) se conmuta de forma atómica y auditada, y el panel de sedes señala con la insignia «Sin Zona Asignada» los centros huérfanos.
* **Cuadrante de Cobertura con Titular Único:** Cada técnico puede tener una zona principal y varias suplencias; el índice parcial `uq_technician_primary_zone` impide dos titularidades vivas simultáneas y la degradación de la anterior deja rastro en auditoría (`TECHNICIAN_ZONE_PRIMARY_DEMOTED`).
* **Cuadrante Legible y Lectura Inversa:** El cuadrante de una zona se lee bajo demanda con la identidad de cada técnico —nombre, código de operador, titular o suplente, guardia, alta y estado— y la baja se ejecuta desde la propia fila, no desde un selector con todo el directorio. La pestaña territorial ofrece además la vista inversa «Por técnico», que enumera las zonas vivas de cada profesional y señala a quien no cubre ninguna (RF-02.3 / QA 15).
* **Motor de Sugerencia Explicada (RF-04 / RF-05):** `TechnicianAssignmentSuggester` pondera afinidad territorial, presencia in situ, paradas en la misma zona, carga viva, pausas por falta de acceso y guardia sanitaria 24/7 para devolver un ranking con desglose de puntos. En zonas desatendidas —sin cuadrante activo o con todo él en el cupo de diez paradas vivas— ordena por distancia Haversine (parada actual, última resuelta o Base Central) y desempata por mayor tiempo desde la última asignación formal, sin excluir a los técnicos del cupo del ranking.
* **Despacho en Dos Toques y Salvaguarda de Frío (Art. II / Art. V.1):** El panel de triaje destaca al primer puesto y permite asignarlo con `[⚡ Asignar Sugerido]`. Si se descarta al técnico de guardia ante una avería de frío, exige `override_justification` de al menos 20 caracteres reales, auditada como `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN` sin alterar el `reassignment_reason` del flujo de reasignación.
* **Mapa Territorial por Zona y Privacidad de Sede:** El mapa de coordinación filtra y tiñe los centros con el color de su demarcación resolviéndolo en cliente (el contrato cartográfico no admite parámetros nuevos). El portal de sede jamás recibe el nombre, código ni color de la zona del cliente (Art. V.4).
* **Transferencia Atómica de Códigos Postales:** Mover un CP entre zonas actualiza la demarcación, bloquea con `FOR UPDATE` las sedes heredadas, reasigna su `zone_id` e inscribe el evento matriz más un evento individual por sede en la misma transacción, con rollback completo ante cualquier fallo.

---

### 16. Stock de Repuestos en Furgoneta (Módulo 18)
* **Inventario por técnico y pieza:** cada furgoneta mantiene su saldo en `technician_van_stocks` sobre la pareja única `(técnico, pieza)`, con cantidad disponible y umbral mínimo. Coordinación fija el saldo absoluto y cada ajuste deja el delta en auditoría; el técnico consulta su stock en solo lectura desde la ruta.
* **Descuento automático al intervenir:** declarar una pieza del catálogo al resolver una avería —correctivo o preventivo— descuenta una unidad del saldo. La pieza fuera de catálogo no descuenta y el inventario **nunca** bloquea el cierre.
* **Descuadres y alerta de bajo umbral:** el saldo negativo se admite y se marca como descuadre; la bandeja de Coordinación ordena las referencias por severidad para reponerlas. El albarán de reposición, el picking y la valoración monetaria pertenecen al Módulo 23.

---

### 17. Firma Digital del Responsable de Sede y Albarán de Servicio (Módulo 19)
* **Dos Vías Excluyentes en el Cierre:** El modal de resolución exige la conformidad de la sede. O se recoge la firma manuscrita digitalizada —nombre del firmante, documento y trazo sobre lienzo— o se atestigua «No había responsable disponible en la sede» con un motivo de al menos 10 caracteres. Activar una vía vacía los datos de la otra, y un bloque incompleto bloquea el envío señalando el campo.
* **Captura Táctil Real:** El trazo se sigue por eventos `pointer` con captura de puntero, de modo que el dedo puede salir del lienzo sin cortar la firma ni arrastrar la página (`touch-action: none`). El lienzo se pinta blanco opaco y se escala por `devicePixelRatio`, así que la firma no sale borrosa en pantallas de alta densidad. Borrar el trazo descarta el PNG y **no** toca el nombre ni el documento.
* **Tinta Decidida en el Cliente:** Menos de 150 píxeles con luminancia inferior a 200 es un lienzo en blanco. Es la única comprobación de tinta del sistema: el servidor no decodifica píxeles. El servidor sí valida el PNG real (firma binaria, MIME con `finfo`, `IHDR`), con techo de 300 KiB y 2 Mpx.
* **Atomicidad e Inmutabilidad:** La hoja se escribe en la misma transacción que piezas, furgoneta, solicitudes, transición a `RESOLVED` y notificación: si algo falla, nada de lo anterior queda. La tabla no admite `UPDATE` ni `DELETE`; una corrección exige una resolución nueva.
* **Documento Congelado:** `signed_document` guarda ticket, fecha de resolución, sede, máquina, técnico, diagnóstico, acción y piezas con nombre y cantidad en el instante del cierre. Una sede renombrada o una pieza recatalogada después no alteran el papel ya firmado.
* **Albarán A4 Imprimible:** Se compone en el navegador con `window.print()` y una hoja de estilos A4 que oculta la interfaz, admite varias páginas y nunca parte el bloque de firma. Sin costes, notas internas, SLA ni saldos. Tras una reapertura, el albarán vigente es el del último cierre; las visitas anteriores se consultan como históricas con su fecha, y una avería cerrada sin firma dice «Sin firma del responsable» sin reutilizar la firma anterior.
* **Acceso y Trazabilidad:** El técnico lee si la avería está asignada a él o si firmó alguna de sus hojas; coordinación lee cualquiera. El resto recibe `403 SERVICE_SHEET_FORBIDDEN`. Cada lectura deja un evento `VIEW_SERVICE_SHEET` y cada cierre registra si hubo firma, quién firmó y cuándo —nunca el trazo— o el motivo de ausencia.

---

### 18. Encuesta de Satisfacción Post-Resolución (Módulo 20)
* **Un voto por episodio:** `incident_satisfaction_surveys` se indexa por `(incident_id, resolution_at)`, de modo que una reapertura abre un episodio nuevo sin sobrescribir el anterior. La encuesta y su evento de auditoría se escriben en la misma transacción y la tabla no admite correcciones posteriores.
* **Valoración en el Portal de Sede:** modal de 1–5 estrellas y comentario de hasta 500 caracteres, con aviso «Tiene N servicios por valorar» sobre el `pending_total` real y refresco tras cada envío. Un `409` retira el episodio ya valorado y un fallo de red conserva lo escrito.
* **Lectura en el Cuadro de Mandos:** bloque CSAT con media, respuestas, distribución 5→1, tabla por sede, tabla por técnico con nombre congelado y tendencia mensual, siempre bajo el filtro temporal vigente. Sin filtro territorial ni exportación; el técnico solo ve su superficie de lectura.

---

### 19. Ausencias Temporales y Relevo de Fin de Turno (Módulo 21)
* **Ausencias sin dar de baja la cuenta:** `technician_absences` registra vacaciones, bajas, formación o fuera de turno con ventana temporal, sin tocar `users.is_active`. El recomendador territorial marca al titular `NO_DISPONIBLE` y asciende al suplente de zona a recomendado.
* **Jornada móvil con turno partido:** `technician_shifts` admite varias sesiones diarias y turno partido mediante el índice parcial de turnos abiertos; el técnico alterna `ON_SHIFT`, `MEAL_BREAK` y `OFF_SHIFT`, con cierre directo desde la comida.
* **Traspaso atómico y salvaguarda sanitaria:** cerrar turno exige resolver las averías `IN_PROGRESS`; los tickets `ASSIGNED`, `PENDING_PARTS` y `PENDING_INFO` se transfieren en una transacción con `FOR UPDATE`, nota interna obligatoria y `409` ante colisión. Si una máquina perecedera se queda sin relevo manual, el protocolo escala a la guardia 24/7 o desasigna a `REGISTERED` con alarma crítica (Art. II). El cómputo de horas pertenece al Módulo 22.

---

### 20. Registro de Jornada y Horas Efectivas (Módulo 22)
* **Bitácora inmutable:** `technician_shift_logs` registra `START_SHIFT`, `PAUSE_MEAL`, `RESUME_SHIFT`, `CLOSE_SHIFT`, `FORCE_CLOSE_SHIFT` y `ADJUST_SHIFT` sin `UPDATE` ni `DELETE`; las pausas se acumulan al segundo y se conservan los instantes originales aunque el turno se rectifique.
* **Informe de horas:** por periodo, el cuadro publica horas brutas, comida, horas efectivas netas, intervención in situ, tiempo en ruta con suelo cero, intervenciones resueltas, turnos de más de 12 h y turnos abiertos huérfanos. El cierre forzado admite una hora efectiva de salida que fecha el protocolo sanitario con el instante real.
* **Rectificación auditada y acceso propio:** la corrección exige validación cuádruple y motivo de 20 caracteres reales, la anulación deja el registro a `0s` sin borrarlo y el CSV sale en UTF-8 BOM con dos secciones en `Europe/Madrid`. La sub-pestaña «⏱️ Registro de Jornada y Horas» y «⏱️ Mis Horas» se aíslan por el `user_id` del token (Art. V.4).

---

### 21. Órdenes de Carga y Cruce de Stock en Triaje (Módulo 23)
* **Ciclo de la orden:** `PENDING_PICKING` → `DISPATCHED` → `CONFIRMED_BY_TECH`, con `CANCELLED` como salida justificada; solo puede existir una orden abierta por técnico, las líneas se dan de baja lógicamente (Art. III.1) y la cancelación exige 20 caracteres reales (Art. V.1).
* **Propuesta y picking:** la consola de stock propone reposición por umbral y por averías con solicitud de pieza, genera órdenes en lote excluyendo ausentes y órdenes abiertas, y emite el Albarán de Picking A4 sin importes (Art. V.4). La recepción móvil en un toque incrementa el stock de forma transaccional y deja el evento en auditoría.
* **Cruce informativo en triaje:** el recomendador evalúa el stock en dos niveles —solicitudes pendientes del expediente o referencias compatibles con modelo y categoría— y publica `van_stock_match` con su insignia. Los saldos negativos valen cero y el bloque **no altera** la puntuación territorial ni la prioridad sanitaria (Art. II).

---

### 22. Modo Sin Conexión e Instalabilidad Móvil (Módulo 24)
* **PWA instalable y app shell precacheado:** manifest e iconos SVG propios, Service Worker con precache del shell completo y **Network-Only estricto** para `/api/*` y métodos no GET: la API jamás se sirve desde caché (Art. V.4).
* **Copia local con vigencia:** `IndexedDB` guarda el paquete de ruta aislado por `technician_id`, fresco hasta 4 h, con aviso ámbar hasta 12 h y solo lectura a partir de ahí.
* **Cola idempotente y tiempo real:** las 9 operaciones de parada se encolan en FIFO con `client_mutation_id` y doble sello temporal; Δt se preserva aunque el reloj del móvil esté desajustado, y el precinto físico in situ es obligatorio ante riesgo sanitario (Art. II).
* **Conflictos sin pérdida:** la cola admite 5 fotografías / 25 MB (la firma PNG queda exenta), el volcado en un toque se archiva como Nota Interna de Taller —incluidas averías canceladas, cerradas > 48 h y órdenes preventivas— y la retención de la cuarentena sanitaria a las 4 h es innegociable. El cierre de sesión se bloquea con cola viva, la re-autenticación exige el mismo técnico y la baja de cuenta vuelca y limpia el dispositivo.

---

### 23. Taxonomía de Causas Raíz y Análisis Pareto 80/20 (Módulo 25)
* **Catálogo maestro gobernado por Coordinación:** 16 causas raíz y 34 soluciones estandarizadas repartidas en las cinco categorías técnicas, con código y categoría inmutables, baja lógica sin borrado físico y una única solución predeterminada activa por causa. El alta es atómica con su primera solución, las guardas impiden dejar una causa activa sin predeterminada y cada cambio registra en auditoría el estado anterior y el nuevo (Art. III.3).
* **Captura en la resolución:** el selector está en la vista del técnico de ruta, en la avería oportunista y en el cierre desde Coordinación; prioriza las causas de la categoría del aviso, ofrece «Mostrar todas» y preselecciona la solución predeterminada. Es obligatorio en la interfaz y en toda llamada que envíe taxonomía o `require_taxonomy` —las llamadas históricas sin claves conservan la compatibilidad del contrato §4.3—, sin rellenar ni sumar caracteres a los dos textos libres de 20 caracteres (Art. V.1).
* **Copia congelada y rastro de reaperturas:** el cierre guarda las referencias y cinco snapshots (código, categoría y nombre de la causa; código y nombre de la solución). La ficha y el historial técnico muestran esa etiqueta congelada; una reapertura en garantía (< 48 h) actualiza el cierre vigente conservando en la línea de tiempo la clasificación anterior. La retirada por 3.ª reincidencia crónica queda exenta y el volcado de una resolución sin conexión preserva la pareja declarada in situ.
* **Diagrama de Pareto 80/20:** la pestaña **🔍 Pareto Causas Raíz** del cuadro de mando parte de las averías resueltas/cerradas por fecha de resolución vigente, cuenta un único voto por expediente y calcula porcentajes a dos decimales que cierran en `100,00 %`; la Zona Vital alcanza la primera causa que cruza el `80,00 %`. Cruza averías, MTTR en horas, unidades y coste de repuestos y solución más aplicada, con filtros por periodo, fabricante normalizado, modelo exacto y categoría, contador separado de históricas sin clasificar y estado vacío seguro. La exportación CSV (UTF-8 BOM, dos secciones) incluye el nombre vigente y el congelado, y queda auditada.
* **Privacidad y mínimo privilegio (Art. V.4):** el catálogo, las etiquetas y el Pareto están vedados al Portal de Sede, al canal QR y al Albarán A4. Coordinación es el único rol que consulta el Pareto, exporta el CSV o gobierna el catálogo; el técnico solo lee el catálogo activo y las etiquetas de sus expedientes. Cualquier otro acceso responde `403`.
* **Motor y rendimiento:** tablas 35 (`root_causes`) y 36 (`standard_solutions`), siete columnas de taxonomía en `incidents` con `CHECK` todo-o-nada, índice parcial único de predeterminada e índice `idx_incidents_pareto_resolved`; disparadores de inmutabilidad sobre código y categoría. El gráfico combinado usa Chart.js 4.x servido localmente y precacheado (Art. IV.3), y el Pareto queda certificado por debajo de 500 ms con 10.000 incidencias.

---

### 24. Historial Completo de Reasignaciones y Cadena de Custodia (Módulo 26)
* **Tramos inmutables con snapshots de identidad:** `incident_assignments` (tabla 37) abre un tramo por asignación y lo cierra con `unassigned_at`, `unassigned_by` y motivo de 1 a 1000 caracteres; congela el nombre y el código del técnico y de quien asigna o desasigna, de modo que un cambio posterior de ficha no reescribe el histórico (Art. III.3). Un índice parcial único garantiza **un solo custodio técnico activo simultáneo** por avería (Art. V.3) y el borrado físico está prohibido (Art. III.1).
* **Apertura y cierre automático en las cuatro vías:** asignación, reasignación, desasignación y relevo de turno cierran el tramo vigente y abren el siguiente en la misma transacción; una colisión de asignación simultánea responde `409` y el tramo cerrado no admite reescritura.
* **Ficha de coordinación:** el detalle del expediente publica la línea de tiempo **«Cadena de Custodia»** con el tiempo de retención y la espera en bandeja, y las métricas de resumen por técnico. El portal de sede, el canal QR y el albarán no reciben ninguna identidad ni el histórico de custodia (Art. V.4).
* **Motor:** migración `022_incident_assignments_custody_chain.sql`, con los enumerados `incident_assignment_source` (9 canales) e `incident_unassignment_reason_type` (10 causas). La asignación sigue entrando por `PATCH /api/coordinator/incidents/{id}/assign`, que ahora deja el tramo escrito.

### 25. Bandeja de Taller Central para Piezas Recuperadas (Módulo 27)
* **Generación por unidad física:** cuando la resolución declara destino `TALLER`, cada pieza genera su fila en `workshop_repair_items` (tabla 38, 34 columnas) con el snapshot de coste y el vínculo al expediente y a la máquina de origen, sin agrupar piezas distintas en una sola línea.
* **Ciclo de taller con diagnóstico real:** la bandeja registra la recepción, el inicio en banco y el dictamen de resolución con diagnóstico obligatorio (≥ 20 caracteres reales), controla la concurrencia sobre la misma pieza y permite reincorporarla al inventario o declararla baja. Ni el técnico ve importes ni la sede ve la bandeja (Art. V.4).
* **Economía circular y recordatorio móvil:** el panel calcula el ahorro por reacondicionamiento frente a la compra, ordena la bandeja y exporta el CSV; el técnico recibe el recordatorio de la pieza pendiente de entregar en taller.
* **Motor:** migración `023_workshop_repair_items.sql`, con baja lógica, auditoría de cada transición y exportación tabular auditada.

### 26. Guías Rápidas de Diagnóstico por Modelo (Módulo 28)
* **Catálogo emparejado por modelo y categoría:** `machine_troubleshooting_guides` (tabla 39, 14 columnas) declara el modelo visible y su clave plegada (`machine_model_key`), la categoría de avería y los tres bloques `JSONB` acotados —códigos de error 0–20, pasos 2–10 y repuestos 0–10—, con una sola guía viva por pareja modelo/categoría (baja lógica, Art. III.1).
* **Uso en ruta:** el técnico conmuta el modelo cuando la ficha no lo declara y despliega la guía en acordeón; los repuestos de la guía se cruzan con el stock de su furgoneta, la guía enlaza con la causa raíz sugerida y se sincroniza en el paquete sin conexión. Queda excluida de las inspecciones preventivas y advierte de la cadena de frío en máquinas perecederas o averías de refrigeración (Art. II).
* **Gobierno en coordinación:** alta, edición y cambio de estado con instrucciones de ≥ 20 caracteres reales por paso, `CHECK` de tamaño de los bloques, inmutabilidad de modelo y categoría y auditoría del estado anterior y el nuevo (Art. III.3). Las semillas cubren los modelos reales del parque.
* **Motor:** migración `024_machine_troubleshooting_guides.sql`, con el enumerado de auditoría `MACHINE_TROUBLESHOOTING_GUIDE`.

### 27. Acta de Merma y Destrucción de Lotes por Cuarentena Sanitaria (Módulo 29)
* **Documento oficial e inmutable:** `sanitary_spoilage_records` (tabla 40, 22 columnas) emite un `record_code` único `ACTA-MERMA-AAAA-NNNN` que distingue al **técnico certificador in situ** del usuario que tramita el alta, y congela origen de la cuarentena (reloj biológico de 4 h, no conformidad preventiva o bloqueo sanitario manual), temperaturas de retirada y reapertura, método de destrucción, fotografía obligatoria (≤ 5 MB, Art. V.5) y observaciones de higienización de 20 a 1000 caracteres. Tras la emisión solo cabe el ajuste económico auditado (Art. III.1).
* **Coherencia de las dos ramas:** el acta declara máquina vacía o los lotes reales (3–500 caracteres alfanuméricos) con las unidades destruidas (0–500) y sus `CHECK` de coherencia; el levantamiento es atómico, desbloquea el QR de la máquina, determina el origen y conserva la relación `1..N` cuando la avería se reabre en la ventana de 48 h (Art. V.6).
* **Segregación financiera (Art. V.4):** el coste estimado y su ajuste son exclusivos de Coordinación, con motivo obligatorio y rastro de quién ajustó; la sede y el técnico no ven importes.
* **Superficie operativa:** pestaña **APPCC** de coordinación con la bandeja de actas, filtros y exportación CSV, histórico por máquina en la ficha técnica, y **Acta Oficial A4** imprimible con la firma de higienización. Migración `025_sanitary_spoilage_records.sql`, con el enumerado `SANITARY_SPOILAGE_RECORD`.

### 28. Perfiles de SLA Diferenciados por Tipo de Sede (Módulo 30)
* **Tres perfiles contractuales y matriz cruzada con la prioridad del aviso:** cada sede declara su `sla_tier` — **Estándar**, **Prioritario Corporativo** o **Infraestructura Crítica 24x7** — con `STANDARD` por defecto y rechazo `422 INVALID_SLA_TIER` ante un valor desconocido. Sin riesgo de cadena de frío, el objetivo de resolución se cruza con la urgencia: Estándar **8 h** (crítica) / **12 h** (alta) / **24 h** (media o baja); Prioritario Corporativo **6 / 8 / 12 h**; Infraestructura Crítica 24x7 **4 / 4 / 8 h**.
* **Techo sanitario innegociable (Art. II):** toda máquina de alimentos perecederos y toda mixta con avería `TEMPERATURE_COLD` o prioridad crítica se sella a **240 minutos (4,0 h)** con independencia del contrato y de la urgencia declarada.
* **Sellado inmutable y recálculo desde el origen:** el aviso congela al nacer `sla_tier_snapshot` (protegido por `trg_incidents_protect_sla_tier_snapshot`, que responde `23514 IMMUTABLE_SLA_TIER_SNAPSHOT`), `sla_target_minutes` y `sla_target_at`. Un cambio de contrato solo rige para avisos nuevos; un cambio de prioridad en triaje o una absorción QR que agrave el aviso recalcula el objetivo desde la hora original de creación. Las pausas imputables a la sede y el intervalo cerrado de una reapertura en garantía se descuentan **sin reiniciar** el reloj (Art. V.6).
* **Ruta con prelación sanitaria:** toda parada con vencimiento efectivo a **4,0 h o menos** sube al bloque crítico; dentro de él, las paradas con riesgo de cadena de frío (`critical_reason = 'COLD_CHAIN'`) van siempre por delante de las puramente contractuales (`'CONTRACTUAL_SLA'`).
* **Escalado contractual 24/7 en el recomendador:** sin titulares ni suplentes en turno en una sede Crítica 24x7 (o sin efectivo libre ante un aviso de 4 h), el técnico de guardia recibe un bono de **+60 puntos** y el motivo «Escalado contractual 24/7», siempre subordinado al bono sanitario de +100 (Art. II). Con objetivos de hasta 8 h y zona saturada (≥ 3 paradas vivas) ordena por menor carga y, a igualdad, por proximidad.
* **Cumplimiento ticket a ticket:** el desglose por sede del cuadro de mandos y su CSV publican el perfil contractual, el objetivo nominal de referencia (**24,0 / 12,0 / 8,0 h**), las brechas reales del periodo y el veredicto «Cumplido» / «Incumplido» / «Sin datos». El nivel de SLA se ve en Coordinación, en la ruta del técnico (también en el paquete sin conexión) y en el portal de cada sede para su propia ubicación; queda fuera del canal QR y de los documentos A4 (Art. V.4).
* **Motor:** migración `026_tiered_sla_contracts.sql`, con el enumerado `sla_tier`; `SlaTargetCalculator` centraliza la matriz y el techo sanitario. Certificado por `TieredSlaDomainTest`, `PostgresTieredSlaSchemaTest`, `TieredSlaContractsApiTest` y `TieredSlaFrontendTest.mjs`.

### 29. Rol de Cargador / Reponedor de Ruta y Cuadrante de Carga (Módulo 31)
* **Cuarto rol operativo `RESTOCKER`:** alta en el CRUD de personal con código de operador (`REP-01` y `REP-02` en las semillas), rol inmutable tras el alta (`409 ROLE_CHANGE_FORBIDDEN`) y prohibición de guardia técnica (`chk_users_restocker_not_on_call`, `422 RESTOCKER_CANNOT_BE_ON_CALL`). El reponedor dispone de su propia vista móvil `RestockerRouteView.js` —zonas asignadas, barra de jornada, contador del día en vivo y modal «⏱️ Mis Horas»— y las rutas exclusivas del SAT le responden `403`.
* **Zonificación de carga paralela a la del SAT:** `restocker_zone_assignments` (tabla 41) con `role_in_zone` (`PRIMARY`/`BACKUP`) e `is_primary` sincronizados: un único titularato vivo por reponedor (`uq_restocker_primary_zone`) y suplencias sin límite, con baja lógica (`removed_at`, Art. III.1), disparador anti-borrado y auditoría bajo la entidad `RESTOCKER_ZONE`.
* **Consolas de coordinación sin pestañas nuevas:** Zonas Operativas publica los contadores `primary_restockers_count` / `backup_restockers_count` y dos cuadrantes independientes (**«🔧 Cuadrante SAT (Técnicos)»** y **«📦 Cuadrante de Carga (Reponedores)»**), más la lectura inversa por operario; Ausencias, Turnos y Registro de Horas incorporan el filtro por rol (`Todos` / `Técnicos SAT` / `Reponedores`) y la columna de rol en la exportación CSV.
* **Jornada compartida con cierre propio:** turnos y ausencias usan el mismo motor que los técnicos, pero el reponedor cierra turno de forma directa, sin asistente de traspaso de averías, y sus ausencias promueven automáticamente al suplente de la zona. La cobertura en vivo de la zona resuelve seis niveles deterministas y señala al **#1 recomendado**.
* **Motor:** migración `027_restocker_role_and_zone_assignments.sql`, que amplía `user_role` con `RESTOCKER` y `audit_entity_type` con `RESTOCKER_ZONE`.

### 30. Catálogo Maestro de Productos y Planograma por Máquina (Módulo 32)
* **Catálogo comercial del parque:** `vending_products` (tabla 42) con `sku` único e inmutable, cinco categorías (`COLD_DRINK`, `SNACK`, `PERISHABLE_FOOD`, `HOT_BEVERAGE_INGREDIENT`, `CUP_STIRRER_CONSUMABLE`) y tres unidades de venta (`UNIT`, `GRAMS`, `SLEEVE`). Coste y precio acotados —el precio `0,00 €` solo en ingredientes y consumibles—, cadena de frío obligatoria en perecederos y vida útil máxima (`max_shelf_life_days`) para el control de caducidades (Art. II).
* **Planograma por máquina:** `machine_planogram_slots` (tabla 43) con capacidad (1–10.000), umbral de reposición estrictamente menor que la capacidad y existencia estimada dentro de ella; una fila viva por `(máquina, carril)`, baja lógica y semáforo de stock `STOCKOUT` / `LOW_STOCK` / `OK`.
* **Cerrojo sanitario bidireccional (Art. II):** el motor impide sembrar un carril con una categoría incompatible con el tipo de máquina o con producto perecedero que no exige frío, y también rechaza cambiar el producto o la tipología de la máquina si dejaría carriles activos incompatibles (`409 PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE` en `PUT /api/coordinator/admin/machines/{id}`).
* **Operativa de coordinación:** alta y edición de productos, alta/edición/baja lógica de carriles, presets por modelo normalizado (*Necta Korinto*, *Saeco Cristallo*, *Fas Fast 900*, *Jofemar Vision*) y clonado de un planograma entre máquinas.
* **Lectura del reponedor sin economía:** el reponedor ve el parque de sus zonas y el planograma en solo lectura, con filtro `stock_alert_only`, y **nunca** recibe coste unitario, precio ni margen (Art. V.4); una máquina fuera de sus zonas responde `403`.
* **Motor:** migración `028_product_catalog_and_planogram_slots.sql`, con dos tipos nativos nuevos y los disparadores de compatibilidad y frío.

### 31. Órdenes de Carga de Producto y Picking con Trazabilidad de Frío (Módulo 33)
* **Ciclo de la orden:** `PENDING_PICKING` → `DISPATCHED` → `CONFIRMED_BY_RESTOCKER` → `RECONCILED_AT_CLOSE`, con `CANCELLED` como salida justificada (≥ 20 caracteres reales, Art. V.1). Código único `PLO-AAAA-NNNN` y una sola orden activa por reponedor (`uq_product_loading_orders_active_restocker`).
* **Propuesta matutina configurable:** modo `ALERTS_ONLY` (solo carriles `STOCKOUT` / `LOW_STOCK`) o `ALL_GAPS` («Ruta completa»), colchón de seguridad `0..50 %` y descuento del stock útil no caducado de la furgoneta; excluye las máquinas en cuarentena o fuera de servicio y las declara en `excluded_unavailable_machines_count` (Art. II).
* **Despacho con trazabilidad de frío (Art. II):** pasar a `DISPATCHED` exige temperatura de salida de cámara entre **−5,0 y 4,0 °C** cuando hay líneas refrigeradas (`422 COLD_CHAMBER_TEMP_EXCEEDED`), lote real (`^[A-Z0-9_-]{2,40}$`) y caducidad futura que no supere la vida útil del producto (`422 EXPIRATION_EXCEEDS_MAX_SHELF_LIFE`), con inmutabilidad de la caducidad por lote (`409 BATCH_EXPIRATION_MISMATCH`).
* **Stock por lote y FEFO:** `restocker_van_product_stocks` (tabla 44) lleva el saldo por `(reponedor, producto, lote)` —pueden coexistir varios lotes del mismo producto— con semáforo `EXPIRED` / `EXPIRING_SOON` (≤ 48 h) / `OK`; el ajuste manual de Coordinación exige motivo de 20 caracteres reales y deja el saldo anterior y el nuevo en auditoría.
* **Recepción y liquidación:** la confirmación móvil en un toque suma las cantidades despachadas por lote; al cierre, la liquidación contrasta `stock − abastecidas en máquinas − mermas retiradas = retorno esperado` frente al contado, exige justificación si hay descuadre y puede ejecutarse desde el móvil o desde Coordinación. La visita in situ del Módulo 34 precarga las unidades abastecidas y retiradas.
* **Hoja de Picking A4 y privacidad:** dos bloques impresos («❄️ Cámara Frigorífica» y «📦 Almacén General y Consumibles») ordenados por urgencia y SKU, **sin importes** (Art. V.4); ninguna respuesta dirigida al reponedor publica coste ni margen.
* **Motor:** migración `029_product_loading_orders_and_cold_picking.sql` (tablas 44–46 y tres enumerados).

### 32. Visita de Reposición In Situ, Control FEFO y Limpieza Diaria (Módulo 34)
* **Trazabilidad de lote en el carril:** `machine_planogram_slots` gana `current_batch_code` y `current_expiration_date`; al completar una visita cada carril aplica `qty_after = qty_before − retiradas caducadas − retiradas dañadas + añadidas` y conserva la caducidad más próxima que siga viva para no perder la alerta FEFO.
* **Triple cerrojo sanitario (Art. II):** (1) bloquea el abastecimiento en cuarentena sanitaria, fuera de servicio o con avería térmica abierta (`409 SANITARY_RESTOCK_BLOCKED`); (2) exige lectura de cabina al abrir la puerta —**≤ 4,0 °C** en perecederos y mixtas, **≤ 8,0 °C** en bebidas frías— y, si se supera, cierra la visita como `ABORTED_THERMAL_BREACH` con precinto preventivo, fotografía (≤ 5 MB, Art. V.5) y nota de ≥ 20 caracteres, activa la cuarentena sanitaria —o el bloqueo de servicio en bebidas frías— y abre o escala al SAT de la zona una incidencia `CRITICAL` de categoría `TEMPERATURE_COLD`; (3) bloquea cargar un carril con remanente caducado o a ≤ 48 h sin retirarlo antes (`422 FEFO_WITHDRAWAL_REQUIRED`) y prohíbe gastar un lote de furgoneta más nuevo si hay otro sano que vence antes (`422 FEFO_OLDER_VAN_BATCH_AVAILABLE`).
* **Checklist de higiene adaptativo:** cuatro puntos en máquinas de café y mixtas (posos, cubo de residuos líquidos, batidores y conductos, bandeja y cristal) y dos en frías, snacks y perecederos (bandeja y cristal, derrames de cabina), con evidencia fotográfica obligatoria ante aborto térmico o retirada de unidades.
* **Ejecución atómica e idempotente:** tablas 47 y 48 (`machine_restock_visits` / `machine_restock_visit_items`) de **solo inserción** (sin `UPDATE` ni `DELETE`), idempotencia por `(restocker_id, client_mutation_id)` con respuesta `idempotent_replay = true`, descuento atómico de la furgoneta y cola `IndexedDB` para sótanos sin cobertura. Un conflicto diferido responde `409 OFFLINE_VISIT_CONFLICT` y puede volcarse sin bloquear el resto de la cola.
* **Merma comercial separada de la sanitaria:** la retirada por caducidad o envase dañado con frío correcto **no** abre cuarentena; congela `unit_cost_snapshot_eur` y `commercial_spoilage_loss_eur`, visibles solo para Coordinación (Art. V.4), y alimenta la liquidación del Módulo 33.
* **Superficie de coordinación:** sub-vista **«📋 Visitas de Reposición In Situ y Control FEFO»** con KPIs, filtros, detalle de visita y coste de la merma comercial; el reponedor solo accede a su histórico y a su propio stock.
* **Motor:** migración `030_in_situ_restock_visit_and_fefo.sql`, con el enumerado `restock_visit_status`.

---

## 👥 Actores del Sistema y Credenciales de Demostración

La aplicación cuenta con un conmutador de perfiles en la barra superior para alternar de forma inmediata entre roles:

| Perfil / Actor | Identificador / Email | Contraseña / Código | Función Principal |
| :--- | :--- | :--- | :--- |
| **🏢 1. Responsable de Sede** | `SEDE-BCN-01` *(Hospital del Mar)*<br>`SEDE-BCN-02` *(Torre Glòries)* | Código + `DEV-<site_code>` *(clave de desarrollo, ver nota)* | Supervisa máquinas del edificio, reporta averías (< 2 min), anexa evidencias y reabre en garantía. |
| **📊 2. Coordinador de Operaciones** | `coordinacion@vendguard.internal` | `Password123!` | Triaje central, supervisión 24/7 de SLAs, administración del parque y personal, preventivos y certificados sanitarios, catálogo y analítica de repuestos, cuadro de mando de métricas y visor de auditoría, gobierno del catálogo de causas raíz y análisis Pareto 80/20 de garantías. Supervisa además la pausa de espera de sede (reanudación manual y cancelación por inactividad), la superficie operativa del barrido sanitario, la cadena de custodia, la bandeja de taller, el catálogo de guías de diagnóstico, el libro APPCC de mermas, los perfiles de SLA por sede, el cuadrante de reponedores, el catálogo de productos y planogramas, las órdenes de carga de producto y las visitas de reposición in situ. |
| **📱 3. Técnico de Ruta de Campo** | `jordi.ruta@vendguard.internal`<br>`marta.ruta@vendguard.internal` | `Password123!` | Interfaz vertical *mobile-first*, gestión de averías en ruta, inicio de intervención, pausa estructurada por repuestos o por falta de acceso al inmueble (pausa de sede), resolución justificada con piezas sustituidas y causa raíz, guías rápidas de diagnóstico, hilo de conversación del expediente, inspecciones preventivas, consulta del historial de la máquina, insignia de SLA contractual de cada parada y métricas individuales. |
| **🚚 4. Cargador / Reponedor de Ruta** | `lucia.repostaje@vendguard.internal` *(REP-01)*<br>`oscar.repostaje@vendguard.internal` *(REP-02)* | `Password123!` | Vista móvil de reposición: zonas de carga, jornada y horas propias, planograma sin importes, recepción de la orden de carga, liquidación al cierre y visita in situ con control FEFO, checklist de higiene y cola sin conexión. |
| **🤳 5. Usuario / Consumidor Final** | Enlace QR (`?code=VEND-0101`) | *Público / Sin registro* | Reporte inmediato in situ escaneando la pegatina de la máquina, con feedback de estado si ya estaba reportada. |

> **Clave de centro de desarrollo (cierre de S-4).** En pruebas, las semillas emiten una clave determinista por sede; para el trabajo manual en local hay que pedirla de forma explícita (`VENDGUARD_DEV_SITE_KEYS=1 php bin/seed.php`). La regla es `DEV-<site_code>` (p. ej. `DEV-SEDE-BCN-01`). En producción el campo no se siembra jamás: cada sede recibe su clave aleatoria al alta o al reemitirse desde coordinación, se muestra una sola vez y solo se guarda su huella bcrypt. Sin clave emitida, el acceso de la sede falla en cerrado.

---

## 🚀 Puesta en Marcha Rápida

### Requisitos Previos
* **PHP:** 8.2 o superior con extensiones `pdo_pgsql` y `mbstring` habilitadas (la imagen oficial ya compila `mbstring`; el [Dockerfile](Dockerfile) instala `pdo_pgsql`).
* **Base de Datos:** PostgreSQL 16+ (Supabase en despliegue; motor PostgreSQL local en contenedor para desarrollo y pruebas).
* **Navegador:** Chrome, Edge, Firefox o Safari con soporte de módulos ES.

### 1. Configuración de Base de Datos y Semillas

#### Entorno Local (PostgreSQL):
```powershell
# Opción recomendada: inicialización canónica en un solo paso
# (DDL base, migraciones incrementales, catálogo maestro e histórico de demostración):
php bin/init_cloud_db.php

# O ejecutar migraciones de forma independiente:
php bin/migrate.php

# Cargar el histórico de demostración portable (17 averías canónicas INC-DEMO-*; diez de ellas llegan clasificadas para el Pareto):
php bin/seed_demo_history.php

# Sembrar averías activas repartidas entre técnicos para probar el filtro del Mapa Territorial
# (ocho averías INC-MAPA-*, una sede multi-técnico, una sin asignar y un técnico sin trabajo):
php bin/seed_demo_map.php
```

#### Entorno Cloud / Producción (Render + Supabase PostgreSQL):
El script [`bin/init_cloud_db.php`](bin/init_cloud_db.php) es el único punto de convergencia del esquema y no es tolerante a medias: aplica el DDL base (`database/supabase_init.sql`), **todas las migraciones incrementales** de `database/supabase_migrations/` (hoy 001–030, de ahí que una base ya desplegada converja en cada arranque), el catálogo maestro (`database/supabase_seeds.sql`) y el histórico de demostración. Todo está declarado con `IF NOT EXISTS` y semillas «insertar si no existe», así que reejecutarlo no duplica nada. Si cualquier paso falla, el script reintenta con espera progresiva y, agotados los intentos, **sale con código distinto de cero**: con el `&&` del arranque, el contenedor no levanta y no se sirve tráfico contra un esquema sin verificar (EARS-02.5).
```powershell
DATABASE_URL="postgres://usuario:clave@aws-0-<region>.pooler.supabase.com:6543/postgres?sslmode=verify-full" php bin/init_cloud_db.php
```

El histórico de demostración (las 17 averías `INC-DEMO-…` con su traza de estados y su auditoría) entra en **cada arranque y por defecto** (EARS-02.6): un redespliegue lo devuelve a su sitio sin configurar nada, y la pasada que reafirma un expediente desplaza su traza con las fechas del expediente —con `UPDATE`, nunca borrando, y sólo sobre la traza que sigue siendo la del fixture—. `VENDGUARD_DEMO_HISTORY=0` deja el arranque en limpio estricto, y una avería en garantía cuya máquina esté ocupada por otro aviso vivo se omite y se declara en lugar de tumbar el servicio.

### 2. Iniciar el Servidor Web Local
```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

### 3. Acceder a la Aplicación
Abre en tu navegador:
👉 **[http://127.0.0.1:8000/](http://127.0.0.1:8000/)**

### 4. Programar los Procesos de Fondo (Cron)

Los tres procesos batch del sistema pueden ejecutarse tanto desde el `crond` interno del contenedor ([`deploy/cron/vendguard-sanitary-sweep`](deploy/cron/vendguard-sanitary-sweep)) como desde un planificador externo por CLI o HTTP. La credencial HTTP es la cabecera `X-Cron-Secret` (variable de entorno `CRON_SECRET`; en desarrollo vale la clave local `vendguard-dev-cron-key-local-only`) y las tres rutas son fail-closed: sin la marca de `CronAuthMiddleware` responden `401`.

| Proceso | Cadencia recomendada | Comando CLI / Endpoint HTTP |
| :--- | :--- | :--- |
| **Despachador del Centro de Notificaciones** (Módulo 14, RF-01.3/RF-04/RF-06): rescata envíos atrapados en `PROCESSING` (> 5 min), vigila los umbrales de inminencia de SLA sanitario (2 h sin asignar / 3 h sin iniciar) y entrega la cola `notification_outbox` con prioridad `CRITICAL_SANITARY` y backoff progresivo (1m, 5m, 15m, 60m). | **Cada 1–2 minutos** | `php bin/send_notifications.php [--batch-size=N]` *(o `POST /api/cron/notifications-dispatch`)* |
| **Barrido del Reloj Sanitario Biológico** (RF-03.5.1, Art. II): activa la cuarentena de las máquinas bajo vigilancia con más de 4 h naturales sin frío confirmado **sin** esperar a un intento de cierre; una fila sostenida por otro consumidor se aplaza en 2 s en lugar de bloquear la pasada. | **Cada 15 minutos** — acota la latencia de detección en «4 h naturales + hasta 15 min» (+ una cadencia si otro consumidor sostiene la fila). | `php bin/sanitary_clock_sweep.php` *(o `POST /api/cron/sanitary-clock-sweep`)* |
| **Cierre definitivo de garantía** (RF-10): archiva a `CERRADA` los expedientes resueltos que superan las 48 h sin reclamación. | **Una vez al día** (ej. 03:00 UTC) | `POST /api/cron/auto-close` |

```bash
# crontab -e  (Linux / macOS — o instalado de serie en /etc/crontabs/root dentro del contenedor)
* * * * *    cd /ruta/a/gestor-incidencias-vending && php bin/send_notifications.php >> /var/log/vendguard-notifications.log 2>&1
*/15 * * * * cd /ruta/a/gestor-incidencias-vending && CRON_SECRET=... php bin/sanitary_clock_sweep.php >> /var/log/vendguard-sweep.log 2>&1
0 3 * * *    curl -s -X POST -H "X-Cron-Secret: $CRON_SECRET" https://tu-dominio/api/cron/auto-close
```

#### Programación externa gratuita sin depender de GitHub Actions (`cron-job.org` o Cloudflare Workers)
Si la cuenta de GitHub tiene bloqueada la ejecución de Actions o el contenedor se despliega en un plan gratuito que se suspende por inactividad (ej. Render Free), puedes desactivar los workflows `.yml` en la pestaña *Actions* de GitHub y programar las tres llamadas HTTP en un servicio gratuito como **[cron-job.org](https://cron-job.org)** o un **Cron Trigger de Cloudflare Workers**:

1. **Despachador de notificaciones (Emails + Webhook Telegram + Alertas SLA 2h/3h):**
   * `POST https://tu-dominio/api/cron/notifications-dispatch` · Cabecera `X-Cron-Secret: <CRON_SECRET>` · Cadencia: **cada 1–2 minutos**.
2. **Barrido sanitario + Keep-Alive de Supabase:**
   * `POST https://tu-dominio/api/cron/sanitary-clock-sweep` · Cabecera `X-Cron-Secret: <CRON_SECRET>` · Cadencia: **cada 15 minutos** *(al ejecutar consultas reales contra PostgreSQL cada 15 min, evita además la pausa por inactividad de 7 días de Supabase sin necesitar el workflow de GitHub)*.
3. **Auto-cierre de garantía 48 h:**
   * `POST https://tu-dominio/api/cron/auto-close` · Cabecera `X-Cron-Secret: <CRON_SECRET>` · Cadencia: **diaria (`0 3 * * *`)**.

El equivalente de línea de comandos del barrido devuelve código de salida **distinto de cero** si alguna máquina no pudo evaluarse, de modo que un planificador o un supervisor de tareas puede alertar sobre él; el mismo barrido sigue disponible por HTTP para entornos sin acceso a la consola del servidor. La cadencia se **comprueba** desde el panel de coordinación: la pestaña *🩺 Barrido Sanitario* publica el latido (última pasada y minutos transcurridos), la tendencia de 24 h y los expedientes aplazados por contención de fila, con la alerta de escalado cuando un aplazamiento persiste tres pasadas consecutivas (RF-07).

### 5. Despliegue en Contenedor (Render) y Variables de Entorno
El arranque del contenedor es una cadena fail-closed: ningún paso se salta y, si uno falla, el servicio no queda sirviendo.
```dockerfile
CMD ["sh", "-c", "env > /etc/vendguard/cron.env && chmod 600 /etc/vendguard/cron.env && php bin/init_cloud_db.php && crond -b && php -S 0.0.0.0:${PORT} -t public public/index.php"]
```
* **Paso 1 — entorno para cron:** las variables del contenedor se vuelcan en `/etc/vendguard/cron.env` con permisos `600`, de modo que las tareas de `crond` dispongan de `CRON_SECRET` y de las credenciales del motor sin heredar el entorno del servidor web.
* **Paso 2 — convergencia de la base de datos:** `init_cloud_db.php` aplica el DDL base (`database/supabase_init.sql`), **todas las migraciones de `database/supabase_migrations/` en orden** —el libro `schema_migrations` registra la huella de cada fichero y el arranque exige que el motor haya convergido—, el catálogo maestro y el histórico de demostración. Es lo que hace que una base **ya existente** reciba las tablas y columnas nuevas de cada despliegue; si este paso falla, la cadena se corta y el contenedor no arranca.
* **Paso 3 — `crond` y servidor web:** el demonio interno ejecuta el [reloj de barrido sanitario](deploy/cron/vendguard-sanitary-sweep) (§4) y después arranca `php -S` sirviendo `public/index.php`, que despacha tanto la API como los assets estáticos con su MIME y `Cache-Control: no-cache` para JS/CSS (un despliegue no se queda a medias por caché del navegador). El puerto lo inyecta la plataforma (`PORT`, por defecto 8080).
* **Imagen y extensiones:** `php:8.2-cli-alpine` incluye `mbstring` y las CA del sistema (comprobado con `php -m`); el [Dockerfile](Dockerfile) añade `pdo_pgsql`. No hace falta ninguna otra extensión ni hay `composer`/`npm` en runtime (Dogma Vanilla).

**Variables de entorno** (única fuente de verdad: [`SecretProvider`](src/Infrastructure/Config/SecretProvider.php) y [`ConnectionFactory`](src/Infrastructure/Database/ConnectionFactory.php)):

| Variable | Obligatoria en producción | Efecto |
| :--- | :--- | :--- |
| `SECRET_KEY` | **Sí** con `APP_ENV=production` | Firma HMAC de los tokens de sesión. Sin ella y sin entorno declarado se usa una clave **exclusiva de desarrollo**. |
| `CRON_SECRET` | **Sí** con `APP_ENV=production` | Credencial `X-Cron-Secret` de los tres procesos batch (`auto-close`, `sanitary-clock-sweep` y `notifications-dispatch`). |
| `APP_ENV` ó `VENDGUARD_ENV` | Recomendada | `production`/`prod` activan el **fallo en cerrado**: sin claves el sistema no arranca y `root` sin contraseña se rechaza (hallazgos S-1 y S-5). |
| `DATABASE_URL` / `SUPABASE_DB_URL` | Alternativa | Cadena única de PostgreSQL (`postgres://usuario:clave@host:puerto/base?sslmode=verify-full`). Tiene prioridad sobre `DB_*`. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Sí (o la cadena única) | Credenciales sueltas; se aceptan también `DB_DATABASE`, `DB_USERNAME` y `DB_PASS`. |
| `sslmode` y `sslrootcert` / `DB_SSL_ROOT_CERT` | No: hay valores por defecto seguros | TLS con verificación de identidad es **obligatorio** (RNF-05): solo se admite `sslmode=verify-full`, y `require`, `prefer`, `allow`, `disable` o `ssl: false` **abortan el arranque**. Sin CA declarada se usa la de Supabase incluida en `src/Infrastructure/Database/prod-ca-2021.crt` y, si no aparece, el almacén de certificados de la imagen; si tampoco hay ninguna, el arranque falla (el criterio no se degrada). |
| `PORT` | La inyecta la plataforma | Puerto del servidor embebido. |
| `APP_BASE_URL` | **Sí** si hay avisos externos por consola | Host público canónico de los enlaces que salen del sistema: las URLs impresas en las etiquetas QR y el botón de seguimiento de los correos. En las peticiones web, si falta, se resuelve por el host de la petición; un worker CLI sin ella entrega el botón como ruta relativa —que el cliente de correo resolvería contra su propio dominio— y la pasada lo **declara** en `relative_tracking_links_count`, con aviso por `stderr` (T-NOTIF-17). Se acepta también `VENDGUARD_APP_URL` como respaldo. |
| `VENDGUARD_TELEGRAM_BOT_TOKEN` | Opcional (Módulo 15) | Token del bot que entrega al técnico el aviso de asignación con el enlace a su avería. Sin él **no se encola** ningún envío personal y la asignación sigue funcionando igual; el panel de usuarios marca qué técnicos están sin vincular. Se admite `TG_BOT_TOKEN` como respaldo (el mismo nombre que usa el relé corporativo). El `chat_id` de cada técnico se declara en **Administración → Usuarios**. |
| `VENDGUARD_SMTP_HOST`, `VENDGUARD_SMTP_FROM_ADDRESS` | Para envío de emails | Servidor SMTP (ej. `smtp-relay.brevo.com`) y remitente verificado. Si no se declaran, se registra `UnconfiguredEmailTransport` y los correos quedan en fallo transitorio visible en la bandeja. |
| `VENDGUARD_SMTP_PORT`, `VENDGUARD_SMTP_ENCRYPTION`, `VENDGUARD_SMTP_USERNAME`, `VENDGUARD_SMTP_PASSWORD`, `VENDGUARD_SMTP_FROM_NAME` | Opcionales (con SMTP) | Puerto (`587`/`2525` con `tls` o `465` con `ssl`), credenciales `AUTH LOGIN` y nombre visible del remitente. |
| `VENDGUARD_DEV_SITE_KEYS` | **Nunca** en producción | Solo en local: siembra claves de centro deterministas `DEV-<site_code>`. En producción no se siembra ninguna: cada sede recibe su clave aleatoria al alta o al reemitirse desde coordinación. |
| `VENDGUARD_ALLOW_INSECURE_HTTP` | **Nunca** en producción | Rebaja la política de transporte del frontal: sin ella, cualquier petición pública por HTTP plano recibe `308` a la misma URL sobre HTTPS (con método y cuerpo conservados), porque el cerrojo entre pestañas y el Service Worker solo existen en contexto seguro. El bucle local y `/api/health` quedan exentos siempre. Con `1` se admite HTTP plano en una demo controlada de LAN sin terminador TLS. |

**Cron en la plataforma:** un *Cron Job* con la misma imagen ejecutando `php bin/sanitary_clock_sweep.php` cada 15 minutos (o una llamada externa a `POST /api/cron/sanitary-clock-sweep` con la cabecera `X-Cron-Secret`) y `POST /api/cron/auto-close` una vez al día. Sin el barrido programado, la pestaña *🩺 Barrido Sanitario* lo delata con el latido envejecido: la superficie distingue «no hay nada que hacer» de «el barrido no está corriendo».

> **Credenciales de demostración en el despliegue.** El paso 2 siembra el personal interno con la contraseña de demostración, de modo que `coordinacion@vendguard.internal` / `Password123!` funciona nada más desplegar. Las claves de centro, en cambio, **no** se siembran en producción (arriba).

---

## 🔌 Resumen de Endpoints de la API REST

Todas las respuestas cumplen con la envolvente canónica JSON (`{ success: true, data: ... }` o `{ success: false, error: ... }`):

| Método | Endpoint | Rol Autorizado | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/` y `/api/health` | Público | Comprobación de salud operativa y estado del sistema. |
| `POST`| `/api/auth/site-login` | Público | Acceso de responsables mediante código de sede (`site_code`) y clave de centro (`access_code`). |
| `POST`| `/api/auth/login` | Interno | Login para personal interno emitiendo token Bearer criptográfico. |
| `GET` | `/api/locations/{code}/machines` | `LOCATION_MANAGER` | Catálogo de máquinas del centro con estado de ticket activo. |
| `POST`| `/api/incidents` | `LOCATION_MANAGER` | Registro de avería con cálculo de urgencia y prevención de duplicados (409). |
| `POST`| `/api/incidents/{code}/comments`| `LOCATION_MANAGER` | Anexa comentarios o evidencias fotográficas a la bitácora. |
| `GET` | `/api/incidents/{code}/comments` | `LOCATION_MANAGER` | Consulta la bitácora de comentarios y evidencias de la avería. |
| `POST`| `/api/incidents/{code}/reopen` | `LOCATION_MANAGER` | Reapertura dentro de garantía (< 48h) desasignando al técnico. |
| `GET` | `/api/coordinator/incidents` | `COORDINATOR` | Listado global con filtros combinados y evaluación de SLA > 60m. |
| `PATCH`| `/api/coordinator/incidents/{id}/assign`| `COORDINATOR`| Asignación técnica a técnico de ruta (justificación si varía urgencia). |
| `PATCH`| `/api/coordinator/incidents/{id}/cancel`| `COORDINATOR`| Descarte lógico justificado (*Soft Delete*). |
| `GET` | `/api/coordinator/locations` | `COORDINATOR` | Listado de sedes con total de máquinas y averías activas. |
| `GET` | `/api/coordinator/locations/{id}/machines` | `COORDINATOR` | Parque de máquinas de una sede con estado operativo. |
| `GET` | `/api/coordinator/machines/{id}/qr-label` | `COORDINATOR` | Datos y SVG vectorial de etiqueta QR para máquina. |
| `GET` | `/api/coordinator/locations/{id}/qr-batch` | `COORDINATOR` | Lote completo de etiquetas QR de una sede para impresión masiva. |
| `GET` | `/api/coordinator/metrics/summary` | `COORDINATOR` | KPIs globales, MTTR, comparativa de tendencia y alertas de SLA. |
| `GET` | `/api/coordinator/metrics/breakdown` | `COORDINATOR` | Desglose multidimensional (sedes, técnicos, máquinas, averías) con reintervenciones por garantía 48h por técnico (`warranty_reopens`). |
| `GET` | `/api/coordinator/metrics/export` | `COORDINATOR` | Descarga CSV con codificación UTF-8 BOM de métricas agregadas. |
| `GET` | `/api/coordinator/audit-log` | `COORDINATOR` | Consulta cronológica paginada y filtrable del registro inmutable. |
| `GET` | `/api/coordinator/audit-log/export` | `COORDINATOR` | Descarga CSV del registro inmutable (máx. 10.000 filas). |
| `GET` | `/api/coordinator/sanitary-sweep/contention` | `COORDINATOR` | Superficie operativa del barrido sanitario: latido, tendencia de 24 h, expedientes aplazados por contención de fila y alerta de escalado cuando el aplazamiento persiste (RF-07). |
| `GET` | `/api/coordinator/incidents/{id}/detail` | `COORDINATOR` | Ficha integral de solo lectura del expediente: sede y máquina, ciclo de vida, SLA de frío, intervención, bitácora, piezas y matriz de permisos (Módulo 09). |
| `GET` | `/api/technician/my-route` | `TECHNICIAN` | Hoja de ruta móvil ordenada por criticidad. |
| `GET` | `/api/technician/my-metrics` | `TECHNICIAN` | Autoconsulta individual protegida de rendimiento (Art. V.4). |
| `GET` | `/api/technician/machines/{id}/history` | `TECHNICIAN` | Historial de averías de la máquina de la ruta (solo lectura, Art. V.4). |
| `PATCH`| `/api/technician/incidents/{id}/start` | `TECHNICIAN` | Inicio de intervención física in situ (`started_at`). |
| `PATCH`| `/api/technician/incidents/{id}/pause` | `TECHNICIAN` | Pausa estructurada por repuestos (`PENDING_PARTS`) con selección compatible o pieza fuera de catálogo. |
| `POST`| `/api/technician/incidents/{id}/resolve`| `TECHNICIAN` | Resolución técnica documentada ($\ge 20$ chars diagnóstico y acción) con Causa Raíz y Solución Estandarizada (Módulo 25). |
| `POST`| `/api/cron/auto-close` | Clave Cron | Cierre definitivo automático tras 48h de garantía. |
| `POST`| `/api/cron/sanitary-clock-sweep` | Clave Cron | Barrido de fondo del Reloj Sanitario Biológico: activa la cuarentena sin intento de cierre (latencia ≤ 15 min). |
| `POST`| `/api/cron/notifications-dispatch` | Clave Cron | Pasada del despachador de notificaciones: rescate de envíos en tránsito, vigilancia de SLA (2h/3h) y entrega con prioridad `CRITICAL_SANITARY`. |
| `GET` | `/api/qr/scan/{code}` | Público | Consulta ciudadana de estado de máquina por código QR. |
| `POST`| `/api/qr/report` | Público | Envío directo de incidencia ciudadana desde lectura QR. |

#### Administración Integral (Módulo 04, rol `COORDINATOR`)
| Método | Endpoint | Descripción del Recurso |
| :--- | :--- | :--- |
| `GET/POST/PATCH` | `/api/coordinator/locations[/{id}][/deactivate\|/reactivate]` | CRUD de sedes clientes con bajas lógicas. |
| `POST` | `/api/coordinator/locations/{id}/access-code` | Reemisión de la clave de centro de una sede: se muestra una sola vez y solo se guarda su huella bcrypt (S-4). |
| `GET/POST/PATCH` | `/api/coordinator/machines[/{id}][/transfer\|/deactivate\|/reactivate]` | CRUD de máquinas con transferencias auditadas entre sedes. |
| `GET/POST/PATCH` | `/api/coordinator/users[/{id}][/reset-password\|/deactivate\|/reactivate]` | CRUD de personal interno con reseteo de credenciales y contador informativo de garantía 48h (`warranty_incidents_count`). |

#### Mantenimiento Preventivo y Certificación Sanitaria (Módulo M1)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/preventive/dashboard` | `COORDINATOR` | Cuadro de vencimientos y alertas de inspección por tipología. |
| `GET/POST` | `/api/coordinator/preventive/orders` | `COORDINATOR` | Listado y creación de órdenes preventivas. |
| `GET` | `/api/coordinator/preventive/orders/{id}/detail` | `COORDINATOR` | Detalle integral de solo lectura de la orden (checklist, cronología de auditoría y cumplimiento). |
| `POST` | `/api/coordinator/preventive/generate-due` | `COORDINATOR` | Generación automática de órdenes por vencimiento normativo. |
| `PATCH` | `/api/coordinator/preventive/orders/{id}/assign\|/cancel` | `COORDINATOR` | Asignación técnica o cancelación justificada de la orden. |
| `GET/PATCH` | `/api/coordinator/preventive/settings` y `/api/coordinator/machines/{id}/preventive-config` | `COORDINATOR` | Frecuencias normativas por tipología (globales y por máquina). |
| `GET` | `/api/technician/preventive/route` | `TECHNICIAN` | Ruta preventiva móvil del técnico. |
| `POST` | `/api/technician/preventive/orders/{id}/claim\|/start\|/complete\|/reinspect` | `TECHNICIAN` | Ciclo de inspección: reclamo, inicio, checklist con dictamen y reinspección. |
| `GET` | `/api/technician/preventive/orders/{id}/checklist` | `TECHNICIAN` | Puntos de control normativos de la inspección. |
| `GET` | `/api/site/sanitary-status` | `LOCATION_MANAGER` | Semáforo higiénico de la sede (última desinfección y temperatura). |
| `GET` | `/api/site/certificates/machine/{code}` y `/api/site/certificates/global` | `LOCATION_MANAGER` | Certificados sanitarios oficial individual y global consolidado (JSON o A4 imprimible). |

#### Catálogo de Repuestos y Trazabilidad (Módulo M2)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST` | `/api/coordinator/spare-parts` | `COORDINATOR` | Listado filtrable y creación de repuestos con modelos compatibles (409 si duplicado). |
| `GET` | `/api/coordinator/spare-parts/models` | `COORDINATOR` | Modelos únicos del parque para los selectores de compatibilidad. |
| `GET/PUT` | `/api/coordinator/spare-parts/{id}` | `COORDINATOR` | Detalle con unidades instaladas y edición (código inmutable). |
| `DELETE` | `/api/coordinator/spare-parts/{id}` | `COORDINATOR` | Baja lógica del repuesto sin borrado físico (Art. III). |
| `PATCH` | `/api/coordinator/spare-parts/{id}/status` | `COORDINATOR` | Baja lógica y reactivación sin borrado físico (Art. III). |
| `GET` | `/api/coordinator/spare-parts/analytics` | `COORDINATOR` | Ranking de piezas, costes por modelo/sede y alertas de fallo crónico. |
| `GET` | `/api/coordinator/spare-parts/export` | `COORDINATOR` | Exportación CSV de consumos con snapshots congelados. |
| `GET` | `/api/coordinator/spare-parts/requests/pending-review` | `COORDINATOR` | Bandeja de piezas fuera de catálogo pendientes de homologación. |
| `GET` | `/api/technician/spare-parts/catalog?machine_id={id}` | `TECHNICIAN` | Catálogo compatible con la máquina intervenida (< 250 ms). |
| `PATCH` | `/api/technician/incidents/{id}/pause` | `TECHNICIAN` | Pausa estructurada con `requested_parts` (1–50 uds.) o pieza fuera de catálogo. |
| `POST` | `/api/technician/incidents/{id}/resolve` | `TECHNICIAN` | Resolución con `replaced_parts_declared`, destino `DESGUACE`/`TALLER` y snapshot de coste. |
| `POST` | `/api/technician/preventive/orders/{id}/complete` | `TECHNICIAN` | Cierre preventivo con registro opcional de piezas sustituidas. |

#### Logística de Rutas y Cartografía Territorial (Módulo M4)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/technician/route/map` | `TECHNICIAN` | Hoja de ruta secuenciada con paradas, criticidades, desglose correctivo/preventivo y enlaces GPS. |
| `GET` | `/api/coordinator/map/active-incidents` | `COORDINATOR` | Matriz territorial de sedes con averías activas, severidad máxima y marcas multi-técnico. |
| `GET/PUT` | `/api/coordinator/route/settings` | `COORDINATOR` | Consulta y actualización de parámetros de la Base Central (coordenadas y radio operativo). |
| `POST/PUT` | `/api/coordinator/locations` | `COORDINATOR` | Alta y edición de sedes con latitud/longitud validadas dentro del marco operativo territorial. |

#### Reintegros e Importe Retenido (Módulo M5)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/qr/report` | Público | Reporte ciudadano con solicitud opcional de reintegro (fallo de pago o dinero tragado). |
| `GET` | `/api/public/refunds/track` | Público | Seguimiento anónimo del reintegro por token con estado y Liquidación. |
| `PATCH` | `/api/public/refunds/track` | Público | Rectificación ciudadana de datos de cobro (Bizum/IBAN Módulo 97 ISO 7064). |
| `GET` | `/api/technician/incidents/{id}/refund` | `TECHNICIAN` | Dictamen económico desacoplado de la resolución técnica del ticket. |
| `GET` | `/api/location/refunds` | `LOCATION_MANAGER` | Reintegros de la sede pendientes de entrega en conserjería. |
| `POST` | `/api/location/refunds/{id}/deliver` | `LOCATION_MANAGER` | Entrega presencial validando el PIN secreto de 4 dígitos (bloqueo 423 tras 5 intentos). |
| `GET` | `/api/coordinator/refunds` | `COORDINATOR` | Bandeja de liquidación administrativa de reintegros custodiados. |
| `POST` | `/api/coordinator/refunds/{id}/approve\|/pay\|/reject\|/regularize` | `COORDINATOR` | Aprobación, pago, rechazo y regularización con registro inmutable. |

#### Hilo de Conversación del Expediente (Módulo 10)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST` | `/api/location/incidents/{id}/comments` | `LOCATION_MANAGER` | Hilo del expediente desde el portal de sede, con alias retrocompatible por código de ticket (`/api/incidents/{ticket_code}/comments`). **Nunca** incluye notas internas ni el nombre del técnico (Art. V.4). |
| `GET/POST` | `/api/technician/incidents/{id}/comments` | `TECHNICIAN` | Hilo íntegro para el técnico, notas internas incluidas, con selector de privacidad preseleccionado en «Nota Interna de Taller». |
| `GET/POST` | `/api/coordinator/incidents/{id}/comments` | `COORDINATOR` | Inspección total del diálogo y publicación de notas internas o públicas durante el triaje. |

#### Pausa Contractual de Espera de Sede (Módulo 11)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/technician/incidents/{id}/pause-pending-info` | `TECHNICIAN` | Pausa por falta de acceso o información del inmueble: causa tipificada, motivo ≥ 20 caracteres y **reloj de SLA congelado**. |
| `POST` | `/api/technician/incidents/{id}/resume-pending-info` | `TECHNICIAN` | Reanudación manual in situ con el vencimiento desplazado por los segundos naturales de la pausa. |
| `POST` | `/api/coordinator/incidents/{id}/pause-pending-info` | `COORDINATOR` | Pausa supervisada sobre cualquier ticket activo. |
| `POST` | `/api/coordinator/incidents/{id}/resume-pending-info` | `COORDINATOR` | Reanudación manual: devuelve el expediente a `ASSIGNED` o a `REGISTERED` según haya responsable o no. |
| `POST` | `/api/coordinator/incidents/{id}/cancel-inactivity` | `COORDINATOR` | Cancelación justificada tras **72 horas hábiles** de silencio, desvinculando los reintegros pendientes (Art. V.1 y V.2). |

#### Centro de Notificaciones Transaccionales y Alertas de Emergencia (Módulo 14)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/notifications/outbox` | `COORDINATOR` | Bandeja de salida paginada con KPIs de estados, alerta de fallos permanentes y filtros combinados. |
| `POST` | `/api/coordinator/notifications/outbox/{id}/retry` | `COORDINATOR` | Reintento manual individual refrescando el destinatario vigente de la sede o del webhook. |
| `POST` | `/api/coordinator/notifications/outbox/retry-all-failed` | `COORDINATOR` | Reintento masivo de todos los envíos en estado `FAILED_PERMANENT`. |
| `POST` | `/api/coordinator/notifications/outbox/{id}/cancel` | `COORDINATOR` | Cancelación manual auditada a `CANCELLED_OBSOLETE`. |
| `GET/PUT` | `/api/coordinator/notifications/settings` | `COORDINATOR` | Consulta y actualización del Canal Corporativo de Emergencias (URL `https://` validada anti-SSRF) y técnicos de guardia. |
| `POST` | `/api/coordinator/notifications/settings/test-webhook` | `COORDINATOR` | Prueba interactiva de conexión con el Webhook corporativo (timeout estricto de 5 s). |
| `PATCH` | `/api/coordinator/users/{id}/on-call` | `COORDINATOR` | Conmutación del estado de guardia 24/7 (`is_on_call`) de un técnico de ruta. |

#### Alertas de Asignación a Técnicos vía Telegram (Módulo 15)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `PATCH` | `/api/coordinator/users/{id}` | `COORDINATOR` | Vinculación del chat personal de Telegram del técnico (`telegram_chat_id`), con validación de rol `TECHNICIAN`, regex numérico, unicidad y auditoría enmascarada (RF-TG-07/10). |
| `GET` | `/api/technician/my-route?ticket={code}` | `TECHNICIAN` | Acceso directo por enlace profundo (*Deep Linking*) a la avería asignada desde el botón interactivo del mensaje de Telegram (RF-TG-05). |

#### Avería Oportunista In Situ y Reingreso en Ruta (Módulo 16)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/technician/locations/{location_id}/opportunistic-machines` | `TECHNICIAN` | Catálogo de máquinas de la sede con estado operativo, prediagnóstico (`CREATE_NEW`, `ASSUME_DIRECT`, `ASSUME_WITH_CONFIRMATION`, `REOPEN_IN_SITU`, `ALREADY_MINE`, `BLOCKED_IN_PROGRESS_BY_OTHER`) y alerta sanitaria Art. II ante paradas críticas en otras sedes. |
| `POST` | `/api/technician/opportunistic/incidents` | `TECHNICIAN` | Alta de avería oportunista in situ (Flujo A: `NEW`), naciendo en `IN_PROGRESS`, con validación de imagen ($\le 5\text{ MB}$, Art. V.5), bloqueo de captura bancaria (Art. V.4) y salvaguarda de frío (Art. II). |
| `POST` | `/api/technician/opportunistic/incidents/{id}/assume` | `TECHNICIAN` | Asunción de ticket existente (Flujo B: `ASSUMED`), traspaso con nota interna confidencial (`is_internal = TRUE`), reapertura en garantía in situ y sustitución atómica de 3.ª reincidencia crónica. |
| `GET` | `/api/technician/my-route?include_opportunistic_context=1` | `TECHNICIAN` | Hoja de ruta extendida con sedes habilitadas (paradas activas o ventana de gracia de 30 min) y aviso de paradas críticas pendientes en otras sedes. |
| `GET` | `/api/coordinator/metrics` | `COORDINATOR` | KPIs globales incluyendo desplazamientos logísticos evitados por intervenciones oportunistas (`opportunistic_interventions`). |

#### Zonificación Operativa y Asignación Asistida (Módulo 17)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/zones` | `COORDINATOR` | Catálogo territorial con conteo agregado de sedes, técnicos y códigos postales por zona en un solo round-trip. |
| `POST` | `/api/coordinator/zones` | `COORDINATOR` | Alta de demarcación con validación de nombre, código y color `#RRGGBB`; detecta CPs en conflicto antes de persistir. |
| `GET/PUT` | `/api/coordinator/zones/{id}` | `COORDINATOR` | Detalle y edición de la zona con sus códigos postales y contadores agregados; el cuadrante con identidades se sirve en su subruta. |
| `POST` | `/api/coordinator/zones/{id}/postal-codes` | `COORDINATOR` | Traslado atómico de códigos postales entre zonas con reasignación de sedes heredadas y auditoría dual matriz-sede. |
| `POST` | `/api/coordinator/zones/{id}/technicians` | `COORDINATOR` | Vinculación de técnico como titular o suplente, con degradación auditada de la titularidad previa. |
| `DELETE` | `/api/coordinator/zones/{id}/technicians/{tech_id}` | `COORDINATOR` | Baja lógica del cuadrante (`removed_at`), preservando el histórico de cobertura. |
| `GET` | `/api/coordinator/zones/{id}/technicians` | `COORDINATOR` | Cuadrante vivo con identidad: nombre, código de operador, titularidad, guardia, alta y estado, incluidas las bajas con vinculación viva. |
| `GET` | `/api/coordinator/technicians/quadrants` | `COORDINATOR` | Lectura inversa: zonas vivas por técnico con el rol en cada una, incluidos los técnicos activos sin zona. |
| `POST` | `/api/coordinator/locations/{id}/override-zone` | `COORDINATOR` | Excepción manual de sede o restablecimiento de la herencia por código postal. |
| `GET` | `/api/coordinator/incidents/{id}/suggested-technicians` | `COORDINATOR` | Ranking territorial explicado con desglose de puntuación, insignias y salvaguarda sanitaria de guardia. |

---

#### Firma Digital y Albarán de Servicio (Módulo 19)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/technician/incidents/{id}/resolve` | `TECHNICIAN` | Cierre correctivo con `service_sheet` obligatorio en la interfaz: firma (`signer_name`, `signer_document`, `signature_png_base64`) o ausencia (`absence_reason`). La hoja, sus piezas y el estado se escriben en una sola transacción. |
| `GET` | `/api/technician/incidents/{id}/service-sheet` | `TECHNICIAN` | Albarán de la resolución vigente con documento congelado, hoja, historial y vigencia. Autoriza el técnico asignado o quien firmó alguna hoja; `?sheet_id=` sirve una hoja histórica. |
| `GET` | `/api/coordinator/incidents/{id}/service-sheet` | `COORDINATOR` | Mismo contrato de lectura para el panel de triaje: cualquier expediente, incluida la hoja histórica de una avería reabierta. |

#### Stock de Repuestos en Furgoneta (Módulo 18)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/van-stocks` | `COORDINATOR` | Inventario de furgonetas con saldo, umbral, descuadres y alerta ordenada por severidad. |
| `PUT` | `/api/coordinator/van-stocks` | `COORDINATOR` | Fija el saldo absoluto de una pareja técnico-pieza y audita el delta. |
| `GET` | `/api/technician/van-stock` | `TECHNICIAN` | Saldo de la furgoneta del técnico autenticado, en solo lectura. |

#### Encuesta de Satisfacción Post-Resolución (Módulo 20)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/location/satisfaction-surveys/pending` | `LOCATION_MANAGER` | Episodios pendientes de valorar por la sede, con `pending_total` real. |
| `POST` | `/api/location/incidents/{id}/satisfaction-survey` | `LOCATION_MANAGER` | Registra 1–5 estrellas y comentario ≤ 500; `409` si el episodio ya fue valorado e inmutable después. |
| `GET` | `/api/coordinator/metrics/csat` | `COORDINATOR` | Media, respuestas, distribución 5→1, tablas por sede y técnico y tendencia bajo el filtro temporal. |
| `GET` | `/api/technician/my-metrics/csat` | `TECHNICIAN` | CSAT de solo lectura del técnico autenticado (incremento 20.1). |

#### Ausencias y Relevo de Turno (Módulo 21)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/technicians/absences` | `COORDINATOR` | Ausencias activas y programadas de la plantilla técnica. |
| `POST` | `/api/coordinator/technicians/{id}/absences` | `COORDINATOR` | Alta de vacaciones, baja, formación o fuera de turno con ventana temporal, sin desactivar la cuenta. |
| `DELETE` | `/api/coordinator/absences/{id}` | `COORDINATOR` | Cancela la ausencia con motivo (≥ 5 caracteres) y deja rastro en auditoría. |
| `GET` | `/api/coordinator/technicians/{id}/shift` | `COORDINATOR` | Estado de jornada vigente del técnico (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`). |
| `POST` | `/api/coordinator/technicians/{id}/shifts/force-close` | `COORDINATOR` | Cierre forzado con traspaso asistido de la cola y salvaguarda sanitaria. |
| `GET` | `/api/technician/handover-targets` | `TECHNICIAN` | Compañeros activos disponibles para el relevo, sin exponer el cuadrante interno. |
| `GET` | `/api/technician/shift/status` | `TECHNICIAN` | Estado de la jornada propia y del turno abierto. |
| `POST` | `/api/technician/shift/start` | `TECHNICIAN` | Inicia turno y abre una sesión de jornada. |
| `POST` | `/api/technician/shift/pause-meal` | `TECHNICIAN` | Pausa de comida acumulable al segundo. |
| `POST` | `/api/technician/shift/resume` | `TECHNICIAN` | Reanuda el turno tras la pausa. |
| `POST` | `/api/technician/shift/close-handover` | `TECHNICIAN` | Cierra jornada y traspasa la cola con nota interna obligatoria; bloquea con averías `IN_PROGRESS`. |

#### Registro de Jornada y Horas Efectivas (Módulo 22)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/shifts/{id}/logs` | `COORDINATOR` | Bitácora inmutable del turno (`START_SHIFT`, pausas, cierre y ajustes). |
| `PATCH` | `/api/coordinator/shifts/{id}/adjust` | `COORDINATOR` | Rectifica un turno cerrado con validación cuádruple y motivo ≥ 20 caracteres. |
| `GET` | `/api/coordinator/metrics/work-hours` | `COORDINATOR` | Informe por periodo: horas brutas, comida, netas, ruta e intervenciones resueltas. |
| `GET` | `/api/coordinator/metrics/work-hours/export` | `COORDINATOR` | CSV UTF-8 BOM con dos secciones en `Europe/Madrid`. |
| `GET` | `/api/technician/shift/my-hours` | `TECHNICIAN` | Horas propias del técnico autenticado, aisladas por el `user_id` del token. |

#### Órdenes de Carga y Cruce de Stock (Módulo 23)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/van-restock-orders/proposals` | `COORDINATOR` | Propuesta de reposición por umbral, descuadre y averías con solicitud de pieza. |
| `POST` | `/api/coordinator/van-restock-orders/generate-batch` | `COORDINATOR` | Genera órdenes en lote excluyendo ausentes y técnicos con orden abierta. |
| `GET` | `/api/coordinator/van-restock-orders` | `COORDINATOR` | Listado de órdenes con estado, destinatario y líneas. |
| `POST` | `/api/coordinator/van-restock-orders` | `COORDINATOR` | Alta manual de orden en `PENDING_PICKING`. |
| `GET` | `/api/coordinator/van-restock-orders/{id}` | `COORDINATOR` | Detalle con cantidades solicitadas y despachadas. |
| `PUT` | `/api/coordinator/van-restock-orders/{id}` | `COORDINATOR` | Edita líneas mientras la orden sigue en `PENDING_PICKING` (baja lógica, Art. III.1). |
| `POST` | `/api/coordinator/van-restock-orders/{id}/dispatch` | `COORDINATOR` | Despacho con cantidades reales; pasa a `DISPATCHED`. |
| `POST` | `/api/coordinator/van-restock-orders/{id}/cancel` | `COORDINATOR` | Cancelación justificada (≥ 20 caracteres) y auditada. |
| `POST` | `/api/technician/van-stock/restock-orders/{id}/confirm` | `TECHNICIAN` | Recepción móvil en un toque; incrementa el stock de forma transaccional. |

#### Modo Sin Conexión e Instalabilidad Móvil (Módulo 24)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/technician/offline/bootstrap` | `TECHNICIAN` | Paquete de ruta completo para `IndexedDB`: paradas, catálogo, taxonomía y contexto de sincronización. |
| `POST` | `/api/technician/offline/conflicts/dump` | `TECHNICIAN` | Archiva la cola conflictiva como Nota Interna de Taller, incluidas cerradas > 48 h y preventivas. |
| `POST` | `/api/technician/offline/deactivated-account-dump` | `TECHNICIAN` | Volcado de la cola de un técnico dado de baja; exige cuenta inactiva y el token del propio técnico. |

#### Taxonomía de Causas Raíz y Análisis Pareto (Módulo 25)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/technician/root-causes` | `TECHNICIAN` · `COORDINATOR` | Catálogo activo de causas y soluciones para el selector de resolución y la copia sin conexión (RF-02.3). |
| `GET` | `/api/coordinator/root-causes` | `COORDINATOR` | Catálogo completo con sus soluciones y contadores para la administración. |
| `POST` | `/api/coordinator/root-causes` | `COORDINATOR` | Alta atómica de causa raíz con su primera solución predeterminada (409 si el código ya existe). |
| `PUT` | `/api/coordinator/root-causes/{id}` | `COORDINATOR` | Edición de nombre, descripción y vigencia; código y categoría permanecen inmutables. |
| `POST` | `/api/coordinator/root-causes/{id}/solutions` | `COORDINATOR` | Alta de solución estandarizada; el cambio de predeterminada es atómico. |
| `PUT` | `/api/coordinator/root-causes/{id}/solutions/{solutionId}` | `COORDINATOR` | Edición y baja/reactivación con guardas de predeterminada única. |
| `GET` | `/api/coordinator/metrics/root-causes-pareto` | `COORDINATOR` | Pareto 80/20 con filtros de periodo, fabricante, modelo y categoría; incluye históricas sin clasificar, MTTR y costes. |
| `GET` | `/api/coordinator/metrics/root-causes-pareto/export` | `COORDINATOR` | CSV UTF-8 BOM en dos secciones (resumen y detalle nominal) con nombres vigente y congelado; registra la exportación. |
| `POST` | `/api/coordinator/incidents/{id}/resolve` | `COORDINATOR` | Cierre desde triaje con `require_taxonomy`: exige causa y solución para pasar a `RESOLVED`. |

#### Bandeja de Taller Central (Módulo 27)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/workshop-items` | `COORDINATOR` | Bandeja de piezas recuperadas con custodia física, estado y ahorro por reacondicionamiento. |
| `GET` | `/api/coordinator/workshop-items/export` | `COORDINATOR` | Exportación CSV de la bandeja y sus indicadores de economía circular. |
| `POST` | `/api/coordinator/workshop-items/{id}/receive` | `COORDINATOR` | Recepción física de la pieza en el taller central. |
| `POST` | `/api/coordinator/workshop-items/{id}/start-bench` | `COORDINATOR` | Inicio del trabajo en banco con control de concurrencia sobre la pieza. |
| `POST` | `/api/coordinator/workshop-items/{id}/resolve` | `COORDINATOR` | Dictamen con diagnóstico real (≥ 20 caracteres): reincorporación al inventario o baja. |

#### Guías Rápidas de Diagnóstico por Modelo (Módulo 28)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST` | `/api/coordinator/troubleshooting-guides` | `COORDINATOR` | Catálogo completo y alta de una guía por pareja modelo/categoría (`409` si ya existe una viva). |
| `PUT` | `/api/coordinator/troubleshooting-guides/{id}` | `COORDINATOR` | Edición de bloques y vigencia; el modelo y la categoría permanecen inmutables. |
| `PATCH` | `/api/coordinator/troubleshooting-guides/{id}/status` | `COORDINATOR` | Baja lógica y reactivación de la guía sin borrado físico (Art. III.1). |
| `GET` | `/api/troubleshooting-guides/by-model` | `TECHNICIAN` · `COORDINATOR` | Guía vigente del modelo y la categoría del aviso para la ruta y la copia sin conexión. La sede recibe `403`. |

#### Acta de Merma y Cuarentena Sanitaria APPCC (Módulo 29)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/sanitary-quarantines/active` | `TECHNICIAN` · `COORDINATOR` | Cuarentenas activas con su origen, el reloj biológico y el bloqueo de QR vigente. |
| `POST` | `/api/sanitary-quarantines/machines/{machineId}/lift` | `TECHNICIAN` · `COORDINATOR` | Levantamiento atómico de la cuarentena con emisión del acta y desbloqueo del QR. |
| `GET` | `/api/sanitary-spoilage-records/by-machine/{machineId}` y `/by-machine-code/{code}` | `TECHNICIAN` · `COORDINATOR` | Histórico de actas de la máquina y sus reaperturas (`1..N`). |
| `GET` | `/api/sanitary-spoilage-records/{id}/official-act` | `TECHNICIAN` · `COORDINATOR` | Acta Oficial A4 imprimible con firma de higienización. |
| `GET` | `/api/coordinator/sanitary-spoilage-records[/export-csv]` | `COORDINATOR` | Libro APPCC de mermas con filtros y exportación CSV auditada. |
| `PATCH` | `/api/coordinator/sanitary-spoilage-records/{id}/loss-estimate` | `COORDINATOR` | Ajuste económico auditado con motivo obligatorio (Art. V.4). |

#### Perfiles de SLA Diferenciados por Sede (Módulo 30)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST/PUT/PATCH` | `/api/coordinator/locations[/{id}]` | `COORDINATOR` | Alta y edición de sede con `sla_tier` (Estándar por defecto, `422 INVALID_SLA_TIER` fuera del enumerado) y auditoría solo del cambio efectivo de contrato. |
| `GET` | `/api/coordinator/incidents` y `/api/coordinator/incidents/{id}/detail` | `COORDINATOR` | Triaje y ficha con el perfil sellado, el objetivo en minutos/horas y la hora límite de vencimiento. |
| `GET` | `/api/coordinator/incidents/{id}/suggested-technicians` | `COORDINATOR` | Ranking con `contractual_24_7_escalation_active` y el bono `contractual_24_7_on_call_bonus` (+60 puntos). |
| `GET` | `/api/coordinator/metrics/breakdown` y `/metrics/export` | `COORDINATOR` | Desglose por sede con perfil contractual, objetivo nominal de referencia, brechas reales y estado SLA. |
| `GET` | `/api/technician/route/map` | `TECHNICIAN` | Parada con `sla_tier`, `sla_due_at` y `critical_reason` (`COLD_CHAIN` / `CONTRACTUAL_SLA`), también offline. |
| `GET` | `/api/locations/{code}/machines` | `LOCATION_MANAGER` | SLA contratado de su propia sede y plazo comprometido de sus avisos; nunca datos de otras sedes. |

#### Rol de Reponedor y Cuadrante de Carga (Módulo 31)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST/PUT` | `/api/coordinator/staff[/{id}]` | `COORDINATOR` | Alta y edición de personal con rol `RESTOCKER` (código `REP-*`), rol inmutable y guardia técnica prohibida. |
| `GET` | `/api/coordinator/restockers/quadrants` | `COORDINATOR` | Lectura inversa: zonas vivas por reponedor con su rol en cada una. |
| `GET/POST` | `/api/coordinator/zones/{id}/restockers` | `COORDINATOR` | Cuadrante de carga de la zona con identidad y titularidad/suplencia. |
| `DELETE` | `/api/coordinator/zones/{id}/restockers/{restocker_id}` | `COORDINATOR` | Baja lógica idempotente del vínculo, conservando el histórico de cobertura. |
| `GET` | `/api/coordinator/zones/{id}/restocker-coverage` | `COORDINATOR` | Cobertura en vivo: turno, ausencia, carga viva y reponedor recomendado. |
| `GET` | `/api/restocker/my-route` | `RESTOCKER` | Vista móvil del reponedor: zonas asignadas, estado de jornada y estado base de ruta. |
| `GET/POST` | `/api/technician/shift/{status,start,pause-meal,resume,close}` | `TECHNICIAN` · `RESTOCKER` | Jornada compartida por los dos roles de campo; el cierre del reponedor es directo, sin traspaso de averías. |

#### Catálogo de Productos y Planograma por Máquina (Módulo 32)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST` | `/api/coordinator/products` | `COORDINATOR` | Catálogo de productos y consumibles con filtros y alta (`409` si el SKU ya existe). |
| `PUT` | `/api/coordinator/products/{id}` | `COORDINATOR` | Edición de ficha; el `sku` permanece inmutable. |
| `PATCH` | `/api/coordinator/products/{id}/status` | `COORDINATOR` | Baja lógica y reactivación del producto (Art. III.1). |
| `GET/PUT` | `/api/coordinator/machines/{id}/planogram` | `COORDINATOR` | Planograma completo (con coste por carril) y guardado en bloque de los carriles. |
| `PUT/DELETE` | `/api/coordinator/machines/{id}/planogram/slots/{slot_code}` | `COORDINATOR` | Alta o edición de un carril y baja lógica, con validación de compatibilidad y cadena de frío. |
| `POST` | `/api/coordinator/machines/{id}/planogram/apply-preset` y `/clone` | `COORDINATOR` | Preset por modelo de máquina o clonado del planograma de otra máquina. |
| `PUT` | `/api/coordinator/admin/machines/{id}` | `COORDINATOR` | Cambio de tipología con cerrojo de planograma (`409 PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE`). |
| `GET` | `/api/restocker/machines` | `RESTOCKER` | Parque de sus zonas con alerta de stock (`?stock_alert_only=true`) y planograma sin costes. |
| `GET` | `/api/restocker/machines/{id}/planogram` | `RESTOCKER` | Planograma de solo lectura sin coste unitario, precio ni margen (Art. V.4). |

#### Órdenes de Carga de Producto y Picking con Frío (Módulo 33)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/restocker-van-stocks` | `COORDINATOR` | Stock por lote de las furgonetas con semáforo de caducidad y descuadres. |
| `PUT` | `/api/coordinator/restocker-van-stocks/{restockerId}` | `COORDINATOR` | Alta o ajuste de un lote con motivo obligatorio de 20 caracteres reales y auditoría del saldo. |
| `GET` | `/api/coordinator/product-loading-orders/proposal` | `COORDINATOR` | Propuesta matutina por `ALERTS_ONLY` o `ALL_GAPS`, con colchón y descuento del stock útil de la furgoneta. |
| `GET/POST` | `/api/coordinator/product-loading-orders` | `COORDINATOR` | Listado y alta individual de órdenes en `PENDING_PICKING`. |
| `POST` | `/api/coordinator/product-loading-orders/batch` | `COORDINATOR` | Emisión masiva excluyendo reponedores ausentes, inactivos o con orden abierta. |
| `GET/PUT` | `/api/coordinator/product-loading-orders/{id}` | `COORDINATOR` | Detalle con lotes despachados y edición de líneas mientras siga en `PENDING_PICKING`. |
| `GET` | `/api/coordinator/product-loading-orders/{id}/picking-sheet` | `COORDINATOR` | Hoja de Picking A4 en dos bloques (cámara frigorífica y almacén seco) sin importes. |
| `POST` | `/api/coordinator/product-loading-orders/{id}/dispatch` | `COORDINATOR` | Despacho con temperatura de cámara ≤ 4,0 °C y lote/caducidad por línea refrigerada. |
| `POST` | `/api/coordinator/product-loading-orders/{id}/reconcile` | `COORDINATOR` | Liquidación al cierre (`RECONCILED_AT_CLOSE`) con retorno contado y descuadre justificado. |
| `POST` | `/api/coordinator/product-loading-orders/{id}/cancel` | `COORDINATOR` | Cancelación justificada (≥ 20 caracteres reales) y auditada. |
| `GET` | `/api/restocker/van-stocks` | `RESTOCKER` | Stock FEFO propio por lote con semáforo `EXPIRED` / `EXPIRING_SOON` / `OK`. |
| `POST` | `/api/restocker/product-loading-orders/{id}/confirm` | `RESTOCKER` | Recepción móvil en un toque; suma las cantidades despachadas por lote de forma atómica. |
| `POST` | `/api/restocker/product-loading-orders/{id}/reconcile` | `RESTOCKER` | Liquidación al cierre desde el propio móvil, con pre-cálculo de abastecidas y mermas. |

#### Visita de Reposición In Situ y Control FEFO (Módulo 34)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/restocker/machines/{machineId}/visits` | `RESTOCKER` | Visita completa e idempotente (`client_mutation_id`): triple cerrojo térmico/FEFO, checklist de higiene y líneas por carril. |
| `GET` | `/api/restocker/visits` | `RESTOCKER` | Histórico propio de visitas con filtros por máquina y estado. |
| `POST` | `/api/restocker/visits/photo` | `RESTOCKER` | Evidencia fotográfica (≤ 5 MB, validada por magic bytes) del aborto térmico o de la merma retirada. |
| `POST` | `/api/restocker/visits/conflict-dump` | `RESTOCKER` | Vuelca una visita en conflicto como Nota Interna de Taller sin bloquear el resto de la cola offline. |
| `GET` | `/api/coordinator/restock-visits` | `COORDINATOR` | Libro de visitas in situ con KPIs, filtros y coste de la merma comercial. |
| `GET` | `/api/coordinator/restock-visits/{id}` | `COORDINATOR` | Detalle con snapshot contable por carril; el reponedor nunca recibe esos importes (Art. V.4). |

---

## 🧪 Batería Completa de Pruebas Automatizadas (T-39)

La integridad de VendGuard está certificada mediante un ejecutor de pruebas automatizado nativo, ejecutado en fases continuas (unitarias PHP, unitarias reactivas JS, integración HTTP real, guion E2E de los tres perfiles y guarda de coherencia entre las cifras documentadas y las ejecutadas):

```powershell
# Ejecutar la suite completa (Unitarias PHP, Unitarias JS e Integración PHP):
php tests/run_all.php
```

### Resumen de Ejecución Global (Noviembre 2026)

La cifra vigente se declara una sola vez y la propia batería la audita (`tests/Support/DocMetricsGuard.php`), de modo que un desfase documental la pone en rojo:

batería global: 441 suites · 16.993 aserciones

```text
======================================================================
 RESUMEN DE EJECUCIÓN GLOBAL (T-39)
======================================================================
 Tiempo de ejecución total : 464.23 segundos  (variable en cada corrida)
 Suites de pruebas PHP Unit : 197 / 197 pasadas
 Suites de pruebas JS Unit  : 106 / 106 pasadas
 Suites de Integración PHP  : 135 / 135 pasadas
 Suites E2E Manuales (T-40): 3 / 3 pasadas
 ──────────────────────────────────────────────────────────────────
 Total Suites Ejecutadas    : 441
 Total Aserciones Evaluadas : 16.993
 Fallos Detectados          : 0
 Cifras documentadas        : COHERENTES
 Base de datos restablecida : SÍ (Semillas intactas)
======================================================================
 RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
 CONDICIÓN T-39 CUMPLIDA SATISFACTORIAMENTE.
======================================================================
```

Entre las suites de certificación destacan:
* **`ConstitutionalAuditTest`** — auditoría automática de los Artículos I al VII (cero `DELETE FROM` en producción, tipado estricto, tokens visuales Docker).
* **`SiteManagerPartsDataSegregationTest` (T-SPARE-20)** — blindaje del Art. V.4: ningún endpoint del portal de sede expone piezas, destinos ni costes.
* **`SiteManagerRefundDataSegregationTest`** — blindaje del Art. V.4 en reintegros: ningún rol ajeno consulta el dictamen económico (403) y la entrega en sede exige el PIN de recogida.
* **`SiteManagerRouteDataSegregationTest` (T-MAP-19)** — blindaje del Art. V.4: bloqueo 403 Forbidden para responsables de sede frente a rutas de campo y prohibición de rastreo en segundo plano del personal.
* **`RouteOptimizationServiceTest` (T-MAP-07)** — verificación matemática de la heurística de optimización de rutas (Haversine, paradas prioritarias por SLA < 4h, parada activa `#1` y exclusión de `PENDING_PARTS`).
* **`MapZoomPanUtilTest.mjs` (T-MAP-21)** — controlador gestual reactivo puro: anclaje, límites de escala, clamp de encuadre, gestos táctiles *pinch-to-zoom* y recentrado interactivo `focusOn`.
* **`CoordinatorRouteMapApiTest` / `TechnicianRouteMapApiTest` (T-MAP-17/18)** — integración HTTP contra el motor del arnés del ciclo cartográfico completo, fallback a Base Central y cálculo de enlaces universales de navegación.
* **`CoordinatorButtonVariantsGuardTest.mjs`** — los 361 botones de las 24 plantillas `Coordinator*.js` y de la vista móvil del Módulo 33 visten la escalera `--control-*` y ninguno declara geometría ni color en línea (sí una colocación o un color de estado); la guarda se prueba contra muestras sintéticas y cae también si alguna variante declarada queda muerta.
* **`CoordinatorActionsWrapGuardTest.mjs`** — ningún grupo de acciones del panel depende de tener pocos controles: el grupo envuelve y la etiqueta nunca se parte dentro de su propio botón.
* **`SanitarySweepVisibilityServiceTest` / `CoordinatorSanitarySweepEndpointTest` (T-PAUSE-41)** — la superficie del barrido sanitario: umbrales de escalado a las tres pasadas consecutivas, cierre del seguimiento **por identidad** y el ciclo completo contra el motor del arnés sobre dos conexiones PDO reales (aplazamiento → cuarentena → cierre → alerta apagada).
* **`NotificationDispatcherAndSlaSweepTest` / `CoordinatorNotificationApiTest` (Módulo 14)** — certificación del ciclo completo de la bandeja de salida transaccional, rescate de envíos atascados en `PROCESSING`, escalado automático de SLA sanitario (2h/3h) y validación anti-SSRF de Webhooks.
* **`TelegramTransportTest` / `AdminUserTelegramLinkTest` (Módulo 15)** — certificación del canal y transporte HTTP hacia la API de Telegram, vinculación de chats de técnicos, deep linking a averías y entrega real de alertas de asignación.
* **`OpportunisticIncidentApiIntegrationTest` / `OpportunisticAtomicWritesTest` (Módulo 16)** — catálogo de máquinas de la sede, alta y asunción in situ, reapertura en garantía, sustitución atómica del ticket crónico y bloqueo de convivencia entre sedes bajo `FOR UPDATE`.
* **`PostgresZoneSchemaTest` / `OperationalZoneRepositoryTest` (Módulo 17)** — unicidad global del CP vivo por índice parcial, titularidad única por técnico, baja lógica de zonas y cuadrantes, y traslado transaccional de CPs con auditoría matriz-sede.
* **`SuggestedTechniciansApiTest` / `ColdChainOverrideJustificationTest` (Módulo 17)** — ranking determinista con mediana inferior a 100 ms, salvaguarda del Art. V.1 con umbral de 20 caracteres reales y registro del descarte en `audit_log`.
* **`OperationalZoneManagementTabTest.mjs` / `CoordinatorDashboardAssignPanelTest.mjs` (Módulo 17)** — consola territorial de administración, despacho del sugerido en dos toques, justificación sanitaria dinámica y filtro de zona del mapa de coordinación.
* **`OpportunisticFrontendIntegrationTest.mjs` (Módulo 16)** — tarjeta temporal de 30 minutos, entrada oportunista desde la parada activa y desde la inspección preventiva, agrupación en Parada #1, KPI de desplazamientos evitados y alias constitucional del técnico en el portal de sede.
* **`RootCauseTaxonomyIntegrationTest` / `RootCauseTaxonomyServiceTest` (Módulo 25)** — catálogo atómico e inmutable, guardas de baja/reactivación, captura obligatoria, snapshots congelados, reaperturas y `403` en Pareto, CSV y CRUD para técnicos y sedes.
* **`RootCauseParetoServiceTest` / `ResolutionTaxonomyValidatorTest` (Módulo 25)** — 80/20 con cierre exacto en `100,00 %`, filtros y empates, CSV de dos secciones, independencia de los 20 caracteres y tolerancia a la sincronización diferida.
* **`RootCauseTaxonomyFrontendAndPwaTest.mjs` (Módulo 25)** — selector en ruta, etiquetas en el detalle y el historial, pestaña Pareto con Chart.js local precacheado y opacidad total en sede, QR y albarán.

> **Nota operativa:** las suites de integración incluyen pruebas de HTTP real contra `127.0.0.1:8000`. Si el puerto está ocupado por un servidor obsoleto, arránquelo antes con `php -S 127.0.0.1:8000 -t public public/index.php`.

### Canal propio del motor nuevo (`new_engine`)

Las suites que declaran `VENDGUARD_TEST_CATEGORY = 'new_engine'` exigen un PostgreSQL real y trabajan sobre esquemas aislados, así que **no forman parte de la batería hermética**: `run_all.php` las enumera y las deriva a un canal con comando propio (hallazgo H-4).

```bash
source deploy/local/postgres/.env.local     # motor local; .env.docker-tls para el TLS
php tests/run_new_engine.php                # las 71 suites del canal
php tests/run_new_engine.php Zone           # filtra por subcadena del nombre
```

El canal lee `PG_TEST_DSN` del entorno y **falla cerrado**: sin la variable, o con el motor inalcanzable, sale con código `2` y un mensaje explícito en lugar de omitir suites en silencio. Cada suite corre en su propio proceso y el resumen publica verdes, aserciones y fallos. La medición de latencia contra el motor remoto (`PoolerLatencyMeasurementTest.php`) declara su **contrato de entorno propio** (`VENDGUARD_TEST_ENV_CONTRACT` → `deploy/local/supabase/.env.local`, ignorado por git); el canal lo resuelve solo para ese proceso hijo e inyecta `DATABASE_URL` y `DB_SSL_ROOT_CERT` sin publicar sus valores, mientras el resto de suites sigue midiendo el banco del arnés. Si el fichero falta, la suite declara `exit 2` y el informe la lista como **no ejecutable** con su motivo, sin contarla como fallo ni omitirla. La medición del pooler remoto sigue siendo la evidencia de RNF-01: si el enlace del proveedor incumple el presupuesto de 250 ms en alguna operación, el canal publica el fallo con su mediana y su p95, sin convertirlo en omisión.

Corrida histórica del 18 de noviembre de 2026 (canal cerrado · **70/70 suites en verde**, 0 no ejecutables bajo `.env.docker-tls`):

```text
======================================================================
 RESUMEN DEL CANAL new_engine
 Suites evaluadas    : 70
 Suites en verde     : 70
 No ejecutables      : 0
 Aserciones          : 2.281
 Fallos              : 0
 RESULTADO: 100% EN VERDE. CANAL new_engine CERRADO.
======================================================================
```

> **Corrida completa del 19 de noviembre de 2026 (71 suites):** **71 suites evaluadas · 70 en verde · 2.342 aserciones · 1 fallo**. El fallo es `PoolerLatencyMeasurementTest`: el pooler de certificación (`deploy/local/supabase/.env.local`, Supavisor 6543) ya tiene la migración `032` y completó sus 20 rondas, pero las medianas de «crear» (**252,5 ms**, p95 288,9 ms) y «ruta» (**254,1 ms**, p95 369,8 ms) superan el presupuesto de 250 ms; 28 de sus 30 aserciones quedan verdes. El resto del canal queda en verde, incluidas `RestockerSatSynergyApiTest` 55/55 y `RestockerSatLayoutBrowserTest` 22/22. `exit=1`; el fallo se mantiene documentado como H-36-05.

> **H-36-04 (19 de noviembre de 2026):** `RestockerSatLayoutBrowserTest` certifica **22/22** a `360 × 800` en Chromium real las superficies L1 y SAT (documento, bandeja, tarjeta, nota interna, foto, coincidencia en sede y acciones alcanzables). La suite forma parte de la corrida completa anterior y sus 22 aserciones están incluidas en las **2.342** del canal.

En esa misma corrida histórica, `PostgresTieredSlaMetricsPerformanceTest` declara un contrato de entorno propio (`VENDGUARD_TEST_ENV_CONTRACT` → `deploy/local/supabase/.env.local`) cuyo DSN exige TLS (`sslmode=verify-full`): bajo `.env.local` (puerto 5432, sin TLS) el canal la enumera como no ejecutable (`SQLSTATE[08006]`), mientras que con `.env.docker-tls` (puerto 55433 con TLS local) completa sus **34/34 aserciones** y cierra las **70/70 suites** en verde.

En la corrida histórica del 18 de noviembre, el pooler remoto quedó en verde y estable: `PoolerLatencyMeasurementTest` completa sus 20 rondas y pasa 30/30 aserciones en tres corridas. La optimización de `asignar` integró el cierre y la apertura de custodia en el CTE atómico y trasladó la revalidación de estado, titular y urgencia al propio `UPDATE` condicionado, eliminando en PostgreSQL la lectura bloqueada previa. Las medianas de «asignar» fueron **166,8 ms**, **165,6 ms** y **174,3 ms** (p95 194,2 / 224,6 / 241,1 ms) frente al presupuesto de 250 ms; el enlace base se mantuvo en 43,8–44,1 ms.

El cerrojo entre pestañas también queda certificado: `OfflineSyncLockBrowserTest` completa sus **16/16 aserciones**. El runner prefiere Chrome/Chromium del sistema, después el Chromium que Playwright tenga en su caché local y, por último, Edge; así evita el arranque de Edge 157, que en este puesto termina con código 0 antes de publicar DevTools, sin instalar dependencias ni rebajar la exigencia de navegador real.


El canal incluye además `OfflineSyncLockBrowserTest`, única suite que exige **navegador real**: abre dos pestañas de Chromium sobre el mismo perfil, comparte `IndexedDB` entre ambas y comprueba con un servidor de pruebas que el cerrojo `vendguard-offline-sync` impide dos envíos en vuelo y que el control negativo sin cerrojo sí los detecta. La quinta sección oculta `navigator.locks` en las dos pestañas —lo que ve una PWA servida por HTTP plano— y verifica que el arriendo en `localStorage` sostiene la misma exclusión (16 aserciones en total).

Entre las suites específicas de este canal se encuentran `PostgresTimeTrackingSchemaTest` (Módulo 22 · integridad relacional de `technician_shifts` y `technician_shift_logs`), `PostgresConstitutionalTest` (Art. III.1 en las 48 tablas de su contrato de correspondencia, Módulos 14 y 17–34), `PostgresAbsenceShiftSchemaTest` (Módulo 21), `PostgresSatisfactionSurveySchemaTest` (Módulo 20), `PostgresServiceSheetSchemaTest` (Módulo 19), `PostgresVanRestockSchemaTest` (Módulo 23 · órdenes de carga y baja lógica), `PostgresVanStockSchemaTest` (Módulo 18), `PostgresZoneSchemaTest` (Módulo 17), `PostgresRootCauseTaxonomySchemaTest` (Módulo 25 · tablas 35/36, columnas congeladas, predeterminada única e inmutabilidad) y `RootCauseParetoPerformanceTest` (Módulo 25 · 10.000 averías bajo el umbral).

---

## 📂 Estructura del Repositorio

```text
gestor-incidencias-vending/
├── constitution.md               # Ley Suprema del proyecto (Artículos I al VII)
├── AGENTS.md                     # Directrices operativas de desarrollo y SDD
├── Dockerfile                    # Contenedor para despliegues cloud en Render
├── router.php                    # Router del servidor embebido de PHP (php -S ... router.php)
├── README.md                     # Esta guía maestra de entrega y operaciones
├── bin/
│   ├── init_cloud_db.php         # Inicializador del arranque desplegado (una sola ruta: DDL base, migraciones, catálogo, histórico de demostración)
│   ├── migrate.php               # Migrador incremental del motor único (PostgresMigrationRunner)
│   ├── seed_demo_history.php     # Histórico de demostración (17 averías INC-DEMO-*, traza y auditoría)
│   ├── seed_demo_map.php         # Averías demo repartidas entre técnicos para el Mapa Territorial (8 INC-MAPA-*)
│   ├── create_bizum_case.php     # Script CLI de caso demo de reintegro por Bizum
│   ├── sanitary_clock_sweep.php  # Barrido CLI del Reloj Sanitario Biológico (mismo reloj que el endpoint cron)
│   └── send_notifications.php    # Worker CLI del despachador de notificaciones (mismo grafo que el endpoint cron)
├── database/
│   ├── supabase_init.sql         # DDL canónico de PostgreSQL (48 tablas, 41 enumerados, disparadores)
│   ├── supabase_migrations/      # Migraciones incrementales canónicas del motor único
│   ├── supabase_seeds.sql        # Catálogo maestro de semillas del motor único
│   ├── DemoHistorySeeder.php     # Histórico de demostración portable: 17 averías (13 cerradas y 4 en garantía, dos reabiertas; diez clasificadas con causa raíz), traza y auditoría
│   └── DemoMapSeeder.php         # Sembrador demo del Mapa Territorial: 8 averías INC-MAPA-* con reparto multi-técnico, una sin asignar y un técnico sin trabajo
├── deploy/
│   ├── cron/                     # Crontab interno del contenedor (barrido sanitario y despachador de notificaciones)
│   ├── cloudflare/               # Relé gratuito de Cloudflare Worker para el Webhook de Telegram y Cron opcional
│   └── local/                    # Aprovisionamiento del motor PostgreSQL local para desarrollo y certificación
├── docs/
│   ├── design.md                 # Especificación de tokens visuales Docker
│   ├── manual_0_analisis_problema.md # Análisis de negocio y reglas de vending
│   ├── manual_e2e_verification.md    # Guion de verificación E2E (T-40)
│   ├── manual_modulo_m2_repuestos.md # Manual de usuario del Módulo M2 (coordinador y técnico)
│   ├── features_pendientes.md        # Catálogo de features pendientes y roadmap
│   ├── auditoria_arquitectura.md     # Auditoría de arquitectura
│   ├── verificacion_*.md             # Informes de verificación (criterios de cierre, hitos H2/H4, humo de flujos, usabilidad 360px)
│   ├── propuesta_*.md                # Propuestas y decisiones pendientes del Product Owner (política de reintento del barrido, accesos de sede)
│   ├── informe_sonda_humo_flujo_modulo_11.md # Sonda de humo del flujo de pausa de sede
│   ├── handoff_rama_incident-comments.md     # Handoff de la rama de trabajo
│   └── constitutional_audit_report.md# Dictamen formal de auditoría
├── specs/
│   ├── 02-qr-codes/              # Especificación del módulo de códigos QR
│   ├── 03-metrics-audit/         # Especificación del módulo de métricas y auditoría
│   ├── 04-admin-crud/            # Especificación del panel de administración integral
│   ├── 05-preventive-maintenance/ # Especificación del mantenimiento preventivo y certificación sanitaria (M1)
│   ├── 06-spare-parts/           # Especificación del catálogo de repuestos y trazabilidad (M2)
│   ├── 07-route-map/             # Especificación del mapa interactivo de rutas y logística territorial (M4)
│   ├── 08-refunds/               # Especificación y plan del módulo de reintegros y dinero tragado (M5)
│   ├── 09-incident-detail-modal/ # Especificación del modal de detalle integral de incidencias
│   ├── 10-incident-comments/     # Especificación del hilo de conversación del expediente
│   ├── 11-pending-info-sla-pause/# Especificación de la pausa contractual de sede y del barrido sanitario
│   ├── 12-hybrid-supabase-migration/# Especificación de persistencia cloud híbrida en PostgreSQL (Supabase)
│   ├── 13-single-engine-supabase/# Especificación de consolidación definitiva en motor único PostgreSQL
│   ├── 14-notification-center-outbox/# Especificación de la bandeja de salida transaccional y canal de emergencias
│   ├── 15-telegram-technician-alerts/# Especificación de alertas directas a técnicos vía Bot de Telegram
│   ├── 16-opportunistic-incident/# Especificación de atención y asunción oportunista de averías en ruta
│   ├── 17-operational-zones/     # Especificación de zonificación operativa y asignación asistida por territorio
│   ├── 18-van-stock/             # Especificación del stock de repuestos en furgoneta
│   ├── 19-digital-signature/     # Especificación de firma digital en móvil y albarán de servicio
│   ├── 20-csat-satisfaction-survey/# Especificación de la encuesta de satisfacción post-resolución
│   ├── 21-absence-shift-handover/# Especificación de ausencias temporales y relevo de turno
│   ├── 22-technician-time-tracking/# Especificación de registro de jornada y horas efectivas
│   ├── 23-van-restock-stock-aware-triage/# Especificación de órdenes de carga y cruce de stock en triaje
│   ├── 24-offline-pwa/           # Especificación del modo sin conexión e instalabilidad móvil
│   ├── 25-root-cause-taxonomy/   # Especificación de taxonomía de causas raíz y análisis de Pareto
│   ├── 26-reassignment-history-custody-chain/# Historial de reasignaciones y cadena de custodia
│   ├── 27-workshop-refurbishment-loop/# Bandeja de taller central para piezas recuperadas
│   ├── 28-troubleshooting-guides/# Guías rápidas de diagnóstico por modelo
│   ├── 29-haccp-spoilage-log/    # Acta de merma y destrucción de lotes (APPCC/HACCP)
│   ├── 30-tiered-sla-contracts/  # Perfiles de SLA escalonados por tipo de sede
│   ├── 31-restocker-role-zones-shifts/# Rol de reponedor, zonificación de carga y jornada
│   ├── 32-product-catalog-planogram/# Catálogo de productos y planograma por máquina
│   ├── 33-product-loading-cold-picking/# Órdenes de carga de producto y picking con frío
│   ├── 34-in-situ-restock-visit/ # Visita de reposición in situ, FEFO y limpieza diaria
│   ├── functional/               # Especificaciones funcionales EARS transversales
│   └── technical/                # Contratos de API, DDL y esquemas de base de datos
├── src/
│   ├── Core/                     # Entidades inmutables, Enums, DTOs y Servicios de Dominio (Haversine, SLA, Repuestos)
│   ├── Application/              # Casos de uso de autenticación, optimización de rutas, métricas y auditoría
│   ├── Infrastructure/           # Repositorios PDO, conexión DB, uploader de imágenes y transportes nativos SMTP/HTTPS
│   └── Presentation/             # Router frontal, Middlewares RBAC y Controladores REST
├── public/                       # Raíz pública web (DocumentRoot)
│   ├── index.html                # Contenedor SPA del Frontend Vue.js
│   ├── index.php                 # Front Controller y despachador de assets
│   ├── docs/                     # Guías rápidas imprimibles para taller (A4, técnico de campo)
│   └── assets/
│       ├── css/
│       │   ├── design-tokens.css # Tokens CSS de Docker (#2560ff, tipografías, bordes, escalera --control-* de botones)
│       │   ├── metrics-print.css # Reglas de maquetación de informe A4 para impresión
│       │   └── qr-print.css      # Estilos de impresión de etiquetas QR industriales
│       └── js/
│           ├── app.js            # Montaje raíz Vue 3 con conmutador de perfiles
│           ├── api.js            # Cliente HTTP nativo fetch con descargas autenticadas
│           ├── store.js          # Almacén reactivo de sesión y alertas
│           ├── vendor/           # Vue 3 ESM y Chart.js 4.x embebidos (sin CDN ni npm)
│           ├── utils/            # Cartografía Web Mercator pura (Mercator.js) y gestos (MapZoomPan.js)
│           ├── components/       # Componentes UI (MetricCards, RouteMapModal, TerritorialMapTab, etc.)
│           └── views/            # Vistas (CoordinatorDashboardView, TechnicianRouteView, etc.)
└── tests/
    ├── run_all.php               # Ejecutor global de la batería de 441 suites (100% verde)
    ├── run_new_engine.php        # Canal propio de la categoría new_engine (PG_TEST_DSN)
    ├── bootstrap.php             # Autoloader compartido de las suites
    ├── Manual/                   # Ejecutor de verificación E2E manual (T-40)
    ├── unit/                     # Pruebas unitarias de lógica pura, geometría y contratos
    └── integration/              # Pruebas de persistencia real en el motor del arnés y API HTTP
```

---

## 📜 Licencia y Metodología
Proyecto desarrollado bajo la metodología **Specification-Driven Development (SDD)** conforme a la Constitución de VendGuard.
Octubre 2026.
