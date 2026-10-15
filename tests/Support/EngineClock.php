<?php

declare(strict_types=1);

/**
 * VendGuard - Reloj del arnés frente al motor único (dogma Vanilla, sin dependencias)
 *
 * Declara, del lado del arnés, la regla que el motor ya aplica del lado del banco: **un instante
 * que cruza hacia una columna con zona horaria se escribe en la zona de la sesión del motor**, no
 * en la hora local de la máquina ni en la hora local de sede.
 *
 * Por qué existe. Las marcas del esquema (`incidents.created_at`, `resolved_at`, `paused_at`,
 * `sla_target_at`, `audit_log.created_at`, …) son `timestamptz` y la sesión del motor va en
 * **UTC**: es el valor por defecto de PostgreSQL y el del contenedor desplegado, cuyo `php.ini`
 * tampoco declara `date.timezone`. Un `'Y-m-d H:i:s'` sin zona no dice lo que parece: el motor lo
 * interpreta en *su* zona, de modo que el instante guardado queda el huso entero por delante del
 * que la suite quería sembrar. Con el huso de verano, una suite que sembraba «49 horas» veía al
 * motor medir 47 —una ventana de cierre de 48 h que no cerraba nada—, y los minutos de espera de
 * un tablero salían 120 minutos cortos porque el instante sembrado caía en el futuro.
 *
 * La dirección contraria ya la cubre el motor: una lectura de `timestamptz` viaja **con su
 * desfase** (`2026-10-15 20:40:00+00`), así que `strtotime()` la interpreta en su instante real y
 * no depende del huso de la máquina. Lo que sí dependía era el render de un `new
 * DateTimeImmutable()` o un `date()` desnudos, que tomaban la zona del `php.ini` del desarrollador
 * y hacían que el veredicto de la batería cambiara de máquina en máquina. Por eso `bootstrap.php`
 * declara el reloj del arnés en lugar de heredarlo (EARS-13.5 y 13.6) y `HarnessClockContractTest`
 * certifica la declaración, la sesión viva y el censo de instantes.
 *
 * Qué no hace esta clase: no decide el contrato de las marcas que el *dominio* persiste como hora
 * local de sede (`sla_target_at` y compañía). Esa reconciliación —si el dominio sigue escribiendo
 * pared de sede o pasa a escribir instantes— es una decisión del contrato del motor único y vive
 * en el lote que toque esas suites (EARS-13.10).
 */
final class EngineClock
{
    /** Zona de la sesión del motor. `HarnessClockContractTest` la contrasta con `SHOW TimeZone`. */
    public const SESSION_TIMEZONE = 'UTC';

    /** Zona operativa del parque: la que denotan los instantes locales del sistema. */
    public const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Zona de la sesión del motor. */
    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::SESSION_TIMEZONE);
    }

    /** Instante actual en la zona del motor: el reloj real, con el huso explícito. */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    /**
     * Marca `Y-m-d H:i:s` de un instante **en la zona del motor**, lista para una columna con zona.
     *
     * @param DateTimeImmutable $instant Instante en cualquier zona (el instante no cambia, cambia
     *                                   solo su representación).
     * @return string Marca sin desfase, ya en la zona que el motor aplicará al interpretarla.
     */
    public static function stamp(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    /**
     * Marca del instante que queda `$seconds` segundos antes del reloj del arnés (o después, con
     * signo negativo): el idioma directo para sembrar «hace N minutos/horas/días» sin que el huso
     * de la máquina se cuele en la resta.
     *
     * @param int $seconds Segundos a retroceder (negativos avanzan hacia el futuro).
     * @return string Marca en la zona del motor.
     */
    public static function shift(int $seconds): string
    {
        // El signo es obligatorio en el modificador relativo (`+900`/`-900`); `%+d` lo garantiza
        // también para el cero, de modo que `shift(0)` es «ahora mismo».
        $moment = self::now()->modify(sprintf('%+d seconds', $seconds));

        return self::stamp($moment);
    }
}
