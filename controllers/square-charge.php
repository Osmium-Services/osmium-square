<?php

use Osmium\Core\Library\PaymentProviders;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\CheckoutOrderPaymentState;
use Osmium\Modules\Checkout\Services\ShopPaymentException;
use Osmium\Services\Square\Models\SquareConfig;
use Osmium\Services\Square\Models\SquareClient;
use Osmium\Services\Square\Models\SquareDeclinedException;
use Osmium\Services\Square\Models\SquarePaymentProvider;

require_once __DIR__ . '/../../osmium-shop/models/ShopPaymentException.php';
require_once __DIR__ . '/../../osmium-shop/models/CheckoutOrderPaymentState.php';
require_once __DIR__ . '/../../osmium-shop/models/CheckoutModel.php';
require_once __DIR__ . '/../../osmium-shop/models/CheckoutOrder.php';

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

// Square must still be the provider admin selected: the charge route is reachable whatever is selected now
$selectedProvider = PaymentProviders::configured($this->osmium->config->checkout, PaymentProviders::external($this->osmium));
$squareNotSelected = $selectedProvider !== SquarePaymentProvider::ID;
if ($squareNotSelected) $this->osmium->jsonError('Card payment is not available at the moment. Please contact us and we will help.');

// The session copy of the order may be stale: charge only an order that is still awaiting payment, was started with
// Square, is the one this session is paying for, and still owes exactly the amount the session remembers.
$orderId = (int)($_SESSION['pending_order_id'] ?? 0);
$order = $orderId > 0 ? (new CheckoutOrder($this->osmium->dataSource, 'shop_orders'))->getById($orderId) : null;

$orderUsable = $order !== null
    && CheckoutOrderPaymentState::of((string)$order['status']) === CheckoutOrderPaymentState::AWAITING
    && ($order['payment_provider'] ?? '') === SquarePaymentProvider::ID
    && \hash_equals((string)$order['order_ref'], (string)($pending['ref'] ?? ''))
    && SquareClient::toMinorUnits((float)$order['total_inc_tax']) === (int)$pending['amount_minor']
    && (string)$order['currency'] === (string)$pending['currency'];
if (!$orderUsable) $this->osmium->jsonError('We could not find your order. Please contact us before trying again.');

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
