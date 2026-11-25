<?php

declare(strict_types=1);

namespace Osmium\Services\Square\Models;

/**
 * admin.status_icons: a card in the admin navbar while Square is the selected payment provider. Green when it is
 * switched on with its keys set and in live mode; amber and crossed through when it still needs configuring or is
 * in test mode. Reads only local config (no Square call).
 */
class SquareStatusIcon
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public static function icons(array $payload): array
    {
        $selected = (string) ($payload['osmium']->config->checkout->paymentProvider ?? 'none');
        $notSelected = $selected !== SquarePaymentProvider::ID;
        if ($notSelected) return [];

        $client = SquareConfig::client();
        $ready = $client->isEnabled() && !$client->isTestMode();

        return [self::icon(ready: $ready, testMode: $client->isTestMode())];
    }

    /** @return array<string, mixed> */
    public static function icon(bool $ready, bool $testMode): array
    {
        $title = $ready ? 'Square payments are live' : ($testMode ? 'Square is in test mode: no real payments yet' : 'Square needs configuring');

        return [
            'id' => 'square',
            'title' => $title,
            'icon' => 'bx bx-credit-card',
            'path' => 'settings/square/',
            'color' => $ready ? 'success' : 'warning',
            'crossed' => !$ready,
        ];
    }
}
