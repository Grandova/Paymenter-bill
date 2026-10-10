<?php

namespace Tests\Feature;

use App\Admin\Resources\CouponResource;
use App\Admin\Resources\UserResource;
use App\Classes\Settings;
use App\Http\Middleware\SetLocale;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_chinese_catalogs_cover_english_keys_and_preserve_placeholders(): void
    {
        foreach (glob(lang_path('en/*.php')) as $path) {
            $english = Arr::dot(require $path);
            $chinese = Arr::dot(require lang_path('zh/' . basename($path)));

            foreach ($english as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $this->assertArrayHasKey($key, $chinese, basename($path) . ':' . $key);

                preg_match_all('/:[a-zA-Z_]+/', $value, $expected);
                preg_match_all('/:[a-zA-Z_]+/', $chinese[$key], $actual);
                $this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), basename($path) . ':' . $key);
            }
        }
    }

    public function test_locale_defaults_to_chinese_and_respects_session_choice(): void
    {
        $settings = collect(Settings::settings())->flatten(1)->keyBy('name');
        $this->assertSame('zh', $settings['app_language']['default']);
        $this->assertSame('简体中文', $settings['app_language']['options']['zh']);
        $this->assertNotContains('vendor', $settings['allowed_languages']['default']);

        config(['app.locale' => 'zh']);
        session()->forget('locale');
        $middleware = new SetLocale;
        $request = Request::create('/');

        $middleware->handle($request, function () {
            $this->assertSame('zh', app()->getLocale());
            $this->assertSame('账单', __('invoices.invoices'));

            return response('');
        });

        session(['locale' => 'en']);
        $middleware->handle($request, function () {
            $this->assertSame('en', app()->getLocale());
            $this->assertSame('Invoices', __('invoices.invoices'));

            return response('');
        });
    }

    public function test_admin_labels_and_framework_controls_use_chinese(): void
    {
        app()->setLocale('zh');

        $this->assertSame('用户', UserResource::getModelLabel());
        $this->assertSame('客户与财务', UserResource::getNavigationGroup());
        $this->assertSame('优惠券', CouponResource::getPluralModelLabel());
        $this->assertSame('名字', TextInput::make('first_name')->getLabel());
        $this->assertSame('创建时间', TextColumn::make('created_at')->getLabel());
        $this->assertSame('创建 用户', __('filament-panels::resources/pages/create-record.title', ['label' => UserResource::getModelLabel()]));
        $this->assertSame('每页', __('filament::components/pagination.fields.records_per_page.label'));

        $validator = Validator::make([], ['email' => 'required']);
        $this->assertStringContainsString('邮箱', $validator->errors()->first('email'));
    }

    public function test_default_notification_templates_render_chinese_with_original_variables(): void
    {
        app()->setLocale('zh');
        foreach (EmailTemplateSeeder::mapping as $template) {
            foreach (['subject', 'body', 'in_app_title', 'in_app_body', 'edit_preference_message'] as $field) {
                if (!isset($template[$field])) {
                    continue;
                }
                preg_match_all('/\$[a-zA-Z_]\w*/u', $template[$field], $expected);
                preg_match_all('/\$[a-zA-Z_]\w*/u', __($template[$field]), $actual);
                $this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), $field);
            }
        }
        $template = EmailTemplateSeeder::mapping['new_login_detected'];
        $data = ['ip' => '127.0.0.1', 'device' => 'Firefox', 'time' => '2026-01-01 12:00:00'];

        $this->assertSame('检测到新登录', __($template['subject']));
        $body = Blade::render(__($template['in_app_body']), $data);
        $this->assertStringContainsString('您的账户', $body);
        $this->assertStringContainsString('127.0.0.1', $body);
        $this->assertStringContainsString('Firefox', $body);
        $this->assertStringContainsString($data['time'], $body);
        $this->assertSame('商家自定义内容', __('商家自定义内容'));
    }
}
