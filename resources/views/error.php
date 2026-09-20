<?php
use SyncBridge\Support\View;
?>
<main class="error-page"><section><p class="eyebrow">SyncBridge</p><h1><?= View::e((string) $heading) ?></h1><p><?= View::e((string) $message) ?></p><a class="button button-primary" href="/">Torna alla dashboard</a></section></main>

