FROM php:8.2-cli-alpine

# Instalar dependencias de sistema:
# - ca-certificates para TLS
# - postgresql-dev para soporte compilado de PostgreSQL
# - $PHPIZE_DEPS para la cadena de compilación de extensiones
RUN apk add --no-cache ca-certificates postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql \
    && apk del $PHPIZE_DEPS

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar el proyecto completo
COPY . .

# Instalar reloj canónico de barrido sanitario (EARS-06.2, deploy/cron/vendguard-sanitary-sweep)
# en /etc/crontabs/root con permisos 600
RUN mkdir -p /etc/crontabs /etc/vendguard \
    && cp deploy/cron/vendguard-sanitary-sweep /etc/crontabs/root \
    && chmod 600 /etc/crontabs/root

# Crear y dar permisos a la carpeta de uploads para evidencias fotográficas
RUN mkdir -p public/uploads && chmod -R 777 public/uploads

# Puerto dinámico asignado por el entorno (Render, Railway, etc.)
ENV PORT=8080
EXPOSE 8080

# En arranque:
# 1. Exportar entorno a /etc/vendguard/cron.env para las tareas de crond
# 2. Arrancar demonio crond en segundo plano
# 3. Iniciar inicializador de BD en la nube (tablas, migraciones y semillas)
# 4. Arrancar servidor web PHP
CMD ["sh", "-c", "env > /etc/vendguard/cron.env && chmod 600 /etc/vendguard/cron.env && crond -b && php bin/init_cloud_db.php && php -S 0.0.0.0:${PORT} -t public public/index.php"]
