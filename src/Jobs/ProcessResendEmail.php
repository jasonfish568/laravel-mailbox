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
