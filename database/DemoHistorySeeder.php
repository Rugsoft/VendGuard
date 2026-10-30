<?php

declare(strict_types=1);

namespace VendGuard\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * DemoHistorySeeder — Histórico de averías *atendidas* para probar las estadísticas.
 *
 * QUÉ ES
 *   Un sembrador de demostración que escribe un histórico de averías ya atendidas
 *   (alta → asignación → intervención → resolución → cierre) con su traza de
 *   estados (`incident_history`) y su rastro de auditoría (`audit_log`), para que
 *   el cuadro de mandos de Métricas, el listado de averías y el detalle del
 *   coordinador tengan datos con los que probar.
 *
 * QUÉ HISTORIAS CUENTA (y por qué esas)
 *   · Trece averías completadas (`CLOSED`): llenan las series temporales del cuadro
 *     de mandos y dejan la máquina libre para el siguiente aviso.
 *   · Cuatro averías **en garantía** (`RESOLVED`, sin cierre): resueltas dentro de
 *     las 48 h, todavía con su técnico asignado y con la sede aún con derecho a
 *     reabrir (Art. V.6). Son el escenario con el que se prueba el estado «en
 *     garantía» del listado y su cuenta atrás.
 *   · Dos de ellas **reabrieron de verdad**: la traza muestra la resolución, la
 *     reapertura justificada por la sede, la reasignación y la nueva resolución
 *     —la reincidencia que el desglose por técnico cuenta en `warranty_reopens`
 *     (EARS 2.3.1)—. Una de las dos ya cerró al expirar su garantía; la otra sigue
 *     en garantía y espera una segunda reapertura.
 *
 * POR QUÉ NO REUTILIZA `DemoMetricsSeeder`
 *   Aquel sembrador es el *fixture local del arnés*: su SQL está escrito en el
 *   dialecto del motor local (acentos graves y `ON DUPLICATE KEY UPDATE`
 *   literales) y el inventario de dialecto privativo lo declara así a propósito
 *   (`tests/unit/SqlDialectPortabilityTest.php`, grupo 5). Este fichero nace para
 *   el motor nuevo (PostgreSQL en Supabase, que es el despliegue vigente) y por eso
 *   no escribe ni un constructo privativo: los identificadores van desnudos y la
 *   cláusula de upsert la renderiza el adaptador de dialecto (T-HYBRID-25), que es
 *   el punto único de traducción (EARS-01.5). La misma clase sirve, por tanto, a
 *   los dos motores.
 *
 * CUÁNDO SE EJECUTA — Y CUÁNDO NO
 *   **En cada arranque del despliegue**, como paso [6/6] del inicializador: las
 *   diecisiete averías se reafirman en cada redespliegue sin depender de que nadie
 *   recuerde declarar una variable (EARS-02.6). La puerta sigue existiendo, ahora
 *   como *opt-out*: `VENDGUARD_DEMO_HISTORY=0` en el entorno del servicio deja el
 *   arranque en limpio estricto que exige la especificación.
 *
 *   También se ejecuta a mano, cuando el operador quiere reafirmarlo sin arrancar:
 *
 *       php bin/seed_demo_history.php
 *
 * CÓMO SIGUE SIENDO COHERENTE EN CADA PASADA (EARS-02.6)
 *   Las fechas del fixture son relativas —«hace tres días», «ayer a las 21:30»— y se
 *   materializan al sembrar, así que cada pasada *mueve* el expediente hacia adelante.
 *   La traza y la auditoría son inmutables por diseño (Art. III.1 y III.3: nada se
 *   borra), de modo que una pasada que moviera el expediente y dejara su traza anclada
 *   en la fecha anterior dejaría la historia **antes** del alta que cuenta, y el detalle
 *   del coordinador leería el orden al revés. Por eso la pasada que reafirma un
 *   expediente **reafirma también sus marcas**: el mismo hecho, con la misma traza,
 *   desplazado en bloque al esquema temporal vigente.
 *
 *   Y no todo vale para hacerlo:
 *     · **Nunca un borrado.** Ni `DELETE` ni `TRUNCATE`: la reafirmación es un `UPDATE`
 *       fila a fila y cada fila conserva su identidad. La señal de purga es del arnés y la
 *       aplicación no la activa jamás (T-HYBRID-12 y T-HYBRID-26), así que este camino no la
 *       usa ni la nombra.
 *     · **Sólo la traza del fixture.** Se reafirma el expediente cuya traza almacenada
 *       *es* la del fixture —mismo número de eventos, mismas transiciones con los mismos
 *       actores y mismas acciones de auditoría, en el mismo orden—. En cuanto la traza
 *       almacenada deja de ser ésa (la sede reabrió la avería desde el portal, el
 *       coordinador la canceló, el fixture cambió de forma), ese expediente no se toca.
 *     · **Sólo expedientes de demostración.** Todo lo que este sembrador escribe o
 *       reafirma cuelga de sus propios `ticket_code` (`INC-DEMO-11xx`/`12xx`): la traza
 *       de una avería real no está a su alcance.
 *     · **El parque manda.** Una avería viva del fixture ocupa una máquina viva: si esa
 *       máquina ya está ocupada por otro expediente vivo —un aviso real de la
 *       demostración—, el fixture no se inventa el sitio. Se omite, se cuenta y se
 *       declara.
 *
 * REGLAS QUE RESPETA (y que la suite unitaria certifica sin base de datos)
 *   · Una sola incidencia viva por máquina: las cuatro averías en garantía ocupan
 *     máquinas distintas entre sí y sin otro expediente abierto, de modo que el
 *     fixture nunca fuerza el índice único parcial `uq_machine_active_ticket`
 *     (Art. V.2 y V.6). Las completadas sí repiten máquina con las completadas:
 *     cuentan la historia de un parque que vuelve a caer y se cierra bien.
 *   · Diagnóstico y solución superan los 20 caracteres reales que exige
 *     `CHECK` en los dos motores (Art. V.1).
 *   · Las marcas temporales son coherentes y monótonas: alta < asignación <
 *     inicio < resolución < cierre, y el cierre cabe dentro de la ventana de
 *     garantía de 48 h (Art. V.6). En una avería reabierta la monotonía se
 *     reinicia desde la reapertura —resolución < reapertura < reasignación <
 *     reinicio < nueva resolución— y la reapertura cae siempre dentro de las 48 h
 *     posteriores a la resolución que reabre: es lo único que el motor de estados
 *     admite (EARS 9.2).
 *   · Cada máquina y cada técnico del fixture existen en el catálogo maestro
 *     (`database/supabase_seeds.sql`): el histórico no inventa parque.
 *   · Es idempotente: reejecutarlo reafirma las mismas filas por `ticket_code`
 *     y no duplica trazas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class DemoHistorySeeder
{
    /** Zona horaria oficial del parque de máquinas (EARS-09.2). */
    public const PARK_TIMEZONE = 'Europe/Madrid';

    /**
     * Variable de entorno que gobierna el histórico de demostración en el arranque.
     *
     * Es la mitad *opt-out* de la política (EARS-02.6): el despliegue vigente siembra
     * el histórico **siempre**, en cada arranque, y el operador conserva la puerta para
     * apagarlo cuando quiera un arranque en limpio estricto declarando
     * `VENDGUARD_DEMO_HISTORY=0`. El «no» también se reconoce como `false`, `off` o
     * `no`, sin distinguir mayúsculas.
     */
    public const ENVIRONMENT_FLAG = 'VENDGUARD_DEMO_HISTORY';

    /** Formas en que el entorno declara el «no» del histórico de demostración. */
    private const ENVIRONMENT_FALSY = ['0', 'false', 'off', 'no'];

    /** Longitud mínima real de un diagnóstico o una solución (Art. V.1). */
    public const MIN_JUSTIFICATION_LENGTH = 20;

    /** Ventana de garantía de 48 h entre la resolución y el cierre (Art. V.6). */
    public const WARRANTY_WINDOW_MINUTES = 2880;

    /**
     * Antigüedad mínima de una avería **completada** del fixture, en días.
     *
     * No es un adorno: el cierre se sitúa dentro de la ventana de garantía de
     * 48 h, así que una avería resuelta «ayer» cerraría en el futuro. Se exige
     * que ninguna quede por debajo de tres días para que el histórico sea
     * siempre físicamente posible, se ejecute el día que se ejecute.
     */
    public const MIN_DAY_OFFSET = -3;

    /**
     * Antigüedad mínima de una avería **en garantía** del fixture, en días.
     *
     * No cierra, así que la ventana de cierre no la limita; lo que la limita es la
     * otra: tiene que haber resuelto ya —una resolución futura no es una avería— y
     * seguir dentro de las 48 h de garantía cuando se siembra, que es lo que la hace
     * reabrible desde el portal de la sede. Un día cumple las dos cosas, se ejecute
     * el sembrador a la hora que se ejecute: a las 00:10 la avería de «ayer a las
     * 21:30» queda dos horas atrás, y a las 23:50 queda veintiséis horas atrás.
     */
    public const MIN_IN_WARRANTY_DAY_OFFSET = -1;

    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dialect = SqlDialectAdapter::forConnection($pdo);
    }

    /**
     * ¿Hay que sembrar el histórico de demostración en este arranque?
     *
     * El valor por defecto es **sí** (EARS-02.6): el despliegue vigente carga las
     * diecisiete averías en cada redespliegue sin que nadie tenga que recordar una
     * variable, y sólo una declaración explícita de «no» lo apaga. La regla vive aquí,
     * en un único sitio, para que el arranque del contenedor y su suite la ejerzan sin
     * duplicarla.
     *
     * @param string|null $value Valor a interpretar; si es `null` se lee del entorno.
     */
    public static function enabledByEnvironment(?string $value = null): bool
    {
        $declared = $value ?? getenv(self::ENVIRONMENT_FLAG);

        if ($declared === false || trim((string)$declared) === '') {
            return true;
        }

        return !in_array(strtolower(trim((string)$declared)), self::ENVIRONMENT_FALSY, true);
    }

    /**
     * Catálogo de averías completadas que este sembrador escribe.
     *
     * Las fechas se declaran **relativas** (día, hora local del parque y
     * duraciones en minutos) y se materializan al sembrar, para que el
     * histórico siga siendo reciente en cada ejecución en lugar de envejecer
     * con el repositorio.
     *
     * @return list<array{
     *     ticket_code: string,
     *     machine_code: string,
     *     category: string,
     *     urgency: string,
     *     status: 'CLOSED'|'RESOLVED',
     *     technician_email: string,
     *     reporter_name: string,
     *     reporter_phone: string,
     *     description: string,
     *     diagnosis: string,
     *     action: string,
     *     day_offset: int,
     *     reported_at: string,
     *     assign_delay_minutes: int,
     *     start_delay_minutes: int,
     *     resolution_minutes: int,
     *     closure_delay_minutes: ?int,
     *     reopen?: array{
     *         after_minutes: int,
     *         reassign_minutes: int,
     *         restart_minutes: int,
     *         resolution_minutes: int,
     *         reason: string,
     *         diagnosis: string,
     *         action: string
     *     }
     * }>
     */
    public static function fixtures(): array
    {
        return [
            [
                'ticket_code' => 'INC-DEMO-1101',
                'machine_code' => 'VEND-0101',
                'category' => 'TEMPERATURE_COLD',
                'urgency' => 'CRITICAL',
                'status' => 'CLOSED',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'reporter_name' => 'Dra. Carmen Morales',
                'reporter_phone' => '611223344',
                'description' => 'Cuba de sándwiches a 9,4 °C con aviso acústico intermitente y lácteos templados al tacto.',
                'diagnosis' => 'Sonda NTC de cámara descalibrada 4,8 °C por humedad en el conector estanco tras limpieza a presión.',
                'action' => 'Sustitución de la sonda NTC, sellado del conector con grasa dieléctrica y verificación de 3,5 °C estables.',
                'day_offset' => -35,
                'reported_at' => '08:30',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 110,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1103',
                'machine_code' => 'VEND-0301',
                'category' => 'TEMPERATURE_COLD',
                'urgency' => 'CRITICAL',
                'status' => 'CLOSED',
                'technician_email' => 'carlos.ruta@vendguard.internal',
                'reporter_name' => 'Carles Coordinador Bellvitge',
                'reporter_phone' => '600555666',
                'description' => 'Bandeja inferior de ensaladas entre 7,2 °C y 8,6 °C durante toda la mañana.',
                'diagnosis' => 'Envoltorio plástico obstruyendo parcialmente el conducto de retorno de aire del evaporador.',
                'action' => 'Retirada del elemento obstructor, limpieza del plenum y comprobación de 3,6 °C constantes.',
                'day_offset' => -32,
                'reported_at' => '07:45',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 135,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1105',
                'machine_code' => 'VEND-0201',
                'category' => 'PRODUCT_JAM',
                'urgency' => 'MEDIUM',
                'status' => 'CLOSED',
                'technician_email' => 'marta.ruta@vendguard.internal',
                'reporter_name' => 'Marc Recepción',
                'reporter_phone' => '600333444',
                'description' => 'Espiral 14 de aperitivos trabada con la bolsa a medio caer en la trampilla de entrega.',
                'diagnosis' => 'Bolsa de frutos secos atrapada entre la espiral y la trampilla basculante de recogida.',
                'action' => 'Extracción manual, alineación del muelle de retorno y diez ciclos de dispensación verificados.',
                'day_offset' => -28,
                'reported_at' => '10:05',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 95,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1107',
                'machine_code' => 'VEND-0601',
                'category' => 'PAYMENT_SYSTEM',
                'urgency' => 'HIGH',
                'status' => 'CLOSED',
                'technician_email' => 'marc.ruta@vendguard.internal',
                'reporter_name' => 'Clara Innovació',
                'reporter_phone' => '611222333',
                'description' => 'El datáfono contactless muestra error de red y rechaza todos los pagos con tarjeta.',
                'diagnosis' => 'Antena 4G adhesiva desprendida dentro del chasis metálico generando apantallamiento.',
                'action' => 'Reubicación exterior de la antena, fijación antivibración y test de cobertura con la operadora.',
                'day_offset' => -25,
                'reported_at' => '11:20',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 160,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1109',
                'machine_code' => 'VEND-0501',
                'category' => 'PRODUCT_JAM',
                'urgency' => 'LOW',
                'status' => 'CLOSED',
                'technician_email' => 'elena.ruta@vendguard.internal',
                'reporter_name' => 'Albert Campus UPC',
                'reporter_phone' => '600999000',
                'description' => 'Las barritas energéticas de la espiral 6 no caen al pulsar el código de selección.',
                'diagnosis' => 'Microinterruptor de fin de carrera del motor de espiral desalineado por vibración.',
                'action' => 'Ajuste del soporte del motor y validación de cinco expulsiones consecutivas correctas.',
                'day_offset' => -22,
                'reported_at' => '14:00',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 80,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1111',
                'machine_code' => 'VEND-0701',
                'category' => 'TEMPERATURE_COLD',
                'urgency' => 'CRITICAL',
                'status' => 'CLOSED',
                'technician_email' => 'elena.ruta@vendguard.internal',
                'reporter_name' => 'Sergi Logística Can Ruti',
                'reporter_phone' => '611444555',
                'description' => 'Aviso sonoro continuo por desvío térmico sostenido a 7,9 °C en la máquina de sándwiches.',
                'diagnosis' => 'Condensación en el conector del sensor NTC desviando la lectura de la cámara 3,1 °C.',
                'action' => 'Secado del conector con aire caliente, sellado dieléctrico y lectura normalizada a 3,4 °C.',
                'day_offset' => -19,
                'reported_at' => '08:15',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 125,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1113',
                'machine_code' => 'VEND-1001',
                'category' => 'ELECTRICAL_OFF',
                'urgency' => 'HIGH',
                'status' => 'CLOSED',
                'technician_email' => 'carlos.ruta@vendguard.internal',
                'reporter_name' => 'Mireia Serveis Almeda',
                'reporter_phone' => '622111222',
                'description' => 'La bomba de presión no arranca y la caldera queda bloqueada por error de caudal de agua.',
                'diagnosis' => 'Bomba de vibración 230 V gripada por calcificación acumulada en la válvula antirretorno.',
                'action' => 'Sustitución de la bomba de presión y descalcificación preventiva del circuito hidráulico.',
                'day_offset' => -16,
                'reported_at' => '09:15',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 170,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1115',
                'machine_code' => 'VEND-0401',
                'category' => 'PAYMENT_SYSTEM',
                'urgency' => 'MEDIUM',
                'status' => 'CLOSED',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'reporter_name' => 'Núria Port',
                'reporter_phone' => '600777888',
                'description' => 'El lector de tarjetas comunica timeout con el telemando tras cada intento de cobro.',
                'diagnosis' => 'Mazo de cables MDB pinzado contra la bisagra inferior de la puerta del mueble.',
                'action' => 'Reparación y enfundado del mazo MDB, con verificación de cobro contactless en diez intentos.',
                'day_offset' => -13,
                'reported_at' => '12:40',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 105,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1117',
                'machine_code' => 'VEND-0302',
                'category' => 'OTHER',
                'urgency' => 'LOW',
                'status' => 'CLOSED',
                'technician_email' => 'marta.ruta@vendguard.internal',
                'reporter_name' => 'Carles Coordinador Bellvitge',
                'reporter_phone' => '600555666',
                'description' => 'La pantalla frontal muestra caracteres incompletos y dificulta elegir la bebida.',
                'diagnosis' => 'Falso contacto en el conector de cinta del display LCD por un tornillo de fijación perdido.',
                'action' => 'Sustitución del cable plano, reposición del tornillo de fijación y contraste verificado.',
                'day_offset' => -10,
                'reported_at' => '15:30',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 90,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1119',
                'machine_code' => 'VEND-0901',
                'category' => 'PAYMENT_SYSTEM',
                'urgency' => 'HIGH',
                'status' => 'CLOSED',
                'technician_email' => 'marc.ruta@vendguard.internal',
                'reporter_name' => 'Pau Esdeveniments',
                'reporter_phone' => '611888999',
                'description' => 'La máquina no acredita saldo tras pagos con tarjeta durante el montaje de la feria.',
                'diagnosis' => 'Lector contactless con firmware desactualizado rechazando el protocolo de la red local.',
                'action' => 'Actualización del firmware del lector y validación de seis cobros consecutivos correctos.',
                'day_offset' => -8,
                'reported_at' => '13:25',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 130,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1121',
                'machine_code' => 'VEND-0801',
                'category' => 'PRODUCT_JAM',
                'urgency' => 'MEDIUM',
                'status' => 'CLOSED',
                'technician_email' => 'carlos.ruta@vendguard.internal',
                'reporter_name' => 'Gemma Gestió',
                'reporter_phone' => '611666777',
                'description' => 'El muelle de la fila 4 de aperitivos gira sin expulsar el producto seleccionado.',
                'diagnosis' => 'Acumulación de polvo de aperitivo en la guía superior frenando el avance del espiral.',
                'action' => 'Limpieza de la guía, engrase alimentario del eje y comprobación de la fila completa.',
                'day_offset' => -5,
                'reported_at' => '16:10',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 85,
                'closure_delay_minutes' => 2880,
            ],
            [
                'ticket_code' => 'INC-DEMO-1123',
                'machine_code' => 'VEND-0102',
                'category' => 'ELECTRICAL_OFF',
                'urgency' => 'CRITICAL',
                'status' => 'CLOSED',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'reporter_name' => 'Guillermo Mantenimiento',
                'reporter_phone' => '644556677',
                'description' => 'Máquina completamente apagada y diferencial del cuadro saltado a última hora.',
                'diagnosis' => 'Fuga de agua en el racor de la electroválvula de entrada que provoca el salto diferencial.',
                'action' => 'Sustitución del racor rápido y del tramo de teflón de 6 mm con secado de la electrónica.',
                'day_offset' => -3,
                'reported_at' => '17:05',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 155,
                'closure_delay_minutes' => 2880,
            ],

            // ───────────────────────────────────────────────────────────────
            // Averías EN GARANTÍA (RESOLVED, sin cierre): la máquina sigue
            // operativa, el técnico sigue firmando la resolución y la sede aún
            // puede reabrir dentro de las 48 h (Art. V.6). Todas resuelven «ayer»
            // para que la garantía esté viva cuando se siembren, y ocupan
            // máquinas distintas entre sí y sin otro expediente abierto: son
            // incidencias vivas y no pueden pisar el índice único parcial.
            // ───────────────────────────────────────────────────────────────
            [
                'ticket_code' => 'INC-DEMO-1201',
                'machine_code' => 'VEND-0502',
                'category' => 'PRODUCT_JAM',
                'urgency' => 'MEDIUM',
                'status' => 'RESOLVED',
                'technician_email' => 'elena.ruta@vendguard.internal',
                'reporter_name' => 'Albert Campus UPC',
                'reporter_phone' => '600999000',
                'description' => 'Atasco del grupo de infusión: la molienda no cae al vaso y sale vapor denso por la ranura de erogación.',
                'diagnosis' => 'Atasco del grupo de infusión por apelmazamiento de una molienda excesivamente fina y húmeda.',
                'action' => 'Limpieza por inmersión del grupo erogador, reajuste del micrométrico del molino y cinco cafés de prueba.',
                'day_offset' => -1,
                'reported_at' => '08:35',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 40,
                'resolution_minutes' => 95,
                'closure_delay_minutes' => null,
            ],
            [
                'ticket_code' => 'INC-DEMO-1202',
                'machine_code' => 'VEND-0901',
                'category' => 'ELECTRICAL_OFF',
                'urgency' => 'HIGH',
                'status' => 'RESOLVED',
                'technician_email' => 'marc.ruta@vendguard.internal',
                'reporter_name' => 'Pau Esdeveniments',
                'reporter_phone' => '611888999',
                'description' => 'La cabina de frío deja de enfriar de golpe y el display parpadea con error de alimentación eléctrica.',
                'diagnosis' => 'Falso contacto en el conector de alimentación del módulo frigorífico por holgura del terminal.',
                'action' => 'Recrimpado del terminal de alimentación, fijación del conector y verificación de 4,1 °C estables.',
                'day_offset' => -1,
                'reported_at' => '09:20',
                'assign_delay_minutes' => 20,
                'start_delay_minutes' => 65,
                'resolution_minutes' => 140,
                'closure_delay_minutes' => null,
            ],
            [
                'ticket_code' => 'INC-DEMO-1203',
                'machine_code' => 'VEND-0301',
                'category' => 'TEMPERATURE_COLD',
                'urgency' => 'CRITICAL',
                'status' => 'RESOLVED',
                'technician_email' => 'carlos.ruta@vendguard.internal',
                'reporter_name' => 'Carles Coordinador Bellvitge',
                'reporter_phone' => '600555666',
                'description' => 'Desvío térmico sostenido a 8,3 °C en la bandeja de sándwiches con aviso acústico continuo.',
                'diagnosis' => 'Junta de la puerta de la cabina deformada por el calor, con entrada de aire ambiente al cerrar.',
                'action' => 'Sustitución de la junta magnética de puerta y comprobación de 3,7 °C estables durante una hora.',
                'day_offset' => -1,
                'reported_at' => '07:50',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 35,
                'resolution_minutes' => 120,
                'closure_delay_minutes' => null,
            ],

            // ───────────────────────────────────────────────────────────────
            // Reincidencias por garantía: la avería se resolvió, la sede la reabrió
            // dentro de las 48 h y el técnico volvió a resolverla. La reapertura
            // lleva user_id NULL —así la escribe `reopen()`, que además desasigna al
            // técnico y pone resolved_at a NULL—, y la atribución al técnico que
            // firmó la resolución anterior es la que el desglose por técnico
            // reconstruye para contar `warranty_reopens` (EARS 2.3.1). La primera
            // avería termina CLOSED —su garantía ya expiró—; la segunda sigue
            // RESOLVED, así que admite una segunda reapertura.
            // ───────────────────────────────────────────────────────────────
            [
                'ticket_code' => 'INC-DEMO-1204',
                'machine_code' => 'VEND-0601',
                'category' => 'PAYMENT_SYSTEM',
                'urgency' => 'HIGH',
                'status' => 'CLOSED',
                'technician_email' => 'marc.ruta@vendguard.internal',
                'reporter_name' => 'Clara Innovació',
                'reporter_phone' => '611222333',
                'description' => 'El lector contactless vuelve a mostrar error de red y rechaza todos los pagos con tarjeta.',
                'diagnosis' => 'Antena 4G adhesiva desprendida dentro del chasis metálico generando apantallamiento de señal.',
                'action' => 'Reubicación exterior de la antena magnética, fijación antivibración y test de cobertura con la operadora.',
                'day_offset' => -12,
                'reported_at' => '09:00',
                'assign_delay_minutes' => 20,
                'start_delay_minutes' => 60,
                'resolution_minutes' => 100,
                'closure_delay_minutes' => 2880,
                'reopen' => [
                    'after_minutes' => 1200,
                    'reassign_minutes' => 120,
                    'restart_minutes' => 180,
                    'resolution_minutes' => 300,
                    'reason' => 'El datáfono vuelve a denegar los cobros con tarjeta tras media hora de uso continuo en la hora punta.',
                    'diagnosis' => 'Asiento de la antena exterior vencido por la vibración del compresor, con pérdida de masa de contacto.',
                    'action' => 'Reasiento de la antena con brida atornillada, repaso del mazo de radiofrecuencia y veinte cobros verificados.',
                ],
            ],
            [
                'ticket_code' => 'INC-DEMO-1205',
                'machine_code' => 'VEND-0302',
                'category' => 'ELECTRICAL_OFF',
                'urgency' => 'CRITICAL',
                'status' => 'RESOLVED',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'reporter_name' => 'Núria Port',
                'reporter_phone' => '600777888',
                'description' => 'La máquina salta el diferencial al conectar la caldera y queda sin servicio con error de resistencia.',
                'diagnosis' => 'Fuga en el racor de entrada de la electroválvula de llenado que hace saltar el diferencial al mojar el mazo.',
                'action' => 'Sustitución del racor de entrada y del tramo de teflón, con secado del mazo y prueba de aislamiento dieléctrico.',
                'day_offset' => -1,
                'reported_at' => '08:00',
                'assign_delay_minutes' => 15,
                'start_delay_minutes' => 45,
                'resolution_minutes' => 90,
                'closure_delay_minutes' => null,
                'reopen' => [
                    'after_minutes' => 300,
                    'reassign_minutes' => 60,
                    'restart_minutes' => 120,
                    'resolution_minutes' => 240,
                    'reason' => 'La máquina vuelve a saltar el diferencial en cuanto la caldera alcanza la temperatura de servicio.',
                    'diagnosis' => 'Resistencia de la caldera con derivación a tierra por carbonilla acumulada en el terminal cerámico.',
                    'action' => 'Sustitución de la resistencia de caldera, limpieza del terminal cerámico y prueba de aislamiento a 500 V.',
                ],
            ],
        ];
    }

    /**
     * Escribe el histórico completo: averías, traza de estados y auditoría.
     *
     * Cada expediente del fixture se resuelve en uno de tres modos (EARS-02.6):
     *
     *   · **Alta**: el expediente no existía. Se escribe con su traza y su auditoría
     *     completas.
     *   · **Reafirmación**: existía y su traza almacenada sigue siendo la del fixture.
     *     Se reafirman sus fechas y se desplaza su traza y su auditoría al mismo esquema
     *     temporal, fila a fila y con `UPDATE` —nunca con un borrado—, para que la
     *     historia no quede anclada antes del alta que cuenta.
     *   · **Preservación**: existía y su traza ya no es la del fixture —la sede la
     *     reabrió, el coordinador la canceló, el fixture cambió de forma—. No se toca
     *     nada de ese expediente y se declara en el resumen.
     *
     * Y una avería **viva** del fixture (en garantía) no se reafirma si su máquina está
     * ocupada por otro aviso vivo: el sitio lo manda el parque (`uq_machine_active_ticket`,
     * Art. V.2 y V.6). Se declara y se sigue; un aviso en garantía no puede tumbar el
     * arranque del servicio.
     *
     * @return array{incidents: int, history_events: int, audit_events: int,
     *               history_realigned: int, audit_realigned: int,
     *               preserved: list<string>, skipped: list<string>}
     *
     * @throws RuntimeException Si el catálogo maestro no está aplicado o el motor rechaza el alta.
     */
    public function seed(): array
    {
        $machines = $this->machineCatalog();
        $technicians = $this->technicianCatalog();
        $coordinator = $this->coordinator();

        $rootCauses = $this->rootCauseCatalog();

        $this->pdo->beginTransaction();

        try {
            $incidentCount = 0;
            $historyCount = 0;
            $auditCount = 0;
            $historyRealigned = 0;
            $auditRealigned = 0;
            $preserved = [];
            $skipped = [];

            foreach (self::fixtures() as $fixture) {
                $machine = $machines[$fixture['machine_code']] ?? null;
                if ($machine === null) {
                    throw new RuntimeException(sprintf(
                        'La máquina «%s» del histórico de demostración no existe en el catálogo maestro: aplica primero las semillas del motor (database/supabase_seeds.sql).',
                        $fixture['machine_code']
                    ));
                }

                $technicianId = $technicians[$fixture['technician_email']] ?? null;
                if ($technicianId === null) {
                    throw new RuntimeException(sprintf(
                        'El técnico «%s» del histórico de demostración no existe en el catálogo maestro de usuarios.',
                        $fixture['technician_email']
                    ));
                }

                $timeline = $this->timeline($fixture);

                $historyEvents = $this->historyEvents($fixture, $technicianId, $coordinator, $timeline);
                $auditEvents = $this->auditEvents($fixture, $machine, $technicianId, $coordinator, $timeline);

                $existingId = $this->incidentIdOrNull($fixture['ticket_code']);
                $storedHistory = $existingId === null ? [] : $this->storedHistory($existingId);
                $storedAudit = $existingId === null ? [] : $this->storedAudit($existingId);

                // El parque manda: una avería viva del fixture necesita su máquina libre, y
                // el índice parcial `uq_machine_active_ticket` no admite dos expedientes vivos
                // sobre la misma máquina (Art. V.2 y V.6). Se comprueba antes de escribir —con
                // el propio ticket excluido— para que el choque no aborte el arranque entero.
                if ($fixture['status'] === 'RESOLVED'
                    && $this->machineOccupiedByOtherIncident($machine['id'], $fixture['ticket_code'])) {
                    $skipped[] = $fixture['ticket_code'];
                    continue;
                }

                // La traza del fixture se reafirma; la que ya no es la suya, no se toca.
                if ($existingId !== null
                    && !$this->matchesFixtureShape($storedHistory, $historyEvents, $storedAudit, $auditEvents)) {
                    $preserved[] = $fixture['ticket_code'];
                    continue;
                }

                $this->upsertIncident($fixture, $machine, $technicianId, $timeline, $rootCauses);
                $incidentCount++;

                $incidentId = $this->incidentId($fixture['ticket_code']);

                if ($existingId === null) {
                    $historyCount += $this->insertHistory($incidentId, $historyEvents);
                    $auditCount += $this->insertAudit($incidentId, $auditEvents);
                    continue;
                }

                $historyRealigned += $this->realignHistory($incidentId, $historyEvents, $storedHistory);
                $auditRealigned += $this->realignAudit($incidentId, $auditEvents, $storedAudit);
            }

            $this->pdo->commit();

            return [
                'incidents' => $incidentCount,
                'history_events' => $historyCount,
                'audit_events' => $auditCount,
                'history_realigned' => $historyRealigned,
                'audit_realigned' => $auditRealigned,
                'preserved' => $preserved,
                'skipped' => $skipped,
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException(
                'Error sembrando el histórico de demostración: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Mapeo canónico de causas raíz para las averías cerradas/resueltas de demostración.
     *
     * Distribución intencionada tipo Pareto (80/20):
     * - RC-PAY-01 y RC-JAM-01 concentran el volumen principal.
     * - RC-COLD-01 y RC-ELEC-01 aportan volumen secundario.
     * - Otras causas completan la cola de la distribución.
     *
     * @var array<string, string>
     */
    private const DEMO_TAXONOMY_MAP = [
        'INC-DEMO-1101' => 'RC-COLD-01',
        'INC-DEMO-1103' => 'RC-COLD-02',
        'INC-DEMO-1105' => 'RC-JAM-01',
        'INC-DEMO-1107' => 'RC-PAY-03',
        'INC-DEMO-1109' => 'RC-JAM-01',
        'INC-DEMO-1111' => 'RC-COLD-01',
        'INC-DEMO-1113' => 'RC-ELEC-01',
        'INC-DEMO-1115' => 'RC-PAY-01',
        'INC-DEMO-1119' => 'RC-PAY-01',
        'INC-DEMO-1121' => 'RC-JAM-01',
    ];

    /**
     * Alta idempotente de la avería: la clave natural es su código de ticket.
     *
     * La cláusula de reafirmación la renderiza el adaptador (`EXCLUDED` en el
     * motor nuevo, `VALUES()` en el local): una sola definición de la regla, dos
     * sintaxis (EARS-01.5).
     *
     * @param array<string, mixed> $fixture
     * @param array{id: int, location_id: int, machine_type: string} $machine
     * @param array<string, string> $timeline
     * @param array<string, array<string, mixed>> $rootCauses
     */
    private function upsertIncident(
        array $fixture,
        array $machine,
        int $technicianId,
        array $timeline,
        array $rootCauses = []
    ): void
    {
        $columns = [
            'ticket_code', 'machine_id', 'machine_type_snapshot', 'location_id',
            'assigned_technician_id', 'reporter_name', 'reporter_phone', 'category',
            'description', 'urgency', 'status', 'assigned_at', 'started_at',
            'resolved_at', 'closed_at', 'reopened_at', 'reopen_reason',
            'resolution_diagnosis', 'resolution_action', 'created_at', 'updated_at',
        ];

        $causeCode = self::DEMO_TAXONOMY_MAP[$fixture['ticket_code']] ?? null;
        $taxonomy = ($causeCode !== null && isset($rootCauses[$causeCode]))
            ? $rootCauses[$causeCode]
            : null;

        if ($taxonomy !== null) {
            $columns = array_merge($columns, [
                'root_cause_id',
                'standard_solution_id',
                'root_cause_code_snapshot',
                'root_cause_category_snapshot',
                'root_cause_name_snapshot',
                'standard_solution_code_snapshot',
                'standard_solution_name_snapshot',
            ]);
        }

        $refresh = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== 'ticket_code'
        ));

        $sql = 'INSERT INTO incidents (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns))
            . ') '
            . $this->dialect->upsertFromInsertedRow(['ticket_code'], $refresh);

        $params = [
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
            ':assigned_at' => $timeline['assigned'],
            ':started_at' => $timeline['started'],
            ':resolved_at' => $timeline['resolved'],
            ':closed_at' => $timeline['closed'],
            ':reopened_at' => $timeline['reopened'],
            ':reopen_reason' => $this->reopenReason($fixture),
            ':resolution_diagnosis' => $this->effectiveDiagnosis($fixture),
            ':resolution_action' => $this->effectiveAction($fixture),
            ':created_at' => $timeline['created'],
            ':updated_at' => $timeline['closed'] ?? $timeline['resolved'],
        ];

        if ($taxonomy !== null) {
            $params[':root_cause_id'] = $taxonomy['root_cause_id'];
            $params[':standard_solution_id'] = $taxonomy['standard_solution_id'];
            $params[':root_cause_code_snapshot'] = $taxonomy['root_cause_code'];
            $params[':root_cause_category_snapshot'] = $taxonomy['root_cause_category'];
            $params[':root_cause_name_snapshot'] = $taxonomy['root_cause_name'];
            $params[':standard_solution_code_snapshot'] = $taxonomy['standard_solution_code'];
            $params[':standard_solution_name_snapshot'] = $taxonomy['standard_solution_name'];
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Eventos de traza de estados que el fixture declara para un expediente.
     *
     * El sembrador no los escribe a ciegas: los devuelve como lista ordenada, y quien los
     * escribe comprueba antes si el expediente ya existe y si su traza almacenada sigue
     * siendo **esta misma** —mismo número de eventos, mismas transiciones y mismos
     * actores, en el mismo orden—. Cuando lo es, la desplaza con `UPDATE` al esquema
     * temporal vigente en lugar de añadir una segunda pasada por los mismos estados: el
     * histórico de estados es inmutable por diseño, se amplía o se desplaza, nunca se
     * borra (Art. III.1).
     *
     * El triaje inicial se anota en la marca en que ocurrió (`first_assigned`), no en
     * la de la última asignación: en una avería reabierta el expediente vuelve a
     * triaje y se le asigna técnico otra vez, de modo que `assigned_at` acaba siendo
     * posterior al arranque de la primera intervención y una traza que usara esa
     * marca contaría el triaje después de la reapertura.
     *
     * Una avería reabierta encadena una segunda vuelta —`RESOLVED → REOPENED →
     * ASSIGNED → IN_PROGRESS → RESOLVED`— sin perder la primera resolución: es la
     * pareja de eventos `RESOLVED`/`REOPENED` la que el desglose por técnico lee
     * para atribuir la reincidencia a quien firmó la resolución reabierta
     * (EARS 2.3.1), y la reapertura lleva `user_id` NULL porque así la escribe
     * `reopen()`, que actúa con la sede como causante y no con una cuenta interna.
     *
     * @param array<string, mixed> $fixture
     * @param array<string, string> $timeline
     * @return list<array{user_id: ?int, from_status: ?string, to_status: string, action_note: string, created_at: string}>
     */
    private function historyEvents(
        array $fixture,
        int $technicianId,
        int $coordinatorId,
        array $timeline
    ): array {
        $events = [
            [
                'user_id' => null,
                'from_status' => null,
                'to_status' => 'REGISTERED',
                'action_note' => 'Aviso recibido desde el código QR de la máquina por ' . $fixture['reporter_name'] . '.',
                'created_at' => $timeline['created'],
            ],
            [
                'user_id' => $coordinatorId,
                'from_status' => 'REGISTERED',
                'to_status' => 'ASSIGNED',
                'action_note' => 'Triaje del servicio: avería asignada al técnico de ruta por urgencia ' . $fixture['urgency'] . '.',
                'created_at' => $timeline['first_assigned'],
            ],
            [
                'user_id' => $technicianId,
                'from_status' => 'ASSIGNED',
                'to_status' => 'IN_PROGRESS',
                'action_note' => 'Intervención iniciada in situ sobre la máquina ' . $fixture['machine_code'] . '.',
                'created_at' => $timeline['started'],
            ],
            [
                'user_id' => $technicianId,
                'from_status' => 'IN_PROGRESS',
                'to_status' => 'RESOLVED',
                'action_note' => 'Diagnóstico: ' . $fixture['diagnosis'] . ' | Solución: ' . $fixture['action'],
                'created_at' => $timeline['first_resolved'],
            ],
        ];

        // La vuelta de garantía: la sede reabre —sin cuenta interna, como la
        // escribe `reopen()`—, coordinación devuelve el aviso a la ruta y el técnico
        // vuelve a resolver. La nota de la reapertura reproduce la del motor de
        // estados, con el ordinal de la reincidencia y el motivo de la sede.
        if (is_array($fixture['reopen'] ?? null)) {
            $events[] = [
                'user_id' => null,
                'from_status' => 'RESOLVED',
                'to_status' => 'REOPENED',
                'action_note' => 'Reapertura solicitada por la sede (1ª reincidencia). Motivo: ' . $fixture['reopen']['reason'],
                'created_at' => $timeline['reopened'],
            ];
            $events[] = [
                'user_id' => $coordinatorId,
                'from_status' => 'REOPENED',
                'to_status' => 'ASSIGNED',
                'action_note' => 'Triaje de la reapertura: el aviso vuelve al técnico de ruta por urgencia ' . $fixture['urgency'] . '.',
                'created_at' => $timeline['reassigned'],
            ];
            $events[] = [
                'user_id' => $technicianId,
                'from_status' => 'ASSIGNED',
                'to_status' => 'IN_PROGRESS',
                'action_note' => 'Reintervención por garantía iniciada in situ sobre la máquina ' . $fixture['machine_code'] . '.',
                'created_at' => $timeline['restarted'],
            ];
            $events[] = [
                'user_id' => $technicianId,
                'from_status' => 'IN_PROGRESS',
                'to_status' => 'RESOLVED',
                'action_note' => 'Diagnóstico (reintervención): ' . $this->effectiveDiagnosis($fixture)
                    . ' | Solución: ' . $this->effectiveAction($fixture),
                'created_at' => $timeline['reresolved'],
            ];
        }

        // Sólo cierra lo que cerró: una avería en garantía se queda sin el evento y
        // sigue figurando como expediente vivo en la traza.
        if ($timeline['closed'] !== null) {
            $events[] = [
                'user_id' => null,
                'from_status' => 'RESOLVED',
                'to_status' => 'CLOSED',
                'action_note' => 'Cierre definitivo tras la ventana de garantía de 48 horas sin reincidencia.',
                'created_at' => $timeline['closed'],
            ];
        }

        return $events;
    }

    /**
     * Escribe la traza completa de un expediente nuevo.
     *
     * @param list<array{user_id: ?int, from_status: ?string, to_status: string, action_note: string, created_at: string}> $events
     * @return int Número de eventos escritos.
     */
    private function insertHistory(int $incidentId, array $events): int
    {
        $sql = 'INSERT INTO incident_history (incident_id, user_id, from_status, to_status, action_note, created_at)'
            . ' VALUES (:incident_id, :user_id, :from_status, :to_status, :action_note, :created_at)';
        $statement = $this->pdo->prepare($sql);

        foreach ($events as $event) {
            $statement->execute([
                ':incident_id' => $incidentId,
                ':user_id' => $event['user_id'],
                ':from_status' => $event['from_status'],
                ':to_status' => $event['to_status'],
                ':action_note' => $event['action_note'],
                ':created_at' => $event['created_at'],
            ]);
        }

        return count($events);
    }

    /**
     * Desplaza la traza almacenada al esquema temporal vigente, fila a fila.
     *
     * Sólo se invoca cuando la traza almacenada **es** la del fixture —la guardia de forma
     * lo certifica antes—, de modo que la correspondencia es uno a uno por orden de
     * escritura (`id`) y ninguna fila cambia de identidad. El `UPDATE` es la única
     * operación que el Art. III.1 admite sobre una traza: el borrado lo rechaza el propio
     * motor con `SQLSTATE 45000` y la ventana de purga es del arnés (T-HYBRID-12 y
     * T-HYBRID-26), no de la aplicación.
     *
     * El conteo es el de **marcas procesadas**, no el de `rowCount()`: el motor heredado
     * devuelve cero cuando el `UPDATE` reafirma los mismos valores, y una pasada dentro del
     * mismo minuto no cambia ni una marca.
     *
     * @param list<array{user_id: ?int, from_status: ?string, to_status: string, action_note: string, created_at: string}> $expected
     * @param list<array{id: int, user_id: ?int, from_status: ?string, to_status: string, action_note: ?string}> $stored
     * @return int Número de marcas desplazadas.
     */
    private function realignHistory(int $incidentId, array $expected, array $stored): int
    {
        $sql = 'UPDATE incident_history'
            . ' SET user_id = :user_id, from_status = :from_status, to_status = :to_status,'
            . ' action_note = :action_note, created_at = :created_at'
            . ' WHERE id = :id AND incident_id = :incident_id';

        $statement = $this->pdo->prepare($sql);

        foreach ($expected as $index => $event) {
            $statement->execute([
                ':user_id' => $event['user_id'],
                ':from_status' => $event['from_status'],
                ':to_status' => $event['to_status'],
                ':action_note' => $event['action_note'],
                ':created_at' => $event['created_at'],
                ':id' => $stored[$index]['id'],
                ':incident_id' => $incidentId,
            ]);
        }

        return count($expected);
    }

    /**
     * Eventos de auditoría que el fixture declara para un expediente.
     *
     * Quien los escribe comprueba antes si el expediente ya existe y si su rastro
     * almacenado sigue siendo este mismo —mismas acciones, en el mismo orden—; cuando lo
     * es, lo desplaza con `UPDATE` al mismo esquema temporal que la avería, para que la
     * auditoría cuente *cuándo* ocurrió con la cronología del expediente.
     *
     * El vocabulario combina el que la aplicación sí escribe con el que todavía no
     * audita: `REOPEN_TICKET` es literal —lo emite el portal de la sede al reabrir,
     * con la sede como actor y `user_id` NULL—, mientras que `TICKET_CREATED`,
     * `TECHNICIAN_ASSIGNED`, `INTERVENTION_STARTED`, `TICKET_RESOLVED` y
     * `TICKET_AUTO_CLOSED` son el vocabulario histórico de los sembradores, porque la
     * aplicación aún no audita el alta, el inicio de intervención ni el cierre
     * automático (contrato `specs/technical/metrics_audit_contracts.md`).
     *
     * @param array<string, mixed> $fixture
     * @param array{id: int, location_id: int, machine_type: string} $machine
     * @param array<string, string> $timeline
     * @return list<array<string, mixed>>
     */
    private function auditEvents(
        array $fixture,
        array $machine,
        int $technicianId,
        int $coordinatorId,
        array $timeline
    ): array {
        $technicianName = $this->userName($technicianId);
        $coordinatorName = $this->userName($coordinatorId);

        $events = [
            [
                'action' => 'TICKET_CREATED',
                'user_id' => null,
                'user_role' => 'SYSTEM',
                'user_name' => $fixture['reporter_name'] . ' (Aviso QR)',
                'previous_state' => null,
                'new_state' => [
                    'ticket_code' => $fixture['ticket_code'],
                    'status' => 'REGISTERED',
                    'urgency' => $fixture['urgency'],
                    'category' => $fixture['category'],
                ],
                'metadata' => ['source' => 'QR_SCAN'],
                'created_at' => $timeline['created'],
            ],
            [
                'action' => 'TECHNICIAN_ASSIGNED',
                'user_id' => $coordinatorId,
                'user_role' => 'COORDINATOR',
                'user_name' => $coordinatorName,
                'previous_state' => ['status' => 'REGISTERED', 'assigned_technician_id' => null],
                'new_state' => ['status' => 'ASSIGNED', 'assigned_technician_id' => $technicianId],
                'metadata' => ['technician_name' => $technicianName],
                'created_at' => $timeline['first_assigned'],
            ],
            [
                'action' => 'INTERVENTION_STARTED',
                'user_id' => $technicianId,
                'user_role' => 'TECHNICIAN',
                'user_name' => $technicianName,
                'previous_state' => ['status' => 'ASSIGNED'],
                'new_state' => ['status' => 'IN_PROGRESS'],
                'metadata' => ['location' => 'In-situ'],
                'created_at' => $timeline['started'],
            ],
            [
                'action' => 'TICKET_RESOLVED',
                'user_id' => $technicianId,
                'user_role' => 'TECHNICIAN',
                'user_name' => $technicianName,
                'previous_state' => ['status' => 'IN_PROGRESS'],
                'new_state' => [
                    'status' => 'RESOLVED',
                    'resolution_diagnosis' => $fixture['diagnosis'],
                    'resolution_action' => $fixture['action'],
                ],
                'metadata' => ['warranty_period_hours' => 48],
                'created_at' => $timeline['first_resolved'],
            ],
        ];

        // Segunda vuelta: la reapertura de la sede y todo lo que desencadena. El
        // evento de reapertura replica —forma incluida— el que emite el portal de la
        // sede (LocationPortalController): actor SITE_MANAGER sin cuenta interna,
        // estado previo con el técnico saliente y el motivo declarado en `new_state`.
        if (is_array($fixture['reopen'] ?? null)) {
            $siteName = $this->locationName($machine['location_id']);

            $events[] = [
                'action' => 'REOPEN_TICKET',
                'user_id' => null,
                'user_role' => 'SITE_MANAGER',
                'user_name' => $siteName !== '' ? 'Responsable de Sede · ' . $siteName : 'Responsable de Sede',
                'previous_state' => [
                    'status' => 'RESOLVED',
                    'assigned_technician_id' => $technicianId,
                    'resolved_at' => $timeline['first_resolved'],
                ],
                'new_state' => [
                    'status' => 'REOPENED',
                    'assigned_technician_id' => null,
                    'reopen_reason' => $fixture['reopen']['reason'],
                    'reopened_at' => $timeline['reopened'],
                ],
                'metadata' => [
                    'ticket_code' => $fixture['ticket_code'],
                    'reopen_count' => 1,
                ],
                'created_at' => $timeline['reopened'],
            ];
            $events[] = [
                'action' => 'TECHNICIAN_ASSIGNED',
                'user_id' => $coordinatorId,
                'user_role' => 'COORDINATOR',
                'user_name' => $coordinatorName,
                'previous_state' => ['status' => 'REOPENED', 'assigned_technician_id' => null],
                'new_state' => ['status' => 'ASSIGNED', 'assigned_technician_id' => $technicianId],
                'metadata' => ['technician_name' => $technicianName],
                'created_at' => $timeline['reassigned'],
            ];
            $events[] = [
                'action' => 'INTERVENTION_STARTED',
                'user_id' => $technicianId,
                'user_role' => 'TECHNICIAN',
                'user_name' => $technicianName,
                'previous_state' => ['status' => 'ASSIGNED'],
                'new_state' => ['status' => 'IN_PROGRESS'],
                'metadata' => ['location' => 'In-situ', 'round' => 2],
                'created_at' => $timeline['restarted'],
            ];
            $events[] = [
                'action' => 'TICKET_RESOLVED',
                'user_id' => $technicianId,
                'user_role' => 'TECHNICIAN',
                'user_name' => $technicianName,
                'previous_state' => ['status' => 'IN_PROGRESS'],
                'new_state' => [
                    'status' => 'RESOLVED',
                    'resolution_diagnosis' => $this->effectiveDiagnosis($fixture),
                    'resolution_action' => $this->effectiveAction($fixture),
                ],
                'metadata' => ['warranty_period_hours' => 48, 'round' => 2],
                'created_at' => $timeline['reresolved'],
            ];
        }

        // Sólo el expediente que agotó su garantía tiene cierre automático.
        if ($timeline['closed'] !== null) {
            $events[] = [
                'action' => 'TICKET_AUTO_CLOSED',
                'user_id' => null,
                'user_role' => 'SYSTEM_CRON',
                'user_name' => 'AutoCloseCron (48h Garantía)',
                'previous_state' => ['status' => 'RESOLVED'],
                'new_state' => ['status' => 'CLOSED'],
                'metadata' => ['warranty_cleared' => true],
                'created_at' => $timeline['closed'],
            ];
        }

        return $events;
    }

    /**
     * Escribe el rastro de auditoría completo de un expediente nuevo.
     *
     * @param list<array<string, mixed>> $events
     * @return int Número de registros escritos.
     */
    private function insertAudit(int $incidentId, array $events): int
    {
        $sql = 'INSERT INTO audit_log (entity_type, entity_id, action, user_id, user_role, user_name,'
            . ' previous_state, new_state, metadata, created_at)'
            . ' VALUES (:entity_type, :entity_id, :action, :user_id, :user_role, :user_name,'
            . ' :previous_state, :new_state, :metadata, :created_at)';
        $statement = $this->pdo->prepare($sql);

        foreach ($events as $event) {
            $statement->execute([
                ':entity_type' => 'TICKET',
                ':entity_id' => $incidentId,
                ':action' => $event['action'],
                ':user_id' => $event['user_id'],
                ':user_role' => $event['user_role'],
                ':user_name' => $event['user_name'],
                ':previous_state' => $this->encodeJson($event['previous_state']),
                ':new_state' => $this->encodeJson($event['new_state']),
                ':metadata' => $this->encodeJson($event['metadata']),
                ':created_at' => $event['created_at'],
            ]);
        }

        return count($events);
    }

    /**
     * Desplaza el rastro de auditoría almacenado al esquema temporal vigente.
     *
     * Mismo contrato que la traza: sólo se invoca con la guardia de forma satisfecha, la
     * correspondencia es uno a uno por orden de escritura y la fila conserva su identidad.
     * El registro se reafirma entero —incluidos `previous_state` y `new_state`, que llevan
     * dentro marcas del expediente— porque es la única forma de que la auditoría cuente
     * *cuándo* ocurrió con la misma cronología que la avería.
     *
     * @param list<array<string, mixed>> $expected
     * @param list<array{id: int, action: string}> $stored
     * @return int Número de registros desplazados.
     */
    private function realignAudit(int $incidentId, array $expected, array $stored): int
    {
        $sql = 'UPDATE audit_log'
            . ' SET user_id = :user_id, user_role = :user_role, user_name = :user_name,'
            . ' previous_state = :previous_state, new_state = :new_state, metadata = :metadata,'
            . ' created_at = :created_at'
            . ' WHERE id = :id AND entity_type = :entity_type AND entity_id = :entity_id';

        $statement = $this->pdo->prepare($sql);

        foreach ($expected as $index => $event) {
            $statement->execute([
                ':user_id' => $event['user_id'],
                ':user_role' => $event['user_role'],
                ':user_name' => $event['user_name'],
                ':previous_state' => $this->encodeJson($event['previous_state']),
                ':new_state' => $this->encodeJson($event['new_state']),
                ':metadata' => $this->encodeJson($event['metadata']),
                ':created_at' => $event['created_at'],
                ':id' => $stored[$index]['id'],
                ':entity_type' => 'TICKET',
                ':entity_id' => $incidentId,
            ]);
        }

        return count($expected);
    }

    /** Payload JSONB en la forma que el motor acepta, o `null` si el evento no declara estado previo. */
    private function encodeJson(mixed $value): ?string
    {
        return $value === null ? null : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Materializa la línea temporal de una avería del fixture.
     *
     * La marca se declara en la hora local del parque y se escribe en la forma que
     * cada motor entiende: con desplazamiento horario explícito en el motor nuevo
     * —`TIMESTAMPTZ` no admite una hora local sin decir de qué huso es— y en hora
     * local de pared en el motor heredado, que es como el resto de la aplicación
     * lo escribe. Corregir esa forma no es aquí donde toca; cambiarla sin más
     * desplazaría en dos horas el histórico de todas las métricas.
     *
     * Una avería reabierta declara dos vueltas y las devuelve separadas: la primera
     * resolución (`first_resolved`) es la que el indicador de reincidencias busca
     * como técnico resolutor —la reapertura va con `user_id` NULL, igual que la
     * escribe `reopen()`—, y la resolución efectiva (`resolved`) es la que reinicia
     * el reloj de las 48 h y la que el cuadro de mandos lee como instante de
     * resolución. El inicio de intervención, en cambio, se conserva de la primera
     * vuelta: la aplicación lo fija con `COALESCE(started_at, CURRENT_TIMESTAMP)`.
     *
     * @param array<string, mixed> $fixture
     * @return array{created: string, first_assigned: string, assigned: string, started: string,
     *               first_resolved: string, resolved: string, reopened: ?string, reassigned: ?string,
     *               restarted: ?string, reresolved: ?string, closed: ?string}
     */
    private function timeline(array $fixture): array
    {
        $zone = new DateTimeZone(self::PARK_TIMEZONE);
        $midnight = (new DateTimeImmutable('now', $zone))
            ->modify(sprintf('%+d days', (int)$fixture['day_offset']))
            ->setTime(0, 0, 0);

        [$hour, $minute] = array_map('intval', explode(':', (string)$fixture['reported_at']));
        $created = $midnight->setTime($hour, $minute, 0);
        $started = $created->modify(sprintf('+%d minutes', (int)$fixture['start_delay_minutes']));
        $firstResolved = $created->modify(sprintf('+%d minutes', (int)$fixture['resolution_minutes']));
        $firstAssigned = $created->modify(sprintf('+%d minutes', (int)$fixture['assign_delay_minutes']));
        $assigned = $firstAssigned;

        $reopen = $fixture['reopen'] ?? null;
        $reopened = $reassigned = $restarted = null;
        $resolved = $firstResolved;

        if (is_array($reopen)) {
            $reopened = $firstResolved->modify(sprintf('+%d minutes', (int)$reopen['after_minutes']));
            $reassigned = $reopened->modify(sprintf('+%d minutes', (int)$reopen['reassign_minutes']));
            $restarted = $reopened->modify(sprintf('+%d minutes', (int)$reopen['restart_minutes']));
            $resolved = $reopened->modify(sprintf('+%d minutes', (int)$reopen['resolution_minutes']));

            // La reapertura desasigna al técnico y es la reasignación posterior la
            // que vuelve a fijar `assigned_at`; el inicio de intervención no se toca.
            $assigned = $reassigned;
        }

        $closed = $fixture['closure_delay_minutes'] === null
            ? null
            : $resolved->modify(sprintf('+%d minutes', (int)$fixture['closure_delay_minutes']));

        return [
            'created' => $this->stamp($created),
            'first_assigned' => $this->stamp($firstAssigned),
            'assigned' => $this->stamp($assigned),
            'started' => $this->stamp($started),
            'first_resolved' => $this->stamp($firstResolved),
            'resolved' => $this->stamp($resolved),
            'reopened' => $reopened === null ? null : $this->stamp($reopened),
            'reassigned' => $reassigned === null ? null : $this->stamp($reassigned),
            'restarted' => $restarted === null ? null : $this->stamp($restarted),
            'reresolved' => $reopen === null ? null : $this->stamp($resolved),
            'closed' => $closed === null ? null : $this->stamp($closed),
        ];
    }

    /**
     * Motivo de la reapertura declarado por la sede, o `null` si no la hubo.
     *
     * @param array<string, mixed> $fixture
     */
    private function reopenReason(array $fixture): ?string
    {
        $reopen = $fixture['reopen'] ?? null;

        return is_array($reopen) ? (string)$reopen['reason'] : null;
    }

    /**
     * Diagnóstico de la resolución **efectiva**: el de la reintervención por
     * garantía cuando la hubo, porque es el que quedó escrito en el expediente.
     *
     * @param array<string, mixed> $fixture
     */
    private function effectiveDiagnosis(array $fixture): string
    {
        $reopen = $fixture['reopen'] ?? null;

        return is_array($reopen) ? (string)$reopen['diagnosis'] : (string)$fixture['diagnosis'];
    }

    /**
     * Solución de la resolución **efectiva**, con el mismo criterio que el
     * diagnóstico: manda la última vuelta.
     *
     * @param array<string, mixed> $fixture
     */
    private function effectiveAction(array $fixture): string
    {
        $reopen = $fixture['reopen'] ?? null;

        return is_array($reopen) ? (string)$reopen['action'] : (string)$fixture['action'];
    }

    /** Marca temporal en la forma del motor activo. */
    private function stamp(DateTimeImmutable $moment): string
    {
        return $this->dialect->isPostgres()
            ? $moment->format('Y-m-d H:i:sP')
            : $moment->format('Y-m-d H:i:s');
    }

    private function incidentIdOrNull(string $ticketCode): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM incidents WHERE ticket_code = :ticket_code');
        $statement->execute([':ticket_code' => $ticketCode]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    private function incidentId(string $ticketCode): int
    {
        $id = $this->incidentIdOrNull($ticketCode);

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'El motor no devolvió el expediente «%s» que se acaba de escribir: el histórico no puede colgar de un identificador inexistente.',
                $ticketCode
            ));
        }

        return $id;
    }

    /**
     * Traza de estados almacenada de un expediente, en orden de escritura.
     *
     * Se lee entera porque la guardia de forma la necesita completa: la comparación es de
     * secuencia —evento contra evento—, no de presencia.
     *
     * @return list<array{id: int, user_id: ?int, from_status: ?string, to_status: string, action_note: ?string}>
     */
    private function storedHistory(int $incidentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, from_status, to_status, action_note FROM incident_history'
            . ' WHERE incident_id = :incident_id ORDER BY id'
        );
        $statement->execute([':incident_id' => $incidentId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rastro de auditoría almacenado de un expediente, en orden de escritura.
     *
     * @return list<array{id: int, action: string}>
     */
    private function storedAudit(int $incidentId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, action FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :incident_id ORDER BY id"
        );
        $statement->execute([':incident_id' => $incidentId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ¿La traza y el rastro almacenados siguen siendo los del fixture?
     *
     * La comparación es de **forma**: mismo número de filas, mismas transiciones de estado con
     * los mismos actores y mismas acciones de auditoría, en el mismo orden de escritura. Las
     * marcas temporales no se comparan —ésa es justo la deriva que hay que corregir— y tampoco
     * los textos: si el fixture cambia una nota, la reafirmación la actualiza.
     *
     * La guardia es también la que protege al histórico que ya no es del fixture: en cuanto el
     * expediente recibe un evento que el fixture no declara —una reapertura de la sede desde el
     * portal, una cancelación del coordinador, un cierre automático del reloj sanitario, o el
     * propio fixture cambiando de forma entre versiones—, deja de ser reafirmable y el sembrador
     * no lo toca.
     *
     * @param list<array{id: int, user_id: ?int, from_status: ?string, to_status: string, action_note: ?string}> $storedHistory
     * @param list<array<string, mixed>> $expectedHistory
     * @param list<array{id: int, action: string}> $storedAudit
     * @param list<array<string, mixed>> $expectedAudit
     */
    private function matchesFixtureShape(
        array $storedHistory,
        array $expectedHistory,
        array $storedAudit,
        array $expectedAudit
    ): bool {
        if (count($storedHistory) !== count($expectedHistory) || count($storedAudit) !== count($expectedAudit)) {
            return false;
        }

        foreach ($expectedHistory as $index => $event) {
            if (!$this->sameMarker($storedHistory[$index]['from_status'], $event['from_status'])
                || !$this->sameMarker($storedHistory[$index]['to_status'], $event['to_status'])
                || !$this->sameMarker($storedHistory[$index]['user_id'], $event['user_id'])) {
                return false;
            }
        }

        foreach ($expectedAudit as $index => $event) {
            if ((string)$storedAudit[$index]['action'] !== (string)$event['action']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Comparación de una marca del fixture contra la almacenada, tolerando el `NULL`.
     *
     * Vale para los estados —el alta no tiene estado previo y el cierre automático no tiene
     * causante— y para el `user_id` de la reapertura, que va nulo porque la sede no tiene cuenta
     * interna. Los identificadores se comparan como cadena porque cada motor los devuelve con su
     * propio tipo.
     */
    private function sameMarker(mixed $stored, mixed $expected): bool
    {
        if ($stored === null || $expected === null) {
            return $stored === null && $expected === null;
        }

        return (string)$stored === (string)$expected;
    }

    /**
     * ¿La máquina ya está ocupada por **otro** expediente vivo?
     *
     * `uq_machine_active_ticket` admite una sola incidencia activa por máquina (Art. V.2 y V.6),
     * de modo que una avería viva del fixture —una en garantía— no puede ocupar el sitio que ya
     * ocupa un aviso de verdad. La consulta copia la condición del índice y excluye el propio
     * ticket del fixture: el sitio lo manda el parque, y el sembrador se aparta en vez de intentar
     * el alta y abortar el arranque del servicio.
     */
    private function machineOccupiedByOtherIncident(int $machineId, string $ticketCode): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM incidents"
            . " WHERE machine_id = :machine_id AND ticket_code <> :ticket_code"
            . " AND status NOT IN ('CLOSED', 'CANCELLED')"
        );
        $statement->execute([':machine_id' => $machineId, ':ticket_code' => $ticketCode]);

        return (int)$statement->fetchColumn() > 0;
    }

    private function userName(int $userId): string
    {
        $statement = $this->pdo->prepare('SELECT name FROM users WHERE id = :id');
        $statement->execute([':id' => $userId]);
        $name = $statement->fetchColumn();

        return $name === false ? 'Personal interno' : (string)$name;
    }

    /** Nombre de la sede, que firma las reaperturas que solicita su responsable. */
    private function locationName(int $locationId): string
    {
        $statement = $this->pdo->prepare('SELECT name FROM locations WHERE id = :id');
        $statement->execute([':id' => $locationId]);
        $name = $statement->fetchColumn();

        return $name === false ? '' : trim((string)$name);
    }

    /**
     * Catálogo maestro de causas raíz con su solución predeterminada activa.
     *
     * Si la tabla `root_causes` no existe en la base o no tiene registros,
     * devuelve un array vacío de modo que los incidentes de demo se siembren
     * sin taxonomía de forma retrocompatible.
     *
     * @return array<string, array{root_cause_id: int, standard_solution_id: int, root_cause_code: string, root_cause_category: string, root_cause_name: string, standard_solution_code: string, standard_solution_name: string}>
     */
    private function rootCauseCatalog(): array
    {
        try {
            $sql = "
                SELECT
                    rc.id AS root_cause_id,
                    rc.code AS root_cause_code,
                    rc.category AS root_cause_category,
                    rc.name AS root_cause_name,
                    ss.id AS standard_solution_id,
                    ss.code AS standard_solution_code,
                    ss.name AS standard_solution_name
                FROM root_causes rc
                JOIN standard_solutions ss ON ss.root_cause_id = rc.id
                WHERE rc.is_active = TRUE
                  AND ss.is_active = TRUE
                  AND ss.is_default = TRUE
            ";

            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return [];
            }

            $catalog = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $catalog[(string)$row['root_cause_code']] = [
                    'root_cause_id' => (int)$row['root_cause_id'],
                    'standard_solution_id' => (int)$row['standard_solution_id'],
                    'root_cause_code' => (string)$row['root_cause_code'],
                    'root_cause_category' => (string)$row['root_cause_category'],
                    'root_cause_name' => (string)$row['root_cause_name'],
                    'standard_solution_code' => (string)$row['standard_solution_code'],
                    'standard_solution_name' => (string)$row['standard_solution_name'],
                ];
            }

            return $catalog;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Parque de máquinas del catálogo maestro, indexado por código.
     *
     * @return array<string, array{id: int, location_id: int, machine_type: string}>
     */
    private function machineCatalog(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, code, location_id, machine_type FROM machines WHERE deleted_at IS NULL'
        );

        $catalog = [];
        foreach ($statement->fetchAll() as $row) {
            $catalog[(string)$row['code']] = [
                'id' => (int)$row['id'],
                'location_id' => (int)$row['location_id'],
                'machine_type' => (string)$row['machine_type'],
            ];
        }

        return $catalog;
    }

    /**
     * Personal interno del catálogo maestro, indexado por correo: técnicos y
     * coordinación.
     *
     * @return array<string, int>
     */
    private function technicianCatalog(): array
    {
        $statement = $this->pdo->query('SELECT id, email, role FROM users WHERE deleted_at IS NULL');

        $catalog = [];
        foreach ($statement->fetchAll() as $row) {
            if ((string)$row['role'] !== 'TECHNICIAN') {
                continue;
            }
            $catalog[(string)$row['email']] = (int)$row['id'];
        }

        return $catalog;
    }

    /** Identificador de la coordinación del servicio, que firma la asignación. */
    private function coordinator(): int
    {
        $statement = $this->pdo->query('SELECT id, role FROM users WHERE deleted_at IS NULL');

        foreach ($statement->fetchAll() as $row) {
            if ((string)$row['role'] === 'COORDINATOR') {
                return (int)$row['id'];
            }
        }

        throw new RuntimeException(
            'No hay ninguna cuenta de coordinación en el catálogo maestro: el histórico no puede atribuir la asignación.'
        );
    }
}
