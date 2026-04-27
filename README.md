<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## Kinesilk API — Endpoints

### Públicos (sin autenticación)

| Método | Ruta | Descripción |
| ------ | ---- | ----------- |
| `GET` | `/api/v1/available_slots` | Slots disponibles para reserva |
| `GET` | `/api/v1/services` | Listado de servicios |
| `GET` | `/api/v1/services/{id}` | Detalle de un servicio |
| `GET` | `/api/v1/locations` | Listado de locales |
| `GET` | `/api/v1/locations/{id}` | Detalle de un local |
| `GET` | `/api/v1/packs` | Listado de packs de servicios |
| `GET` | `/api/v1/packs/{id}` | Detalle de un pack |
| `POST` | `/api/v1/webhooks/woocommerce` | Webhook de WooCommerce (HMAC-SHA256) |

### Autenticados — Bearer token + scope requerido

#### Bookings

| Método | Ruta | Scope | Descripción |
| ------ | ---- | ----- | ----------- |
| `GET` | `/api/v1/bookings` | `bookings:read` | Listado de reservas |
| `GET` | `/api/v1/bookings/{id}` | `bookings:read` | Detalle de una reserva |
| `POST` | `/api/v1/bookings` | `bookings:write` | Crear reserva |
| `PATCH` | `/api/v1/bookings/{id}` | `bookings:write` | Actualizar reserva |
| `PATCH` | `/api/v1/bookings/{id}/cancel` | `bookings:write` | Cancelar reserva |

#### Clients

| Método | Ruta | Scope | Descripción |
| ------ | ---- | ----- | ----------- |
| `GET` | `/api/v1/clients` | `clients:read` | Listado de clientes |
| `GET` | `/api/v1/clients/{id}` | `clients:read` | Detalle de un cliente |
| `POST` | `/api/v1/clients` | `clients:write` | Crear cliente |
| `PATCH` | `/api/v1/clients/{id}` | `clients:write` | Actualizar cliente |
| `PATCH` | `/api/v1/clients/{id}/deactivate` | `clients:write` | Desactivar cliente |
| `GET` | `/api/v1/clients/{id}/attributes` | `clients:read` | Atributos personalizados del cliente |
| `GET` | `/api/v1/clients/{id}/packs` | `clients:read` | Packs del cliente |

#### Providers

| Método | Ruta | Scope | Descripción |
| ------ | ---- | ----- | ----------- |
| `GET` | `/api/v1/providers` | `providers:read` | Listado de proveedores |
| `GET` | `/api/v1/providers/{id}` | `providers:read` | Detalle de un proveedor |

#### Sales

| Método | Ruta | Scope | Descripción |
| ------ | ---- | ----- | ----------- |
| `GET` | `/api/v1/sales` | `sales:read` | Listado de ventas |
| `GET` | `/api/v1/sales/{id}` | `sales:read` | Detalle de una venta |

#### Client Packs

| Método | Ruta | Scope | Descripción |
| ------ | ---- | ----- | ----------- |
| `GET` | `/api/v1/client-packs` | `clients:read` | Listado de packs de clientes |
| `GET` | `/api/v1/client-packs/{id}` | `clients:read` | Detalle de un pack de cliente |
| `POST` | `/api/v1/client-packs` | `clients:write` | Asignar pack a cliente |
| `PATCH` | `/api/v1/client-packs/{id}/use` | `bookings:write` | Consumir sesión de un pack |

#### Custom Attributes

| Método | Ruta | Scope | Descripción |
| ------ | ---- | ----- | ----------- |
| `GET` | `/api/v1/custom_attributes` | `clients:read` | Listado de atributos personalizados |
