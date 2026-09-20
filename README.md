# SyncBridge

SyncBridge is a public, fictional integration demo that shows how orders and
customers can be synchronized from an e-commerce platform to an ERP through
signed webhooks, a durable queue, automatic retries, idempotency and a complete
audit trail.

The application is designed for PHP 8.2+ and MySQL/MariaDB, with no framework
dependency. It can run locally through Docker and on shared hosting such as
SiteGround.

## Status

Milestone 3 includes the complete responsive dashboard, event inspection,
per-attempt history, audit trail, manual retry/replay, request throttling and
automatic demo-data cleanup, in addition to the signed webhook and queue
engine delivered in the previous milestones.

## Demonstration flow

1. A visitor creates a fictional order or customer in the simulator.
2. SyncBridge signs and submits a real HTTP webhook.
3. The receiver verifies the signature and stores the event exactly once.
4. The worker validates and maps the payload for the fictional ERP.
5. Transient errors are retried with exponential backoff.
6. Permanent failures move to the dead-letter queue and can be replayed.
7. Every transition remains visible in the audit trail.

No real customer, order or company data is used by the public demo.

## Planned public routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/` | Operational dashboard |
| POST | `/demo/events` | Create a fictional demo event |
| POST | `/api/webhooks/shop` | Receive a signed webhook |
| POST | `/demo/process` | Process one event in the interactive demo |
| GET | `/events/{uuid}` | Event details and attempts |
| POST | `/events/{uuid}/replay` | Replay failed/dead-letter event |
| POST | `/events/{uuid}/retry-now` | Run a scheduled retry immediately |
| POST | `/cron/process` | Protected shared-hosting worker |
| GET | `/health` | Safe application health check |

## Security baseline

- HMAC-SHA256 signature over the raw request body.
- Timestamp tolerance to reduce replay attacks.
- Unique idempotency key for every source event.
- CSRF protection on browser actions.
- Constant-time comparison for signatures and secrets.
- Generic public error messages; technical details stay in server logs.
- Prepared SQL statements and strict payload validation.
- Public demo uses fictional data and per-session filtering.
- Per-IP request limits are stored as keyed hashes, not raw addresses.
- Expired demo data can be removed by the bundled cleanup task.

See [`docs/architecture.md`](docs/architecture.md) for the technical design and
[`database/schema.sql`](database/schema.sql) for the MySQL schema.

The SiteGround deployment layout is documented in
[`docs/deployment-siteground.md`](docs/deployment-siteground.md).
