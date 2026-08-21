<?php
declare(strict_types=1);

// Worker loop logic
$requestCount = 0;
$startTime = microtime(true);

$handler = function () use (&$requestCount, $startTime) {
    $requestCount++;
    $reqStart = microtime(true);
    
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Powered-By: FrankenPHP Worker Mode');
    
    $mem = round(memory_get_usage(true) / 1024 / 1024, 2);
    $uptime = round(microtime(true) - $startTime, 2);
    $reqDuration = round((microtime(true) - $reqStart) * 1000, 3);
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>FrankenPHP Worker Mode Demo</title>
        <style>
            body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; padding: 2.5rem; }
            .box { max-width: 600px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 16px; border: 1px solid #334155; }
            h1 { color: #38bdf8; margin-bottom: 0.5rem; }
            p { color: #94a3b8; font-size: 0.95rem; }
            .stats { margin: 1.5rem 0; display: flex; flex-direction: column; gap: 0.75rem; }
            .stat { display: flex; justify-content: space-between; padding: 0.75rem; background: #0f172a; border-radius: 8px; }
            .badge { background: #0284c7; color: white; padding: 0.2rem 0.6rem; border-radius: 4px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>🚀 FrankenPHP Worker Mode</h1>
            <p>This script runs in-memory and demonstrates high-throughput state handling!</p>
            
            <div class="stats">
                <div class="stat"><span>Requests Handled:</span> <span class="badge"><?= $requestCount ?></span></div>
                <div class="stat"><span>Memory Allocated:</span> <span><?= $mem ?> MB</span></div>
                <div class="stat"><span>Worker Uptime:</span> <span><?= $uptime ?>s</span></div>
                <div class="stat"><span>Request Duration:</span> <span><?= $reqDuration ?> ms</span></div>
            </div>

            <p><a href="/" style="color: #38bdf8; text-decoration: none;">&larr; Back to Developer Hub</a></p>
        </div>
    </body>
    </html>
    <?php
};

// FrankenPHP worker loop with fallback for standard script execution
if (function_exists('frankenphp_handle_request')) {
    try {
        do {
            $running = frankenphp_handle_request($handler);
            gc_collect_cycles();
        } while ($running);
    } catch (\Throwable $e) {
        // Executed directly as a standard request
        $handler();
    }
} else {
    $handler();
}
