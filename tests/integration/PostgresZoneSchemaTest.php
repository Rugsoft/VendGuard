<?php

declare(strict_types=1);

/**
 * PostgresZoneSchemaTest · T-ZONE-02
 *
 * Certifica **en el motor real** el esquema de zonificación operativa del Módulo 17: las tres
 * tablas nuevas, las columnas territoriales de `locations` y la ampliación del enumerado de
 * auditoría. No comprueba el servicio de aplicación —eso llega con T-ZONE-05 y T-ZONE-07— sino
 * las garantías que la base de datos debe imponer por sí sola, porque son las que ningún camino
 * de código puede saltarse:
 *
 *   1. **Un código postal vivo, una zona (RF-01.2 / QA 7 / QA 13).** El índice único parcial
 *      `uq_zone_postal_codes_live` rechaza con `SQLSTATE '23505'` el mismo CP en dos zonas vivas:
 *      la unicidad es global y no depende de que el servicio la recuerde. La liberación es
 *      lógica (`released_at`, UPDATE), de modo que un CP liberado **sí** puede reasignarse a otra
 *      zona y la fila histórica se conserva; el `DELETE` sigue rechazándose con `45000`.
 *   2. **Titularidad única viva (RF-02.1).** El índice parcial `uq_technician_primary_zone`
 *      (`WHERE is_primary = TRUE AND removed_at IS NULL`) rechaza el segundo titularato del mismo
 *      técnico. Es un índice y no una consulta de aplicación: dos peticiones concurrentes no
 *      pueden colar dos titulares.
 *   3. **Suplencias múltiples (RF-02.1).** El mismo técnico puede ser suplente en varias zonas
 *      sin límite: lo que es único es la titularidad, no la pertenencia al cuadrante.
 *   4. **Baja lógica y reingreso (RF-02.2 / QA 10 / Art. III.1).** Marcar `removed_at` extingue
 *      la vinculación sin borrarla, y el reingreso estrena una fila nueva. Se certifica que la
 *      fila histórica sigue ahí y que la unicidad parcial permite el reingreso.
 *   5. **Formatos (RF-01.1 / RF-01.2 / QA 7).** `color_hex` exige `#RRGGBB` y el CP exige cinco
 *      dígitos, tanto en el catálogo territorial como en `locations.postal_code`.
 *   6. **El CP de sede admite NULL pero no basura (QA 7).** Una sede histórica sin CP se acepta
 *      (la migración no inventa datos); cuatro dígitos o letras se rechazan.
 *   7. **La zona solo se desactiva (RF-01.3 / Art. III.1).** `DELETE` sobre el catálogo lanza
 *      `SQLSTATE '45000'` con la marca constitucional; abierta la ventana de purga del arnés, la
 *      clave foránea `RESTRICT` de códigos postales y cuadrante sigue impidiendo llevarse una zona
 *      en uso. La desactivación lógica deja sus sedes en *«Sin Zona Asignada»* (`zone_id = NULL`).
 *   8. **Auditoría territorial (QA 11).** El enumerado `audit_entity_type` contiene
 *      `OPERATIONAL_ZONE` y `TECHNICIAN_ZONE`, y `audit_log` admite escribir ambos: un valor
 *      declarado que el motor no deje insertar no serviría de nada.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO pertenece a la
 *   batería hermética (RNF-04): se declara con su marca de categoría, se ejecuta en su canal
 *   propio y su informe va aparte. Sin `PG_TEST_DSN` la suite **no se omite en silencio**: lo dice
 *   y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al terminar, incluso
 * si una aserción falla. No deja una sola tabla en la base de certificación.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresZoneSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite: no comparte escenario con las suites de correspondencia. */
const ZONE_TEST_SCHEMA = 't_zone02_territorial';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-02 · Esquema de zonificación operativa (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures: solo los datos mínimos que la regla necesita para poder dispararse
// ─────────────────────────────────────────────────────────────────────────────

/** Alta de zona y devolución de su identificador. */
$createZone = static function (PDO $pdo, string $code, string $name, string $color = '#2560ff'): int {
    $stmt = $pdo->prepare(
        'INSERT INTO operational_zones (code, name, color_hex) VALUES (:code, :name, :color) RETURNING id'
    );
    $stmt->execute([':code' => $code, ':name' => $name, ':color' => $color]);

    return (int)$stmt->fetchColumn();
};

/** Alta de técnico: rol `TECHNICIAN` y clave canónica, que es lo que el cuadrante filtra. */
$createTechnician = static function (PDO $pdo, string $suffix, string $name): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code)
         VALUES (:name, :email, :email_key, :hash, \'TECHNICIAN\', :operator)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => $name,
        ':email' => 'tecnico.zone.' . $suffix . '@vendguard.internal',
        ':email_key' => 'tecnico.zone.' . $suffix . '@vendguard.internal',
        ':hash' => password_hash('zona-' . $suffix, PASSWORD_BCRYPT),
        ':operator' => 'ZONE-' . strtoupper($suffix),
    ]);

    return (int)$stmt->fetchColumn();
};

/** Alta de sede con su CP y, opcionalmente, su zona. */
$createLocation = static function (PDO $pdo, string $suffix, ?string $postalCode, ?int $zoneId): int {
    $stmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, postal_code, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :postal_code, :zone_id)
         RETURNING id'
    );
    $stmt->execute([
        ':site_code' => 'SEDE-ZONE-' . strtoupper($suffix),
        ':site_code_key' => 'sede-zone-' . strtolower($suffix),
        ':name' => 'Sede de certificación territorial ' . strtoupper($suffix),
        ':address' => 'Calle de la Certificación 1, Barcelona',
        ':postal_code' => $postalCode,
        ':zone_id' => $zoneId,
    ]);

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;
$zoneA = 0;
$zoneB = 0;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migración 007 en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, ZONE_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la migración 007 aplican sin errores y el esquema converge',
        in_array('007_operational_zones.sql', $schemaReport['migrations'], true)
            && in_array('008_zone_postal_code_release.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    $zoneA = $createZone($pgPdo, 'ZONA-CERT-A', 'Zona de Certificación Alfa');
    $zoneB = $createZone($pgPdo, 'ZONA-CERT-B', 'Zona de Certificación Beta', '#10b981');
    $zoneC = $createZone($pgPdo, 'ZONA-CERT-C', 'Zona de Certificación Gamma', '#F59E0B');

    $technicianOne = $createTechnician($pgPdo, 'uno', 'Técnico de Certificación Uno');
    $technicianTwo = $createTechnician($pgPdo, 'dos', 'Técnico de Certificación Dos');

    $locationA = $createLocation($pgPdo, 'a', '08001', $zoneA);
    $locationB = $createLocation($pgPdo, 'b', '08002', $zoneB);

    $assert(
        'El escenario siembra zonas, técnicos y sedes con CP',
        $zoneA > 0 && $zoneB > 0 && $zoneC > 0 && $technicianOne > 0 && $technicianTwo > 0 && $locationA > 0 && $locationB > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Un código postal pertenece a una sola zona (RF-01.2 / QA 7)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Unicidad global del código postal ---\n";

    $linkPostalCode = static function (PDO $pdo, string $postalCode, int $zoneId): void {
        $stmt = $pdo->prepare('INSERT INTO operational_zone_postal_codes (postal_code, zone_id) VALUES (:cp, :zone)');
        $stmt->execute([':cp' => $postalCode, ':zone' => $zoneId]);
    };

    $linkPostalCode($pgPdo, '08001', $zoneA);

    $duplicatePostalCode = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $zoneB, $linkPostalCode): void {
        $linkPostalCode($pgPdo, '08001', $zoneB);
    });

    $assert(
        'El mismo CP vivo no puede pertenecer a dos zonas: el índice parcial lo rechaza con 23505',
        $duplicatePostalCode !== null
            && $duplicatePostalCode['state'] === '23505'
            && str_contains($duplicatePostalCode['message'], 'uq_zone_postal_codes_live'),
        $duplicatePostalCode === null ? 'el CP duplicado se admitió' : 'estado=' . $duplicatePostalCode['state']
    );

    $linkPostalCode($pgPdo, '08002', $zoneB);
    $assert(
        'Control positivo: un CP distinto en otra zona se admite',
        (int)$pgPdo->query('SELECT COUNT(*) FROM operational_zone_postal_codes')->fetchColumn() === 2
    );

    // ── Liberación lógica (QA 13): el CP se marca, no se borra ──
    $linkPostalCode($pgPdo, '08007', $zoneA);
    $pgPdo->exec("UPDATE operational_zone_postal_codes SET released_at = CURRENT_TIMESTAMP WHERE postal_code = '08007'");

    $releasedRow = $pgPdo->query(
        "SELECT zone_id, released_at FROM operational_zone_postal_codes WHERE postal_code = '08007'"
    )->fetch();
    $assert(
        'Liberar un CP es un UPDATE: la fila histórica se conserva marcada con released_at',
        $releasedRow !== false && $releasedRow['released_at'] !== null && (int)$releasedRow['zone_id'] === $zoneA,
        $releasedRow === false ? 'la fila desapareció' : 'released_at=' . json_encode($releasedRow['released_at'])
    );

    $linkPostalCode($pgPdo, '08007', $zoneB);
    $liveRows = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM operational_zone_postal_codes WHERE postal_code = '08007' AND released_at IS NULL"
    )->fetchColumn();
    $historicalRows = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM operational_zone_postal_codes WHERE postal_code = '08007'"
    )->fetchColumn();
    $assert(
        'Un CP liberado puede asignarse a otra zona y convive con su fila histórica',
        $liveRows === 1 && $historicalRows === 2,
        "vivas={$liveRows} · históricas={$historicalRows}"
    );

    $deletePostalCode = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec("DELETE FROM operational_zone_postal_codes WHERE postal_code = '08007'");
    });
    $assert(
        'El disparador anti-borrado sigue intacto: liberar no abre la puerta al DELETE',
        $deletePostalCode !== null && $deletePostalCode['state'] === '45000',
        $deletePostalCode === null ? 'el DELETE se admitió' : 'estado=' . $deletePostalCode['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Titularidad única y suplencias múltiples (RF-02.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cuadrante: un titular vivo por técnico, suplencias sin límite ---\n";

    $assignTechnician = static function (PDO $pdo, int $technicianId, int $zoneId, bool $isPrimary): void {
        $stmt = $pdo->prepare(
            'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
             VALUES (:technician, :zone, :is_primary)'
        );
        $stmt->execute([
            ':technician' => $technicianId,
            ':zone' => $zoneId,
            ':is_primary' => $isPrimary ? 'TRUE' : 'FALSE',
        ]);
    };

    $assignTechnician($pgPdo, $technicianOne, $zoneA, true);

    $secondPrimary = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianOne, $zoneB, $assignTechnician): void {
        $assignTechnician($pgPdo, $technicianOne, $zoneB, true);
    });

    $assert(
        'Un técnico no puede ser titular de dos zonas vivas: uq_technician_primary_zone lo rechaza con 23505',
        $secondPrimary !== null
            && $secondPrimary['state'] === '23505'
            && str_contains($secondPrimary['message'], 'uq_technician_primary_zone'),
        $secondPrimary === null ? 'la segunda titularidad se admitió' : 'estado=' . $secondPrimary['state']
    );

    $assignTechnician($pgPdo, $technicianTwo, $zoneB, true);
    $assert(
        'Control positivo: otro técnico puede ser titular de otra zona',
        (int)$pgPdo->query(
            'SELECT COUNT(*) FROM technician_zone_assignments WHERE is_primary = TRUE AND removed_at IS NULL'
        )->fetchColumn() === 2
    );

    $assignTechnician($pgPdo, $technicianOne, $zoneB, false);
    $assignTechnician($pgPdo, $technicianOne, $zoneC, false);

    $backupCount = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM technician_zone_assignments
          WHERE technician_id = ' . $technicianOne . ' AND is_primary = FALSE AND removed_at IS NULL'
    )->fetchColumn();

    $assert(
        'El mismo técnico acumula varias suplencias vivas (RF-02.1)',
        $backupCount === 2,
        'suplencias vivas: ' . $backupCount
    );

    $primaryCount = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM technician_zone_assignments
          WHERE technician_id = ' . $technicianOne . ' AND is_primary = TRUE AND removed_at IS NULL'
    )->fetchColumn();

    $assert(
        'Las suplencias no abren una segunda titularidad al mismo técnico',
        $primaryCount === 1,
        'titularidades vivas: ' . $primaryCount
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Baja lógica del cuadrante y reingreso (RF-02.2 / QA 10 / Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Baja lógica y reingreso del cuadrante ---\n";

    $removeFromZone = static function (PDO $pdo, int $technicianId, int $zoneId): void {
        $stmt = $pdo->prepare(
            'UPDATE technician_zone_assignments
                SET removed_at = CURRENT_TIMESTAMP
              WHERE technician_id = :technician AND zone_id = :zone AND removed_at IS NULL'
        );
        $stmt->execute([':technician' => $technicianId, ':zone' => $zoneId]);
    };

    $removeFromZone($pgPdo, $technicianOne, $zoneB);
    $assignTechnician($pgPdo, $technicianOne, $zoneB, false);

    $pairRows = $pgPdo->query(
        'SELECT is_primary, removed_at FROM technician_zone_assignments
          WHERE technician_id = ' . $technicianOne . ' AND zone_id = ' . $zoneB . '
          ORDER BY id'
    )->fetchAll();

    $assert(
        'Tras la baja lógica el mismo técnico reingresa en la misma zona con una fila nueva',
        count($pairRows) === 2
            && $pairRows[0]['removed_at'] !== null
            && $pairRows[1]['removed_at'] === null,
        'filas del par: ' . count($pairRows)
    );

    $removeFromZone($pgPdo, $technicianOne, $zoneA);
    $assignTechnician($pgPdo, $technicianOne, $zoneA, true);
    $assert(
        'La titularidad extinguida no bloquea una titularidad nueva y auditada',
        (int)$pgPdo->query(
            'SELECT COUNT(*) FROM technician_zone_assignments
              WHERE technician_id = ' . $technicianOne . ' AND zone_id = ' . $zoneA . '
                AND is_primary = TRUE AND removed_at IS NULL'
        )->fetchColumn() === 1
    );

    $historicalRows = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM technician_zone_assignments WHERE removed_at IS NOT NULL'
    )->fetchColumn();
    $assert(
        'Las vinculaciones extinguidas se conservan (Art. III.1)',
        $historicalRows === 2,
        'filas históricas: ' . $historicalRows
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Formatos: color de zona y código postal (RF-01.1, RF-01.2, QA 7)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Validación de formato en el catálogo territorial ---\n";

    $invalidColor = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $createZone): void {
        $createZone($pgPdo, 'ZONA-CERT-COLOR-BAD', 'Zona con color corto', '#12345');
    });
    $assert(
        'Un color de zona sin seis dígitos se rechaza (chk_operational_zones_color_format)',
        $invalidColor !== null
            && $invalidColor['state'] === '23514'
            && str_contains($invalidColor['message'], 'chk_operational_zones_color_format'),
        $invalidColor === null ? 'el color inválido se admitió' : 'estado=' . $invalidColor['state']
    );

    $invalidColorName = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $createZone): void {
        $createZone($pgPdo, 'ZONA-CERT-COLOR-NAME', 'Zona con color con nombre', 'azul123');
    });
    $assert(
        'Un color con nombre en vez de hexadecimal se rechaza',
        $invalidColorName !== null && $invalidColorName['state'] === '23514',
        $invalidColorName === null ? 'el color «azul123» se admitió' : 'estado=' . $invalidColorName['state']
    );

    $uppercaseColor = $createZone($pgPdo, 'ZONA-CERT-COLOR-OK', 'Zona con color en mayúsculas', '#10B981');
    $assert(
        'Control positivo: un color hexadecimal válido se admite, sin importar el caso',
        $uppercaseColor > 0
    );

    $shortPostalCode = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $zoneA, $linkPostalCode): void {
        $linkPostalCode($pgPdo, '0800', $zoneA);
    });
    $assert(
        'Un CP de cuatro dígitos se rechaza en el catálogo (chk_postal_code_format)',
        $shortPostalCode !== null
            && $shortPostalCode['state'] === '23514'
            && str_contains($shortPostalCode['message'], 'chk_postal_code_format'),
        $shortPostalCode === null ? 'el CP corto se admitió' : 'estado=' . $shortPostalCode['state']
    );

    $letterPostalCode = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $zoneA, $linkPostalCode): void {
        $linkPostalCode($pgPdo, 'ABC12', $zoneA);
    });
    $assert(
        'Un CP con letras se rechaza en el catálogo',
        $letterPostalCode !== null && $letterPostalCode['state'] === '23514',
        $letterPostalCode === null ? 'el CP con letras se admitió' : 'estado=' . $letterPostalCode['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. CP de la sede: NULL admitido, formato estricto (QA 7)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Código postal de la sede ---\n";

    $historicLocation = $createLocation($pgPdo, 'historica', null, null);
    $assert(
        'Una sede histórica sin CP se acepta y queda sin zona asignada',
        $historicLocation > 0
            && $pgPdo->query('SELECT zone_id FROM locations WHERE id = ' . $historicLocation)->fetchColumn() === null
    );

    $fourDigitLocation = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $createLocation): void {
        $createLocation($pgPdo, 'cp-corto', '0800', null);
    });
    $assert(
        'El CP de la sede rechaza cuatro dígitos (chk_locations_postal_code_format)',
        $fourDigitLocation !== null
            && $fourDigitLocation['state'] === '23514'
            && str_contains($fourDigitLocation['message'], 'chk_locations_postal_code_format'),
        $fourDigitLocation === null ? 'el CP corto se admitió' : 'estado=' . $fourDigitLocation['state']
    );

    $letterLocation = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $createLocation): void {
        $createLocation($pgPdo, 'cp-letras', 'ABC12', null);
    });
    $assert(
        'El CP de la sede rechaza letras',
        $letterLocation !== null && $letterLocation['state'] === '23514',
        $letterLocation === null ? 'el CP con letras se admitió' : 'estado=' . $letterLocation['state']
    );

    $validPostalLocation = $createLocation($pgPdo, 'cp-valido', '08003', null);
    $assert(
        'Control positivo: la sede guarda un CP válido aunque todavía no tenga zona heredada',
        $validPostalLocation > 0
            && $pgPdo->query('SELECT postal_code FROM locations WHERE id = ' . $validPostalLocation)->fetchColumn() === '08003'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. La zona no se borra: se desactiva (RF-01.3 / Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Borrado físico rechazado y desactivación lógica ---\n";

    $physicalDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $zoneA): void {
        $pgPdo->exec('DELETE FROM operational_zones WHERE id = ' . $zoneA);
    });
    $assert(
        'El borrado físico de una zona se rechaza con SQLSTATE 45000 (Art. III.1)',
        $physicalDelete !== null
            && $physicalDelete['state'] === '45000'
            && str_contains($physicalDelete['message'], PURGE_VIOLATION_MARKER),
        $physicalDelete === null ? 'el borrado pasó' : 'estado=' . $physicalDelete['state']
    );

    $restrictedDelete = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $zoneA): ?array {
        return PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $zoneA): void {
            $pgPdo->exec('DELETE FROM operational_zones WHERE id = ' . $zoneA);
        });
    });
    $assert(
        'Aun con la ventana de purga abierta, las claves foráneas RESTRICT impiden borrar una zona en uso',
        $restrictedDelete !== null && $restrictedDelete['state'] === '23503',
        $restrictedDelete === null
            ? 'la zona en uso se borró pese a las claves foráneas'
            : 'estado=' . $restrictedDelete['state']
    );

    $pgPdo->exec('UPDATE operational_zones SET is_active = FALSE WHERE id = ' . $zoneA);
    $pgPdo->exec('UPDATE locations SET zone_id = NULL WHERE zone_id = ' . $zoneA);

    $deactivated = $pgPdo->query('SELECT is_active FROM operational_zones WHERE id = ' . $zoneA)->fetchColumn();
    $orphanLocations = (int)$pgPdo->query('SELECT COUNT(*) FROM locations WHERE zone_id = ' . $zoneA)->fetchColumn();

    $assert(
        'Desactivar la zona la deja inactiva y sus sedes en «Sin Zona Asignada» (zone_id = NULL)',
        in_array($deactivated, [false, 0, '0', 'f'], true)
            && $orphanLocations === 0,
        'is_active=' . json_encode($deactivated) . ' · sedes con la zona: ' . $orphanLocations
    );

    $zoneRowKept = (int)$pgPdo->query('SELECT COUNT(*) FROM operational_zones WHERE id = ' . $zoneA)->fetchColumn();
    $assert(
        'La zona desactivada conserva su fila y su historial (no se borró)',
        $zoneRowKept === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Enumerado de auditoría ampliado y utilizable (QA 11)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Auditoría: OPERATIONAL_ZONE y TECHNICIAN_ZONE ---\n";

    // El filtro por espacio de nombres es obligatorio: el tipo base `public` y el del escenario
    // aislado comparten nombre, y sin él la lectura mezclaría las etiquetas de los dos.
    $enumLabels = $pgPdo->query(
        "SELECT e.enumlabel
           FROM pg_enum e
           JOIN pg_type t ON t.oid = e.enumtypid
           JOIN pg_namespace n ON n.oid = t.typnamespace
          WHERE t.typname = 'audit_entity_type' AND n.nspname = current_schema()
          ORDER BY e.enumsortorder"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'El enumerado audit_entity_type declara las dos entidades territoriales',
        in_array('OPERATIONAL_ZONE', $enumLabels, true) && in_array('TECHNICIAN_ZONE', $enumLabels, true),
        implode(' · ', $enumLabels)
    );

    $writeAuditEvent = static function (PDO $pdo, string $entityType, string $action, int $entityId): void {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (entity_type, entity_id, action, user_role, user_name, new_state)
             VALUES (:entity_type, :entity_id, :action, 'COORDINATOR', 'Coordinación de Certificación', '{}'::jsonb)"
        );
        $stmt->execute([':entity_type' => $entityType, ':entity_id' => $entityId, ':action' => $action]);
    };

    $writeAuditEvent($pgPdo, 'OPERATIONAL_ZONE', 'ZONE_CREATED', $zoneA);
    $writeAuditEvent($pgPdo, 'TECHNICIAN_ZONE', 'TECHNICIAN_ZONE_ASSIGNED', $technicianOne);

    $auditRows = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE entity_type IN ('OPERATIONAL_ZONE', 'TECHNICIAN_ZONE')"
    )->fetchColumn();

    $assert(
        'audit_log admite escribir eventos de las dos entidades nuevas',
        $auditRows === 2,
        'eventos escritos: ' . $auditRows
    );

    $invalidEntity = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $writeAuditEvent): void {
        $writeAuditEvent($pgPdo, 'OPERATIONAL_ZONE_TYPO', 'ZONE_CREATED', 1);
    });
    $assert(
        'Control negativo: una etiqueta fuera del enumerado se rechaza (22P02)',
        $invalidEntity !== null && $invalidEntity['state'] === '22P02',
        $invalidEntity === null ? 'la etiqueta inválida se admitió' : 'estado=' . $invalidEntity['state']
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, ZONE_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve `NULL` cuando la relación no existe: es la sonda de residuo que no
    // depende del texto exacto del mensaje del motor.
    $residueProbe = $pgPdo->query("SELECT to_regclass('" . ZONE_TEST_SCHEMA . ".operational_zones')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus tablas ya no existen',
    $residueProbe === null,
    'la sonda de residuo encontró la tabla en ' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-02 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
