<?php

namespace App\Actions\Fortify;

use App\Models\Events\EventRegistration;
use App\Models\Shop\Order;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);

        // Claim any guest registrations and orders made with this email
        EventRegistration::where('guest_email', $user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id, 'guest_name' => null, 'guest_email' => null]);

        Order::where('guest_email', $user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id, 'guest_name' => null, 'guest_email' => null]);

        return $user;
    }
}
