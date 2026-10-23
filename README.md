# VendGuard · Gestor de Incidencias de Vending

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B%20Vanilla%20POO-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net/)
[![Frontend](https://img.shields.io/badge/Vue.js%203-ES%20Modules%20(No%20Bundler)-4FC08D?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![Database](https://img.shields.io/badge/Database-PostgreSQL%2016%2B%20%7C%20Supabase-3ecf8e?style=flat-square&logo=postgresql&logoColor=white)](https://supabase.com/)
[![Design System](https://img.shields.io/badge/Design%20System-Docker%20Tokens%20(%232560ff)-2496ED?style=flat-square&logo=docker&logoColor=white)](docs/design.md)
[![Tests Status](https://img.shields.io/badge/Tests-325%20Suites%20%7C%2012.659%20Pass%20(100%25)-38bd7d?style=flat-square)](tests/)
[![Constitutional Status](https://img.shields.io/badge/Constitution-Audited%20%26%20Certified-003db5?style=flat-square)](constitution.md)

**VendGuard** es una plataforma web integral de nivel industrial para la gestión, triaje, intervención técnica, métricas de SLA y auditoría inmutable de averías en parques de máquinas de vending (bebidas calientes, frías, snacks y comida perecedera).

El sistema erradica de raíz los problemas críticos del sector vending mediante seis pilares:
1. **Preservación de la cadena de frío (Art. II Constitución):** Detección inmediata y forzado de prioridad máxima (**CRÍTICA / Innegociable**) en máquinas con alimentos perecederos con objetivo estricto de SLA < 4.0 horas.
2. **Prevención atómica de duplicados:** Índice atómico en base de datos (`uq_machine_active_ticket`) que imposibilita la creación de tickets concurrentes para una misma máquina.
3. **Justificación obligatoria de intervenciones (Art. V.1):** Prohibición terminante de resolver incidencias sin registrar diagnóstico técnico real ($\ge 20$ caracteres) y acción correctiva demostrable ($\ge 20$ caracteres).
4. **Trazabilidad y auditoría permanente (Art. III):** Borrado físico estrictamente prohibido (*Soft Delete* obligatorio) y registro inmutable *Append-Only* (`audit_log`) con ventana de garantía de 48 horas y detección de averías crónicas.
5. **Trazabilidad económica de repuestos (Módulo M2):** Catálogo maestro por modelo de máquina con *snapshot* inmutable de coste en cada intervención, clasificación cerrada de destino (`DESGUACE`/`TALLER`) y segregación estricta de datos de piezas y costes para el Responsable de Sede (Art. V.4).
6. **Cartografía territorial y optimización de rutas (Módulo M4):** Proyección pura Web Mercator y secuenciación heurística en 4 fases sin librerías de terceros ni claves de API privadas (Dogma Vanilla), con visión unificada para Coordinación y navegación GPS móvil asistida para Técnicos de Campo, garantizando estricta segregación de datos frente a clientes (Art. V.4).

---

## 🏛️ Filosofía de Ingeniería: Dogma Vanilla & Dualismo Lingüístico

El desarrollo se rige incondicionalmente por la [Constitución del Proyecto](constitution.md) y [AGENTS.md](AGENTS.md):

* **Dogma Vanilla (Cero Bloatware):**
  * **Backend:** PHP 8.2+ moderno puro orientado a objetos (*Clean Architecture*), tipado estricto en el 100% de ficheros (`declare(strict_types=1);`), persistencia nativa con **PDO** y generación algorítmica de códigos QR en SVG sin extensiones externas (`imagick` no requerida). Cero dependencias externas (`composer.json` / `vendor/` ausentes en runtime).
  * **Frontend:** Vue.js 3 estándar modularizado mediante **ES Modules** nativos (`type="module"`), consumiendo directamente la API REST con `fetch()`. Cero empaquetadores o dependencias pesadas (`node_modules/` ausente en runtime).
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
* **Estado operativo `PENDING_INFO` («Pendiente de información»):** el técnico o el coordinador aparcan la avería cuando falta acceso al inmueble o información de la sede, con causa tipificada y motivo de ≥ 20 caracteres. La pausa **congela el reloj contractual de SLA** y su vencimiento se desplaza en horario comercial de la sede.
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

### 16. Firma Digital del Responsable de Sede y Albarán de Servicio (Módulo 19)
* **Dos Vías Excluyentes en el Cierre:** El modal de resolución exige la conformidad de la sede. O se recoge la firma manuscrita digitalizada —nombre del firmante, documento y trazo sobre lienzo— o se atestigua «No había responsable disponible en la sede» con un motivo de al menos 10 caracteres. Activar una vía vacía los datos de la otra, y un bloque incompleto bloquea el envío señalando el campo.
* **Captura Táctil Real:** El trazo se sigue por eventos `pointer` con captura de puntero, de modo que el dedo puede salir del lienzo sin cortar la firma ni arrastrar la página (`touch-action: none`). El lienzo se pinta blanco opaco y se escala por `devicePixelRatio`, así que la firma no sale borrosa en pantallas de alta densidad. Borrar el trazo descarta el PNG y **no** toca el nombre ni el documento.
* **Tinta Decidida en el Cliente:** Menos de 150 píxeles con luminancia inferior a 200 es un lienzo en blanco. Es la única comprobación de tinta del sistema: el servidor no decodifica píxeles. El servidor sí valida el PNG real (firma binaria, MIME con `finfo`, `IHDR`), con techo de 300 KiB y 2 Mpx.
* **Atomicidad e Inmutabilidad:** La hoja se escribe en la misma transacción que piezas, furgoneta, solicitudes, transición a `RESOLVED` y notificación: si algo falla, nada de lo anterior queda. La tabla no admite `UPDATE` ni `DELETE`; una corrección exige una resolución nueva.
* **Documento Congelado:** `signed_document` guarda ticket, fecha de resolución, sede, máquina, técnico, diagnóstico, acción y piezas con nombre y cantidad en el instante del cierre. Una sede renombrada o una pieza recatalogada después no alteran el papel ya firmado.
* **Albarán A4 Imprimible:** Se compone en el navegador con `window.print()` y una hoja de estilos A4 que oculta la interfaz, admite varias páginas y nunca parte el bloque de firma. Sin costes, notas internas, SLA ni saldos. Tras una reapertura, el albarán vigente es el del último cierre; las visitas anteriores se consultan como históricas con su fecha, y una avería cerrada sin firma dice «Sin firma del responsable» sin reutilizar la firma anterior.
* **Acceso y Trazabilidad:** El técnico lee si la avería está asignada a él o si firmó alguna de sus hojas; coordinación lee cualquiera. El resto recibe `403 SERVICE_SHEET_FORBIDDEN`. Cada lectura deja un evento `VIEW_SERVICE_SHEET` y cada cierre registra si hubo firma, quién firmó y cuándo —nunca el trazo— o el motivo de ausencia.

---

## 👥 Actores del Sistema y Credenciales de Demostración

La aplicación cuenta con un conmutador de perfiles en la barra superior para alternar de forma inmediata entre roles:

| Perfil / Actor | Identificador / Email | Contraseña / Código | Función Principal |
| :--- | :--- | :--- | :--- |
| **🏢 1. Responsable de Sede** | `SEDE-BCN-01` *(Hospital del Mar)*<br>`SEDE-BCN-02` *(Torre Glòries)* | Código + `DEV-<site_code>` *(clave de desarrollo, ver nota)* | Supervisa máquinas del edificio, reporta averías (< 2 min), anexa evidencias y reabre en garantía. |
| **📊 2. Coordinador de Operaciones** | `coordinacion@vendguard.internal` | `Password123!` | Triaje central, supervisión 24/7 de SLAs, administración del parque y personal, preventivos y certificados sanitarios, catálogo y analítica de repuestos, cuadro de mando de métricas y visor de auditoría. Supervisa además la pausa de espera de sede (reanudación manual y cancelación por inactividad) y la superficie operativa del barrido sanitario. |
| **📱 3. Técnico de Ruta de Campo** | `jordi.ruta@vendguard.internal`<br>`marta.ruta@vendguard.internal` | `Password123!` | Interfaz vertical *mobile-first*, gestión de averías en ruta, inicio de intervención, pausa estructurada por repuestos o por falta de acceso al inmueble (pausa de sede), resolución justificada con piezas sustituidas, hilo de conversación del expediente, inspecciones preventivas, consulta del historial de la máquina y métricas individuales. |
| **🤳 4. Usuario / Consumidor Final** | Enlace QR (`?code=VEND-0101`) | *Público / Sin registro* | Reporte inmediato in situ escaneando la pegatina de la máquina, con feedback de estado si ya estaba reportada. |

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

# Cargar el histórico de demostración portable (17 averías canónicas INC-DEMO-*):
php bin/seed_demo_history.php
```

#### Entorno Cloud / Producción (Render + Supabase PostgreSQL):
El script [`bin/init_cloud_db.php`](bin/init_cloud_db.php) es el único punto de convergencia del esquema y no es tolerante a medias: aplica el DDL base (`database/supabase_init.sql`), **todas las migraciones incrementales** de `database/supabase_migrations/` (hoy 001–009, de ahí que una base ya desplegada converja en cada arranque), el catálogo maestro (`database/supabase_seeds.sql`) y el histórico de demostración. Todo está declarado con `IF NOT EXISTS` y semillas «insertar si no existe», así que reejecutarlo no duplica nada. Si cualquier paso falla, el script reintenta con espera progresiva y, agotados los intentos, **sale con código distinto de cero**: con el `&&` del arranque, el contenedor no levanta y no se sirve tráfico contra un esquema sin verificar (EARS-02.5).
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
| `POST`| `/api/technician/incidents/{id}/resolve`| `TECHNICIAN` | Resolución técnica documentada ($\ge 20$ chars diagnóstico y acción). |
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
| `POST` | `/api/technician/incidents/{id}/resume-pending-info` | `TECHNICIAN` | Reanudación manual in situ con el vencimiento desplazado en horario comercial. |
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

---

## 🧪 Batería Completa de Pruebas Automatizadas (T-39 & Módulos 02, 03, 04, M1, M2 y M4)

La integridad de VendGuard está certificada mediante un ejecutor de pruebas automatizado nativo, ejecutado en fases continuas (unitarias PHP, unitarias reactivas JS, integración HTTP real, guion E2E de los tres perfiles y guarda de coherencia entre las cifras documentadas y las ejecutadas):

```powershell
# Ejecutar la suite completa (Unitarias PHP, Unitarias JS e Integración PHP):
php tests/run_all.php
```

### Resumen de Ejecución Global (Octubre 2026)

La cifra vigente se declara una sola vez y la propia batería la audita (`tests/Support/DocMetricsGuard.php`), de modo que un desfase documental la pone en rojo:

batería global: 325 suites · 12.659 aserciones

```text
======================================================================
 RESUMEN DE EJECUCIÓN GLOBAL (T-39)
======================================================================
 Tiempo de ejecución total : 299.00 segundos  (variable en cada corrida)
 Suites de pruebas PHP Unit : 141 / 141 pasadas
 Suites de pruebas JS Unit  : 77 / 77 pasadas
 Suites de Integración PHP  : 106 / 106 pasadas
 Suites E2E Manuales (T-40): 1 / 1 pasadas
 ──────────────────────────────────────────────────────────────────
 Total Suites Ejecutadas    : 325
 Total Aserciones Evaluadas : 12.659
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
* **`CoordinatorButtonVariantsGuardTest.mjs`** — los 149 botones del panel del coordinador visten la escalera `--control-*` y ningún botón declara geometría ni color en línea (sí una colocación o un color de estado); la guarda se prueba contra muestras sintéticas y cae también si alguna variante declarada queda muerta.
* **`CoordinatorActionsWrapGuardTest.mjs`** — ningún grupo de acciones del panel depende de tener pocos controles: el grupo envuelve y la etiqueta nunca se parte dentro de su propio botón.
* **`SanitarySweepVisibilityServiceTest` / `CoordinatorSanitarySweepEndpointTest` (T-PAUSE-41)** — la superficie del barrido sanitario: umbrales de escalado a las tres pasadas consecutivas, cierre del seguimiento **por identidad** y el ciclo completo contra el motor del arnés sobre dos conexiones PDO reales (aplazamiento → cuarentena → cierre → alerta apagada).
* **`NotificationDispatcherAndSlaSweepTest` / `CoordinatorNotificationApiTest` (Módulo 14)** — certificación del ciclo completo de la bandeja de salida transaccional, rescate de envíos atascados en `PROCESSING`, escalado automático de SLA sanitario (2h/3h) y validación anti-SSRF de Webhooks.
* **`TelegramTransportTest` / `AdminUserTelegramLinkTest` (Módulo 15)** — certificación del canal y transporte HTTP hacia la API de Telegram, vinculación de chats de técnicos, deep linking a averías y entrega real de alertas de asignación.
* **`OpportunisticIncidentApiIntegrationTest` / `OpportunisticAtomicWritesTest` (Módulo 16)** — catálogo de máquinas de la sede, alta y asunción in situ, reapertura en garantía, sustitución atómica del ticket crónico y bloqueo de convivencia entre sedes bajo `FOR UPDATE`.
* **`PostgresZoneSchemaTest` / `OperationalZoneRepositoryTest` (Módulo 17)** — unicidad global del CP vivo por índice parcial, titularidad única por técnico, baja lógica de zonas y cuadrantes, y traslado transaccional de CPs con auditoría matriz-sede.
* **`SuggestedTechniciansApiTest` / `ColdChainOverrideJustificationTest` (Módulo 17)** — ranking determinista con mediana inferior a 100 ms, salvaguarda del Art. V.1 con umbral de 20 caracteres reales y registro del descarte en `audit_log`.
* **`OperationalZoneManagementTabTest.mjs` / `CoordinatorDashboardAssignPanelTest.mjs` (Módulo 17)** — consola territorial de administración, despacho del sugerido en dos toques, justificación sanitaria dinámica y filtro de zona del mapa de coordinación.
* **`OpportunisticFrontendIntegrationTest.mjs` (Módulo 16)** — tarjeta temporal de 30 minutos, entrada oportunista desde la parada activa y desde la inspección preventiva, agrupación en Parada #1, KPI de desplazamientos evitados y alias constitucional del técnico en el portal de sede.

> **Nota operativa:** las suites de integración incluyen pruebas de HTTP real contra `127.0.0.1:8000`. Si el puerto está ocupado por un servidor obsoleto, arránquelo antes con `php -S 127.0.0.1:8000 -t public public/index.php`.

### Canal propio del motor nuevo (`new_engine`)

Las suites que declaran `VENDGUARD_TEST_CATEGORY = 'new_engine'` exigen un PostgreSQL real y trabajan sobre esquemas aislados, así que **no forman parte de la batería hermética**: `run_all.php` las enumera y las deriva a un canal con comando propio (hallazgo H-4).

```bash
source deploy/local/postgres/.env.local     # motor local; .env.docker-tls para el TLS
php tests/run_new_engine.php                # las 14 suites del canal
php tests/run_new_engine.php Zone           # filtra por subcadena del nombre
```

El canal lee `PG_TEST_DSN` del entorno y **falla cerrado**: sin la variable, o con el motor inalcanzable, sale con código `2` y un mensaje explícito en lugar de omitir suites en silencio. Cada suite corre en su propio proceso y el resumen publica verdes, aserciones y fallos. Una suite que declara `exit 2` —la medición de latencia contra el motor remoto, que exige `DB_SSL_ROOT_CERT` y otra infraestructura— se lista como **no ejecutable**, con su motivo, y no se cuenta como fallo ni desaparece del informe.

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
│   ├── create_bizum_case.php     # Script CLI de caso demo de reintegro por Bizum
│   ├── sanitary_clock_sweep.php  # Barrido CLI del Reloj Sanitario Biológico (mismo reloj que el endpoint cron)
│   └── send_notifications.php    # Worker CLI del despachador de notificaciones (mismo grafo que el endpoint cron)
├── database/
│   ├── supabase_init.sql         # DDL canónico de PostgreSQL (20 tablas, 24 enumerados, disparadores)
│   ├── supabase_migrations/      # Migraciones incrementales canónicas del motor único
│   ├── supabase_seeds.sql        # Catálogo maestro de semillas del motor único
│   └── DemoHistorySeeder.php     # Histórico de demostración portable: 17 averías (13 cerradas y 4 en garantía, dos reabiertas), traza y auditoría
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
│           ├── vendor/           # Vue 3 ESM embebido (sin CDN ni npm)
│           ├── utils/            # Cartografía Web Mercator pura (Mercator.js) y gestos (MapZoomPan.js)
│           ├── components/       # Componentes UI (MetricCards, RouteMapModal, TerritorialMapTab, etc.)
│           └── views/            # Vistas (CoordinatorDashboardView, TechnicianRouteView, etc.)
└── tests/
    ├── run_all.php               # Ejecutor global de la batería de 325 suites (100% verde)
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
