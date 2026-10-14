<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\PreventiveSettingsBatchRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Exception\SeasonalPauseMissingReasonException;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoPreventiveSettingsRepository
 * 
 * Implementación PDO del repositorio de configuraciones sanitarias y pausas estacionales.
 * Garantiza cumplimiento de reglas constitucionales (Art. II) y persistencia segura en MariaDB/MySQL.
 *
 * Además del contrato principal, declara la capacidad de lote
 * (`PreventiveSettingsBatchRepositoryInterface::getMachineSettingsForMany()`), que existe para la
 * puerta sanitaria de la bandeja de ruta del técnico: resolver los ajustes de cada parada con una
 * consulta por parada era un N+1 medido contra el motor remoto (Módulo 12 · T-HYBRID-27).
 */
class PdoPreventiveSettingsRepository implements PreventiveSettingsRepositoryInterface, PreventiveSettingsBatchRepositoryInterface
{
    private PDO $pdo;
    private SqlDialectAdapter $dialect;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
    }

    /**
     * @return array<PreventiveSetting>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, machine_type, default_frequency_days, max_allowed_days, advance_warning_days, created_at, updated_at
            FROM preventive_settings
            ORDER BY id ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];

        foreach ($rows as $row) {
            $settings[] = $this->hydrateSetting($row);
        }

        return $settings;
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        $stmt = $this->pdo->prepare("
            SELECT id, machine_type, default_frequency_days, max_allowed_days, advance_warning_days, created_at, updated_at
            FROM preventive_settings
            WHERE machine_type = :machine_type
            LIMIT 1
        ");

        $stmt->execute([':machine_type' => $machineType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateSetting($row);
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        // Blindaje constitucional del Artículo II (Seguridad Alimentaria)
        if ($machineType === 'PERISHABLE_FOOD') {
            if ($defaultFrequencyDays > 15 || $maxAllowedDays > 15) {
                throw new PerishableFrequencyLimitException(
                    'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias.',
                    max($defaultFrequencyDays, $maxAllowedDays),
                    15,
                    'PERISHABLE_FOOD'
                );
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE preventive_settings
            SET 
                default_frequency_days = :default_freq,
                max_allowed_days = :max_days,
                advance_warning_days = :adv_warning,
                updated_at = CURRENT_TIMESTAMP
            WHERE machine_type = :machine_type
        ");

        return $stmt->execute([
            ':default_freq' => $defaultFrequencyDays,
            ':max_days' => $maxAllowedDays,
            ':adv_warning' => $advanceWarningDays,
            ':machine_type' => $machineType,
        ]);
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        // Consultar la tipología de la máquina para validar reglas de perecederos
        $stmtCheck = $this->pdo->prepare("
            SELECT id, machine_type
            FROM machines
            WHERE id = :id AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmtCheck->execute([':id' => $machineId]);
        $machine = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$machine) {
            return false;
        }

        // Blindaje Artículo II si se intenta superar 15 días en perecederos
        if ($sanitaryFrequencyDays !== null && $machine['machine_type'] === 'PERISHABLE_FOOD' && $sanitaryFrequencyDays > 15) {
            throw new PerishableFrequencyLimitException(
                'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias.',
                $sanitaryFrequencyDays,
                15,
                'PERISHABLE_FOOD'
            );
        }

        $sql = "
            UPDATE machines
            SET 
                sanitary_frequency_days = :frequency,
                next_sanitary_inspection_due = COALESCE(:due, next_sanitary_inspection_due),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':frequency' => $sanitaryFrequencyDays,
            ':due' => $nextSanitaryInspectionDue,
            ':id' => $machineId,
        ]);
    }

    public function setSeasonalPause(
        int $machineId,
        string $reason,
        ?string $pauseUntil = null
    ): bool {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new SeasonalPauseMissingReasonException(
                'Debe justificar documentalmente el motivo de la pausa estacional y la fecha estimada de reanudación.',
                $machineId
            );
        }

        $stmt = $this->pdo->prepare("
            UPDATE machines
            SET 
                is_seasonal_pause = {$this->dialect->booleanLiteral(true)},
                seasonal_pause_reason = :reason,
                seasonal_pause_until = :pause_until,
                sanitary_status = 'SEASONAL_PAUSE',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND deleted_at IS NULL
        ");

        return $stmt->execute([
            ':reason' => $trimmedReason,
            ':pause_until' => $pauseUntil,
            ':id' => $machineId,
        ]);
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        // Al reactivarse el servicio, se transiciona a ATTENTION_REQUIRED con vencimiento hoy
        // exigiendo la revisión higiénica previa reglamentaria (EARS 1.5).
        //
        // Blindaje Art. II (T-PAUSE-22): el desbloqueo estacional sólo muerde sobre una
        // máquina que esté REALMENTE en pausa estacional. Antes, un `WHERE id = :id`
        // desnudo retiraba la cuarentena sanitaria de una máquina averiada y la dejaba
        // en ATTENTION_REQUIRED sin checklist: la puerta trasera por la que el Reloj
        // Sanitario Biológico se podía esquivar desde el módulo 05. La cuarentena sólo
        // se levanta con la reinspección reglamentaria del propio módulo 05
        // (temperatura ≤ 4,0 °C + checklist de higienización).
        $stmt = $this->pdo->prepare("
            UPDATE machines
            SET 
                is_seasonal_pause = {$this->dialect->booleanLiteral(false)},
                seasonal_pause_reason = NULL,
                seasonal_pause_until = NULL,
                sanitary_status = 'ATTENTION_REQUIRED',
                next_sanitary_inspection_due = CURRENT_DATE,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
              AND is_seasonal_pause = {$this->dialect->booleanLiteral(true)}
              AND sanitary_status = 'SEASONAL_PAUSE'
        ");

        $stmt->execute([':id' => $machineId]);

        return $stmt->rowCount() > 0;
    }

    public function getMachineSettings(int $machineId): ?array
    {
        // El JOIN entre los dos conjuntos enumerados se resuelve por ETIQUETA en ambos motores:
        // PostgreSQL rechaza el operador directo entre `machine_type` y `preventive_machine_type`
        // —son tipos distintos por contrato— y MariaDB ya compara por etiqueta, así que su
        // sentencia queda intacta. Lo encontró la medición contra el motor remoto: sin el cast,
        // «Mi Ruta» respondía HTTP 500 entera (T-HYBRID-27).
        $stmt = $this->pdo->prepare("
            SELECT 
                m.id AS machine_id,
                m.code AS machine_code,
                m.machine_type,
                m.sanitary_status,
                m.sanitary_frequency_days,
                m.last_sanitary_inspection_at,
                m.next_sanitary_inspection_due,
                m.is_seasonal_pause,
                m.seasonal_pause_reason,
                m.seasonal_pause_until,
                ps.default_frequency_days,
                ps.max_allowed_days,
                ps.advance_warning_days,
                COALESCE(m.sanitary_frequency_days, ps.default_frequency_days) AS effective_frequency_days
            FROM machines m
            LEFT JOIN preventive_settings ps
                ON {$this->dialect->castToText('ps.machine_type')} = {$this->dialect->castToText('m.machine_type')}
            WHERE m.id = :id AND m.deleted_at IS NULL
            LIMIT 1
        ");

        $stmt->execute([':id' => $machineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return self::hydrateMachineSettings($row);
    }

    /**
     * Ajustes preventivos efectivos de un lote de máquinas en UNA sola consulta
     * (Módulo 12 · T-HYBRID-27).
     *
     * Es la forma por lotes de `getMachineSettings()`: misma sentencia —incluido el JOIN entre los
     * dos conjuntos enumerados, que se resuelve por ETIQUETA en ambos motores— sobre `m.id IN (...)`, y
     * la misma estructura publicada. La puerta sanitaria de la ruta del técnico leía los ajustes
     * de una en una al pintar cada parada, y contra el motor remoto esa lectura era una de las
     * tres vueltas por parada que agotaban el presupuesto de RNF-01.
     *
     * Los identificadores viajan como marcadores nombrados enlazados con `PDO::PARAM_INT` —la
     * forma que los dos drivers traducen a su tipo entero—, nunca interpolados en la sentencia.
     * Se deduplican y se descartan los imposibles, de modo que la consulta no lleva huecos.
     *
     * @param array<int, int|string> $machineIds
     * @return array<int, array<string, mixed>> Mapa `id de máquina => ajustes`, sólo con las
     *         máquinas encontradas.
     */
    public function getMachineSettingsForMany(array $machineIds): array
    {
        $ids = [];
        foreach ($machineIds as $machineId) {
            $id = (int)$machineId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ":settings_batch_{$index}";
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $stmt = $this->pdo->prepare("
            SELECT 
                m.id AS machine_id,
                m.code AS machine_code,
                m.machine_type,
                m.sanitary_status,
                m.sanitary_frequency_days,
                m.last_sanitary_inspection_at,
                m.next_sanitary_inspection_due,
                m.is_seasonal_pause,
                m.seasonal_pause_reason,
                m.seasonal_pause_until,
                ps.default_frequency_days,
                ps.max_allowed_days,
                ps.advance_warning_days,
                COALESCE(m.sanitary_frequency_days, ps.default_frequency_days) AS effective_frequency_days
            FROM machines m
            LEFT JOIN preventive_settings ps
                ON {$this->dialect->castToText('ps.machine_type')} = {$this->dialect->castToText('m.machine_type')}
            WHERE m.id IN (" . implode(', ', $placeholders) . ")
              AND m.deleted_at IS NULL
            ORDER BY m.id ASC
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(int)$row['machine_id']] = self::hydrateMachineSettings($row);
        }

        return $settings;
    }

    /**
     * Hidrata la fila de ajustes efectivos de una máquina.
     *
     * Una sola definición de la estructura publicada: la comparten la lectura individual
     * (`getMachineSettings()`), la lectura por lotes (`getMachineSettingsForMany()`) y el tablero de
     * ruta del técnico (`PdoIncidentRepository::findRouteBoard()`, Módulo 12 · T-HYBRID-27), que
     * resuelve los ajustes de toda la bandeja en su única consulta. De ahí que sea `public static`:
     * la estructura se define aquí y en ningún otro sitio, y ningún consumidor la reimplementa.
     *
     * @param array<string, mixed> $row Fila con las columnas que esta clase publica.
     * @return array<string, mixed>
     */
    public static function hydrateMachineSettings(array $row): array
    {
        return [
            'machine_id' => (int)$row['machine_id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_type' => (string)$row['machine_type'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'sanitary_frequency_days' => $row['sanitary_frequency_days'] !== null ? (int)$row['sanitary_frequency_days'] : null,
            'effective_frequency_days' => (int)$row['effective_frequency_days'],
            'default_frequency_days' => $row['default_frequency_days'] !== null ? (int)$row['default_frequency_days'] : 30,
            'max_allowed_days' => $row['max_allowed_days'] !== null ? (int)$row['max_allowed_days'] : 60,
            'advance_warning_days' => $row['advance_warning_days'] !== null ? (int)$row['advance_warning_days'] : 5,
            'last_sanitary_inspection_at' => $row['last_sanitary_inspection_at'],
            'next_sanitary_inspection_due' => $row['next_sanitary_inspection_due'],
            'is_seasonal_pause' => (bool)$row['is_seasonal_pause'],
            'seasonal_pause_reason' => $row['seasonal_pause_reason'],
            'seasonal_pause_until' => $row['seasonal_pause_until'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        // Transición idempotente (compare-and-set): sólo toca la fila cuando el valor
        // cambia, de modo que el retorno es una promesa fuerte —«esta llamada es la que
        // escribió el estado»— y no un simple «la sentencia no falló». Es lo que permite
        // al Reloj Sanitario Biológico arbitrar la carrera entre sus dos consumidores
        // —la puerta sanitaria del cierre técnico y el barrido de fondo (T-PAUSE-38)— y
        // garantizar un único rastro inmutable por cuarentena (Art. III).
        // Dos marcadores distintos para el mismo valor a propósito: con prepares nativos
        // (sin `ATTR_EMULATE_PREPARES`) un nombre repetido se aplana a marcadores
        // posicionales y PDO no puede resolverlo (`SQLSTATE[HY093]: Invalid parameter
        // number`). El contrato es el mismo —escribir sólo si el valor cambia— y cada
        // `?` viaja con su binding.
        $stmt = $this->pdo->prepare("
            UPDATE machines
            SET sanitary_status = :new_status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
              AND sanitary_status <> :previous_status
        ");

        $stmt->execute([
            ':new_status' => $status,
            ':previous_status' => $status,
            ':id' => $machineId,
        ]);

        // `rowCount()` sobre un UPDATE cuenta sólo las filas realmente modificadas
        // (el proyecto no activa `PDO::MYSQL_ATTR_FOUND_ROWS`), que es exactamente la
        // pregunta del compare-and-set. MySQL reevalúa el `WHERE` tras tomar el bloqueo
        // de la fila, así que de dos escritores simultáneos uno solo ve la transición.
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @return PreventiveSetting
     */
    private function hydrateSetting(array $row): PreventiveSetting
    {
        return new PreventiveSetting(
            (int)$row['id'],
            (string)$row['machine_type'],
            (int)$row['default_frequency_days'],
            (int)$row['max_allowed_days'],
            (int)($row['advance_warning_days'] ?? 5),
            (string)($row['created_at'] ?? ''),
            (string)($row['updated_at'] ?? '')
        );
    }
}
