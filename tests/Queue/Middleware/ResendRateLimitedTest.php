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
