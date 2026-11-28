<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use DomainException;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\DexAuditStatus;
use VendGuard\Core\Domain\Enum\DexImportSource;

/**
 * MachineDexAudit
 *
 * Cabecera inmutable de un volcado EVA-DTS `.dex` (Módulo 39, Tabla 52
 * `machine_dex_audits`, RF-01.1, RF-03.2, RF-04.1, D1 a D5, C2, C3, C6,
 * QA-1, QA-2, QA-3, QA-4, Art. II.2, Art. III.1, Art. III.3 y Art. V.4).
 *
 * **Inmutabilidad por etapas (Art. III.3).** El motor congela toda la captura
 * tras el `INSERT` y solo admite dos escrituras: enlazar una vez una visita, una
 * recaudación o una incidencia (`NULL` → valor, mientras el volcado está
 * `ACTIVE`) y la transición única `ACTIVE` → `VOIDED` con motivo real. La
 * entidad replica esa misma frontera: `withLinkTo…()` y `withVoid()` devuelven
 * copias y fallan en cuanto el vínculo ya estaba fijado o la fila está anulada.
 * Corregir un volcado no es reescribirlo: es importar otro.
 *
 * **Invariantes en el borde.** Se replican las identidades del motor que no
 * pueden esperar a la base: formato `DEX-AAAA-NNNN`, SHA-256 en minúsculas,
 * tamaño del fichero 1..1 048 576 bytes, escala `ID4` 0..4, cotas no negativas
 * de contadores e importes, la coherencia de las cuatro ramas de `delta_source`
 * (espejo de `chk_machine_dex_audits_delta_coherence`), el formato del
 * identificador de mutación del móvil y la coherencia de la anulación lógica
 * (motivo de 20 a 500 caracteres reales con tres bloques alfanuméricos, autor y
 * fecha). Lo que **no** decide es el perímetro territorial ni la primacía
 * sanitaria: eso depende del parque y lo resuelven el validador (`T-DEX-05`) y
 * el disparador `enforce_dex_audit_sanitary_guard()`.
 *
 * **Proyecciones segregadas (QA-1, QA-3, Art. V.4).** La entidad nunca se
 * expone a pelo:
 *
 *   - `toRestockerArray(bool $includeImmediatePrefill = false)` — vista del
 *     móvil en unidades y eventos. `prefill_sales_counter_reading_eur` aparece
 *     **solo** cuando `$includeImmediatePrefill = true` (respuesta directa del
 *     `POST`); el `GET` histórico jamás lo recibe. Omite siempre `CA3`, ventas
 *     por carril, `total_sales_eur`, `delta_sales_eur` y `raw_payload`.
 *   - `toTechnicianArray()` — idéntica a la del historial en unidades, sin
 *     prefill ni importes.
 *   - `toCoordinatorArray()` — auditoría financiera completa, con deltas
 *     efectivos si el predecesor fue anulado, cruces M34/M35 y metadatos de
 *     anulación; el `raw_payload` sigue fuera: se descarga por su endpoint con
 *     cabeceras seguras (C3).
 *
 * `toArray()` y `jsonSerialize()` devuelven la proyección de historial del
 * reponedor a propósito: el camino por defecto es el de mínimo privilegio y la
 * vista financiera exige una llamada explícita.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineDexAudit implements JsonSerializable
{
    /** Forma canónica del código de volcado (RF-01.1). */
    public const AUDIT_CODE_PATTERN = '/^DEX-[0-9]{4}-[0-9]{4,}$/';

    /** Forma canónica de la huella SHA-256. */
    public const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    /** Forma canónica del identificador de mutación del móvil (C5). */
    public const MUTATION_ID_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

    /** Tamaño máximo del `.dex` (1 MB, QA-5). */
    public const MAX_FILE_SIZE_BYTES = 1048576;

    /** Cotas del contrato de esquema. */
    public const MAX_VA1_SALES_EUR = 9999999999.99;
    public const MAX_CA3_EUR = 9999999999.99;
    public const MAX_UNITS = 999999999;
    public const MIN_VOID_REASON_LENGTH = 20;
    public const MAX_VOID_REASON_LENGTH = 500;
    public const MIN_ALNUM_BLOCKS = 3;
    public const MIN_MUTATION_ID_LENGTH = 8;
    public const MAX_MUTATION_ID_LENGTH = 64;

    /** Orígenes de delta admitidos por `chk_machine_dex_audits_delta_coherence`. */
    public const DELTA_SOURCES = ['INITIAL_BASELINE', 'PREVIOUS_AUDIT', 'RESET_FALLBACK', 'UNAVAILABLE'];

    private ?int $id;
    private string $auditCode;
    private int $machineId;
    private string $machineCodeSnapshot;
    private string $machineTypeSnapshot;
    private int $importedBy;
    private string $importerRoleSnapshot;
    private string $importerNameSnapshot;
    private DexImportSource $importSource;
    private DexAuditStatus $status;
    private ?int $restockVisitId;
    private ?int $cashCollectionId;
    private ?int $incidentId;
    private string $originalFilename;
    private int $fileSizeBytes;
    private string $sha256Hash;
    private string $rawPayload;
    private ?string $dxsCommId;
    private ?string $dxsVersion;
    private ?string $dexMachineIdentifier;
    private bool $identityMismatchWarning;
    private int $id4DecimalPlaces;
    private int $ignoredUnknownLinesCount;
    private float $va1TotalSalesEur;
    private int $va1TotalVendUnits;
    private ?float $va1ResetSalesEur;
    private ?int $va1ResetVendUnits;
    private ?float $ca3CashboxEur;
    private ?float $ca3CoinTubesEur;
    private ?float $ca3BillsInEur;
    private ?int $previousAuditId;
    private bool $counterResetDetected;
    private bool $outOfOrderSync;
    private string $deltaSource;
    private ?float $deltaSalesEur;
    private ?int $deltaVendUnits;
    private ?string $clientMutationId;
    private string $capturedAt;
    private ?string $voidReason;
    private ?int $voidedBy;
    private ?string $voidedByNameSnapshot;
    private ?string $voidedAt;
    private ?string $createdAt;
    private ?string $updatedAt;

    /** @var list<MachineDexSelectionLine> */
    private array $selections;

    /** @var list<MachineDexEventLine> */
    private array $events;

    private bool $previousAuditVoided;
    private ?int $effectivePreviousAuditId;
    private ?float $effectiveDeltaSalesEur;
    private ?int $effectiveDeltaVendUnits;
    private ?array $m34CrossCheck;
    private ?array $m35CrossCheck;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param string $auditCode Código legible `DEX-AAAA-NNNN`; se normaliza.
     * @param int $machineId Máquina auditada.
     * @param string $machineCodeSnapshot Código de máquina congelado.
     * @param string $machineTypeSnapshot Tipología congelada en el alta.
     * @param int $importedBy Usuario que firma la importación.
     * @param string $importerRoleSnapshot Rol del importador congelado.
     * @param string $importerNameSnapshot Nombre del importador congelado.
     * @param DexImportSource $importSource Puerta de entrada del volcado.
     * @param string $originalFilename Nombre original del fichero.
     * @param int $fileSizeBytes Tamaño del `.dex` en bytes (1..1 048 576).
     * @param string $sha256Hash Huella canónica del payload normalizado.
     * @param string $rawPayload Contenido crudo; nunca sale en una proyección.
     * @param float $va1TotalSalesEur Total de ventas `VA101`.
     * @param int $va1TotalVendUnits Total de unidades `VA102`.
     * @param string $deltaSource Una de las cuatro ramas de `DELTA_SOURCES`.
     * @param string $capturedAt Instante de captura del volcado.
     * @param string|null $dxsCommId Identificador `DXS` de comunicaciones.
     * @param string|null $dxsVersion Versión del firmware declarada en `DXS`.
     * @param string|null $dexMachineIdentifier Identificador `ID1` del fichero.
     * @param bool $identityMismatchWarning El `ID1` no coincide con `machines.code`.
     * @param int $id4DecimalPlaces Escala decimal `ID4` (0..4).
     * @param int $ignoredUnknownLinesCount Líneas desconocidas ignoradas.
     * @param float|null $va1ResetSalesEur `VA103` opcional.
     * @param int|null $va1ResetVendUnits `VA104` opcional.
     * @param float|null $ca3CashboxEur `CA3` de caja, solo vista de Coordinación.
     * @param float|null $ca3CoinTubesEur `CA3` de tubos de cambio.
     * @param float|null $ca3BillsInEur `CA3` de billetes admitidos.
     * @param int|null $previousAuditId Volcado predecesor de la cadena de deltas.
     * @param bool $counterResetDetected Se detectó retroceso del contador.
     * @param bool $outOfOrderSync Llegó con retraso respecto a un volcado posterior (QA-2).
     * @param float|null $deltaSalesEur Delta monetario persistido.
     * @param int|null $deltaVendUnits Delta de unidades persistido.
     * @param string|null $clientMutationId Idempotencia del móvil (C5).
     * @param DexAuditStatus $status Estado del volcado.
     * @param int|null $restockVisitId Visita M34 vinculada (D1).
     * @param int|null $cashCollectionId Recaudación M35 vinculada (D1).
     * @param int|null $incidentId Incidencia M16/M36 vinculada.
     * @param string|null $voidReason Motivo real de la anulación (Art. V.1).
     * @param int|null $voidedBy Usuario que anula.
     * @param string|null $voidedByNameSnapshot Nombre congelado del anulador.
     * @param string|null $voidedAt Instante de la anulación.
     * @param string|null $createdAt Sello de alta del motor.
     * @param string|null $updatedAt Sello de actualización del motor.
     * @param list<MachineDexSelectionLine> $selections Líneas `PA1`/`PA2`.
     * @param list<MachineDexEventLine> $events Líneas `EA1`/`EA2`.
     *
     * @throws InvalidArgumentException Si un formato, una cota o una coherencia
     *                                  del contrato no se cumplen.
     */
    public function __construct(
        ?int $id,
        string $auditCode,
        int $machineId,
        string $machineCodeSnapshot,
        string $machineTypeSnapshot,
        int $importedBy,
        string $importerRoleSnapshot,
        string $importerNameSnapshot,
        DexImportSource $importSource,
        string $originalFilename,
        int $fileSizeBytes,
        string $sha256Hash,
        string $rawPayload,
        float $va1TotalSalesEur,
        int $va1TotalVendUnits,
        string $deltaSource,
        string $capturedAt,
        ?string $dxsCommId = null,
        ?string $dxsVersion = null,
        ?string $dexMachineIdentifier = null,
        bool $identityMismatchWarning = false,
        int $id4DecimalPlaces = 2,
        int $ignoredUnknownLinesCount = 0,
        ?float $va1ResetSalesEur = null,
        ?int $va1ResetVendUnits = null,
        ?float $ca3CashboxEur = null,
        ?float $ca3CoinTubesEur = null,
        ?float $ca3BillsInEur = null,
        ?int $previousAuditId = null,
        bool $counterResetDetected = false,
        bool $outOfOrderSync = false,
        ?float $deltaSalesEur = null,
        ?int $deltaVendUnits = null,
        ?string $clientMutationId = null,
        DexAuditStatus $status = DexAuditStatus::ACTIVE,
        ?int $restockVisitId = null,
        ?int $cashCollectionId = null,
        ?int $incidentId = null,
        ?string $voidReason = null,
        ?int $voidedBy = null,
        ?string $voidedByNameSnapshot = null,
        ?string $voidedAt = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        array $selections = [],
        array $events = []
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador del volcado debe ser un entero positivo.');
        }

        if ($machineId <= 0) {
            throw new InvalidArgumentException('El volcado debe referenciar una máquina existente.');
        }

        if ($importedBy <= 0) {
            throw new InvalidArgumentException('El volcado debe referenciar un usuario importador existente.');
        }

        $cleanCode = strtoupper(trim($auditCode));
        if (preg_match(self::AUDIT_CODE_PATTERN, $cleanCode) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "El código de volcado «%s» debe cumplir DEX-AAAA-NNNN y se normaliza en mayúsculas.",
                $auditCode
            ));
        }

        $cleanMachineCode = self::assertSnapshot($machineCodeSnapshot, 32, 'machine_code_snapshot');
        $cleanMachineType = self::assertSnapshot($machineTypeSnapshot, 32, 'machine_type_snapshot');
        $cleanRole = strtoupper(self::assertSnapshot($importerRoleSnapshot, 16, 'importer_role_snapshot'));
        $cleanImporterName = self::assertSnapshot($importerNameSnapshot, 150, 'importer_name_snapshot');
        $cleanFilename = self::assertSnapshot($originalFilename, 255, 'original_filename');

        if ($fileSizeBytes < 1 || $fileSizeBytes > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'El tamaño del .dex debe estar entre 1 y %d bytes; se recibieron %d.',
                self::MAX_FILE_SIZE_BYTES,
                $fileSizeBytes
            ));
        }

        $cleanHash = strtolower(trim($sha256Hash));
        if (preg_match(self::SHA256_PATTERN, $cleanHash) !== 1) {
            throw new InvalidArgumentException('La huella del volcado debe ser un SHA-256 hexadecimal de 64 caracteres en minúsculas.');
        }

        $rawLength = strlen($rawPayload);
        if ($rawLength < 1 || $rawLength > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'El payload crudo debe tener entre 1 y %d bytes; se recibieron %d.',
                self::MAX_FILE_SIZE_BYTES,
                $rawLength
            ));
        }

        if ($id4DecimalPlaces < 0 || $id4DecimalPlaces > 4) {
            throw new InvalidArgumentException('La escala ID4 debe estar entre 0 y 4 decimales.');
        }

        if ($ignoredUnknownLinesCount < 0) {
            throw new InvalidArgumentException('El contador de líneas ignoradas no puede ser negativo.');
        }

        self::assertMoney($va1TotalSalesEur, self::MAX_VA1_SALES_EUR, 'va1_total_sales_eur');
        self::assertUnits($va1TotalVendUnits, 'va1_total_vend_units');
        self::assertMoney($va1ResetSalesEur, self::MAX_VA1_SALES_EUR, 'va1_reset_sales_eur');
        self::assertUnits($va1ResetVendUnits, 'va1_reset_vend_units');
        self::assertMoney($ca3CashboxEur, self::MAX_CA3_EUR, 'ca3_cashbox_eur');
        self::assertMoney($ca3CoinTubesEur, self::MAX_CA3_EUR, 'ca3_coin_tubes_eur');
        self::assertMoney($ca3BillsInEur, self::MAX_CA3_EUR, 'ca3_bills_in_eur');

        $cleanDeltaSource = strtoupper(trim($deltaSource));
        if (!in_array($cleanDeltaSource, self::DELTA_SOURCES, true)) {
            throw new InvalidArgumentException(sprintf(
                "El origen de delta «%s» no es válido. Valores permitidos: %s.",
                $deltaSource,
                implode(', ', self::DELTA_SOURCES)
            ));
        }

        self::assertDeltaCoherence(
            $cleanDeltaSource,
            $previousAuditId,
            $counterResetDetected,
            $deltaSalesEur,
            $deltaVendUnits
        );

        $cleanMutationId = $clientMutationId === null ? null : trim($clientMutationId);
        if ($cleanMutationId === '') {
            $cleanMutationId = null;
        }
        if ($cleanMutationId !== null) {
            $length = mb_strlen($cleanMutationId, 'UTF-8');
            if ($length < self::MIN_MUTATION_ID_LENGTH
                || $length > self::MAX_MUTATION_ID_LENGTH
                || preg_match(self::MUTATION_ID_PATTERN, $cleanMutationId) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'El client_mutation_id debe tener entre %d y %d caracteres de [A-Za-z0-9_.:-].',
                    self::MIN_MUTATION_ID_LENGTH,
                    self::MAX_MUTATION_ID_LENGTH
                ));
            }
        }

        $cleanCapturedAt = trim($capturedAt);
        if ($cleanCapturedAt === '') {
            throw new InvalidArgumentException('El volcado debe declarar el instante de captura.');
        }
        self::parseInstant($cleanCapturedAt, 'captured_at');

        foreach ([
            'restock_visit_id' => $restockVisitId,
            'cash_collection_id' => $cashCollectionId,
            'incident_id' => $incidentId,
            'previous_audit_id' => $previousAuditId,
            'voided_by' => $voidedBy,
        ] as $field => $value) {
            if ($value !== null && $value <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'El campo %s debe ser un identificador positivo o null; se recibió %d.',
                    $field,
                    $value
                ));
            }
        }

        $cleanVoidReason = $voidReason === null ? null : trim($voidReason);
        $cleanVoidedName = $voidedByNameSnapshot === null ? null : trim($voidedByNameSnapshot);
        $cleanVoidedAt = $voidedAt === null ? null : trim($voidedAt);

        if ($status->isVoided()) {
            self::assertVoidPayload($cleanVoidReason, $voidedBy, $cleanVoidedName, $cleanVoidedAt, $cleanCapturedAt, $createdAt);
        } elseif ($cleanVoidReason !== null
            || $voidedBy !== null
            || $cleanVoidedName !== null
            || $cleanVoidedAt !== null) {
            throw new InvalidArgumentException(
                'Un volcado ACTIVE no puede conservar residuos de anulación (motivo, autor o fecha) — chk_machine_dex_audits_void_coherence.'
            );
        }

        foreach ($selections as $selection) {
            if (!$selection instanceof MachineDexSelectionLine) {
                throw new InvalidArgumentException('Las selecciones del volcado deben ser MachineDexSelectionLine.');
            }
        }

        foreach ($events as $event) {
            if (!$event instanceof MachineDexEventLine) {
                throw new InvalidArgumentException('Los eventos del volcado deben ser MachineDexEventLine.');
            }
        }

        $this->id = $id;
        $this->auditCode = $cleanCode;
        $this->machineId = $machineId;
        $this->machineCodeSnapshot = $cleanMachineCode;
        $this->machineTypeSnapshot = $cleanMachineType;
        $this->importedBy = $importedBy;
        $this->importerRoleSnapshot = $cleanRole;
        $this->importerNameSnapshot = $cleanImporterName;
        $this->importSource = $importSource;
        $this->status = $status;
        $this->restockVisitId = $restockVisitId;
        $this->cashCollectionId = $cashCollectionId;
        $this->incidentId = $incidentId;
        $this->originalFilename = $cleanFilename;
        $this->fileSizeBytes = $fileSizeBytes;
        $this->sha256Hash = $cleanHash;
        $this->rawPayload = $rawPayload;
        $this->dxsCommId = self::normalizeText($dxsCommId);
        $this->dxsVersion = self::normalizeText($dxsVersion);
        $this->dexMachineIdentifier = self::normalizeText($dexMachineIdentifier);
        $this->identityMismatchWarning = $identityMismatchWarning;
        $this->id4DecimalPlaces = $id4DecimalPlaces;
        $this->ignoredUnknownLinesCount = $ignoredUnknownLinesCount;
        $this->va1TotalSalesEur = round($va1TotalSalesEur, 2);
        $this->va1TotalVendUnits = $va1TotalVendUnits;
        $this->va1ResetSalesEur = self::roundMoney($va1ResetSalesEur);
        $this->va1ResetVendUnits = $va1ResetVendUnits;
        $this->ca3CashboxEur = self::roundMoney($ca3CashboxEur);
        $this->ca3CoinTubesEur = self::roundMoney($ca3CoinTubesEur);
        $this->ca3BillsInEur = self::roundMoney($ca3BillsInEur);
        $this->previousAuditId = $previousAuditId;
        $this->counterResetDetected = $counterResetDetected;
        $this->outOfOrderSync = $outOfOrderSync;
        $this->deltaSource = $cleanDeltaSource;
        $this->deltaSalesEur = self::roundMoney($deltaSalesEur);
        $this->deltaVendUnits = $deltaVendUnits;
        $this->clientMutationId = $cleanMutationId;
        $this->capturedAt = $cleanCapturedAt;
        $this->voidReason = $cleanVoidReason;
        $this->voidedBy = $voidedBy;
        $this->voidedByNameSnapshot = $cleanVoidedName;
        $this->voidedAt = $cleanVoidedAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->selections = array_values($selections);
        $this->events = array_values($events);
        $this->previousAuditVoided = false;
        $this->effectivePreviousAuditId = null;
        $this->effectiveDeltaSalesEur = null;
        $this->effectiveDeltaVendUnits = null;
        $this->m34CrossCheck = null;
        $this->m35CrossCheck = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuditCode(): string
    {
        return $this->auditCode;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getMachineCodeSnapshot(): string
    {
        return $this->machineCodeSnapshot;
    }

    public function getMachineTypeSnapshot(): string
    {
        return $this->machineTypeSnapshot;
    }

    public function getImportedBy(): int
    {
        return $this->importedBy;
    }

    public function getImporterRoleSnapshot(): string
    {
        return $this->importerRoleSnapshot;
    }

    public function getImporterNameSnapshot(): string
    {
        return $this->importerNameSnapshot;
    }

    public function getImportSource(): DexImportSource
    {
        return $this->importSource;
    }

    public function getStatus(): DexAuditStatus
    {
        return $this->status;
    }

    public function getRestockVisitId(): ?int
    {
        return $this->restockVisitId;
    }

    public function getCashCollectionId(): ?int
    {
        return $this->cashCollectionId;
    }

    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getFileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    public function getSha256Hash(): string
    {
        return $this->sha256Hash;
    }

    /**
     * El payload crudo solo se entrega al endpoint de descarga de Coordinación (C3).
     */
    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function getDxsCommId(): ?string
    {
        return $this->dxsCommId;
    }

    public function getDxsVersion(): ?string
    {
        return $this->dxsVersion;
    }

    public function getDexMachineIdentifier(): ?string
    {
        return $this->dexMachineIdentifier;
    }

    public function hasIdentityMismatchWarning(): bool
    {
        return $this->identityMismatchWarning;
    }

    public function getId4DecimalPlaces(): int
    {
        return $this->id4DecimalPlaces;
    }

    public function getIgnoredUnknownLinesCount(): int
    {
        return $this->ignoredUnknownLinesCount;
    }

    public function getVa1TotalSalesEur(): float
    {
        return $this->va1TotalSalesEur;
    }

    public function getVa1TotalVendUnits(): int
    {
        return $this->va1TotalVendUnits;
    }

    public function getVa1ResetSalesEur(): ?float
    {
        return $this->va1ResetSalesEur;
    }

    public function getVa1ResetVendUnits(): ?int
    {
        return $this->va1ResetVendUnits;
    }

    public function getCa3CashboxEur(): ?float
    {
        return $this->ca3CashboxEur;
    }

    public function getCa3CoinTubesEur(): ?float
    {
        return $this->ca3CoinTubesEur;
    }

    public function getCa3BillsInEur(): ?float
    {
        return $this->ca3BillsInEur;
    }

    public function getPreviousAuditId(): ?int
    {
        return $this->previousAuditId;
    }

    public function isCounterResetDetected(): bool
    {
        return $this->counterResetDetected;
    }

    public function isOutOfOrderSync(): bool
    {
        return $this->outOfOrderSync;
    }

    public function getDeltaSource(): string
    {
        return $this->deltaSource;
    }

    public function getDeltaSalesEur(): ?float
    {
        return $this->deltaSalesEur;
    }

    public function getDeltaVendUnits(): ?int
    {
        return $this->deltaVendUnits;
    }

    public function getClientMutationId(): ?string
    {
        return $this->clientMutationId;
    }

    public function getCapturedAt(): string
    {
        return $this->capturedAt;
    }

    public function getVoidReason(): ?string
    {
        return $this->voidReason;
    }

    public function getVoidedBy(): ?int
    {
        return $this->voidedBy;
    }

    public function getVoidedByNameSnapshot(): ?string
    {
        return $this->voidedByNameSnapshot;
    }

    public function getVoidedAt(): ?string
    {
        return $this->voidedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return list<MachineDexSelectionLine>
     */
    public function getSelections(): array
    {
        return $this->selections;
    }

    /**
     * @return list<MachineDexEventLine>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function isPreviousAuditVoided(): bool
    {
        return $this->previousAuditVoided;
    }

    public function getEffectivePreviousAuditId(): ?int
    {
        return $this->effectivePreviousAuditId;
    }

    public function getEffectiveDeltaSalesEur(): ?float
    {
        return $this->effectiveDeltaSalesEur;
    }

    public function getEffectiveDeltaVendUnits(): ?int
    {
        return $this->effectiveDeltaVendUnits;
    }

    public function getM34CrossCheck(): ?array
    {
        return $this->m34CrossCheck;
    }

    public function getM35CrossCheck(): ?array
    {
        return $this->m35CrossCheck;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isVoided(): bool
    {
        return $this->status->isVoided();
    }

    public function hasThermalAlert(): bool
    {
        foreach ($this->events as $event) {
            if ($event->isThermalAlert()) {
                return true;
            }
        }

        return false;
    }

    public function withId(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador del volcado debe ser un entero positivo.');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Reintenta el alta con el siguiente correlativo anual tras una colisión
     * concurrente de `audit_code` (T-DEX-10). El volcado conserva intactas sus
     * demás columnas; solo cambia el código reservado antes del `INSERT`.
     */
    public function withAuditCode(string $auditCode): self
    {
        $cleanCode = trim($auditCode);
        if (preg_match('/^DEX-[0-9]{4}-[0-9]{4,}$/', $cleanCode) !== 1) {
            throw new InvalidArgumentException('El código del volcado no cumple el formato DEX-AAAA-NNNN.');
        }

        $clone = clone $this;
        $clone->auditCode = $cleanCode;

        return $clone;
    }

    public function withLinkToRestockVisit(int $restockVisitId): self
    {
        return $this->withLink('restockVisitId', $restockVisitId, 'la visita de reposición');
    }

    public function withLinkToCashCollection(int $cashCollectionId): self
    {
        return $this->withLink('cashCollectionId', $cashCollectionId, 'la recaudación de efectivo');
    }

    public function withLinkToIncident(int $incidentId): self
    {
        return $this->withLink('incidentId', $incidentId, 'la incidencia');
    }

    /**
     * Anulación lógica única `ACTIVE` → `VOIDED` (Art. III.3 y V.1).
     */
    public function withVoid(string $voidReason, int $voidedBy, string $voidedByName, string $voidedAt): self
    {
        if (!$this->status->isActive()) {
            throw new DomainException('El volcado ya está anulado: la transición es terminal.');
        }

        $cleanVoidedAt = trim($voidedAt);
        self::assertVoidPayload(
            trim($voidReason),
            $voidedBy,
            trim($voidedByName),
            $cleanVoidedAt,
            $this->capturedAt,
            $this->createdAt
        );

        $clone = clone $this;
        $clone->status = DexAuditStatus::VOIDED;
        $clone->voidReason = trim($voidReason);
        $clone->voidedBy = $voidedBy;
        $clone->voidedByNameSnapshot = trim($voidedByName);
        $clone->voidedAt = $cleanVoidedAt;

        return $clone;
    }

    /**
     * @param list<MachineDexSelectionLine> $selections
     */
    public function withSelections(array $selections): self
    {
        foreach ($selections as $selection) {
            if (!$selection instanceof MachineDexSelectionLine) {
                throw new InvalidArgumentException('Las selecciones del volcado deben ser MachineDexSelectionLine.');
            }
            if ($this->id !== null && $selection->getDexAuditId() !== $this->id) {
                throw new InvalidArgumentException('Una selección no puede pertenecer a otro volcado.');
            }
        }

        $clone = clone $this;
        $clone->selections = array_values($selections);

        return $clone;
    }

    /**
     * @param list<MachineDexEventLine> $events
     */
    public function withEvents(array $events): self
    {
        foreach ($events as $event) {
            if (!$event instanceof MachineDexEventLine) {
                throw new InvalidArgumentException('Los eventos del volcado deben ser MachineDexEventLine.');
            }
            if ($this->id !== null && $event->getDexAuditId() !== $this->id) {
                throw new InvalidArgumentException('Un evento no puede pertenecer a otro volcado.');
            }
        }

        $clone = clone $this;
        $clone->events = array_values($events);

        return $clone;
    }

    /**
     * Delta efectivo calculado en lectura cuando el predecesor fue anulado.
     *
     * `$effectivePreviousAuditId` identifica el último `ACTIVE` anterior; queda
     * `null` cuando no existe y, en ese caso, los deltas también son `null`.
     */
    public function withPreviousAuditVoided(
        bool $previousAuditVoided,
        ?float $effectiveDeltaSalesEur = null,
        ?int $effectiveDeltaVendUnits = null,
        ?int $effectivePreviousAuditId = null
    ): self {
        if (!$previousAuditVoided) {
            $effectivePreviousAuditId = null;
            $effectiveDeltaSalesEur = null;
            $effectiveDeltaVendUnits = null;
        }

        $clone = clone $this;
        $clone->previousAuditVoided = $previousAuditVoided;
        $clone->effectivePreviousAuditId = $effectivePreviousAuditId;
        $clone->effectiveDeltaSalesEur = self::roundMoney($effectiveDeltaSalesEur);
        $clone->effectiveDeltaVendUnits = $effectiveDeltaVendUnits;

        return $clone;
    }

    /**
     * Cruces no destructivos con M34 y M35 calculados en lectura (QA-3).
     */
    public function withCrossChecks(?array $m34CrossCheck, ?array $m35CrossCheck): self
    {
        $clone = clone $this;
        $clone->m34CrossCheck = $m34CrossCheck;
        $clone->m35CrossCheck = $m35CrossCheck;

        return $clone;
    }

    /**
     * Vista del móvil en unidades y eventos (QA-1, QA-3, Art. V.4).
     *
     * `prefill_sales_counter_reading_eur` solo aparece en la respuesta inmediata
     * del `POST` de importación (`$includeImmediatePrefill = true`); el `GET`
     * histórico no recibe un solo importe en euros.
     *
     * @return array<string, mixed>
     */
    public function toRestockerArray(bool $includeImmediatePrefill = false): array
    {
        $projection = [
            'id' => $this->id,
            'audit_code' => $this->auditCode,
            'machine_id' => $this->machineId,
            'machine_code' => $this->machineCodeSnapshot,
            'captured_at' => $this->capturedAt,
            'identity_mismatch_warning' => $this->identityMismatchWarning,
            'counter_reset_detected' => $this->counterResetDetected,
            'va1_total_vend_units' => $this->va1TotalVendUnits,
            'delta_vend_units' => $this->deltaVendUnits,
            'selections' => array_map(
                static fn (MachineDexSelectionLine $selection): array => $selection->toUnitProjection(),
                $this->selections
            ),
            'events' => array_map(
                static fn (MachineDexEventLine $event): array => $event->toProjection(),
                $this->events
            ),
            'has_thermal_alert' => $this->hasThermalAlert(),
            'suggested_escalation_payload' => $this->buildSuggestedEscalationPayload(),
        ];

        if ($includeImmediatePrefill) {
            $projection['prefill_sales_counter_reading_eur'] = $this->va1TotalSalesEur;
        }

        return $projection;
    }

    /**
     * Vista del Técnico SAT: la del historial en unidades, sin prefill ni euros.
     *
     * @return array<string, mixed>
     */
    public function toTechnicianArray(): array
    {
        return $this->toRestockerArray(false);
    }

    /**
     * Auditoría financiera completa, reservada a Coordinación (Art. V.4).
     *
     * @return array<string, mixed>
     */
    public function toCoordinatorArray(): array
    {
        return [
            'id' => $this->id,
            'audit_code' => $this->auditCode,
            'machine_id' => $this->machineId,
            'machine_code' => $this->machineCodeSnapshot,
            'machine_type' => $this->machineTypeSnapshot,
            'imported_by' => $this->importedBy,
            'importer_role' => $this->importerRoleSnapshot,
            'importer_name' => $this->importerNameSnapshot,
            'import_source' => $this->importSource->value,
            'import_source_label' => $this->importSource->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'restock_visit_id' => $this->restockVisitId,
            'cash_collection_id' => $this->cashCollectionId,
            'incident_id' => $this->incidentId,
            'original_filename' => $this->originalFilename,
            'file_size_bytes' => $this->fileSizeBytes,
            'sha256_hash' => $this->sha256Hash,
            'dxs_comm_id' => $this->dxsCommId,
            'dxs_version' => $this->dxsVersion,
            'dex_machine_identifier' => $this->dexMachineIdentifier,
            'identity_mismatch_warning' => $this->identityMismatchWarning,
            'id4_decimal_places' => $this->id4DecimalPlaces,
            'ignored_unknown_lines_count' => $this->ignoredUnknownLinesCount,
            'va1_total_sales_eur' => $this->va1TotalSalesEur,
            'va1_total_vend_units' => $this->va1TotalVendUnits,
            'va1_reset_sales_eur' => $this->va1ResetSalesEur,
            'va1_reset_vend_units' => $this->va1ResetVendUnits,
            'ca3_cashbox_eur' => $this->ca3CashboxEur,
            'ca3_coin_tubes_eur' => $this->ca3CoinTubesEur,
            'ca3_bills_in_eur' => $this->ca3BillsInEur,
            'previous_audit_id' => $this->previousAuditId,
            'counter_reset_detected' => $this->counterResetDetected,
            'out_of_order_sync' => $this->outOfOrderSync,
            'delta_source' => $this->deltaSource,
            'delta_sales_eur' => $this->deltaSalesEur,
            'delta_vend_units' => $this->deltaVendUnits,
            'previous_audit_voided' => $this->previousAuditVoided,
            'effective_previous_audit_id' => $this->effectivePreviousAuditId,
            'effective_delta_sales_eur' => $this->effectiveDeltaSalesEur,
            'effective_delta_vend_units' => $this->effectiveDeltaVendUnits,
            'm34_cross_check' => $this->m34CrossCheck,
            'm35_cross_check' => $this->m35CrossCheck,
            'client_mutation_id' => $this->clientMutationId,
            'captured_at' => $this->capturedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'void_reason' => $this->voidReason,
            'voided_by' => $this->voidedBy,
            'voided_by_name_snapshot' => $this->voidedByNameSnapshot,
            'voided_at' => $this->voidedAt,
            'selections' => array_map(
                static fn (MachineDexSelectionLine $selection): array => $selection->toCoordinatorArray(),
                $this->selections
            ),
            'events' => array_map(
                static fn (MachineDexEventLine $event): array => $event->toCoordinatorArray(),
                $this->events
            ),
            'has_thermal_alert' => $this->hasThermalAlert(),
            'can_download_raw' => true,
            'suggested_escalation_payload' => $this->buildSuggestedEscalationPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toRestockerArray(false);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toRestockerArray(false);
    }

    /**
     * Carga útil determinista para el escalado en un toque (M16/M36, QA-4).
     *
     * @return array<string, mixed>|null
     */
    private function buildSuggestedEscalationPayload(): ?array
    {
        if (!$this->hasThermalAlert()) {
            return null;
        }

        $eventCodes = [];
        foreach ($this->events as $event) {
            if ($event->isThermalAlert()) {
                $eventCodes[$event->getEventCode()] = true;
            }
        }

        return [
            'machine_id' => $this->machineId,
            'dex_audit_id' => $this->id,
            'audit_code' => $this->auditCode,
            'captured_at' => $this->capturedAt,
            'category' => 'TEMPERATURE_COLD',
            'urgency' => 'CRITICAL',
            'dispatch_level' => 'L2_SAT',
            'event_codes' => array_keys($eventCodes),
            'title' => sprintf('Rotura de cadena de frío detectada en %s', $this->machineCodeSnapshot),
            'description' => sprintf(
                'El volcado %s registra %d evento(s) térmico(s) (%s) sobre la máquina %s; procede revisar el equipo y aplicar el protocolo sanitario.',
                $this->auditCode,
                count($eventCodes),
                implode(', ', array_keys($eventCodes)),
                $this->machineCodeSnapshot
            ),
        ];
    }

    private function withLink(string $property, int $targetId, string $label): self
    {
        if (!$this->status->isActive()) {
            throw new DomainException('Un volcado anulado no admite vínculos nuevos.');
        }

        if ($targetId <= 0) {
            throw new InvalidArgumentException('El identificador del vínculo debe ser un entero positivo.');
        }

        if ($this->{$property} !== null) {
            throw new DomainException(sprintf(
                'El volcado ya está vinculado a %s: el enlace solo se fija una vez (Art. III.3).',
                $label
            ));
        }

        $clone = clone $this;
        $clone->{$property} = $targetId;

        return $clone;
    }

    /**
     * Espejo de `chk_machine_dex_audits_delta_coherence` (RF-04.1, C1).
     */
    private static function assertDeltaCoherence(
        string $deltaSource,
        ?int $previousAuditId,
        bool $counterResetDetected,
        ?float $deltaSalesEur,
        ?int $deltaVendUnits
    ): void {
        $valid = match ($deltaSource) {
            'INITIAL_BASELINE' => $previousAuditId === null
                && $counterResetDetected === false
                && $deltaSalesEur === null
                && $deltaVendUnits === null,
            'PREVIOUS_AUDIT' => $previousAuditId !== null
                && $counterResetDetected === false
                && $deltaSalesEur !== null
                && $deltaVendUnits !== null,
            'RESET_FALLBACK' => $previousAuditId !== null
                && $counterResetDetected === true
                && ($deltaSalesEur !== null || $deltaVendUnits !== null),
            'UNAVAILABLE' => $previousAuditId !== null
                && $counterResetDetected === true
                && $deltaSalesEur === null
                && $deltaVendUnits === null,
            default => false,
        };

        if (!$valid) {
            throw new InvalidArgumentException(sprintf(
                'La rama de delta «%s» no es coherente con previous_audit_id, counter_reset_detected ni los deltas persistidos.',
                $deltaSource
            ));
        }
    }

    /**
     * Espejo de `chk_machine_dex_audits_void_coherence` (Art. V.1).
     */
    private static function assertVoidPayload(
        ?string $voidReason,
        ?int $voidedBy,
        ?string $voidedByName,
        ?string $voidedAt,
        string $capturedAt,
        ?string $createdAt
    ): void {
        if ($voidReason === null || $voidedBy === null || $voidedByName === null || $voidedAt === null) {
            throw new InvalidArgumentException(
                'Un volcado VOIDED exige motivo, autor, nombre congelado y fecha de anulación.'
            );
        }

        $length = mb_strlen($voidReason, 'UTF-8');
        if ($length < self::MIN_VOID_REASON_LENGTH || $length > self::MAX_VOID_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El motivo de anulación debe tener entre %d y %d caracteres reales; se recibieron %d.',
                self::MIN_VOID_REASON_LENGTH,
                self::MAX_VOID_REASON_LENGTH,
                $length
            ));
        }

        if (preg_match('/([[:alnum:]]+[^[:alnum:]]+){2,}[[:alnum:]]+/u', $voidReason) !== 1) {
            throw new InvalidArgumentException(
                'El motivo de anulación debe contener al menos tres bloques alfanuméricos reales (Art. V.1).'
            );
        }

        if (trim($voidedByName) === '' || mb_strlen(trim($voidedByName), 'UTF-8') > 150) {
            throw new InvalidArgumentException('El nombre congelado del anulador debe tener entre 1 y 150 caracteres.');
        }

        $voidInstant = self::parseInstant($voidedAt, 'voided_at');
        $reference = self::parseInstant($createdAt ?? $capturedAt, 'created_at');
        if ($voidInstant < $reference) {
            throw new InvalidArgumentException('La anulación no puede ser anterior al alta del volcado.');
        }
    }

    private static function assertSnapshot(string $value, int $maxLength, string $field): string
    {
        $clean = trim($value);
        if ($clean === '' || mb_strlen($clean, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s debe tener entre 1 y %d caracteres.',
                $field,
                $maxLength
            ));
        }

        return $clean;
    }

    private static function assertUnits(?int $units, string $field): void
    {
        if ($units !== null && ($units < 0 || $units > self::MAX_UNITS)) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s debe estar entre 0 y %d; se recibió %d.',
                $field,
                self::MAX_UNITS,
                $units
            ));
        }
    }

    private static function assertMoney(?float $amount, float $ceiling, string $field): void
    {
        if ($amount !== null && ($amount < 0.0 || $amount > $ceiling)) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s debe estar entre 0,00 y %.2f €; se recibió %.2f €.',
                $field,
                $ceiling,
                $amount
            ));
        }
    }

    private static function parseInstant(string $value, string $field): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s no es una fecha interpretable: %s.',
                $field,
                $exception->getMessage()
            ), 0, $exception);
        }
    }

    private static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }

    private static function roundMoney(?float $amount): ?float
    {
        return $amount === null ? null : round($amount, 2);
    }
}
