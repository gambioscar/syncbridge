<?php

use SyncBridge\Support\Csrf;
use SyncBridge\Support\View;

$event = $detail['event'];
?>
<main class="detail-page">
    <a class="back-link" href="/">← Dashboard</a>
    <div class="detail-heading">
        <div><p class="eyebrow">Event inspection</p><h1><?= View::e(View::eventLabel((string) $event['event_type'])) ?></h1><code><?= View::e((string) $event['uuid']) ?></code></div>
        <span class="status status-<?= View::e((string) $event['status']) ?>"><?= View::e(View::statusLabel((string) $event['status'])) ?></span>
    </div>

    <section class="detail-grid">
        <article class="panel event-summary">
            <div class="panel-title"><h2>Stato operativo</h2><span><?= (int) $event['attempts'] ?> tentativi</span></div>
            <dl>
                <div><dt>Origine</dt><dd><?= View::e((string) $event['source_system']) ?></dd></div>
                <div><dt>Destinazione</dt><dd><?= View::e((string) $event['target_system']) ?></dd></div>
                <div><dt>Ricevuto</dt><dd><?= View::e(View::date((string) $event['received_at'])) ?></dd></div>
                <div><dt>Prossimo tentativo</dt><dd><?= View::e(View::date($event['next_attempt_at'] === null ? null : (string) $event['next_attempt_at'])) ?></dd></div>
                <div><dt>Duplicati ignorati</dt><dd><?= (int) $event['duplicate_count'] ?></dd></div>
                <div><dt>SHA-256</dt><dd><code><?= View::e(substr((string) $event['payload_sha256'], 0, 16)) ?>…</code></dd></div>
            </dl>
            <?php if (!empty($event['last_error_message'])): ?><div class="error-box"><strong>Ultimo errore</strong><p><?= View::e((string) $event['last_error_message']) ?></p></div><?php endif; ?>
            <?php if ((string) $event['status'] === 'retrying'): ?>
                <form method="post" action="/events/<?= View::e((string) $event['uuid']) ?>/retry-now"><input type="hidden" name="csrf" value="<?= View::e(Csrf::token()) ?>"><button class="button button-primary" type="submit">Esegui subito il retry</button></form>
            <?php elseif (in_array((string) $event['status'], ['failed', 'dead_letter'], true)): ?>
                <form method="post" action="/events/<?= View::e((string) $event['uuid']) ?>/replay"><input type="hidden" name="csrf" value="<?= View::e(Csrf::token()) ?>"><button class="button button-primary" type="submit">Riproduci evento</button></form>
            <?php endif; ?>
        </article>

        <article class="panel payload-panel"><div class="panel-title"><h2>Payload ricevuto</h2><span>JSON</span></div><pre><?= View::e(View::json((string) $event['payload_json'])) ?></pre></article>
    </section>

    <section class="panel timeline-panel">
        <div class="panel-title"><h2>Tentativi di consegna</h2><span><?= count($detail['attempts']) ?> registrati</span></div>
        <?php if ($detail['attempts'] === []): ?><p class="muted">L’evento non è stato ancora elaborato.</p><?php else: ?>
            <div class="attempt-list">
            <?php foreach ($detail['attempts'] as $attempt): ?>
                <article><span class="attempt-dot outcome-<?= View::e((string) $attempt['outcome']) ?>"></span><div><strong>Tentativo #<?= (int) $attempt['attempt_number'] ?></strong><p><?= View::e((string) ($attempt['error_message'] ?: 'Consegna completata dal gestionale.')) ?></p><small><?= View::e(View::date((string) $attempt['finished_at'])) ?> · <?= (int) $attempt['duration_ms'] ?> ms · HTTP <?= (int) $attempt['response_code'] ?></small></div></article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel timeline-panel">
        <div class="panel-title"><h2>Audit trail</h2><span><?= count($detail['audit']) ?> operazioni</span></div>
        <div class="audit-grid">
        <?php foreach ($detail['audit'] as $audit): ?>
            <article><span></span><div><strong><?= View::e(str_replace('_', ' ', (string) $audit['action'])) ?></strong><small><?= View::e(View::date((string) $audit['created_at'])) ?> · <?= View::e((string) $audit['actor_type']) ?></small></div></article>
        <?php endforeach; ?>
        </div>
    </section>
</main>

