<?php

declare(strict_types=1);

namespace Osmium\Services\Square\Models;

use Osmium\Modules\Checkout\Services\ShopPaymentException;

/**
 * Adapts Square to core's payment.* hooks (see ServiceHooks / ShopPaymentProviders).
 *
 * Core only hands over the order. Square's card token never reaches core: the
 * browser posts it to this service's own route (square-charge.php), which
 * charges the amount stashed here at create time and records Square's payment
 * id in the session. confirm then just asks Square about that payment.
 *
 * Every handler answers only for provider id 'square' and returns null for
 * anything else, so another payment service can coexist.
 */
class SquarePaymentProvider
{
    public const ID = 'square';
    public const SESSION_KEY = 'square_pending';

    /**
     * payment.providers - advertise Square and whether it can take money now.
     */
    public static function provider(array $payload): array
    {
        $client = SquareConfig::client();

        return [
            'id' => self::ID,
            'label' => 'Square',
            'ready' => $client->isEnabled(),
            'frontend' => [ // All public by design: the SDK needs them in the page
                'applicationId' => $client->applicationId(),
                'locationId' => $client->locationId(),
                'sdkUrl' => $client->sdkUrl(),
                'chargeUrl' => '/api/checkout-square-charge',
            ],
            'settingsRoute' => 'settings/square/',
        ];
    }

    /**
     * payment.create - Square has no payment until the card is tokenized, so
     * the reference is our own order ref and the amount is remembered for the
     * charge route.
     *
     * @return array{ref: string, test_mode: bool}|null
     */
    public static function create(array $payload): ?array
    {
        $notOurs = ($payload['provider'] ?? '') !== self::ID;
        if ($notOurs) return null;

        $order = $payload['order'];
        $ref = (string)$order['order_ref'];

        $_SESSION[self::SESSION_KEY] = [
            'ref' => $ref,
            'amount_minor' => SquareClient::toMinorUnits((float)$order['total_inc_tax']),
            'currency' => (string)($order['currency'] ?? 'GBP'),
            'email' => (string)($order['customer_email'] ?? ''),
            'payment_id' => null,
        ];

        return ['ref' => $ref, 'test_mode' => SquareConfig::client()->isTestMode()];
    }

    /**
     * payment.confirm - ask Square what happened to the payment the charge
     * route made.
     *
     * @return array{status: string, amount: float, currency: string, capture_ref: string, payer_email: ?string}|null
     * @throws ShopPaymentException
     */
    public static function confirm(array $payload): ?array
    {
        $notOurs = ($payload['provider'] ?? '') !== self::ID;
        if ($notOurs) return null;

        $pending = $_SESSION[self::SESSION_KEY] ?? null;

        $wrongOrder = !\is_array($pending) || !\hash_equals(known_string: (string)$pending['ref'], user_string: (string)$payload['ref']);
        if ($wrongOrder) throw new ShopPaymentException('Square confirm: no pending charge for ref ' . (string)$payload['ref']);

        $noCharge = empty($pending['payment_id']);
        if ($noCharge) throw new ShopPaymentException('Square confirm: card has not been charged for ' . (string)$payload['ref']);

        return SquareConfig::client()->confirmPayment((string)$pending['payment_id']);
    }
}
