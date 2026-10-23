# AI Usage Disclosure — PolicyFlow

## Tools used

- **ChatGPT:** Used as a coding and learning assistant while implementing the Laravel API, database-backed state tracking, mock-insurer scenarios, tests, and project documentation.
- **Docker:** Used to run the PHP/Laravel application and PostgreSQL locally.
- **Laravel automated tests:** Used to verify endpoint behavior and regression safety after implementation changes.
- **Git:** Used to version the project and record implementation changes.

## Concrete assistance received

ChatGPT helped break the assignment into implementation steps, explain Laravel routing/controllers/models/migrations, plan the premium-calculation logic, and implement request validation and duplicate-intake idempotency. It also helped design state history and insurer-attempt records, mock-insurer behavior, timeout reconciliation, callback idempotency, and feature tests. Documentation for setup, endpoints, reliability decisions, and limitations was also drafted with assistance.

## Incorrect or incomplete suggestion and correction

An early version of the duplicate-intake exception handling checked only the PostgreSQL unique-violation SQLSTATE. The feature test suite used SQLite, so the duplicate-intake test exposed that this was not portable across database drivers. The handler was updated to recognize the relevant unique-constraint error signatures for PostgreSQL, SQLite, and MySQL, then the duplicate-intake test was rerun as part of the suite.

This correction is documented to show that generated suggestions were tested against actual application behavior rather than accepted without verification.

## Verification performed

The following were run locally:

- Database migrations completed successfully against the development PostgreSQL container.
- Manual API checks covered policy intake, duplicate intake, successful issuance, request detail/history, filtering/pagination, timeout reconciliation, and duplicate-callback handling.
- Automated test command:

  ```powershell
  docker run --rm -v "${PWD}:/app" -w /app policyflow-php php artisan test
  ```

- A prior full-suite run passed **10 tests and 55 assertions**. After that run, an explicit `DUPLICATE_CALLBACK` submit scenario and a test that replays the identical success callback were added. Rerun the test command after applying those final files and record the resulting test/ assertion count before submission. The suite covers valid intake and premium calculation, invalid trip dates, idempotent duplicate intake, single successful issuance, timeout reconciliation without re-issuance, retry after temporary insurer failure, delayed callback completion, duplicate callback replay, and protection for an already-issued policy.

## Limitations and remaining production considerations

The mock insurer is local and deterministic; it does not contact an external insurer. The assignment implementation does not include production callback signature verification, a queue/scheduled retry worker, load or concurrency testing, or deployment-grade secrets management. These should be addressed before treating the service as production-ready.
