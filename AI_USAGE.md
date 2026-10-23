# AI Usage Disclosure — PolicyFlow

## 1. AI Tools Used

- **ChatGPT:** Used as a development assistant for project setup, implementation guidance, debugging, test development, and documentation.
- **Docker:** Used to run the PHP application environment and PostgreSQL database.
- **Laravel testing tools:** Used to execute automated tests and verify application behavior.

## 2. Areas Where AI Assisted

ChatGPT assisted with:

- Setting up the Laravel application and Docker-based development environment.
- Designing database migrations and Eloquent model relationships for policy requests, state histories, insurer attempts, and mock insurer records.
- Implementing premium calculation using plan rates, inclusive trip duration, age multipliers, destination multipliers, and coverage multipliers.
- Implementing policy intake, duplicate-request idempotency, policy submission, callback handling, and reconciliation.
- Developing feature tests for validation, duplicate intake, successful issuance, and timeout reconciliation.
- Debugging database-related test failures and preparing project documentation.

## 3. Example of an Incorrect or Incomplete Suggestion

An initial duplicate-request error handler checked only PostgreSQL's unique-constraint SQLSTATE (`23505`). During automated testing, the application used SQLite, and the duplicate-intake test failed because SQLite reported the constraint violation differently.

The handler was revised to recognize the relevant PostgreSQL and SQLite error codes, as well as MySQL's duplicate-key error code. The duplicate-intake test was then rerun to verify that the first request creates a policy request and the repeated request returns the existing record instead of creating another one.

This demonstrated the importance of verifying AI-assisted code against the actual runtime and test environment.

## 4. Verification Performed

The following automated test suite passed:

- Valid intake calculates the premium and produces the `VALIDATED` status.
- Invalid trip dates are rejected.
- Duplicate intake requests are handled idempotently.
- Successful submission issues exactly one policy, and repeated submission does not issue another.
- A timeout after insurer issuance can be reconciled without issuing another policy.

**Recorded test result:** 7 tests passed, with 31 assertions.

Manual API checks were also performed for policy intake, duplicate requests, successful submission, policy details, listing and pagination, duplicate callbacks, and timeout reconciliation.

## 5. Human Review and Responsibility

AI-generated suggestions were reviewed and tested during implementation. Code was adjusted when actual test results exposed issues. Database migrations, API responses, state transitions, and reconciliation behavior were checked through automated tests or manual API requests.

The developer remains responsible for the final implementation, correctness, security, and any limitations in the submitted solution.

## 6. Limitations

The implemented mock-insurer scenarios and reliability behavior should be assessed against the actual code and test results. Automated tests do not establish production readiness, and additional failure-injection, concurrency, security, and integration testing would be appropriate before production deployment.

