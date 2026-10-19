<?php

declare(strict_types=1);

/**
 * UniqueViolationRaceTranslationTest (G6 · detección unificada de unicidad)
 *
 * Certifica, con **carreras reales entre dos procesos y dos conexiones PDO**, que las
 * cuatro superficies de alta con índice único traducen la colisión a su error de
 * negocio (409) y nunca a un 500 de infraestructura:
 *
 *   1. **Usuarios internos** (`uq_users_email`): alta simultánea del mismo correo.
 *   2. **Sedes** (`uq_locations_site_code` / `uq_locations_site_code_key`): alta
 *      simultánea del mismo código de centro.
 *   3. **Máquinas** (`uq_machines_code`): alta simultánea del mismo código de parque.
 *   4. **Catálogo de repuestos** (`uq_spare_parts_part_code`): alta simultánea del
 *      mismo código de pieza.
 *
 * Cada caso repite la carrera de la forma en que la vive el usuario: un proceso
 * titular inserta la fila y la retiene **sin confirmar**; la operación de negocio de
 * esta suite pasa su comprobación previa —no ve la fila ajena— y es el índice único
 * quien la delata al confirmar el titular. La espera medida prueba que la segunda
 * escritura esperó de verdad en lugar de decidir sobre una foto vieja.
 *
 * Además, cada caso reintenta la operación con la fila ya confirmada: la comprobación
 * previa debe responder **el mismo error de negocio**, de modo que la carrera y el
 * camino ordinario no puedan divergir.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AdminLocationService;
use VendGuard\Application\Service\AdminMachineService;
use VendGuard\Application\Service\AdminUserService;
use VendGuard\Application\Service\SparePartCatalogService;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\SparePartCodeExistsException;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · G6 · Carreras reales de unicidad y traducción a 409\n";
echo " Dos procesos · dos conexiones PDO · cuatro índices únicos\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::registerShutdownRestitution();
(new SeedRunner($pdo))->seedAll();

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    $failures++;
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
};

$dialect = SqlDialectAdapter::forConnection($pdo);

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$sparePartRepo = new PdoSparePartRepository($pdo);

$userService = new AdminUserService($userRepo);
$locationService = new AdminLocationService($locationRepo);
$machineService = new AdminMachineService($machineRepo, $locationRepo);
$sparePartService = new SparePartCatalogService($sparePartRepo);

$seedLocation = $locationRepo->findBySiteCode('SEDE-BCN-01');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$assert('0.1 Semilla disponible: sede y coordinación', $seedLocation !== null && $coordinator !== null);
if ($seedLocation === null || $coordinator === null) {
    echo "\nNo se puede certificar sin la semilla del escenario.\n";
    exit(1);
}

$actor = ['id' => $coordinator->getId(), 'role' => 'COORDINATOR', 'name' => $coordinator->getName()];
$holderScript = __DIR__ . '/../Support/UniqueRaceHolder.php';

/**
 * Lanza la carrera: el proceso titular inserta la sentencia y la retiene sin
 * confirmar; la operación de negocio se ejecuta contra esa fila invisible.
 *
 * @param callable():void $operation Operación que debe chocar con el índice único.
 * @return array{error: ?Throwable, elapsed: float, exit: int, log: string, ready: bool}
 */
$race = function (string $insertSql, callable $operation) use ($pdo, $holderScript): array {
    $sqlFile = tempnam(sys_get_temp_dir(), 'vg_g6_sql_');
    $readyFile = tempnam(sys_get_temp_dir(), 'vg_g6_ready_');
    $logFile = tempnam(sys_get_temp_dir(), 'vg_g6_log_');
    @unlink($readyFile);
    file_put_contents($sqlFile, $insertSql);

    $process = proc_open(
        [PHP_BINARY, $holderScript, $sqlFile, $readyFile, '1500'],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($sqlFile);
        @unlink($readyFile);
        @unlink($logFile);
        throw new RuntimeException('No se pudo arrancar el proceso titular de la carrera.');
    }
    fclose($pipes[0]);

    $deadline = microtime(true) + 10.0;
    while (!is_file($readyFile) && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $ready = is_file($readyFile);

    $startedAt = microtime(true);
    $error = null;
    if ($ready) {
        try {
            $operation();
        } catch (Throwable $exception) {
            $error = $exception;
        }
    }
    $elapsed = microtime(true) - $startedAt;

    $exit = proc_close($process);
    $log = trim((string)file_get_contents($logFile));

    @unlink($sqlFile);
    @unlink($readyFile);
    @unlink($logFile);

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return ['error' => $error, 'elapsed' => $elapsed, 'exit' => $exit, 'log' => $log, 'ready' => $ready];
};

/**
 * Comprueba el desenlace de una carrera: esperó, terminó en verde el titular y la
 * operación perdedora recibió el error de negocio indicado, jamás un 500.
 */
$assertRaceOutcome = function (
    string $label,
    array $raceResult,
    string $expectedClass,
    string $expectedCode,
    int $expectedHttpStatus
) use ($assert): void {
    $assert(
        "{$label} · el titular retuvo la fila sin confirmar y confirmó en verde",
        $raceResult['ready'] === true && $raceResult['exit'] === 0 && str_contains($raceResult['log'], 'COMMITTED'),
        'ready=' . var_export($raceResult['ready'], true) . ' · exit=' . $raceResult['exit'] . ' · log=' . $raceResult['log']
    );

    $error = $raceResult['error'];
    $assert(
        "{$label} · la operación perdedora esperó al índice único",
        $raceResult['elapsed'] >= 0.30,
        sprintf('espera medida: %.3f s', $raceResult['elapsed'])
    );
    $assert(
        "{$label} · la colisión se traduce al error de negocio {$expectedCode} (409), nunca a un 500",
        $error instanceof $expectedClass
            && method_exists($error, 'getErrorCode')
            && $error->getErrorCode() === $expectedCode
            && method_exists($error, 'getHttpStatusCode')
            && $error->getHttpStatusCode() === $expectedHttpStatus
            && !$error instanceof PDOException && !$error instanceof RuntimeException,
        $error !== null
            ? get_class($error) . ': ' . $error->getMessage()
            : 'la operación perdedora no lanzó ninguna excepción'
    );
};

$expectBusinessFailure = function (string $label, callable $operation, string $expectedClass, string $expectedCode) use ($assert): void {
    $error = null;
    try {
        $operation();
    } catch (Throwable $exception) {
        $error = $exception;
    }

    $assert(
        "{$label} · la comprobación previa responde el mismo error de negocio ({$expectedCode})",
        $error instanceof $expectedClass
            && method_exists($error, 'getErrorCode')
            && $error->getErrorCode() === $expectedCode,
        $error !== null ? get_class($error) . ': ' . $error->getMessage() : 'no se lanzó ninguna excepción'
    );
};

// ---------------------------------------------------------------------
// CASO 1 · Usuarios internos · uq_users_email
// ---------------------------------------------------------------------
echo "\n--- Caso 1: alta simultánea del mismo correo interno ---\n";

$raceEmail = 'race.user@vendguard.test';
$emailKey = $dialect->identityKey($raceEmail);
$userPayload = [
    'name' => 'Race User',
    'email' => $raceEmail,
    'role' => 'COORDINATOR',
    'password' => 'ClaveSegura123',
];

$userRace = $race(
    "INSERT INTO users (name, email, email_key, password_hash, role) VALUES ('Race User', '{$raceEmail}', '{$emailKey}', 'hash-titular', 'COORDINATOR')",
    static function () use ($userService, $userPayload, $actor): void {
        $userService->createUser($userPayload, $actor);
    }
);
$assertRaceOutcome('1.1 Usuarios', $userRace, InactiveRecordCollisionException::class, 'USER_ALREADY_EXISTS_ACTIVE', 409);
$expectBusinessFailure(
    '1.2 Usuarios',
    static function () use ($userService, $userPayload, $actor): void {
        $userService->createUser($userPayload, $actor);
    },
    InactiveRecordCollisionException::class,
    'USER_ALREADY_EXISTS_ACTIVE'
);

// ---------------------------------------------------------------------
// CASO 2 · Sedes · uq_locations_site_code(_key)
// ---------------------------------------------------------------------
echo "\n--- Caso 2: alta simultánea del mismo código de sede ---\n";

$raceSiteCode = 'SEDE-RACE-01';
$siteCodeKey = $dialect->identityKey($raceSiteCode);
$locationPayload = [
    'site_code' => $raceSiteCode,
    'name' => 'Sede de Carrera',
    'address' => 'Calle de la Carrera 1',
];

$locationRace = $race(
    "INSERT INTO locations (site_code, site_code_key, name, address) VALUES ('{$raceSiteCode}', '{$siteCodeKey}', 'Sede de Carrera', 'Calle de la Carrera 1')",
    static function () use ($locationService, $locationPayload, $actor): void {
        $locationService->createLocation($locationPayload, $actor);
    }
);
$assertRaceOutcome('2.1 Sedes', $locationRace, InactiveRecordCollisionException::class, 'LOCATION_ALREADY_EXISTS_ACTIVE', 409);
$expectBusinessFailure(
    '2.2 Sedes',
    static function () use ($locationService, $locationPayload, $actor): void {
        $locationService->createLocation($locationPayload, $actor);
    },
    InactiveRecordCollisionException::class,
    'LOCATION_ALREADY_EXISTS_ACTIVE'
);

// ---------------------------------------------------------------------
// CASO 3 · Máquinas · uq_machines_code
// ---------------------------------------------------------------------
echo "\n--- Caso 3: alta simultánea del mismo código de máquina ---\n";

$raceMachineCode = 'VEND-RACE-01';
$machinePayload = [
    'code' => $raceMachineCode,
    'model' => 'Modelo de Carrera',
    'machine_type' => 'SNACKS',
    'location_id' => $seedLocation->getId(),
    'floor_wing' => 'Planta 0 · Carrera',
];
$seedLocationId = (int)$seedLocation->getId();

$machineRace = $race(
    "INSERT INTO machines (location_id, code, model, machine_type, floor_wing) VALUES ({$seedLocationId}, '{$raceMachineCode}', 'Modelo de Carrera', 'SNACKS', 'Planta 0 · Carrera')",
    static function () use ($machineService, $machinePayload, $actor): void {
        $machineService->createMachine($machinePayload, $actor);
    }
);
$assertRaceOutcome('3.1 Máquinas', $machineRace, InactiveRecordCollisionException::class, 'MACHINE_ALREADY_EXISTS_ACTIVE', 409);
$expectBusinessFailure(
    '3.2 Máquinas',
    static function () use ($machineService, $machinePayload, $actor): void {
        $machineService->createMachine($machinePayload, $actor);
    },
    InactiveRecordCollisionException::class,
    'MACHINE_ALREADY_EXISTS_ACTIVE'
);

// ---------------------------------------------------------------------
// CASO 4 · Catálogo de repuestos · uq_spare_parts_part_code
// ---------------------------------------------------------------------
echo "\n--- Caso 4: alta simultánea del mismo código de repuesto ---\n";

$racePartCode = 'SP-RACE-01';
$sparePartPayload = [
    'part_code' => $racePartCode,
    'name' => 'Repuesto de Carrera',
    'category' => 'MECHANICAL',
    'manufacturer' => 'Fabricante de Carrera',
    'reference_cost' => 12.5,
    'compatible_models' => ['Modelo de Carrera'],
];

$sparePartRace = $race(
    "INSERT INTO spare_parts (part_code, name, manufacturer) VALUES ('{$racePartCode}', 'Repuesto de Carrera', 'Fabricante de Carrera')",
    static function () use ($sparePartService, $sparePartPayload, $actor): void {
        $sparePartService->createSparePart($sparePartPayload, $actor);
    }
);
$assertRaceOutcome('4.1 Repuestos', $sparePartRace, SparePartCodeExistsException::class, 'SPARE_PART_CODE_EXISTS', 409);
$expectBusinessFailure(
    '4.2 Repuestos',
    static function () use ($sparePartService, $sparePartPayload, $actor): void {
        $sparePartService->createSparePart($sparePartPayload, $actor);
    },
    SparePartCodeExistsException::class,
    'SPARE_PART_CODE_EXISTS'
);

// ---------------------------------------------------------------------
// CIERRE · las filas creadas por las carreras no sobreviven a la suite
// ---------------------------------------------------------------------
// Los cuatro titulares confirmaron de verdad, así que sus filas existen: se retiran
// por su identidad concreta y no con una purga general, que vaciaría datos de otras
// suites. Las maestras están protegidas por `prevent_hard_delete()`, de modo que el
// borrado se abre con la misma ventana que usa el limpiador del arnés (Art. III.1).
TestDataCleaner::withPurgeWindow($pdo, static function () use (
    $pdo,
    $raceEmail,
    $raceSiteCode,
    $raceMachineCode,
    $racePartCode
): void {
    $pdo->prepare('DELETE FROM spare_part_compatibilities WHERE spare_part_id IN (SELECT id FROM spare_parts WHERE part_code = :code)')
        ->execute([':code' => $racePartCode]);
    $pdo->prepare('DELETE FROM spare_parts WHERE part_code = :code')->execute([':code' => $racePartCode]);
    $pdo->prepare('DELETE FROM machines WHERE code = :code')->execute([':code' => $raceMachineCode]);
    $pdo->prepare('DELETE FROM locations WHERE site_code = :code')->execute([':code' => $raceSiteCode]);
    $pdo->prepare('DELETE FROM users WHERE email = :email')->execute([':email' => $raceEmail]);
});

echo "\n======================================================================\n";
echo " Total Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. G6 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
