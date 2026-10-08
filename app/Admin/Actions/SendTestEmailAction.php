<?php

namespace App\Admin\Actions;

use App\Mail\SystemMail;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendTestEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendTestEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Send Test Email'))
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Send Test Email'))
            ->modalDescription(__('Send a test email to verify your mail configuration.'))
            ->form([
                TextInput::make('recipient')
                    ->label(__('Recipient Email'))
                    ->email()
                    ->required()
                    ->default(fn () => auth()->user()?->email),
            ])
            ->action(function (array $data) {
                Gate::authorize('has-permission', 'admin.settings.update');

                try {
                    Mail::to($data['recipient'])->send(new SystemMail([
                        'subject' => __('Test Email from ') . config('app.name'),
                        'body' => '<p>' . __('This is a test email to verify your mail configuration is working correctly.') . '</p>',
                    ]));

                    Notification::make()
                        ->title(__('Test email sent to ') . $data['recipient'])
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    $hint = self::getMailErrorHint($e);

                    Notification::make()
                        ->title(__('Failed to send test email'))
                        ->body(\Str::markdown($hint))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    private static function getMailErrorHint(Throwable $e): string
    {
        $message = $e->getMessage();

        // Common error patterns and user-friendly messages
        $patterns = [
            '/auth(entication)? failed|could not authenticate|\b535\b/i' => __('Authentication failed. Please check your Mail Username and Password.'),
            '/connection timed out/i' => __('Connection to mail server timed out. Check your Mail Host and Port settings.'),

            '/connection refused/i' => __('Connection to mail server was refused. Verify your Mail Host and Port are correct.'),

            '/could not connect to host/i' => __('Could not connect to mail server. Check your Mail Host and network connection.'),

            '/ssl.*handshake|stream_socket_enable_crypto|certificate/i' => __('SSL/TLS error. Try changing Mail Encryption (TLS/SSL) or check certificates.'),

            '/STARTTLS/i' => __('Mail server requires STARTTLS. Enable TLS encryption.'),

            '/getaddrinfo|name or service not known|temporary failure in name resolution/i' => __('Could not resolve mail server address. Check your Mail Host setting.'),

            '/invalid address|\b550\b/i' => __('Invalid email address. Check the recipient or sender address.'),
        ];

        foreach ($patterns as $pattern => $friendlyMessage) {
            if (preg_match($pattern, $message)) {
                return $friendlyMessage . "\n\n" . __('Technical details: ') . $message;
            }
        }

        // Default message with technical details
        return __('Mail sending failed. Please check your mail configuration settings.') . "\n\n" . __('Error: ') . $message;
    }
}
