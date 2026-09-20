<?php

use SyncBridge\Support\Csrf;
use SyncBridge\Support\View;
?>
<main>
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow"><span></span> Integration reliability platform</p>
            <h1>Le integrazioni non devono <em>rompersi in silenzio.</em></h1>
            <p class="hero-lead">SyncBridge riceve webhook, sincronizza sistemi e rende visibile ogni tentativo. Errori inclusi.</p>
            <div class="hero-actions">
                <a class="button button-primary" href="#simulator">Prova un webhook <span>→</span></a>
                <a class="button button-ghost" href="#workflow">Scopri il flusso</a>
            </div>
            <ul class="hero-points">
                <li>✓ Firma HMAC</li><li>✓ Retry automatici</li><li>✓ Audit completo</li>
            </ul>
        </div>
        <div class="integration-map" aria-label="Flusso e-commerce, SyncBridge e gestionale">
            <div class="map-line"></div>
            <div class="map-node node-shop"><small>SORGENTE</small><strong>Demo Shop</strong><span>Ordini · Clienti</span></div>
            <div class="map-core"><span class="core-pulse"></span><b>SB</b><strong>SyncBridge</strong><small>EVENT ROUTER</small></div>
            <div class="map-node node-erp"><small>DESTINAZIONE</small><strong>Demo ERP</strong><span>Dati normalizzati</span></div>
            <span class="event-chip chip-one">order.created</span>
            <span class="event-chip chip-two">HMAC ✓</span>
        </div>
    </section>

    <section class="metric-grid" aria-label="Riepilogo sessione">
        <article><span>Eventi ricevuti</span><strong><?= (int) $counts['total'] ?></strong><small>nella sessione</small></article>
        <article><span>Sincronizzati</span><strong><?= (int) $counts['succeeded'] ?></strong><small class="positive">consegnati all’ERP</small></article>
        <article><span>In attesa</span><strong><?= (int) ($counts['queued'] + $counts['processing'] + $counts['retrying']) ?></strong><small class="warning">coda e retry</small></article>
        <article><span>Da recuperare</span><strong><?= (int) ($counts['failed'] + $counts['dead_letter']) ?></strong><small class="negative">falliti o dead letter</small></article>
    </section>

    <section class="content-section" id="simulator">
        <div class="section-heading">
            <div><p class="eyebrow">Live simulator</p><h2>Invia un evento reale</h2></div>
            <p>Il simulatore genera dati fittizi, firma il payload sul server e lo invia al vero endpoint webhook.</p>
        </div>
        <div class="simulator-card">
            <form id="demo-form" class="simulator-form">
                <input type="hidden" name="csrf" value="<?= View::e(Csrf::token()) ?>">
                <label>Tipo di evento
                    <select name="event_type">
                        <option value="order.created">Nuovo ordine</option>
                        <option value="customer.updated">Cliente aggiornato</option>
                    </select>
                </label>
                <label>Comportamento destinazione
                    <select name="scenario">
                        <option value="success">Sincronizzazione riuscita</option>
                        <option value="temporary_failure">Errore temporaneo + retry</option>
                        <option value="permanent_failure">Errore permanente</option>
                    </select>
                </label>
                <button class="button button-primary" type="submit">Invia webhook <span>→</span></button>
            </form>
            <div class="terminal" aria-live="polite">
                <div class="terminal-top"><span></span><span></span><span></span><b>LIVE REQUEST</b></div>
                <pre id="result">Pronto a ricevere un evento…</pre>
            </div>
        </div>
    </section>

    <section class="content-section" id="events">
        <div class="section-heading">
            <div><p class="eyebrow">Event stream</p><h2>Ultimi eventi</h2></div>
            <p>Ogni stato è persistente e può essere ispezionato fino al singolo tentativo.</p>
        </div>
        <div class="table-card">
            <?php if ($events === []): ?>
                <div class="empty-state"><span>↗</span><h3>Nessun evento nella sessione</h3><p>Usa il simulatore per avviare il primo flusso.</p></div>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Evento</th><th>Stato</th><th>Tentativi</th><th>Ricevuto</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><strong><?= View::e(View::eventLabel((string) $event['event_type'])) ?></strong><small><?= View::e(substr((string) $event['uuid'], 0, 8)) ?>…</small></td>
                                <td><span class="status status-<?= View::e((string) $event['status']) ?>"><?= View::e(View::statusLabel((string) $event['status'])) ?></span></td>
                                <td><?= (int) $event['attempts'] ?> / <?= (int) $event['max_attempts'] ?></td>
                                <td><?= View::e(View::date((string) $event['created_at'])) ?></td>
                                <td><a class="row-link" href="/events/<?= View::e((string) $event['uuid']) ?>">Dettagli →</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="workflow" id="workflow">
        <div class="section-heading light"><div><p class="eyebrow">Under the hood</p><h2>Un flusso progettato per fallire bene</h2></div></div>
        <div class="workflow-grid">
            <article><b>01</b><h3>Ricezione verificata</h3><p>Timestamp e firma HMAC validano l’origine e l’integrità del payload.</p></article>
            <article><b>02</b><h3>Coda idempotente</h3><p>Lo stesso evento può arrivare più volte senza produrre duplicati.</p></article>
            <article><b>03</b><h3>Retry controllati</h3><p>Gli errori temporanei vengono riprovati con intervalli progressivi.</p></article>
            <article><b>04</b><h3>Recupero trasparente</h3><p>Audit e replay manuale rendono diagnosticabile ogni interruzione.</p></article>
        </div>
    </section>
</main>

