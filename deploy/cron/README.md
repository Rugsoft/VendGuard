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
| Reloj canónico | [`vendguard-sanitary-sweep`](vendguard-sanitary-sweep) → `/etc/crontabs/root` | La cadencia de 15 minutos mientras el contenedor está en pie. Sin red y sin credenciales expuestas. |
| Despertador y vigilante | [`.github/workflows/sanitary-clock.yml`](../../.github/workflows/sanitary-clock.yml) | Despierta el contenedor si está suspendido, detecta la ventana perdida, invoca el mismo servicio canónico por el endpoint autenticado y **alerta**. |

## Instalación en la imagen (tarea T-HYBRID-12)

1. `COPY deploy/cron/vendguard-sanitary-sweep /etc/crontabs/root`
2. `RUN chmod 600 /etc/crontabs/root`
3. Arrancar el demonio antes del servidor web, en el mismo `CMD`:
   `sh -c "crond -b -l 8 ; php bin/init_cloud_db.php ; php -S 0.0.0.0:${PORT} -t public public/index.php"`
4. Generar `/etc/vendguard/cron.env` en el arranque a partir del entorno del contenedor
   (los trabajos de `crond` no heredan el entorno de PID 1).

`crond` forma parte de busybox y está presente en la imagen Alpine con la que ya se
construye: no añade dependencias externas (Dogma Vanilla, Art. IV).

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

Ninguna de estas comprobaciones se apoya en «lo he configurado en un panel»: las
cuatro son observables desde el repositorio y desde el propio registro de pasadas.
