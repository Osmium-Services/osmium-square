<?php

use Osmium\Modules\Checkout\Services\ShopPaymentException;
use Osmium\Services\Square\Models\SquareConfig;
use Osmium\Services\Square\Models\SquareDeclinedException;
use Osmium\Services\Square\Models\SquarePaymentProvider;

require_once __DIR__ . '/../../../modules/checkout/services/ShopPaymentException.php';

/**
 * Square charge route.
 *
 * The browser tokenizes the card with Square's Web Payments SDK and POSTs the
 * token here ({"token": "..."}). The amount never comes from the browser: it is
 * the one SquarePaymentProvider::create() stashed in the session from the
 * server-computed order. Core's normal order confirmation runs afterwards and
 * asks Square what happened.
 */

$square = SquareConfig::client();

if (!$square->isEnabled()) {
    $this->osmium->jsonError('Card payment is not available at the moment. Please contact us and we will help.');
}

$input = \json_decode(json: (string)\file_get_contents('php://input'), associative: true) ?? [];
$token = \trim((string)($input['token'] ?? ''));

$noToken = $token === '';
if ($noToken) $this->osmium->jsonError('We could not read your card details. Please try again.');

$pending = $_SESSION[SquarePaymentProvider::SESSION_KEY] ?? null;

$noPending = !\is_array($pending);
if ($noPending) $this->osmium->jsonError('We could not find your order. Please contact us before trying again.');

$alreadyCharged = !empty($pending['payment_id']);
if ($alreadyCharged) $this->osmium->jsonSuccess(['success' => true, 'order_ref' => $pending['ref']]); // A double-click must not charge twice

try {
    $payment = $square->createPayment(
        sourceId: $token,
        idempotencyKey: 'order-' . $pending['ref'],
        amountMinor: (int)$pending['amount_minor'],
        currency: (string)$pending['currency'],
        orderRef: (string)$pending['ref'],
        buyerEmail: $pending['email'] ?: null,
    );

    $paymentId = (string)($payment['id'] ?? '');

    $unusable = $paymentId === '';
    if ($unusable) throw new ShopPaymentException('Square did not return a payment id');

    $_SESSION[SquarePaymentProvider::SESSION_KEY]['payment_id'] = $paymentId;

    $this->osmium->jsonSuccess(['success' => true, 'order_ref' => $pending['ref']]);
} catch (SquareDeclinedException $e) {
    \error_log($e->getMessage());
    $this->osmium->jsonError('Your card was declined. Please check the details or try another card.');
} catch (ShopPaymentException $e) {
    \error_log('Square charge failed: ' . $e->getMessage());
    $this->osmium->jsonError('We could not take your payment. Please try again or contact us. Do not pay twice.');
}
