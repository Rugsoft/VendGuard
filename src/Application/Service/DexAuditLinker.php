<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Domain\Repository\MachineDexAuditRepositoryInterface;

/**
 * DexAuditLinker
 *
 * Resuelve el enlace opcional `dex_audit_id` de M34/M35/M36 (Módulo 39,
 * RF-04.3 y RF-05.2; D1, D4, D5, C5 y QA-5).
 *
 * **Validación explícita y ordenada.** Un identificador ausente no es un
 * vínculo; uno presente debe ser un entero positivo (`422
 * DEX_AUDIT_ID_INVALID`), existir (`404 DEX_AUDIT_NOT_FOUND`), pertenecer a la
 * misma máquina (`422 DEX_AUDIT_MACHINE_MISMATCH`), seguir `ACTIVE` (`409
 * DEX_AUDIT_VOIDED`) y no estar enlazado a otro registro del mismo tipo (`409
 * DEX_AUDIT_ALREADY_LINKED`). Repetir el mismo destino es idempotente.
 *
 * **Solo vínculos.** La escritura de negocio toca exclusivamente
 * `restock_visit_id`, `cash_collection_id` o `incident_id` de
 * `machine_dex_audits`; no altera DDL, existencias, `current_estimated_qty` ni
 * la captura inmutable. El enlace deja además su rastro en `audit_log` bajo
 * `MACHINE_DEX_AUDIT` (Art. III.3). El repositorio bloquea la cabecera con
 * `FOR UPDATE` y los índices únicos parciales cierran la carrera entre dos
 * peticiones.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class DexAuditLinker
{
    public const TARGET_RESTOCK_VISIT = 'restock_visit';
    public const TARGET_CASH_COLLECTION = 'cash_collection';
    public const TARGET_INCIDENT = 'incident';

    private const TARGET_COLUMNS = [
        self::TARGET_RESTOCK_VISIT => 'restock_visit_id',
        self::TARGET_CASH_COLLECTION => 'cash_collection_id',
        self::TARGET_INCIDENT => 'incident_id',
    ];

    public function __construct(
        private readonly MachineDexAuditRepositoryInterface $audits,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    /**
     * Lee `dex_audit_id` del cuerpo: `null` si no viaja y `422` si no es un entero positivo.
     */
    public static function parseOptionalId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int)trim($value);
        }

        throw new DexAuditValidationException(
            'El dex_audit_id debe ser un entero positivo.',
            'DEX_AUDIT_ID_INVALID',
            ['field' => 'dex_audit_id', 'value' => $value],
            422
        );
    }

    /**
     * Comprueba existencia, máquina y estado sin exigir un destino concreto.
     *
     * @return array{id:int, machine_id:int, status:string, restock_visit_id:int|null, cash_collection_id:int|null, incident_id:int|null}
     */
    public function assertCompatible(int $auditId, int $machineId): array
    {
        if ($auditId <= 0) {
            throw new DexAuditValidationException(
                'El dex_audit_id debe ser un entero positivo.',
                'DEX_AUDIT_ID_INVALID',
                ['field' => 'dex_audit_id', 'value' => $auditId],
                422
            );
        }

        $audit = $this->audits->findLinkTargetForUpdate($auditId);
        if ($audit === null) {
            throw new DexAuditValidationException(
                'El volcado .dex indicado no existe.',
                'DEX_AUDIT_NOT_FOUND',
                ['dex_audit_id' => $auditId],
                404
            );
        }

        if ((int)$audit['machine_id'] !== $machineId) {
            throw new DexAuditValidationException(
                'El volcado .dex pertenece a otra máquina.',
                'DEX_AUDIT_MACHINE_MISMATCH',
                ['dex_audit_id' => $auditId, 'machine_id' => $machineId, 'audit_machine_id' => (int)$audit['machine_id']],
                422
            );
        }

        if (strtoupper((string)$audit['status']) !== 'ACTIVE') {
            throw new DexAuditValidationException(
                'El volcado .dex está anulado y no admite vínculos.',
                'DEX_AUDIT_VOIDED',
                ['dex_audit_id' => $auditId, 'status' => (string)$audit['status']],
                409
            );
        }

        return $audit;
    }

    /**
     * Valida que el volcado pueda enlazarse al destino indicado (QA-5).
     *
     * @param self::TARGET_* $target
     */
    public function assertLinkable(int $auditId, int $machineId, string $target, int $targetId): array
    {
        if (!isset(self::TARGET_COLUMNS[$target])) {
            throw new \InvalidArgumentException('El tipo de vínculo del volcado no es válido.');
        }

        if ($targetId <= 0) {
            throw new DexAuditValidationException(
                'El registro operativo que se quiere enlazar no es válido.',
                'DEX_AUDIT_TARGET_INVALID',
                ['target' => $target, 'target_id' => $targetId],
                422
            );
        }

        $audit = $this->assertCompatible($auditId, $machineId);
        $column = self::TARGET_COLUMNS[$target];
        $current = $audit[$column];
        if ($current !== null && (int)$current !== $targetId) {
            throw new DexAuditValidationException(
                'El volcado .dex ya está vinculado a otro registro operativo.',
                'DEX_AUDIT_ALREADY_LINKED',
                ['dex_audit_id' => $auditId, 'target' => $target, 'target_id' => $targetId, 'linked_to' => (int)$current],
                409
            );
        }

        return $audit;
    }

    /**
     * @param array{id: int|null, role: string, name: string}|null $actor
     */
    public function linkToRestockVisit(
        int $auditId,
        int $machineId,
        int $restockVisitId,
        ?array $actor = null
    ): void {
        $this->link($auditId, $machineId, self::TARGET_RESTOCK_VISIT, $restockVisitId, $actor);
    }

    /**
     * @param array{id: int|null, role: string, name: string}|null $actor
     */
    public function linkToCashCollection(
        int $auditId,
        int $machineId,
        int $cashCollectionId,
        ?array $actor = null
    ): void {
        $this->link($auditId, $machineId, self::TARGET_CASH_COLLECTION, $cashCollectionId, $actor);
    }

    /**
     * @param array{id: int|null, role: string, name: string}|null $actor
     */
    public function linkToIncident(
        int $auditId,
        int $machineId,
        int $incidentId,
        ?array $actor = null
    ): void {
        $this->link($auditId, $machineId, self::TARGET_INCIDENT, $incidentId, $actor);
    }

    /**
     * @param self::TARGET_* $target
     * @param array{id: int|null, role: string, name: string}|null $actor
     */
    private function link(
        int $auditId,
        int $machineId,
        string $target,
        int $targetId,
        ?array $actor = null
    ): void {
        $audit = $this->assertLinkable($auditId, $machineId, $target, $targetId);
        $column = self::TARGET_COLUMNS[$target];
        if ($audit[$column] !== null) {
            return;
        }

        match ($target) {
            self::TARGET_RESTOCK_VISIT => $this->audits->linkToRestockVisit($auditId, $targetId),
            self::TARGET_CASH_COLLECTION => $this->audits->linkToCashCollection($auditId, $targetId),
            self::TARGET_INCIDENT => $this->audits->linkToIncident($auditId, $targetId),
        };

        if ($this->auditLogger === null || $actor === null || (int)($actor['id'] ?? 0) <= 0) {
            return;
        }

        $this->auditLogger->logMachineDexAuditEvent(
            $auditId,
            'DEX_AUDIT_LINKED',
            $actor,
            [$column => null],
            [$column => $targetId],
            [
                'audit_code' => (string)($audit['audit_code'] ?? ''),
                'machine_id' => $machineId,
                'target' => $target,
                'target_id' => $targetId,
            ]
        );
    }
}
