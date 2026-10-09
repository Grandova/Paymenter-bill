<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            'company_name' => [['Paymenter', 'Paymenter 中文预览'], '云服务'],
            'theme_default_home_page_text' => [['Welcome to Paymenter!', '欢迎使用 Paymenter！'], '欢迎来到云服务中心'],
            'theme_default_primary' => [['hsl(222, 38%, 15%)'], 'hsl(351, 72%, 47%)'],
            'theme_default_secondary' => [['hsl(237, 33%, 60%)'], 'hsl(351, 72%, 47%)'],
            'theme_default_neutral' => [['hsl(220, 13%, 89%)'], 'hsl(220, 9%, 89%)'],
            'theme_default_base' => [['hsl(222, 35%, 15%)'], 'hsl(215, 18%, 25%)'],
            'theme_default_background' => [['hsl(220, 14%, 96%)'], 'hsl(220, 12%, 97%)'],
            'theme_default_dark-primary' => [['hsl(229, 100%, 64%)'], 'hsl(351, 85%, 68%)'],
            'theme_default_dark-secondary' => [['hsl(237, 33%, 60%)'], 'hsl(351, 72%, 47%)'],
        ];
        foreach ($settings as $key => [$old, $value]) {
            // Preserve the operator's own name, welcome text and custom colors.
            DB::table('settings')->whereNull('settingable_type')->where('key', $key)->whereIn('value', $old)->update(['value' => $value]);
        }
        Cache::forget('settings');
    }

    public function down(): void {}
};
