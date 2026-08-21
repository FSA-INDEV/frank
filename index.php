<?php
declare(strict_types=1);

// Helper function to scan /var/www for projects
function getProjects(string $baseDir): array {
    $ignored = ['.', '..', '.git', '.github', '.idea', '.vscode', '.frankenphp', '.database', 'vendor', 'node_modules', 'caddy_data', 'caddy_config', 'bin'];
    $projects = [];
    
    if (!is_dir($baseDir)) {
        return $projects;
    }
    
    $entries = scandir($baseDir);
    foreach ($entries as $entry) {
        if (in_array($entry, $ignored, true)) {
            continue;
        }
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($fullPath)) {
            $hasPublic = file_exists($fullPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
            $hasAppSpa = file_exists($fullPath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'index.html');
            $hasIndex = file_exists($fullPath . DIRECTORY_SEPARATOR . 'index.php') || file_exists($fullPath . DIRECTORY_SEPARATOR . 'index.html');
            $type = 'directory';
            $url = '/' . $entry . '/';
            
            if ($hasPublic) {
                $type = 'mvc';
            } elseif ($hasAppSpa) {
                $type = 'spa';
                $url = '/' . $entry . '/app/';
            } elseif ($hasIndex) {
                $type = 'flat';
            }
            
            $projects[] = [
                'name' => $entry,
                'path' => $fullPath,
                'url' => $url,
                'type' => $type,
                'has_public' => $hasPublic,
                'modified' => filemtime($fullPath),
            ];
        }
    }
    
    usort($projects, fn($a, $b) => $b['modified'] <=> $a['modified']);
    return $projects;
}

// Database Health Check
$dbStatus = false;
$dbVersion = 'N/A';
$dbCount = 0;
$dbList = [];
try {
    $host = getenv('DB_HOST') ?: 'database';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE') ?: 'app_dev';
    $user = getenv('DB_USERNAME') ?: 'dev';
    $pass = getenv('DB_PASSWORD') ?: 'secret';
    
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $dbStatus = true;
    $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
    $dbList = $pdo->query("
        SELECT table_schema as db_name, COUNT(table_name) as table_count 
        FROM information_schema.tables 
        WHERE table_schema NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
        GROUP BY table_schema
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $dbStatus = false;
}

$projects = getProjects(__DIR__);
$extensions = get_loaded_extensions();
natcasesort($extensions);
$opcache = function_exists('opcache_get_status') ? @opcache_get_status() : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FrankenPHP Developer Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0f19;
            --surface: #111827;
            --surface-card: #1f2937;
            --surface-hover: #374151;
            --border: #374151;
            --text: #f9fafb;
            --text-muted: #9ca3af;
            --primary: #38bdf8;
            --primary-glow: rgba(56, 189, 248, 0.15);
            --secondary: #818cf8;
            --accent: #ec4899;
            --success: #10b981;
            --warning: #f59e0b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 2rem 1.5rem;
            line-height: 1.5;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-badge {
            font-size: 2.2rem;
            background: linear-gradient(135deg, #0284c7, #6366f1);
            padding: 0.5rem 0.8rem;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.3);
        }

        .brand h1 {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            background: linear-gradient(to right, #38bdf8, #818cf8, #f472b6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand p {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 600;
        }

        .badge-success {
            background-color: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: currentColor;
            box-shadow: 0 0 8px currentColor;
        }

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

        .btn:hover {
            background-color: var(--surface-hover);
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-primary {
            background: linear-gradient(135deg, #0284c7, #4f46e5);
            border: none;
            color: #fff;
        }

        .btn-primary:hover {
            opacity: 0.9;
            color: #fff;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 900px) {
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .project-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .project-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            background-color: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
        }

        .project-item:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(56, 189, 248, 0.1);
        }

        .project-info {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .project-icon {
            font-size: 1.5rem;
            background-color: var(--surface-hover);
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .project-meta h3 {
            font-size: 1rem;
            font-weight: 700;
        }

        .project-meta p {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
        }

        .type-tag {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .tag-mvc {
            background-color: rgba(99, 102, 241, 0.2);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.4);
        }

        .tag-spa {
            background-color: rgba(236, 72, 153, 0.2);
            color: #f472b6;
            border: 1px solid rgba(236, 72, 153, 0.4);
        }

        .tag-flat {
            background-color: rgba(56, 189, 248, 0.2);
            color: #7dd3fc;
            border: 1px solid rgba(56, 189, 248, 0.4);
        }

        .tag-dir {
            background-color: rgba(156, 163, 175, 0.2);
            color: #d1d5db;
        }

        /* Database credentials box */
        .cred-box {
            background: #030712;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.85rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 1rem;
        }
        .cred-row { display: flex; justify-content: space-between; }
        .cred-key { color: var(--text-muted); }
        .cred-val { color: #38bdf8; font-weight: 600; }

        /* Diagnostics */
        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.75rem;
        }

        .diag-item {
            background-color: var(--surface-card);
            padding: 0.85rem;
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        .diag-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .diag-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
            font-family: 'JetBrains Mono', monospace;
        }

        .ext-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            max-height: 140px;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .chip {
            background-color: var(--surface-card);
            border: 1px solid var(--border);
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-family: 'JetBrains Mono', monospace;
        }

        .chip.highlight {
            background-color: rgba(56, 189, 248, 0.15);
            border-color: rgba(56, 189, 248, 0.4);
            color: #38bdf8;
        }

        footer {
            margin-top: 3rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.875rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="brand">
                <div class="logo-badge">🐘</div>
                <div>
                    <h1>FrankenPHP Developer Hub</h1>
                    <p>High-Performance Drop-In PHP, MariaDB & Mercure Development Server</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="badge badge-success">
                    <span class="dot"></span> Server Active (PHP <?= PHP_VERSION ?>)
                </div>
                <a href="http://localhost:8080/?server=database&username=root&db=fsa_db" target="_blank" class="btn btn-primary">🗄️ Adminer DB</a>
                <a href="/mercure-demo.html" target="_blank" class="btn">⚡ Mercure Hub</a>
                <a href="/demo-db/" class="btn">📦 DB Demo App</a>
            </div>
        </header>

        <div class="grid-2">
            <!-- Drop-In Projects List -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span>📁</span>
                        <span>Drop-In Projects (<code>/var/www/</code>)</span>
                    </div>
                    <span class="badge" style="background: var(--surface-card);"><?= count($projects) ?> Detected</span>
                </div>

                <div class="project-list">
                    <?php if (empty($projects)): ?>
                        <div class="empty-state">
                            <p>No subdirectories found in <code>/var/www</code> yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($projects as $p): ?>
                            <a href="<?= htmlspecialchars($p['url']) ?>" class="project-item">
                                <div class="project-info">
                                    <div class="project-icon">
                                        <?= $p['type'] === 'mvc' ? '🚀' : ($p['type'] === 'spa' ? '⚡' : ($p['type'] === 'flat' ? '📄' : '📁')) ?>
                                    </div>
                                    <div class="project-meta">
                                        <h3><?= htmlspecialchars($p['name']) ?></h3>
                                        <p><?= htmlspecialchars($p['url']) ?></p>
                                    </div>
                                </div>
                                <span class="type-tag tag-<?= $p['type'] ?>">
                                    <?= $p['type'] === 'mvc' ? 'MVC (public/)' : ($p['type'] === 'spa' ? 'SPA (app/)' : ($p['type'] === 'flat' ? 'Flat PHP' : 'Folder')) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Database & Credentials Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span>🗄️</span>
                        <span>Databases (MariaDB 11.4 / MySQL)</span>
                    </div>
                    <span class="badge" style="background: <?= $dbStatus ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; color: <?= $dbStatus ? '#34d399' : '#f87171' ?>;">
                        <?= $dbStatus ? '● Connected' : '● Disconnected' ?>
                    </span>
                </div>

                <div class="cred-box">
                    <div class="cred-row"><span class="cred-key">Server (Inside Docker):</span> <span class="cred-val">database:3306</span></div>
                    <div class="cred-row"><span class="cred-key">Host (From PC/DBeaver):</span> <span class="cred-val">localhost:3306</span></div>
                    <div class="cred-row"><span class="cred-key">Databases Active:</span> <span class="cred-val"><?= implode(', ', array_column($dbList, 'db_name')) ?></span></div>
                    <div class="cred-row"><span class="cred-key">User / Password:</span> <span class="cred-val">root / root</span></div>
                </div>

                <div style="display: flex; gap: 0.75rem;">
                    <a href="http://localhost:8080/?server=database&username=root&db=fsa_db" target="_blank" class="btn btn-primary" style="flex: 1; justify-content: center;">
                        ⚡ Open fsa_db in Adminer
                    </a>
                    <a href="/demo-db/" class="btn" style="flex: 1; justify-content: center;">
                        📦 Open DB Demo App
                    </a>
                </div>
            </div>
        </div>

        <div class="grid-3">
            <!-- Server Diagnostics -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><span>⚙️</span><span>PHP Runtime</span></div>
                </div>
                <div class="diag-grid">
                    <div class="diag-item">
                        <div class="diag-label">PHP Version</div>
                        <div class="diag-value"><?= PHP_VERSION ?></div>
                    </div>
                    <div class="diag-item">
                        <div class="diag-label">Memory Limit</div>
                        <div class="diag-value"><?= ini_get('memory_limit') ?></div>
                    </div>
                    <div class="diag-item">
                        <div class="diag-label">Upload Max</div>
                        <div class="diag-value"><?= ini_get('upload_max_filesize') ?></div>
                    </div>
                    <div class="diag-item">
                        <div class="diag-label">Execution Time</div>
                        <div class="diag-value"><?= ini_get('max_execution_time') ?>s</div>
                    </div>
                </div>
            </div>

            <!-- OPcache Status -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><span>⚡</span><span>OPcache Engine</span></div>
                    <span class="badge badge-success"><?= ($opcache && $opcache['opcache_enabled']) ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <div class="diag-grid">
                    <div class="diag-item">
                        <div class="diag-label">Cached Scripts</div>
                        <div class="diag-value"><?= $opcache['opcache_statistics']['num_cached_scripts'] ?? 0 ?></div>
                    </div>
                    <div class="diag-item">
                        <div class="diag-label">Hit Rate</div>
                        <div class="diag-value"><?= number_format($opcache['opcache_statistics']['opcache_hit_rate'] ?? 100, 1) ?>%</div>
                    </div>
                    <div class="diag-item">
                        <div class="diag-label">Memory Used</div>
                        <div class="diag-value"><?= isset($opcache['memory_usage']['used_memory']) ? round($opcache['memory_usage']['used_memory'] / 1024 / 1024, 1) . 'MB' : 'N/A' ?></div>
                    </div>
                    <div class="diag-item">
                        <div class="diag-label">JIT Engine</div>
                        <div class="diag-value"><?= isset($opcache['jit']['enabled']) && $opcache['jit']['enabled'] ? 'Active' : 'Standby' ?></div>
                    </div>
                </div>
            </div>

            <!-- Loaded Extensions -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><span>🧩</span><span>Loaded Extensions (<?= count($extensions) ?>)</span></div>
                </div>
                <div class="ext-chips">
                    <?php 
                    $priorityExts = ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'redis', 'opcache', 'intl', 'zip', 'pcntl', 'apcu', 'bcmath', 'gd', 'xdebug'];
                    foreach ($extensions as $ext): 
                        $isHigh = in_array(strtolower($ext), $priorityExts, true);
                    ?>
                        <span class="chip <?= $isHigh ? 'highlight' : '' ?>"><?= htmlspecialchars($ext) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <footer>
            FrankenPHP Drop-In Development Server &bull; Powered by Caddy, PHP 8.5, MariaDB 11.4 & Mercure &bull; <a href="/healthz" style="color: var(--primary); text-decoration: none;">Healthcheck: OK</a>
        </footer>
    </div>
</body>
</html>
