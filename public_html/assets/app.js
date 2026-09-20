'use strict';

const form = document.querySelector('#demo-form');
const result = document.querySelector('#result');

if (form && result) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    result.textContent = 'Preparazione e invio del webhook…';

    try {
      const data = Object.fromEntries(new FormData(form).entries());
      const preparedResponse = await fetch('/demo/prepare', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify(data),
      });
      const prepared = await preparedResponse.json();
      if (!preparedResponse.ok) throw new Error(prepared.error || 'Preparazione non riuscita.');

      const webhookResponse = await fetch('/api/webhooks/shop', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-SyncBridge-Timestamp': prepared.timestamp,
          'X-SyncBridge-Signature': prepared.signature,
          'X-SyncBridge-Demo-Session': prepared.demo_session_id,
        },
        body: prepared.body,
      });
      const webhook = await webhookResponse.json();
      if (!webhookResponse.ok) throw new Error(webhook.error || 'Webhook non accettato.');

      const processResponse = await fetch('/demo/process', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({csrf: data.csrf}),
      });
      const processing = await processResponse.json();
      if (!processResponse.ok) throw new Error(processing.error || 'Elaborazione non riuscita.');

      result.textContent = JSON.stringify({webhook, processing}, null, 2);
      window.setTimeout(() => {
        window.location.assign(`/events/${encodeURIComponent(webhook.uuid)}`);
      }, 450);
    } catch (error) {
      result.textContent = error instanceof Error ? error.message : 'Errore inatteso.';
    } finally {
      submit.disabled = false;
    }
  });
}
