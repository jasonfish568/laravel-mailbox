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
use Illuminate\Queue\InteractsWithQueue;

class ProcessResendEmail implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue, Queueable;

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

    public function uniqueFor(): int
    {
        return 25 * 60 * 60;
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
        return $this->usesSynchronousQueue() ? [] : [new ResendRateLimited];
    }

    public function usesSynchronousQueue(): bool
    {
        $connection = $this->connection ?: config('queue.default');
        $connections = config('queue.connections', []);

        return is_string($connection)
            && is_array($connections)
            && isset($connections[$connection])
            && is_array($connections[$connection])
            && ($connections[$connection]['driver'] ?? null) === 'sync';
    }

    public function handle(ResendClient $client): void
    {
        /** @var class-string<InboundEmail> $modelClass */
        $modelClass = config('mailbox.model');
        $email = $modelClass::fromMessage($client->rawEmail($this->emailId));

        Mailbox::callMailboxes($email);
    }
}
