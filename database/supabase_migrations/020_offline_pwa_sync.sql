-- ============================================================================
-- Módulo 24 · Modo Sin Conexión e Instalabilidad Móvil para Técnicos de Campo
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   La cola del técnico reenvía sus operaciones cuando recupera la cobertura, y
--   una red que aparece y desaparece no concede entregas exactamente una vez: el
--   mismo POST puede llegar dos veces porque la respuesta se perdió a mitad de
--   subida. Sin un registro central de qué operaciones ya se aplicaron, cada
--   reintento volvería a descontar repuestos de la furgoneta, a duplicar
--   comentarios y a emitir un segundo albarán (RF-04.2). Esta tabla es ese
--   registro: la identidad de la operación la fija el dispositivo
--   (`client_mutation_id`) y el servidor conserva su resultado para devolverlo
--   tal cual cuando el reintento llegue.
--
-- QUÉ DECLARA
--   1. La Tabla 34 `offline_sync_mutations`, con la unicidad por técnico y
--      operación, el doble sello temporal (instante declarado in situ, instante
--      efectivo aplicado e instante de recepción) y el resultado replicable.
--   2. Los dos índices de lectura: por parada y por técnico, ambos con
--      `synced_at` descendente — el expediente pregunta «qué me llegó de esta
--      avería» y la bandeja «qué sincronizó este técnico y cuándo».
--   3. El disparador constitucional `trg_no_delete_offline_sync_mutations`
--      (`prevent_hard_delete()`, Art. III.1): el rastro de una sincronización no
--      se borra ni siquiera cuando la cuenta del técnico causa baja.
--
-- POR QUÉ `stop_id` NO LLEVA CLAVE FORÁNEA
--   La parada es polimórfica (`INCIDENT` o `PREVENTIVE_ORDER`, dos tablas con
--   secuencias independientes), y una FK doble obligaría a dos columnas
--   anulables más un `CHECK` de exclusividad para representar lo mismo que el
--   discriminante `stop_type`. El catálogo cerrado de `stop_type` es el que
--   cierra la forma; que el destino exista lo garantiza la aplicación, que
--   escribe esta fila en la misma transacción que el cambio de estado y nunca
--   como registro suelto.
--
-- POR QUÉ HAY TRES MARCAS TEMPORALES Y NO UNA
--   El reloj del teléfono no es autoridad (QA 4). `client_occurred_at` conserva
--   lo que el dispositivo creyó que era la hora, `effective_occurred_at` es el
--   instante que el servidor aplicó de verdad tras resolver el desfase y
--   `synced_at` es cuándo llegó. Guardar sólo la efectiva borraría la prueba de
--   que hubo ajuste; guardar sólo la declarada haría que las métricas de
--   intervención dependieran de un reloj que nadie audita.
--
-- NOTA SOBRE LA IDEMPOTENCIA
--   El ejecutor registra la huella de cada migración y no la reaplica, pero la
--   misma declaración vive también en `database/supabase_init.sql` para que una
--   base creada desde cero y una base migrada converjan. Todo se declara con
--   `IF NOT EXISTS`, los índices con `IF NOT EXISTS` y el disparador se recrea
--   con `DROP TRIGGER IF EXISTS` / `CREATE TRIGGER`.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Tabla 34 · offline_sync_mutations — libro de operaciones sincronizadas
-- ----------------------------------------------------------------------------
-- `response_payload` es la respuesta que el reintento recibe literalmente: si
-- se guardara sólo un «ok», el cliente no podría reconstruir el albarán ni el
-- identificador del comentario creado, y acabaría pidiéndolos otra vez. Se
-- mantiene como `JSONB` porque su forma la dicta el contrato del endpoint, no
-- esta tabla (el registro es un sobre, no un segundo modelo de dominio).
CREATE TABLE IF NOT EXISTS offline_sync_mutations (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id           BIGINT NOT NULL,
    client_mutation_id      VARCHAR(64) NOT NULL,
    stop_type               VARCHAR(20) NOT NULL,
    stop_id                 BIGINT NOT NULL,
    operation_type          VARCHAR(40) NOT NULL,
    outcome_status          VARCHAR(30) NOT NULL DEFAULT 'APPLIED',
    client_occurred_at      TIMESTAMPTZ NOT NULL,
    chain_started_at        TIMESTAMPTZ,
    effective_occurred_at   TIMESTAMPTZ NOT NULL,
    synced_at               TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    clock_skew_adjusted     BOOLEAN NOT NULL DEFAULT FALSE,
    sanitary_seal_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    response_http_status    INT NOT NULL DEFAULT 200,
    response_payload        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_offline_sync_mutations PRIMARY KEY (id),
    CONSTRAINT fk_offline_sync_mutations_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- La clave de idempotencia: la misma operación del mismo técnico no puede
    -- existir dos veces, por mucho que el reintento llegue con otra conexión.
    CONSTRAINT uq_offline_sync_tech_mutation UNIQUE (technician_id, client_mutation_id),
    -- El identificador lo genera el cliente: se acota el formato para que un
    -- valor corrupto o un `NULL` disfrazado falle aquí y no al cruzarlo con la
    -- cola local del dispositivo.
    CONSTRAINT chk_offline_sync_mutation_id_format CHECK (
        char_length(btrim(client_mutation_id)) BETWEEN 8 AND 64
        AND client_mutation_id ~ '^[A-Za-z0-9_.:-]+$'
    ),
    CONSTRAINT chk_offline_sync_stop_type CHECK (
        stop_type IN ('INCIDENT', 'PREVENTIVE_ORDER')
    ),
    -- Las 9 operaciones que la Decisión D1 autoriza sin conexión. El catálogo
    -- vive en el motor para que una operación nueva no entre por una errata del
    -- cliente sin pasar por la especificación.
    CONSTRAINT chk_offline_sync_operation_type CHECK (
        operation_type IN (
            'START_INCIDENT',
            'RESUME_INCIDENT_PENDING_INFO',
            'PAUSE_INCIDENT_PARTS',
            'PAUSE_INCIDENT_PENDING_INFO',
            'RESOLVE_INCIDENT',
            'START_PREVENTIVE',
            'COMPLETE_PREVENTIVE',
            'REINSPECT_PREVENTIVE',
            'ADD_INCIDENT_COMMENT'
        )
    ),
    CONSTRAINT chk_offline_sync_outcome_status CHECK (
        outcome_status IN ('APPLIED', 'CONFLICT_DUMPED', 'DEACTIVATED_ACCOUNT_DUMP')
    )
);

CREATE INDEX IF NOT EXISTS idx_offline_sync_mutations_stop
    ON offline_sync_mutations (stop_type, stop_id, synced_at DESC);

CREATE INDEX IF NOT EXISTS idx_offline_sync_mutations_tech_synced
    ON offline_sync_mutations (technician_id, synced_at DESC);

DROP TRIGGER IF EXISTS trg_no_delete_offline_sync_mutations ON offline_sync_mutations;
CREATE TRIGGER trg_no_delete_offline_sync_mutations
    BEFORE DELETE ON offline_sync_mutations
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();
