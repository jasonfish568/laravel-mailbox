# Resend Inbound Driver Design

Date: 2026-07-28

## Summary

Add a first-party Resend inbound driver to `beyondcode/laravel-mailbox`.
The driver will verify Resend's Svix-signed `email.received` webhook, queue
processing on a configurable Laravel queue connection, retrieve the received
email's raw MIME through the Resend Receiving API, and pass that MIME to the
package's configured `InboundEmail` model.

The implementation targets the current `master` compatibility matrix:
PHP 8.1 and Laravel 10 through 13. It does not target or preserve source
compatibility with laravel-mailbox 3.1 or Laravel 9.

## Goals

- Support `MAILBOX_DRIVER=resend`.
- Verify the exact raw webhook body using Resend's Svix headers and signing
  secret before trusting any payload field.
- Retrieve the complete raw MIME, including attachments, instead of
  reconstructing a message from Resend's parsed HTML and text fields.
- Support zero-worker local use through Laravel's `sync` queue connection.
- Support bursty and high-volume production use through a configurable
  asynchronous queue connection and per-second API rate limiting.
- Preserve the existing configured-model path through
  `InboundEmail::fromMessage()` and `Mailbox::callMailboxes()`.
- Cover the feature with deterministic tests that make no real network calls.
- Document setup, operational limits, retry behavior, and at-least-once
  delivery semantics.

## Non-goals

- Supporting laravel-mailbox 3.1, Laravel 9, or PHP 8.0.
- Adding the official Resend PHP SDK or the Svix PHP SDK.
- Reconstructing MIME from Resend's parsed response fields.
- Adding a new database table or persistent webhook-event ledger.
- Providing exactly-once mailbox execution.
- Adding generic queue support to the existing Mailgun, Postmark, SendGrid, or
  MailCare drivers.
- Changing the public mailbox routing API.
- Adding a hard maximum MIME size that differs from the existing drivers.

## Dependencies

Add these Illuminate components with the same version matrix already used by
the package:

- `illuminate/http`: `^10.0|^11.0|^12.0|^13.0`
- `illuminate/bus`: `^10.0|^11.0|^12.0|^13.0`
- `illuminate/queue`: `^10.0|^11.0|^12.0|^13.0`
- `illuminate/cache`: `^10.0|^11.0|^12.0|^13.0`

Laravel applications already receive these components through
`laravel/framework`. Declaring them explicitly keeps the package's component
dependencies accurate. No Resend-specific or Svix-specific Composer package
will be added.

## Configuration

Add `resend` to the documented list of supported drivers and add:

```php
'services' => [
    // ...
    'resend' => [
        'api_key' => env('MAILBOX_RESEND_API_KEY'),
        'webhook_secret' => env('MAILBOX_RESEND_WEBHOOK_SECRET'),
        'queue_connection' => env('MAILBOX_RESEND_QUEUE_CONNECTION', 'sync'),
        'rate_limit' => (int) env('MAILBOX_RESEND_RATE_LIMIT', 5),
    ],
],
```

`queue_connection` deliberately defaults to `sync`. This preserves
zero-configuration behavior and propagates processing failures to the webhook
response. Production applications expecting bursts can select `redis`,
`database`, `sqs`, or another configured Laravel queue connection.

`rate_limit` defaults to five Receiving API requests per second because
Resend's default API limit is five requests per second per team. Applications
that share the team-level limit with other workloads can configure a lower
value. Applications with a Resend-approved higher limit can configure a higher
value.

The driver will throw a server error when an API key, webhook secret, queue
connection, or positive rate limit is not configured correctly. A deployment
configuration error must not be presented as an invalid third-party webhook.

## Components

### `Drivers\Resend`

Register:

```text
POST /{mailbox.path}/resend
```

The default endpoint is `POST /laravel-mailbox/resend`. The driver does not use
the package's HTTP basic-authentication middleware because authenticity comes
from the Svix signature.

### `MailboxManager`

Add `createResendDriver()` and import the new driver, matching the existing
driver factory pattern.

### `Http\Requests\ResendRequest`

The request is the inbound security boundary. It will:

1. Read the exact raw body with `getContent()`.
2. require the `svix-id`, `svix-timestamp`, and `svix-signature` headers;
3. call `Support\ResendWebhookSignature` before trusting parsed JSON;
4. parse the JSON payload;
5. expose the signed event type, `svix-id`, and `data.email_id`;
6. require a non-empty string `data.email_id` only for `email.received`.

A correctly signed event with a different type is valid but irrelevant to the
inbound driver. It will be acknowledged with HTTP 204 so a mistakenly broad
Resend webhook subscription does not retry forever.

### `Support\ResendWebhookSignature`

Implement the small symmetric Svix verification surface without a vendor SDK.
The verifier will:

- require a non-empty `whsec_` signing secret;
- Base64-decode the secret suffix strictly;
- require an integer Unix timestamp no more than 300 seconds in the past or
  future;
- build the signed content as
  `{svix-id}.{svix-timestamp}.{raw-body}`;
- calculate a raw HMAC-SHA256 digest and Base64-encode it;
- parse the space-delimited signature header;
- accept the request when any `v1,<signature>` entry matches;
- compare signatures with `hash_equals()`.

The verifier will be isolated from HTTP and queue concerns so fixed vectors and
boundary conditions can be tested directly.

### `Http\Controllers\ResendController`

After request validation:

- return 204 for a signed non-`email.received` event;
- create `Jobs\ProcessResendEmail` with only the signed `email_id` and
  `svix-id`;
- validate the configured queue connection and positive API rate limit;
- dispatch the job through Laravel's `dispatch()` helper;
- return 200 after successful dispatch.

When the configured connection is `sync`, dispatch executes the job in the
request and propagates exceptions as HTTP 5xx. With an asynchronous connection,
HTTP 200 means the job was accepted by Laravel's queue, not that mailbox
processing has completed. Using the `dispatch()` helper also ensures Laravel
acquires the `ShouldBeUnique` lock before pushing the job. If dispatch or
synchronous processing throws, the controller releases that unique lock before
rethrowing. Otherwise a failure after lock acquisition but before successful
queue acceptance could suppress Resend's next webhook retry.

### `Jobs\ProcessResendEmail`

The job will implement `ShouldQueue` and `ShouldBeUnique`.

- `uniqueId()` returns the `svix-id`.
- The unique lock prevents the same delivery from being queued or processed
  concurrently.
- The lock does not promise durable exactly-once behavior after completion.
- The job carries only scalar identifiers; it never serializes the request,
  API key, webhook secret, MIME, or signed download URL.

For asynchronous connections, the job applies
`Queue\Middleware\ResendRateLimited`. The `sync` connection relies on Resend's
webhook backoff instead of releasing a synchronous job.

The job gets a fresh signed raw download URL during each attempt by invoking
`Clients\ResendClient` with the `email_id`. It then:

1. resolves the configured mailbox model;
2. calls `::fromMessage($mime)`;
3. passes the resulting inbound email to `Mailbox::callMailboxes()`.

The job uses a 24-hour `retryUntil()` window instead of a fixed attempt count.
Laravel's queue rate-limit middleware increments a job's attempt count when it
releases the job, so a small hard attempt limit could fail healthy jobs merely
because they waited for rate-limit capacity.

Processing exceptions use this backoff sequence:

```text
5 seconds, 1 minute, 5 minutes, 30 minutes, 2 hours, 5 hours, 10 hours
```

After the final value, subsequent processing failures continue using the
10-hour delay until the 24-hour retry window ends. Rate-limit middleware
releases use short capacity-based delays instead of this exception backoff.
A job timeout of 180 seconds leaves room for a large MIME download and mailbox
parsing while still bounding a stuck worker.

### `Queue\Middleware\ResendRateLimited`

Laravel 10's named rate-limit definition object supports minute windows but
does not expose `Limit::perSecond()`. To preserve the package's Laravel 10
floor, a small package middleware will use the cross-version
`Illuminate\Cache\RateLimiter` API directly.

For each asynchronous job attempt, the middleware will:

1. read and validate the positive integer
   `mailbox.services.resend.rate_limit`;
2. check a package-specific cache key against that maximum;
3. release an over-limit job for `availableIn($key) + 1` seconds;
4. otherwise call `hit($key, 1)` to consume one slot in a one-second window;
5. pass the job to the next middleware or handler.

The middleware resolves `RateLimiter` from Laravel's container inside
`handle()`, so the queued middleware object contains no unserializable cache
service. It deliberately applies only to asynchronous connections because
releasing a job on the `sync` connection cannot defer work.

### `Clients\ResendClient`

The client performs two separate requests.

First, retrieve metadata:

```text
GET https://api.resend.com/emails/receiving/{rawurlencoded-email-id}
Authorization: Bearer {api-key}
User-Agent: beyondcode/laravel-mailbox
```

The host and scheme for this authenticated request are fixed in code. The
request does not follow redirects, uses a five-second connection timeout, and
uses a 15-second total timeout. It must return a successful JSON response with
a non-empty `raw.download_url`.

Second, download the raw MIME:

- require an HTTPS URL;
- do not send the Resend API key or package credentials;
- do not follow redirects;
- use a five-second connection timeout and a 120-second total timeout;
- require a successful response with a non-empty body.

The signed URL is treated as a credential and must not be logged. The entire
MIME becomes a string because the existing configured-model contract accepts
the whole raw message and stores it in a `longText` column when matched.
Queue concurrency and rate limiting bound aggregate pressure; individual
workers still need enough memory for the largest accepted inbound email.

Raw-download transport errors will be wrapped in a sanitized package exception
that does not include the signed URL, its query string, or the response body.
The original URL-bearing exception will not be chained into the new exception,
because Laravel's default failed-job logging may render chained exception
messages.

## Request and Processing Flow

```text
Resend
  -> POST signed metadata webhook
  -> ResendRequest verifies exact raw body
  -> ResendController dispatches ProcessResendEmail
  -> queue connection (sync or asynchronous)
  -> ResendClient calls authenticated Receiving API
  -> ResendClient downloads unauthenticated signed raw MIME URL
  -> configured InboundEmail model::fromMessage(raw MIME)
  -> Mailbox::callMailboxes()
```

The raw download URL is obtained inside the job, never before queueing, so its
short expiration does not make a delayed job unusable.

## HTTP and Failure Semantics

| Condition | Behavior |
| --- | --- |
| Missing Svix header | HTTP 401; do not dispatch |
| Invalid signature | HTTP 401; do not dispatch |
| Timestamp outside the 300-second window | HTTP 401; do not dispatch |
| Malformed JSON | HTTP 400; do not dispatch |
| `email.received` without a valid `data.email_id` | HTTP 400; do not dispatch |
| Valid signed non-`email.received` event | HTTP 204; do not dispatch |
| Invalid package configuration | HTTP 5xx |
| Queue dispatch failure | Release the delivery's unique lock and return HTTP 5xx, allowing Resend to retry |
| Successful asynchronous dispatch | HTTP 200 |
| Successful synchronous processing | HTTP 200 |
| Receiving API, raw download, MIME conversion, or mailbox failure on `sync` | HTTP 5xx, allowing Resend to retry |
| The same failures on an asynchronous queue | Throw from the job and apply queue retry/backoff |

All non-successful Receiving API responses, including 429 and 5xx, are
retriable processing failures. A malformed successful API response is also a
processing failure; silently rebuilding a partial email is not allowed.

## Delivery and Idempotency

Resend webhooks and Laravel queued jobs are at-least-once systems.
`ShouldBeUnique` keyed by `svix-id` prevents concurrent duplicate work, but it
does not create a permanent processed-event ledger. A manual Resend replay or
a failure after a mailbox handler has already produced a side effect can cause
the handler to run again.

Documentation will tell consumers to make side-effecting handlers idempotent,
preferably by using the raw email's stable `Message-Id` as the business
deduplication key. The driver will not alter the MIME or replace its
`Message-Id` with Resend's API identifier.

## Security

- Verify the exact raw body before reading `type` or `data.email_id`.
- Reject secrets that are missing, malformed, or not valid strict Base64 after
  the `whsec_` prefix.
- Bound both old and future timestamps to prevent replay and clock-skew abuse.
- Use constant-time signature comparison.
- Never log the API key, webhook secret, raw signature header, or signed
  download URL.
- Sanitize raw-download exceptions before they can reach failed-job logging.
- Send the Bearer token only to the fixed Resend API origin and disable
  redirects on that authenticated request.
- Require HTTPS for the returned download URL.
- Disable download redirects so a signed URL cannot redirect the client to an
  unexpected destination.
- URL-encode the `email_id` path segment.
- Do not add HTTP basic authentication to the Resend route.

## Testing

All tests use Laravel, bus, queue, cache, and HTTP fakes. No test calls Resend.

### Driver and route tests

- Selecting `resend` creates and registers the driver.
- The configured mailbox path prefixes the Resend endpoint.
- The endpoint accepts POST and does not use basic-authentication middleware.

### Signature tests

- Verify a fixed, independently calculated Svix-compatible vector.
- Reject a one-byte raw-body change.
- Reject a JSON parse-and-reserialize change.
- Accept a matching `v1` entry among multiple signature entries.
- Reject unsupported signature versions without a matching `v1`.
- Reject a wrong secret or signature.
- Reject missing and malformed `whsec_` secrets.
- Reject malformed Base64.
- Reject missing headers.
- Accept timestamps on the permitted boundary.
- Reject old and future timestamps outside the 300-second window.

### Request and controller tests

- Missing or invalid signatures return 401 and dispatch nothing.
- Malformed JSON and missing `email_id` return 400 and dispatch nothing.
- Signed unrelated events return 204 and dispatch nothing.
- A signed received event dispatches the expected scalar identifiers.
- Dispatch uses the configured `sync` or asynchronous connection.
- Invalid queue or non-positive rate-limit configuration returns HTTP 5xx.
- A dispatch exception releases the unique lock and becomes HTTP 5xx.
- The same delivery can be dispatched when Resend retries after that exception.

### Client tests

- The metadata request uses the fixed Resend API origin, URL-encoded email ID,
  Bearer token, and package User-Agent.
- The raw request uses the returned HTTPS URL without Authorization.
- Redirects are disabled for the raw request.
- A raw MIME fixture is returned byte-for-byte.
- Metadata redirects, 401, 404, 429, and 5xx responses throw.
- Raw-download failures throw.
- Missing, empty, malformed, or non-HTTPS `raw.download_url` values throw.
- An empty raw MIME response throws.
- Failure output and exceptions do not expose configured secrets or the signed
  URL.

### Job tests

- The job's unique ID is the `svix-id`.
- The job exposes the approved 24-hour retry window, timeout, and exception
  backoff sequence.
- Asynchronous processing supplies `ResendRateLimited`; `sync` processing
  supplies no queue middleware.
- The custom middleware consumes at most the configured number of cache
  limiter hits in a one-second window and releases excess jobs.
- `sync` exceptions propagate to the request.
- Asynchronous exceptions remain queue failures.
- A realistic MIME fixture with an attachment reaches the configured model and
  exposes the expected headers, body, and attachment after processing.
- The configured custom model is honored.
- `Mailbox::callMailboxes()` is invoked only after both HTTP requests succeed.

Run the complete package test suite under the existing PHP 8.1 through 8.5 and
Laravel 10 through 13 CI matrix.

## Documentation

Update `config/mailbox.php` and `docs/drivers/drivers.md`.

The Resend driver documentation will include:

- required environment variables;
- `MAILBOX_DRIVER=resend`;
- the default `/laravel-mailbox/resend` endpoint;
- selecting only the `email.received` webhook event;
- copying the endpoint-specific Resend webhook signing secret;
- the distinction between the API key and webhook secret;
- why the driver retrieves raw MIME after receiving metadata;
- the default `sync` behavior;
- configuring an asynchronous queue and running workers for high volume;
- the default team-level five-request-per-second API limit and its config;
- retry behavior for sync and asynchronous modes;
- configuring the queue's `retry_after` above the job's 180-second timeout and
  keeping the worker timeout below `retry_after`;
- at-least-once delivery and handler idempotency;
- worker memory considerations for MIME messages containing attachments.

No README example, migration, changelog, or unrelated driver documentation
will be changed.

## Success Criteria

- A valid Resend `email.received` event produces the same configured
  `InboundEmail` behavior as existing raw-MIME drivers, including attachments.
- Forged, stale, future-dated, or malformed webhook requests cannot cause an
  API call or queue dispatch.
- The API key is never sent to the raw download origin.
- Local users can run with the default `sync` connection and no queue worker.
- High-volume users can select an asynchronous queue and cap Receiving API
  calls at their configured per-second limit.
- Temporary API and download failures are retried through Resend in sync mode
  or Laravel's queue in asynchronous mode.
- Tests pass across the repository's supported PHP and Laravel versions.
- The PR remains a single Resend inbound-driver feature with no unrelated
  refactoring.

## References

- [Resend: Receiving Emails](https://resend.com/docs/dashboard/receiving/introduction)
- [Resend: Verify Webhook Requests](https://resend.com/docs/webhooks/verify-webhooks-requests)
- [Resend: Retrieve Received Email](https://resend.com/docs/api-reference/emails/retrieve-received-email)
- [Resend: Webhook Retries and Replays](https://resend.com/docs/webhooks/retries-and-replays)
- [Resend: API Usage Limits](https://resend.com/docs/api-reference/rate-limit)
