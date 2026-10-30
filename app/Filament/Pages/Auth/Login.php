<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Carbon\CarbonInterval;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Login with password + emailed code (default), or with only an emailed code
 * when password login is switched off under Settings.
 */
class Login extends BaseLogin
{
    public static function passwordLoginEnabled(): bool
    {
        return (bool) config('modbot.auth.password_login');
    }

    public function form(Schema $schema): Schema
    {
        if (static::passwordLoginEnabled()) {
            return parent::form($schema);
        }

        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getRememberFormComponent(),
        ]);
    }

    public function authenticate(): ?LoginResponse
    {
        $keys = $this->lockoutKeys();

        if ($this->lockedOut($keys)) {
            return null;
        }

        try {
            $response = $this->attemptLogin();
        } catch (ValidationException $e) {
            $this->recordFailure($keys);

            throw $e;
        }

        if ($response !== null) {
            RateLimiter::clear($keys['email']);
        }

        return $response;
    }

    /**
     * Lockout counters: one per email address (stops guessing one account
     * from many IPs) and one per IP address (stops trying many accounts).
     *
     * @return array{email: string, ip: string}
     */
    private function lockoutKeys(): array
    {
        $email = mb_strtolower(trim((string) ($this->data['email'] ?? '')));

        return [
            'email' => 'login-failures:email:'.sha1($email),
            'ip' => 'login-failures:ip:'.request()->ip(),
        ];
    }

    /**
     * @param  array{email: string, ip: string}  $keys
     */
    private function lockedOut(array $keys): bool
    {
        $max = max(1, (int) config('modbot.auth.max_failed_logins'));

        $seconds = max(
            RateLimiter::tooManyAttempts($keys['email'], $max) ? RateLimiter::availableIn($keys['email']) : 0,
            RateLimiter::tooManyAttempts($keys['ip'], $max * 4) ? RateLimiter::availableIn($keys['ip']) : 0,
        );

        if ($seconds === 0) {
            return false;
        }

        $this->userUndertakingMultiFactorAuthentication = null;

        Notification::make()
            ->title('Too many failed login attempts')
            ->body('Try again in '.CarbonInterval::seconds($seconds)->cascade()->forHumans(['parts' => 1]).'.')
            ->danger()
            ->send();

        return true;
    }

    /**
     * @param  array{email: string, ip: string}  $keys
     */
    private function recordFailure(array $keys): void
    {
        $max = max(1, (int) config('modbot.auth.max_failed_logins'));
        $decay = max(1, (int) config('modbot.auth.lockout_minutes')) * 60;

        $emailFailures = RateLimiter::hit($keys['email'], $decay);
        $ipFailures = RateLimiter::hit($keys['ip'], $decay);

        if ($emailFailures === $max || $ipFailures === $max * 4) {
            Log::warning('Login locked after too many failed attempts', [
                'email' => $this->data['email'] ?? null,
                'ip' => request()->ip(),
                'minutes' => $decay / 60,
            ]);
        }
    }

    private function attemptLogin(): ?LoginResponse
    {
        if (static::passwordLoginEnabled()) {
            return parent::authenticate();
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $remember = $data['remember'] ?? false;

        // Same timing for known and unknown addresses.
        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($data): User {
            $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();

            if (! $user || ! $this->isUserAllowedToAccessPanel($user)) {
                $this->userUndertakingMultiFactorAuthentication = null;
                $this->throwFailureValidationException();
            }

            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));

        // Same payload as Filament's own login, so its challenge form can find the user.
        $pending = $this->getUserUndertakingMultiFactorAuthenticationData();
        $userKey = Filament::getUserScopedAuthIdentifier($user);
        $challengeStarted = $pending
            && hash_equals($pending['userKey'], $userKey)
            && $this->getUserUndertakingMultiFactorAuthentication();

        // Step 1: send the code and show the code screen.
        if (! $challengeStarted) {
            $challenge = $this->getMultiFactorChallenge();
            $provider = $challenge->getFirstEnabledProvider($user) ?? $this->throwFailureValidationException();

            $this->userUndertakingMultiFactorAuthentication = encrypt([
                'identifier' => $user->getAuthIdentifier(),
                'userKey' => $userKey,
            ]);
            $challenge->beforeChallenge($user, $provider);
            $this->cacheSchema('multiFactorChallengeForm', null);
            $this->multiFactorChallengeForm->fill();

            return null;
        }

        // Step 2: check the code (validation rules of the email provider).
        if ($this->isMultiFactorChallengeRateLimited($user)) {
            return null;
        }

        $this->cacheSchema('multiFactorChallengeForm', null);

        // Fail closed: without the code field there is nothing that checks the code.
        if (blank($this->multiFactorChallengeForm->getComponents())) {
            $this->userUndertakingMultiFactorAuthentication = null;
            $this->throwFailureValidationException();
        }

        $this->multiFactorChallengeForm->validate();

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (blank($this->userUndertakingMultiFactorAuthentication) && ! static::passwordLoginEnabled()) {
            return 'Enter your email address and we\'ll send you a login code.';
        }

        return parent::getSubheading();
    }
}
