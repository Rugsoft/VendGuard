FROM php:8.2-cli-alpine

# Instalar extensiones nativas PDO y MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar el proyecto completo
COPY . .

# Crear y dar permisos a la carpeta de uploads para evidencias fotográficas
RUN mkdir -p public/uploads && chmod -R 777 public/uploads

# Puerto dinámico asignado por el entorno (Render, Railway, etc.)
ENV PORT=8080
EXPOSE 8080

# Iniciar servidor web embebido de PHP con router integrado
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public public/index.php"]
