<!DOCTYPE html>
<html>
<head>
    <title>Demo Flat PHP Project</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; padding: 2.5rem; text-align: center; }
        .card { max-width: 600px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 16px; border: 1px solid #334155; }
        h1 { color: #38bdf8; }
        a { color: #818cf8; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>📁 Demo Flat PHP Project</h1>
        <p>This is a standard drop-in project without a <code>public/</code> directory.</p>
        <p style="margin: 1.5rem 0;">Current Time: <strong><?= date('Y-m-d H:i:s') ?></strong></p>
        <p><a href="about.php">Go to About Page &rarr;</a> &bull; <a href="/">&larr; Back to Dashboard</a></p>
    </div>
</body>
</html>
