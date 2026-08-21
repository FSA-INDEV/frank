<?php
declare(strict_types=1);

// Database Connection
function getDb(): ?PDO {
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
    } catch (\Throwable $e) {
        return null;
    }
}

// Broadcast event to Mercure Hub
function publishMercure(string $topic, array $data): void {
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

$pdo = getDb();

// --- REST API ENDPOINTS FOR ZERO-RELOAD REACTIVITY ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$pdo) {
        echo json_encode(['error' => 'Database connection unavailable'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $endpoint = $_GET['api'];

    // 1. Fetch all data
    if ($endpoint === 'all') {
        $users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
        $products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
        $orders = $pdo->query("
            SELECT o.*, u.name as user_name, u.email as user_email 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            ORDER BY o.id DESC
        ")->fetchAll();
        $version = $pdo->query("SELECT VERSION()")->fetchColumn();

        echo json_encode([
            'users'     => $users,
            'products'  => $products,
            'orders'    => $orders,
            'version'   => $version,
            'connected' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. User mutations
    if ($endpoint === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $role   = $_POST['role'] ?? 'developer';
        $status = $_POST['status'] ?? 'active';

        $stmt = $pdo->prepare("INSERT INTO users (name, email, role, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $role, $status]);
        $id = (int)$pdo->lastInsertId();

        $user = $pdo->query("SELECT * FROM users WHERE id = {$id}")->fetch();
        publishMercure('https://example.com/notifications', [
            'type'    => 'user.created',
            'payload' => $user,
            'text'    => "User '{$name}' created!",
        ]);

        echo json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($endpoint === 'update_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $role   = $_POST['role'] ?? 'developer';
        $status = $_POST['status'] ?? 'active';

        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?");
        $stmt->execute([$name, $email, $role, $status, $id]);

        $user = $pdo->query("SELECT * FROM users WHERE id = {$id}")->fetch();
        publishMercure('https://example.com/notifications', [
            'type'    => 'user.updated',
            'payload' => $user,
            'text'    => "User #{$id} ({$name}) updated!",
        ]);

        echo json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($endpoint === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        publishMercure('https://example.com/notifications', [
            'type'    => 'user.deleted',
            'payload' => ['id' => $id],
            'text'    => "User #{$id} deleted.",
        ]);

        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Product mutations
    if ($endpoint === 'create_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name     = trim($_POST['name'] ?? '');
        $sku      = trim($_POST['sku'] ?? '');
        $price    = (float)($_POST['price'] ?? 0);
        $stock    = (int)($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');

        $stmt = $pdo->prepare("INSERT INTO products (name, sku, price, stock, category) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $sku, $price, $stock, $category]);
        $id = (int)$pdo->lastInsertId();

        $product = $pdo->query("SELECT * FROM products WHERE id = {$id}")->fetch();
        publishMercure('https://example.com/notifications', [
            'type'    => 'product.created',
            'payload' => $product,
            'text'    => "Product '{$name}' created!",
        ]);

        echo json_encode(['success' => true, 'product' => $product], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($endpoint === 'update_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $sku      = trim($_POST['sku'] ?? '');
        $price    = (float)($_POST['price'] ?? 0);
        $stock    = (int)($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');

        $stmt = $pdo->prepare("UPDATE products SET name = ?, sku = ?, price = ?, stock = ?, category = ? WHERE id = ?");
        $stmt->execute([$name, $sku, $price, $stock, $category, $id]);

        $product = $pdo->query("SELECT * FROM products WHERE id = {$id}")->fetch();
        publishMercure('https://example.com/notifications', [
            'type'    => 'product.updated',
            'payload' => $product,
            'text'    => "Product '{$name}' updated!",
        ]);

        echo json_encode(['success' => true, 'product' => $product], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($endpoint === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);

        publishMercure('https://example.com/notifications', [
            'type'    => 'product.deleted',
            'payload' => ['id' => $id],
            'text'    => "Product #{$id} deleted.",
        ]);

        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Order mutations
    if ($endpoint === 'create_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $amount = (float)($_POST['total_amount'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_amount, status) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $amount, $status]);
        $id = (int)$pdo->lastInsertId();

        $order = $pdo->query("
            SELECT o.*, u.name as user_name, u.email as user_email 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            WHERE o.id = {$id}
        ")->fetch();

        publishMercure('https://example.com/notifications', [
            'type'    => 'order.created',
            'payload' => $order,
            'text'    => "Order #{$id} placed ($" . number_format($amount, 2) . ")!",
        ]);

        echo json_encode(['success' => true, 'order' => $order], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($endpoint === 'update_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id     = (int)($_POST['id'] ?? 0);
        $amount = (float)($_POST['total_amount'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        $stmt = $pdo->prepare("UPDATE orders SET total_amount = ?, status = ? WHERE id = ?");
        $stmt->execute([$amount, $status, $id]);

        $order = $pdo->query("
            SELECT o.*, u.name as user_name, u.email as user_email 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            WHERE o.id = {$id}
        ")->fetch();

        publishMercure('https://example.com/notifications', [
            'type'    => 'order.updated',
            'payload' => $order,
            'text'    => "Order #{$id} status: {$status}",
        ]);

        echo json_encode(['success' => true, 'order' => $order], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($endpoint === 'delete_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$id]);

        publishMercure('https://example.com/notifications', [
            'type'    => 'order.deleted',
            'payload' => ['id' => $id],
            'text'    => "Order #{$id} cancelled.",
        ]);

        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reactive Database & Real-Time Sync</title>
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
            background: linear-gradient(to right, #38bdf8, #818cf8, #f472b6);
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

        /* Realtime status pulse */
        .pulse-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .pulse-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #34d399;
            box-shadow: 0 0 10px #34d399;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.85); } 100% { opacity: 1; transform: scale(1); } }

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
        td { padding: 0.75rem 0.9rem; border-bottom: 1px solid var(--border); vertical-align: middle; transition: background 0.5s ease; }
        tr.highlight td { background-color: rgba(56, 189, 248, 0.15) !important; }
        tr.new-row td { background-color: rgba(16, 185, 129, 0.15) !important; }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-admin { background: rgba(236, 72, 153, 0.2); color: #f472b6; }
        .badge-developer { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
        .badge-manager { background: rgba(129, 140, 248, 0.2); color: #818cf8; }
        .badge-user { background: rgba(156, 163, 175, 0.2); color: #d1d5db; }
        .badge-paid { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .badge-shipped { background: rgba(99, 102, 241, 0.2); color: #a5b4fc; }
        .badge-cancelled { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .badge-active { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-inactive { background: rgba(239, 68, 68, 0.2); color: #f87171; }

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
                <div class="logo-icon">⚡</div>
                <div>
                    <h1>Reactive Real-Time Database Demo</h1>
                    <p>Zero-Reload Live Synchronization via Mercure SSE &bull; MariaDB <code>app_dev</code></p>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div class="pulse-badge">
                    <span class="pulse-dot"></span> <span id="stream-status">Live Reactive Stream</span>
                </div>
                <a href="http://localhost:8080/?server=database&username=dev&db=app_dev" target="_blank" class="btn btn-primary">
                    🗄️ Adminer GUI
                </a>
                <a href="/" class="btn">&larr; Main Hub</a>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <div class="tabs">
            <button class="tab-btn active" id="tab-btn-users" onclick="switchTab('users')">
                👥 Users (<span id="count-users">0</span>)
            </button>
            <button class="tab-btn" id="tab-btn-products" onclick="switchTab('products')">
                📦 Products (<span id="count-products">0</span>)
            </button>
            <button class="tab-btn" id="tab-btn-orders" onclick="switchTab('orders')">
                🛒 Orders (<span id="count-orders">0</span>)
            </button>
        </div>

        <!-- 1. USERS TAB -->
        <div id="tab-users">
            <!-- Create User Form (AJAX Zero-Reload) -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">➕ <span>Create New User (Broadcasts Live in Real-Time)</span></div>
                </div>
                <form id="form-create-user" onsubmit="handleCreateUser(event)">
                    <div class="form-row">
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Full Name</label>
                            <input class="input" type="text" name="name" placeholder="e.g. Alan Turing" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Email Address</label>
                            <input class="input" type="email" name="email" placeholder="turing@example.com" required>
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
                            ⚡ Create User
                        </button>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
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
                        <tbody id="tbody-users">
                            <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">Loading live users...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2. PRODUCTS TAB -->
        <div id="tab-products" style="display: none;">
            <!-- Create Product Form (AJAX Zero-Reload) -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">➕ <span>Add New Product (Broadcasts Live in Real-Time)</span></div>
                </div>
                <form id="form-create-product" onsubmit="handleCreateProduct(event)">
                    <div class="form-row">
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Product Name</label>
                            <input class="input" type="text" name="name" placeholder="e.g. Ergonomic Mouse" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">SKU Code</label>
                            <input class="input" type="text" name="sku" placeholder="MS-ERG-01" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Price ($)</label>
                            <input class="input" type="number" step="0.01" name="price" placeholder="79.99" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Stock Qty</label>
                            <input class="input" type="number" name="stock" value="100" required>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Category</label>
                            <input class="input" type="text" name="category" value="Hardware">
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 38px; justify-content: center;">
                            ⚡ Add Product
                        </button>
                    </div>
                </form>
            </div>

            <!-- Products Table -->
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
                        <tbody id="tbody-products">
                            <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">Loading live products...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. ORDERS TAB -->
        <div id="tab-orders" style="display: none;">
            <!-- Create Order Form (AJAX Zero-Reload) -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">➕ <span>Create New Order (Broadcasts Live in Real-Time)</span></div>
                </div>
                <form id="form-create-order" onsubmit="handleCreateOrder(event)">
                    <div class="form-row">
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Customer (User)</label>
                            <select id="select-order-user" name="user_id" required>
                                <option value="">Select customer...</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; color: var(--text-muted);">Total Amount ($)</label>
                            <input class="input" type="number" step="0.01" name="total_amount" placeholder="149.99" required>
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
                            ⚡ Place Order
                        </button>
                    </div>
                </form>
            </div>

            <!-- Orders Table -->
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
                        <tbody id="tbody-orders">
                            <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">Loading live orders...</td></tr>
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
            <form onsubmit="handleUpdateUser(event)">
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
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div id="modal-product" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 1rem; font-size: 1.25rem;">✏️ Edit Product</h2>
            <form onsubmit="handleUpdateProduct(event)">
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
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div id="modal-order" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 1rem; font-size: 1.25rem;">✏️ Edit Order</h2>
            <form onsubmit="handleUpdateOrder(event)">
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
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Live Toast Stream -->
    <div class="toast-feed" id="toast-feed"></div>

    <script>
        // Reactive State
        const state = {
            users: [],
            products: [],
            orders: []
        };

        function showToast(msg) {
            const feed = document.getElementById('toast-feed');
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `⚡ ${msg}`;
            feed.appendChild(toast);
            setTimeout(() => toast.remove(), 4500);
        }

        function switchTab(tab) {
            document.querySelectorAll('.tabs .tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-btn-' + tab).classList.add('active');
            ['users', 'products', 'orders'].forEach(t => {
                document.getElementById('tab-' + t).style.display = (t === tab) ? 'block' : 'none';
            });
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }

        // Render Functions
        function renderUsers(highlightId = null) {
            const tbody = document.getElementById('tbody-users');
            document.getElementById('count-users').innerText = state.users.length;

            // Also update order customer dropdown
            const selectUser = document.getElementById('select-order-user');
            selectUser.innerHTML = '<option value="">Select customer...</option>' + 
                state.users.map(u => `<option value="${u.id}">#${u.id} - ${escapeHtml(u.name)} (${escapeHtml(u.email)})</option>`).join('');

            if (state.users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No users found.</td></tr>';
                return;
            }

            tbody.innerHTML = state.users.map(u => `
                <tr id="row-user-${u.id}" class="${u.id == highlightId ? 'highlight' : ''}">
                    <td>#${u.id}</td>
                    <td><strong>${escapeHtml(u.name)}</strong></td>
                    <td>${escapeHtml(u.email)}</td>
                    <td><span class="badge badge-${escapeHtml(u.role)}">${escapeHtml(u.role)}</span></td>
                    <td><span class="badge badge-${escapeHtml(u.status)}">${escapeHtml(u.status)}</span></td>
                    <td style="color: var(--text-muted); font-size: 0.8rem;">${u.created_at || 'Just now'}</td>
                    <td style="text-align: right;">
                        <button class="btn btn-sm" onclick='openEditUser(${JSON.stringify(u)})'>✏️ Edit</button>
                        <button class="btn btn-sm btn-danger" onclick="handleDeleteUser(${u.id})">🗑️</button>
                    </td>
                </tr>
            `).join('');
        }

        function renderProducts(highlightId = null) {
            const tbody = document.getElementById('tbody-products');
            document.getElementById('count-products').innerText = state.products.length;

            if (state.products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No products found.</td></tr>';
                return;
            }

            tbody.innerHTML = state.products.map(pr => `
                <tr id="row-product-${pr.id}" class="${pr.id == highlightId ? 'highlight' : ''}">
                    <td>#${pr.id}</td>
                    <td><code>${escapeHtml(pr.sku)}</code></td>
                    <td><strong>${escapeHtml(pr.name)}</strong></td>
                    <td>${escapeHtml(pr.category)}</td>
                    <td><span style="color: #34d399; font-weight: 700; font-family: 'JetBrains Mono', monospace;">$${parseFloat(pr.price).toFixed(2)}</span></td>
                    <td>${pr.stock} in stock</td>
                    <td style="text-align: right;">
                        <button class="btn btn-sm" onclick='openEditProduct(${JSON.stringify(pr)})'>✏️ Edit</button>
                        <button class="btn btn-sm btn-danger" onclick="handleDeleteProduct(${pr.id})">🗑️</button>
                    </td>
                </tr>
            `).join('');
        }

        function renderOrders(highlightId = null) {
            const tbody = document.getElementById('tbody-orders');
            document.getElementById('count-orders').innerText = state.orders.length;

            if (state.orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No orders found.</td></tr>';
                return;
            }

            tbody.innerHTML = state.orders.map(o => `
                <tr id="row-order-${o.id}" class="${o.id == highlightId ? 'highlight' : ''}">
                    <td><strong>#${o.id}</strong></td>
                    <td>${escapeHtml(o.user_name || 'User #' + o.user_id)} <span style="color: var(--text-muted); font-size: 0.75rem;">(${escapeHtml(o.user_email || '')})</span></td>
                    <td><span style="color: #34d399; font-weight: 700; font-family: 'JetBrains Mono', monospace;">$${parseFloat(o.total_amount).toFixed(2)}</span></td>
                    <td><span class="badge badge-${escapeHtml(o.status)}">${escapeHtml(o.status)}</span></td>
                    <td style="color: var(--text-muted); font-size: 0.8rem;">${o.created_at || 'Just now'}</td>
                    <td style="text-align: right;">
                        <button class="btn btn-sm" onclick='openEditOrder(${JSON.stringify(o)})'>✏️ Edit</button>
                        <button class="btn btn-sm btn-danger" onclick="handleDeleteOrder(${o.id})">🗑️</button>
                    </td>
                </tr>
            `).join('');
        }

        function escapeHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Modal Openers
        function openEditUser(u) {
            document.getElementById('edit-user-id').value = u.id;
            document.getElementById('edit-user-name').value = u.name;
            document.getElementById('edit-user-email').value = u.email;
            document.getElementById('edit-user-role').value = u.role;
            document.getElementById('edit-user-status').value = u.status;
            document.getElementById('modal-user').classList.add('open');
        }

        function openEditProduct(pr) {
            document.getElementById('edit-product-id').value = pr.id;
            document.getElementById('edit-product-name').value = pr.name;
            document.getElementById('edit-product-sku').value = pr.sku;
            document.getElementById('edit-product-price').value = pr.price;
            document.getElementById('edit-product-stock').value = pr.stock;
            document.getElementById('edit-product-category').value = pr.category;
            document.getElementById('modal-product').classList.add('open');
        }

        function openEditOrder(o) {
            document.getElementById('edit-order-id').value = o.id;
            document.getElementById('edit-order-amount').value = o.total_amount;
            document.getElementById('edit-order-status').value = o.status;
            document.getElementById('modal-order').classList.add('open');
        }

        // AJAX Action Handlers (Zero-Reload)
        async function handleCreateUser(e) {
            e.preventDefault();
            const form = e.target;
            const body = new FormData(form);
            const res = await fetch('?api=create_user', { method: 'POST', body });
            const data = await res.json();
            if (data.success) {
                form.reset();
            }
        }

        async function handleUpdateUser(e) {
            e.preventDefault();
            const form = e.target;
            const body = new FormData(form);
            const res = await fetch('?api=update_user', { method: 'POST', body });
            const data = await res.json();
            if (data.success) {
                closeModal('modal-user');
            }
        }

        async function handleDeleteUser(id) {
            if (!confirm('Are you sure you want to delete User #' + id + '?')) return;
            const body = new FormData();
            body.append('id', id);
            await fetch('?api=delete_user', { method: 'POST', body });
        }

        async function handleCreateProduct(e) {
            e.preventDefault();
            const form = e.target;
            const body = new FormData(form);
            const res = await fetch('?api=create_product', { method: 'POST', body });
            const data = await res.json();
            if (data.success) {
                form.reset();
            }
        }

        async function handleUpdateProduct(e) {
            e.preventDefault();
            const form = e.target;
            const body = new FormData(form);
            const res = await fetch('?api=update_product', { method: 'POST', body });
            const data = await res.json();
            if (data.success) {
                closeModal('modal-product');
            }
        }

        async function handleDeleteProduct(id) {
            if (!confirm('Delete product #' + id + '?')) return;
            const body = new FormData();
            body.append('id', id);
            await fetch('?api=delete_product', { method: 'POST', body });
        }

        async function handleCreateOrder(e) {
            e.preventDefault();
            const form = e.target;
            const body = new FormData(form);
            const res = await fetch('?api=create_order', { method: 'POST', body });
            const data = await res.json();
            if (data.success) {
                form.reset();
            }
        }

        async function handleUpdateOrder(e) {
            e.preventDefault();
            const form = e.target;
            const body = new FormData(form);
            const res = await fetch('?api=update_order', { method: 'POST', body });
            const data = await res.json();
            if (data.success) {
                closeModal('modal-order');
            }
        }

        async function handleDeleteOrder(id) {
            if (!confirm('Cancel & delete order #' + id + '?')) return;
            const body = new FormData();
            body.append('id', id);
            await fetch('?api=delete_order', { method: 'POST', body });
        }

        // Initial Data Fetch
        async function fetchInitialData() {
            try {
                const res = await fetch('?api=all');
                const data = await res.json();
                state.users = data.users || [];
                state.products = data.products || [];
                state.orders = data.orders || [];
                renderUsers();
                renderProducts();
                renderOrders();
            } catch (err) {
                console.error('Failed to load initial data:', err);
            }
        }

        // Mercure Live SSE Reactive Listener
        function initMercureLive() {
            const eventSource = new EventSource('/.well-known/mercure?topic=' + encodeURIComponent('https://example.com/notifications'));
            
            eventSource.onopen = () => {
                document.getElementById('stream-status').innerText = 'Live Reactive Stream Connected';
            };

            eventSource.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (!data.type) return;

                    showToast(data.text || 'Database updated in real-time');

                    // 1. User events
                    if (data.type === 'user.created') {
                        state.users.unshift(data.payload);
                        renderUsers(data.payload.id);
                    } else if (data.type === 'user.updated') {
                        const idx = state.users.findIndex(u => u.id == data.payload.id);
                        if (idx !== -1) state.users[idx] = data.payload;
                        renderUsers(data.payload.id);
                    } else if (data.type === 'user.deleted') {
                        state.users = state.users.filter(u => u.id != data.payload.id);
                        renderUsers();
                    }

                    // 2. Product events
                    else if (data.type === 'product.created') {
                        state.products.unshift(data.payload);
                        renderProducts(data.payload.id);
                    } else if (data.type === 'product.updated') {
                        const idx = state.products.findIndex(pr => pr.id == data.payload.id);
                        if (idx !== -1) state.products[idx] = data.payload;
                        renderProducts(data.payload.id);
                    } else if (data.type === 'product.deleted') {
                        state.products = state.products.filter(pr => pr.id != data.payload.id);
                        renderProducts();
                    }

                    // 3. Order events
                    else if (data.type === 'order.created') {
                        state.orders.unshift(data.payload);
                        renderOrders(data.payload.id);
                    } else if (data.type === 'order.updated') {
                        const idx = state.orders.findIndex(o => o.id == data.payload.id);
                        if (idx !== -1) state.orders[idx] = data.payload;
                        renderOrders(data.payload.id);
                    } else if (data.type === 'order.deleted') {
                        state.orders = state.orders.filter(o => o.id != data.payload.id);
                        renderOrders();
                    }
                } catch (err) {
                    console.error('Error handling SSE event:', err);
                }
            };

            eventSource.onerror = () => {
                document.getElementById('stream-status').innerText = 'Stream Reconnecting...';
            };
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchInitialData();
            initMercureLive();
        });
    </script>
</body>
</html>
