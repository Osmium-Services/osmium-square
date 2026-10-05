<?php

declare(strict_types=1);

namespace Osmium\Services\Square\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Square\Models\SquareConfig;

/**
 * Square settings controller - switch, mode and credentials.
 *
 * Routes:
 *   - index() → /admin/settings/square/
 *
 * Choosing Square as the active checkout provider happens on the core
 * Settings > Payments page; this page only holds Square's own settings.
 */
class SquareController extends AdminController
{
    private const FLASH_KEY = 'square_flash';

    private const KEY_ALPHABET = '/^[A-Za-z0-9_-]+$/';
    private const KEY_MIN_LENGTH = 10;

    /**
     * Post field => [config key, label, secret?]. Secrets are never echoed
     * back and a blank submission keeps the stored value. The Application and
     * Location IDs are public (they sit in the checkout page's HTML), so they
     * are shown, but they still keep the stored value when left blank.
     */
    private const FIELDS = [
        'test_application_id' => ['testApplicationId', 'Sandbox Application ID', false],
        'test_access_token' => ['testAccessToken', 'Sandbox Access Token', true],
        'test_location_id' => ['testLocationId', 'Sandbox Location ID', false],
        'live_application_id' => ['liveApplicationId', 'Production Application ID', false],
        'live_access_token' => ['liveAccessToken', 'Production Access Token', true],
        'live_location_id' => ['liveLocationId', 'Production Location ID', false],
    ];

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $config = (array) SquareConfig::get();

        $fields = [];
        foreach (self::FIELDS as $postField => [$configKey, $label, $secret]) {
            $fields[$postField] = [
                'label' => $label,
                'secret' => $secret,
                'has' => $config[$configKey] !== '',
                'value' => $secret ? '' : $config[$configKey], // Secrets never go back into the page
            ];
        }

        $this->data['admin']['square'] = [
            'enabled' => (bool) $config['enabled'],
            'mode' => $config['mode'],
            'fields' => $fields,
        ];
        $this->data['admin']['flash'] = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        $this->setView('square/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->flashAndRedirect('danger', 'Invalid form submission. Please try again.');

        $values = (array) SquareConfig::get();

        foreach (self::FIELDS as $postField => [$configKey, $label]) {
            $posted = \trim((string) ($_POST[$postField] ?? ''));

            $keepCurrent = $posted === '';
            if ($keepCurrent) continue;

            $error = $this->shapeError(label: $label, value: $posted);
            if ($error !== null) $this->flashAndRedirect('danger', $error);

            $values[$configKey] = $posted;
        }

        $mode = \trim((string) ($_POST['mode'] ?? 'test'));
        $values['enabled'] = isset($_POST['enabled']);
        $values['mode'] = $mode === 'live' ? 'live' : 'test';

        SquareConfig::save($values);

        $this->admin->model->changelog->log(
            description: 'Updated Square settings',
            recordType: 'settings',
        );

        $this->flashAndRedirect('success', 'Settings saved successfully!');
    }

    /**
     * Catches a value that is the wrong *shape* - truncated, masked or carrying
     * stray characters - which admin would otherwise accept silently and surface
     * only as "payment unavailable" on the customer's checkout.
     */
    private function shapeError(string $label, string $value): ?string
    {
        $hasStrayCharacters = \preg_match(self::KEY_ALPHABET, $value) !== 1;
        if ($hasStrayCharacters) {
            return "{$label} contains characters that no real Square value has - usually a sign it was copied "
                . 'from an abbreviated or masked display. Copy the full value from the Square Developer Console.';
        }

        $tooShort = \strlen($value) < self::KEY_MIN_LENGTH;
        if ($tooShort) {
            return "{$label} is only " . \strlen($value) . ' characters, which is too short to be a real one. '
                . 'It looks truncated - copy the full value from the Square Developer Console.';
        }

        return null;
    }

    private function flashAndRedirect(string $type, string $text): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
        $this->redirect('settings/square/');
    }
}
