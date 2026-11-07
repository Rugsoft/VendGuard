<?php

declare(strict_types=1);

namespace VendGuard\Database;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Sembrador de averías activas para el Mapa Territorial de Coordinación (RF-MAP-09).
 *
 * QUÉ RESUELVE
 *
 * El mapa territorial filtra por técnico asignado, severidad y trabajo sin asignar. Con el
 * catálogo maestro recién sembrado no hay ni una sola avería, así que el filtro por técnico
 * siempre devuelve el mapa vacío y no se puede comprobar que conserve las sedes del operario
 * elegido. Este sembrador crea un juego de averías **activas** repartidas a propósito:
 *
 *   · SEDE-BCN-01 y SEDE-BCN-03 trabajadas por Jordi; SEDE-BCN-02 y SEDE-BCN-03 por Marta
 *     (la 03 queda multi-técnico, que es el caso del distintivo de consolidación).
 *   · SEDE-BCN-04 para Carlos y SEDE-BCN-07 para Elena, esta última crítica de cadena de frío
 *     y en curso.
 *   · SEDE-BCN-05 con una avería **sin asignar**, para el filtro «Solo sin asignar».
 *   · Marc queda sin trabajo a propósito: es el técnico con el que se prueba el estado vacío
 *     del filtro.
 *
 * REGLAS QUE RESPETA
 *
 *   · Una avería activa por máquina (`uq_machine_active_ticket`): cada fixture usa una máquina
 *     distinta y, si la máquina ya tiene un ticket vivo, se omite y se declara en vez de
 *     reventar la siembra.
 *   · Nada se pisa: la clave natural es el código de ticket (`INC-MAPA-…`). Volver a ejecutar
 *     el sembrador no duplica nada; informa de lo que ya existía.
 *   · No se inventan cerrados ni resoluciones: las averías nacen abiertas, con su marca de
 *     creación, asignación e inicio coherentes con el estado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: identificadores en inglés, documentación y mensajes en castellano.
 */
final class DemoMapSeeder
{
    /** Estados que ocupan la máquina según `uq_machine_active_ticket`. */
    private const OCCUPYING_STATUSES = ['REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO', 'RESOLVED', 'REOPENED'];

    private readonly PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Juego de averías de demostración del mapa territorial.
     *
     * `reported_minutes_ago` sitúa el alta en el pasado reciente para que las averías estén
     * vivas y el reloj de SLA tenga sentido; el resto de marcas derivan de ella.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fixtures(): array
    {
        return [
            [
                'ticket_code' => 'INC-MAPA-01',
                'machine_code' => 'VEND-0101',
                'category' => 'TEMPERATURE_COLD',
                'urgency' => 'CRITICAL',
                'status' => 'ASSIGNED',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'reporter_name' => 'Dra. Carmen Morales',
                'reporter_phone' => '611223344',
                'description' => 'Cuba de sándwiches a 8,6 °C con alarma térmica y lácteos templados al tacto en la bandeja superior.',
                'reported_minutes_ago' => 95,
                'assign_delay_minutes' => 12,
            ],
            [
                'ticket_code' => 'INC-MAPA-02',
                'machine_code' => 'VEND-0102',
                'category' => 'PAYMENT_SYSTEM',
                'urgency' => 'HIGH',
                'status' => 'ASSIGNED',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'reporter_name' => 'Marc Recepción',
                'reporter_phone' => '600333444',
                'description' => 'El lector de tarjeta rechaza el contactless y devuelve el importe sin dispensar el café.',
                'reported_minutes_ago' => 140,
                'assign_delay_minutes' => 15,
            ],
            [
                'ticket_code' => 'INC-MAPA-03',
                'machine_code' => 'VEND-0201',
                'category' => 'PRODUCT_JAM',
                'urgency' => 'MEDIUM',
                'status' => 'ASSIGNED',
                'technician_email' => 'marta.ruta@vendguard.internal',
                'reporter_name' => 'Clara Innovació',
                'reporter_phone' => '611222333',
                'description' => 'Espiral 9 de snacks trabada con la bolsa a medio caer sobre la trampilla de entrega.',
                'reported_minutes_ago' => 210,
                'assign_delay_minutes' => 18,
            ],
            [
                'ticket_code' => 'INC-MAPA-04',
                'machine_code' => 'VEND-0301',
                'category' => 'TEMPERATURE_COLD',
                'urgency' => 'HIGH',
                'status' => 'ASSIGNED',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'reporter_name' => 'Carles Coordinador Bellvitge',
                'reporter_phone' => '600555666',
                'description' => 'Bandeja de ensaladas entre 6,8 °C y 7,4 °C durante toda la mañana sin recuperar consigna.',
                'reported_minutes_ago' => 260,
                'assign_delay_minutes' => 14,
            ],
            [
                'ticket_code' => 'INC-MAPA-05',
                'machine_code' => 'VEND-0302',
                'category' => 'ELECTRICAL_OFF',
                'urgency' => 'MEDIUM',
                'status' => 'ASSIGNED',
                'technician_email' => 'marta.ruta@vendguard.internal',
                'reporter_name' => 'Carles Coordinador Bellvitge',
                'reporter_phone' => '600555666',
                'description' => 'El módulo de café se apaga solo tras dos servicios y muestra el display sin tensión.',
                'reported_minutes_ago' => 180,
                'assign_delay_minutes' => 20,
            ],
            [
                'ticket_code' => 'INC-MAPA-06',
                'machine_code' => 'VEND-0401',
                'category' => 'PAYMENT_SYSTEM',
                'urgency' => 'LOW',
                'status' => 'ASSIGNED',
                'technician_email' => 'carlos.ruta@vendguard.internal',
                'reporter_name' => 'Nuria Oficinas 22',
                'reporter_phone' => '699888777',
                'description' => 'El monedero no devuelve el cambio de un euro y acumula monedas en el canal de retorno.',
                'reported_minutes_ago' => 320,
                'assign_delay_minutes' => 25,
            ],
            [
                'ticket_code' => 'INC-MAPA-07',
                'machine_code' => 'VEND-0501',
                'category' => 'PRODUCT_JAM',
                'urgency' => 'MEDIUM',
                'status' => 'REGISTERED',
                'technician_email' => null,
                'reporter_name' => 'Albert Campus UPC',
                'reporter_phone' => '600999000',
                'description' => 'Las barritas energéticas de la espiral 6 no caen al pulsar el código de selección.',
                'reported_minutes_ago' => 75,
                'assign_delay_minutes' => null,
            ],
            [
                'ticket_code' => 'INC-MAPA-08',
                'machine_code' => 'VEND-0701',
                'category' => 'TEMPERATURE_COLD',
                'urgency' => 'CRITICAL',
                'status' => 'IN_PROGRESS',
                'technician_email' => 'elena.ruta@vendguard.internal',
                'reporter_name' => 'Sergi Logística Can Ruti',
                'reporter_phone' => '611444555',
                'description' => 'Aviso sonoro continuo por desvío térmico sostenido a 7,9 °C en la máquina de sándwiches.',
                'reported_minutes_ago' => 130,
                'assign_delay_minutes' => 10,
                'start_delay_minutes' => 35,
            ],
        ];
    }

    /**
     * Siembra el juego de averías y devuelve lo que hizo con cada código de ticket.
     *
     * @return array{inserted: array<int, string>, existing: array<int, string>, skipped: array<int, string>}
     */
    public function seed(): array
    {
        $machines = $this->machinesByCode();
        $technicians = $this->techniciansByEmail();
        $now = new DateTimeImmutable('now');

        $inserted = [];
        $existing = [];
        $skipped = [];

        $this->pdo->beginTransaction();

        try {
            foreach (self::fixtures() as $fixture) {
                $ticketCode = (string)$fixture['ticket_code'];

                if ($this->ticketExists($ticketCode)) {
                    $existing[] = $ticketCode;
                    continue;
                }

                $machineCode = (string)$fixture['machine_code'];
                $machine = $machines[$machineCode] ?? null;
                if ($machine === null) {
                    $skipped[] = $ticketCode . ' (máquina ' . $machineCode . ' inexistente)';
                    continue;
                }

                $technicianId = null;
                $technicianEmail = $fixture['technician_email'];
                if ($technicianEmail !== null) {
                    $technicianId = $technicians[(string)$technicianEmail] ?? null;
                    if ($technicianId === null) {
                        $skipped[] = $ticketCode . ' (técnico ' . $technicianEmail . ' inexistente o inactivo)';
                        continue;
                    }
                }

                if ($this->machineHasActiveTicket($machine['id'])) {
                    $skipped[] = $ticketCode . ' (máquina ' . $machineCode . ' ya ocupada por otro aviso vivo)';
                    continue;
                }

                $this->insertIncident($fixture, $machine, $technicianId, $now);
                $inserted[] = $ticketCode;
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException(
                'Error sembrando las averías del mapa territorial: ' . $exception->getMessage(),
                (int)$exception->getCode(),
                $exception
            );
        }

        return ['inserted' => $inserted, 'existing' => $existing, 'skipped' => $skipped];
    }

    /**
     * Resumen de la siembra tal y como lo lee el coordinador: sede, técnico y averías vivas.
     *
     * @return array<int, array{site_code: string, site_name: string, technician: string, tickets: int}>
     */
    public function summary(): array
    {
        $sql = 'SELECT l.site_code, l.name AS site_name,'
            . " COALESCE(u.name, 'Sin asignar') AS technician, COUNT(*) AS tickets"
            . ' FROM incidents i'
            . ' JOIN locations l ON l.id = i.location_id'
            . ' LEFT JOIN users u ON u.id = i.assigned_technician_id'
            . " WHERE i.ticket_code LIKE 'INC-MAPA-%' AND i.deleted_at IS NULL"
            . ' GROUP BY l.site_code, l.name, COALESCE(u.name, \'Sin asignar\')'
            . ' ORDER BY l.site_code, technician';

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'site_code' => (string)$row['site_code'],
                'site_name' => (string)$row['site_name'],
                'technician' => (string)$row['technician'],
                'tickets' => (int)$row['tickets'],
            ],
            $rows
        );
    }

    /**
     * @return array<string, array{id: int, location_id: int, machine_type: string}>
     */
    private function machinesByCode(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, code, location_id, machine_type FROM machines WHERE deleted_at IS NULL'
        )->fetchAll(PDO::FETCH_ASSOC);

        $machines = [];
        foreach ($rows as $row) {
            $machines[(string)$row['code']] = [
                'id' => (int)$row['id'],
                'location_id' => (int)$row['location_id'],
                'machine_type' => (string)$row['machine_type'],
            ];
        }

        return $machines;
    }

    /**
     * @return array<string, int>
     */
    private function techniciansByEmail(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, email FROM users WHERE role = 'TECHNICIAN' AND is_active = TRUE AND deleted_at IS NULL"
        );

        $technicians = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $technicians[(string)$row['email']] = (int)$row['id'];
        }

        return $technicians;
    }

    private function ticketExists(string $ticketCode): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM incidents WHERE ticket_code = :ticket_code');
        $statement->execute([':ticket_code' => $ticketCode]);

        return $statement->fetchColumn() !== false;
    }

    private function machineHasActiveTicket(int $machineId): bool
    {
        $placeholders = implode(', ', array_map(static fn (string $status): string => "'" . $status . "'", self::OCCUPYING_STATUSES));

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM incidents WHERE machine_id = :machine_id'
            . ' AND deleted_at IS NULL AND status IN (' . $placeholders . ')'
        );
        $statement->execute([':machine_id' => $machineId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array{id: int, location_id: int, machine_type: string} $machine
     */
    private function insertIncident(array $fixture, array $machine, ?int $technicianId, DateTimeImmutable $now): void
    {
        $createdAt = $now->modify('-' . (int)$fixture['reported_minutes_ago'] . ' minutes');
        $assignDelay = $fixture['assign_delay_minutes'];
        $assignedAt = $assignDelay === null ? null : $createdAt->modify('+' . (int)$assignDelay . ' minutes');
        $startedAt = isset($fixture['start_delay_minutes']) && $assignedAt !== null
            ? $createdAt->modify('+' . (int)$fixture['start_delay_minutes'] . ' minutes')
            : null;

        $sql = 'INSERT INTO incidents ('
            . 'ticket_code, machine_id, machine_type_snapshot, location_id, assigned_technician_id,'
            . ' reporter_name, reporter_phone, category, description, urgency, status,'
            . ' assigned_at, started_at, cycle_started_at, created_at, updated_at'
            . ') VALUES ('
            . ':ticket_code, :machine_id, :machine_type_snapshot, :location_id, :assigned_technician_id,'
            . ' :reporter_name, :reporter_phone, :category, :description, :urgency, :status,'
            . ' :assigned_at, :started_at, :cycle_started_at, :created_at, :updated_at'
            . ')';

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':ticket_code' => $fixture['ticket_code'],
            ':machine_id' => $machine['id'],
            ':machine_type_snapshot' => $machine['machine_type'],
            ':location_id' => $machine['location_id'],
            ':assigned_technician_id' => $technicianId,
            ':reporter_name' => $fixture['reporter_name'],
            ':reporter_phone' => $fixture['reporter_phone'],
            ':category' => $fixture['category'],
            ':description' => $fixture['description'],
            ':urgency' => $fixture['urgency'],
            ':status' => $fixture['status'],
            ':assigned_at' => $assignedAt?->format('c'),
            ':started_at' => $startedAt?->format('c'),
            ':cycle_started_at' => ($assignedAt ?? $createdAt)->format('c'),
            ':created_at' => $createdAt->format('c'),
            ':updated_at' => ($startedAt ?? $assignedAt ?? $createdAt)->format('c'),
        ]);
    }
}
