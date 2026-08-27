# ============================================================
#  Luitech — Contenedor de producción (PHP 8.3 + Apache)
#  Dokploy/docker build: usa este Dockerfile automáticamente.
#  Las credenciales de BD llegan como variables de entorno del panel
#  (DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD).
# ============================================================
FROM php:8.3-apache

# Extensión PDO MySQL (no viene en la imagen base)
RUN docker-php-ext-install pdo pdo_mysql

# Módulos de Apache necesarios (cabeceras de seguridad del .htaccess)
RUN a2enmod rewrite headers

# Permitir que el .htaccess del proyecto aplique sus directivas
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
      > /etc/apache2/conf-available/luitech.conf \
    && a2enconf luitech

# Código de la aplicación
COPY --chown=www-data:www-data . /var/www/html/

EXPOSE 80
# Crea/verifica tablas en cada arranque (idempotente) y luego sirve la web
CMD ["sh", "-c", "php /var/www/html/database/migrate.php; exec apache2-foreground"]
