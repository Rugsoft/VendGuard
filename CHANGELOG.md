# Changelog · VendGuard

Registro de los hitos de producto y de los módulos cerrados bajo el ciclo SDD.
El proyecto no publica versiones semánticas independientes: cada entrada corresponde
a un módulo funcional certificado por la batería global.

## [Módulo 17] · Zonificación Operativa y Asignación Asistida — 2026-10-20

**Estado:** Cerrado · especificación funcional, contratos técnicos y batería de pruebas en verde.

### Añadido

- Zonas operativas delimitadas por códigos postales, con nombre, código único y color
  corporativo `#RRGGBB`, y liberación lógica del CP (`released_at`) para que reasignar
  un código nunca exija borrarlo (Art. III).
- Herencia automática de zona en la sede por su CP, con excepción manual atómica
  (`is_manual_override`) y auditoría del cambio; el panel de sedes señala los centros
  sin demarcación.
- Cuadrante de cobertura con zona principal y suplencias, protegido por el índice
  parcial `uq_technician_primary_zone`: un técnico no puede tener dos titularidades
  vivas y la degradación de la anterior queda registrada.
- Motor `TechnicianAssignmentSuggester` con `TechnicianWorkloadScorer`: afinidad
  territorial, presencia in situ, paradas en la misma zona, carga viva, pausas por
  falta de acceso y guardia sanitaria 24/7, con desempate determinista y fallback
  Haversine medido desde la parada actual, la última resuelta o la Base Central.
- Endpoint `GET /api/coordinator/incidents/{id}/suggested-technicians` con el ranking
  desglosado, y consola de administración territorial como cuarta subpestaña de
  Administración (zonas, CPs, cuadrante y traslados en bloque).
- Panel de asignación con despacho del técnico recomendado en dos toques
  (`[⚡ Asignar Sugerido]`), banner de emergencia sanitaria y campo obligatorio
  `override_justification` de 20 caracteres reales al descartar la recomendación de
  frío, auditado como `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN` sin tocar el
  `reassignment_reason` del flujo de reasignación (Art. II y Art. V.1).
- Filtro por zona operativa en el mapa territorial de coordinación, con los centros
  teñidos por el color de su demarcación y resuelto en cliente para no alterar el
  contrato cartográfico.
- Transferencia transaccional de códigos postales entre zonas: bloqueo `FOR UPDATE`
  de las sedes heredadas, reasignación en bloque y auditoría dual matriz-sede.

### Corregido (2026-10-20, auditoría de cierre)

- El panel de sedes incorpora la columna de zona operativa con el color de la
  demarcación y la insignia «Sin Zona Asignada» para los centros cuyo código postal
  no resuelve ninguna zona activa (RF-03.4).
- La edición de sede permite forzar una zona, dejarla sin zona o restablecer la
  herencia por código postal; la excepción viaja al servidor después del guardado y
  solo cuando el coordinador la cambia (RF-03.2, RF-03.3).
- El «cupo máximo de paradas» de RF-06.1 deja de ser una cláusula sin magnitud: se
  fija en diez paradas vivas por técnico (Resolución QA 14) y una zona con todo el
  cuadrante en el cupo se declara desatendida sin excluir a sus técnicos del ranking.
- La preservación de averías que exige RF-03.3 y los casos límite 3 y 4 queda
  certificada con aserciones sobre el traslado de CP, la desactivación de la zona y
  la baja del técnico del cuadrante.

### Corregido (2026-10-20, despliegue en Render)

- El arranque del contenedor vuelve a levantar sobre una base que ya servía antes de
  este módulo: `locations` existía sin `zone_id`, `CREATE TABLE IF NOT EXISTS` no
  añade columnas y el bloque de la clave foránea territorial abortaba el paso 3/6 con
  `SQLSTATE 42703` en los tres reintentos. El DDL base declara ahora las tres columnas
  territoriales de forma idempotente junto a la tabla, así que los índices y la clave
  foránea se materializan en el mismo paso y la migración `007` solo confirma.
- `CloudDeploySchemaParityTest` gana el caso 6: reproduce un `locations` anterior al
  módulo, reaplica el DDL base y certifica columnas, índice y clave foránea antes de
  la capa incremental, además de la convergencia final.

### Corregido (2026-10-20, modales territoriales)

- Los cinco diálogos de la consola territorial (alta, edición, liberación, códigos
  postales y cuadrante) se pintaban sobre la cáscara `vg-modal` a pelo: esa clase no
  declara relleno ni anatomía, así que el título y los controles tocaban el borde de la
  tarjeta y las acciones quedaban dentro del área que se desplaza. Ahora los cinco
  entran por el componente compartido `ModalDialog` —cabecera con hairline, cuerpo de
  20 px y pie separado—, igual que el resto de diálogos del panel, y ganan cierre con
  `Escape`, clic en el fondo, foco en el diálogo y bloqueo del desplazamiento de fondo.
- Los botones de envío viven en el pie pero siguen enviando el formulario del cuerpo
  (atributo `form`), de modo que el Enter del teclado no pierde su comportamiento.
- `OperationalZoneManagementTabTest` gana dos aserciones (1.10 y 1.11) que impiden
  reintroducir un diálogo fuera del componente compartido o sin pie de acciones.

### Seguridad

- El portal de la sede no recibe el nombre, código ni color de su zona operativa, ni
  la afinidad de los técnicos (Art. V.4).

### Verificado

- Batería global: 307 suites y 11.946 aserciones al 100 % en verde, con la guarda de
  coherencia documental activa y la base del arnés restaurada a semillas.
- Canal `new_engine` contra PostgreSQL: esquema territorial, repositorio, servicio de
  aplicación, traslado de CPs, API de zonas, sugerencias, salvaguarda de frío y
  privacidad de sede, todos en verde.
- Superficies Vue: consola territorial (45 aserciones), panel de asignación (32),
  mapa territorial con filtro por zona (101) y las 72 suites ESM sin fallos.

## [Módulo 16] · Avería Oportunista In Situ — 2026-10-19

**Estado:** Cerrado · especificación funcional, contratos técnicos y batería de pruebas en verde.

### Añadido

- Alta y asunción de averías sobre otras máquinas de la sede en la que el técnico ya
  está interviniendo, con modalidades `NEW` y `ASSUMED` y trazabilidad en auditoría.
- Ventana de gracia de 30 minutos tras resolver o pausar una parada, con tarjeta
  temporal «Sede recién visitada (30 min)» y margen de 5 minutos si el formulario ya
  estaba abierto.
- Flujo móvil de tres toques (`TechnicianOpportunisticModal`) con catálogo de máquinas
  de la sede, aviso sanitario de otra sede y bloqueo de convivencia entre sedes.
- Reapertura in situ en garantía de 48 horas y sustitución atómica del ticket por un
  sucesor limpio en la tercera reincidencia (avería crónica).
- Insignias «Oportunista In Situ · Nueva/Asumida» en triaje y ficha de detalle, KPI de
  desplazamientos evitados y desglose por submotivo.
- Aviso oportunista en el portal de sede con el técnico anonimizado como
  «Servicio Técnico Oficial · Operador #[Código]» (Art. V.4).
- Notificaciones de la nueva modalidad en el centro de salida: alerta prioritaria a
  Coordinación al posponer una parada de frío, aviso a la sede en reaperturas y
  desasignación por correo al técnico saliente (sin Telegram, RF-TG-06).

### Verificado

- Batería global: 293 suites y 11.507 aserciones al 100 % en verde, con la guarda de
  coherencia documental activa y la base del arnés restaurada a semillas.
- Cobertura específica del módulo: esquema, políticas puras, escrituras atómicas,
  notificaciones y privacidad, servicio de aplicación, API y superficies Vue.
