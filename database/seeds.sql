-- =============================================================================
-- VendGuard - Datos Semilla Iniciales (Seed Data)
-- =============================================================================
-- Proyecto: Gestor de Incidencias de Vending (VendGuard)
-- Versión: 1.0.0
-- Propósito: Carga de sedes, máquinas y usuarios de prueba para desarrollo/test.
-- Contraseña unificada de desarrollo: 'Password123!'
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 1. SEDES INICIALES (locations)
-- -----------------------------------------------------------------------------
INSERT INTO `locations` (`site_code`, `name`, `address`, `contact_name`, `contact_phone`)
VALUES
  ('SEDE-BCN-01', 'Hospital del Mar - Edificio Central', 'Passeig Marítim 25, Barcelona', 'Laura Sanitaria', '600111222'),
  ('SEDE-BCN-02', 'Torre Glòries - Planta 4 Oficinas', 'Avinguda Diagonal 211, Barcelona', 'Marc Recepción', '600333444'),
  ('SEDE-BCN-03', 'Hospital Universitari de Bellvitge', 'Carrer de la Feixa Llarga s/n, L\'Hospitalet de Llobregat', 'Carles Coordinador', '600555666'),
  ('SEDE-BCN-04', 'World Trade Center Barcelona', 'Moll de Barcelona s/n, Barcelona', 'Núria Port', '600777888'),
  ('SEDE-BCN-05', 'Campus Nord UPC - Edifici Nexus', 'Carrer del Gran Capità 2, Barcelona', 'Albert Campus', '600999000'),
  ('SEDE-BCN-06', 'Parc Tecnològic Barcelona Activa', 'Carrer Marie Curie 8, Nou Barris, Barcelona', 'Clara Innovació', '611222333'),
  ('SEDE-BCN-07', 'Badalona Centre Mèdic Can Ruti', 'Carretera de Canyet s/n, Badalona', 'Sergi Logística', '611444555'),
  ('SEDE-BCN-08', 'Sant Cugat Trade Center', 'Avinguda de les Corts Catalanes 5, Sant Cugat del Vallès', 'Gemma Gestió', '611666777'),
  ('SEDE-BCN-09', 'Fira Gran Via - Pavelló 1', 'Avinguda Joan Carles I 64, L\'Hospitalet de Llobregat', 'Pau Esdeveniments', '611888999'),
  ('SEDE-BCN-10', 'WTC Almeda Park Cornellà', 'Plaça de la Pau s/n, Cornellà de Llobregat', 'Mireia Serveis', '622111222')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `address` = VALUES(`address`),
  `contact_name` = VALUES(`contact_name`),
  `contact_phone` = VALUES(`contact_phone`),
  `deleted_at` = NULL;

-- -----------------------------------------------------------------------------
-- 2. PARQUE DE MÁQUINAS (machines)
-- -----------------------------------------------------------------------------
-- Nota: location_id se enlaza dinámicamente mediante site_code.
INSERT INTO `machines` (`location_id`, `code`, `model`, `machine_type`, `floor_wing`, `notes`)
VALUES
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01'), 'VEND-0101', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Planta Baja - Urgencias', 'Máquina de sándwiches y lácteos frescos'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01'), 'VEND-0102', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 1 - Sala Médica', 'Café en grano y bebidas calientes'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02'), 'VEND-0201', 'Necta Samba Combo', 'COMBO', 'Planta 4 - Office Este', 'Snacks y refrescos variados'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-03'), 'VEND-0301', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Edificio Principal - Hall Consultas', 'Comida fresca y ensaladas preparadas'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-03'), 'VEND-0302', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 2 - Sala Descanso Personal', 'Café de especialidad e infusiones'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-04'), 'VEND-0401', 'Necta Samba Combo', 'COMBO', 'Edifici Est - Planta Baixa Lobby', 'Bebidas isotónicas y aperitivos saludables'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-05'), 'VEND-0501', 'Fas Fast 900', 'SNACKS', 'Edifici Nexus I - Entrada Estudiants', 'Aperitivos, frutos secos y barritas energéticas'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-05'), 'VEND-0502', 'Fas Perla', 'HOT_DRINKS', 'Edifici Nexus I - Sala Professorat', 'Café largo, cortado y chocolate'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-06'), 'VEND-0601', 'Azkoyen Palma+', 'COLD_DRINKS', 'Coworking Principal - Zona Cafeteria', 'Aguas minerales, zumos y refrescos en lata/botella'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-07'), 'VEND-0701', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Planta 0 - Accés Visitants', 'Sandwiches envasados y yogures refrigerados'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-08'), 'VEND-0801', 'Necta Samba Combo', 'COMBO', 'Atri Central - Planta Baixa', 'Bebidas frías y aperitivos mixtos'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-09'), 'VEND-0901', 'Azkoyen Palma+', 'COLD_DRINKS', 'Pavelló 1 - Porta Nord', 'Bebidas frías de alta rotación para ferias'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-10'), 'VEND-1001', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 1 - Mòdul B Corporatiu', 'Servicio de café continuo para oficinas')
ON DUPLICATE KEY UPDATE
  `location_id` = VALUES(`location_id`),
  `model` = VALUES(`model`),
  `machine_type` = VALUES(`machine_type`),
  `floor_wing` = VALUES(`floor_wing`),
  `notes` = VALUES(`notes`),
  `deleted_at` = NULL;

-- -----------------------------------------------------------------------------
-- 3. USUARIOS DEL SISTEMA (users)
-- -----------------------------------------------------------------------------
-- Hash Bcrypt para 'Password123!': $2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `operator_code`, `phone`)
VALUES
  ('Sara Coordinadora', 'coordinacion@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'COORDINATOR', NULL, '677000111'),
  ('Jordi Técnico Ruta BCN', 'jordi.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-01', '677222333'),
  ('Marta Técnica Ruta BCN', 'marta.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-02', '677444555'),
  ('Carlos Técnico Ruta Sud', 'carlos.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-03', '677666777'),
  ('Elena Técnica Ruta Nord', 'elena.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-04', '677888999'),
  ('Marc Técnico Express BCN', 'marc.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-05', '677112233')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `operator_code` = VALUES(`operator_code`),
  `phone` = VALUES(`phone`),
  `deleted_at` = NULL;
