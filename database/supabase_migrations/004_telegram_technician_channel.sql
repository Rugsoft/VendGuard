-- ============================================================================
-- Módulo 15 · Aviso de asignación al técnico por Telegram
-- ----------------------------------------------------------------------------
-- 1. Canal de entrega personal del técnico (Módulo 14 extendido, RF-TG-01).
-- 2. Identidad Telegram del usuario: dato personal, opcional y único (RF-TG-07).
--
-- NOTA SOBRE EL EJECUTOR (PostgresMigrationRunner):
-- Aplica cada migración dentro de una transacción. PostgreSQL 12+ admite
-- `ALTER TYPE ... ADD VALUE` en ese contexto, pero el valor nuevo NO puede
-- usarse hasta que la transacción confirme. Por eso este fichero se limita a
-- declarar el valor y la columna: ninguna fila con canal 'TELEGRAM' se escribe
-- aquí. Sobre un motor anterior a 12 la sentencia sería rechazada, y ese caso
-- queda cubierto por la suite de esquema del motor de certificación.
-- ============================================================================

-- 1. Canal TELEGRAM en la cola de salida.
ALTER TYPE notification_channel ADD VALUE IF NOT EXISTS 'TELEGRAM';

-- 2. Identidad de chat del técnico (chat de usuario o de grupo/supergrupo).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(32);

-- 2.1. Formato: identificador numérico de Telegram, negativo cuando es grupo.
DO $do$
BEGIN
    ALTER TABLE users
        ADD CONSTRAINT chk_users_telegram_chat_id
        CHECK (telegram_chat_id IS NULL OR telegram_chat_id ~ '^-?[0-9]{5,20}$');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 2.2. Un chat pertenece a un solo usuario activo: dos técnicos con el mismo
--      chat_id significaría que uno recibe las averías del otro (regla 1 de la
--      especificación funcional).
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_telegram_chat_id
    ON users (telegram_chat_id)
    WHERE telegram_chat_id IS NOT NULL AND deleted_at IS NULL;
