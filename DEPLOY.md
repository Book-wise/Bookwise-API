# Deploy — Kinesilk API

> **Stack**: Laravel 13 · PHP 8.3 · MySQL 8 · Composer 2 · Node 20+
>
> **Repositorio**: [github.com/sebaquevedo/Bookwise-API](https://github.com/sebaquevedo/Bookwise-API)

---

## Índice

- [Requisitos del servidor](#requisitos-del-servidor)
- [Recomendaciones de hosting](#recomendaciones-de-hosting)
- [Deploy en HostGator (cPanel)](#deploy-en-hostgator-cpanel)
- [Deploy en DigitalOcean (VPS)](#deploy-en-digitalocean-vps)
- [Configuración post-deploy](#configuración-post-deploy)
- [Mantenimiento](#mantenimiento)

---

## Requisitos del servidor

| Requisito | Mínimo | Recomendado |
|-----------|--------|-------------|
| PHP | 8.2 | **8.3** |
| Extensiones PHP | BCMath, Ctype, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML, MySQL, GD | mismas |
| Base de datos | MySQL 8.0 | MySQL 8.0+ |
| Composer | 2.x | 2.x |
| Node.js | 18.x | 20.x (para build de frontend si aplica) |
| Servidor web | Apache con mod_rewrite o Nginx | Nginx |
| HTTPS | SSL/TLS obligatorio | Let's Encrypt (Certbot) |

### Extensiones PHP requeridas

```bash
# Ubuntu/Debian
sudo apt install php8.3-cli php8.3-common php8.3-mysql php8.3-xml \
                 php8.3-mbstring php8.3-curl php8.3-bcmath php8.3-gd \
                 php8.3-zip php8.3-intl
```

---

## Recomendaciones de hosting

| Proveedor | Tipo | Ideal para |
|-----------|------|------------|
| **HostGator** | Shared hosting (cPanel) | Clientes actuales de Kinesilk, fácil manejo |
| **DigitalOcean** | VPS (Droplet) | Más control, mejor rendimiento, escalable |
| **Laravel Cloud** | Serverless | Si se quiere cero mantenimiento de servidor |

---

## Deploy en HostGator (cPanel)

### 1. Subir archivos

**Opción A — Git (recomendada):**

```bash
# Acceder por SSH desde cPanel → Terminal
cd /home/tuusuario/public_html
git clone https://github.com/sebaquevedo/Bookwise-API.git kinesilk-api
cd kinesilk-api
```

**Opción B — FTP:**

Subir todo el proyecto a `/public_html/kinesilk-api/` excepto:
- `.env` (se crea manualmente)
- `vendor/` (se instala con Composer)
- `node_modules/` (no necesario para el backend)

### 2. Configurar archivo `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Editar `.env` con los valores de producción:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kinesilk.cl/api

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=tu_base_de_datos
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password

MAIL_MAILER=smtp
MAIL_HOST=mail.kinesilk.cl
MAIL_PORT=465
MAIL_USERNAME=info@kinesilk.cl
MAIL_PASSWORD=********
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="info@kinesilk.cl"
MAIL_FROM_NAME="Kinesilk"

# Para migrar a Resend después (solo cambiar esto):
# MAIL_MAILER=resend
# RESEND_API_KEY=re_...

WC_BASE_URL=https://www.kinesilk.cl
WC_CONSUMER_KEY=ck_...
WC_CONSUMER_SECRET=cs_...
WC_WEBHOOK_SECRET=...
```

### 3. Instalar dependencias

```bash
composer install --optimize-autoloader --no-dev
```

### 4. Migrar base de datos

```bash
php artisan migrate --force
```

### 5. Cachear configuraciones

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 6. Configurar directorio público

Crear un archivo `.htaccess` en `/public_html/kinesilk-api/public/` si Apache no redirige bien, o configurar un subdominio que apunte a `/public_html/kinesilk-api/public`.

**HostGator — Redirección a `public/`:**

Si el dominio raíz apunta a la carpeta del proyecto, crear o modificar `public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

> ⚠️ **Alternativa más limpia**: crear un **subdominio** (ej: `api.kinesilk.cl` o `backend.kinesilk.cl`) desde cPanel → Domains y apuntarlo a la carpeta `kinesilk-api/public`.

### 7. Configurar el CRON (⚠️ OBLIGATORIO para recordatorios)

Sin este paso, los recordatorios de reserva **NUNCA se enviarán**.

Desde cPanel → **Cron Jobs**:

```cron
* * * * * /usr/local/bin/php /home/tuusuario/public_html/kinesilk-api/artisan schedule:run >> /dev/null 2>&1
```

> Reemplazar `/home/tuusuario/public_html/kinesilk-api/` por la ruta real donde esté instalado el proyecto.

**¿Qué hace esto?** Ejecuta `php artisan schedule:run` cada minuto. Laravel internamente revisa si hay tareas programadas para ese minuto y las ejecuta. Nuestras tareas:

| Tarea | Frecuencia | Qué hace |
|-------|-----------|----------|
| `app:send-booking-reminders` | Cada 15min | Busca reservas próximas y envía recordatorios por email (24h y 30min antes) |

**Las notificaciones inmediatas** (al crear reserva, al pagar) **sí funcionan sin el cron** porque se disparan con la request HTTP.

### 8. Configurar cola de trabajos (opcional)

Si se quiere encolar correos para no ralentizar las respuestas HTTP:

```bash
# En el cron, agregar además:
* * * * * /usr/local/bin/php /home/tuusuario/public_html/kinesilk-api/artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

Actualmente los correos se envían sincrónicamente (no requieren worker).

---

## Deploy en DigitalOcean (VPS)

### 1. Crear Droplet

- **SO**: Ubuntu 24.04 LTS
- **Plan**: Basic ($6/mo — 1GB RAM / 1 CPU es suficiente para empezar)
- **Región**: Chile o US (lo que prefieras)
- **Autenticación**: SSH key (más seguro que contraseña)

### 2. Conectarse por SSH

```bash
ssh root@<IP_DEL_DROPLET>
```

### 3. Instalar stack LEMP

```bash
# Actualizar paquetes
apt update && apt upgrade -y

# Nginx
apt install nginx -y

# PHP 8.3 + extensiones
apt install php8.3-fpm php8.3-cli php8.3-common php8.3-mysql \
            php8.3-xml php8.3-mbstring php8.3-curl php8.3-bcmath \
            php8.3-gd php8.3-zip php8.3-intl -y

# MySQL 8
apt install mysql-server -y

# Composer
apt install composer -y

# Git
apt install git -y

# Certbot (SSL)
apt install certbot python3-certbot-nginx -y
```

### 4. Configurar MySQL

```bash
mysql_secure_installation

# Crear base de datos y usuario
mysql -u root -p
```

```sql
CREATE DATABASE kinesilk_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kinesilk'@'localhost' IDENTIFIED BY 'tu_password_segura';
GRANT ALL PRIVILEGES ON kinesilk_api.* TO 'kinesilk'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 5. Clonar proyecto

```bash
cd /var/www
git clone https://github.com/sebaquevedo/Bookwise-API.git kinesilk-api
cd kinesilk-api
```

### 6. Configurar permisos

```bash
chown -R www-data:www-data /var/www/kinesilk-api
chmod -R 755 /var/www/kinesilk-api/storage
chmod -R 755 /var/www/kinesilk-api/bootstrap/cache
```

### 7. Configurar `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Editar `.env` (ver [sección de HostGator paso 2](#2-configurar-archivo-env) para los valores).

**DigitalOcean específico:**

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.kinesilk.cl

DB_HOST=127.0.0.1
DB_DATABASE=kinesilk_api
DB_USERNAME=kinesilk
DB_PASSWORD=tu_password_segura
```

### 8. Instalar dependencias

```bash
composer install --optimize-autoloader --no-dev
```

### 9. Migrar

```bash
php artisan migrate --force
```

### 10. Cachear

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 11. Configurar Nginx

```bash
nano /etc/nginx/sites-available/kinesilk-api
```

```nginx
server {
    listen 80;
    server_name api.kinesilk.cl;
    root /var/www/kinesilk-api/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Activar sitio:

```bash
ln -s /etc/nginx/sites-available/kinesilk-api /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

### 12. SSL con Let's Encrypt (HTTPS)

```bash
certbot --nginx -d api.kinesilk.cl
```

Esto modifica la configuración de Nginx para agregar SSL automáticamente.

### 13. Configurar CRON (⚠️ OBLIGATORIO para recordatorios)

```bash
# Editar crontab del usuario www-data
crontab -e -u www-data
```

Agregar línea:

```cron
* * * * * cd /var/www/kinesilk-api && php artisan schedule:run >> /dev/null 2>&1
```

### 14. Configurar worker de cola (opcional)

```bash
nano /etc/systemd/system/queue-worker.service
```

```ini
[Unit]
Description=Laravel Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=php /var/www/kinesilk-api/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable queue-worker
systemctl start queue-worker
```

---

## Configuración post-deploy

### Verificar que todo funciona

```bash
# Probar que la API responde
curl https://api.kinesilk.cl/api/v1/services

# Verificar scheduler (debe mostrar las tareas)
php artisan schedule:list

# Ver logs si algo falla
tail -f /var/www/kinesilk-api/storage/logs/laravel.log

# En HostGator, los logs están en:
# /home/tuusuario/public_html/kinesilk-api/storage/logs/laravel.log
```

### Probar envío de email

```bash
php artisan tinker
```

```php
Mail::raw('Prueba desde producción', fn ($msg) => $msg->to('tu@email.com')->subject('Test Kinesilk'));
```

### Probar recordatorios manualmente

```bash
php artisan app:send-booking-reminders
```

---

## Mantenimiento

### Actualizar código

```bash
cd /var/www/kinesilk-api

# HostGator (con git)
cd ~/public_html/kinesilk-api
git pull origin main

# DigitalOcean
cd /var/www/kinesilk-api
git pull origin main

# En ambos:
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Monitorear logs

```bash
# DigitalOcean
tail -f /var/www/kinesilk-api/storage/logs/laravel.log

# HostGator
tail -f ~/public_html/kinesilk-api/storage/logs/laravel.log
```

### Backup de base de datos

```bash
# DigitalOcean
mysqldump -u kinesilk -p kinesilk_api > backup_$(date +%Y%m%d).sql

# HostGator — desde cPanel → phpMyAdmin o Backup Wizard
```

---

## Resumen de tareas programadas

| Comando | Frecuencia | Descripción | Requiere CRON |
|---------|-----------|-------------|---------------|
| `app:send-booking-reminders` | cada 15min | Envía recordatorios 24h y 30min antes de la cita | ✅ **Sí** |
| `app:send-booking-reminders` (notif. inmediatas) | al crear reserva | Envía confirmación de cita | ❌ No |

---

## Migración de SMTP a Resend

Si en el futuro se quiere cambiar a Resend (más simple, mejor deliverability):

```env
# En .env, cambiar:
MAIL_MAILER=resend
RESEND_API_KEY=re_...   # Tu API key de Resend

# Opcional: mantener SMTP como fallback
# MAIL_MAILER=failover
# (configurar mailers failover en config/mail.php)
```

Sin tocar una línea de código — Laravel abstrae el driver de mail.

---

## Troubleshooting

### Error 500 en producción

```bash
# Ver el error real
tail -f storage/logs/laravel.log

# Causas comunes:
# 1. Falta config:cache después de cambiar .env
# 2. Permisos incorrectos en storage/
# 3. Extensión PHP faltante
```

### Correos no llegan

1. Verificar configuración SMTP en `.env`
2. Probar con `php artisan tinker` (comando de test más arriba)
3. Revisar logs de Laravel (`storage/logs/laravel.log`)
4. HostGator: algunos planes bloquean puerto 465 — probar con puerto 587 y `MAIL_ENCRYPTION=tls`

### CRON no ejecuta recordatorios

```bash
# Verificar que el cron esté activo
crontab -l

# Ejecutar manualmente para ver errores
php artisan app:send-booking-reminders
```
