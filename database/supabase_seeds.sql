-- =============================================================================
-- VendGuard · Semillas maestras del motor nuevo · ARRANQUE EN LIMPIO
-- PostgreSQL 15+ (certificado contra PostgreSQL 16.15 en deploy/local/postgres/)
-- Fichero: database/supabase_seeds.sql
-- Módulo: 12-hybrid-supabase-migration · T-HYBRID-03
-- =============================================================================
--
-- QUÉ ES ESTE FICHERO
--   El catálogo maestro con el que arranca en limpio una base de datos vacía del
--   motor nuevo (EARS-02.1): las 10 sedes con su código postal, las 3 zonas
--   operativas del Módulo 17 con sus códigos postales y el cuadrante técnico demo,
--   la configuración de la ruta base, las 13 máquinas del parque, el personal
--   interno con su contraseña hasheada, las frecuencias normativas del preventivo,
--   el catálogo maestro de repuestos con sus compatibilidades por modelo, el
--   catálogo de taxonomía de causas raíz y soluciones estandarizadas del Módulo
--   25 (16 causas y 34 soluciones) y las cuatro órdenes de carga demo del Módulo
--   23 (una por estado del ciclo). Solo
--   datos: no crea ni modifica esquema, que es responsabilidad de
--   `database/supabase_init.sql` y de `database/supabase_migrations/`.
--
-- LO QUE ESTE FICHERO NO CONTIENE (deliberadamente)
--   · Ninguna incidencia, ni histórica ni activa, ni comentarios, ni marcas de
--     auditoría de averías. El arranque en limpio es estricto (EARS-02.1, spec
--     §7): los expedientes de la base anterior quedan archivados en el sistema
--     previo. La Sección 14 sí siembra la jornada demo del Módulo 22 —dos turnos
--     cerrados, su bitácora y su auditoría laboral—, porque el registro horario
--     necesita datos demostrables sin inventar incidencias.
--   · Ningún pedido preventivo, certificado sanitario ni checklist respondido:
--     son *fixtures* del arnés de pruebas (EARS-02.2), y viven en
--     `database/DemoMetricsSeeder.php` precisamente para que producción no los
--     herede.
--   · Ninguna clave de centro de sede (`access_code_hash`). La clave de centro es
--     un secreto operativo y se entrega por coordinación; sembrar una conocida
--     sería entregar el portal de sede a quien lea el repositorio (hallazgo S-4).
--     El arnés sí las siembra, y solo fuera de producción, desde `SeedRunner`.
--   · Ninguna imagen ni binario: el motor guarda exclusivamente datos tabulares
--     (EARS-05.1).
--   · Ninguna marca oportunista (`opportunistic_mode`, `opportunistic_origin_reason`,
--     `opportunistic_informant_name`, `chronic_predecessor_incident_id`): la avería
--     oportunista in situ (Módulo 16) nace de una visita real, nunca de una semilla,
--     de modo que el arranque en limpio deja esas cuatro columnas a NULL.
--
-- UNA SOLA REGLA DE UPSERT, DOS RENDERIZADOS
--   `ON CONFLICT ... DO UPDATE` no tiene forma común con el motor local, que
--   escribe `ON DUPLICATE KEY UPDATE ... VALUES(...)`. La regla —qué clave
--   identifica cada fila del catálogo y qué campos se refrescan al reencontrarla—
--   se declara **una vez** y tiene dos traducciones de su sintaxis: la de este
--   fichero y la que resolverá el adaptador de dialecto (T-HYBRID-25), que
--   `SeedRunner` consumirá en lugar de sostener un `ON CONFLICT` inexistente en
--   MariaDB (T-HYBRID-12). El adaptador se escribe **conforme a esta tabla**, y no
--   al revés:
--
--   | Tabla                      | Clave de conflicto                  | Campos refrescados al reencontrar la fila                                                    |
--   |----------------------------|-------------------------------------|----------------------------------------------------------------------------------------------|
--   | locations                  | `site_code_key` (canónica, A-2)     | `site_code`, `name`, `address`, `latitude`, `longitude`, `contact_name`, `contact_phone`, `deleted_at = NULL` |
--   | route_settings             | ninguna (fila singleton sin clave)  | ninguno: se inserta solo si no existe                                                        |
--   | machines                   | `code`                              | `location_id`, `model`, `machine_type`, `floor_wing`, `notes`, `sanitary_status`, `next_sanitary_inspection_due`, `deleted_at = NULL` |
--   | users                      | `email_key` (canónica, A-1)         | `email`, `name`, `password_hash`, `role`, `operator_code`, `phone`, `deleted_at = NULL`       |
--   | preventive_settings        | `machine_type`                      | `default_frequency_days`, `max_allowed_days`, `advance_warning_days`                          |
--   | spare_parts                | `part_code`                         | `name`, `category`, `manufacturer`, `reference_cost`, `is_active`                            |
--   | spare_part_compatibilities | `(spare_part_id, machine_model)`    | ninguno: el par se asegura con `DO NOTHING` (así lo declara el motor local)                   |
--   | operational_zones          | `code` (única)                      | ninguno: se inserta solo si no existe (Módulo 17)                                            |
--   | operational_zone_postal_codes | `postal_code` (clave primaria)    | ninguno: se inserta solo si no existe; el CP es único en todo el catálogo (QA 7)             |
--   | technician_zone_assignments | `(technician_id, zone_id)` vivo     | ninguno: se inserta solo si no existe (la baja es lógica, QA 10)                              |
--   | technician_van_stocks      | `(technician_id, spare_part_id)`    | ninguno: se inserta solo si no existe (Módulo 18)                                            |
--   | van_restock_orders         | `(technician_id, created_at)` de la semilla | ninguno: se inserta solo si no existe (Módulo 23)                                    |
--   | van_restock_order_items    | `(order_id, spare_part_id)`         | ninguno: se inserta solo si no existe (Módulo 23; la retirada es lógica, QA 1)              |
--
--   Dos consecuencias buscadas de esa regla:
--     1. Reejecutarlo no duplica nada y deja el catálogo igual: el arranque del
--        contenedor puede invocarlo en cada pasada.
--     2. Reafirma la fila maestra, incluido su `deleted_at = NULL`, sin tocar
--        contadores de sesión ni credenciales de sede.
--   `route_settings` es la única excepción estructural y está declarada: en el
--   motor nuevo su identidad es una fila `GENERATED ALWAYS AS IDENTITY` sin clave
--   natural, de modo que el renderizado idempotente es «insertar si no existe»,
--   que es exactamente lo que hace el `ON DUPLICATE KEY UPDATE id = VALUES(id)`
--   del motor local (un no-op al reencontrarla).
--
-- CLAVES CANÓNICAS (EARS-08.5, A-1/A-2, D-5)
--   El motor local declara `UNIQUE` sobre `site_code` y `email` con una colación
--   insensible a mayúsculas **y acentos**. `lower()` no reproduce esa semántica,
--   así que la unicidad se resuelve en el motor nuevo con las columnas canónicas
--   `site_code_key` y `email_key`, calculadas por la aplicación. Estas semillas
--   las escriben explícitamente porque son `NOT NULL`: una fila sin clave
--   normalizada no sería un duplicado invisible, sería un alta incompleta. Todos
--   los identificadores sembrados son ASCII sin acentos, de modo que su clave
--   canónica es su forma en minúsculas, y eso es exactamente lo que se escribe
--   (verificado, no deducido).
--
-- FUENTE DEL CATÁLOGO Y DIVERGENCIA DECLARADA
--   El catálogo de este fichero reproduce el **catálogo vivo y certificado** —el
--   que produce `SeedRunner` y sobre el que corre la batería hermética—, que es lo
--   que el contrato de correspondencia toma como fuente de verdad, y no una
--   suposición leída de un fichero `.sql`.
--   Divergencia declarada y pendiente de alinear: el bloque de compatibilidades de
--   `database/cloud_init.sql` declara **52** pares frente a los **50** del catálogo
--   vivo y de este fichero; su regla es más ancha y admite que un grupo de infusión
--   de café y un cartucho de filtración de caldera sean compatibles con
--   `Azkoyen Palma+`, que es la máquina de bebidas frías `VEND-0601`/`VEND-0901`.
--   Este fichero no hereda esa anchura a propósito: el motor nuevo arranca con el
--   catálogo que las pruebas certifican. Alinear `cloud_init.sql` es una decisión
--   del catálogo, no de este módulo, y queda reportada.
--
-- CÓMO SE APLICA
--   Sobre un esquema ya creado por `database/supabase_init.sql`. El arranque del
--   contenedor lo aplica a través del ejecutor de migraciones del motor activo
--   (`bin/init_cloud_db.php` y `PostgresMigrationRunner`, T-HYBRID-12/23); este
--   fichero no lleva sentencias de control de transacción, para que decida quien
--   lo invoca.
--
-- =============================================================================


-- =============================================================================
-- SECCIÓN 1 · locations — las 10 sedes iniciales
-- =============================================================================
-- `site_code_key` es la clave canónica (minúsculas) que la aplicación calcula;
-- `latitude`/`longitude` son las coordenadas reales de cada sede, sin las cuales
-- el mapa territorial y el cálculo de rutas no tienen nada que ordenar.

-- El código postal (Resolución QA 7) es obligatorio en el alta de una sede y
-- resuelve por herencia su zona operativa: cada una de las diez sedes sembradas
-- lleva el CP real de su dirección, y la Sección 12 lo traduce a zona.
INSERT INTO locations (
    site_code, site_code_key, name, address, latitude, longitude, contact_name, contact_phone, contact_email, postal_code
) VALUES
    ('SEDE-BCN-01', 'sede-bcn-01', 'Hospital del Mar - Edificio Central', 'Passeig Marítim 25, Barcelona', 41.3853120, 2.1932450, 'Laura Sanitaria', '600111222', 'contacto.sede-bcn-01@sede.vendguard.es', '08003'),
    ('SEDE-BCN-02', 'sede-bcn-02', 'Torre Glòries - Planta 4 Oficinas', 'Avinguda Diagonal 211, Barcelona', 41.4036290, 2.1895120, 'Marc Recepción', '600333444', 'contacto.sede-bcn-02@sede.vendguard.es', '08018'),
    ('SEDE-BCN-03', 'sede-bcn-03', 'Hospital Universitari de Bellvitge', 'Carrer de la Feixa Llarga s/n, L''Hospitalet de Llobregat', 41.3458200, 2.1075400, 'Carles Coordinador', '600555666', 'contacto.sede-bcn-03@sede.vendguard.es', '08907'),
    ('SEDE-BCN-04', 'sede-bcn-04', 'World Trade Center Barcelona', 'Moll de Barcelona s/n, Barcelona', 41.3725000, 2.1819000, 'Núria Port', '600777888', 'contacto.sede-bcn-04@sede.vendguard.es', '08039'),
    ('SEDE-BCN-05', 'sede-bcn-05', 'Campus Nord UPC - Edifici Nexus', 'Carrer del Gran Capità 2, Barcelona', 41.3888000, 2.1123000, 'Albert Campus', '600999000', 'contacto.sede-bcn-05@sede.vendguard.es', '08034'),
    ('SEDE-BCN-06', 'sede-bcn-06', 'Parc Tecnològic Barcelona Activa', 'Carrer Marie Curie 8, Nou Barris, Barcelona', 41.4392000, 2.1758000, 'Clara Innovació', '611222333', 'contacto.sede-bcn-06@sede.vendguard.es', '08042'),
    ('SEDE-BCN-07', 'sede-bcn-07', 'Badalona Centre Mèdic Can Ruti', 'Carretera de Canyet s/n, Badalona', 41.4645000, 2.2421000, 'Sergi Logística', '611444555', 'contacto.sede-bcn-07@sede.vendguard.es', '08916'),
    ('SEDE-BCN-08', 'sede-bcn-08', 'Sant Cugat Trade Center', 'Avinguda de les Corts Catalanes 5, Sant Cugat del Vallès', 41.4712000, 2.0689000, 'Gemma Gestió', '611666777', 'contacto.sede-bcn-08@sede.vendguard.es', '08195'),
    ('SEDE-BCN-09', 'sede-bcn-09', 'Fira Gran Via - Pavelló 1', 'Avinguda Joan Carles I 64, L''Hospitalet de Llobregat', 41.3547000, 2.1284000, 'Pau Esdeveniments', '611888999', 'contacto.sede-bcn-09@sede.vendguard.es', '08908'),
    ('SEDE-BCN-10', 'sede-bcn-10', 'WTC Almeda Park Cornellà', 'Plaça de la Pau s/n, Cornellà de Llobregat', 41.3533000, 2.0862000, 'Mireia Serveis', '622111222', 'contacto.sede-bcn-10@sede.vendguard.es', '08940')
ON CONFLICT (site_code_key) DO UPDATE SET
    site_code    = EXCLUDED.site_code,
    name         = EXCLUDED.name,
    address      = EXCLUDED.address,
    latitude     = EXCLUDED.latitude,
    longitude    = EXCLUDED.longitude,
    contact_name = EXCLUDED.contact_name,
    contact_phone = EXCLUDED.contact_phone,
    contact_email = EXCLUDED.contact_email,
    postal_code  = EXCLUDED.postal_code,
    deleted_at   = NULL;


-- =============================================================================
-- SECCIÓN 2 · route_settings — la Base Central de rutas (fila singleton)
-- =============================================================================
-- Sin clave natural: la identidad de la fila es su propia existencia. El radio
-- operativo y los límites del parque son los valores por defecto declarados en el
-- DDL base (100 km y el rectángulo del territorio), y no se reescriben aquí.

INSERT INTO route_settings (base_name, base_address, base_latitude, base_longitude)
SELECT v.base_name, v.base_address, v.base_latitude, v.base_longitude
FROM (VALUES (
    'Base Central VendGuard Barcelona',
    'Carrer de la Marina 100, 08018 Barcelona',
    41.3935000,
    2.1890000
)) AS v(base_name, base_address, base_latitude, base_longitude)
WHERE NOT EXISTS (SELECT 1 FROM route_settings);


-- =============================================================================
-- SECCIÓN 3 · machines — las 13 máquinas maestras
-- =============================================================================
-- La sede se resuelve por su código (no por un identificador que la secuencia
-- asigna distinto en cada motor). El vencimiento de la inspección sanitaria se
-- calcula con la misma regla normativa que declara el motor local: **15 días** en
-- máquinas con producto perecedero (perecederos y combo) y **30 días** en el
-- resto. La lógica de cálculo del reloj de frío vive en el servicio canónico
-- (`SanitaryClockSweepService`), no aquí: esto solo es el catálogo del parque.

INSERT INTO machines (
    location_id, code, model, machine_type, floor_wing, notes, sanitary_status, next_sanitary_inspection_due
) VALUES
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-01'), 'VEND-0101', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Planta Baja - Urgencias', 'Máquina de sándwiches y lácteos frescos', 'OK', CURRENT_DATE + 15),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-01'), 'VEND-0102', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 1 - Sala Médica', 'Café en grano y bebidas calientes', 'OK', CURRENT_DATE + 30),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-02'), 'VEND-0201', 'Necta Samba Combo', 'COMBO', 'Planta 4 - Office Este', 'Snacks y refrescos variados', 'OK', CURRENT_DATE + 15),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-03'), 'VEND-0301', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Edificio Principal - Hall Consultas', 'Comida fresca y ensaladas preparadas', 'OK', CURRENT_DATE + 15),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-03'), 'VEND-0302', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 2 - Sala Descanso Personal', 'Café de especialidad e infusiones', 'OK', CURRENT_DATE + 30),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-04'), 'VEND-0401', 'Necta Samba Combo', 'COMBO', 'Edifici Est - Planta Baixa Lobby', 'Bebidas isotónicas y aperitivos saludables', 'OK', CURRENT_DATE + 15),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-05'), 'VEND-0501', 'Fas Fast 900', 'SNACKS', 'Edifici Nexus I - Entrada Estudiants', 'Aperitivos, frutos secos y barritas energéticas', 'OK', CURRENT_DATE + 30),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-05'), 'VEND-0502', 'Fas Perla', 'HOT_DRINKS', 'Edifici Nexus I - Sala Professorat', 'Café largo, cortado y chocolate', 'OK', CURRENT_DATE + 30),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-06'), 'VEND-0601', 'Azkoyen Palma+', 'COLD_DRINKS', 'Coworking Principal - Zona Cafeteria', 'Aguas minerales, zumos y refrescos en lata/botella', 'OK', CURRENT_DATE + 30),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-07'), 'VEND-0701', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Planta 0 - Accés Visitants', 'Sandwiches envasados y yogures refrigerados', 'OK', CURRENT_DATE + 15),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-08'), 'VEND-0801', 'Necta Samba Combo', 'COMBO', 'Atri Central - Planta Baixa', 'Bebidas frías y aperitivos mixtos', 'OK', CURRENT_DATE + 15),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-09'), 'VEND-0901', 'Azkoyen Palma+', 'COLD_DRINKS', 'Pavelló 1 - Porta Nord', 'Bebidas frías de alta rotación para ferias', 'OK', CURRENT_DATE + 30),
    ((SELECT id FROM locations WHERE site_code = 'SEDE-BCN-10'), 'VEND-1001', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 1 - Mòdul B Corporatiu', 'Servicio de café continuo para oficinas', 'OK', CURRENT_DATE + 30)
ON CONFLICT (code) DO UPDATE SET
    location_id                  = EXCLUDED.location_id,
    model                        = EXCLUDED.model,
    machine_type                 = EXCLUDED.machine_type,
    floor_wing                   = EXCLUDED.floor_wing,
    notes                        = EXCLUDED.notes,
    sanitary_status              = EXCLUDED.sanitary_status,
    next_sanitary_inspection_due = EXCLUDED.next_sanitary_inspection_due,
    deleted_at                   = NULL;


-- =============================================================================
-- SECCIÓN 4 · users — el personal interno
-- =============================================================================
-- `email_key` es la clave canónica (minúsculas) que la aplicación calcula.
--
-- Sobre la contraseña: EARS-02.3 exige que las credenciales maestras se
-- inicialicen hasheadas con un algoritmo criptográfico estándar. El hash de abajo
-- es Bcrypt de coste 10 —el mismo que usan `password_hash` en el proyecto y el
-- mismo que el motor local siembra— y **debe rotarse** desde el módulo de
-- administración antes de poner el sistema en servicio. Lo que nunca se escribe
-- aquí es la contraseña en claro ni la clave de centro de ninguna sede.

INSERT INTO users (
    name, email, email_key, password_hash, role, operator_code, phone
) VALUES
    ('Sara Coordinadora', 'coordinacion@vendguard.internal', 'coordinacion@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'COORDINATOR', NULL, '677000111'),
    ('Jordi Técnico Ruta BCN', 'jordi.ruta@vendguard.internal', 'jordi.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-01', '677222333'),
    ('Marta Técnica Ruta BCN', 'marta.ruta@vendguard.internal', 'marta.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-02', '677444555'),
    ('Carlos Técnico Ruta Sud', 'carlos.ruta@vendguard.internal', 'carlos.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-03', '677666777'),
    ('Elena Técnica Ruta Nord', 'elena.ruta@vendguard.internal', 'elena.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-04', '677888999'),
    ('Marc Técnico Express BCN', 'marc.ruta@vendguard.internal', 'marc.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-05', '677112233')
ON CONFLICT (email_key) DO UPDATE SET
    email         = EXCLUDED.email,
    name          = EXCLUDED.name,
    password_hash = EXCLUDED.password_hash,
    role          = EXCLUDED.role,
    operator_code = EXCLUDED.operator_code,
    phone         = EXCLUDED.phone,
    deleted_at    = NULL;


-- =============================================================================
-- SECCIÓN 5 · preventive_settings — frecuencias normativas por tipo de máquina
-- =============================================================================
-- Máximo legal y aviso previo de cada tipo. Los 15 días de los perecederos son
-- el techo sanitario que fija el Art. II: no se amplían por configuración.

INSERT INTO preventive_settings (
    machine_type, default_frequency_days, max_allowed_days, advance_warning_days
) VALUES
    ('PERISHABLE_FOOD', 15, 15, 5),
    ('HOT_DRINKS',      30, 60, 5),
    ('COLD_DRINKS',     45, 90, 5),
    ('SNACKS',          60, 90, 5),
    ('COMBO',           15, 45, 5)
ON CONFLICT (machine_type) DO UPDATE SET
    default_frequency_days = EXCLUDED.default_frequency_days,
    max_allowed_days       = EXCLUDED.max_allowed_days,
    advance_warning_days   = EXCLUDED.advance_warning_days;


-- =============================================================================
-- SECCIÓN 6 · spare_parts — el catálogo maestro de repuestos
-- =============================================================================

INSERT INTO spare_parts (
    part_code, name, category, manufacturer, reference_cost, is_active, notes
) VALUES
    ('VALV-ULKA-01',   'Electroválvula 24V 2 Vías Ulka', 'HYDRAULIC', 'Ulka', 28.50, TRUE, 'Válvula de entrada de agua para calderas espresso'),
    ('BOMB-VIB-02',    'Bomba Vibratoria 230V EX5', 'HYDRAULIC', 'Ulka / CEME', 36.00, TRUE, 'Bomba de presión estándar para café'),
    ('SOND-NTC-01',    'Sonda Térmica NTC 10K Roscada', 'THERMAL', 'Carel', 15.20, TRUE, 'Sonda de temperatura para cámaras refrigeradas'),
    ('TERM-SEG-01',    'Termostato de Seguridad 135°C', 'THERMAL', 'Campini', 12.80, TRUE, 'Rearme manual de seguridad para grupo térmico'),
    ('MOT-ESP-01',     'Motor Extractor de Espiral 24V DC', 'MECHANICAL', 'Sande / Merkle', 24.50, TRUE, 'Motor con microinterruptor de posición'),
    ('MON-CASH-01',    'Monedero Selector de Monedas NRI G13', 'PAYMENT_SYSTEM', 'Crane / CPI', 185.00, TRUE, 'Validador estándar MDB multimoneda'),
    ('DISP-LCD-01',    'Display LCD Gráfico 128x64 Azul', 'ELECTRONIC', 'Winstar', 42.00, TRUE, 'Pantalla frontal de selección de usuario'),
    ('JUNT-TOR-01',    'Kit 10 Juntas Tóricas Silicona Alimentaria', 'CONSUMABLE', 'Parker', 8.50, TRUE, 'Juntas de estanqueidad para grupo de café y pistón'),
    ('TELEC-NAYAX-01', 'Lector Contactless & Telemetría VPOS Touch', 'PAYMENT_SYSTEM', 'Nayax', 295.00, TRUE, 'Terminal de pago con tarjeta, móvil contactless y telemetría 4G MDB'),
    ('FOTO-CAID-01',   'Sensor Fotocélula Detección Caída de Producto', 'ELECTRONIC', 'Omron / Fas', 38.00, TRUE, 'Barrera óptica infrarroja de validación de entrega de producto'),
    ('VENT-EVAP-01',   'Motor Ventilador Evaporador No-Frost 230V', 'THERMAL', 'Ebm-Papst', 46.50, TRUE, 'Turbina de recirculación de aire frío en cabina refrigerada'),
    ('GRUP-ESPR-01',   'Grupo Infusor de Café Espresso Z4000', 'MECHANICAL', 'N&W / Bianchi', 145.00, TRUE, 'Módulo de erogación de café en grano con cámara de compresión variable'),
    ('FILT-AGUA-01',   'Cartucho Filtración Antical y Carbón Activo', 'CONSUMABLE', 'Brita Professional', 58.00, TRUE, 'Filtro descalcificador de agua potable para calderas vending')
ON CONFLICT (part_code) DO UPDATE SET
    name           = EXCLUDED.name,
    category       = EXCLUDED.category,
    manufacturer   = EXCLUDED.manufacturer,
    reference_cost = EXCLUDED.reference_cost,
    is_active      = EXCLUDED.is_active;


-- =============================================================================
-- SECCIÓN 7 · spare_part_compatibilities — compatibilidad repuesto ↔ modelo
-- =============================================================================
-- Los 50 pares del catálogo vivo, para que el motor nuevo no herede la regla más
-- ancha que declara `database/cloud_init.sql` (ver la divergencia declarada en la
-- cabecera). Cada par es una fila del modelo de datos; el repuesto se resuelve por
-- su código para no depender de la secuencia de identidad de otro motor.

INSERT INTO spare_part_compatibilities (spare_part_id, machine_model)
SELECT sp.id, c.machine_model
FROM (VALUES
    ('VALV-ULKA-01',   'Bianchi Gaia Espresso'),
    ('VALV-ULKA-01',   'Fas Perla'),
    ('VALV-ULKA-01',   'Azkoyen Palma+'),
    ('BOMB-VIB-02',    'Bianchi Gaia Espresso'),
    ('BOMB-VIB-02',    'Fas Perla'),
    ('BOMB-VIB-02',    'Azkoyen Palma+'),
    ('SOND-NTC-01',    'Sanden Vendo G-Drink'),
    ('SOND-NTC-01',    'Necta Samba Combo'),
    ('SOND-NTC-01',    'Azkoyen Palma+'),
    ('SOND-NTC-01',    'Fas Fast 900'),
    ('TERM-SEG-01',    'Bianchi Gaia Espresso'),
    ('TERM-SEG-01',    'Fas Perla'),
    ('TERM-SEG-01',    'Azkoyen Palma+'),
    ('MOT-ESP-01',     'Sanden Vendo G-Drink'),
    ('MOT-ESP-01',     'Necta Samba Combo'),
    ('MOT-ESP-01',     'Azkoyen Palma+'),
    ('MOT-ESP-01',     'Fas Fast 900'),
    ('MON-CASH-01',    'Sanden Vendo G-Drink'),
    ('MON-CASH-01',    'Bianchi Gaia Espresso'),
    ('MON-CASH-01',    'Necta Samba Combo'),
    ('MON-CASH-01',    'Azkoyen Palma+'),
    ('MON-CASH-01',    'Fas Fast 900'),
    ('MON-CASH-01',    'Fas Perla'),
    ('DISP-LCD-01',    'Sanden Vendo G-Drink'),
    ('DISP-LCD-01',    'Bianchi Gaia Espresso'),
    ('DISP-LCD-01',    'Necta Samba Combo'),
    ('DISP-LCD-01',    'Azkoyen Palma+'),
    ('DISP-LCD-01',    'Fas Fast 900'),
    ('DISP-LCD-01',    'Fas Perla'),
    ('JUNT-TOR-01',    'Bianchi Gaia Espresso'),
    ('JUNT-TOR-01',    'Fas Perla'),
    ('JUNT-TOR-01',    'Azkoyen Palma+'),
    ('TELEC-NAYAX-01', 'Sanden Vendo G-Drink'),
    ('TELEC-NAYAX-01', 'Bianchi Gaia Espresso'),
    ('TELEC-NAYAX-01', 'Necta Samba Combo'),
    ('TELEC-NAYAX-01', 'Azkoyen Palma+'),
    ('TELEC-NAYAX-01', 'Fas Fast 900'),
    ('TELEC-NAYAX-01', 'Fas Perla'),
    ('FOTO-CAID-01',   'Sanden Vendo G-Drink'),
    ('FOTO-CAID-01',   'Necta Samba Combo'),
    ('FOTO-CAID-01',   'Azkoyen Palma+'),
    ('FOTO-CAID-01',   'Fas Fast 900'),
    ('VENT-EVAP-01',   'Sanden Vendo G-Drink'),
    ('VENT-EVAP-01',   'Necta Samba Combo'),
    ('VENT-EVAP-01',   'Azkoyen Palma+'),
    ('VENT-EVAP-01',   'Fas Fast 900'),
    ('GRUP-ESPR-01',   'Bianchi Gaia Espresso'),
    ('GRUP-ESPR-01',   'Fas Perla'),
    ('FILT-AGUA-01',   'Bianchi Gaia Espresso'),
    ('FILT-AGUA-01',   'Fas Perla')
) AS c(part_code, machine_model)
JOIN spare_parts sp ON sp.part_code = c.part_code
ON CONFLICT (spare_part_id, machine_model) DO NOTHING;


-- =============================================================================
-- SECCIÓN 8 · notification_channel_settings — Canal Corporativo de Emergencias
-- =============================================================================
-- Configuración por defecto del canal corporativo de emergencias (desactivado hasta configuración).
INSERT INTO notification_channel_settings (webhook_name, webhook_url, is_webhook_enabled)
SELECT v.webhook_name, v.webhook_url, v.is_webhook_enabled
FROM (VALUES (
    'Canal Central de Emergencias',
    NULL::VARCHAR(500),
    FALSE
)) AS v(webhook_name, webhook_url, is_webhook_enabled)
WHERE NOT EXISTS (SELECT 1 FROM notification_channel_settings);


-- =============================================================================
-- SECCIÓN 9 · operational_zones — las tres zonas operativas de Barcelona
-- =============================================================================
-- El parque sembrado se reparte en tres demarcaciones con un color corporativo
-- distinto, que es el token con el que el mapa territorial pinta cada sede. Se
-- insertan solo si no existen (no añaden una cláusula de upsert nueva, ver la
-- cabecera): reejecutar el arranque no duplica el catálogo.

INSERT INTO operational_zones (code, name, color_hex, description)
SELECT v.code, v.name, v.color_hex, v.description
FROM (VALUES
    ('ZONA-BCN-NORTE',  'Barcelona Litoral y Norte', '#2560ff', 'Sant Martí, Poblenou, Nou Barris y Badalona'),
    ('ZONA-BCN-CENTRO', 'Barcelona Centro y Litoral', '#10b981', 'Ciutat Vella, Eixample y Campus Nord (Les Corts)'),
    ('ZONA-BCN-SUR',    'Barcelona Sur y Vallès',     '#f59e0b', 'Bellvitge, Fira Gran Via, Cornellà y Sant Cugat')
) AS v(code, name, color_hex, description)
WHERE NOT EXISTS (
    SELECT 1 FROM operational_zones z WHERE z.code = v.code
);


-- =============================================================================
-- SECCIÓN 10 · operational_zone_postal_codes — el CP como llave territorial
-- =============================================================================
-- Los diez códigos postales de las sedes sembradas, cada uno en una sola zona:
-- el índice único parcial `uq_zone_postal_codes_live` prohíbe que el mismo CP
-- viva en dos demarcaciones (RF-01.2, QA 7). El CP se resuelve por el código de
-- la zona para no depender del identificador que asigna la secuencia. Solo se
-- considera ocupado si su asignación está **viva** (QA 13).

INSERT INTO operational_zone_postal_codes (postal_code, zone_id)
SELECT v.postal_code, z.id
FROM (VALUES
    ('08018', 'ZONA-BCN-NORTE'),
    ('08042', 'ZONA-BCN-NORTE'),
    ('08916', 'ZONA-BCN-NORTE'),
    ('08003', 'ZONA-BCN-CENTRO'),
    ('08039', 'ZONA-BCN-CENTRO'),
    ('08034', 'ZONA-BCN-CENTRO'),
    ('08907', 'ZONA-BCN-SUR'),
    ('08908', 'ZONA-BCN-SUR'),
    ('08940', 'ZONA-BCN-SUR'),
    ('08195', 'ZONA-BCN-SUR')
) AS v(postal_code, zone_code)
JOIN operational_zones z ON z.code = v.zone_code
WHERE NOT EXISTS (
    SELECT 1 FROM operational_zone_postal_codes p
     WHERE p.postal_code = v.postal_code
       AND p.released_at IS NULL
);


-- =============================================================================
-- SECCIÓN 11 · technician_zone_assignments — el cuadrante demo
-- =============================================================================
-- Un titular por zona (`is_primary = TRUE`), que es el máximo que admite el
-- índice parcial `uq_technician_primary_zone`, y dos técnicos de respaldo con
-- suplencias repartidas. La vinculación se resuelve por la clave canónica del
-- usuario (`email_key`) y por el código de la zona, nunca por identificadores
-- que la secuencia asigna distinto en cada base.

INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
SELECT u.id, z.id, v.is_primary
FROM (VALUES
    ('elena.ruta@vendguard.internal',  'ZONA-BCN-NORTE',  TRUE),
    ('marta.ruta@vendguard.internal',  'ZONA-BCN-CENTRO', TRUE),
    ('carlos.ruta@vendguard.internal', 'ZONA-BCN-SUR',    TRUE),
    ('jordi.ruta@vendguard.internal',  'ZONA-BCN-NORTE',  FALSE),
    ('jordi.ruta@vendguard.internal',  'ZONA-BCN-CENTRO', FALSE),
    ('marc.ruta@vendguard.internal',   'ZONA-BCN-SUR',    FALSE),
    ('marc.ruta@vendguard.internal',   'ZONA-BCN-NORTE',  FALSE)
) AS v(email, zone_code, is_primary)
JOIN users u ON u.email_key = v.email
JOIN operational_zones z ON z.code = v.zone_code
WHERE NOT EXISTS (
    SELECT 1 FROM technician_zone_assignments a
     WHERE a.technician_id = u.id
       AND a.zone_id = z.id
       AND a.removed_at IS NULL
);


-- =============================================================================
-- SECCIÓN 12 · Herencia territorial de las sedes (RF-03.1, Resolución QA 7)
-- =============================================================================
-- La zona de una sede se deriva de su código postal salvo que el coordinador la
-- haya forzado manualmente. Estas dos sentencias reafirman la herencia y dejan
-- en «Sin Zona Asignada» (`zone_id = NULL`) la sede cuyo CP no pertenece a
-- ningún catálogo: nunca se inventa una zona.

UPDATE locations l
   SET zone_id = p.zone_id
  FROM operational_zone_postal_codes p
 WHERE p.postal_code = l.postal_code
   AND p.released_at IS NULL
   AND l.is_manual_override = FALSE
   AND l.zone_id IS DISTINCT FROM p.zone_id;

UPDATE locations l
   SET zone_id = NULL
 WHERE l.is_manual_override = FALSE
   AND l.postal_code IS NOT NULL
   AND l.zone_id IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM operational_zone_postal_codes p
        WHERE p.postal_code = l.postal_code
          AND p.released_at IS NULL
   );


-- =============================================================================
-- SECCIÓN 13 · technician_van_stocks — el saldo demo de las furgonetas (Módulo 18)
-- =============================================================================
-- Cinco saldos repartidos entre tres técnicos, resueltos por la clave canónica del
-- usuario (`email_key`) y por el código del repuesto. Se incluyen a propósito un
-- saldo justo en el umbral (JUNT-TOR-01: 1 ≤ 2) y un descuadre negativo
-- (TELEC-NAYAX-01: -1): la alerta y la cantidad negativa tienen que ser
-- demostrables en el entorno sembrado, no solo dentro de una prueba. Ninguna fila
-- lleva coste: el coste del repuesto se congela en la avería (RD-05).

INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
SELECT u.id, sp.id, v.quantity_available, v.min_threshold
FROM (VALUES
    ('jordi.ruta@vendguard.internal',  'VALV-ULKA-01',     4, 2),
    ('jordi.ruta@vendguard.internal',  'JUNT-TOR-01',      1, 2),
    ('marta.ruta@vendguard.internal',  'BOMB-VIB-02',      3, 1),
    ('marta.ruta@vendguard.internal',  'TELEC-NAYAX-01',  -1, 0),
    ('carlos.ruta@vendguard.internal', 'SOND-NTC-01',      2, 2)
) AS v(email, part_code, quantity_available, min_threshold)
JOIN users u ON u.email_key = v.email
JOIN spare_parts sp ON sp.part_code = v.part_code
WHERE NOT EXISTS (
    SELECT 1 FROM technician_van_stocks vs
     WHERE vs.technician_id = u.id
       AND vs.spare_part_id = sp.id
);


-- =============================================================================
-- SECCIÓN 14 · technician_shifts y technician_shift_logs — jornada demo (M22)
-- ============================================================================
-- Dos sesiones cerradas y realistas: una jornada normal con dos pausas de
-- comida acumuladas (Jordi) y un olvido de salida corregido por Coordinación
-- con hora efectiva retroactiva (Marta). La segunda conserva la marca original
-- de cierre del día siguiente y la deja inmutable, que es exactamente lo que
-- el Art. III.3 exige poder demostrar.
--
-- Las fechas son fijas y las filas se resuelven por técnico + instante de
-- inicio, de modo que reejecutar el catálogo no duplica la jornada ni sus
-- eventos. La bitácora se siembra después de los turnos porque necesita su
-- identidad generada.

INSERT INTO technician_shifts (
    technician_id, shift_date, status, started_at,
    meal_break_started_at, meal_break_ended_at, meal_break_seconds,
    closed_at, closed_by, close_reason,
    original_started_at, original_closed_at, original_meal_break_seconds,
    is_force_closed, is_adjusted, adjusted_at, adjusted_by, adjustment_reason
)
SELECT
    u.id,
    v.shift_date,
    'OFF_SHIFT',
    v.started_at,
    v.meal_started_at,
    v.meal_ended_at,
    v.meal_seconds,
    v.closed_at,
    closer.id,
    v.close_reason,
    v.original_started_at,
    v.original_closed_at,
    v.original_meal_seconds,
    v.is_force_closed,
    v.is_adjusted,
    v.adjusted_at,
    adjuster.id,
    v.adjustment_reason
FROM (VALUES
    (
        'jordi.ruta@vendguard.internal',
        DATE '2026-10-20',
        TIMESTAMPTZ '2026-10-20 08:00:00+02',
        TIMESTAMPTZ '2026-10-20 14:00:00+02',
        TIMESTAMPTZ '2026-10-20 14:40:00+02',
        3600,
        TIMESTAMPTZ '2026-10-20 17:00:00+02',
        'jordi.ruta@vendguard.internal',
        'Cierre de jornada al finalizar la ruta.',
        TIMESTAMPTZ '2026-10-20 08:00:00+02',
        TIMESTAMPTZ '2026-10-20 17:00:00+02',
        3600,
        FALSE,
        FALSE,
        NULL::TIMESTAMPTZ,
        NULL::TEXT,
        NULL::TEXT
    ),
    (
        'marta.ruta@vendguard.internal',
        DATE '2026-10-21',
        TIMESTAMPTZ '2026-10-21 08:00:00+02',
        TIMESTAMPTZ '2026-10-21 14:00:00+02',
        TIMESTAMPTZ '2026-10-21 14:30:00+02',
        1800,
        TIMESTAMPTZ '2026-10-21 17:00:00+02',
        'coordinacion@vendguard.internal',
        'Cierre forzado con hora real de salida tras olvido de fichaje.',
        TIMESTAMPTZ '2026-10-21 08:00:00+02',
        TIMESTAMPTZ '2026-10-22 09:00:00+02',
        1800,
        TRUE,
        TRUE,
        TIMESTAMPTZ '2026-10-22 09:05:00+02',
        'coordinacion@vendguard.internal',
        'Cierre forzado con hora real de salida tras olvido de fichaje.'
    )
) AS v(
    email, shift_date, started_at, meal_started_at, meal_ended_at, meal_seconds,
    closed_at, closer_email, close_reason, original_started_at, original_closed_at,
    original_meal_seconds, is_force_closed, is_adjusted, adjusted_at, adjuster_email,
    adjustment_reason
)
JOIN users u ON u.email_key = v.email
JOIN users closer ON closer.email_key = v.closer_email
LEFT JOIN users adjuster ON adjuster.email_key = v.adjuster_email
WHERE NOT EXISTS (
    SELECT 1
      FROM technician_shifts s
     WHERE s.technician_id = u.id
       AND s.started_at = v.started_at
);


INSERT INTO technician_shift_logs (
    shift_id, technician_id, event_type, actor_user_id,
    effective_at, previous_state, new_state, reason
)
SELECT
    s.id,
    s.technician_id,
    v.event_type,
    actor.id,
    v.effective_at,
    CASE
        WHEN v.previous_status IS NULL THEN NULL
        ELSE jsonb_build_object('status', v.previous_status)
    END,
    jsonb_build_object(
        'status', v.new_status,
        'meal_break_seconds', v.meal_seconds,
        'closed_at', CASE
            WHEN v.closed_at IS NULL THEN NULL
            ELSE to_char(v.closed_at AT TIME ZONE 'Europe/Madrid', 'YYYY-MM-DD"T"HH24:MI:SS')
        END
    ),
    v.reason
FROM (VALUES
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-20 08:00:00+02', 'START_SHIFT', TIMESTAMPTZ '2026-10-20 08:00:00+02', 'jordi.ruta@vendguard.internal', NULL::TEXT, 'ON_SHIFT', 0, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-20 08:00:00+02', 'PAUSE_MEAL', TIMESTAMPTZ '2026-10-20 10:00:00+02', 'jordi.ruta@vendguard.internal', 'ON_SHIFT', 'MEAL_BREAK', 0, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-20 08:00:00+02', 'RESUME_SHIFT', TIMESTAMPTZ '2026-10-20 10:20:00+02', 'jordi.ruta@vendguard.internal', 'MEAL_BREAK', 'ON_SHIFT', 1200, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-20 08:00:00+02', 'PAUSE_MEAL', TIMESTAMPTZ '2026-10-20 14:00:00+02', 'jordi.ruta@vendguard.internal', 'ON_SHIFT', 'MEAL_BREAK', 1200, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-20 08:00:00+02', 'RESUME_SHIFT', TIMESTAMPTZ '2026-10-20 14:40:00+02', 'jordi.ruta@vendguard.internal', 'MEAL_BREAK', 'ON_SHIFT', 3600, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-20 08:00:00+02', 'CLOSE_SHIFT', TIMESTAMPTZ '2026-10-20 17:00:00+02', 'jordi.ruta@vendguard.internal', 'ON_SHIFT', 'OFF_SHIFT', 3600, TIMESTAMPTZ '2026-10-20 17:00:00+02', NULL::TEXT),
    ('marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-21 08:00:00+02', 'START_SHIFT', TIMESTAMPTZ '2026-10-21 08:00:00+02', 'marta.ruta@vendguard.internal', NULL::TEXT, 'ON_SHIFT', 0, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-21 08:00:00+02', 'PAUSE_MEAL', TIMESTAMPTZ '2026-10-21 14:00:00+02', 'marta.ruta@vendguard.internal', 'ON_SHIFT', 'MEAL_BREAK', 0, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-21 08:00:00+02', 'RESUME_SHIFT', TIMESTAMPTZ '2026-10-21 14:30:00+02', 'marta.ruta@vendguard.internal', 'MEAL_BREAK', 'ON_SHIFT', 1800, NULL::TIMESTAMPTZ, NULL::TEXT),
    ('marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-21 08:00:00+02', 'FORCE_CLOSE_SHIFT', TIMESTAMPTZ '2026-10-22 09:00:00+02', 'coordinacion@vendguard.internal', 'ON_SHIFT', 'OFF_SHIFT', 1800, TIMESTAMPTZ '2026-10-21 17:00:00+02', 'Cierre forzado con hora real de salida tras olvido de fichaje.'),
    ('marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-21 08:00:00+02', 'ADJUST_SHIFT', TIMESTAMPTZ '2026-10-22 09:05:00+02', 'coordinacion@vendguard.internal', 'OFF_SHIFT', 'OFF_SHIFT', 1800, TIMESTAMPTZ '2026-10-21 17:00:00+02', 'Cierre forzado con hora real de salida tras olvido de fichaje.')
) AS v(
    email, shift_started_at, event_type, effective_at, actor_email,
    previous_status, new_status, meal_seconds, closed_at, reason
)
JOIN users technician_user ON technician_user.email_key = v.email
JOIN technician_shifts s
  ON s.technician_id = technician_user.id
 AND s.started_at = v.shift_started_at
JOIN users actor ON actor.email_key = v.actor_email
WHERE NOT EXISTS (
    SELECT 1
      FROM technician_shift_logs l
     WHERE l.shift_id = s.id
       AND l.event_type = v.event_type
       AND l.effective_at = v.effective_at
);


INSERT INTO audit_log (
    entity_type, entity_id, action, user_id, user_role, user_name,
    previous_state, new_state, metadata, created_at
)
SELECT
    'USER'::audit_entity_type,
    s.technician_id,
    v.action,
    actor.id,
    'COORDINATOR',
    actor.name,
    v.previous_state,
    v.new_state,
    jsonb_build_object('shift_id', s.id, 'reason', v.reason),
    v.created_at
FROM (VALUES
    (
        TIMESTAMPTZ '2026-10-21 08:00:00+02',
        'FORCE_CLOSE_TECHNICIAN_SHIFT',
        jsonb_build_object('status', 'ON_SHIFT'),
        jsonb_build_object('status', 'OFF_SHIFT', 'closed_at', '2026-10-21T17:00:00+02:00'),
        TIMESTAMPTZ '2026-10-22 09:00:00+02',
        'Cierre forzado con hora real de salida tras olvido de fichaje.'
    ),
    (
        TIMESTAMPTZ '2026-10-21 08:00:00+02',
        'ADJUST_TECHNICIAN_SHIFT',
        jsonb_build_object('started_at', '2026-10-21T08:00:00+02:00', 'closed_at', '2026-10-22T09:00:00+02:00', 'meal_break_seconds', 1800),
        jsonb_build_object('started_at', '2026-10-21T08:00:00+02:00', 'closed_at', '2026-10-21T17:00:00+02:00', 'meal_break_seconds', 1800, 'is_adjusted', TRUE),
        TIMESTAMPTZ '2026-10-22 09:05:00+02',
        'Cierre forzado con hora real de salida tras olvido de fichaje.'
    )
) AS v(shift_started_at, action, previous_state, new_state, created_at, reason)
JOIN technician_shifts s
  ON s.started_at = v.shift_started_at
JOIN users technician_user ON technician_user.id = s.technician_id
 AND technician_user.email_key = 'marta.ruta@vendguard.internal'
JOIN users actor ON actor.email_key = 'coordinacion@vendguard.internal'
WHERE NOT EXISTS (
    SELECT 1
      FROM audit_log a
     WHERE a.entity_type = 'USER'::audit_entity_type
       AND a.entity_id = s.technician_id
       AND a.action = v.action
       AND a.created_at = v.created_at
);


-- =============================================================================
-- SECCIÓN 15 · van_restock_orders y van_restock_order_items — cargas demo (M23)
-- =============================================================================
-- Cuatro órdenes, una por estado del ciclo, para que la superficie de Coordinación
-- y el móvil del técnico tengan algo demostrable desde el primer arranque sin
-- inventar averías: la orden no nace de un expediente, nace de la demanda
-- logística. Se resuelven por la clave canónica del técnico (`email_key`), por
-- su instante de creación (identidad natural de la semilla) y por el código de
-- la pieza, nunca por identificadores que la secuencia asigna distinto en cada
-- base. Reejecutar el catálogo no duplica ninguna orden.
--
-- La orden de Jordi incluye una línea `MANUAL` dada de baja lógica: la hoja A4 y
-- el despacho la excluyen, pero la fila permanece con su actor y su instante
-- (Art. III.1 y QA 1). La de Marta se sembró con despacho parcial (`dispatched_qty`
-- menor que la cantidad solicitada) y la de Carlos ya fue confirmada por el
-- técnico, con su marca de recepción. La de Elena ejemplifica la cancelación
-- justificada (Art. V.1) sin tocar el stock del vehículo.

INSERT INTO van_restock_orders (
    technician_id, status, notes, created_by, created_at,
    dispatched_by, dispatched_at, confirmed_by, confirmed_at,
    cancelled_by, cancelled_at, cancellation_reason
)
SELECT
    technician.id,
    v.status,
    v.notes,
    creator.id,
    v.created_at,
    dispatcher.id,
    v.dispatched_at,
    confirmer.id,
    v.confirmed_at,
    canceller.id,
    v.cancelled_at,
    v.cancellation_reason
FROM (VALUES
    (
        'jordi.ruta@vendguard.internal',
        'PENDING_PICKING',
        'Reposición matinal: preparar en jaula 3 antes de las 08:30.',
        'coordinacion@vendguard.internal',
        TIMESTAMPTZ '2026-10-26 07:45:00+02',
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        NULL::TEXT
    ),
    (
        'marta.ruta@vendguard.internal',
        'DISPATCHED',
        'Entregado parcialmente en el mostrador de almacén.',
        'coordinacion@vendguard.internal',
        TIMESTAMPTZ '2026-10-25 07:40:00+02',
        'coordinacion@vendguard.internal',
        TIMESTAMPTZ '2026-10-25 08:05:00+02',
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        NULL::TEXT
    ),
    (
        'carlos.ruta@vendguard.internal',
        'CONFIRMED_BY_TECH',
        'Carga recibida antes de iniciar la ruta del sud.',
        'coordinacion@vendguard.internal',
        TIMESTAMPTZ '2026-10-24 07:30:00+02',
        'coordinacion@vendguard.internal',
        TIMESTAMPTZ '2026-10-24 08:00:00+02',
        'carlos.ruta@vendguard.internal',
        TIMESTAMPTZ '2026-10-24 08:20:00+02',
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        NULL::TEXT
    ),
    (
        'elena.ruta@vendguard.internal',
        'CANCELLED',
        'Preparada la víspera de un cambio de ruta.',
        'coordinacion@vendguard.internal',
        TIMESTAMPTZ '2026-10-23 07:35:00+02',
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        NULL::TEXT,
        NULL::TIMESTAMPTZ,
        'coordinacion@vendguard.internal',
        TIMESTAMPTZ '2026-10-23 09:10:00+02',
        'Devolución física de la caja a estantería por cambio de ruta.'
    )
) AS v(
    technician_email, status, notes, creator_email, created_at,
    dispatcher_email, dispatched_at, confirmer_email, confirmed_at,
    canceller_email, cancelled_at, cancellation_reason
)
JOIN users technician ON technician.email_key = v.technician_email
JOIN users creator ON creator.email_key = v.creator_email
LEFT JOIN users dispatcher ON dispatcher.email_key = v.dispatcher_email
LEFT JOIN users confirmer ON confirmer.email_key = v.confirmer_email
LEFT JOIN users canceller ON canceller.email_key = v.canceller_email
WHERE NOT EXISTS (
    SELECT 1
      FROM van_restock_orders o
     WHERE o.technician_id = technician.id
       AND o.created_at = v.created_at
);


INSERT INTO van_restock_order_items (
    order_id, spare_part_id,
    current_quantity_snapshot, min_threshold_snapshot, pending_request_qty_snapshot,
    requested_qty, dispatched_qty, reason,
    removed_at, removed_by, created_at
)
SELECT
    o.id,
    sp.id,
    v.current_quantity,
    v.min_threshold,
    v.pending_request_qty,
    v.requested_qty,
    v.dispatched_qty,
    v.reason,
    v.removed_at,
    remover.id,
    v.created_at
FROM (VALUES
    -- Orden de Jordi (PENDING_PICKING): stock bajo, demanda combinada y una
    -- línea manual retirada antes del despacho (baja lógica, QA 1).
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-26 07:45:00+02', 'JUNT-TOR-01',   1, 2, 0, 2, NULL::INTEGER, 'LOW_STOCK',       NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-26 07:45:00+02'),
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-26 07:45:00+02', 'SOND-NTC-01',   0, 1, 2, 4, NULL::INTEGER, 'BOTH',            NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-26 07:45:00+02'),
    ('jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-26 07:45:00+02', 'FILT-AGUA-01',  0, 0, 0, 1, NULL::INTEGER, 'MANUAL',          TIMESTAMPTZ '2026-10-26 07:50:00+02', 'coordinacion@vendguard.internal', TIMESTAMPTZ '2026-10-26 07:45:00+02'),
    -- Orden de Marta (DISPATCHED): despacho parcial real de almacén (C3).
    ('marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-25 07:40:00+02', 'BOMB-VIB-02',   3, 0, 4, 1, 1,             'PENDING_REQUEST', NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-25 07:40:00+02'),
    ('marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-25 07:40:00+02', 'TELEC-NAYAX-01', -1, 0, 1, 3, 2,            'BOTH',            NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-25 07:40:00+02'),
    -- Orden de Carlos (CONFIRMED_BY_TECH): recepción móvil ya sellada.
    ('carlos.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-24 07:30:00+02', 'TERM-SEG-01',   0, 1, 1, 3, 3,             'BOTH',            NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-24 07:30:00+02'),
    ('carlos.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-24 07:30:00+02', 'MOT-ESP-01',    0, 0, 1, 1, 1,             'PENDING_REQUEST', NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-24 07:30:00+02'),
    -- Orden de Elena (CANCELLED): material solicitado pero nunca despachado.
    ('elena.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-23 07:35:00+02', 'VALV-ULKA-01',   0, 0, 2, 2, NULL::INTEGER, 'PENDING_REQUEST', NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-23 07:35:00+02'),
    ('elena.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-23 07:35:00+02', 'DISP-LCD-01',    0, 0, 0, 1, NULL::INTEGER, 'MANUAL',          NULL::TIMESTAMPTZ, NULL::TEXT, TIMESTAMPTZ '2026-10-23 07:35:00+02')
) AS v(
    technician_email, order_created_at, part_code,
    current_quantity, min_threshold, pending_request_qty,
    requested_qty, dispatched_qty, reason,
    removed_at, remover_email, created_at
)
JOIN users technician ON technician.email_key = v.technician_email
JOIN van_restock_orders o
  ON o.technician_id = technician.id
 AND o.created_at = v.order_created_at
JOIN spare_parts sp ON sp.part_code = v.part_code
LEFT JOIN users remover ON remover.email_key = v.remover_email
WHERE NOT EXISTS (
    SELECT 1
      FROM van_restock_order_items i
     WHERE i.order_id = o.id
       AND i.spare_part_id = sp.id
);


-- Eventos de auditoría del ciclo sembrado (Art. III.3). El tipo
-- `VAN_RESTOCK_ORDER` se estrena con estas órdenes, y cada evento conserva el
-- estado previo y el resultante para que la traza sea reconstruible.
INSERT INTO audit_log (
    entity_type, entity_id, action, user_id, user_role, user_name,
    previous_state, new_state, metadata, created_at
)
SELECT
    'VAN_RESTOCK_ORDER'::audit_entity_type,
    o.id,
    v.action,
    actor.id,
    v.user_role,
    actor.name,
    v.previous_state,
    v.new_state,
    jsonb_build_object(
        'order_code', 'RO-' || lpad(o.id::text, 6, '0'),
        'source', 'SEED'
    ),
    v.created_at
FROM (VALUES
    (
        'jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-26 07:45:00+02',
        'CREATE_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        NULL::JSONB,
        jsonb_build_object('status', 'PENDING_PICKING', 'technician_id', 'jordi.ruta@vendguard.internal', 'source', 'SEED'),
        TIMESTAMPTZ '2026-10-26 07:45:00+02'
    ),
    (
        'jordi.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-26 07:45:00+02',
        'UPDATE_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        jsonb_build_object('status', 'PENDING_PICKING', 'active_items', jsonb_build_array('JUNT-TOR-01', 'SOND-NTC-01', 'FILT-AGUA-01')),
        jsonb_build_object('status', 'PENDING_PICKING', 'active_items', jsonb_build_array('JUNT-TOR-01', 'SOND-NTC-01'), 'soft_removed_part_codes', jsonb_build_array('FILT-AGUA-01')),
        TIMESTAMPTZ '2026-10-26 07:50:00+02'
    ),
    (
        'marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-25 07:40:00+02',
        'CREATE_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        NULL::JSONB,
        jsonb_build_object('status', 'PENDING_PICKING', 'source', 'SEED'),
        TIMESTAMPTZ '2026-10-25 07:40:00+02'
    ),
    (
        'marta.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-25 07:40:00+02',
        'DISPATCH_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        jsonb_build_object('status', 'PENDING_PICKING'),
        jsonb_build_object('status', 'DISPATCHED', 'total_dispatched_units', 3),
        TIMESTAMPTZ '2026-10-25 08:05:00+02'
    ),
    (
        'carlos.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-24 07:30:00+02',
        'CREATE_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        NULL::JSONB,
        jsonb_build_object('status', 'PENDING_PICKING', 'source', 'SEED'),
        TIMESTAMPTZ '2026-10-24 07:30:00+02'
    ),
    (
        'carlos.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-24 07:30:00+02',
        'DISPATCH_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        jsonb_build_object('status', 'PENDING_PICKING'),
        jsonb_build_object('status', 'DISPATCHED', 'total_dispatched_units', 4),
        TIMESTAMPTZ '2026-10-24 08:00:00+02'
    ),
    (
        'carlos.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-24 07:30:00+02',
        'CONFIRM_VAN_RESTOCK_ORDER', 'carlos.ruta@vendguard.internal', 'TECHNICIAN',
        jsonb_build_object('status', 'DISPATCHED'),
        jsonb_build_object('status', 'CONFIRMED_BY_TECH', 'total_received_units', 4),
        TIMESTAMPTZ '2026-10-24 08:20:00+02'
    ),
    (
        'elena.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-23 07:35:00+02',
        'CREATE_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        NULL::JSONB,
        jsonb_build_object('status', 'PENDING_PICKING', 'source', 'SEED'),
        TIMESTAMPTZ '2026-10-23 07:35:00+02'
    ),
    (
        'elena.ruta@vendguard.internal', TIMESTAMPTZ '2026-10-23 07:35:00+02',
        'CANCEL_VAN_RESTOCK_ORDER', 'coordinacion@vendguard.internal', 'COORDINATOR',
        jsonb_build_object('status', 'PENDING_PICKING'),
        jsonb_build_object('status', 'CANCELLED', 'cancellation_reason', 'Devolución física de la caja a estantería por cambio de ruta.'),
        TIMESTAMPTZ '2026-10-23 09:10:00+02'
    )
) AS v(
    technician_email, order_created_at, action, actor_email, user_role,
    previous_state, new_state, created_at
)
JOIN users technician ON technician.email_key = v.technician_email
JOIN van_restock_orders o
  ON o.technician_id = technician.id
 AND o.created_at = v.order_created_at
JOIN users actor ON actor.email_key = v.actor_email
WHERE NOT EXISTS (
    SELECT 1
      FROM audit_log a
     WHERE a.entity_type = 'VAN_RESTOCK_ORDER'::audit_entity_type
       AND a.entity_id = o.id
       AND a.action = v.action
       AND a.created_at = v.created_at
);


-- ============================================================================
-- SECCIÓN 16 · offline_sync_mutations — sincronizaciones demo (M24)
-- ============================================================================
-- QUÉ DEMUESTRA
--   Dos operaciones reales del libro de idempotencia: una inspección preventiva
--   completada sin cobertura y aplicada al recuperar la señal (`APPLIED`), y una
--   reinspección que llegó cuando la orden ya no admitía el cambio y se volcó
--   como conflicto (`CONFLICT_DUMPED`), conservando el desfase de reloj del
--   dispositivo en la fila. Son las dos ramas que la bandeja del técnico y el
--   expediente de coordinación necesitan para no arrancar vacíos.
--
-- POR QUÉ LA SECCIÓN PUEDE INSERTAR CERO FILAS
--   El arranque en limpio (`bin/init_cloud_db.php`) no siembra órdenes
--   preventivas: su catálogo es maestro y deja el histórico a cero. Como la
--   parada es polimórfica y apunta a una orden preventiva concreta, las filas se
--   resuelven por `order_code` y sólo entran cuando esa orden existe —el camino
--   de `SeedRunner::seedAll()`, que sí la crea—. Inventar un `stop_id` sin fila
--   detrás sería peor que no sembrar: el libro de idempotencia quedaría
--   apuntando a un expediente inexistente.
--
-- POR QUÉ EL VOLCADO NO SIEMBRA SU EVENTO DE AUDITORÍA
--   La acción `OFFLINE_CONFLICT_DUMPED` nace en el repositorio del módulo
--   (T-OFFLINE-05) y su etiqueta en el visor llega con la interfaz
--   (T-OFFLINE-11); el guardián del vocabulario rechaza cualquier acción
--   persistida que el visor todavía no sepa nombrar. Sembrarla hoy dejaría
--   `audit_log` con una acción invisible para coordinación. La mutación
--   aplicada sí deja rastro con la acción que ya está catalogada.

INSERT INTO offline_sync_mutations (
    technician_id, client_mutation_id, stop_type, stop_id, operation_type,
    outcome_status, client_occurred_at, chain_started_at, effective_occurred_at,
    synced_at, clock_skew_adjusted, sanitary_seal_confirmed,
    response_http_status, response_payload
)
SELECT
    technician.id,
    v.client_mutation_id,
    'PREVENTIVE_ORDER',
    preventive.id,
    v.operation_type,
    v.outcome_status,
    v.client_occurred_at,
    v.chain_started_at,
    v.effective_occurred_at,
    v.synced_at,
    v.clock_skew_adjusted,
    v.sanitary_seal_confirmed,
    v.response_http_status,
    v.response_payload || jsonb_build_object('preventive_order_id', preventive.id)
FROM (VALUES
    (
        'jordi.ruta@vendguard.internal',
        'ORD-PREV-2026-0001',
        'seed-offline-20261026-0001',
        'COMPLETE_PREVENTIVE',
        'APPLIED',
        CURRENT_TIMESTAMP - INTERVAL '1 day',
        CURRENT_TIMESTAMP - INTERVAL '1 day',
        CURRENT_TIMESTAMP - INTERVAL '1 day 25 minutes',
        CURRENT_TIMESTAMP - INTERVAL '1 day',
        FALSE,
        FALSE,
        200,
        jsonb_build_object(
            'status', 'COMPLETED',
            'result', 'CONFORME',
            'idempotent_replay', false,
            'source', 'SEED'
        )
    ),
    (
        'jordi.ruta@vendguard.internal',
        'ORD-PREV-2026-0002',
        'seed-offline-20261026-0002',
        'REINSPECT_PREVENTIVE',
        'CONFLICT_DUMPED',
        CURRENT_TIMESTAMP - INTERVAL '2 days',
        CURRENT_TIMESTAMP - INTERVAL '3 days',
        CURRENT_TIMESTAMP - INTERVAL '3 days 25 minutes',
        CURRENT_TIMESTAMP - INTERVAL '2 days',
        TRUE,
        FALSE,
        409,
        jsonb_build_object(
            'error', 'OFFLINE_MUTATION_CONFLICT',
            'conflict_reason', 'La orden preventiva ya constaba como completada en el servidor al recuperar la cobertura.',
            'work_summary', jsonb_build_object('checklist_completed', true, 'evidence_photos', 1),
            'source', 'SEED'
        )
    )
) AS v(
    technician_email, order_code, client_mutation_id, operation_type,
    outcome_status, synced_at, client_occurred_at, chain_started_at,
    effective_occurred_at, clock_skew_adjusted, sanitary_seal_confirmed,
    response_http_status, response_payload
)
JOIN users technician ON technician.email_key = v.technician_email
JOIN preventive_orders preventive ON preventive.order_code = v.order_code
WHERE NOT EXISTS (
    SELECT 1
      FROM offline_sync_mutations m
     WHERE m.technician_id = technician.id
       AND m.client_mutation_id = v.client_mutation_id
);


-- Rastro de auditoría de la mutación aplicada (RF-04.3, Art. III.3). El evento
-- conserva la hora declarada, la efectiva y la de sincronización para que el
-- visor pueda distinguir una intervención in situ de una llegada tardía.
INSERT INTO audit_log (
    entity_type, entity_id, action, user_id, user_role, user_name,
    previous_state, new_state, metadata, created_at
)
SELECT
    'PREVENTIVE_ORDER'::audit_entity_type,
    m.stop_id,
    'EVALUATE_PREVENTIVE_CHECKLIST',
    m.technician_id,
    'TECHNICIAN',
    technician.name,
    jsonb_build_object('status', 'IN_PROGRESS'),
    jsonb_build_object('status', 'COMPLETED', 'result', 'CONFORME'),
    jsonb_build_object(
        'source', 'SEED',
        'offline_sync', jsonb_build_object(
            'client_mutation_id', m.client_mutation_id,
            'operation_type', m.operation_type,
            'client_occurred_at', m.client_occurred_at,
            'effective_occurred_at', m.effective_occurred_at,
            'synced_at', m.synced_at,
            'clock_skew_adjusted', m.clock_skew_adjusted
        )
    ),
    m.synced_at
FROM offline_sync_mutations m
JOIN users technician ON technician.id = m.technician_id
WHERE m.stop_type = 'PREVENTIVE_ORDER'
  AND m.client_mutation_id = 'seed-offline-20261026-0001'
  AND NOT EXISTS (
      SELECT 1
        FROM audit_log a
       WHERE a.entity_type = 'PREVENTIVE_ORDER'::audit_entity_type
         AND a.entity_id = m.stop_id
         AND a.action = 'EVALUATE_PREVENTIVE_CHECKLIST'
         AND a.created_at = m.synced_at
  );

-- ============================================================================
-- SECCIÓN 17 · root_causes y standard_solutions — taxonomía inicial (Módulo 25)
-- ============================================================================
-- QUÉ SIEMBRA
--   Las 16 causas raíz del catálogo inicial, repartidas en las 5 categorías de
--   `incident_category`, y sus 34 soluciones estandarizadas. Cada causa nace con
--   exactamente una solución activa marcada como predeterminada (`is_default`),
--   que es la que el modal de resolución preselecciona en un toque (D1).
--
-- POR QUÉ `DO NOTHING` Y NO `DO UPDATE`
--   A diferencia del resto de maestras, este catálogo lo gobierna Coordinación
--   desde su panel: puede renombrar una causa, añadir soluciones o cambiar cuál
--   es la predeterminada. Un `DO UPDATE` en cada arranque desharía esos cambios
--   y, peor aún, podría dejar dos soluciones predeterminadas activas si el
--   coordinador ya había relevado la de la semilla. La semilla sólo asegura que
--   la fila exista; nunca reescribe una decisión posterior.
--
-- ORDEN
--   Primero las causas y después las soluciones, que resuelven su
--   `root_cause_id` por el código único de la causa. Así el fichero es
--   idempotente y no depende de los identificadores que asigne la secuencia en
--   cada base.

INSERT INTO root_causes (code, category, name, description, is_active)
VALUES
    ('RC-COLD-01', 'TEMPERATURE_COLD', 'Sonda térmica NTC descalibrada o derivada', NULL, TRUE),
    ('RC-COLD-02', 'TEMPERATURE_COLD', 'Obstrucción o suciedad en condensador / ventilador evaporador', NULL, TRUE),
    ('RC-COLD-03', 'TEMPERATURE_COLD', 'Fuga de gas refrigerante o fallo de compresor hermético', NULL, TRUE),
    ('RC-COLD-04', 'TEMPERATURE_COLD', 'Disparo de termostato de seguridad en caldera / grupo térmico', NULL, TRUE),
    ('RC-PAY-01', 'PAYMENT_SYSTEM', 'Moneda doblada o cuerpo extraño en selector multimoneda', NULL, TRUE),
    ('RC-PAY-02', 'PAYMENT_SYSTEM', 'Tubos de cambio agotados o atasco en casete de devolución', NULL, TRUE),
    ('RC-PAY-03', 'PAYMENT_SYSTEM', 'Pérdida de enlace o bloqueo en terminal Contactless / Nayax', NULL, TRUE),
    ('RC-JAM-01', 'PRODUCT_JAM', 'Atasco de producto en espiral o bandeja de extracción', NULL, TRUE),
    ('RC-JAM-02', 'PRODUCT_JAM', 'Fallo de motor extractor de espiral o microinterruptor de leva', NULL, TRUE),
    ('RC-JAM-03', 'PRODUCT_JAM', 'Suciedad o desalineación en fotocélula de detección de caída', NULL, TRUE),
    ('RC-JAM-04', 'PRODUCT_JAM', 'Bloqueo mecánico o pérdida de estanqueidad en grupo infusor de café', NULL, TRUE),
    ('RC-ELEC-01', 'ELECTRICAL_OFF', 'Disparo de protección magneto-térmica o diferencial del edificio', NULL, TRUE),
    ('RC-ELEC-02', 'ELECTRICAL_OFF', 'Fallo en fuente de alimentación conmutada o placa de potencia', NULL, TRUE),
    ('RC-ELEC-03', 'ELECTRICAL_OFF', 'Avería o apagado de pantalla Display LCD frontal', NULL, TRUE),
    ('RC-OTH-01', 'OTHER', 'Obstrucción calcárea o fallo de electroválvula / bomba hidráulica', NULL, TRUE),
    ('RC-OTH-02', 'OTHER', 'Vandalismo, golpe externo en puerta/botonera o falsa alarma sin fallo', NULL, TRUE)
ON CONFLICT (code) DO NOTHING;

INSERT INTO standard_solutions (root_cause_id, code, name, is_default, is_active)
SELECT rc.id, seed.code, seed.name, seed.is_default, TRUE
  FROM (
      VALUES
          ('RC-COLD-01', 'SOL-COLD-01A', 'Recalibración y ajuste de sonda NTC en cámara', TRUE),
          ('RC-COLD-01', 'SOL-COLD-01B', 'Sustitución de sonda térmica NTC roscada', FALSE),
          ('RC-COLD-02', 'SOL-COLD-02A', 'Limpieza de batería condensadora y desbloqueo de turbina', TRUE),
          ('RC-COLD-02', 'SOL-COLD-02B', 'Sustitución de motor ventilador de evaporador', FALSE),
          ('RC-COLD-03', 'SOL-COLD-03A', 'Sellado de circuito y recarga de refrigerante', TRUE),
          ('RC-COLD-03', 'SOL-COLD-03B', 'Sustitución de grupo frigorífico / compresor', FALSE),
          ('RC-COLD-04', 'SOL-COLD-04A', 'Rearme manual de termostato y purga térmica', TRUE),
          ('RC-COLD-04', 'SOL-COLD-04B', 'Sustitución de termostato de seguridad 135°C', FALSE),
          ('RC-PAY-01', 'SOL-PAY-01A', 'Apertura de canal, retirada de objeto y limpieza óptica', TRUE),
          ('RC-PAY-01', 'SOL-PAY-01B', 'Sustitución de monedero selector MDB', FALSE),
          ('RC-PAY-02', 'SOL-PAY-02A', 'Desatasco de casete, nivelación y prueba de expulsión', TRUE),
          ('RC-PAY-02', 'SOL-PAY-02B', 'Sustitución de casete / módulo de cambio', FALSE),
          ('RC-PAY-03', 'SOL-PAY-03A', 'Reinicio de bus MDB y reconexión de antena 4G', TRUE),
          ('RC-PAY-03', 'SOL-PAY-03B', 'Sustitución de terminal lector Contactless MDB', FALSE),
          ('RC-JAM-01', 'SOL-JAM-01A', 'Liberación de canal, realineado de espiral y test de giro', TRUE),
          ('RC-JAM-01', 'SOL-JAM-01B', 'Reconfiguración de paso de espiral según envase', FALSE),
          ('RC-JAM-02', 'SOL-JAM-02A', 'Ajuste de leva y reconexión de terminal de motor', TRUE),
          ('RC-JAM-02', 'SOL-JAM-02B', 'Sustitución de motor extractor de espiral 24V', FALSE),
          ('RC-JAM-03', 'SOL-JAM-03A', 'Limpieza de lentes infrarrojas y calibración de barrera', TRUE),
          ('RC-JAM-03', 'SOL-JAM-03B', 'Sustitución de sensor fotocélula de caída', FALSE),
          ('RC-JAM-04', 'SOL-JAM-04A', 'Desmontaje, higienización, engrase alimentario y cambio de juntas', TRUE),
          ('RC-JAM-04', 'SOL-JAM-04B', 'Sustitución de grupo infusor de café espresso', FALSE),
          ('RC-ELEC-01', 'SOL-ELEC-01A', 'Verificación de toma de corriente, rearme y arranque supervisado', TRUE),
          ('RC-ELEC-01', 'SOL-ELEC-01B', 'Sustitución de fusible interno o filtro antiparasitario', FALSE),
          ('RC-ELEC-02', 'SOL-ELEC-02A', 'Reseteo de etapa de potencia y apriete de bornes', TRUE),
          ('RC-ELEC-02', 'SOL-ELEC-02B', 'Sustitución de fuente / placa electrónica de control', FALSE),
          ('RC-ELEC-03', 'SOL-ELEC-03A', 'Reconexión de faja plana de datos y ajuste de contraste', TRUE),
          ('RC-ELEC-03', 'SOL-ELEC-03B', 'Sustitución de módulo Display LCD gráfico', FALSE),
          ('RC-OTH-01', 'SOL-OTH-01A', 'Descalcificación de circuito y limpieza de asiento de válvula', TRUE),
          ('RC-OTH-01', 'SOL-OTH-01B', 'Sustitución de electroválvula o bomba vibratoria', FALSE),
          ('RC-OTH-01', 'SOL-OTH-01C', 'Sustitución de cartucho filtrante antical', FALSE),
          ('RC-OTH-02', 'SOL-OTH-02A', 'Ajuste mecánico de cerradura/puerta y test funcional completo', TRUE),
          ('RC-OTH-02', 'SOL-OTH-02B', 'Verificación integral sin anomalía técnica reproducible (falsa alarma)', FALSE),
          ('RC-OTH-02', 'SOL-OTH-02C', 'Reparación de botonera o cristal frontal', FALSE)
  ) AS seed(cause_code, code, name, is_default)
  JOIN root_causes rc ON rc.code = seed.cause_code
ON CONFLICT (code) DO NOTHING;

-- ============================================================================
-- FIN · 10 sedes con CP · 3 zonas operativas · 10 códigos postales · 7 vinculaciones
--       de cuadrante · 5 saldos de stock de furgoneta · 1 configuración de ruta ·
--       13 máquinas · 6 usuarios internos · 5 frecuencias normativas · 13 repuestos ·
--       16 causas raíz · 34 soluciones estandarizadas (Módulo 25) ·
--       50 compatibilidades · 1 canal emergencias · 2 turnos demo ·
--       11 eventos de bitácora · 2 eventos de auditoría de jornada ·
--       4 órdenes de carga (una por estado) · 9 líneas (1 con baja lógica) ·
--       9 eventos de auditoría de carga · 2 mutaciones de sincronización y 1
--       evento de auditoría de sincronización **cuando el catálogo preventivo ya
--       existe** (`SeedRunner`); en el arranque en limpio la Sección 16 no inserta
--       nada porque no hay orden preventiva a la que apuntar.
--       0 incidencias · 0 histórico de averías (arranque en limpio estricto).
-- =============================================================================
