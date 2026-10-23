# PolicyFlow — Fault-Tolerant Insurance Issuance & Reconciliation Engine

PolicyFlow is a REST API built with Laravel and PostgreSQL for receiving travel-insurance requests, calculating a premium, submitting requests to a deterministic mock insurer, recording state transitions and attempts, and reconciling uncertain issuance outcomes without issuing a duplicate policy.

## Features

- Validated policy intake with inclusive trip-day calculation and a stored premium snapshot.
- Database-enforced idempotency for `(agent_id, client_request_id)`.
- Explicit request states and append-only state-history records.
- Insurer attempt tracking, provider references, unique policy numbers, and a database-backed mock-insurer issuance registry.
- Simulated `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, and `DUPLICATE_CALLBACK` scenarios.
- Callback replay/duplicate handling through `POST /api/mock-insurer/callback`.
- Reconciliation for cases where an insurer may have issued a policy but the application did not receive confirmation.
- Status filtering and pagination.
- Automated feature tests.

## Technology

- PHP 8.4
- Laravel 13
- PostgreSQL 16
- Docker
- PHPUnit / Laravel feature testing

## Architecture

The HTTP API is handled by Laravel controllers. `PremiumCalculator` owns premium calculation. Eloquent models represent policy requests, status history, insurer attempts, and mock-insurer issuance records. Database transactions protect state transitions, and unique constraints provide a final safeguard against duplicate intake and policy issuance.

## Run locally with Docker (PowerShell)

These instructions assume Docker Desktop is running and the project files are in the current directory.

1. Install PHP dependencies into `vendor`:

   ```powershell
   docker run --rm -v "${PWD}:/app" -w /app composer:2 install
   ```

2. Build the PHP image:

   ```powershell
   docker build -t policyflow-php .
   ```

3. Start PostgreSQL. Run this once to create the container:

   ```powershell
   docker run --name policyflow-db `
     -e POSTGRES_DB=policyflow `
     -e POSTGRES_USER=policyflow_user `
     -e POSTGRES_PASSWORD=policyflow_dev_password `
     -p 5433:5432 `
     -d postgres:16
   ```

   If the container already exists but is stopped, use `docker start policyflow-db` instead of creating it again.

4. Ensure `.env` exists (copy `.env.example` if necessary) and configure these database settings:

   ```dotenv
   DB_CONNECTION=pgsql
   DB_HOST=host.docker.internal
   DB_PORT=5433
   DB_DATABASE=policyflow
   DB_USERNAME=policyflow_user
   DB_PASSWORD=policyflow_dev_password
   ```

   These credentials are for local development only; use managed secrets for a deployed environment.

5. Generate the application key and apply migrations:

   ```powershell
   docker run --rm -v "${PWD}:/app" -w /app policyflow-php php artisan key:generate
   docker run --rm -v "${PWD}:/app" -w /app policyflow-php php artisan migrate
   ```

6. Start the API server in a terminal and leave it running:

   ```powershell
   docker run --rm -it -p 8000:8000 -v "${PWD}:/app" -w /app policyflow-php php artisan serve --host=0.0.0.0
   ```

   Base URL: `http://localhost:8000`

## API endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/policy-requests` | Validate intake, calculate premium, persist the request |
| `GET` | `/api/policy-requests/{id}` | Retrieve a request with state history and insurer attempts |
| `POST` | `/api/policy-requests/{id}/submit` | Submit to the mock insurer using a selected scenario |
| `POST` | `/api/mock-insurer/callback` | Process an insurer callback; repeated callbacks are safe |
| `POST` | `/api/policy-requests/{id}/reconcile` | Verify an uncertain result against the mock-insurer registry |
| `GET` | `/api/policy-requests?status=ISSUED&per_page=10&page=1` | List, filter, and paginate requests |

### Create a policy request

```http
POST /api/policy-requests
Content-Type: application/json

{
  "client_request_id": "REQ-001",
  "agent_id": "AGENT-001",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-12",
  "destination": "DOMESTIC",
  "trip_start": "2027-01-10",
  "trip_end": "2027-01-12",
  "plan_code": "BASIC",
  "sum_insured": 100000
}
```

A new request returns `201 Created`; a replay with the same `(agent_id, client_request_id)` returns `200 OK` and `idempotent_replay: true`. Invalid input returns `422 Unprocessable Entity`. Supported destinations are `DOMESTIC` and `INTERNATIONAL`; plans are `BASIC`, `PLUS`, and `PREMIUM`; coverage amounts are `100000`, `300000`, or `500000`.

### Premium calculation

The inclusive trip duration is `trip_end - trip_start + 1 day`.

`Premium = daily plan rate × trip days × age multiplier × destination multiplier × coverage multiplier`

| Factor | Value |
|---|---|
| Daily plan rates | BASIC ₹12; PLUS ₹20; PREMIUM ₹30 |
| Age at trip start | 0–17: 1.10; 18–60: 1.00; 61–70: 1.50; 71+: 2.00 |
| Destination | DOMESTIC: 1.00; INTERNATIONAL: 1.80 |
| Sum insured | ₹100,000: 1.00; ₹300,000: 1.25; ₹500,000: 1.50 |

The calculated amount and multipliers are stored with the request as a snapshot. For example, a 3-day domestic BASIC trip for a customer aged 28 with ₹100,000 coverage costs ₹36.00.

### Submit scenarios

Submit with a JSON body such as `{"scenario":"SUCCESS"}`. The default scenario is `SUCCESS`.

| Scenario | Expected behavior |
|---|---|
| `SUCCESS` | Mock insurer issuance is confirmed; request becomes `ISSUED` and receives a unique policy number. |
| `TEMPORARY_FAILURE` | Returns `503`; request becomes `FAILED` and is marked retryable. A later submission can retry. This simulated failure occurs before a policy is created in the mock-insurer registry. |
| `TIMEOUT_AFTER_ISSUE` | Returns `202`; the mock insurer has issued a policy, but the application records `PENDING_CONFIRMATION`. Call reconciliation instead of resubmitting. |
| `DELAYED_CALLBACK` | Returns `202` and records `PENDING_CONFIRMATION`; a later callback or reconciliation confirms the existing issuance. |
| `DUPLICATE_CALLBACK` | Returns `202` with the issuance pending callback delivery. Send the same success callback twice: the first confirms issuance and the replay is acknowledged as a duplicate without changing the policy. |

Example:

```http
POST /api/policy-requests/1/submit
Content-Type: application/json

{"scenario":"TIMEOUT_AFTER_ISSUE"}
```

### Callback and duplicate-callback behavior

```http
POST /api/mock-insurer/callback
Content-Type: application/json

{
  "policy_request_id": 1,
  "provider_reference": "MOCK-1",
  "outcome": "SUCCESS",
  "policy_number": "POL-00000001"
}
```

Allowed callback outcomes are `SUCCESS` and `FAILED`. Re-delivering a callback after issuance returns `duplicate_callback: true` and does not change the issued policy. A provider-reference mismatch is rejected. To exercise the explicit `DUPLICATE_CALLBACK` scenario, submit using that scenario and then deliver the identical success callback twice. The first callback changes the request to `ISSUED`; the replay returns `duplicate_callback: true` and leaves the policy number unchanged.

### Reconcile an uncertain result

```http
POST /api/policy-requests/1/reconcile
```

The reconciliation endpoint checks the mock-insurer issuance registry and, if issuance is confirmed, updates the request with the existing provider reference and policy number. It does not create a second issuance. If no insurer issuance result is available, the endpoint requests manual review rather than assuming success or failure.

## State model

- `RECEIVED` → `VALIDATED` after intake validation and premium calculation.
- `VALIDATED` → `SUBMITTED` when an issuance attempt begins.
- `SUBMITTED` → `ISSUED` after synchronous success.
- `SUBMITTED` → `FAILED` for the simulated temporary failure; failed requests can be retried.
- `SUBMITTED` → `PENDING_CONFIRMATION` for timeout or delayed callback scenarios.
- `PENDING_CONFIRMATION` → `ISSUED` after a confirming callback or reconciliation.
- `FAILED` → `SUBMITTED` when an explicit retry starts a new insurer attempt.

Every transition is recorded in `policy_state_histories`. Attempts and their outcomes are tracked in `insurer_attempts`.

## Tests

Run the test suite inside the PHP container:

```powershell
docker run --rm -v "${PWD}:/app" -w /app policyflow-php php artisan test
```

The suite covers premium calculation, invalid travel dates, unsupported plans and coverage values, idempotent intake, successful single issuance, timeout reconciliation, retry after temporary failure, delayed callback completion, duplicate success-callback replay, and protection against callbacks overwriting issued policies. Run the command above from the project root before submission and ensure the complete suite passes.

## Reliability decisions and limitations

- Idempotency is enforced by a database unique constraint, not just a preliminary application check.
- A pending/uncertain request cannot be resubmitted; reconciliation first checks the insurer's persisted result.
- Unique constraints and `firstOrCreate` protect the mock-insurer issue registry, and issued policies are not overwritten by callbacks.
- `lockForUpdate` is used around state transitions. Exact row-lock behavior depends on the production database; SQLite-based feature tests do not prove PostgreSQL concurrency behavior.
- The insurer is a deterministic simulator, not a real insurer integration. External callback authentication/signature verification, production secrets management, scheduled retry workers/queues, and load/concurrency testing are outside this assignment implementation.
- Temporary failures are retried by an explicit API request; there is no background retry scheduler.
