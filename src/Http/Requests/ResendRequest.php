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
            'data' => ['present', 'array'],
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
