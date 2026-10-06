<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Timebox;

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

        $challengeStarted = filled($this->userUndertakingMultiFactorAuthentication)
            && decrypt($this->userUndertakingMultiFactorAuthentication) === $user->getAuthIdentifier();

        // Step 1: send the code and show the code screen.
        if (! $challengeStarted) {
            $challenge = $this->getMultiFactorChallenge();
            $provider = $challenge->getFirstEnabledProvider($user) ?? $this->throwFailureValidationException();

            $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());
            $challenge->beforeChallenge($user, $provider);
            $this->multiFactorChallengeForm->fill();

            return null;
        }

        // Step 2: check the code (validation rules of the email provider).
        if ($this->isMultiFactorChallengeRateLimited($user)) {
            return null;
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
