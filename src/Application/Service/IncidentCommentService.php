<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Application\DTO\IncidentCommentItemDto;
use VendGuard\Application\DTO\IncidentCommentThreadDto;
use VendGuard\Core\Domain\Exception\ConversationSealedException;
use VendGuard\Core\Domain\Exception\InvalidCommentLengthException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Storage\LocalFileUploader;

/**
 * IncidentCommentService
 *
 * Orquestación de negocio del hilo de comentarios bidireccional con notas
 * internas confidenciales (Módulo 10, T-COM-03).
 *
 * Responsabilidades (spec.md RF-02, RF-03, RF-04, RF-05, RF-06):
 * - Segregación estricta en servidor: ante la Sede las notas internas jamás
 *   llegan al DTO (Art. V.4 / RNF-01).
 * - Enmascaramiento de identidad técnica ante la Sede: "Servicio Técnico
 *   Oficial (Operador #XX)" y "Coordinación Central de Operaciones" (RF-02.2).
 * - Identidad nominal completa para Técnicos y Coordinadores (RF-02.3).
 * - Validación de longitud (5 a 1.000 caracteres descriptivos, RF-03.1).
 * - Máquina de estados del sellado (RF-05): estados activos y RESOLVED en
 *   ventana de garantía de 48 h admiten mensajes; CLOSED/CANCELLED y
 *   RESOLVED con ventana vencida quedan sellados (Art. V.6).
 * - Forzado fail-safe de la clasificación: Sede siempre público (RF-03.2);
 *   Técnico/Coordinador con nota interna por defecto (RF-03.3).
 * - Evidencias fotográficas delegadas en LocalFileUploader (Art. V.5, RF-04.2).
 * - Auditoría inmutable INCIDENT_COMMENT_ADDED (RF-06.3 / Art. III).
 *
 * Dogma Vanilla: PHP 8.2+ estricto, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
class IncidentCommentService
{
    /** Límite mínimo de caracteres descriptivos por mensaje (RF-03.1). */
    public const MIN_COMMENT_LENGTH = 5;

    /** Límite máximo de caracteres descriptivos por mensaje (RF-03.1). */
    public const MAX_COMMENT_LENGTH = 1000;

    /** Ventana de garantía post-resolución que mantiene el diálogo abierto (Art. V.6). */
    public const WARRANTY_WINDOW_HOURS = 48;

    /**
     * Motivo de solo lectura del hilo (RF-05.4): el expediente se reabrió en garantía,
     * la desasignación obligatoria lo devolvió a triaje y el técnico que intervino
     * conserva la consulta pero no la publicación hasta que coordinación lo reasigne.
     */
    public const READ_ONLY_REOPENED_AWAITING_REASSIGNMENT = 'REOPENED_AWAITING_REASSIGNMENT';

    /** Bloque por defecto del cursor de paginación (RF-01.2). */
    public const DEFAULT_THREAD_LIMIT = 50;

    /** Máximo contractual del tamaño de bloque por petición (plan.md §2.1). */
    public const MAX_THREAD_LIMIT = 100;

    private const ROLE_SITE_MANAGER = 'SITE_MANAGER';
    private const ROLE_TECHNICIAN = 'TECHNICIAN';
    private const AUTHOR_TYPE_TECHNICIAN = 'TECHNICIAN';
    private const AUTHOR_TYPE_COORDINATOR = 'COORDINATOR';
    private const AUTHOR_TYPE_REPORTER = 'REPORTER';

    private const TECHNICIAN_MASK_PATTERN = 'Servicio Técnico Oficial (Operador #%02d)';
    private const COORDINATOR_MASK = 'Coordinación Central de Operaciones';

    private IncidentRepositoryInterface $incidentRepo;
    private AuditLogger $auditLogger;
    private LocalFileUploader $fileUploader;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?AuditLogger $auditLogger = null,
        ?LocalFileUploader $fileUploader = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new \VendGuard\Infrastructure\Repository\PdoIncidentRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->fileUploader = $fileUploader ?? new LocalFileUploader();
    }

    /**
     * Construye el hilo de conversación del expediente para el perfil llamante.
     *
     * @param int|string $identifier ID numérico de la incidencia o código de ticket (con o sin '#').
     * @param string $role Rol del llamante: 'SITE_MANAGER', 'TECHNICIAN' o 'COORDINATOR'.
     * @param int|null $authUserId ID del usuario interno autenticado (null para la Sede).
     * @param int $limit Tamaño de bloque solicitado (recortado al máximo contractual).
     * @param int|null $beforeId Cursor de paginación retrospectiva (ID del mensaje más antiguo cargado).
     *
     * @throws \DomainException Si el expediente no existe o está borrado lógicamente.
     */
    public function getThread(
        int|string $identifier,
        string $role,
        ?int $authUserId = null,
        int $limit = self::DEFAULT_THREAD_LIMIT,
        ?int $beforeId = null
    ): IncidentCommentThreadDto {
        $detail = $this->incidentRepo->findEnrichedDetailById($identifier);
        if ($detail === null) {
            throw new \DomainException('La incidencia solicitada no existe.', 404);
        }

        $incidentRow = $detail['incident'];
        $machineRow = $detail['machine'] ?? null;
        $locationRow = $detail['location'] ?? null;

        $isSiteManager = $this->isSiteManager($role);
        $includeInternal = !$isSiteManager;

        // Cargamos una unidad extra para calcular `has_more_before` sin una
        // segunda consulta: si viene el mensaje extra, hay histórico previo.
        // El sobrante es el mensaje MÁS ANTIGUO de la ventana (el repositorio
        // devuelve siempre el bloque más reciente), así que se descarta por la
        // izquierda para conservar los `limit` mensajes más recientes (RF-01.2).
        $fetchLimit = min(max($limit, 1), self::MAX_THREAD_LIMIT);
        $comments = $this->incidentRepo->getCommentsPaged(
            (int)$incidentRow['id'],
            $includeInternal,
            $fetchLimit + 1,
            $beforeId
        );
        $hasMoreBefore = count($comments) > $fetchLimit;
        if ($hasMoreBefore) {
            array_shift($comments);
        }

        $projected = [];
        foreach ($comments as $comment) {
            $projected[] = $isSiteManager
                ? $this->projectForSite($comment)
                : $this->projectForInternal($comment, $authUserId);
        }

        // Recuentos segregados para las insignias de las tarjetas (RF-01.1):
        // la Sede solo conoce el recuento público; el equipo interno, el total.
        $countedComments = $this->incidentRepo->countComments((int)$incidentRow['id'], $includeInternal);
        $totalComments = $this->incidentRepo->countComments((int)$incidentRow['id'], true);

        $commentIds = array_map(static fn(IncidentComment $c): int => (int)$c->getId(), $comments);

        // Permiso efectivo de publicación (RF-05.1 a RF-05.4): el sellado cierra la
        // conversación para todos los perfiles y, además, el técnico de ruta solo publica
        // en los expedientes que tiene asignados. Un técnico con antecedentes que consulta
        // un expediente reabierto y aún sin reasignar recibe el hilo en modo de solo
        // lectura, con el motivo explícito para que la interfaz no muestre un formulario
        // condenado a un 403.
        $statusEnum = IncidentStatus::tryFrom((string)($incidentRow['status'] ?? ''));
        $isTechnicianChannel = strtoupper($role) === self::ROLE_TECHNICIAN;
        $acceptsComments = $this->acceptsNewComments($incidentRow);
        $isAssignedTechnician = $authUserId !== null
            && (int)($incidentRow['assigned_technician_id'] ?? 0) === $authUserId;
        $canComment = $acceptsComments && (!$isTechnicianChannel || $isAssignedTechnician);
        $readOnlyReason = (!$canComment && $acceptsComments && $statusEnum === IncidentStatus::REOPENED)
            ? self::READ_ONLY_REOPENED_AWAITING_REASSIGNMENT
            : null;

        return new IncidentCommentThreadDto(
            incident: [
                'id' => (int)$incidentRow['id'],
                'ticket_code' => (string)($incidentRow['ticket_code'] ?? ''),
                'machine_code' => (string)($machineRow['code'] ?? ''),
                'machine_model' => (string)($machineRow['model'] ?? ''),
                'location_name' => (string)($locationRow['name'] ?? ''),
                'status' => (string)($incidentRow['status'] ?? ''),
                'status_label' => $this->statusLabel($incidentRow['status'] ?? ''),
                'is_sealed' => !$acceptsComments,
                'can_comment' => $canComment,
                'read_only_reason' => $readOnlyReason,
            ],
            pagination: [
                // Ante la Sede el total transmitido es el recuento público:
                // jamás se expone cifra alguna que contabilice notas internas (RNF-01).
                'total_comments' => $isSiteManager ? $countedComments : $totalComments,
                'loaded_count' => count($projected),
                'has_more_before' => $hasMoreBefore,
                'oldest_id' => $commentIds === [] ? null : min($commentIds),
                'latest_id' => $commentIds === [] ? null : max($commentIds),
            ],
            comments: $projected
        );
    }

    /**
     * Publica un comentario o nota interna en el hilo del expediente.
     *
     * @param int|string $identifier ID numérico de la incidencia o código de ticket (con o sin '#').
     * @param string $role Rol del llamante ('SITE_MANAGER', 'TECHNICIAN', 'COORDINATOR').
     * @param string $commentText Texto del mensaje (5 a 1.000 caracteres descriptivos).
     * @param array{id: int|null, role: string, name: string}|null $author Datos del autor interno (null para Sede).
     * @param bool|null $isInternal Clasificación solicitada; null aplica el valor fail-safe del rol.
     * @param array<string, mixed>|null $photo Array estilo $_FILES con la fotografía opcional.
     *
     * @return IncidentCommentThreadDto Hilo actualizado tras la publicación (RF-03.4).
     *
     * @throws InvalidCommentLengthException Si el texto no cumple los límites (RF-03.1).
     * @throws ConversationSealedException Si el expediente está sellado (RF-05.3, RF-07.2).
     * @throws \VendGuard\Core\Domain\Exception\InvalidUploadException Si la imagen no supera la validación binaria (Art. V.5).
     */
    public function addComment(
        int|string $identifier,
        string $role,
        string $commentText,
        ?array $author = null,
        ?bool $isInternal = null,
        ?array $photo = null
    ): IncidentCommentThreadDto {
        // 1. Normalización y validación temprana del texto (fail-fast, RF-03.1).
        $trimmedText = trim($commentText);
        $textLength = mb_strlen($trimmedText);
        if ($textLength < self::MIN_COMMENT_LENGTH || $textLength > self::MAX_COMMENT_LENGTH) {
            throw new InvalidCommentLengthException();
        }

        // 2. Resolución del expediente (404 si no existe o está borrado lógicamente).
        $detail = $this->incidentRepo->findEnrichedDetailById($identifier);
        if ($detail === null) {
            throw new \DomainException('La incidencia solicitada no existe.', 404);
        }
        $incidentRow = $detail['incident'];
        $incidentId = (int)$incidentRow['id'];

        // 3. Máquina de estados del sellado (RF-05.1, RF-05.2, RF-05.3, RF-07.2).
        if (!$this->acceptsNewComments($incidentRow)) {
            throw new ConversationSealedException();
        }

        // 4. Clasificación fail-safe del mensaje (RF-03.2, RF-03.3):
        //    la Sede jamás publica interno; el equipo interno, por defecto, sí.
        $isSiteManager = $this->isSiteManager($role);
        $effectiveInternal = $isSiteManager
            ? false
            : ($isInternal ?? true);

        // 5. Evidencia fotográfica opcional con validación binaria en servidor
        //    (Art. V.5 / RF-04.2): tamaño ≤ 5 MB y magic bytes reales.
        $photoPath = null;
        if ($photo !== null && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photoPath = $this->fileUploader->upload($photo);
        }

        // 6. Autoría e identidad visible según el perfil (RF-02.2, RF-02.3).
        if ($isSiteManager) {
            $authorType = self::AUTHOR_TYPE_REPORTER;
            $authorUserId = null;
            $authorName = $this->siteAuthorName($detail);
        } else {
            $authorType = $role === self::ROLE_SITE_MANAGER
                ? self::AUTHOR_TYPE_REPORTER
                : strtoupper($role);
            $authorUserId = (int)($author['id'] ?? 0);
            $authorName = (string)($author['name'] ?? '');
            if ($authorUserId === 0 || $authorName === '') {
                throw new \InvalidArgumentException('Se requieren los datos del autor interno para publicar en el hilo.');
            }
        }

        // 7. Persistencia append-only del mensaje (Art. III, RF-06.1, RF-06.2).
        $comment = $this->incidentRepo->addComment(new IncidentComment(
            id: null,
            incidentId: $incidentId,
            authorType: $authorType,
            userId: $authorUserId > 0 ? $authorUserId : null,
            authorName: $authorName,
            commentText: $trimmedText,
            photoPath: $photoPath,
            isInternal: $effectiveInternal,
            createdAt: date('Y-m-d H:i:s'),
            ticketCode: (string)($incidentRow['ticket_code'] ?? '')
        ));

        // 8. Evento inmutable de auditoría (RF-06.3 / Art. III.3).
        $this->auditLogger->logTicketEvent(
            $incidentId,
            'INCIDENT_COMMENT_ADDED',
            [
                'id' => $authorUserId > 0 ? $authorUserId : null,
                'role' => $isSiteManager ? self::ROLE_SITE_MANAGER : strtoupper($role),
                'name' => $authorName,
            ],
            null,
            [
                'comment_id' => $comment->getId(),
                'ticket_code' => $incidentRow['ticket_code'] ?? '',
                'is_internal' => $effectiveInternal,
                'has_photo' => $photoPath !== null,
            ]
        );

        // 9. Hilo actualizado para el perfil llamante (RF-03.4).
        return $this->getThread($incidentId, $role, $authorUserId > 0 ? $authorUserId : null);
    }

    /**
     * Máquina de estados del sellado de la conversación (plan.md §3.3, RF-05).
     *
     * PuedeComentar = estado activo (REPORTED/ASSIGNED/IN_PROGRESS/PENDING_PARTS/
     *                 PENDING_INFO/REOPENED) o (RESOLVED y Δt desde la resolución ≤ 48 h).
     *
     * PENDING_INFO admite comentarios por diseño: la respuesta de la sede es el
     * mecanismo mismo de desbloqueo (RF-05.1/05.2) y las notas internas del taller
     * no alteran la pausa (spec §6.5). El sellado estricto solo aplica a los
     * estados terminales CLOSED/CANCELLED (RF-05.4, Art. III).
     *
     * @param array<string, mixed> $incidentRow
     */
    public function acceptsNewComments(array $incidentRow): bool
    {
        $status = IncidentStatus::tryFrom((string)($incidentRow['status'] ?? ''));
        if ($status === null) {
            return false;
        }

        return match ($status) {
            IncidentStatus::REGISTERED,
            IncidentStatus::ASSIGNED,
            IncidentStatus::IN_PROGRESS,
            IncidentStatus::PENDING_PARTS,
            IncidentStatus::PENDING_INFO,
            IncidentStatus::REOPENED => true,
            IncidentStatus::CLOSED,
            IncidentStatus::CANCELLED => false,
            IncidentStatus::RESOLVED => $this->isWithinWarrantyWindow($incidentRow['resolved_at'] ?? null),
        };
    }

    /**
     * Ventana de garantía de 48 h desde la resolución (RF-05.2 / Art. V.6).
     */
    public function isWithinCommentWindow(?string $resolvedAt): bool
    {
        return $this->isWithinWarrantyWindow($resolvedAt);
    }

    private function isWithinWarrantyWindow(?string $resolvedAt): bool
    {
        if ($resolvedAt === null || $resolvedAt === '') {
            // Resuelta sin marca temporal: el sellado por garantía no es
            // calculable, así que se aplica el criterio conservador del
            // fail-safe defaults y se mantiene el diálogo abierto.
            return true;
        }

        $resolvedTimestamp = strtotime($resolvedAt);
        if ($resolvedTimestamp === false) {
            return true;
        }

        return (time() - $resolvedTimestamp) <= self::WARRANTY_WINDOW_HOURS * 3600;
    }

    /**
     * Proyección pública para el perfil de Sede (RF-02.1, RF-02.2, RNF-01):
     * excluye por completo las notas internas y enmascara la identidad de
     * técnicos y coordinadores antes de construir el DTO.
     */
    private function projectForSite(IncidentComment $comment): IncidentCommentItemDto
    {
        // Barrera dura en servidor: una nota interna jamás alcanza el DTO de Sede.
        if ($comment->isInternal()) {
            throw new ConversationSealedException(
                'INTERNAL_COMMENT_LEAK',
                'Se detectó una nota interna en la proyección pública de la Sede.',
                500
            );
        }

        return new IncidentCommentItemDto(
            id: (int)$comment->getId(),
            authorType: $comment->getAuthorType(),
            authorName: $this->maskedAuthorNameForSite($comment),
            commentText: $comment->getCommentText(),
            photoUrl: $comment->getPhotoPath(),
            createdAt: $comment->getCreatedAt(),
            isOwnMessage: $comment->getAuthorType() === self::AUTHOR_TYPE_REPORTER,
            isInternal: null
        );
    }

    /**
     * Proyección nominal completa para Técnicos y Coordinadores
     * (RF-02.3, RF-02.4): identidad real y candado de confidencialidad.
     */
    private function projectForInternal(IncidentComment $comment, ?int $authUserId): IncidentCommentItemDto
    {
        return new IncidentCommentItemDto(
            id: (int)$comment->getId(),
            authorType: $comment->getAuthorType(),
            authorName: $comment->getAuthorName(),
            commentText: $comment->getCommentText(),
            photoUrl: $comment->getPhotoPath(),
            createdAt: $comment->getCreatedAt(),
            isOwnMessage: $authUserId !== null && $comment->getUserId() === $authUserId,
            isInternal: $comment->isInternal()
        );
    }

    /**
     * Enmascaramiento oficial ante la Sede (RF-02.2 / Art. V.4).
     */
    private function maskedAuthorNameForSite(IncidentComment $comment): string
    {
        return match ($comment->getAuthorType()) {
            self::AUTHOR_TYPE_TECHNICIAN => sprintf(
                self::TECHNICIAN_MASK_PATTERN,
                $comment->getUserId() ?? 0
            ),
            self::AUTHOR_TYPE_COORDINATOR => self::COORDINATOR_MASK,
            default => $comment->getAuthorName(),
        };
    }

    /**
     * Nombre visible de la Sede como autora de sus mensajes.
     *
     * @param array<string, mixed> $detail
     */
    private function siteAuthorName(array $detail): string
    {
        $locationName = (string)($detail['location']['name'] ?? '');
        return $locationName !== ''
            ? 'Responsable de Sede · ' . $locationName
            : 'Responsable de Sede';
    }

    private function isSiteManager(string $role): bool
    {
        return strtoupper($role) === self::ROLE_SITE_MANAGER;
    }

    /**
     * Etiqueta legible del estado para la cabecera del modal (RF-01.4).
     */
    private function statusLabel(string $status): string
    {
        try {
            return IncidentStatus::from($status)->label();
        } catch (\ValueError) {
            return $status;
        }
    }
}
