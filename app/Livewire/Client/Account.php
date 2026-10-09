<?php

namespace App\Livewire\Client;

use App\Helpers\NotificationHelper;
use App\Livewire\Component;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Account extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public function mount()
    {
        $user = Auth::user();

        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
    }

    public function rules()
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . Auth::id(),
        ];
    }

    public function submit()
    {
        $validatedData = $this->validate();

        /** @var User $user */
        $user = Auth::user();
        $user->update($validatedData);

        // If email was changed, we should mark it as unverified and send a new verification email
        if ($user->wasChanged('email')) {
            $user->email_verified_at = null;
            $user->save();
            NotificationHelper::emailVerificationNotification($user);
        }

        $this->notify(__('Account updated successfully.'));
    }

    public function render()
    {
        return view('client.account.index')->layoutData([
            'title' => __('Account'),
        ]);
    }
}
