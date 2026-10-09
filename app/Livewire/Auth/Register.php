<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\Login;
use App\Attributes\DisabledIf;
use App\Helpers\NotificationHelper;
use App\Livewire\Component;
use App\Models\User;
use App\Traits\Captchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

#[DisabledIf('registration_disabled')]
class Register extends Component
{
    use Captchable;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $verification_code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $tos = false;

    public function rules()
    {
        $rules = [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];

        if (config('settings.tos')) {
            $rules['tos'] = 'accepted';
        }

        if (config('settings.mail_verify_on_register')) {
            $rules['verification_code'] = 'required|digits:6';
        }

        return $rules;
    }

    public function sendVerificationCode(): void
    {
        if (!config('settings.mail_verify_on_register')) {
            return;
        }

        $this->captcha();
        $this->validateOnly('email', ['email' => 'required|email|max:255|unique:users']);

        if (config('settings.mail_disable')) {
            $this->addError('email', __('Email is unavailable. Please contact the site administrator.'));

            return;
        }

        $email = mb_strtolower(trim($this->email));
        $emailKey = hash('sha256', $email);
        $sendRateKey = 'register-email-code-send:' . hash('sha256', $email . '|' . request()->ip());
        $ipRateKey = 'register-email-code-ip:' . hash('sha256', request()->ip());

        if (RateLimiter::tooManyAttempts($sendRateKey, 1) || RateLimiter::tooManyAttempts('register-email-code-hourly:' . $emailKey, 5) || RateLimiter::tooManyAttempts($ipRateKey, 10)) {
            $this->addError('email', __('Too many attempts. Try again later.'));

            return;
        }

        RateLimiter::hit($sendRateKey, 60);
        RateLimiter::hit('register-email-code-hourly:' . $emailKey, 3600);
        RateLimiter::hit($ipRateKey, 3600);

        $code = (string) random_int(100000, 999999);
        Cache::put('register-email-code:' . $emailKey, hash('sha256', $code), now()->addMinutes(10));

        NotificationHelper::sendSystemEmailNotification(
            '注册邮箱验证码',
            '<p>您的注册验证码是：<strong>' . $code . '</strong></p><p>验证码将在 10 分钟后失效，请勿向他人透露。</p>',
            email: $this->email,
        );

        $this->notify(__('Verification code sent.'));
    }

    public function submit(Login $login)
    {
        $this->captcha();

        $this->validate();

        $emailVerifiedAt = null;
        if (config('settings.mail_verify_on_register')) {
            $emailKey = hash('sha256', mb_strtolower(trim($this->email)));
            $codeKey = 'register-email-code:' . $emailKey;
            $attemptKey = 'register-email-code-attempt:' . $emailKey;

            if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
                $this->addError('verification_code', __('Too many attempts. Try again later.'));

                return;
            }

            if (!hash_equals((string) Cache::get($codeKey, ''), hash('sha256', $this->verification_code))) {
                RateLimiter::hit($attemptKey, 600);
                $this->addError('verification_code', __('Invalid code.'));

                return;
            }

            Cache::forget($codeKey);
            RateLimiter::clear($attemptKey);
            $emailVerifiedAt = now();
        }

        $user = User::create([
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'email_verified_at' => $emailVerifiedAt,
        ]);

        $login->execute($user);

        return $this->redirectIntended(route('dashboard'), true);
    }

    public function render()
    {
        return view('auth.register');
    }
}
