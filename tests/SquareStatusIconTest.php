<?php

declare(strict_types=1);

namespace Tests\Services\Square;

use Osmium\Services\Square\Models\SquareStatusIcon;
use PHPUnit\Framework\TestCase;

foreach (['Client', 'Config', 'PaymentProvider', 'StatusIcon'] as $model) {
    require_once __DIR__ . "/../models/Square{$model}.php";
}

/**
 * The navbar card: only for the selected provider, green when live and ready, amber and crossed otherwise.
 */
final class SquareStatusIconTest extends TestCase
{
    private string $originalDirectory;

    protected function setUp(): void
    {
        $this->originalDirectory = (string) \getcwd();
        \chdir(\sys_get_temp_dir()); // No config file here, so the defaults apply: off, test mode
    }

    protected function tearDown(): void
    {
        \chdir($this->originalDirectory);
    }

    private function payload(string $provider): array
    {
        return ['osmium' => (object) ['config' => (object) ['checkout' => (object) ['paymentProvider' => $provider]]]];
    }

    public function test_no_icon_when_another_provider_is_selected(): void
    {
        $this->assertSame([], SquareStatusIcon::icons($this->payload('none')));
    }

    public function test_unconfigured_selected_provider_is_amber_and_crossed(): void
    {
        $icon = SquareStatusIcon::icons($this->payload('square'))[0];

        $this->assertSame('warning', $icon['color']);
        $this->assertTrue($icon['crossed']);
        $this->assertSame('settings/square/', $icon['path']);
    }

    public function test_ready_and_live_is_green(): void
    {
        $icon = SquareStatusIcon::icon(ready: true, testMode: false);

        $this->assertSame('success', $icon['color']);
        $this->assertFalse($icon['crossed']);
    }
}
