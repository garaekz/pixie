# Pixie

Pixie is a Laravel and Inertia/Vue assessment app for submitting image generations through the Pixelforge API. It keeps provider work asynchronous, reserves customer credits before submission, and settles the final charge from the usage and rates reported for that generation.

Pixelforge is a fictional provider for the assessment. Its images are abstract placeholders; neither generations nor credit purchases involve real payments.

## Local setup

Requirements: PHP 8.5, Composer, Node.js 22 LTS (22.18 or later), PostgreSQL, and a queue worker. The project currently uses Laravel 13, Inertia, Vue, Pest, and Wayfinder.

```sh
# Copy .env.example to .env if it does not exist, then configure APP_URL,
# PostgreSQL, and Pixelforge before continuing.
composer run setup
```

Create the PostgreSQL database named by `DB_DATABASE` and configure its credentials before running setup. `composer run setup` installs PHP and JavaScript dependencies, creates `.env` from `.env.example` when needed, generates the application key, migrates the database, and builds the frontend.

When Flock is running, use its `.test` site and do not run `php artisan serve` or `composer run dev`. Flock supplies the local PHP/runtime versions and services. Start the frontend and workers as needed:

```sh
npm run dev
php artisan queue:work
php artisan schedule:work
```

Without Flock, `composer run dev` starts the normal Laravel development stack. Set `APP_URL` to the URL that actually serves the app.

## Pixelforge configuration

Set these values in `.env` (never commit real credentials):

```dotenv
PF_BASE_URL=https://pixelforge.promptedit.dev/v1
PF_API_KEY=your-current-api-key
PF_WEBHOOK_SECRET=your-current-webhook-secret
```

Configure the key's webhook URL as:

```text
https://your-host.example/webhooks/pixelforge
```

The endpoint verifies the raw request body with the Pixelforge signature, requires a fresh timestamp within five minutes, and records event IDs so duplicate deliveries are harmless. It accepts signed ping events, handles duplicate and out-of-order generation events, and uses polling/reconciliation when a submission response is ambiguous. During local development, expose the app with a tunnel and use the resulting public URL in the Pixelforge dashboard.

## Credits and pricing

Pixie stores money as integer picodollars: `$1 = 1,000,000,000,000` units. Calculations use exact decimal arithmetic rather than floating point. Customer charges apply a 25% markup to the provider cost.

Generation creation obtains the current provider pricing catalog and reserves its quoted maximum hold. The UI also shows a midpoint estimate and maximum hold. After completion, the actual provider token usage and rates determine the final charge. Money conversion and the 25% markup round half up at the picodollar boundary. Integer units preserve fractional-cent costs without floating-point drift. A provider rate increase can make the actual charge exceed the hold and produce a negative balance under the current financial policy; later generations are then blocked until credits are added. Failed generations are released without a customer charge. Repeated purchase and generation request IDs are idempotent, and each settled generation creates at most one ledger entry.

The Buy credits action adds exactly `$5` to the wallet; no payment is taken. New accounts start with a zero balance.

If submission is uncertain, Pixie preserves the reservation, retries with bounded backoff, and supports a manual recovery command. A completed generation cannot be charged twice.

## Key rotation

The active and previous credentials can coexist during a drain window:

```dotenv
PF_API_KEY=the-new-key
PF_WEBHOOK_SECRET=the-new-secret
PF_PREVIOUS_API_KEY=the-old-key
PF_PREVIOUS_WEBHOOK_SECRET=the-old-secret
```

Pixie supports one previous credential pair. Keep it configured and valid until all in-flight generations created with the original key finish and their webhook deliveries are no longer expected. Webhook verification accepts both configured secrets and associates the event with the matching provider-key hash. Provider API calls continue using the original key recorded on each generation; new generations use the active key. After changing Cloud environment values, redeploy so the application and workers refresh their configuration. Remove the previous values only after the old-key backlog and webhook deliveries have settled.

## Admin report

Grant an account admin access with:

```sh
php artisan pixie:admin user@example.com
```

The admin report is scoped to the active provider key and groups settled generations by UTC completion date and user. It reports local provider cost, customer spend, profit, generation count, and the provider usage comparison for the selected date range.

## Operations

The scheduler dispatches due reconciliations every minute. The queue worker must run for generation submissions and retries:

```sh
php artisan pixelforge:reconcile
php artisan pixelforge:retry GENERATION_UUID
php artisan queue:work
```

Use a database or Redis queue consistently across web, worker, and scheduler processes. If changing queue backends, drain the existing queue before switching workers; for example, a database-backed one-off drain is `php artisan queue:work database --queue=default --stop-when-empty`. This runs the jobs before removing them from the queue. Before rotating credentials, drain or deliberately reconcile the old-key backlog. A small Cloud setup needs a worker and scheduler alongside the web process; keep them in the same deployment environment and region where applicable.

Email verification is enabled through the existing Fortify flow. The example environment logs mail by default. SMTP delivery works with the included mailer; API-based providers may require their SDK.

## UI

The dashboard shows the latest six generations matching the selected status filter, without pagination. The Generations page provides the full history with twelve records per page and status filtering. Each card shows status, image, size, quality, relative time, and a final-cost badge (or an animated pending-cost indicator before the real charge lands); the detail page includes the prompt, settings, timestamps, usage, reservation, and charge metadata.

## Architecture

- Generation, billing, webhook, and report controllers coordinate HTTP only; business operations live in Actions.
- Form Requests validate HTTP input and provider envelopes at trust boundaries.
- PHP enums represent generation status, submission state, size, quality, event type, and ledger type.
- Signed webhook processing is separate from provider polling and from the shared generation lifecycle settlement logic.
- Eloquent models and database transactions protect wallet balances, reservations, and ledger records.

## Verification

Useful local checks are:

```sh
npm run check
npm run types:check
php vendor/bin/phpstan analyse
php artisan test --compact
php vendor/bin/pint --dirty --format agent
```

The test suite uses Pest and HTTP fakes for Pixelforge interactions. It covers validation, pricing math, idempotency, lifecycle settlement, signed webhooks, retries, wallet behavior, browsing, and admin reporting. The GitHub Actions workflow installs against SQLite and creates `database/database.sqlite`; PHPUnit then runs the Pest suite with an in-memory SQLite database, synchronous queues, and array-backed sessions/cache. Local PostgreSQL remains the deployment-shaped setup. Provider calls are HTTP-faked in tests, and concurrency behavior is covered by transactional/unit-style scenarios rather than real worker contention.
