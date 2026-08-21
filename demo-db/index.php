<?php
declare(strict_types=1);

// Database Connection Helper
function getDbConnection(): ?PDO {
    $host = getenv('DB_HOST') ?: 'database';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE') ?: 'app_dev';
    $user = getenv('DB_USERNAME') ?: 'dev';
    $pass = getenv('DB_PASSWORD') ?: 'secret';

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        return null;
    }
}

// Broadcast event to Mercure Hub
function publishMercureEvent(string $topic, array $data): void {
    $secret = getenv('MERCURE_PUBLISHER_JWT_KEY') ?: 'FrankenPHPMercurePublisherSecretKey2026Dev!';
    $hubUrl = getenv('MERCURE_INTERNAL_URL') ?: 'http://127.0.0.1:80/.well-known/mercure';

    // Build JWT
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $h = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = $b64(json_encode(['mercure' => ['publish' => ['*']], 'exp' => time() + 3600]));
    $s = $b64(hash_hmac('sha256', "{$h}.{$p}", $secret, true));
    $jwt = "{$h}.{$p}.{$s}";

    $postData = http_build_query([
        'topic' => $topic,
        'data'  => json_encode($data, JSON_UNESCAPED_UNICODE),
    ], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($hubUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $jwt,
        'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    @curl_exec($ch);
}

$pdo = getDbConnection();
$message = null;
$messageType = 'success';

// Handle Form Submissions
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? 'developer';

        if ($name && $email) {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, role, status) VALUES (?, ?, ?, 'active')");
                $stmt->execute([$name, $email, $role]);
                $userId = $pdo->lastInsertId();
                $message = "User '{$name}' successfully added (ID: #{$userId})!";
                
                // Real-time broadcast
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'user.created',
                    'text'  => "New user {$name} ({$role}) registered!",
                    'id'    => $userId,
                ]);
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'add_product') {
        $name     = trim($_POST['name'] ?? '');
        $sku      = trim($_POST['sku'] ?? '');
        $price    = (float)($_POST['price'] ?? 0);
        $stock    = (int)($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');

        if ($name && $sku && $price > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO products (name, sku, price, stock, category) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $sku, $price, $stock, $category]);
                $message = "Product '{$name}' created successfully!";

                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'product.created',
                    'text'  => "New product added: {$name} ($" . number_format($price, 2) . ")",
                ]);
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Query records
$users = [];
$products = [];
$orders = [];
$dbVersion = 'Unknown';

if ($pdo) {
    try {
        $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
        $users = $pdo->query("SELECT * FROM users ORDER BY id DESC LIMIT 10")->fetchAll();
        $products = $pdo->query("SELECT * FROM products ORDER BY id DESC LIMIT 10")->fetchAll();
        $orders = $pdo->query("
            SELECT o.*, u.name as user_name, u.email as user_email 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            ORDER BY o.id DESC LIMIT 5
        ")->fetchAll();
    } catch (PDOException $e) {
        $message = "Query failed: " . $e->getMessage();
        $messageType = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database CRUD & Real-Time Demo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d16;
            --surface: #0f172a;
            --surface-card: #1e293b;
            --border: #334155;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #38bdf8;
            --primary-glow: rgba(56, 189, 248, 0.2);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 2rem 1.5rem;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .brand { display: flex; align-items: center; gap: 0.85rem; }
        .logo-icon {
            font-size: 2rem;
            background: linear-gradient(135deg, #0284c7, #6366f1);
            padding: 0.4rem 0.7rem;
            border-radius: 12px;
        }
        .brand h1 {
            font-size: 1.6rem;
            font-weight: 800;
            background: linear-gradient(to right, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .brand p { color: var(--text-muted); font-size: 0.85rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid var(--border);
            background-color: var(--surface-card);
            color: var(--text);
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); }
        .btn-primary { background: linear-gradient(135deg, #0284c7, #4f46e5); border: none; color: #fff; }
        .btn-primary:hover { opacity: 0.9; color: #fff; }

        .alert {
            padding: 0.85rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; }
        .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; }

        .diag-banner {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .diag-metrics { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .metric-item { display: flex; flex-direction: column; }
        .metric-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .metric-val { font-size: 0.95rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; color: var(--primary); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

        .card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.5rem;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .card-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left; }
        th { padding: 0.6rem 0.8rem; background: var(--surface-card); color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); }
        td { padding: 0.6rem 0.8rem; border-bottom: 1px solid var(--border); }
        tr:hover td { background-color: rgba(255, 255, 255, 0.02); }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-role { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
        .badge-price { font-family: 'JetBrains Mono', monospace; color: #34d399; font-weight: 700; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 1rem; }
        .input, select {
            width: 100%;
            padding: 0.55rem 0.8rem;
            background-color: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-size: 0.85rem;
            outline: none;
        }
        .input:focus, select:focus { border-color: var(--primary); }

        .toast-feed {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            z-index: 100;
        }
        .toast {
            background: #1e293b;
            border: 1px solid var(--primary);
            color: #fff;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            animation: slideIn 0.3s ease;
            font-size: 0.85rem;
        }
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="brand">
                <div class="logo-icon">🗄️</div>
                <div>
                    <h1>Database CRUD & Mercure Demo</h1>
                    <p>Testing MariaDB/MySQL connection, queries, and real-time push synchronization</p>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <a href="http://localhost:8080/?server=database&username=dev&db=app_dev" target="_blank" class="btn btn-primary">
                    ⚡ Open in Adminer GUI
                </a>
                <a href="/" class="btn">&larr; Main Dashboard</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Diagnostics Banner -->
        <div class="diag-banner">
            <div class="diag-metrics">
                <div class="metric-item">
                    <span class="metric-label">Status</span>
                    <span class="metric-val" style="color: <?= $pdo ? '#34d399' : '#f87171' ?>;">
                        <?= $pdo ? '● Connected' : '● Disconnected' ?>
                    </span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Database Host</span>
                    <span class="metric-val"><?= getenv('DB_HOST') ?: 'database' ?>:<?= getenv('DB_PORT') ?: '3306' ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Active DB</span>
                    <span class="metric-val"><?= getenv('DB_DATABASE') ?: 'app_dev' ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Engine Version</span>
                    <span class="metric-val"><?= htmlspecialchars($dbVersion) ?></span>
                </div>
            </div>
            <div>
                <a href="./" class="btn" style="font-size: 0.8rem;">🔄 Refresh Data</a>
            </div>
        </div>

        <div class="grid-2">
            <!-- Users Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">👥 <span>Users Table (<code>users</code>)</span></div>
                    <span class="badge" style="background: var(--surface-card);"><?= count($users) ?> Loaded</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>#<?= $u['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td><span class="badge badge-role"><?= htmlspecialchars($u['role']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Add User Form -->
                <form method="POST" style="margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1rem;">
                    <input type="hidden" name="action" value="add_user">
                    <div class="form-grid">
                        <input class="input" type="text" name="name" placeholder="Full Name" required>
                        <input class="input" type="email" name="email" placeholder="Email Address" required>
                        <select name="role">
                            <option value="developer">Developer</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                            <option value="user">User</option>
                        </select>
                        <button type="submit" class="btn btn-primary" style="justify-content: center;">+ Add User</button>
                    </div>
                </form>
            </div>

            <!-- Products Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">📦 <span>Products Catalog (<code>products</code>)</span></div>
                    <span class="badge" style="background: var(--surface-card);"><?= count($products) ?> Loaded</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $pr): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($pr['sku']) ?></code></td>
                                    <td><?= htmlspecialchars($pr['name']) ?></td>
                                    <td><?= htmlspecialchars($pr['category']) ?></td>
                                    <td><span class="badge-price">$<?= number_format((float)$pr['price'], 2) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Add Product Form -->
                <form method="POST" style="margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1rem;">
                    <input type="hidden" name="action" value="add_product">
                    <div class="form-grid">
                        <input class="input" type="text" name="name" placeholder="Product Name" required>
                        <input class="input" type="text" name="sku" placeholder="SKU Code" required>
                        <input class="input" type="number" step="0.01" name="price" placeholder="Price ($)" required>
                        <input class="input" type="text" name="category" placeholder="Category" value="Hardware">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.75rem; justify-content: center;">+ Add Product</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Live Toast Stream -->
    <div class="toast-feed" id="toast-feed"></div>

    <script>
        // Listen to Mercure for live database event toasts
        const eventSource = new EventSource('/.well-known/mercure?topic=' + encodeURIComponent('https://example.com/notifications'));
        eventSource.onmessage = (e) => {
            try {
                const data = JSON.parse(e.data);
                if (data.event) {
                    const toast = document.createElement('div');
                    toast.className = 'toast';
                    toast.innerHTML = `⚡ <strong>Real-Time Update:</strong> ${data.text}`;
                    document.getElementById('toast-feed').appendChild(toast);
                    setTimeout(() => toast.remove(), 5000);
                }
            } catch (err) {}
        };
    </script>
</body>
</html>
