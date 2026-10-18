# deploy/cron · Dónde vive el reloj del barrido sanitario

**Decisión ratificada (módulo 12, spec §4 RF-06 y plan §7):** el reloj canónico del
barrido biológico vive **dentro del contenedor de aplicaciones**, y su despertador y
vigilante vive **fuera**, en el flujo programado del repositorio. Ni la lógica ni el
reloj viven en el motor de base de datos: `pg_cron` + `pg_net` queda descartado como
orquestador canónico (EARS-06.6 lo admite solo con la credencial en un almacén del
proveedor), porque el programador interno corre dentro de la base que se suspende y
`pg_net` llama con 2 s de timeout a un contenedor que también se duerme.

| Pieza | Artefacto versionado | Qué cubre |
| :--- | :--- | :--- |
| Reloj canónico | [`vendguard-sanitary-sweep`](vendguard-sanitary-sweep) → `/etc/crontabs/root` | La cadencia de 15 minutos del barrido sanitario (`bin/sanitary_clock_sweep.php`) y la cadencia de 1 minuto del despachador de notificaciones (`bin/send_notifications.php`) mientras el contenedor está en pie. Sin red y sin credenciales expuestas. |
| Despertador y vigilante | [`.github/workflows/sanitary-clock.yml`](../../.github/workflows/sanitary-clock.yml) *(o planificador externo HTTP como `cron-job.org` / Cloudflare Workers)* | Despierta el contenedor si está suspendido, detecta la ventana perdida, invoca los servicios canónicos por sus endpoints autenticados y **alerta**. |

## Instalación en la imagen (tarea T-HYBRID-12)

1. `COPY deploy/cron/vendguard-sanitary-sweep /etc/crontabs/root`
2. `RUN chmod 600 /etc/crontabs/root`
3. Arrancar el demonio antes del servidor web, en el mismo `CMD`:
   `sh -c "crond -b -l 8 ; php bin/init_cloud_db.php ; php -S 0.0.0.0:${PORT} -t public public/index.php"`
4. Generar `/etc/vendguard/cron.env` en el arranque a partir del entorno del contenedor
   (los trabajos de `crond` no heredan el entorno de PID 1).

`crond` forma parte de busybox y está presente en la imagen Alpine con la que ya se
construye: no añade dependencias externas (Dogma Vanilla, Art. IV).

### Reintento con espera progresiva

La entrada reintenta la invocación fallida dentro de la misma pasada, con esperas de
**0, 30, 60 y 120 segundos**, y termina en **1** si agota los cuatro intentos (EARS-06.5).
Reintentar es seguro por construcción: la pasada de `SanitaryClockSweepService` es
idempotente, de modo que el segundo intento de una pasada que ya había avanzado no
duplica cuarentenas ni rastros. El techo de la cadena (210 s) queda muy por debajo de la
ventana de 15 minutos, así que dos pasadas nunca se solapan; y el `exit 1` final es lo que
hace que el fallo **no muera en silencio**: queda en el registro de la plataforma y el
vigilante externo lo ve como ventana perdida y alerta.

### El host público del botón de los correos (T-NOTIF-17)

`/etc/vendguard/cron.env` se genera con `env > …`, de modo que la entrada de cada minuto hereda **todo** el entorno del
contenedor, incluido `APP_BASE_URL`. Declararla en el panel del despliegue es lo que hace que el aviso a la Sede salga
con el botón absoluto: sin ella, el aviso se entrega igual —la sede conoce su ticket— pero el botón viaja como ruta
relativa y el cliente de correo lo resuelve contra su propio dominio. La pasada no lo calla: publica
`relative_tracking_links_count` en el resumen y el worker escribe el remedio en su salida de error, que aquí queda
redirigida al registro de la plataforma.

## La variante `pg_cron` + `pg_net` (admitida, no canónica)

EARS-06.6 la admite como orquestador alternativo y aquí queda documentada con sus
condiciones, que son dos y no negociables:

1. **La credencial se lee en tiempo de ejecución de un almacén de secretos del proveedor
   (Vault).** Queda expresamente prohibido incorporar el secreto al DDL versionado, a
   `database/supabase_init.sql`, a una migración o a cualquier fichero del repositorio.
2. **No se instala de forma incondicional.** `pg_cron` (y `pg_net`) solo van en el DDL de
   la variante que decide usarlas; el DDL base no las declara, porque el motor local de la
   categoría de certificación no las ofrece y escribirlas ahí rompería la certificación
   precisamente en el motor donde se ejecuta (plan §8).

Y no es el reloj canónico, por un motivo que no depende de la configuración: **su
programador corre dentro del motor que se suspende**, y `pg_net` llama con 2 s de timeout
a un contenedor que también puede estar dormido. Un barrido sanitario que no se ejecuta en
silencio es el peor fallo posible para el Art. II, así que el reloj canónico sigue siendo
este del contenedor, con el vigilante externo encima. Habilitarla exigiría además
documentar el aprovisionamiento de la extensión y del secreto en el Vault, que hoy no
cubre ninguna tarea del plan.

## Cómo se verifica que el reloj existe y late

1. **El artefacto existe y está instalado:** el fichero está en el repositorio y en
   `/etc/crontabs/root` con modo `600`.
2. **El demonio corre:** `ps aux | grep crond` muestra el proceso en el contenedor.
3. **El reloj late de verdad:** aparece una pasada nueva en el historial del barrido
   (`sanitary_sweep_passes`) dentro de cada ventana de 15 minutos, y la telemetría
   autenticada de `GET /api/status` expone `sanitary_clock.last_pass_at`.
4. **El vigilante muerde:** si se detiene el demonio con el contenedor en pie, la
   siguiente ejecución del flujo programado detecta la ventana perdida, invoca el
   barrido por el endpoint autenticado y deja constancia del incidente.
5. **Reintenta y falla en voz alta:** con el enlace caído, una invocación se reintenta con
   espera progresiva y, si agota los cuatro intentos, el proceso termina en `1` (no en 0).

Ninguna de estas comprobaciones se apoya en «lo he configurado en un panel»: las
cuatro son observables desde el repositorio y desde el propio registro de pasadas.
