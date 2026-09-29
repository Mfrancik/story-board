# Logging Standards
Read before building any feature logic. The goal: any single request can be
traced end-to-end months later, and every feature doc's Observability section
points at real, greppable log events.

## Channel setup (config/logging.php)
- Default channel: `stack` → [`daily`, plus `slack`/Sentry for error+ in prod].
- The `daily` channel uses the **JSON formatter** — structured logs, not prose,
  so they're machine-searchable. 14-day retention locally, 30+ in prod.
- Local dev may add `stderr` for convenience; never rely on it as the record.

## Correlation ID (the backbone)
- Middleware `AssignRequestId` runs on every request: reuse inbound
  `X-Request-Id` or generate a UUID, then `Log::withContext(['request_id' => $id])`
  and return it on the response header.
- Queued jobs carry the originating `request_id` into their own log context so
  async work stays traceable to the request that caused it.
- Rule: given one request_id, `grep` must reconstruct the entire story of that
  request across web, Livewire, and queue.

## Event naming convention
Every intentional log line has a stable, greppable event name as its message:

    <feature>.<action>.<result>
    auth.login.succeeded · auth.login.failed · invoice.create.succeeded
    payment.webhook.rejected · export.generate.started

Context array carries the details. Never interpolate variable data into the
message string — it belongs in context, or the event isn't greppable.

    Log::info('invoice.create.succeeded', ['invoice_id' => $invoice->id,
        'user_id' => $user->id, 'total_cents' => $invoice->total]);

## What must be logged (per feature — this is the checklist /preflight audits)
- Auth events: login success/failure, logout, password reset, permission denied.
- Every write that matters: create/update/delete of core domain records, with
  the acting user_id and the record id.
- Every external call (APIs, mail, payment): started + succeeded/failed, with
  duration_ms and a safe summary of the response — never full payloads.
- Every queued job: started, succeeded, or failed (with exception).
- Every error branch: if code catches an exception or rejects input for a
  non-obvious reason, it logs why before moving on.
- Feature-specific events listed in the story's Applicable Standards section.

## Levels
- `debug` local-only diagnostics · `info` normal business events ·
- `warning` handled-but-abnormal (retry, validation of suspicious input,
  external service slow) · `error` a request failed to do its job ·
- `critical` data integrity or money at risk. No `info` spam inside loops.

## Never log
Passwords, tokens, API keys, session IDs, full card numbers, raw request
bodies of auth endpoints, or personal data beyond ids/emails needed to trace.
When in doubt, log the id, not the object.

## Tie-in to the rest of the system
- `/document`'s Observability section must list the feature's event names, the
  levels used, and one example grep/query to trace a healthy flow.
- `/preflight` treats a critical path with no log events as CRITICAL (blocking).
- RUNBOOK entries should cite the log events that identified the root cause,
  so future debugging starts from the logs, not from scratch.
