# 🐘 FrankenPHP Drop-In Dev Server & `frank` CLI

<p align="center">
  <img src="https://raw.githubusercontent.com/dunglas/frankenphp/main/docs/logo.svg" width="120" alt="FrankenPHP Logo" />
</p>

<p align="center">
  <strong>An XAMPP-like drop-in PHP development server powered by FrankenPHP, Caddy, Mercure SSE Hub, and the <code>frank</code> CLI manager.</strong>
</p>

<p align="center">
  <a href="#-1-line-installation"><img src="https://img.shields.io/badge/Install-1--Line%20Curl-blue.svg?style=for-the-badge" alt="1-Line Install"></a>
  <a href="#-the-frank-cli"><img src="https://img.shields.io/badge/CLI-frank-magenta.svg?style=for-the-badge" alt="frank CLI"></a>
  <a href="#-built-in-mercure-hub"><img src="https://img.shields.io/badge/RealTime-Mercure%20SSE-emerald.svg?style=for-the-badge" alt="Mercure SSE"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-yellow.svg?style=for-the-badge" alt="MIT License"></a>
</p>

---

## ⚡ 1-Line Installation

Run this single command on **Linux**, **macOS**, or **WSL**:

```bash
curl -fsSL https://raw.githubusercontent.com/juandavidmm/frankenphp-dev-server/main/install.sh | bash
```

> **What it does:**
> 1. Verifies Docker & Docker Compose prerequisites.
> 2. Configures `/var/www` with permissions and secure Mercure JWT keys.
> 3. Installs the global `frank` CLI command into `/usr/local/bin/frank`.
> 4. Builds and starts FrankenPHP, Redis, and Adminer containers.

---

## 📁 How Drop-In Hosting Works (XAMPP-Style)

Drop any project or PHP file directly into `/var/www` and access it instantly in your browser:

| File / Folder in `/var/www` | Access URL | Auto-Routing Behavior |
| :--- | :--- | :--- |
| `/var/www/test.php` | `http://localhost/test.php` | Direct PHP execution |
| `/var/www/my-site/index.php` | `http://localhost/my-site/` | Flat / WordPress / standard PHP |
| `/var/www/laravel-app/public/index.php` | `http://localhost/laravel-app/` | **Auto-routes into `public/` directory** |

---

## 🛠️ The `frank` CLI

Manage your development environment effortlessly from anywhere in your terminal:

```bash
frank start            # Start all services in the background (alias: frank up)
frank stop             # Stop all services (alias: frank down)
frank restart          # Restart services
frank status           # Show container health, open ports & active projects
frank logs [-f]        # View and follow live server logs
frank reload           # Hot-reload Caddyfile configuration with zero downtime
frank bash             # Open an interactive shell inside the FrankenPHP container
frank composer <args>  # Run Composer commands directly inside the container
frank open             # Open the Developer Hub in your default browser
frank doctor           # Run automated environment health checks
frank test-mercure     # Dispatch a test event to the Mercure SSE Hub
```

### 🚀 Scaffolding New Projects with `frank new`
```bash
# Create a flat PHP project
frank new blog --flat

# Create a fresh Laravel application
frank new shop --laravel

# Create a fresh Symfony application
frank new api --symfony
```

---

## 🌐 Web Endpoints

- 🐘 **Developer Hub Dashboard**: [http://localhost](http://localhost)
- ⚡ **Mercure SSE Console**: [http://localhost/mercure-demo.html](http://localhost/mercure-demo.html)
- 🗄️ **Adminer Database GUI**: [http://localhost:8080](http://localhost:8080)
- 🩺 **Health Check**: [http://localhost/healthz](http://localhost/healthz)

---

## ⚡ Built-In Mercure Real-Time SSE Hub

FrankenPHP comes with an integrated **Mercure Hub** for push notifications and Server-Sent Events (SSE).

### 1. Subscribe in JavaScript (Frontend)
```javascript
const eventSource = new EventSource('/.well-known/mercure?topic=' + encodeURIComponent('https://example.com/notifications'));

eventSource.onmessage = (event) => {
    console.log('Real-time event received:', event.data);
};
```

### 2. Publish in PHP (Backend)
```php
$response = file_get_contents('http://localhost/mercure-test.php', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'topic'   => 'https://example.com/notifications',
            'message' => 'Hello from PHP in Real-Time! 🐘'
        ])
    ]
]));
```

---

## 📦 Stack Specifications

- **FrankenPHP**: v1.12+
- **PHP**: 8.5 / 8.4 (ZTS)
- **Caddy**: v2.11+ with automatic HTTP/2, HTTP/3 (QUIC), Zstandard & Gzip compression
- **PHP Extensions**: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `redis`, `opcache`, `intl`, `zip`, `pcntl`, `apcu`, `bcmath`, `gd`, `xdebug`
- **Cache & Queues**: Redis Alpine
- **Database GUI**: Adminer (supports MySQL, PostgreSQL, SQLite)

---

## 📄 License

Open-source software licensed under the [MIT License](LICENSE).
