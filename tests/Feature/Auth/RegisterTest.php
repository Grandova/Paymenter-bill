<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Register;
use App\Mail\SystemMail;
use App\Models\CustomProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail as MailFacade;
use Livewire\Livewire;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['settings.captcha' => 'disabled']);
    }

    /**
     * Test if the register page renders successfully
     */
    public function test_renders_successfully()
    {
        $this->withoutVite();

        $response = $this->get(route('register'));

        $response->assertStatus(200);
    }

    public function test_legacy_custom_properties_are_not_shown_on_registration(): void
    {
        CustomProperty::create([
            'name' => 'Phone',
            'key' => 'phone',
            'type' => 'string',
            'model' => 'App\\Models\\User',
            'non_editable' => false,
            'required' => true,
            'show_on_invoice' => false,
        ]);

        Livewire::test(Register::class)
            ->assertDontSee('Phone')
            ->assertDontSee('properties.phone');
    }

    /**
     * Test if the user can register with valid credentials
     */
    public function test_can_register_with_valid_credentials()
    {
        $response = Livewire::test(Register::class)
            ->set('first_name', 'Corwin')
            ->set('last_name', 'Corwin')
            ->set('email', 'corwin@paymenter.org')
            ->set('password', 'passw0rd')
            ->set('password_confirmation', 'passw0rd')
            ->call('submit');

        $response->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'corwin@paymenter.org',
        ]);
    }

    public function test_registration_requires_an_email_code_when_enabled(): void
    {
        config(['settings.mail_verify_on_register' => true, 'settings.mail_disable' => false, 'settings.captcha' => 'disabled']);
        MailFacade::fake();

        $component = Livewire::test(Register::class)
            ->set('first_name', 'Corwin')
            ->set('last_name', 'Corwin')
            ->set('email', 'corwin@paymenter.org')
            ->set('password', 'passw0rd')
            ->set('password_confirmation', 'passw0rd')
            ->assertSee('获取邮箱验证码')
            ->assertSee('邮箱验证码');

        $component->call('sendVerificationCode')->assertHasNoErrors();

        $code = null;
        MailFacade::assertQueued(SystemMail::class, function (SystemMail $mail) use (&$code) {
            preg_match('/<strong>(\d{6})<\/strong>/', $mail->mail['body'], $matches);
            $code = $matches[1] ?? null;

            return true;
        });
        $this->assertNotNull($code);

        $wrongCode = $code === '000000' ? '000001' : '000000';
        $component->set('verification_code', $wrongCode)->call('submit')->assertHasErrors('verification_code');
        $this->assertDatabaseMissing('users', ['email' => 'corwin@paymenter.org']);

        $component->set('verification_code', $code)->call('submit')->assertHasNoErrors();

        $this->assertTrue(User::where('email', 'corwin@paymenter.org')->firstOrFail()->hasVerifiedEmail());
    }

    /**
     * Test if the user can't register with invalid credentials
     */
    public function test_cant_register_with_invalid_credentials()
    {
        $response = Livewire::test(Register::class)
            ->set('first_name', 'Corwin')
            ->set('last_name', 'Corwin')
            ->set('email', 'corwin@paymenter.org')
            ->set('password', 'passw0rd')
            ->set('password_confirmation', 'pswrd')
            ->call('submit');

        $response->assertHasErrors('password');
    }

    /**
     * Test if non-optional fields are required
     */
    public function test_name_and_email_fields_are_required()
    {
        $response = Livewire::test(Register::class)
            ->set('password', 'passw0rd')
            ->set('password_confirmation', 'passw0rd')
            ->call('submit');

        $response->assertHasErrors('first_name')
            ->assertHasErrors('last_name')
            ->assertHasErrors('email');
    }
}
