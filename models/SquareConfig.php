<?php

declare(strict_types=1);

namespace Osmium\Services\Square\Models;

/**
 * Square configuration.
 *
 * File-based config (app/config/services/square.json.php), matching the other
 * services. The access token is a secret: the settings page never echoes it
 * back, and a blank submission keeps the stored value.
 */
class SquareConfig
{
    /**
     * Payment types this service can take, as id => label. The site picks which of them it
     * shows customers (acceptedMethods); ids match the shop service's PaymentProviders::METHODS.
     */
    public const SUPPORTED_METHODS = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express'];

    private static ?object $config = null;
    private static string $configPath = 'app/config/services/square.json.php';

    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));
        $stored = (array) ($decoded->square ?? []);

        self::$config = (object) ($stored + (array) self::defaults());

        return self::$config;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        $dir = \dirname(self::$configPath);
        $dirExists = \is_dir($dir);
        if (!$dirExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['square' => $values],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::$configPath, "<?php exit(); ?>\n" . $json . "\n");
        self::clearCache();
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    public static function client(): SquareClient
    {
        return new SquareClient(self::get());
    }

    /**
     * The payment types the site accepts through this service, in SUPPORTED_METHODS order.
     *
     * @return string[]
     */
    public static function acceptedMethods(): array
    {
        $chosen = (array) (self::get()->acceptedMethods ?? []);

        return \array_values(\array_intersect(\array_keys(self::SUPPORTED_METHODS), $chosen));
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'acceptedMethods' => ['visa', 'mastercard', 'amex'],
            'mode' => 'test',
            'testApplicationId' => '',
            'testAccessToken' => '',
            'testLocationId' => '',
            'liveApplicationId' => '',
            'liveAccessToken' => '',
            'liveLocationId' => '',
        ];
    }
}
