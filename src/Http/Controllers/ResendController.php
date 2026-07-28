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
