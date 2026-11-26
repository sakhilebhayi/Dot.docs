<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Notifications\Account\EmailAddressChanged;
use App\Support\CentralSignIn;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
        ])->validateWithBag('updateProfileInformation');

        // With central sign-in on, the address is InfoDot's: changing it here
        // would only make the two disagree (EA-3 / E-03).
        if (mb_strtolower($input['email']) !== mb_strtolower($user->email) && CentralSignIn::active()) {
            throw ValidationException::withMessages([
                'email' => __('Your email address is managed by your InfoDot account. Change it there.'),
            ])->errorBag('updateProfileInformation');
        }

        $oldEmail = $user->email;

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }

        // The OLD inbox hears about it, so a hijacked session cannot move
        // the account quietly (EA-3 / E-03).
        if (mb_strtolower($oldEmail) !== mb_strtolower($user->email)) {
            Notification::route('mail', $oldEmail)->notify(new EmailAddressChanged($user->email));
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
