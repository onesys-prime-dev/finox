<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function make(): Password
    {
        return Password::min((int) Settings::get('password_min_length', 10))->letters()->mixedCase()->numbers();
    }
}
