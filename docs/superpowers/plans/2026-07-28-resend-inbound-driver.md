# Resend Inbound Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a secure, queue-aware Resend inbound driver that verifies Svix webhooks, retrieves the complete raw MIME from Resend, and processes it through the existing mailbox model and router.

**Architecture:** A Resend route validates the exact raw webhook body before dispatching a unique queue job. The job applies a Laravel 10-compatible one-second cache rate limiter on asynchronous connections, retrieves a fresh signed raw-MIME URL through a small Laravel HTTP client, and passes the MIME to the configured inbound model and mailbox router.

**Tech Stack:** PHP 8.1+, Laravel/Illuminate 10–13, Orchestra Testbench, PHPUnit attributes, Laravel HTTP/Bus/Queue/Cache, zbateson/mail-mime-parser.

## Global Constraints

- Work only in `/Users/wangjiangyu/backend-projects/laravel-mailbox`; never touch `/private/var/www/auto-compliance`.
- Develop on `codex/resend-inbound-driver` and push only to `origin` (`jasonfish568/laravel-mailbox`); `upstream` is read-only.
- Support PHP `^8.1` and Illuminate `^10.0|^11.0|^12.0|^13.0`.
- Do not add `resend/resend-php`, `svix/svix`, or any other vendor SDK.
- Do not add a migration, webhook ledger, exactly-once claim, or queue behavior to existing drivers.
- Register `POST /{mailbox.path}/resend`; the default endpoint is `/laravel-mailbox/resend`.
- Verify `svix-id`, `svix-timestamp`, and `svix-signature` against the exact raw body with a 300-second past/future tolerance before trusting payload fields.
- Use `MAILBOX_RESEND_API_KEY`, `MAILBOX_RESEND_WEBHOOK_SECRET`, `MAILBOX_RESEND_QUEUE_CONNECTION`, and `MAILBOX_RESEND_RATE_LIMIT`.
- Default the queue connection to `sync` and the team-level API rate limit to five requests per second.
- Retrieve the raw MIME inside the job, not before dispatch; never reconstruct MIME from parsed HTML/text.
- Send the Bearer token only to the fixed `https://api.resend.com` origin and disable redirects on that request.
- Require HTTPS for the signed raw URL, disable redirects, and never leak the URL or credentials through exceptions or logs.
- Preserve at-least-once semantics and document handler idempotency.
- Follow existing PSR-2-style formatting and PHPUnit `#[Test]` attributes.
- Use TDD for every task: failing test, observed failure, minimal implementation, passing focused tests, then commit.

## File Map

### Create

- `src/Drivers/Resend.php` — register the Resend webhook route.
- `src/Support/ResendWebhookSignature.php` — verify the symmetric Svix signature and timestamp.
- `src/Clients/ResendClient.php` — call the Receiving API and download sanitized raw MIME.
- `src/Queue/Middleware/ResendRateLimited.php` — enforce the configured one-second API window on asynchronous jobs.
- `src/Jobs/ProcessResendEmail.php` — unique, retryable job that converts MIME and invokes mailboxes.
- `src/Http/Requests/ResendRequest.php` — authenticate the raw webhook and validate its payload.
- `src/Http/Controllers/ResendController.php` — acknowledge unrelated events or dispatch the processing job.
- `tests/Drivers/ResendTest.php` — driver factory and route tests.
- `tests/Support/ResendWebhookSignatureTest.php` — fixed-vector and boundary tests.
- `tests/Clients/ResendClientTest.php` — authenticated metadata and unauthenticated raw-download tests.
- `tests/Queue/Middleware/ResendRateLimitedTest.php` — one-second limiter and invalid-config tests.
- `tests/Jobs/ProcessResendEmailTest.php` — job metadata, middleware, model, and mailbox tests.
- `tests/Concerns/SignsResendWebhooks.php` — deterministic signed-request test helper.
- `tests/Controllers/ResendTest.php` — request status, dispatch, sync integration, and attachment tests.
- `tests/Fixtures/resend-email.eml` — multipart MIME with a text attachment.

### Modify

- `composer.json` — explicitly require Illuminate HTTP, Bus, Queue, and Cache components.
- `config/mailbox.php` — expose the Resend driver, credentials, queue connection, and rate.
- `src/MailboxManager.php` — add `createResendDriver()`.
- `docs/drivers/drivers.md` — document setup, queues, limits, retries, and idempotency.

---

### Task 1: Package Wiring and Resend Route

**Files:**
- Create: `src/Drivers/Resend.php`
- Create: `tests/Drivers/ResendTest.php`
- Modify: `composer.json:18-27`
- Modify: `config/mailbox.php:5-11,57-66`
- Modify: `src/MailboxManager.php:5-10,39-47`

**Interfaces:**
- Consumes: Existing `DriverInterface::register()` and `MailboxManager` driver factory convention.
- Produces: `BeyondCode\Mailbox\Drivers\Resend`, `MailboxManager::createResendDriver()`, the `/laravel-mailbox/resend` route, and `mailbox.services.resend.*` configuration used by all later tasks.

- [ ] **Step 1: Install the baseline dependencies and prove the current suite is green**

Run:

```bash
composer install --no-interaction
composer test
```

Expected: Composer installs into ignored `vendor/` and the existing suite passes before feature changes.

- [ ] **Step 2: Write the failing driver and route tests**

Create `tests/Drivers/ResendTest.php`:

```php
<?php

namespace BeyondCode\Mailbox\Tests\Drivers;

use BeyondCode\Mailbox\Drivers\Resend;
use BeyondCode\Mailbox\Http\Controllers\ResendController;
use BeyondCode\Mailbox\MailboxManager;
use BeyondCode\Mailbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ResendTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']['mailbox.driver'] = 'resend';
    }

    #[Test]
    public function it_creates_the_resend_driver()
    {
        $this->assertInstanceOf(
            Resend::class,
            $this->app->make(MailboxManager::class)->driver('resend')
        );
    }

    #[Test]
    public function it_registers_the_resend_webhook_route()
    {
        $route = collect($this->app['router']->getRoutes())->first(function ($route) {
            return $route->uri() === 'laravel-mailbox/resend';
        });

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertSame(ResendController::class, $route->getAction('uses'));
        $this->assertNotContains('laravel-mailbox', $route->gatherMiddleware());
    }

    #[Test]
    public function it_uses_the_configured_mailbox_path()
    {
        config(['mailbox.path' => 'custom-mailbox']);

        (new Resend)->register();

        $route = collect($this->app['router']->getRoutes())->first(function ($route) {
            return $route->uri() === 'custom-mailbox/resend';
        });

        $this->assertNotNull($route);
    }
}
```

- [ ] **Step 3: Run the test and observe the missing-driver failure**

Run:

```bash
vendor/bin/phpunit tests/Drivers/ResendTest.php
```

Expected: FAIL during package boot or driver resolution because `createResendDriver()` and `Drivers\Resend` do not exist.

- [ ] **Step 4: Add the explicit component requirements**

Extend `composer.json` `require` in sorted order:

```json
"illuminate/bus": "^10.0|^11.0|^12.0|^13.0",
"illuminate/cache": "^10.0|^11.0|^12.0|^13.0",
"illuminate/container": "^10.0|^11.0|^12.0|^13.0",
"illuminate/database": "^10.0|^11.0|^12.0|^13.0",
"illuminate/http": "^10.0|^11.0|^12.0|^13.0",
"illuminate/log": "^10.0|^11.0|^12.0|^13.0",
"illuminate/queue": "^10.0|^11.0|^12.0|^13.0",
"illuminate/routing": "^10.0|^11.0|^12.0|^13.0",
"illuminate/support": "^10.0|^11.0|^12.0|^13.0"
```

Run:

```bash
composer update illuminate/bus illuminate/cache illuminate/http illuminate/queue --with-all-dependencies --no-interaction
composer validate --strict
```

Expected: dependency resolution succeeds for the current environment; `composer.lock` remains ignored.

- [ ] **Step 5: Add the configuration and driver factory**

Add this service configuration to `config/mailbox.php` and include `"resend"` in the supported-driver comment:

```php
'resend' => [
    'api_key' => env('MAILBOX_RESEND_API_KEY'),
    'webhook_secret' => env('MAILBOX_RESEND_WEBHOOK_SECRET'),
    'queue_connection' => env('MAILBOX_RESEND_QUEUE_CONNECTION', 'sync'),
    'rate_limit' => (int) env('MAILBOX_RESEND_RATE_LIMIT', 5),
],
```

Import the driver and add to `MailboxManager`:

```php
use BeyondCode\Mailbox\Drivers\Resend;

public function createResendDriver()
{
    return new Resend;
}
```

- [ ] **Step 6: Implement the route-only driver**

Create `src/Drivers/Resend.php`:

```php
<?php

namespace BeyondCode\Mailbox\Drivers;

use BeyondCode\Mailbox\Http\Controllers\ResendController;
use Illuminate\Support\Facades\Route;

class Resend implements DriverInterface
{
    public function register()
    {
        Route::prefix(config('mailbox.path'))->group(function () {
            Route::post('/resend', ResendController::class);
        });
    }
}
```

- [ ] **Step 7: Run focused and package tests**

Run:

```bash
vendor/bin/phpunit tests/Drivers/ResendTest.php
composer test
```

Expected: both pass. Referencing `ResendController::class` is safe before its file is created because the route stores the class name as a string and this task does not invoke the endpoint.

- [ ] **Step 8: Commit the wiring**

```bash
git add composer.json config/mailbox.php src/Drivers/Resend.php src/MailboxManager.php tests/Drivers/ResendTest.php
git commit -m "feat: register Resend inbound driver"
```

---

### Task 2: Svix Webhook Signature Verification

**Files:**
- Create: `src/Support/ResendWebhookSignature.php`
- Create: `tests/Support/ResendWebhookSignatureTest.php`

**Interfaces:**
- Consumes: `Carbon\Carbon::now()` for deterministic clock control.
- Produces: `ResendWebhookSignature::verify(string $payload, ?string $id, ?string $timestamp, ?string $signatures, ?string $secret): bool` for `ResendRequest`.

- [ ] **Step 1: Write all fixed-vector, raw-body, and edge tests**

Create `tests/Support/ResendWebhookSignatureTest.php` with the independently
calculated signature `d36LOzwlBe0bKqKR8+qDaX65GyQsZLnNBqd0FeDwie8=`:

```php
<?php

namespace BeyondCode\Mailbox\Tests\Support;

use BeyondCode\Mailbox\Support\ResendWebhookSignature;
use BeyondCode\Mailbox\Tests\TestCase;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ResendWebhookSignatureTest extends TestCase
{
    private const PAYLOAD = '{"type":"email.received","data":{"email_id":"email_123"}}';
    private const SECRET = 'whsec_dGVzdC1zZWNyZXQ=';
    private const TIMESTAMP = '1700000000';
    private const SIGNATURE = 'd36LOzwlBe0bKqKR8+qDaX65GyQsZLnNBqd0FeDwie8=';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp((int) self::TIMESTAMP));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_verifies_a_fixed_svix_signature()
    {
        $this->assertTrue((new ResendWebhookSignature)->verify(
            self::PAYLOAD,
            'msg_test',
            self::TIMESTAMP,
            'v1,'.self::SIGNATURE,
            self::SECRET
        ));
    }

    #[Test]
    public function it_rejects_any_raw_body_change()
    {
        $this->assertFalse((new ResendWebhookSignature)->verify(
            self::PAYLOAD." ",
            'msg_test',
            self::TIMESTAMP,
            'v1,'.self::SIGNATURE,
            self::SECRET
        ));
    }

    #[Test]
    public function it_rejects_json_that_was_parsed_and_reserialized()
    {
        $reserialized = json_encode(
            json_decode(self::PAYLOAD, true),
            JSON_PRETTY_PRINT
        );

        $this->assertFalse((new ResendWebhookSignature)->verify(
            $reserialized,
            'msg_test',
            self::TIMESTAMP,
            'v1,'.self::SIGNATURE,
            self::SECRET
        ));
    }

    #[Test]
    public function it_accepts_any_matching_v1_signature()
    {
        $header = 'v1,invalid v2,invalid v1,'.self::SIGNATURE;

        $this->assertTrue((new ResendWebhookSignature)->verify(
            self::PAYLOAD,
            'msg_test',
            self::TIMESTAMP,
            $header,
            self::SECRET
        ));
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function it_rejects_invalid_signature_inputs(
        ?string $id,
        ?string $timestamp,
        ?string $signature,
        ?string $secret
    ) {
        $this->assertFalse((new ResendWebhookSignature)->verify(
            self::PAYLOAD,
            $id,
            $timestamp,
            $signature,
            $secret
        ));
    }

    public static function invalidInputs(): array
    {
        return [
            'missing id' => [null, self::TIMESTAMP, 'v1,'.self::SIGNATURE, self::SECRET],
            'missing timestamp' => ['msg_test', null, 'v1,'.self::SIGNATURE, self::SECRET],
            'missing signature' => ['msg_test', self::TIMESTAMP, null, self::SECRET],
            'missing secret' => ['msg_test', self::TIMESTAMP, 'v1,'.self::SIGNATURE, null],
            'wrong prefix' => ['msg_test', self::TIMESTAMP, 'v1,'.self::SIGNATURE, 'secret'],
            'bad base64' => ['msg_test', self::TIMESTAMP, 'v1,'.self::SIGNATURE, 'whsec_%%%'],
            'empty decoded secret' => ['msg_test', self::TIMESTAMP, 'v1,'.self::SIGNATURE, 'whsec_'],
            'wrong secret' => ['msg_test', self::TIMESTAMP, 'v1,'.self::SIGNATURE, 'whsec_b3RoZXItc2VjcmV0'],
            'malformed timestamp' => ['msg_test', 'not-an-integer', 'v1,'.self::SIGNATURE, self::SECRET],
            'wrong signature' => ['msg_test', self::TIMESTAMP, 'v1,invalid', self::SECRET],
            'unsupported version' => ['msg_test', self::TIMESTAMP, 'v2,'.self::SIGNATURE, self::SECRET],
        ];
    }

    #[Test]
    public function it_enforces_both_timestamp_boundaries()
    {
        $verifier = new ResendWebhookSignature;
        $pastBoundary = (string) (now()->timestamp - 300);
        $futureBoundary = (string) (now()->timestamp + 300);
        $tooOld = (string) (now()->timestamp - 301);
        $tooFarInFuture = (string) (now()->timestamp + 301);

        $this->assertTrue($verifier->verify(
            self::PAYLOAD,
            'msg_test',
            $pastBoundary,
            'v1,'.$this->signature($pastBoundary),
            self::SECRET
        ));
        $this->assertTrue($verifier->verify(
            self::PAYLOAD,
            'msg_test',
            $futureBoundary,
            'v1,'.$this->signature($futureBoundary),
            self::SECRET
        ));
        $this->assertFalse($verifier->verify(
            self::PAYLOAD,
            'msg_test',
            $tooOld,
            'v1,'.$this->signature($tooOld),
            self::SECRET
        ));
        $this->assertFalse($verifier->verify(
            self::PAYLOAD,
            'msg_test',
            $tooFarInFuture,
            'v1,'.$this->signature($tooFarInFuture),
            self::SECRET
        ));
    }

    private function signature(string $timestamp): string
    {
        return base64_encode(hash_hmac(
            'sha256',
            'msg_test.'.$timestamp.'.'.self::PAYLOAD,
            'test-secret',
            true
        ));
    }
}
```

- [ ] **Step 2: Run the test and observe the missing-class failure**

Run:

```bash
vendor/bin/phpunit tests/Support/ResendWebhookSignatureTest.php
```

Expected: FAIL because `BeyondCode\Mailbox\Support\ResendWebhookSignature` does not exist.

- [ ] **Step 3: Implement the minimal verifier**

Create `src/Support/ResendWebhookSignature.php`:

```php
<?php

namespace BeyondCode\Mailbox\Support;

use Carbon\Carbon;

class ResendWebhookSignature
{
    protected const PREFIX = 'whsec_';
    protected const TOLERANCE = 300;

    public function verify(
        string $payload,
        ?string $id,
        ?string $timestamp,
        ?string $signatures,
        ?string $secret
    ): bool {
        if (! $id || ! $timestamp || ! $signatures || ! $secret) {
            return false;
        }

        if (! str_starts_with($secret, static::PREFIX)) {
            return false;
        }

        $decodedSecret = base64_decode(substr($secret, strlen(static::PREFIX)), true);
        $timestamp = filter_var($timestamp, FILTER_VALIDATE_INT);

        if ($decodedSecret === false || $decodedSecret === '' || $timestamp === false) {
            return false;
        }

        $now = Carbon::now()->timestamp;

        if ($timestamp < $now - static::TOLERANCE || $timestamp > $now + static::TOLERANCE) {
            return false;
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $id.'.'.$timestamp.'.'.$payload,
            $decodedSecret,
            true
        ));

        foreach (preg_split('/\s+/', trim($signatures)) as $signature) {
            [$version, $value] = array_pad(explode(',', $signature, 2), 2, null);

            if ($version === 'v1' && is_string($value) && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run focused and package tests**

Run:

```bash
vendor/bin/phpunit tests/Support/ResendWebhookSignatureTest.php
composer test
```

Expected: all signature cases and the full package suite pass.

- [ ] **Step 5: Commit the verifier**

```bash
git add src/Support/ResendWebhookSignature.php tests/Support/ResendWebhookSignatureTest.php
git commit -m "feat: verify Resend webhook signatures"
```

---

### Task 3: Resend Receiving API Client

**Files:**
- Create: `src/Clients/ResendClient.php`
- Create: `tests/Clients/ResendClientTest.php`

**Interfaces:**
- Consumes: `mailbox.services.resend.api_key` and Laravel's `Http` facade.
- Produces: `ResendClient::rawEmail(string $emailId): string` for `ProcessResendEmail`.

- [ ] **Step 1: Write successful, failure, and sanitization tests**

Create `tests/Clients/ResendClientTest.php`:

```php
<?php

namespace BeyondCode\Mailbox\Tests\Clients;

use BeyondCode\Mailbox\Clients\ResendClient;
use BeyondCode\Mailbox\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ResendClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mailbox.services.resend.api_key' => 're_test']);
        Http::preventStrayRequests();
    }

    #[Test]
    public function it_retrieves_raw_email_without_forwarding_the_api_key()
    {
        $downloadUrl = 'https://inbound-cdn.resend.com/raw/email?signature=secret';
        $mime = "From: sender@example.com\r\nTo: inbox@example.com\r\n\r\nHello";

        Http::fake([
            ResendClient::API_URL.'/email%2F123' => Http::response([
                'raw' => ['download_url' => $downloadUrl],
            ]),
            $downloadUrl => Http::response($mime),
        ]);

        $this->assertSame($mime, (new ResendClient)->rawEmail('email/123'));

        Http::assertSent(function (Request $request) {
            return $request->url() === ResendClient::API_URL.'/email%2F123'
                && $request->hasHeader('Authorization', 'Bearer re_test')
                && $request->hasHeader('User-Agent', ResendClient::USER_AGENT);
        });

        Http::assertSent(function (Request $request) use ($downloadUrl) {
            return $request->url() === $downloadUrl
                && ! $request->hasHeader('Authorization');
        });
    }

    #[Test]
    public function it_requires_an_api_key()
    {
        config(['mailbox.services.resend.api_key' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resend API key is not configured.');

        (new ResendClient)->rawEmail('email_123');
    }

    #[Test]
    #[DataProvider('metadataFailureStatuses')]
    public function it_throws_for_metadata_failures_without_exposing_the_api_key(int $status)
    {
        Http::fake([
            ResendClient::API_URL.'/email_123' => Http::response('', $status),
        ]);

        $exception = null;

        try {
            (new ResendClient)->rawEmail('email_123');
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringNotContainsString('re_test', (string) $exception);
        Http::assertSentCount(1);
    }

    public static function metadataFailureStatuses(): array
    {
        return [
            'redirect' => [302],
            'unauthorized' => [401],
            'not found' => [404],
            'rate limited' => [429],
            'server error' => [500],
        ];
    }

    #[Test]
    #[DataProvider('invalidDownloadUrls')]
    public function it_rejects_invalid_raw_download_urls($downloadUrl)
    {
        Http::fake([
            ResendClient::API_URL.'/email_123' => Http::response([
                'raw' => ['download_url' => $downloadUrl],
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Resend did not return a valid raw email download URL.');

        (new ResendClient)->rawEmail('email_123');
    }

    public static function invalidDownloadUrls(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'malformed' => ['not a url'],
            'http' => ['http://example.com/raw'],
            'credentials' => ['https://user:pass@example.com/raw'],
        ];
    }

    #[Test]
    #[DataProvider('rawDownloadFailures')]
    public function it_sanitizes_raw_download_failures(int $status, string $body)
    {
        $downloadUrl = 'https://inbound-cdn.resend.com/raw/email?signature=secret';

        Http::fake([
            ResendClient::API_URL.'/email_123' => Http::response([
                'raw' => ['download_url' => $downloadUrl],
            ]),
            $downloadUrl => Http::response(
                $body,
                $status,
                $status === 302 ? ['Location' => 'https://example.com/redirected'] : []
            ),
        ]);

        try {
            (new ResendClient)->rawEmail('email_123');
            $this->fail('Expected raw download failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to download the raw Resend email.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('signature=secret', (string) $exception);
        }

        Http::assertSentCount(2);
    }

    public static function rawDownloadFailures(): array
    {
        return [
            'empty success' => [200, ''],
            'redirect' => [302, 'redirect'],
            'server error' => [500, 'download failed'],
        ];
    }
}
```

- [ ] **Step 2: Run the test and observe the missing-client failure**

Run:

```bash
vendor/bin/phpunit tests/Clients/ResendClientTest.php
```

Expected: FAIL because `BeyondCode\Mailbox\Clients\ResendClient` does not exist.

- [ ] **Step 3: Implement the authenticated metadata request**

Start `src/Clients/ResendClient.php` with:

```php
<?php

namespace BeyondCode\Mailbox\Clients;

use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ResendClient
{
    public const API_URL = 'https://api.resend.com/emails/receiving';
    public const USER_AGENT = 'beyondcode/laravel-mailbox';

    public function rawEmail(string $emailId): string
    {
        $apiKey = config('mailbox.services.resend.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new LogicException('Resend API key is not configured.');
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->withHeaders(['User-Agent' => static::USER_AGENT])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)
            ->timeout(15)
            ->get(static::API_URL.'/'.rawurlencode($emailId))
            ->throw();

        if (! $response->successful()) {
            throw new RuntimeException('Unable to retrieve the Resend email.');
        }

        $downloadUrl = $response->json('raw.download_url');

        if (! $this->isHttpsUrl($downloadUrl)) {
            throw new UnexpectedValueException('Resend did not return a valid raw email download URL.');
        }

        return $this->download($downloadUrl);
    }
```

- [ ] **Step 4: Implement the isolated raw download and URL validation**

Complete the class with:

```php
    protected function download(string $url): string
    {
        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->connectTimeout(5)
                ->timeout(120)
                ->get($url);

            if (! $response->successful() || $response->body() === '') {
                throw new RuntimeException;
            }

            return $response->body();
        } catch (Throwable) {
            throw new RuntimeException('Unable to download the raw Resend email.');
        }
    }

    protected function isHttpsUrl($url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
```

- [ ] **Step 5: Run focused and package tests**

Run:

```bash
vendor/bin/phpunit tests/Clients/ResendClientTest.php
composer test
```

Expected: all client cases pass; no request escapes `Http::fake()`.

- [ ] **Step 6: Commit the client**

```bash
git add src/Clients/ResendClient.php tests/Clients/ResendClientTest.php
git commit -m "feat: retrieve Resend raw emails"
```

---

### Task 4: Queue Rate Limiting and Email Processing Job

**Files:**
- Create: `src/Queue/Middleware/ResendRateLimited.php`
- Create: `src/Jobs/ProcessResendEmail.php`
- Create: `tests/Queue/Middleware/ResendRateLimitedTest.php`
- Create: `tests/Jobs/ProcessResendEmailTest.php`

**Interfaces:**
- Consumes: `ResendClient::rawEmail(string): string`, `mailbox.model`, `mailbox.services.resend.rate_limit`, `Mailbox::callMailboxes()`.
- Produces: `ProcessResendEmail::__construct(string $emailId, string $webhookId)`, `uniqueId(): string`, `backoff(): array`, `retryUntil(): DateTimeInterface`, `middleware(): array`, and `handle(ResendClient): void` for the controller and queue worker.

- [ ] **Step 1: Write the one-second middleware tests**

Create `tests/Queue/Middleware/ResendRateLimitedTest.php`:

```php
<?php

namespace BeyondCode\Mailbox\Tests\Queue\Middleware;

use BeyondCode\Mailbox\Queue\Middleware\ResendRateLimited;
use BeyondCode\Mailbox\Tests\TestCase;
use Illuminate\Cache\RateLimiter;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class ResendRateLimitedTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->make(RateLimiter::class)->clear(ResendRateLimited::KEY);

        parent::tearDown();
    }

    #[Test]
    public function it_releases_jobs_over_the_per_second_limit()
    {
        config(['mailbox.services.resend.rate_limit' => 1]);

        $job = Mockery::mock();
        $job->shouldReceive('release')
            ->once()
            ->with(Mockery::on(fn ($seconds) => $seconds >= 1 && $seconds <= 2))
            ->andReturn(false);

        $handled = 0;
        $next = function () use (&$handled) {
            $handled++;
        };

        $middleware = new ResendRateLimited;
        $middleware->handle($job, $next);
        $middleware->handle($job, $next);

        $this->assertSame(1, $handled);
    }

    #[Test]
    public function it_rejects_a_non_positive_limit()
    {
        config(['mailbox.services.resend.rate_limit' => 0]);

        $this->expectException(LogicException::class);

        (new ResendRateLimited)->handle(Mockery::mock(), fn () => null);
    }
}
```

- [ ] **Step 2: Write the job metadata and handler tests**

Create `tests/Jobs/ProcessResendEmailTest.php`:

```php
<?php

namespace BeyondCode\Mailbox\Tests\Jobs;

use BeyondCode\Mailbox\Clients\ResendClient;
use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;
use BeyondCode\Mailbox\Jobs\ProcessResendEmail;
use BeyondCode\Mailbox\Queue\Middleware\ResendRateLimited;
use BeyondCode\Mailbox\Tests\TestCase;
use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ProcessResendEmailTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TestResendInboundEmail::$rawMessage = null;

        parent::tearDown();
    }

    #[Test]
    public function it_exposes_unique_retry_and_middleware_configuration()
    {
        Carbon::setTestNow('2026-07-28 00:00:00');

        $syncJob = (new ProcessResendEmail('email_123', 'msg_123'))->onConnection('sync');
        $queuedJob = (new ProcessResendEmail('email_123', 'msg_123'))->onConnection('redis');

        $this->assertSame('msg_123', $queuedJob->uniqueId());
        $this->assertSame([5, 60, 300, 1800, 7200, 18000, 36000], $queuedJob->backoff());
        $this->assertSame(now()->addDay()->timestamp, $queuedJob->retryUntil()->timestamp);
        $this->assertSame(180, $queuedJob->timeout);
        $this->assertSame([], $syncJob->middleware());
        $this->assertInstanceOf(ResendRateLimited::class, $queuedJob->middleware()[0]);
    }

    #[Test]
    public function it_builds_the_configured_model_and_calls_mailboxes()
    {
        config(['mailbox.model' => TestResendInboundEmail::class]);

        $client = Mockery::mock(ResendClient::class);
        $client->shouldReceive('rawEmail')->once()->with('email_123')->andReturn('raw mime');

        Mailbox::shouldReceive('callMailboxes')
            ->once()
            ->with(Mockery::type(TestResendInboundEmail::class));

        (new ProcessResendEmail('email_123', 'msg_123'))->handle($client);

        $this->assertSame('raw mime', TestResendInboundEmail::$rawMessage);
    }

    #[Test]
    public function it_does_not_call_mailboxes_when_retrieval_fails()
    {
        $expected = new RuntimeException('api failed');
        $client = Mockery::mock(ResendClient::class);
        $client->shouldReceive('rawEmail')
            ->once()
            ->with('email_123')
            ->andThrow($expected);

        Mailbox::shouldReceive('callMailboxes')->never();

        try {
            (new ProcessResendEmail('email_123', 'msg_123'))->handle($client);
            $this->fail('Expected the client exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }
    }
}

class TestResendInboundEmail extends InboundEmail
{
    public static ?string $rawMessage = null;

    public static function fromMessage($message)
    {
        static::$rawMessage = $message;

        return parent::fromMessage($message);
    }
}
```

- [ ] **Step 3: Run the tests and observe both missing-class failures**

Run:

```bash
vendor/bin/phpunit tests/Queue/Middleware/ResendRateLimitedTest.php tests/Jobs/ProcessResendEmailTest.php
```

Expected: FAIL because the middleware and job do not exist.

- [ ] **Step 4: Implement the Laravel 10-compatible one-second middleware**

Create `src/Queue/Middleware/ResendRateLimited.php`:

```php
<?php

namespace BeyondCode\Mailbox\Queue\Middleware;

use Illuminate\Cache\RateLimiter;
use LogicException;

class ResendRateLimited
{
    public const KEY = 'laravel-mailbox:resend';

    public function handle($job, $next)
    {
        $maxAttempts = (int) config('mailbox.services.resend.rate_limit', 5);

        if ($maxAttempts < 1) {
            throw new LogicException('Resend API rate limit must be a positive integer.');
        }

        /** @var RateLimiter $limiter */
        $limiter = app(RateLimiter::class);

        if ($limiter->tooManyAttempts(static::KEY, $maxAttempts)) {
            return $job->release($limiter->availableIn(static::KEY) + 1);
        }

        $limiter->hit(static::KEY, 1);

        return $next($job);
    }
}
```

- [ ] **Step 5: Implement the unique processing job**

Create `src/Jobs/ProcessResendEmail.php`:

```php
<?php

namespace BeyondCode\Mailbox\Jobs;

use BeyondCode\Mailbox\Clients\ResendClient;
use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;
use BeyondCode\Mailbox\Queue\Middleware\ResendRateLimited;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessResendEmail implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public $timeout = 180;

    public string $emailId;
    public string $webhookId;

    public function __construct(string $emailId, string $webhookId)
    {
        $this->emailId = $emailId;
        $this->webhookId = $webhookId;
    }

    public function uniqueId(): string
    {
        return $this->webhookId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function backoff(): array
    {
        return [5, 60, 300, 1800, 7200, 18000, 36000];
    }

    public function middleware(): array
    {
        return $this->connection === 'sync' ? [] : [new ResendRateLimited];
    }

    public function handle(ResendClient $client): void
    {
        /** @var class-string<InboundEmail> $modelClass */
        $modelClass = config('mailbox.model');
        $email = $modelClass::fromMessage($client->rawEmail($this->emailId));

        Mailbox::callMailboxes($email);
    }
}
```

- [ ] **Step 6: Run focused and package tests**

Run:

```bash
vendor/bin/phpunit tests/Queue/Middleware/ResendRateLimitedTest.php tests/Jobs/ProcessResendEmailTest.php
composer test
```

Expected: middleware and job tests pass, followed by the complete suite.

- [ ] **Step 7: Commit queue processing**

```bash
git add src/Queue/Middleware/ResendRateLimited.php src/Jobs/ProcessResendEmail.php tests/Queue/Middleware/ResendRateLimitedTest.php tests/Jobs/ProcessResendEmailTest.php
git commit -m "feat: process Resend emails through queues"
```

---

### Task 5: Authenticated Webhook Endpoint and Sync Integration

**Files:**
- Create: `src/Http/Requests/ResendRequest.php`
- Create: `src/Http/Controllers/ResendController.php`
- Create: `tests/Concerns/SignsResendWebhooks.php`
- Create: `tests/Controllers/ResendTest.php`
- Create: `tests/Fixtures/resend-email.eml`

**Interfaces:**
- Consumes: `ResendWebhookSignature::verify(...)`, `ProcessResendEmail`, `dispatch()`, the Resend route from Task 1, and the client/job from Tasks 3–4.
- Produces: HTTP 401/400/204/200/5xx behavior and the complete webhook-to-mailbox flow.

- [ ] **Step 1: Create the deterministic signed-request test helper**

Create `tests/Concerns/SignsResendWebhooks.php`:

```php
<?php

namespace BeyondCode\Mailbox\Tests\Concerns;

use Illuminate\Testing\TestResponse;

trait SignsResendWebhooks
{
    protected function postResendWebhook(
        string $payload,
        string $webhookId = 'msg_test',
        ?int $timestamp = null,
        ?string $signature = null,
        bool $configureSecret = true
    ): TestResponse {
        $timestamp = $timestamp ?? now()->timestamp;
        $secret = 'test-secret';

        if ($configureSecret) {
            config([
                'mailbox.services.resend.webhook_secret' => 'whsec_'.base64_encode($secret),
            ]);
        }

        $signature = $signature ?? base64_encode(hash_hmac(
            'sha256',
            $webhookId.'.'.$timestamp.'.'.$payload,
            $secret,
            true
        ));

        return $this->call('POST', '/laravel-mailbox/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $webhookId,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
        ], $payload);
    }
}
```

- [ ] **Step 2: Write authentication, validation, and dispatch tests**

Create `tests/Controllers/ResendTest.php`:

```php
<?php

namespace BeyondCode\Mailbox\Tests\Controllers;

use BeyondCode\Mailbox\Clients\ResendClient;
use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;
use BeyondCode\Mailbox\Jobs\ProcessResendEmail;
use BeyondCode\Mailbox\Tests\Concerns\SignsResendWebhooks;
use BeyondCode\Mailbox\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ResendTest extends TestCase
{
    use SignsResendWebhooks;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']['mailbox.driver'] = 'resend';
    }

    #[Test]
    public function it_rejects_invalid_signatures_without_dispatching()
    {
        Bus::fake();

        $payload = json_encode([
            'type' => 'email.received',
            'data' => ['email_id' => 'email_123'],
        ]);

        $this->postResendWebhook($payload, signature: 'invalid')->assertStatus(401);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_rejects_missing_svix_headers_without_dispatching()
    {
        Bus::fake();
        config([
            'mailbox.services.resend.webhook_secret' => 'whsec_'.base64_encode('test-secret'),
        ]);

        $this->call(
            'POST',
            '/laravel-mailbox/resend',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"type":"email.received","data":{"email_id":"email_123"}}'
        )->assertStatus(401);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_rejects_stale_webhooks_without_dispatching()
    {
        Bus::fake();

        $this->postResendWebhook(
            '{"type":"email.received","data":{"email_id":"email_123"}}',
            timestamp: now()->timestamp - 301
        )->assertStatus(401);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_rejects_malformed_signed_json_without_dispatching()
    {
        Bus::fake();

        $this->postResendWebhook('{"type":')
            ->assertStatus(400);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_rejects_invalid_received_payloads()
    {
        Bus::fake();

        $this->postResendWebhook('{"type":"email.received","data":{}}')
            ->assertStatus(400);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_acknowledges_signed_unrelated_events()
    {
        Bus::fake();

        $this->postResendWebhook('{"type":"email.delivered","data":{}}')
            ->assertNoContent();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_dispatches_received_emails_to_the_configured_connection()
    {
        Bus::fake();
        config(['mailbox.services.resend.queue_connection' => 'redis']);

        $this->postResendWebhook(
            '{"type":"email.received","data":{"email_id":"email_123"}}',
            'msg_123'
        )->assertOk();

        Bus::assertDispatched(ProcessResendEmail::class, function ($job) {
            return $job->emailId === 'email_123'
                && $job->webhookId === 'msg_123'
                && $job->connection === 'redis';
        });
    }

    #[Test]
    #[DataProvider('invalidQueueConnections')]
    public function it_rejects_invalid_queue_configuration($connection)
    {
        Bus::fake();
        config(['mailbox.services.resend.queue_connection' => $connection]);

        $this->postResendWebhook(
            '{"type":"email.received","data":{"email_id":"email_123"}}'
        )->assertStatus(500);

        Bus::assertNothingDispatched();
    }

    public static function invalidQueueConnections(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidRateLimits')]
    public function it_rejects_invalid_rate_limit_configuration($rateLimit)
    {
        Bus::fake();
        config(['mailbox.services.resend.rate_limit' => $rateLimit]);

        $this->postResendWebhook(
            '{"type":"email.received","data":{"email_id":"email_123"}}'
        )->assertStatus(500);

        Bus::assertNothingDispatched();
    }

    public static function invalidRateLimits(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'non-numeric' => ['invalid'],
        ];
    }

    #[Test]
    public function it_reports_a_missing_webhook_secret_as_server_configuration()
    {
        Bus::fake();
        $payload = '{"type":"email.received","data":{"email_id":"email_123"}}';

        $this->postResendWebhook(
            $payload,
            configureSecret: false
        )->assertStatus(500);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_releases_the_unique_lock_when_dispatch_fails()
    {
        $original = $this->app->make(Dispatcher::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        $this->app->instance(Dispatcher::class, $dispatcher);

        $payload = '{"type":"email.received","data":{"email_id":"email_123"}}';

        try {
            $this->postResendWebhook($payload, 'msg_retry')->assertStatus(500);
        } finally {
            $this->app->instance(Dispatcher::class, $original);
        }

        Bus::fake();

        $this->postResendWebhook($payload, 'msg_retry')->assertOk();
        Bus::assertDispatched(ProcessResendEmail::class);
    }
}
```

- [ ] **Step 3: Add a realistic multipart MIME fixture**

Create `tests/Fixtures/resend-email.eml`:

```text
From: Resend Sender <sender@example.com>
To: inbox@example.com
Subject: Inbound from Resend
Message-ID: <resend-test@example.com>
Date: Tue, 28 Jul 2026 10:00:00 +0800
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="resend-boundary"

--resend-boundary
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 7bit

Hello from Resend.
--resend-boundary
Content-Type: text/plain; name="note.txt"
Content-Disposition: attachment; filename="note.txt"
Content-Transfer-Encoding: base64

YXR0YWNobWVudCBjb250ZW50
--resend-boundary--
```

- [ ] **Step 4: Add synchronous integration, failure-ordering, and custom-model tests**

Insert these methods into `ResendTest` before its closing brace:

```php
    #[Test]
    public function it_processes_raw_mime_and_attachments_on_the_sync_connection()
    {
        $downloadUrl = 'https://inbound-cdn.resend.com/raw/email?signature=test';
        $mime = file_get_contents(__DIR__.'/../Fixtures/resend-email.eml');
        $handled = false;

        config([
            'mailbox.services.resend.api_key' => 're_test',
            'mailbox.services.resend.queue_connection' => 'sync',
        ]);

        Http::fake([
            ResendClient::API_URL.'/email_123' => Http::response([
                'raw' => ['download_url' => $downloadUrl],
            ]),
            $downloadUrl => Http::response($mime),
        ]);

        Mailbox::to('inbox@example.com', function (InboundEmail $email) use (&$handled) {
            $handled = true;

            $this->assertSame('Inbound from Resend', $email->subject());
            $this->assertSame('Hello from Resend.', trim($email->text()));
            $this->assertCount(1, $email->attachments());
        });

        $this->postResendWebhook(
            '{"type":"email.received","data":{"email_id":"email_123"}}',
            'msg_123'
        )->assertOk();

        $this->assertTrue($handled);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_returns_a_server_error_without_calling_mailboxes_when_sync_retrieval_fails()
    {
        $handled = false;

        config([
            'mailbox.services.resend.api_key' => 're_test',
            'mailbox.services.resend.queue_connection' => 'sync',
        ]);

        Http::fake([
            ResendClient::API_URL.'/email_123' => Http::response('', 500),
        ]);

        Mailbox::to('inbox@example.com', function () use (&$handled) {
            $handled = true;
        });

        $this->postResendWebhook(
            '{"type":"email.received","data":{"email_id":"email_123"}}',
            'msg_failure'
        )->assertStatus(500);

        $this->assertFalse($handled);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_honors_the_configured_model()
    {
        $downloadUrl = 'https://inbound-cdn.resend.com/raw/email?signature=test';

        config([
            'mailbox.model' => TestResendControllerInboundEmail::class,
            'mailbox.services.resend.api_key' => 're_test',
            'mailbox.services.resend.queue_connection' => 'sync',
        ]);

        Http::fake([
            ResendClient::API_URL.'/email_123' => Http::response([
                'raw' => ['download_url' => $downloadUrl],
            ]),
            $downloadUrl => Http::response('raw mime'),
        ]);

        Mailbox::shouldReceive('callMailboxes')
            ->once()
            ->with(Mockery::type(TestResendControllerInboundEmail::class));

        $this->postResendWebhook(
            '{"type":"email.received","data":{"email_id":"email_123"}}',
            'msg_model'
        )->assertOk();

        $this->assertSame('raw mime', TestResendControllerInboundEmail::$rawMessage);
    }
```

Append the controller-local model after `ResendTest`:

```php
class TestResendControllerInboundEmail extends InboundEmail
{
    public static ?string $rawMessage = null;

    public static function fromMessage($message)
    {
        static::$rawMessage = $message;

        return parent::fromMessage($message);
    }
}
```

- [ ] **Step 5: Run endpoint tests and observe the missing request/controller failure**

Run:

```bash
vendor/bin/phpunit tests/Controllers/ResendTest.php
```

Expected: FAIL because the registered route's request and controller classes do not exist.

- [ ] **Step 6: Implement the authenticated FormRequest**

Create `src/Http/Requests/ResendRequest.php`:

```php
<?php

namespace BeyondCode\Mailbox\Http\Requests;

use BeyondCode\Mailbox\Support\ResendWebhookSignature;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use LogicException;

class ResendRequest extends FormRequest
{
    protected function prepareForValidation()
    {
        $secret = config('mailbox.services.resend.webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            throw new LogicException('Resend webhook secret is not configured.');
        }

        $signed = app(ResendWebhookSignature::class)->verify(
            $this->getContent(),
            $this->header('svix-id'),
            $this->header('svix-timestamp'),
            $this->header('svix-signature'),
            $secret
        );

        abort_unless($signed, 401, 'Invalid Resend signature or timestamp.');
    }

    public function rules()
    {
        return [
            'type' => ['required', 'string'],
            'data' => ['required', 'array'],
            'data.email_id' => ['required_if:type,email.received', 'string', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Invalid Resend webhook payload.',
        ], 400));
    }

    public function eventType(): string
    {
        return $this->input('type');
    }

    public function emailId(): ?string
    {
        return $this->input('data.email_id');
    }

    public function webhookId(): string
    {
        return $this->header('svix-id');
    }
}
```

- [ ] **Step 7: Implement the dispatching controller**

Create `src/Http/Controllers/ResendController.php`:

```php
<?php

namespace BeyondCode\Mailbox\Http\Controllers;

use BeyondCode\Mailbox\Http\Requests\ResendRequest;
use BeyondCode\Mailbox\Jobs\ProcessResendEmail;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use LogicException;
use Throwable;

class ResendController
{
    public function __invoke(ResendRequest $request)
    {
        if ($request->eventType() !== 'email.received') {
            return response('', 204);
        }

        $connection = config('mailbox.services.resend.queue_connection', 'sync');
        $rateLimit = (int) config('mailbox.services.resend.rate_limit', 5);

        if (! is_string($connection) || trim($connection) === '') {
            throw new LogicException('Resend queue connection is not configured.');
        }

        if ($rateLimit < 1) {
            throw new LogicException('Resend API rate limit must be a positive integer.');
        }

        $job = (new ProcessResendEmail($request->emailId(), $request->webhookId()))
            ->onConnection($connection);

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            (new UniqueLock(app(Cache::class)))->release($job);

            throw $exception;
        }

        return response('', 200);
    }
}
```

- [ ] **Step 8: Run all Resend tests and the package suite**

Run:

```bash
vendor/bin/phpunit tests/Drivers/ResendTest.php tests/Support/ResendWebhookSignatureTest.php tests/Clients/ResendClientTest.php tests/Queue/Middleware/ResendRateLimitedTest.php tests/Jobs/ProcessResendEmailTest.php tests/Controllers/ResendTest.php
composer test
```

Expected: all Resend-focused tests and the complete existing suite pass.

- [ ] **Step 9: Commit the endpoint**

```bash
git add src/Http/Requests/ResendRequest.php src/Http/Controllers/ResendController.php tests/Concerns/SignsResendWebhooks.php tests/Controllers/ResendTest.php tests/Fixtures/resend-email.eml
git commit -m "feat: accept Resend inbound webhooks"
```

---

### Task 6: Driver Documentation and Final Verification

**Files:**
- Modify: `docs/drivers/drivers.md`

**Interfaces:**
- Consumes: Every public configuration key and operational behavior implemented in Tasks 1–5.
- Produces: User-facing setup and operations guidance; no new runtime API.

- [ ] **Step 1: Add the Resend driver documentation**

Insert this section before `## Local development / log driver` in
`docs/drivers/drivers.md`:

````markdown
## Resend

Configure Resend to send the `email.received` event to:

```text
https://your-app.example/laravel-mailbox/resend
```

Then configure the driver:

```dotenv
MAILBOX_DRIVER=resend
MAILBOX_RESEND_API_KEY=re_xxxxxxxxx
MAILBOX_RESEND_WEBHOOK_SECRET=whsec_xxxxxxxxx
MAILBOX_RESEND_QUEUE_CONNECTION=sync
MAILBOX_RESEND_RATE_LIMIT=5
```

The Resend API key and the endpoint-specific `whsec_` webhook signing secret
are different credentials. The incoming webhook contains metadata rather than
the complete message. Laravel Mailbox uses the signed `email_id` to call
Resend's Receiving API, then downloads the raw MIME so original headers,
bodies, and attachments remain intact.

### Queue processing

The `sync` connection is the default and does not need a queue worker. API,
download, MIME parsing, and mailbox exceptions return HTTP 5xx so Resend can
retry the webhook.

For high volume, set `MAILBOX_RESEND_QUEUE_CONNECTION` to `redis`, `database`,
`sqs`, or another configured Laravel queue connection and run queue workers. A
successful enqueue returns HTTP 200; later processing failures are retried by
Laravel.

Resend's default API limit is five requests per second per team, so
`MAILBOX_RESEND_RATE_LIMIT` defaults to `5`. Lower it when other applications
share the team's allowance. Only raise it after Resend has approved a higher
limit for the team.

The job timeout is 180 seconds. Configure the queue connection's `retry_after`
above 180 seconds and keep the worker's `--timeout` below `retry_after`.
Workers must also have enough memory for the complete MIME message, including
attachments.

Webhook and queue delivery are at least once. Make mailbox handlers with side
effects idempotent, preferably using the raw email's stable `Message-Id` as the
business deduplication key.
````

- [ ] **Step 2: Run documentation and code hygiene checks**

Run:

```bash
composer validate --strict
git diff --check
```

Expected: both exit successfully.

- [ ] **Step 3: Run the complete test suite**

Run:

```bash
composer test
```

Expected: every existing and new test passes.

- [ ] **Step 4: Review the final feature diff**

Run:

```bash
git status --short
git diff --stat upstream/master...HEAD
git diff upstream/master...HEAD -- composer.json config/mailbox.php src tests docs/drivers/drivers.md
```

Expected:

- only Resend feature, tests, configuration, dependencies, design/plan, and
  driver documentation are present;
- no migration or unrelated driver behavior changed;
- no secrets, signed URLs, generated dependency trees, or ignored artifacts
  are staged.

- [ ] **Step 5: Commit the documentation**

```bash
git add docs/drivers/drivers.md
git commit -m "docs: document Resend inbound driver"
```

- [ ] **Step 6: Run the completion verification required before publishing**

Invoke `superpowers:verification-before-completion`, then re-run every command
it requires. Record the exact test counts and environment in the handoff.

- [ ] **Step 7: Request code review before push/PR**

Invoke `superpowers:requesting-code-review`. Address any correctness,
compatibility, security, or scope findings before publishing.

- [ ] **Step 8: Publish only after review passes**

Invoke `github:yeet` to confirm scope, push
`codex/resend-inbound-driver` to `origin`, and open a draft pull request from:

```text
jasonfish568:codex/resend-inbound-driver
```

to:

```text
beyondcode:master
```

The PR description must summarize signature verification, Receiving API/raw
MIME retrieval, sync/async queue behavior, rate limiting, at-least-once
semantics, documentation, and the verified PHP/Laravel test coverage.
