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
--   interno con su contraseña hasheada, las frecuencias normativas del preventivo
--   y el catálogo maestro de repuestos con sus compatibilidades por modelo. Solo
--   datos: no crea ni modifica esquema, que es responsabilidad de
--   `database/supabase_init.sql` y de `database/supabase_migrations/`.
--
-- LO QUE ESTE FICHERO NO CONTIENE (deliberadamente)
--   · Ninguna incidencia, ni histórica ni activa, ni comentarios, ni marcas de
--     auditoría. El arranque en limpio es estricto (EARS-02.1, spec §7): los
--     expedientes de la base anterior quedan archivados en el sistema previo.
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
-- FIN · 10 sedes con CP · 3 zonas operativas · 10 códigos postales · 7 vinculaciones
--       de cuadrante · 1 configuración de ruta · 13 máquinas · 6 usuarios internos ·
--       5 frecuencias normativas · 13 repuestos · 50 compatibilidades · 1 canal emergencias.
--       0 incidencias · 0 histórico · 0 auditoría (arranque en limpio estricto).
-- =============================================================================
