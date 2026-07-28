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
