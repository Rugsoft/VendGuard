<?php

declare(strict_types=1);

/**
 * EvaDtsParserAndCatalogTest · T-DEX-04
 *
 * Certifica el parser determinista de ficheros EVA-DTS `.dex` y el catálogo fijo de eventos
 * `EA1`/`EA2` (Módulo 39, RF-02, RF-05.1, D3, D4, QA-4, QA-5, Art. I.3, IV y V.5).
 *
 * QUÉ SE MIDE, Y POR QUÉ ASÍ
 *
 *   1. **Seguridad antes que negocio (QA-5).** Vacío, más de 1 MB, bytes nulos, controles
 *      peligrosos y codificación no UTF-8: el parser convierte desde Windows-1252 cuando hace
 *      falta y rechaza el resto con su código funcional, no con un mensaje genérico.
 *
 *   2. **Envoltorio y cabeceras (RF-02.1).** `DXS*`..`DXE*`, `VA1` obligatorio, duplicados de
 *      `VA1`/`CA3`/`ID4` y `PA101` repetido. La huella se calcula sobre el texto normalizado, de
 *      modo que CRLF y LF producen el mismo SHA-256.
 *
 *   3. **Lectura tolerante (RF-02.2, QA-4).** Escala `ID4` (0..4, defecto 2), decimal explícito,
 *      `ID1` con aviso de identidad, `CA3` opcional, emparejamiento `PA1`+`PA2`, contadores
 *      `EA202`/`EA203` y bandera `EA205`, y conteo de segmentos desconocidos sin fallar
 *      (Art. I.3).
 *
 *   4. **Catálogo determinista (RF-05.1).** La familia térmica se evalúa antes que el prefijo de
 *      pago: `EA1_TEMP*` es una alerta sanitaria crítica y no un fallo de monedero.
 *
 * Uso:
 *   php tests/unit/EvaDtsParserAndCatalogTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Enum\DexEventCategory;
use VendGuard\Core\Domain\Enum\DexEventSeverity;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Service\EvaDtsEventCatalog;
use VendGuard\Core\Service\EvaDtsParser;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$parser = new EvaDtsParser();

$buildPayload = static function (array $body): string {
    return implode("\n", array_merge(['DXS*TESTCOMM*VA*DEX 6.0'], $body, ['DXE*TEST']));
};

$expectFailure = static function (string $payload, string $errorCode, string $what) use ($parser, $assert): void {
    try {
        $parser->parse($payload);
        $assert($what, false, 'no se lanzó ninguna excepción');
    } catch (DexAuditValidationException $exception) {
        $assert(
            $what,
            $exception->getErrorCode() === $errorCode && $exception->getHttpStatusCode() === 422,
            sprintf('código=%s · http=%d', $exception->getErrorCode(), $exception->getHttpStatusCode())
        );
    }
};

$body = [
    'ID1*MAQ-003',
    'ID4*2',
    'VA1*2550*41',
    'CA3*1234*567*89',
    'PA1*PA101*150',
    'PA2*10*1500*3*450',
    'PA1*PA102*200',
    'PA2*5*1000',
    'EA1*EG01*20261120*091500',
    'EA2*EG01*2*3*0*1',
    'MA5*propietario',
];
$payload = $buildPayload($body);

echo "======================================================================\n";
echo " VENDGUARD · T-DEX-04 · Parser EVA-DTS (.dex) y catálogo EA1/EA2\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Seguridad y envoltorio (QA-5, RF-02.1) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$parsed = $parser->parse($payload, 'MAQ-003');

$assert(
    '1.1 La huella SHA-256 se calcula sobre el texto normalizado sin espacios finales',
    $parsed['sha256_hash'] === hash('sha256', implode("\n", array_merge(['DXS*TESTCOMM*VA*DEX 6.0'], $body, ['DXE*TEST'])))
        && $parsed['raw_payload_normalized'] === implode("\n", array_merge(['DXS*TESTCOMM*VA*DEX 6.0'], $body, ['DXE*TEST']))
);
$assert(
    '1.2 El tamaño declarado es el del fichero recibido',
    $parsed['byte_size'] === strlen($payload)
);
$crlfPayload = str_replace("\n", "\r\n", $payload);
$crlfParsed = $parser->parse($crlfPayload);
$assert(
    '1.3 CRLF y LF producen exactamente la misma huella',
    $crlfParsed['sha256_hash'] === $parsed['sha256_hash']
);
$assert(
    '1.3 bis El texto recibido se conserva sin normalizar para la descarga',
    $crlfParsed['raw_payload_received'] === $crlfPayload
        && $crlfParsed['raw_payload_normalized'] === $parsed['raw_payload_normalized']
);
$assert(
    '1.4 Un fichero Latin-1/Windows-1252 se convierte a UTF-8 antes de calcular la huella',
    (static function () use ($parser): bool {
        $latin = "DXS*TESTCOMM*VA*DEX 6.0\nVA1*2550*41\nMA5*Caf\xe9\nDXE*TEST";
        $utf8 = "DXS*TESTCOMM*VA*DEX 6.0\nVA1*2550*41\nMA5*Café\nDXE*TEST";
        $result = $parser->parse($latin);

        return mb_check_encoding($result['raw_payload_normalized'], 'UTF-8')
            && str_contains($result['raw_payload_normalized'], 'Café')
            && $result['sha256_hash'] === hash('sha256', $utf8);
    })()
);

$expectFailure('', 'DEX_INVALID_TEXT_PAYLOAD', '1.5 Un fichero vacío se rechaza con su código funcional');
$expectFailure("DXS*A\nVA1*1*1\n\0\nDXE*B", 'DEX_INVALID_TEXT_PAYLOAD', '1.6 Un byte nulo se rechaza antes de parsear');
$expectFailure("DXS*A\nVA1*1*1\nMA5*ok\x07\nDXE*B", 'DEX_INVALID_TEXT_PAYLOAD', '1.7 Un carácter de control peligroso se rechaza');
$expectFailure(
    str_repeat('A', EvaDtsParser::MAX_BYTES + 1),
    'DEX_FILE_TOO_LARGE',
    '1.8 Un fichero de más de 1 MB se rechaza antes de tocar el contenido'
);
$expectFailure("VA1*100*1\nDXE*TEST", 'DEX_INVALID_ENVELOPE', '1.9 Un fichero sin apertura DXS* se rechaza');
$expectFailure("DXS*TEST\nVA1*100*1", 'DEX_INVALID_ENVELOPE', '1.10 Un fichero sin cierre DXE* se rechaza');
$expectFailure("DXS*TEST\nID4*2\nDXE*TEST", 'DEX_MISSING_VA1_SEGMENT', '1.11 Un fichero sin VA1 se rechaza (RF-02.1)');
$expectFailure(
    "DXS*TEST\nVA1*100*1\nVA1*200*2\nDXE*TEST",
    'DEX_DUPLICATE_HEADER_SEGMENT',
    '1.12 Un VA1 duplicado se rechaza'
);
$expectFailure(
    "DXS*TEST\nID4*2\nID4*3\nVA1*100*1\nDXE*TEST",
    'DEX_DUPLICATE_HEADER_SEGMENT',
    '1.13 Un ID4 duplicado se rechaza'
);
$expectFailure(
    "DXS*TEST\nCA3*1*2*3\nCA3*4*5*6\nVA1*100*1\nDXE*TEST",
    'DEX_DUPLICATE_HEADER_SEGMENT',
    '1.14 Un CA3 duplicado se rechaza'
);
$expectFailure(
    "DXS*TEST\nVA1*-100*1\nDXE*TEST",
    'DEX_INVALID_NUMERIC_BOUNDS',
    '1.15 Un importe negativo se rechaza (cotas no negativas)'
);
$expectFailure(
    "DXS*TEST\nVA1*100*1000000000\nDXE*TEST",
    'DEX_INVALID_NUMERIC_BOUNDS',
    '1.16 Un contador de unidades fuera de techo se rechaza'
);
$expectFailure(
    "DXS*TEST\nID4*5\nVA1*100*1\nDXE*TEST",
    'DEX_INVALID_ID4_DECIMAL',
    '1.17 Una escala ID4 fuera de 0..4 se rechaza'
);
$expectFailure(
    "DXS*TEST\nVA1*100*1\nPA1*PA101*150\nPA2*1*150\nPA1*PA101*150\nPA2*1*150\nDXE*TEST",
    'DEX_DUPLICATE_SELECTION_CODE',
    '1.18 Un PA101 repetido en el mismo .dex se rechaza (QA-4)'
);
$expectFailure(
    "DXS*TEST\nVA1*100*1\nEA1**20260101*1200\nDXE*TEST",
    'DEX_INVALID_CODE',
    '1.19 Un código de evento vacío se rechaza como campo de texto (H25-12)'
);
$expectFailure(
    "DXS*TEST\nVA1*100*1\nEA2*" . str_repeat('A', 33) . "*1\nDXE*TEST",
    'DEX_INVALID_CODE',
    '1.20 Un código de evento fuera de longitud se rechaza como campo de texto (H25-12)'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Cabeceras, identidad y escala decimal (RF-02.2) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$assert(
    '2.1 El DXS aporta el identificador de comunicaciones y la versión',
    $parsed['dxs_comm_id'] === 'TESTCOMM' && $parsed['dxs_version'] === 'DEX 6.0'
);
$assert(
    '2.2 ID1 se expone como identificador de máquina',
    $parsed['dex_machine_identifier'] === 'MAQ-003'
);
$assert(
    '2.3 Con el código esperado distinto se marca el aviso de identidad sin bloquear',
    $parser->parse($payload, 'MAQ-999')['identity_mismatch_warning'] === true
        && $parser->parse($payload, 'maq-003')['identity_mismatch_warning'] === false
        && $parsed['identity_mismatch_warning'] === false
);
$assert(
    '2.4 Un ID1 todo ceros o plantilla neutra no genera aviso',
    (static function () use ($parser): bool {
        $result = $parser->parse("DXS*C\nID1*000000\nVA1*100*1\nDXE*F", 'MAQ-003');
        $plantilla = $parser->parse("DXS*C\nID1*XXXX\nVA1*100*1\nDXE*F", 'MAQ-003');

        return $result['identity_mismatch_warning'] === false
            && $plantilla['identity_mismatch_warning'] === false;
    })()
);
$assert(
    '2.5 Sin ID4 se asume la escala 2 y VA1 se divide entre 100',
    $parser->parse("DXS*C\nVA1*2550*41\nDXE*F")['va1_total_sales_eur'] === 25.50
);
$assert(
    '2.6 ID4 = 0 conserva el importe entero y el decimal explícito se acepta',
    $parser->parse("DXS*C\nID4*0\nVA1*2550*41\nDXE*F")['va1_total_sales_eur'] === 2550.00
        && $parser->parse("DXS*C\nVA1*25.50*41\nDXE*F")['va1_total_sales_eur'] === 25.50
);
$assert(
    '2.7 VA1 aporta totales y parciales de reseteo',
    $parsed['va1_total_sales_eur'] === 25.50
        && $parsed['va1_total_vend_units'] === 41
        && $parsed['va1_reset_sales_eur'] === null
        && $parsed['va1_reset_vend_units'] === null
);
$assert(
    '2.8 CA3 opcional aporta caja, tubos y billetes',
    $parsed['ca3_cashbox_eur'] === 12.34
        && $parsed['ca3_coin_tubes_eur'] === 5.67
        && $parsed['ca3_bills_in_eur'] === 0.89
);
$assert(
    '2.9 CA3 con cinco campos respeta el desplazamiento de CA301',
    (static function () use ($parser): bool {
        $result = $parser->parse("DXS*C\nVA1*100*1\nCA3*0*999*111*22\nDXE*F");

        return $result['ca3_cashbox_eur'] === 9.99
            && $result['ca3_coin_tubes_eur'] === 1.11
            && $result['ca3_bills_in_eur'] === 0.22;
    })()
);
$assert(
    '2.10 Sin CA3 los tres importes quedan en NULL',
    $parser->parse("DXS*C\nVA1*100*1\nDXE*F")['ca3_cashbox_eur'] === null
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Selecciones PA1/PA2 y eventos EA1/EA2 (QA-4) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$assert(
    '3.1 Cada PA1 se empareja con su PA2 consecutivo y conserva la secuencia',
    count($parsed['selections']) === 2
        && $parsed['selections'][0]['line_sequence'] === 1
        && $parsed['selections'][0]['normalized_selection_code'] === 'PA101'
        && $parsed['selections'][1]['line_sequence'] === 2
        && $parsed['selections'][1]['normalized_selection_code'] === 'PA102'
);
$assert(
    '3.2 El precio unitario, las unidades y las ventas se escalan con ID4',
    $parsed['selections'][0]['unit_price_eur'] === 1.50
        && $parsed['selections'][0]['total_vend_units'] === 10
        && $parsed['selections'][0]['total_sales_eur'] === 15.00
        && $parsed['selections'][1]['unit_price_eur'] === 2.00
        && $parsed['selections'][1]['total_sales_eur'] === 10.00
);
$assert(
    '3.3 Los parciales de reseteo de PA2 son opcionales',
    $parsed['selections'][0]['reset_vend_units'] === 3
        && $parsed['selections'][0]['reset_sales_eur'] === 4.50
        && $parsed['selections'][1]['reset_vend_units'] === null
        && $parsed['selections'][1]['reset_sales_eur'] === null
);
$assert(
    '3.4 Un PA1 sin PA2 consecutivo se omite y se cuenta como desconocido',
    (static function () use ($parser): bool {
        $result = $parser->parse("DXS*C\nVA1*100*1\nPA1*PA999*150\nDXE*F");

        return $result['selections'] === [] && $result['ignored_unknown_lines_count'] === 1;
    })()
);
$assert(
    '3.5 Un segmento desconocido incrementa el contador sin romper el parseo (Art. I.3)',
    $parsed['ignored_unknown_lines_count'] === 1
);
$assert(
    '3.6 EA1 aporta código, fecha cruda y una ocurrencia',
    $parsed['events'][0]['source_block'] === 'EA1'
        && $parsed['events'][0]['event_code'] === 'EG01'
        && $parsed['events'][0]['occurrence_count'] === 1
        && $parsed['events'][0]['event_occurred_at_raw'] === '20261120 091500'
        && $parsed['events'][0]['count_since_reset'] === null
);
$assert(
    '3.7 EA2 aporta EA202, EA203 y la bandera EA205',
    $parsed['events'][1]['source_block'] === 'EA2'
        && $parsed['events'][1]['count_since_reset'] === 2
        && $parsed['events'][1]['count_since_init'] === 3
        && $parsed['events'][1]['is_active_flag'] === true
        && $parsed['events'][1]['occurrence_count'] === 2
);
$assert(
    '3.8 El evento EG01 se clasifica como térmico crítico y activa el aviso sanitario',
    $parsed['events'][0]['event_category'] === 'THERMAL_REFRIGERATION'
        && $parsed['events'][0]['severity'] === 'CRITICAL'
        && $parsed['events'][0]['is_thermal_alert'] === true
        && str_contains($parsed['events'][0]['description_es'], 'EG01')
);
$assert(
    '3.9 Un EA2 con contadores a cero conserva al menos una ocurrencia',
    (static function () use ($parser): bool {
        $result = $parser->parse("DXS*C\nVA1*100*1\nEA2*EA202*0*0\nDXE*F");

        return $result['events'][0]['occurrence_count'] === 1
            && $result['events'][0]['count_since_reset'] === 0;
    })()
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Catálogo EvaDtsEventCatalog (RF-05.1) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$assert(
    '4.1 EG* es refrigeración crítica',
    EvaDtsEventCatalog::category('EG01') === DexEventCategory::THERMAL_REFRIGERATION
        && EvaDtsEventCatalog::severity('EG01') === DexEventSeverity::CRITICAL
);
$assert(
    '4.2 Los alias térmicos se evalúan antes que el prefijo de pago EA*',
    EvaDtsEventCatalog::category('EA1_TEMP9') === DexEventCategory::THERMAL_REFRIGERATION
        && EvaDtsEventCatalog::category('EA2_TEMP_LOW') === DexEventCategory::THERMAL_REFRIGERATION
        && EvaDtsEventCatalog::category('E_TEMP_HIGH') === DexEventCategory::THERMAL_REFRIGERATION
        && EvaDtsEventCatalog::category('TEMP_SENSOR') === DexEventCategory::THERMAL_REFRIGERATION
        && EvaDtsEventCatalog::category('REFR_DOOR') === DexEventCategory::THERMAL_REFRIGERATION
);
$assert(
    '4.3 Los prefijos de pago EA/EB/EC/EJ y sus alias son PAYMENT_MDB WARNING',
    EvaDtsEventCatalog::category('EA202') === DexEventCategory::PAYMENT_MDB
        && EvaDtsEventCatalog::category('EB01') === DexEventCategory::PAYMENT_MDB
        && EvaDtsEventCatalog::category('EC10') === DexEventCategory::PAYMENT_MDB
        && EvaDtsEventCatalog::category('EJ05') === DexEventCategory::PAYMENT_MDB
        && EvaDtsEventCatalog::category('MDB_OFFLINE') === DexEventCategory::PAYMENT_MDB
        && EvaDtsEventCatalog::category('CASHLESS_TIMEOUT') === DexEventCategory::PAYMENT_MDB
        && EvaDtsEventCatalog::severity('EA202') === DexEventSeverity::WARNING
);
$assert(
    '4.4 Los prefijos EF/EH y sus alias son MECHANICAL_VEND WARNING',
    EvaDtsEventCatalog::category('EF01') === DexEventCategory::MECHANICAL_VEND
        && EvaDtsEventCatalog::category('EH01') === DexEventCategory::MECHANICAL_VEND
        && EvaDtsEventCatalog::category('SPIRAL_JAM') === DexEventCategory::MECHANICAL_VEND
        && EvaDtsEventCatalog::category('MOTOR_OVERHEAT') === DexEventCategory::MECHANICAL_VEND
);
$assert(
    '4.5 Los prefijos ED/EE y sus alias son POWER_CONTROLLER WARNING',
    EvaDtsEventCatalog::category('ED01') === DexEventCategory::POWER_CONTROLLER
        && EvaDtsEventCatalog::category('EE01') === DexEventCategory::POWER_CONTROLLER
        && EvaDtsEventCatalog::category('VMC_RESET') === DexEventCategory::POWER_CONTROLLER
        && EvaDtsEventCatalog::category('EEPROM_CRC') === DexEventCategory::POWER_CONTROLLER
);
$assert(
    '4.6 Un código no mapeado se conserva como OTHER INFO (Art. I.3)',
    EvaDtsEventCatalog::category('ZZZ9') === DexEventCategory::OTHER
        && EvaDtsEventCatalog::severity('ZZZ9') === DexEventSeverity::INFO
        && EvaDtsEventCatalog::describeInSpanish('ZZZ9') === 'Evento Otros Eventos · ZZZ9'
);
$assert(
    '4.7 La clasificación es insensible a mayúsculas y espacios',
    EvaDtsEventCatalog::category('  eg01 ') === DexEventCategory::THERMAL_REFRIGERATION
        && EvaDtsEventCatalog::isThermal('eg07') === true
        && EvaDtsEventCatalog::isThermal('EA202') === false
);
$assert(
    '4.8 `describe()` devuelve la pareja completa de familia y gravedad',
    EvaDtsEventCatalog::describe('EF07') === [
        'category' => DexEventCategory::MECHANICAL_VEND,
        'severity' => DexEventSeverity::WARNING,
    ]
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-DEX-04 (parser EVA-DTS y catálogo determinista) CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL PARSER EVA-DTS.\n";
echo "======================================================================\n";
exit(1);
