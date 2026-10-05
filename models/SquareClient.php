<?php

declare(strict_types=1);

namespace Osmium\Services\Square\Models;

use Osmium\Modules\Checkout\Services\ShopPaymentException;

require_once __DIR__ . '/../../../modules/checkout/services/ShopPaymentException.php';

/**
 * SquareClient
 *
 * A thin client for Square's Payments API. It knows how to talk to Square and
 * nothing about our basket, totals or order lifecycle: every amount passed in
 * has already been computed server-side from the database.
 *
 * Square takes JSON, and integer minor units (pence). That conversion happens
 * at this boundary and nowhere else.
 */
class SquareClient
{
    private const API_BASE_LIVE = 'https://connect.squareup.com';
    private const API_BASE_SANDBOX = 'https://connect.squareupsandbox.com';
    private const SDK_URL_LIVE = 'https://web.squarecdn.com/v1/square.js';
    private const SDK_URL_SANDBOX = 'https://sandbox.web.squarecdn.com/v1/square.js';
    private const API_VERSION = '2026-09-16';
    private const TIMEOUT_SECONDS = 20;

    public function __construct(private object $config) {}

    public function isEnabled(): bool
    {
        $switchedOn = !empty($this->config->enabled);
        if (!$switchedOn) return false;

        return $this->applicationId() !== '' && $this->accessToken() !== '' && $this->locationId() !== '';
    }

    public function isTestMode(): bool
    {
        return ($this->config->mode ?? 'test') !== 'live';
    }

    public function applicationId(): string
    {
        return (string)($this->config->{$this->isTestMode() ? 'testApplicationId' : 'liveApplicationId'} ?? '');
    }

    public function locationId(): string
    {
        return (string)($this->config->{$this->isTestMode() ? 'testLocationId' : 'liveLocationId'} ?? '');
    }

    public function sdkUrl(): string
    {
        return $this->isTestMode() ? self::SDK_URL_SANDBOX : self::SDK_URL_LIVE;
    }

    /**
     * Charge a tokenized card.
     *
     * @param string $sourceId Token from the browser's card.tokenize()
     * @param string $idempotencyKey Same key for the same order, so a retry cannot charge twice
     * @return array The Square payment object
     * @throws SquareDeclinedException When the card was refused (safe to tell the customer to retry)
     * @throws ShopPaymentException For anything else
     */
    public function createPayment(string $sourceId, string $idempotencyKey, int $amountMinor, string $currency, string $orderRef, ?string $buyerEmail): array
    {
        $body = [
            'source_id' => $sourceId,
            'idempotency_key' => \mb_substr($idempotencyKey, 0, 45), // Square's limit
            'amount_money' => ['amount' => $amountMinor, 'currency' => \strtoupper($currency)],
            'location_id' => $this->locationId(),
            'reference_id' => \mb_substr($orderRef, 0, 40),
            'note' => 'Order ' . $orderRef,
        ];

        $hasEmail = $buyerEmail !== null && \trim($buyerEmail) !== '';
        if ($hasEmail) $body['buyer_email_address'] = \trim($buyerEmail);

        $response = $this->request(method: 'POST', path: '/v2/payments', body: $body);

        return (array)($response['payment'] ?? []);
    }

    /**
     * Ask Square what happened to a payment, normalised to the shape core's
     * payment.confirm expects.
     *
     * @return array{status: string, amount: float, currency: string, capture_ref: string, payer_email: ?string}
     */
    public function confirmPayment(string $paymentId): array
    {
        $response = $this->request(method: 'GET', path: '/v2/payments/' . \rawurlencode($paymentId));

        return $this->normalisePayment((array)($response['payment'] ?? []));
    }

    public function normalisePayment(array $payment): array
    {
        $status = match ((string)($payment['status'] ?? '')) {
            'COMPLETED' => 'completed',
            'FAILED', 'CANCELED' => 'failed',
            default => 'pending', // APPROVED / PENDING
        };

        $money = (array)($payment['amount_money'] ?? []);

        return [
            'status' => $status,
            'amount' => \round(num: ((int)($money['amount'] ?? 0)) / 100, precision: 2),
            'currency' => \strtoupper((string)($money['currency'] ?? '')),
            'capture_ref' => (string)($payment['id'] ?? ''),
            'payer_email' => ($payment['buyer_email_address'] ?? null) ?: null,
        ];
    }

    public static function toMinorUnits(float $amount): int
    {
        return (int)\round(num: $amount * 100);
    }

    private function accessToken(): string
    {
        return (string)($this->config->{$this->isTestMode() ? 'testAccessToken' : 'liveAccessToken'} ?? '');
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $tokenMissing = $this->accessToken() === '';
        if ($tokenMissing) throw new ShopPaymentException('Square credentials are not configured');

        $base = $this->isTestMode() ? self::API_BASE_SANDBOX : self::API_BASE_LIVE;
        $curl = \curl_init($base . $path);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken(),
                'Square-Version: ' . self::API_VERSION,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ];

        $hasBody = $body !== null;
        if ($hasBody) $options[CURLOPT_POSTFIELDS] = \json_encode($body);

        \curl_setopt_array($curl, $options);

        $raw = \curl_exec($curl);
        $status = (int)\curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($curl);

        $callFailed = $raw === false;
        if ($callFailed) throw new ShopPaymentException('Could not reach Square: ' . $curlError);

        $response = \json_decode(json: (string)$raw, associative: true) ?? [];

        $callRejected = $status < 200 || $status >= 300;
        if ($callRejected) {
            \error_log("Square {$method} {$path} failed with status {$status}: " . (string)$raw);

            $isCardRefusal = ($response['errors'][0]['category'] ?? '') === 'PAYMENT_METHOD_ERROR';
            if ($isCardRefusal) throw new SquareDeclinedException('Square declined the card: ' . (string)($response['errors'][0]['code'] ?? 'unknown'));

            $detail = \mb_substr(string: (string)$raw, start: 0, length: 500); // Diagnostic only: never show a customer
            throw new ShopPaymentException("Square rejected the request ({$status}): {$detail}");
        }

        return $response;
    }
}
