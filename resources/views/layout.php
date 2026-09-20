<?php

use SyncBridge\Support\View;

$pageTitle = isset($title) ? (string) $title . ' · SyncBridge' : 'SyncBridge · API Automation Demo';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="SyncBridge: demo pubblica di webhook, code, retry e audit trail.">
    <title><?= View::e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<div class="page-shell">
    <header class="site-header">
        <a class="brand" href="/" aria-label="SyncBridge home">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            <span>Sync<span>Bridge</span></span>
        </a>
        <nav aria-label="Navigazione principale">
            <a href="/">Dashboard</a>
            <a href="/#workflow">Workflow</a>
            <span class="demo-badge"><b></b> Demo pubblica</span>
        </nav>
    </header>

    <?php if (!empty($_SESSION['_flash']) && is_array($_SESSION['_flash'])): ?>
        <div class="flash flash-<?= View::e((string) ($_SESSION['_flash']['type'] ?? 'success')) ?>">
            <?= View::e((string) ($_SESSION['_flash']['message'] ?? '')) ?>
        </div>
        <?php unset($_SESSION['_flash']); ?>
    <?php endif; ?>

    <?= $content ?>

    <footer class="site-footer">
        <p><strong>SyncBridge</strong> è un progetto dimostrativo sviluppato da Oscar Gambi.</p>
        <p>Nessun dato mostrato appartiene a clienti o transazioni reali.</p>
    </footer>
</div>
</body>
</html>

