<?php

declare(strict_types=1);

namespace Osmium\Services\Square\Models;

use Osmium\Modules\Checkout\Services\ShopPaymentException;

require_once __DIR__ . '/../../../modules/checkout/services/ShopPaymentException.php';

/**
 * The card itself was refused (declined, bad CVV, expired). Unlike every other
 * failure, retrying with another card is the right advice.
 */
class SquareDeclinedException extends ShopPaymentException
{
}
