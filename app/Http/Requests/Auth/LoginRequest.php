<?php

namespace App\Http\Requests\Auth;

use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Authenticate with per-account lockout, deactivation check and login history.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $email = mb_strtolower($this->string('email'));
        $user = User::where('email', $email)->first();

        // Per-IP throttle so one address cannot spray passwords across many accounts.
        $ipKey = 'login-ip:'.$this->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '.ceil(RateLimiter::availableIn($ipKey) / 60).' minute(s).',
            ]);
        }

        $valid = $user && Hash::check($this->string('password'), $user->password);

        if ($user?->locked_until && $user->locked_until->isFuture()) {
            $this->history($user, $email, 'locked');
            RateLimiter::hit($ipKey, 60);
            // Only someone who knows the password is told the account is locked (no account enumeration).
            if (! $valid) {
                throw ValidationException::withMessages(['email' => trans('auth.failed')]);
            }
            throw ValidationException::withMessages([
                'email' => 'This account is temporarily locked. Try again in '.max(1, (int) ceil(now()->diffInSeconds($user->locked_until, true) / 60)).' minute(s).',
            ]);
        }

        if (! $valid) {
            RateLimiter::hit($ipKey, 60);
            if ($user) {
                $count = $user->failed_login_count + 1;
                $max = (int) Settings::get('lockout_attempts', 5);
                $user->forceFill([
                    'failed_login_count' => $count >= $max ? 0 : $count,
                    'locked_until' => $count >= $max ? now()->addMinutes((int) Settings::get('lockout_minutes', 15)) : null,
                ])->save();
            }
            $this->history($user, $email, 'failed');
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        if (! $user->is_active) {
            $this->history($user, $email, 'failed');
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Contact an administrator.']);
        }

        RateLimiter::clear($ipKey);
        Auth::login($user, $this->boolean('remember'));
        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        $this->history($user, $email, 'success');
    }

    private function history(?User $user, string $email, string $event): void
    {
        LoginHistory::create([
            'user_id' => $user?->id,
            'email' => $email,
            'event' => $event,
            'ip' => $this->ip(),
            'user_agent' => substr((string) $this->userAgent(), 0, 255),
        ]);
    }
}
