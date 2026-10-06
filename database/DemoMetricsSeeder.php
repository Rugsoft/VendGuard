<?php

declare(strict_types=1);

namespace VendGuard\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * DemoMetricsSeeder
 * 
 * Puebla la base de datos con un historial representativo y realista de:
 * 1. Averías resueltas y cerradas con diagnósticos técnicos y acciones de resolución (Art. V.1).
 * 2. Tiempos de resolución (MTTR) que demuestran cumplimiento de SLA (< 4.0h en perecederos).
 * 3. Incidencias en distintos estados: en garantía (RESOLVED) y cerradas (CLOSED).
 * 4. Trazabilidad completa en `audit_log` con eventos de creación, asignación, inicio, resolución y cierre.
 * 
 * Idempotente: utiliza ON DUPLICATE KEY UPDATE respetando el Artículo III.
 */
class DemoMetricsSeeder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Ejecuta el sembrado de métricas, reparaciones e histórico de auditoría.
     *
     * @return array<string, int> Conteo de registros insertados.
     */
    public function seed(): array
    {
        $this->ensureUsers();

        $locMap = $this->getLocationMap();
        $machMap = $this->getMachineMap();
        $userMap = $this->getUserMap();

        if (empty($locMap) || empty($machMap) || empty($userMap)) {
            throw new RuntimeException("No se encontraron sedes, máquinas o usuarios base para sembrar métricas.");
        }

        $jordiId = $userMap['jordi.ruta@vendguard.internal'] ?? null;
        $martaId = $userMap['marta.ruta@vendguard.internal'] ?? null;
        $saraId = $userMap['coordinacion@vendguard.internal'] ?? null;

        if ($jordiId === null || $martaId === null || $saraId === null) {
            throw new RuntimeException("No se pudieron resolver los IDs de los usuarios técnicos y coordinadora.");
        }

        $vend0101 = $machMap['VEND-0101'] ?? null; // PERISHABLE_FOOD (Hospital del Mar)
        $vend0102 = $machMap['VEND-0102'] ?? null; // HOT_DRINKS (Hospital del Mar)
        $vend0201 = $machMap['VEND-0201'] ?? null; // COMBO (Torre Glòries)
        $vend0301 = $machMap['VEND-0301'] ?? null; // PERISHABLE_FOOD (Bellvitge)
        $vend0401 = $machMap['VEND-0401'] ?? null; // COMBO (WTC)
        $vend0501 = $machMap['VEND-0501'] ?? null; // SNACKS (Campus Nord)
        $vend0601 = $machMap['VEND-0601'] ?? null; // COLD_DRINKS (Parc Tecnològic)
        $vend0701 = $machMap['VEND-0701'] ?? null; // PERISHABLE_FOOD (Badalona Can Ruti)
        $vend1001 = $machMap['VEND-1001'] ?? null; // HOT_DRINKS (WTC Almeda)

        $carlosId = $userMap['carlos.ruta@vendguard.internal'] ?? null;
        $elenaId  = $userMap['elena.ruta@vendguard.internal'] ?? null;
        $marcId   = $userMap['marc.ruta@vendguard.internal'] ?? null;

        if ($vend0101 === null || $vend0102 === null || $vend0201 === null) {
            throw new RuntimeException("No se pudieron resolver las máquinas base VEND-0101, VEND-0102, VEND-0201.");
        }

        $this->pdo->beginTransaction();

        try {
            // Definición de averías y reparaciones con fechas relativas dinámicas
            $ref = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));

            $incidents = [
                // 1. Período anterior (hace 35 días): VEND-0101 Frío perecedero resuelto en 2h 10m (cumple SLA 4h)
                [
                    'ticket_code' => 'INC-DEMO-0810',
                    'machine_id' => $vend0101['id'],
                    'location_id' => $vend0101['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Dra. Carmen Morales',
                    'reporter_phone' => '611223344',
                    'category' => 'TEMPERATURE_COLD',
                    'description' => 'Temperatura en cuba de sándwiches a 9.2°C con aviso acústico intermitente en display.',
                    'urgency' => 'CRITICAL',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-35 days')->setTime(8, 30, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-35 days')->setTime(8, 45, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-35 days')->setTime(9, 10, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-35 days')->setTime(10, 40, 0)->format('Y-m-d H:i:s'), // 130 min (2h 10m)
                    'closed_at' => $ref->modify('-33 days')->setTime(10, 40, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Sonda de temperatura NTC averiada por pico de tensión registrando falsos 12°C en cuba.',
                    'action' => 'Sustitución de sonda NTC y ajuste de parámetros de histéresis a 3.5°C estables.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 2. Período anterior (hace 32 días): VEND-0102 Café caliente resuelto en 3h 45m
                [
                    'ticket_code' => 'INC-DEMO-0822',
                    'machine_id' => $vend0102['id'],
                    'location_id' => $vend0102['location_id'],
                    'assigned_technician_id' => $martaId,
                    'reporter_name' => 'Enrique Vigilancia',
                    'reporter_phone' => '622334455',
                    'category' => 'PRODUCT_JAM',
                    'description' => 'El café no cae en vaso y sale vapor denso por la ranura de erogación.',
                    'urgency' => 'MEDIUM',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-32 days')->setTime(14, 0, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-32 days')->setTime(14, 30, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-32 days')->setTime(15, 15, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-32 days')->setTime(17, 45, 0)->format('Y-m-d H:i:s'), // 225 min (3h 45m)
                    'closed_at' => $ref->modify('-30 days')->setTime(17, 45, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Atasco del grupo de infusión de café por apelmazamiento de molienda excesivamente fina.',
                    'action' => 'Desmontaje del grupo erogador, limpieza por inmersión y reajuste del micrométrico del molino.',
                    'tech_name' => 'Marta Técnica Ruta BCN',
                ],

                // 3. Período anterior / hace 26 días (Últimos 30 días): VEND-0201 Pago con tarjeta resuelto en 3h 30m
                [
                    'ticket_code' => 'INC-DEMO-0828',
                    'machine_id' => $vend0201['id'],
                    'location_id' => $vend0201['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Sonia Administrativa',
                    'reporter_phone' => '633445566',
                    'category' => 'PAYMENT_SYSTEM',
                    'description' => 'El datáfono contactless indica error de comunicación y rechaza pagos con tarjeta.',
                    'urgency' => 'HIGH',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-26 days')->setTime(10, 15, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-26 days')->setTime(10, 30, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-26 days')->setTime(11, 0, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-26 days')->setTime(13, 45, 0)->format('Y-m-d H:i:s'), // 210 min (3h 30m)
                    'closed_at' => $ref->modify('-24 days')->setTime(13, 45, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Fallo de comunicación del lector contactless por cableado MDB pinzado en puerta.',
                    'action' => 'Reparación y enfundado del mazo de cables MDB y actualización del firmware del lector Nayax.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 4. Últimos 30 días (Hace 18 días): VEND-0101 Frío perecedero resuelto en 2h 05m (cumple SLA 4h)
                [
                    'ticket_code' => 'INC-DEMO-0904',
                    'machine_id' => $vend0101['id'],
                    'location_id' => $vend0101['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Laura Sanitaria',
                    'reporter_phone' => '600111222',
                    'category' => 'TEMPERATURE_COLD',
                    'description' => 'Aviso preventivo: compresor encendido de forma continua con escarcha en evaporador.',
                    'urgency' => 'CRITICAL',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-18 days')->setTime(9, 0, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-18 days')->setTime(9, 12, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-18 days')->setTime(9, 35, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-18 days')->setTime(11, 5, 0)->format('Y-m-d H:i:s'), // 125 min (2h 05m)
                    'closed_at' => $ref->modify('-16 days')->setTime(11, 5, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Acumulación de suciedad en la rejilla del ventilador evaporador reduciendo el flujo térmico.',
                    'action' => 'Limpieza profunda con desengrasante alimentario y comprobación del ciclo de desescarche.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 5. Últimos 30 días (Hace 12 días): VEND-0201 Atasco snacks resuelto en 2h 50m
                [
                    'ticket_code' => 'INC-DEMO-0910',
                    'machine_id' => $vend0201['id'],
                    'location_id' => $vend0201['location_id'],
                    'assigned_technician_id' => $martaId,
                    'reporter_name' => 'Marc Recepción',
                    'reporter_phone' => '600333444',
                    'category' => 'PRODUCT_JAM',
                    'description' => 'Bolsa de patatas enganchada en la espiral 23 sin caer al cajón de entrega.',
                    'urgency' => 'MEDIUM',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-12 days')->setTime(11, 20, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-12 days')->setTime(11, 45, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-12 days')->setTime(12, 30, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-12 days')->setTime(14, 10, 0)->format('Y-m-d H:i:s'), // 170 min (2h 50m)
                    'closed_at' => $ref->modify('-10 days')->setTime(14, 10, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Bolsa de frutos secos trabada en la trampilla basculante de recogida de producto.',
                    'action' => 'Alineación del muelle de retorno de la trampilla y verificación de diez ciclos de dispensación.',
                    'tech_name' => 'Marta Técnica Ruta BCN',
                ],

                // 6. Últimos 7 días (Hace 5 días): VEND-0102 Fuga eléctrica resuelto en 3h 00m
                [
                    'ticket_code' => 'INC-DEMO-0916',
                    'machine_id' => $vend0102['id'],
                    'location_id' => $vend0102['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Guillermo Mantenimiento',
                    'reporter_phone' => '644556677',
                    'category' => 'ELECTRICAL_OFF',
                    'description' => 'Máquina totalmente apagada. El diferencial del cuadro saltó a primera hora.',
                    'urgency' => 'HIGH',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-5 days')->setTime(7, 45, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-5 days')->setTime(8, 0, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-5 days')->setTime(8, 30, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-5 days')->setTime(10, 45, 0)->format('Y-m-d H:i:s'), // 180 min (3h 00m)
                    'closed_at' => $ref->modify('-3 days')->setTime(10, 45, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Fuga de agua en racor de entrada de electroválvula provocando salto diferencial.',
                    'action' => 'Sustitución de racor rápido y tramo de teflón de 6mm con secado completo de la electrónica.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 7. Últimos 7 días (Hace 3 días): VEND-0201 Monedero resuelto en 2h 45m
                [
                    'ticket_code' => 'INC-DEMO-0919',
                    'machine_id' => $vend0201['id'],
                    'location_id' => $vend0201['location_id'],
                    'assigned_technician_id' => $martaId,
                    'reporter_name' => 'Patricia RRHH',
                    'reporter_phone' => '655667788',
                    'category' => 'PAYMENT_SYSTEM',
                    'description' => 'Traga monedas de 1 euro sin acreditar saldo ni devolver el importe.',
                    'urgency' => 'LOW',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-3 days')->setTime(15, 10, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-3 days')->setTime(15, 30, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-3 days')->setTime(16, 15, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-3 days')->setTime(17, 55, 0)->format('Y-m-d H:i:s'), // 165 min (2h 45m)
                    'closed_at' => $ref->modify('-1 day')->setTime(17, 55, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Lector de monedas rechazaba por acumulación de grasa en las fotocélulas de entrada.',
                    'action' => 'Limpieza de fotocélulas ópticas y recalibración del canal de validación de 1€ y 2€.',
                    'tech_name' => 'Marta Técnica Ruta BCN',
                ],

                // 8. Ayer (Últimos 7 días): VEND-0101 Display resuelto en 1h 45m
                [
                    'ticket_code' => 'INC-DEMO-0922',
                    'machine_id' => $vend0101['id'],
                    'location_id' => $vend0101['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Laura Sanitaria',
                    'reporter_phone' => '600111222',
                    'category' => 'OTHER',
                    'description' => 'Display frontal con caracteres ilegibles dificultando la selección de productos.',
                    'urgency' => 'LOW',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-1 day')->setTime(9, 15, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-1 day')->setTime(9, 30, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-1 day')->setTime(9, 50, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-1 day')->setTime(11, 0, 0)->format('Y-m-d H:i:s'), // 105 min (1h 45m)
                    'closed_at' => $ref->modify('-1 day')->setTime(20, 0, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Falso contacto en conector cinta ribbon del display LCD frontal de la máquina.',
                    'action' => 'Sustitución de cable plano ribbon y ajuste de los tornillos de fijación del frontal.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 9. Bellvitge (Hace 8 días): VEND-0301 Alimentos Perecederos resuelto en 1h 50m (cumple SLA 4h)
                [
                    'ticket_code' => 'INC-DEMO-0925',
                    'machine_id' => ($vend0301 !== null ? $vend0301['id'] : $vend0101['id']),
                    'location_id' => ($vend0301 !== null ? $vend0301['location_id'] : $vend0101['location_id']),
                    'assigned_technician_id' => ($carlosId ?? $jordiId),
                    'reporter_name' => 'Carles Coordinador Bellvitge',
                    'reporter_phone' => '600555666',
                    'category' => 'TEMPERATURE_COLD',
                    'description' => 'Aviso en sonda de temperatura: oscilaciones entre 6.8°C y 8.1°C en bandeja inferior de ensaladas.',
                    'urgency' => 'CRITICAL',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-8 days')->setTime(8, 0, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-8 days')->setTime(8, 15, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-8 days')->setTime(8, 40, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-8 days')->setTime(9, 50, 0)->format('Y-m-d H:i:s'), // 110 min (1h 50m)
                    'closed_at' => $ref->modify('-6 days')->setTime(9, 50, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Obstrucción parcial en el conducto de retorno de aire por envoltorio plástico suelto.',
                    'action' => 'Retirada de elemento obstructor, limpieza del plenum y verificación de temperatura a 3.8°C constante.',
                    'tech_name' => 'Carlos Técnico Ruta Sud',
                ],

                // 10. Campus Nord UPC (Hace 6 días): VEND-0501 Atasco espiral aperitivos resuelto en 2h 15m
                [
                    'ticket_code' => 'INC-DEMO-0928',
                    'machine_id' => ($vend0501 !== null ? $vend0501['id'] : $vend0201['id']),
                    'location_id' => ($vend0501 !== null ? $vend0501['location_id'] : $vend0201['location_id']),
                    'assigned_technician_id' => ($elenaId ?? $martaId),
                    'reporter_name' => 'Albert Campus UPC',
                    'reporter_phone' => '600999000',
                    'category' => 'PRODUCT_JAM',
                    'description' => 'Espirales de barritas energéticas trabadas tras intento de compra.',
                    'urgency' => 'MEDIUM',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-6 days')->setTime(10, 10, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-6 days')->setTime(10, 25, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-6 days')->setTime(11, 0, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-6 days')->setTime(12, 25, 0)->format('Y-m-d H:i:s'), // 135 min (2h 15m)
                    'closed_at' => $ref->modify('-4 days')->setTime(12, 25, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Desalineación mecánica del microinterruptor de fin de carrera del motor de espiral.',
                    'action' => 'Ajuste del soporte del motor y test de giro completo con cinco expulsiones correctas.',
                    'tech_name' => 'Elena Técnica Ruta Nord',
                ],

                // 11. Parc Tecnològic (Hace 4 días): VEND-0601 Bebidas Frías - fallo datáfono resuelto en 2h 20m
                [
                    'ticket_code' => 'INC-DEMO-1001',
                    'machine_id' => ($vend0601 !== null ? $vend0601['id'] : $vend0201['id']),
                    'location_id' => ($vend0601 !== null ? $vend0601['location_id'] : $vend0201['location_id']),
                    'assigned_technician_id' => ($marcId ?? $jordiId),
                    'reporter_name' => 'Clara Innovació',
                    'reporter_phone' => '611222333',
                    'category' => 'PAYMENT_SYSTEM',
                    'description' => 'El lector contactless muestra error de red móvil y deniega cobro con tarjeta.',
                    'urgency' => 'HIGH',
                    'status' => 'CLOSED',
                    'created_at' => $ref->modify('-4 days')->setTime(12, 0, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-4 days')->setTime(12, 15, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-4 days')->setTime(12, 50, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-4 days')->setTime(14, 20, 0)->format('Y-m-d H:i:s'), // 140 min (2h 20m)
                    'closed_at' => $ref->modify('-2 days')->setTime(14, 20, 0)->format('Y-m-d H:i:s'),
                    'diagnosis' => 'Antena adhesiva 4G suelta dentro del chasis metálico generando apantallamiento de señal.',
                    'action' => 'Reposicionamiento exterior de la antena magnética y test de cobertura con operadora satisfactorio.',
                    'tech_name' => 'Marc Técnico Express BCN',
                ],

                // 12. Badalona Can Ruti (Ayer): VEND-0701 Alimentos Perecederos resuelto en 1h 40m (cumple SLA 4h)
                [
                    'ticket_code' => 'INC-DEMO-1003',
                    'machine_id' => ($vend0701 !== null ? $vend0701['id'] : $vend0101['id']),
                    'location_id' => ($vend0701 !== null ? $vend0701['location_id'] : $vend0101['location_id']),
                    'assigned_technician_id' => ($elenaId ?? $martaId),
                    'reporter_name' => 'Sergi Logística Can Ruti',
                    'reporter_phone' => '611444555',
                    'category' => 'TEMPERATURE_COLD',
                    'description' => 'Aviso sonoro continuo por desvío térmico a 7.5°C en máquina de sándwiches.',
                    'urgency' => 'CRITICAL',
                    'status' => 'RESOLVED', // En periodo de garantía de 48h
                    'created_at' => $ref->modify('-1 day')->setTime(13, 0, 0)->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-1 day')->setTime(13, 10, 0)->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-1 day')->setTime(13, 30, 0)->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-1 day')->setTime(14, 40, 0)->format('Y-m-d H:i:s'), // 100 min (1h 40m)
                    'closed_at' => null, // En garantía
                    'diagnosis' => 'Sensor NTC descalibrado 3.2°C por condensación en el conector estanco.',
                    'action' => 'Secado con aire caliente, sellado con grasa de silicona dieléctrica y lectura normalizada a 3.4°C.',
                    'tech_name' => 'Elena Técnica Ruta Nord',
                ],

                // 13. WTC Almeda Cornellà (Hoy): VEND-1001 Café caliente resuelto en 1h 55m
                [
                    'ticket_code' => 'INC-DEMO-1005',
                    'machine_id' => ($vend1001 !== null ? $vend1001['id'] : $vend0102['id']),
                    'location_id' => ($vend1001 !== null ? $vend1001['location_id'] : $vend0102['location_id']),
                    'assigned_technician_id' => ($carlosId ?? $jordiId),
                    'reporter_name' => 'Mireia Serveis Almeda',
                    'reporter_phone' => '622111222',
                    'category' => 'ELECTRICAL_OFF',
                    'description' => 'Bomba de presión no arranca y caldera bloqueada por error de caudal de agua.',
                    'urgency' => 'HIGH',
                    'status' => 'RESOLVED', // En periodo de garantía
                    'created_at' => $ref->modify('-4 hours')->format('Y-m-d H:i:s'),
                    'assigned_at' => $ref->modify('-3 hours 45 minutes')->format('Y-m-d H:i:s'),
                    'started_at' => $ref->modify('-3 hours')->format('Y-m-d H:i:s'),
                    'resolved_at' => $ref->modify('-2 hours 5 minutes')->format('Y-m-d H:i:s'), // 115 min (1h 55m)
                    'closed_at' => null,
                    'diagnosis' => 'Bomba de vibración 230V gripada por calcificación en válvula antirretorno.',
                    'action' => 'Sustitución de bomba de presión y descalcificación preventiva de circuito.',
                    'tech_name' => 'Carlos Técnico Ruta Sud',
                ],
            ];

            $insertIncidentStmt = $this->pdo->prepare("
                INSERT INTO `incidents` (
                    `ticket_code`, `machine_id`, `location_id`, `assigned_technician_id`,
                    `reporter_name`, `reporter_phone`, `category`, `description`, `urgency`,
                    `status`, `assigned_at`, `started_at`, `resolved_at`, `closed_at`,
                    `resolution_diagnosis`, `resolution_action`, `created_at`, `updated_at`
                ) VALUES (
                    :ticket_code, :machine_id, :location_id, :assigned_technician_id,
                    :reporter_name, :reporter_phone, :category, :description, :urgency,
                    :status, :assigned_at, :started_at, :resolved_at, :closed_at,
                    :resolution_diagnosis, :resolution_action, :created_at, :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    `machine_id` = VALUES(`machine_id`),
                    `location_id` = VALUES(`location_id`),
                    `assigned_technician_id` = VALUES(`assigned_technician_id`),
                    `category` = VALUES(`category`),
                    `description` = VALUES(`description`),
                    `urgency` = VALUES(`urgency`),
                    `status` = VALUES(`status`),
                    `assigned_at` = VALUES(`assigned_at`),
                    `started_at` = VALUES(`started_at`),
                    `resolved_at` = VALUES(`resolved_at`),
                    `closed_at` = VALUES(`closed_at`),
                    `resolution_diagnosis` = VALUES(`resolution_diagnosis`),
                    `resolution_action` = VALUES(`resolution_action`),
                    `created_at` = VALUES(`created_at`),
                    `updated_at` = VALUES(`updated_at`),
                    `deleted_at` = NULL
            ");

            $insertAuditStmt = $this->pdo->prepare("
                INSERT INTO `audit_log` (
                    `entity_type`, `entity_id`, `action`, `user_id`, `user_role`, `user_name`,
                    `previous_state`, `new_state`, `metadata`, `created_at`
                ) VALUES (
                    :entity_type, :entity_id, :action, :user_id, :user_role, :user_name,
                    :previous_state, :new_state, :metadata, :created_at
                )
            ");

            $incidentCount = 0;
            $auditCount = 0;

            foreach ($incidents as $inc) {
                $insertIncidentStmt->execute([
                    ':ticket_code' => $inc['ticket_code'],
                    ':machine_id' => $inc['machine_id'],
                    ':location_id' => $inc['location_id'],
                    ':assigned_technician_id' => $inc['assigned_technician_id'],
                    ':reporter_name' => $inc['reporter_name'],
                    ':reporter_phone' => $inc['reporter_phone'],
                    ':category' => $inc['category'],
                    ':description' => $inc['description'],
                    ':urgency' => $inc['urgency'],
                    ':status' => $inc['status'],
                    ':assigned_at' => $inc['assigned_at'],
                    ':started_at' => $inc['started_at'],
                    ':resolved_at' => $inc['resolved_at'],
                    ':closed_at' => $inc['closed_at'],
                    ':resolution_diagnosis' => $inc['diagnosis'],
                    ':resolution_action' => $inc['action'],
                    ':created_at' => $inc['created_at'],
                    ':updated_at' => $inc['closed_at'] ?? $inc['resolved_at'] ?? $inc['created_at'],
                ]);

                // Obtener ID del ticket para auditoría
                $idStmt = $this->pdo->prepare("SELECT `id` FROM `incidents` WHERE `ticket_code` = :tc");
                $idStmt->execute([':tc' => $inc['ticket_code']]);
                $incidentId = (int)$idStmt->fetchColumn();
                $incidentCount++;

                // Comprobar si ya existen eventos para este ticket antes de duplicar
                $existStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'TICKET' AND `entity_id` = :eid");
                $existStmt->execute([':eid' => $incidentId]);
                if ((int)$existStmt->fetchColumn() === 0) {
                    // 1. Creación
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'TICKET_CREATED',
                        ':user_id' => null,
                        ':user_role' => 'SYSTEM',
                        ':user_name' => $inc['reporter_name'] . ' (Aviso Web/QR)',
                        ':previous_state' => null,
                        ':new_state' => json_encode([
                            'ticket_code' => $inc['ticket_code'],
                            'status' => 'REGISTERED',
                            'urgency' => $inc['urgency'],
                            'category' => $inc['category'],
                        ], JSON_UNESCAPED_UNICODE),
                        ':metadata' => json_encode(['source' => 'QR_SCAN', 'client_ip' => '127.0.0.1']),
                        ':created_at' => $inc['created_at'],
                    ]);
                    $auditCount++;

                    // 2. Asignación
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'TECHNICIAN_ASSIGNED',
                        ':user_id' => $saraId,
                        ':user_role' => 'COORDINATOR',
                        ':user_name' => 'Sara Coordinadora',
                        ':previous_state' => json_encode(['status' => 'REGISTERED', 'assigned_technician_id' => null]),
                        ':new_state' => json_encode(['status' => 'ASSIGNED', 'assigned_technician_id' => $inc['assigned_technician_id']]),
                        ':metadata' => json_encode(['technician_name' => $inc['tech_name']]),
                        ':created_at' => $inc['assigned_at'],
                    ]);
                    $auditCount++;

                    // 3. Inicio Intervención
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'INTERVENTION_STARTED',
                        ':user_id' => $inc['assigned_technician_id'],
                        ':user_role' => 'TECHNICIAN',
                        ':user_name' => $inc['tech_name'],
                        ':previous_state' => json_encode(['status' => 'ASSIGNED']),
                        ':new_state' => json_encode(['status' => 'IN_PROGRESS']),
                        ':metadata' => json_encode(['location' => 'In-situ']),
                        ':created_at' => $inc['started_at'],
                    ]);
                    $auditCount++;

                    // 4. Resolución
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'TICKET_RESOLVED',
                        ':user_id' => $inc['assigned_technician_id'],
                        ':user_role' => 'TECHNICIAN',
                        ':user_name' => $inc['tech_name'],
                        ':previous_state' => json_encode(['status' => 'IN_PROGRESS']),
                        ':new_state' => json_encode([
                            'status' => 'RESOLVED',
                            'resolution_diagnosis' => $inc['diagnosis'],
                            'resolution_action' => $inc['action'],
                        ], JSON_UNESCAPED_UNICODE),
                        ':metadata' => json_encode(['warranty_period_hours' => 48]),
                        ':created_at' => $inc['resolved_at'],
                    ]);
                    $auditCount++;

                    // 5. Cierre
                    if ($inc['closed_at'] !== null) {
                        $insertAuditStmt->execute([
                            ':entity_type' => 'TICKET',
                            ':entity_id' => $incidentId,
                            ':action' => 'TICKET_AUTO_CLOSED',
                            ':user_id' => null,
                            ':user_role' => 'SYSTEM_CRON',
                            ':user_name' => 'AutoCloseCron (48h Garantía)',
                            ':previous_state' => json_encode(['status' => 'RESOLVED']),
                            ':new_state' => json_encode(['status' => 'CLOSED']),
                            ':metadata' => json_encode(['warranty_cleared' => true]),
                            ':created_at' => $inc['closed_at'],
                        ]);
                        $auditCount++;
                    }
                } else {
                    // Si ya existen, sincronizar sus marcas de tiempo relativas
                    $updateAuditStmt = $this->pdo->prepare("
                        UPDATE `audit_log`
                        SET `created_at` = :created_at
                        WHERE `entity_type` = 'TICKET' AND `entity_id` = :eid AND `action` = :action
                    ");
                    $updateAuditStmt->execute([':created_at' => $inc['created_at'], ':eid' => $incidentId, ':action' => 'TICKET_CREATED']);
                    $updateAuditStmt->execute([':created_at' => $inc['assigned_at'], ':eid' => $incidentId, ':action' => 'TECHNICIAN_ASSIGNED']);
                    $updateAuditStmt->execute([':created_at' => $inc['started_at'], ':eid' => $incidentId, ':action' => 'INTERVENTION_STARTED']);
                    $updateAuditStmt->execute([':created_at' => $inc['resolved_at'], ':eid' => $incidentId, ':action' => 'TICKET_RESOLVED']);
                    if ($inc['closed_at'] !== null) {
                        $updateAuditStmt->execute([':created_at' => $inc['closed_at'], ':eid' => $incidentId, ':action' => 'TICKET_AUTO_CLOSED']);
                    }
                    $auditCount += 5;
                }
            }

            // Eventos en máquinas y sedes
            $existMachAudit = (int)$this->pdo->query("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'MACHINE' AND `action` = 'QR_LABEL_GENERATED'")->fetchColumn();
            if ($existMachAudit === 0) {
                $insertAuditStmt->execute([
                    ':entity_type' => 'MACHINE',
                    ':entity_id' => $vend0101['id'],
                    ':action' => 'QR_LABEL_GENERATED',
                    ':user_id' => $saraId,
                    ':user_role' => 'COORDINATOR',
                    ':user_name' => 'Sara Coordinadora',
                    ':previous_state' => null,
                    ':new_state' => json_encode(['qr_code' => 'VENDGUARD-0101-SAFE', 'type' => 'PERISHABLE_FOOD']),
                    ':metadata' => json_encode(['format' => 'SVG_VECTOR', 'phone_override' => null]),
                    ':created_at' => $ref->modify('-20 days')->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
                ]);
                $auditCount++;
            }

            $existLocAudit = (int)$this->pdo->query("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'LOCATION' AND `action` = 'LOCATION_INSPECTED'")->fetchColumn();
            if ($existLocAudit === 0) {
                $insertAuditStmt->execute([
                    ':entity_type' => 'LOCATION',
                    ':entity_id' => $vend0101['location_id'],
                    ':action' => 'LOCATION_INSPECTED',
                    ':user_id' => $saraId,
                    ':user_role' => 'COORDINATOR',
                    ':user_name' => 'Sara Coordinadora',
                    ':previous_state' => json_encode(['status' => 'OPERATIONAL']),
                    ':new_state' => json_encode(['status' => 'VERIFIED_AUDIT']),
                    ':metadata' => json_encode(['inspector' => 'Sanidad Hospitalaria']),
                    ':created_at' => $ref->modify('-15 days')->setTime(16, 30, 0)->format('Y-m-d H:i:s'),
                ]);
                $auditCount++;
            }

            // Escenario demo del indicador de reintervenciones por garantía (Art. V.6):
            // INC-DEMO-1001 (Marc) sufrió una reapertura válida dentro de las 48 h de su
            // resolución y fue resuelto de nuevo antes del cierre final. El expediente
            // termina CLOSED; el histórico inmutable muestra la reincidencia para el
            // desglose por técnico de Métricas (EARS 2.3.1). Idempotente por terna.
            $reopenIncidentId = (int)$this->pdo->query(
                "SELECT `id` FROM `incidents` WHERE `ticket_code` = 'INC-DEMO-1001'"
            )->fetchColumn();
            if ($reopenIncidentId > 0) {
                $reopenCreatedAt = $ref->modify('-4 days')->setTime(15, 0, 0)->format('Y-m-d H:i:s'); // 40 min tras la resolución (14:20)
                $reresolveAt = $ref->modify('-4 days')->setTime(15, 45, 0)->format('Y-m-d H:i:s');

                // Evento de resolución original (el que reopen() encontrará como técnico
                // resolutor para la atribución de la reincidencia).
                $insertOriginalResolve = $this->pdo->prepare("
                    INSERT INTO `incident_history` (
                        `incident_id`, `user_id`, `from_status`, `to_status`, `action_note`, `created_at`
                    ) SELECT :origres_incident_id, :origres_user_id, 'IN_PROGRESS', 'RESOLVED', :origres_note, :origres_created_at
                      WHERE NOT EXISTS (
                          SELECT 1 FROM `incident_history`
                          WHERE `incident_id` = :origres_lookup_id
                            AND `to_status` = 'RESOLVED'
                            AND `created_at` = :origres_lookup_at
                      )
                ");
                $insertOriginalResolve->execute([
                    ':origres_incident_id' => $reopenIncidentId,
                    ':origres_user_id' => ($marcId ?? $jordiId),
                    ':origres_note' => 'Avería resuelta con éxito por el técnico de campo. Diagnóstico: Antena adhesiva 4G suelta dentro del chasis metálico. | Solución: Reposicionamiento exterior de la antena magnética.',
                    ':origres_created_at' => $ref->modify('-4 days')->setTime(14, 20, 0)->format('Y-m-d H:i:s'),
                    ':origres_lookup_id' => $reopenIncidentId,
                    ':origres_lookup_at' => $ref->modify('-4 days')->setTime(14, 20, 0)->format('Y-m-d H:i:s'),
                ]);

                $insertReopenHistory = $this->pdo->prepare("
                    INSERT INTO `incident_history` (
                        `incident_id`, `user_id`, `from_status`, `to_status`, `action_note`, `created_at`
                    ) SELECT :reopen_incident_id, NULL, 'RESOLVED', 'REOPENED', :reopen_note, :reopen_created_at
                      WHERE NOT EXISTS (
                          SELECT 1 FROM `incident_history`
                          WHERE `incident_id` = :reopen_lookup_id
                            AND `to_status` = 'REOPENED'
                            AND `created_at` = :reopen_lookup_at
                      )
                ");
                $insertReopenHistory->execute([
                    ':reopen_incident_id' => $reopenIncidentId,
                    ':reopen_note' => 'Reapertura solicitada por la sede (1ª reincidencia). Motivo: El datáfono vuelve a denegar cobros tras 30 minutos de uso continuo.',
                    ':reopen_created_at' => $reopenCreatedAt,
                    ':reopen_lookup_id' => $reopenIncidentId,
                    ':reopen_lookup_at' => $reopenCreatedAt,
                ]);

                $insertReresolveHistory = $this->pdo->prepare("
                    INSERT INTO `incident_history` (
                        `incident_id`, `user_id`, `from_status`, `to_status`, `action_note`, `created_at`
                    ) SELECT :reresolve_incident_id, :reresolve_user_id, 'REOPENED', 'RESOLVED', :reresolve_note, :reresolve_created_at
                      WHERE NOT EXISTS (
                          SELECT 1 FROM `incident_history`
                          WHERE `incident_id` = :reresolve_lookup_id
                            AND `to_status` = 'RESOLVED'
                            AND `created_at` = :reresolve_lookup_at
                      )
                ");
                $insertReresolveHistory->execute([
                    ':reresolve_incident_id' => $reopenIncidentId,
                    ':reresolve_user_id' => ($marcId ?? $jordiId),
                    ':reresolve_note' => 'Reintervención por garantía: antena 4G reasentada con fijación antivibración y test de cobertura definitivo.',
                    ':reresolve_created_at' => $reresolveAt,
                    ':reresolve_lookup_id' => $reopenIncidentId,
                    ':reresolve_lookup_at' => $reresolveAt,
                ]);
            }

            $this->pdo->commit();

            return [
                'incidents' => $incidentCount,
                'audit_events' => $auditCount,
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Error sembrando datos demo de métricas: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private function ensureUsers(): void
    {
        $hash = password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 10]);

        $users = [
            ['name' => 'Sara Coordinadora', 'email' => 'coordinacion@vendguard.internal', 'role' => 'COORDINATOR', 'phone' => '677000111'],
            ['name' => 'Jordi Técnico Ruta BCN', 'email' => 'jordi.ruta@vendguard.internal', 'role' => 'TECHNICIAN', 'phone' => '677222333'],
            ['name' => 'Marta Técnica Ruta BCN', 'email' => 'marta.ruta@vendguard.internal', 'role' => 'TECHNICIAN', 'phone' => '677444555'],
        ];

        $sql = "INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `phone`, `is_active`)
                VALUES (:name, :email, :password_hash, :role, :phone, 1)
                ON DUPLICATE KEY UPDATE
                    `name` = VALUES(`name`),
                    `password_hash` = VALUES(`password_hash`),
                    `role` = VALUES(`role`),
                    `phone` = VALUES(`phone`),
                    `deleted_at` = NULL";

        $stmt = $this->pdo->prepare($sql);
        foreach ($users as $u) {
            $stmt->execute([
                ':name' => $u['name'],
                ':email' => $u['email'],
                ':password_hash' => $hash,
                ':role' => $u['role'],
                ':phone' => $u['phone'],
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function getUserMap(): array
    {
        $stmt = $this->pdo->query("SELECT `email`, `id` FROM `users` WHERE `deleted_at` IS NULL");
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['email']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function getLocationMap(): array
    {
        $stmt = $this->pdo->query("SELECT `site_code`, `id` FROM `locations` WHERE `deleted_at` IS NULL");
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['site_code']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * @return array<string, array{id: int, location_id: int, machine_type: string}>
     */
    private function getMachineMap(): array
    {
        $stmt = $this->pdo->query("SELECT `code`, `id`, `location_id`, `machine_type` FROM `machines` WHERE `deleted_at` IS NULL");
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['code']] = [
                'id' => (int)$row['id'],
                'location_id' => (int)$row['location_id'],
                'machine_type' => (string)$row['machine_type'],
            ];
        }
        return $map;
    }
}
