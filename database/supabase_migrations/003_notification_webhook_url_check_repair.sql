-- =============================================================================
-- VendGuard · Migraciones incrementales del motor nuevo · 003 · REPARACIÓN DEL
-- CERCO `chk_webhook_url_https`
-- Fichero: database/supabase_migrations/003_notification_webhook_url_check_repair.sql
-- Módulo: 14-notification-center-outbox · T-NOTIF-04
-- Referencia: specs/technical/notification_center_outbox_contracts.md §2.4
-- Ejecutor: src/Infrastructure/Database/PostgresMigrationRunner.php
-- =============================================================================
--
-- POR QUÉ EXISTE ESTA MIGRACIÓN Y NO SE REESCRIBE LA 002
--
-- La migración 002 declaró el cerco con la barra invertida duplicada
-- (`[a-z0-9.-]+\\.[a-z]{2,}`). En una cadena estándar de PostgreSQL la barra no
-- se consume al analizar el literal, así que la expresión regular que llegó al
-- motor fue «una barra invertida literal seguida de cualquier carácter»: con
-- `standard_conforming_strings` activo —el valor por defecto— NINGUNA dirección
-- `https://…` real podía satisfacer el cerco y el Canal Corporativo de
-- Emergencias quedaba imposible de configurar (RF-06.5).
--
-- Una migración ya aplicada no se reescribe (el libro `schema_migrations` lo
-- rechaza por huella), de modo que el arreglo viaja aquí: se retira el cerco
-- defectuoso y se vuelve a declarar con la barra simple que el contrato
-- pretendía. El DDL base (`database/supabase_init.sql`) ya declara la forma
-- correcta para las instalaciones nuevas; esta migración repara las que ya
-- corrieron la 002.
-- =============================================================================

ALTER TABLE notification_channel_settings
    DROP CONSTRAINT IF EXISTS chk_webhook_url_https;

ALTER TABLE notification_channel_settings
    ADD CONSTRAINT chk_webhook_url_https CHECK (
        webhook_url IS NULL OR webhook_url ~* '^https://[a-z0-9.-]+\.[a-z]{2,}(:[0-9]+)?(/.*)?$'
    );
