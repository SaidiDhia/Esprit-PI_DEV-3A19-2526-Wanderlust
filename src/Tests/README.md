This folder contains Symfony PHPUnit test scaffolding created under `src/Tests`.

Environment variables used by these tests:

- `TEST_API_BASE_URL` : base URL used by tests when module-specific variables are not set (default: http://127.0.0.1:8000)
- `BOOKING_API_URL` : booking service base URL
- `EVENTS_API_URL` : events service base URL
- `BLOG_API_URL` : blog service base URL
- `USER_API_URL` : user service base URL
- `MESSAGING_API_URL` : messaging service base URL
- `MARKETPLACE_API_URL` : marketplace service base URL
- `TEST_API_ADMIN_TOKEN` : bearer token used to access admin endpoints for activity logs
- `ANOMALY_API_URL` / `BOT_API_URL` / `TOXICITY_API_URL` : external model endpoints
- `REDIS_URL` : host:port for Redis (default `127.0.0.1:6379`)

How tests behave:
- Tests attempt to call the configured endpoints. If an endpoint or required env var is missing or unreachable the related test will be marked skipped.
- The `UserTest` includes checks for admin activity logs, calls to anomaly/bot/toxicity model endpoints, and a small Redis connectivity/risk-score sanity check.

Run tests:

Use the project test runner (Symfony PHPUnit) from the project root:

```bash
php bin/phpunit
```

Notes:
- Tests are intentionally conservative: they skip when external services are not available to avoid failing the test run in environments without those services.
- If you want tests to run against real services, set the environment variables above to point at the running services before running PHPUnit.
