# SyncBridge architecture

## Product position

SyncBridge demonstrates backend integration reliability. It complements a
document-processing portfolio project by focusing on APIs, webhooks,
automation, observability and recovery from failures.

The fictional business scenario is deliberately familiar: an e-commerce
platform sends customer and order changes to an ERP. The external systems are
simulated, while the HTTP requests, signatures, persistence, queue processing,
retries and audit records are real.

## Components

| Component | Responsibility |
| --- | --- |
| Demo simulator | Creates fictional customer/order payloads and selected failure scenarios |
| Webhook receiver | Verifies timestamp and HMAC signature, validates the envelope and enqueues once |
| MySQL queue | Persists payload, status, scheduling and idempotency state |
| Worker | Claims due events, maps data and invokes the target adapter |
| ERP adapter | Simulates success, transient failure or permanent validation failure |
| Retry policy | Schedules retryable failures using bounded exponential backoff |
| Dead-letter queue | Retains exhausted or permanent failures for inspection and replay |
| Audit trail | Records every relevant action and transition |
| Dashboard | Shows health, counters, event details, attempts and manual replay |

## Event lifecycle

Allowed states:

- `queued`: accepted and ready to be processed;
- `processing`: exclusively claimed by a worker;
- `succeeded`: delivered and synchronized;
- `retrying`: temporary failure, waiting for `next_attempt_at`;
- `failed`: permanent validation or mapping failure;
- `dead_letter`: retry limit exhausted.

Allowed transitions:

| From | To |
| --- | --- |
| queued | processing |
| retrying | processing |
| processing | succeeded |
| processing | retrying |
| processing | failed |
| processing | dead_letter |
| failed | queued (manual replay) |
| dead_letter | queued (manual replay) |

Duplicate webhook deliveries do not create another event. They increment the
duplicate counter and create an audit entry tied to the original event.

## Retry policy

The default maximum is five processing attempts. Retry delays are 1, 5, 15 and
60 minutes. A later configuration layer may override these values, but the
public demo keeps them deterministic and visible.

Only temporary failures are retried, for example timeouts, connection errors
or simulated HTTP 429/5xx responses. Invalid payloads and simulated HTTP 4xx
business errors fail immediately.

## Concurrency and recovery

Workers claim events inside a transaction. `locked_at` and `locked_by` prevent
two workers from processing the same event. A maintenance operation can return
stale `processing` events to `retrying` when a worker is interrupted.

The initial shared-hosting version processes a small bounded batch per cron
invocation. The same service can later run continuously without changing the
domain model.

## Public-demo isolation

Browser-generated events carry a random `demo_session_id`. The dashboard only
shows events belonging to the current browser session. The value contains no
personal information and expires with cleanup. Raw API requests can use a
documented demo session header for reproducible tests.

## Deployment model

The repository root remains outside the public document root. SiteGround's
`public_html` receives the contents of `public/`, while application code,
configuration and writable storage remain one level above it. Secrets live in
`.env`, which is never committed.

The worker has two entry points:

- CLI: `php bin/worker.php` for local/server shell use;
- HTTP cron: `/cron/process?token=...` for hosting control panels.

Both call the same application service and apply the same batch limits.

