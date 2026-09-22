FROM php:8.2-cli-alpine

# Instalar ca-certificates para TLS y extensiones PDO y MySQL nativas
RUN apk add --no-cache ca-certificates \
    && docker-php-ext-install pdo pdo_mysql

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar el proyecto completo
COPY . .

# Crear y dar permisos a la carpeta de uploads para evidencias fotográficas
RUN mkdir -p public/uploads && chmod -R 777 public/uploads

# Puerto dinámico asignado por el entorno (Render, Railway, etc.)
ENV PORT=8080
EXPOSE 8080

# Iniciar inicializador de BD en la nube (tablas y semillas) y arrancar servidor web
CMD ["sh", "-c", "php bin/init_cloud_db.php ; php -S 0.0.0.0:${PORT} -t public public/index.php"]
