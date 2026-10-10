<?php

namespace Tests\Unit;

use Paymenter\Extensions\Others\Affiliates\Affiliates;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Tests\TestCase;

class AffiliateFeatureTest extends TestCase
{
    public function test_affiliate_settings_allow_a_configurable_percentage(): void
    {
        $settings = collect((new Affiliates)->getConfig())->keyBy('name');

        $this->assertSame('integer|min:0|max:100', $settings['default_reward']['validation']);
        $this->assertSame('%', $settings['default_reward']['suffix']);
    }

    public function test_affiliate_enabled_state_can_be_saved(): void
    {
        $affiliate = (new Affiliate)->fill(['enabled' => false]);

        $this->assertFalse($affiliate->enabled);
    }
}
