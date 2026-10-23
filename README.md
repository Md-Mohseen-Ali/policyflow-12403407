
# PolicyFlow
## Fault-Tolerant Insurance Issuance & Reconciliation Engine

PolicyFlow is a REST API built with Laravel and PostgreSQL for receiving travel insurance requests, calculating premiums, issuing policies through a mock insurer, and reconciling uncertain insurer outcomes without issuing duplicate policies.

## 1. Technology Stack

- PHP 8.4
- Laravel 13
- PostgreSQL 16
- Docker
- PHPUnit / Laravel automated tests

## 2. Architecture

The application uses a layered Laravel architecture:

- **Routes:** Define the REST API endpoints.
- **Controllers:** Validate requests, coordinate business operations, and return HTTP responses.
- **PremiumCalculator:** Calculates the premium using the configured plan rate and multipliers.
- **Eloquent models:** Represent policy requests, insurer attempts, state history, and mock insurer issuance records.
- **Database migrations:** Define the schema, relationships, indexes, and uniqueness constraints.

### Main database tables

- `policy_requests`: Stores the request, calculated premium snapshot, current status, provider reference, and policy number.
- `policy_state_histories`: Records status transitions and associated reasons or metadata.
- `insurer_attempts`: Records submission attempts and their outcomes.
- `mock_insurer_issues`: Stores the mock insurer's authoritative issuance result.

## 3. Prerequisites

Install Docker Desktop and Git.

The project runs PHP and PostgreSQL in Docker containers. Composer dependencies are included in the Laravel project.

## 4. Local Setup

Start the PostgreSQL container if it is not already running:

```powershell
docker start policyflow-db
```

From the project root, build the PHP image if necessary:

```powershell
docker build -t policyflow-php .
```

Configure the database values in `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=host.docker.internal
DB_PORT=5433
DB_DATABASE=policyflow
DB_USERNAME=policyflow_user
DB_PASSWORD=policyflow_dev_password
```

Run migrations:

```powershell
docker run --rm -v "${PWD}:/app" -w /app policyflow-php php artisan migrate
```

Start the API server in a terminal:

```powershell
docker run --rm -it -p 8000:8000 -v "${PWD}:/app" -w /app policyflow-php php artisan serve --host=0.0.0.0
```

The API is available at `http://localhost:8000`.

> These database credentials are development credentials. Replace them with secure values before using the application outside a local assignment environment.

## 5. API Endpoints

All endpoints use JSON request and response bodies.

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/api/policy-requests` | Create and validate a policy request |
| GET | `/api/policy-requests/{id}` | Retrieve policy details, history, and attempts |
| POST | `/api/policy-requests/{id}/submit` | Submit a validated request to the mock insurer |
| POST | `/api/mock-insurer/callback` | Process an insurer callback |
| POST | `/api/policy-requests/{id}/reconcile` | Reconcile an uncertain issuance result |
| GET | `/api/policy-requests` | List requests with optional filtering and pagination |

### Create a policy request

Example request:

```json
{
  "client_request_id": "REQ-1001",
  "agent_id": "AGENT-101",
  "customer_name": "Test Customer",
  "date_of_birth": "1998-04-10",
  "destination": "DOMESTIC",
  "trip_start": "2026-12-01",
  "trip_end": "2026-12-03",
  "plan_code": "BASIC",
  "sum_insured": 100000
}
```

Example command:

```powershell
Invoke-RestMethod -Method Post `
  -Uri "http://localhost:8000/api/policy-requests" `
  -ContentType "application/json" `
  -Body (Get-Content .\request.json -Raw)
```

A new request returns HTTP `201`. Replaying the same `agent_id` and `client_request_id` returns HTTP `200` with `idempotent_replay: true`. Invalid input returns HTTP `422`.

### Submit a policy

```json
{
  "scenario": "SUCCESS"
}
```

Send this body to `POST /api/policy-requests/{id}/submit`.

To simulate an insurer issuing a policy while the response is lost:

```json
{
  "scenario": "TIMEOUT_AFTER_ISSUE"
}
```

This scenario returns HTTP `202` and marks the request `PENDING_CONFIRMATION`. Reconcile it using:

`POST /api/policy-requests/{id}/reconcile`

The application checks the mock insurer's issuance record instead of blindly submitting the request again.

### Retrieve and list policies

Retrieve a policy:

`GET /api/policy-requests/1`

Filter by status and paginate:

`GET /api/policy-requests?status=ISSUED&per_page=5&page=1`

Supported statuses include `RECEIVED`, `VALIDATED`, `SUBMITTED`, `PENDING_CONFIRMATION`, `ISSUED`, `FAILED`, and `NEEDS_RECONCILIATION`.

## 6. Premium Calculation

The trip duration includes both the departure and return dates.

| Plan | Base rate per day |
|---|---:|
| BASIC | ₹12 |
| PLUS | ₹20 |
| PREMIUM | ₹30 |

Age multiplier at the start of the trip:

| Age | Multiplier |
|---|---:|
| 0–17 | 1.10 |
| 18–60 | 1.00 |
| 61–70 | 1.50 |
| 71+ | 2.00 |

Destination multipliers:

- DOMESTIC: 1.00
- INTERNATIONAL: 1.80

Coverage multipliers:

- ₹100,000: 1.00
- ₹300,000: 1.25
- ₹500,000: 1.50

Formula:

`Premium = Base daily rate × Inclusive trip days × Age multiplier × Destination multiplier × Coverage multiplier`

The calculator uses integer-cent arithmetic for its calculation and returns a two-decimal premium amount. The calculated amount and multipliers are stored with the request as a snapshot.

## 7. Reliability and Consistency

### Duplicate intake

A database uniqueness constraint on `(agent_id, client_request_id)` prevents duplicate requests. Replayed requests return the existing record.

### Duplicate issuance protection

A policy can be submitted only from the `VALIDATED` state. Already-issued policies are returned without issuing again. A unique policy number and a unique mock insurer issuance record provide additional database safeguards.

### Uncertain insurer outcomes

A timeout after insurer issuance changes the request to `PENDING_CONFIRMATION`. Reconciliation checks the insurer's recorded result and updates the policy using that existing result.

### Audit trail

State history records transitions and reasons. Insurer attempts record the operation, outcome, provider reference, and timestamps.

### Callbacks

The mock insurer callback endpoint checks the provider reference and policy state. Duplicate callbacks for an already-issued policy do not overwrite its issued status.

## 8. Running Tests

Run the complete automated test suite:

```powershell
docker run --rm -v "${PWD}:/app" -w /app policyflow-php php artisan test
```

The current test suite covers premium calculation, invalid trip dates, duplicate-intake idempotency, successful issuance, and timeout reconciliation.

## 9. Assumptions and Limitations

- The insurer is simulated using local database records; no real insurance provider is integrated.
- The current timeout scenario simulates a lost response after the mock insurer has recorded issuance.
- This is an assignment implementation, not a production-ready insurance platform.
- Authentication, authorization, secrets management, external-provider security, and operational monitoring require additional production hardening.
- Real-world distributed execution would require further safeguards around network failures, concurrent requests, callback authentication, and reconciliation scheduling.

## 10. Project Status

The automated test suite currently passes seven tests with 31 assertions. Additional reliability scenarios and end-to-end behavior should be verified against the assignment's full acceptance criteria before submission.
