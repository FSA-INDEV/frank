<?php
declare(strict_types=1);

// Database Connection
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
$activeTab = $_GET['tab'] ?? 'users';

// Handle CRUD Actions
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // --- USERS CRUD ---
    if ($action === 'create_user') {
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $role   = $_POST['role'] ?? 'developer';
        $status = $_POST['status'] ?? 'active';

        if ($name && $email) {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, role, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $email, $role, $status]);
                $id = $pdo->lastInsertId();
                $message = "User '{$name}' created successfully (ID: #{$id})!";
                $activeTab = 'users';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'user.created',
                    'text'  => "User added: {$name} ({$role})",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'update_user') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $role   = $_POST['role'] ?? 'developer';
        $status = $_POST['status'] ?? 'active';

        if ($id && $name && $email) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?");
                $stmt->execute([$name, $email, $role, $status, $id]);
                $message = "User #{$id} ({$name}) updated successfully!";
                $activeTab = 'users';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'user.updated',
                    'text'  => "User #{$id} ({$name}) was updated.",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Update failed: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $message = "User #{$id} deleted.";
                $activeTab = 'users';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'user.deleted',
                    'text'  => "User #{$id} was deleted from database.",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Delete failed: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // --- PRODUCTS CRUD ---
    elseif ($action === 'create_product') {
        $name     = trim($_POST['name'] ?? '');
        $sku      = trim($_POST['sku'] ?? '');
        $price    = (float)($_POST['price'] ?? 0);
        $stock    = (int)($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');

        if ($name && $sku && $price > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO products (name, sku, price, stock, category) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $sku, $price, $stock, $category]);
                $id = $pdo->lastInsertId();
                $message = "Product '{$name}' created (SKU: {$sku})!";
                $activeTab = 'products';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'product.created',
                    'text'  => "New product: {$name} ($" . number_format($price, 2) . ")",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'update_product') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $sku      = trim($_POST['sku'] ?? '');
        $price    = (float)($_POST['price'] ?? 0);
        $stock    = (int)($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');

        if ($id && $name && $sku) {
            try {
                $stmt = $pdo->prepare("UPDATE products SET name = ?, sku = ?, price = ?, stock = ?, category = ? WHERE id = ?");
                $stmt->execute([$name, $sku, $price, $stock, $category, $id]);
                $message = "Product #{$id} updated successfully!";
                $activeTab = 'products';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'product.updated',
                    'text'  => "Product #{$id} ({$name}) details updated.",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Update failed: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'delete_product') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Product #{$id} deleted.";
                $activeTab = 'products';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'product.deleted',
                    'text'  => "Product #{$id} removed from catalog.",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Delete failed: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // --- ORDERS CRUD ---
    elseif ($action === 'create_order') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $amount = (float)($_POST['total_amount'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        if ($userId && $amount > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_amount, status) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $amount, $status]);
                $id = $pdo->lastInsertId();
                $message = "Order #{$id} created successfully ($" . number_format($amount, 2) . ")!";
                $activeTab = 'orders';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'order.created',
                    'text'  => "New order #{$id} placed for $" . number_format($amount, 2),
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'update_order') {
        $id     = (int)($_POST['id'] ?? 0);
        $amount = (float)($_POST['total_amount'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        if ($id && $amount > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE orders SET total_amount = ?, status = ? WHERE id = ?");
                $stmt->execute([$amount, $status, $id]);
                $message = "Order #{$id} status updated to '{$status}'!";
                $activeTab = 'orders';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'order.updated',
                    'text'  => "Order #{$id} updated: {$status} ($" . number_format($amount, 2) . ")",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Update failed: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'delete_order') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Order #{$id} deleted.";
                $activeTab = 'orders';
                publishMercureEvent('https://example.com/notifications', [
                    'event' => 'order.deleted',
                    'text'  => "Order #{$id} was cancelled & deleted.",
                    'id'    => $id,
                ]);
            } catch (PDOException $e) {
                $message = "Delete failed: " . $e->getMessage();
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
        $users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
        $products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
        $orders = $pdo->query("
            SELECT o.*, u.name as user_name, u.email as user_email 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            ORDER BY o.id DESC
        ")->fetchAll();
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Database CRUD & Real-Time Sync</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d16;
            --surface: #0f172a;
            --surface-card: #1e293b;
            --surface-hover: #334155;
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
        .container { max-width: 1280px; margin: 0 auto; }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
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
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
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
        .btn-danger { background: rgba(239, 68, 68, 0.2); border-color: rgba(239, 68, 68, 0.4); color: #f87171; }
        .btn-danger:hover { background: #ef4444; color: #fff; }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 6px; }

        .alert {
            padding: 0.85rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; }
        .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.5rem;
        }
        .tab-btn {
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tab-btn:hover { color: var(--text); background: var(--surface-card); }
        .tab-btn.active { color: #fff; background: linear-gradient(135deg, #0284c7, #4f46e5); }

        .card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .card-title { font-size: 1.15rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; text-align: left; }
        th { padding: 0.75rem 0.9rem; background: var(--surface-card); color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); }
        td { padding: 0.75rem 0.9rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:hover td { background-color: rgba(255, 255, 255, 0.02); }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-admin { background: rgba(236, 72, 153, 0.2); color: #f472b6; }
        .badge-dev { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
        .badge-manager { background: rgba(129, 140, 248, 0.2); color: #818cf8; }
        .badge-user { background: rgba(156, 163, 175, 0.2); color: #d1d5db; }
        .badge-paid { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .badge-shipped { background: rgba(99, 102, 241, 0.2); color: #a5b4fc; }
        .badge-cancelled { background: rgba(239, 68, 68, 0.2); color: #f87171; }

        .input, select {
            width: 100%;
            padding: 0.6rem 0.85rem;
            background-color: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-size: 0.85rem;
            outline: none;
        }
        .input:focus, select:focus { border-color: var(--primary); }

        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; align-items: end; }

        /* Modal Overlay */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal.open { display: flex; }
        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.6);
        }

        .toast-feed {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            z-index: 1000;
        }
        .toast {
            background: #1e293b;
            border: 1px solid var(--primary);
            color: #fff;
            padding: 0.85rem 1.25rem;
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
                    <h1>Full Database CRUD & Real-Time Sync</h1>
                    <p>MariaDB 11.4 Engine &bull; Host: <code>database:3306</code> &bull; DB: <code>app_dev</code></p>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <a href="http://localhost:8080/?server=database&username=dev&db=app_dev" target="_blank" class="btn btn-primary">
                    ⚡ Open Adminer GUI
                </a>
                <a href="/" class="btn">&larr; Main Dashboard</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Table Navigation Tabs -->
        <div class="tabs">
            <button class="tab-btn <?= $activeTab === 'users' ? 'active' : '' ?>" onclick="switchTab('users')">
                👥 Users (<?= count($users) ?>)
            </button>
            <button class="tab-btn <?= $activeTab === 'products' ? 'active' : '' ?>" onclick="switchTab('products')">
                📦 Products (<?= count($products) ?>)
            </button>
            <button class="tab-btn <?= $activeTab === 'orders' ? 'active' : '' ?>" onclick="switchTab('orders')">
                🛒 Orders (<?= count($orders) ?>)
            </button>
        </div>

        <!-- 1. USERS TAB -->
        <div id="tab-users" style="display: <?= $activeTab === 'users' ? 'block' : 'none' ?>;">
            <!-- Create User Form -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">➕ <span>Create New User</span></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_user">
                    <div class="form-row">
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Full Name</label>
                            <input class="input" type="text" name="name" placeholder="e.g. Linus Torvalds" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Email Address</label>
                            <input class="input" type="email" name="email" placeholder="linus@example.com" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Role</label>
                            <select name="role">
                                <option value="developer">Developer</option>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="user">User</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Status</label>
                            <select name="status">
                                <option value="active">Active</option>
                                <option value="pending">Pending</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 38px; justify-content: center;">
                            + Save User
                        </button>
                    </div>
                </form>
            </div>

            <!-- Users List Table -->
            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>#<?= $u['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td><span class="badge badge-<?= $u['role'] ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                                    <td><span class="badge badge-<?= $u['status'] ?>"><?= htmlspecialchars($u['status']) ?></span></td>
                                    <td style="color: var(--text-muted); font-size: 0.8rem;"><?= htmlspecialchars($u['created_at']) ?></td>
                                    <td style="text-align: right;">
                                        <button class="btn btn-sm" onclick='openEditUser(<?= json_encode($u) ?>)'>✏️ Edit</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete user #<?= $u['id'] ?>?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2. PRODUCTS TAB -->
        <div id="tab-products" style="display: <?= $activeTab === 'products' ? 'block' : 'none' ?>;">
            <!-- Create Product Form -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">➕ <span>Add New Product</span></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_product">
                    <div class="form-row">
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Product Name</label>
                            <input class="input" type="text" name="name" placeholder="e.g. Ergonomic Chair" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">SKU Code</label>
                            <input class="input" type="text" name="sku" placeholder="CHR-ERG-01" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Price ($)</label>
                            <input class="input" type="number" step="0.01" name="price" placeholder="199.99" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Stock Qty</label>
                            <input class="input" type="number" name="stock" value="50" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Category</label>
                            <input class="input" type="text" name="category" value="Hardware">
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 38px; justify-content: center;">
                            + Save Product
                        </button>
                    </div>
                </form>
            </div>

            <!-- Products List Table -->
            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $pr): ?>
                                <tr>
                                    <td>#<?= $pr['id'] ?></td>
                                    <td><code><?= htmlspecialchars($pr['sku']) ?></code></td>
                                    <td><strong><?= htmlspecialchars($pr['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($pr['category']) ?></td>
                                    <td><span style="color: #34d399; font-weight: 700; font-family: 'JetBrains Mono', monospace;">$<?= number_format((float)$pr['price'], 2) ?></span></td>
                                    <td><?= $pr['stock'] ?> in stock</td>
                                    <td style="text-align: right;">
                                        <button class="btn btn-sm" onclick='openEditProduct(<?= json_encode($pr) ?>)'>✏️ Edit</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete product #<?= $pr['id'] ?>?');">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="id" value="<?= $pr['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. ORDERS TAB -->
        <div id="tab-orders" style="display: <?= $activeTab === 'orders' ? 'block' : 'none' ?>;">
            <!-- Create Order Form -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">➕ <span>Create New Order</span></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_order">
                    <div class="form-row">
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Customer (User)</label>
                            <select name="user_id" required>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>">#<?= $u['id'] ?> - <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Total Amount ($)</label>
                            <input class="input" type="number" step="0.01" name="total_amount" placeholder="99.95" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Status</label>
                            <select name="status">
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="shipped">Shipped</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 38px; justify-content: center;">
                            + Create Order
                        </button>
                    </div>
                </form>
            </div>

            <!-- Orders List Table -->
            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
                                <tr>
                                    <td><strong>#<?= $o['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($o['user_name'] ?? 'User #' . $o['user_id']) ?> <span style="color: var(--text-muted); font-size: 0.75rem;">(<?= htmlspecialchars($o['user_email'] ?? '') ?>)</span></td>
                                    <td><span style="color: #34d399; font-weight: 700; font-family: 'JetBrains Mono', monospace;">$<?= number_format((float)$o['total_amount'], 2) ?></span></td>
                                    <td><span class="badge badge-<?= $o['status'] ?>"><?= htmlspecialchars($o['status']) ?></span></td>
                                    <td style="color: var(--text-muted); font-size: 0.8rem;"><?= htmlspecialchars($o['created_at']) ?></td>
                                    <td style="text-align: right;">
                                        <button class="btn btn-sm" onclick='openEditOrder(<?= json_encode($o) ?>)'>✏️ Edit</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Cancel & delete order #<?= $o['id'] ?>?');">
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="modal-user" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 1rem; font-size: 1.25rem;">✏️ Edit User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" id="edit-user-id" name="id">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Name</label>
                        <input id="edit-user-name" class="input" type="text" name="name" required>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Email</label>
                        <input id="edit-user-email" class="input" type="email" name="email" required>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Role</label>
                        <select id="edit-user-role" name="role">
                            <option value="developer">Developer</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="user">User</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Status</label>
                        <select id="edit-user-status" name="status">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
                        <button type="button" class="btn" onclick="closeModal('modal-user')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div id="modal-product" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 1rem; font-size: 1.25rem;">✏️ Edit Product</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" id="edit-product-id" name="id">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Product Name</label>
                        <input id="edit-product-name" class="input" type="text" name="name" required>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">SKU Code</label>
                        <input id="edit-product-sku" class="input" type="text" name="sku" required>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Price ($)</label>
                        <input id="edit-product-price" class="input" type="number" step="0.01" name="price" required>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Stock Qty</label>
                        <input id="edit-product-stock" class="input" type="number" name="stock" required>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Category</label>
                        <input id="edit-product-category" class="input" type="text" name="category" required>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
                        <button type="button" class="btn" onclick="closeModal('modal-product')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Product</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div id="modal-order" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 1rem; font-size: 1.25rem;">✏️ Edit Order</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" id="edit-order-id" name="id">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Total Amount ($)</label>
                        <input id="edit-order-amount" class="input" type="number" step="0.01" name="total_amount" required>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; color: var(--text-muted);">Status</label>
                        <select id="edit-order-status" name="status">
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="shipped">Shipped</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
                        <button type="button" class="btn" onclick="closeModal('modal-order')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Order</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Live Toast Stream -->
    <div class="toast-feed" id="toast-feed"></div>

    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tabs .tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display = 'none');
            document.getElementById('tab-' + tabName).style.display = 'block';
            event.target.classList.add('active');
            window.history.replaceState(null, null, '?tab=' + tabName);
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }

        function openEditUser(user) {
            document.getElementById('edit-user-id').value = user.id;
            document.getElementById('edit-user-name').value = user.name;
            document.getElementById('edit-user-email').value = user.email;
            document.getElementById('edit-user-role').value = user.role;
            document.getElementById('edit-user-status').value = user.status;
            document.getElementById('modal-user').classList.add('open');
        }

        function openEditProduct(product) {
            document.getElementById('edit-product-id').value = product.id;
            document.getElementById('edit-product-name').value = product.name;
            document.getElementById('edit-product-sku').value = product.sku;
            document.getElementById('edit-product-price').value = product.price;
            document.getElementById('edit-product-stock').value = product.stock;
            document.getElementById('edit-product-category').value = product.category;
            document.getElementById('modal-product').classList.add('open');
        }

        function openEditOrder(order) {
            document.getElementById('edit-order-id').value = order.id;
            document.getElementById('edit-order-amount').value = order.total_amount;
            document.getElementById('edit-order-status').value = order.status;
            document.getElementById('modal-order').classList.add('open');
        }

        // Mercure Live SSE Listener
        const eventSource = new EventSource('/.well-known/mercure?topic=' + encodeURIComponent('https://example.com/notifications'));
        eventSource.onmessage = (e) => {
            try {
                const data = JSON.parse(e.data);
                if (data.event) {
                    const toast = document.createElement('div');
                    toast.className = 'toast';
                    toast.innerHTML = `⚡ <strong>Real-Time Sync:</strong> ${data.text}`;
                    document.getElementById('toast-feed').appendChild(toast);
                    setTimeout(() => toast.remove(), 5000);
                }
            } catch (err) {}
        };
    </script>
</body>
</html>
