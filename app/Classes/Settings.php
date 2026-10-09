<?php

namespace App\Classes;

use App\Admin\Actions\ResetColorsAction;
use App\Admin\Actions\SendTestEmailAction;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Rules\Cidr;
use DateTimeZone;
use Exception;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Minishlink\WebPush\VAPID;
use Ramsey\Uuid\Uuid;

class Settings
{
    public static function settings()
    {
        try {
            // Only code is needed
            $currencies = once(function () {
                return Currency::pluck('code')->toArray();
            });
        } catch (Exception $e) {
            $currencies = [];
        }
        $settings = [
            // Split settings into groups (only used in the settings page for organization)
            'general' => [
                [
                    'name' => 'company_name',
                    'label' => __('Company Name'),
                    'type' => 'text',
                    'override' => 'app.name',
                    'default' => 'Paymenter',
                ],
                [
                    'name' => 'timezone',
                    'label' => __('Timezone'),
                    'type' => 'select',
                    // Read timezones from PHP
                    'options' => DateTimeZone::listIdentifiers(DateTimeZone::ALL),
                    'default' => 'UTC',
                    'required' => true,
                    'override' => 'app.timezone',
                ],
                [
                    'name' => 'app_language',
                    'label' => __('Default Language'),
                    'default' => 'zh',
                    'type' => 'select',
                    // Read languages from resources/lang directory
                    // The ternary operator is only present for now. Since there are no lang files, it returns [], which breaks the frontend, so we return ['en']
                    'options' => array_combine(self::getAvailableLanguages(), array_map(fn ($locale) => config('app.available_locales')[$locale] ?? $locale, self::getAvailableLanguages())),
                    'required' => true,
                    'validation' => 'in:' . implode(',', self::getAvailableLanguages()),
                    'override' => 'app.locale',
                ],
                [
                    'name' => 'allowed_languages',
                    'label' => __('Allowed Languages'),
                    'type' => 'select',
                    'options' => array_combine(self::getAvailableLanguages(), array_map(fn ($locale) => config('app.available_locales')[$locale] ?? $locale, self::getAvailableLanguages())),
                    'database_type' => 'array',
                    'multiple' => true,
                    'default' => self::getAvailableLanguages(),
                    'required' => true,
                ],
                [
                    'name' => 'app_url',
                    'label' => __('App URL'),
                    'default' => 'http://localhost',
                    'type' => 'text',
                    'required' => true,
                    'validation' => 'url',
                    'override' => 'app.url',
                ],
                [
                    'name' => 'logo',
                    'label' => __('Logo (Light Mode)'),
                    'type' => 'file',
                    'required' => false,
                    'accept' => ['image/*'],
                    'file_name' => 'logo-light.webp',
                    'description' => __('Upload a logo to be displayed on light backgrounds.'),
                ],
                [
                    'name' => 'logo_dark',
                    'label' => __('Logo (Dark Mode)'),
                    'type' => 'file',
                    'required' => false,
                    'accept' => ['image/*'],
                    'file_name' => 'logo-dark.webp',
                    'description' => __('Upload a logo to be displayed on dark backgrounds.'),
                ],
                [
                    'name' => 'favicon',
                    'label' => __('Favicon'),
                    'type' => 'file',
                    'required' => false,
                    'accept' => ['image/x-icon', 'image/png', 'image/svg+xml'],
                    'file_name' => 'favicon.ico',
                    'description' => __('Upload a .ico, .png, or .svg file to be used as the browser icon.'),
                ],
                [
                    'name' => 'system_email_address',
                    'label' => __('System Email Address'),
                    'type' => 'email',
                    'required' => true,
                    'description' => __('The email address used for system emails, such as CronJob failures, updates, etc.'),
                ],
                [
                    'name' => 'tos',
                    'label' => __('Terms of Service'),
                    'description' => __('URL to your terms of service. Leave blank to disable.'),
                    'type' => 'text',
                    'required' => false,
                ],
            ],

            // Security (captcha, rate limiting, etc.)
            'security' => [
                [
                    'name' => 'captcha',
                    'label' => __('Captcha'),
                    'type' => 'select',
                    'options' => [
                        'disabled' => __('Disabled'),
                        'recaptcha-v2' => 'Google reCAPTCHA v2',
                        'recaptcha-v3' => 'Google reCAPTCHA v3',
                        'turnstile' => 'Cloudflare Turnstile',
                        'hcaptcha' => 'hCaptcha',
                    ],
                    'default' => 'disabled',
                    'live' => true,
                ],
                [
                    'name' => 'captcha_site_key',
                    'label' => __('Captcha Site Key'),
                    'type' => 'text',
                    'required' => fn (Get $get) => $get('captcha') && $get('captcha') !== 'disabled',
                ],
                [
                    'name' => 'captcha_secret',
                    'label' => __('Captcha Secret'),
                    'type' => 'text',
                    'required' => fn (Get $get) => $get('captcha') && $get('captcha') !== 'disabled',
                ],

                [
                    'name' => 'trusted_proxies',
                    'label' => __('Trusted Proxies'),
                    'type' => 'tags',
                    'database_type' => 'array',
                    'placeholder' => __('IP Addresses or CIDR (e.g. 1.1.1.1/32 or 2606:4700:4700::1111)'),
                    'nested_validation' => [
                        new Cidr(allowWildCard: true),
                    ],
                ],
                [
                    'name' => 'session_validation',
                    'label' => __('Session Validation'),
                    'type' => 'select',
                    'options' => [
                        'none' => __('None'),
                        'ip_admin' => __('Lock session to IP address (Admin)'),
                        'ip_client' => __('Lock session to IP address (Client)'),
                        'ip_both' => __('Lock session to IP address (Admin & Client)'),
                        'user_agent_admin' => __('Lock session to User Agent (Admin)'),
                        'user_agent_client' => __('Lock session to User Agent (Client)'),
                        'user_agent' => __('Lock session to User Agent (Admin & Client)'),
                        'ip_user_agent_admin' => __('Lock session to IP address and User Agent (Admin)'),
                        'ip_user_agent_client' => __('Lock session to IP address and User Agent (Client)'),
                        'ip_user_agent_both' => __('Lock session to IP address and User Agent (Admin & Client)'),
                    ],
                    'default' => 'none',
                ],
            ],

            'social-login' => [
                [
                    'name' => 'oauth_google',
                    'label' => __('Google Enabled'),
                    'description' => new HtmlString('<a href="https://paymenter.org/docs/guides/OAuth#google" target="_blank">' . __('Documentation') . '</a>'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'required' => false,
                ],
                [
                    'name' => 'oauth_google_client_id',
                    'label' => __('Google Client ID'),
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_google_client_secret',
                    'label' => __('Google Client Secret'),
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_github',
                    'label' => __('GitHub Enabled'),
                    'description' => new HtmlString('<a href="https://paymenter.org/docs/guides/OAuth#github" target="_blank">' . __('Documentation') . '</a>'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'required' => false,
                ],
                [
                    'name' => 'oauth_github_client_id',
                    'label' => __('Github Client ID'),
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_github_client_secret',
                    'label' => __('Github Client Secret'),
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_discord',
                    'label' => __('Discord Enabled'),
                    'description' => new HtmlString('<a href="https://paymenter.org/docs/guides/OAuth#discord" target="_blank">' . __('Documentation') . '</a>'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'required' => false,
                ],
                [
                    'name' => 'oauth_discord_client_id',
                    'label' => __('Discord Client ID'),
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_discord_client_secret',
                    'label' => __('Discord Client Secret'),
                    'type' => 'text',
                    'required' => false,
                ],
            ],
            'tax' => [
                [
                    'name' => 'tax_enabled',
                    'label' => __('Tax Enabled'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                [
                    'name' => 'tax_type',
                    'label' => __('Tax Type'),
                    'type' => 'select',
                    'options' => [
                        'inclusive' => __('Inclusive (Price includes tax)'),
                        'exclusive' => __('Exclusive (Price does not include tax)'),
                    ],
                    'default' => 'inclusive',
                ],
            ],
            'mail' => [
                // SMTP etc
                [
                    'name' => 'mail_disable',
                    'label' => __('Disable Mail'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'live' => true,
                    'default' => true,
                ],
                [
                    'name' => 'mail_must_verify',
                    'label' => __('Users must verify email before buying'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                [
                    'name' => 'mail_host',
                    'label' => __('Mail Host'),
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.mailers.smtp.host',
                    'action' => SendTestEmailAction::class,
                ],
                [
                    'name' => 'mail_port',
                    'label' => __('Mail Port'),
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.mailers.smtp.port',
                ],
                [
                    'name' => 'mail_username',
                    'label' => __('Mail Username'),
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.mailers.smtp.username',
                ],
                [
                    'name' => 'mail_password',
                    'label' => __('Mail Password'),
                    'type' => 'password',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'encrypted' => true,
                    'override' => 'mail.mailers.smtp.password',
                ],
                [
                    'name' => 'mail_encryption',
                    'label' => __('Mail Encryption'),
                    'type' => 'select',
                    'options' => [
                        'tls' => 'TLS',
                        'ssl' => 'SSL',
                        null => __('None'),
                    ],
                    'default' => 'tls',
                    'required' => false,
                    'override' => 'mail.mailers.smtp.encryption',
                ],
                [
                    'name' => 'mail_from_address',
                    'label' => __('Mail From Address'),
                    'type' => 'email',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.from.address',
                ],
                [
                    'name' => 'mail_from_name',
                    'label' => __('Mail From Name'),
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.from.name',
                ],

                // Theming
                [
                    'name' => 'mail_header',
                    'label' => __('Header'),
                    'type' => 'markdown',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'default' => '',
                    'disable_toolbar' => true,
                ],
                [
                    'name' => 'mail_footer',
                    'label' => __('Footer'),
                    'type' => 'markdown',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'default' => '',
                    'disable_toolbar' => true,
                ],
                [
                    'name' => 'mail_css',
                    'label' => __('Mail CSS'),
                    'type' => 'markdown',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'default' => '',
                    'disable_toolbar' => true,
                ],
            ],
            'tickets' => [
                [
                    'name' => 'tickets_disabled',
                    'label' => __('Disable Tickets'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => __('Disable the ticket system. This will disable all client side ticket functionality, including the ability to create new tickets and view existing tickets.'),
                ],
                [
                    'name' => 'ticket_departments',
                    'label' => __('Ticket Departments'),
                    'type' => 'tags',
                    'default' => ['Support', 'Sales'],
                    'required' => true,
                    'database_type' => 'array',
                ],
                [
                    'name' => 'ticket_client_closing_disabled',
                    'label' => __('Disallow clients from closing tickets'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                // Email piping
                [
                    'name' => 'ticket_mail_piping',
                    'label' => __('Email Piping'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'live' => true,
                ],
                [
                    'name' => 'ticket_mail_host',
                    'label' => __('Email Host'),
                    'type' => 'text',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                ],
                [
                    'name' => 'ticket_mail_port',
                    'label' => __('Email Port'),
                    'type' => 'number',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                    'default' => 993,
                ],
                [
                    'name' => 'ticket_mail_email',
                    'label' => __('Email Address'),
                    'type' => 'email',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                ],
                [
                    'name' => 'ticket_mail_password',
                    'label' => __('Email Password'),
                    'type' => 'password',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                    'encrypted' => true,
                ],
            ],

            'cronjob' => [
                [
                    'name' => 'cronjob_time',
                    'label' => __('Cron Job Time'),
                    'type' => 'time',
                    'default' => '00:00',
                    'required' => true,
                    'description' => __('Time the cron job should run daily.'),
                ],
                [
                    'name' => 'cronjob_invoice',
                    'label' => __('Send invoice if due date is x days away'),
                    'type' => 'number',
                    'default' => 7,
                    'required' => true,
                ],
                [
                    'name' => 'cronjob_invoice_reminder',
                    'label' => __('Send invoice reminder if due date is x days away'),
                    'type' => 'number',
                    'default' => 3,
                    'required' => true,
                ],
                [
                    // Cancel order is pending for x days
                    'name' => 'cronjob_order_cancel',
                    'label' => __('Cancel order if pending for x days'),
                    'type' => 'number',
                    'default' => 7,
                    'required' => true,
                ],
                [
                    'name' => 'cronjob_order_suspend',
                    'label' => __('Suspend server if invoice is x days overdue'),
                    'type' => 'number',
                    'default' => 2,
                    'required' => true,
                ],
                [
                    'name' => 'cronjob_order_terminate',
                    'label' => __('Delete server if invoice is x days overdue (also cancels the invoice)'),
                    'type' => 'number',
                    'default' => 14,
                    'required' => true,
                ],
                [
                    'name' => 'cronjob_delete_email_logs',
                    'label' => __('Delete email logs older than x days'),
                    'type' => 'number',
                    'default' => 90,
                    'required' => true,
                ],
                [
                    'name' => 'cronjob_close_ticket',
                    'label' => __('Close tickets if no response for x days'),
                    'type' => 'number',
                    'default' => 7,
                    'required' => true,
                ],
            ],
            'credits' => [
                [
                    'name' => 'credits_enabled',
                    'label' => __('Credits Enabled'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                [
                    'name' => 'credits_minimum_deposit',
                    'label' => __('Minimum Deposit'),
                    'type' => 'number',
                    'default' => 5,
                    'required' => true,
                ],
                [
                    'name' => 'credits_maximum_deposit',
                    'label' => __('Maximum Deposit'),
                    'type' => 'number',
                    'default' => 100,
                    'required' => true,
                ],
                [
                    'name' => 'credits_maximum_credit',
                    'label' => __('Maximum Credit'),
                    'type' => 'number',
                    'default' => 300,
                    'required' => true,
                ],
                [
                    'name' => 'credits_auto_use',
                    'label' => __('Automatically use credits'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => __('Automatically pay recurring invoices using available credits. (only pays if credits is more or equal to invoice amount)'),
                ],
                [
                    // Enable credits give back if and service is upgraded or downgraded
                    'name' => 'credits_on_downgrade',
                    'label' => __('Enable credits on service downgrade'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => __('Enable giving back credits to users when they downgrade their service. The credits given back will be the prorated difference between the old and new service based on the remaining time in the billing cycle.'),
                ],
            ],
            'theme' => [
                [
                    'name' => 'theme',
                    'label' => __('Theme'),
                    'default' => 'default',
                    'type' => 'select',
                    'required' => true,
                    // Read themes from themes directory
                    'options' => array_map(fn ($path) => ['value' => basename($path), 'label' => __(basename($path))], glob(base_path('themes/*'), GLOB_ONLYDIR)),
                    'validation' => 'in:' . implode(',', array_map('basename', glob(base_path('themes/*'), GLOB_ONLYDIR))),
                    'action' => ResetColorsAction::class,
                ],
            ],
            'invoices' => [
                [
                    'name' => 'bill_to_text',
                    'label' => __('Bill To Text'),
                    'type' => 'textarea',
                    'default' => '',
                ],
                [
                    'name' => 'invoice_number',
                    'label' => __('Invoice Number'),
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => __('The next invoice number to use. This will be incremented automatically.'),
                ],
                [
                    'name' => 'invoice_number_padding',
                    'label' => __('Invoice Number Padding'),
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => __('Number of digits to use for invoice numbers. Example: 0001, 0002, etc.'),
                ],
                [
                    'name' => 'invoice_number_format',
                    'label' => __('Invoice number format'),
                    'type' => 'text',
                    'default' => 'INV-{number}',
                    'required' => false,
                    'description' => __('Format to use for invoice numbers. Use {number} to insert the zero padded number and use {year}, {month} and {day} placeholders to insert the current date. Example: INV-{year}-{month}-{day}-{number} or INV-{year}{number}. It must at least contain {number}.'),
                    'validation' => 'regex:/{number}/',
                ],
                [
                    'name' => 'invoice_proforma',
                    'label' => __('Proforma Invoices'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => __('Proforma invoices will not be assigned an official invoice number until payment is received and will be marked as "Proforma".'),
                ],
                [
                    'name' => 'immutable_invoices_enabled',
                    'label' => __('Immutable Invoices'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => __('When enabled, invoices can only be edited while in draft status. Once published, they become read-only. Disable this to allow editing invoices at any status.'),
                ],
                [
                    'name' => 'notes_client_visible',
                    'label' => __('Show Adjustment Notes to Clients'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => __('Show the adjustments section (credit/debit notes) to clients on the invoice page. When disabled, only admin-only notes are visible in the admin panel.'),
                ],
                [
                    'name' => 'invoice_snapshot',
                    'label' => __('Invoice Snapshot'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => __('Save a snapshot of important data (name, address, etc.) on the invoice when it is paid. This ensures that if someone changes their details later, old invoices will still have the correct information.'),
                ],
                [
                    'name' => 'credit_note_number',
                    'label' => __('Credit/Debit Note Number'),
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => __('The next credit/debit note number to use. This will be incremented automatically.'),
                ],
                [
                    'name' => 'credit_note_number_padding',
                    'label' => __('Credit/Debit Note Number Padding'),
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => __('Number of digits to use for credit/debit note numbers. Example: 0001, 0002, etc.'),
                ],
                [
                    'name' => 'credit_note_number_format',
                    'label' => __('Credit/Debit Note Number Format'),
                    'type' => 'text',
                    'default' => 'CN-{number}',
                    'required' => false,
                    'description' => __('Format to use for credit/debit note numbers. Use {number} to insert the zero padded number and use {year}, {month} and {day} placeholders to insert the current date. Example: CN-{year}-{month}-{day}-{number} or CN-{year}{number}. It must at least contain {number}.'),
                    'validation' => 'regex:/{number}/',
                ],
            ],
            'other' => [
                [
                    'name' => 'gravatar_default',
                    'label' => __('Gravatar Default'),
                    'description' => __('Default image to use when a user does not have a Gravatar. '),
                    'link' => 'https://docs.gravatar.com/general/images/#default-image',
                    'type' => 'select',
                    'options' => [
                        'mp' => __('Mystery Person'),
                        'identicon' => __('Identicon'),
                        'monsterid' => __('Monster'),
                        'wavatar' => __('Wavatar'),
                        'retro' => __('Retro'),
                        'robohash' => __('Robohash'),
                    ],
                    'default' => 'wavatar',
                ],
                [
                    'name' => 'default_currency',
                    'label' => __('Default Currency'),
                    'type' => 'select',
                    'options' => $currencies,
                    'default' => 'CNY',
                    'required' => true,
                ],
                [
                    'name' => 'registration_disabled',
                    'label' => __('Disable User Registration'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => __('Only allow existing users to log in. This will hide the registration page and prevent new users from signing up.'),
                ],
                [
                    'name' => 'pagination',
                    'label' => __('Pagination'),
                    'type' => 'number',
                    'default' => 10,
                    'required' => true,
                    'description' => __('Number of items to show per page'),
                ],
                [
                    'name' => 'debug',
                    'label' => __('Debug Mode'),
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => __('Enable debug mode to log HTTP requests and errors'),
                ],
            ],
        ];

        // Set theme settings
        $settings['theme'] = [...$settings['theme'], ...Theme::getSettings()];

        return $settings;
    }

    private static function getAvailableLanguages(): array
    {
        return once(
            fn () => glob(base_path('lang/*'), GLOB_ONLYDIR)
                ? array_values(array_diff(array_map('basename', glob(base_path('lang/*'), GLOB_ONLYDIR)), ['vendor']))
                : ['en']
        );
    }

    public static function tax(?User $user = null)
    {
        // Use once so the query is only run once
        return once(function () use ($user) {
            $user ??= Auth::user();
            // Get country from user properties
            $country = $user?->properties->where('key', 'country')->value('value') ?? null;

            // Change country to a two-letter country code if it's not already
            if ($country) {
                $country = array_search($country, config('app.countries')) ?: $country;
            }

            $taxRate = TaxRate::whereIn('country', [$country, 'all'])
                ->orderByRaw('country = ? desc', [$country])
                ->first();

            return $taxRate ?: 0;
        });
    }

    public static function settingsObject()
    {
        return (object) json_decode(json_encode(static::settings()));
    }

    public static function getSetting($key)
    {
        $setting = (object) collect(static::settings())->flatten(1)->firstWhere('name', $key);
        $setting->value = Setting::where('settingable_type', null)->where('key', $key)->value('value') ?? $setting->default ?? null;

        return $setting;
    }

    public static function getTelemetry()
    {
        try {
            $uuid = Setting::where('key', 'telemetry_uuid')->value('value');
        } catch (Exception $e) {
            $uuid = null;
        }
        if (is_null($uuid)) {
            $uuid = Uuid::uuid4()->toString();
            try {
                Setting::updateOrCreate(
                    ['key' => 'telemetry_uuid'],
                    ['value' => $uuid]
                );
            } catch (Exception $e) {
                // Avoid errors in workflows
            }
        }

        // Daily fixed time based on UUID
        $time = hexdec(str_replace('-', '', substr($uuid, 27))) % 1440;
        $hour = floor($time / 60);
        $minute = $time % 60;

        return compact('uuid', 'hour', 'minute');
    }

    public static function validateOrCreateVapidKeys(): bool
    {
        $publicKey = config('settings.vapid_public_key');
        $privateKey = config('settings.vapid_private_key');
        if ($publicKey && $privateKey && strlen($publicKey) > 80 && strlen($privateKey) > 40) {
            return true;
        }
        try {
            $vapid = VAPID::createVapidKeys();
            Setting::updateOrCreate(
                ['key' => 'vapid_public_key', 'encrypted' => true],
                ['value' => $vapid['publicKey']]
            );
            Setting::updateOrCreate(
                ['key' => 'vapid_private_key', 'encrypted' => true],
                ['value' => $vapid['privateKey']]
            );

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
