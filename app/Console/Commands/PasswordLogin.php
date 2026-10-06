<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('modbot:password-login {state : on or off}')]
#[Description('Turn password login on or off (e.g. when login emails are not arriving)')]
class PasswordLogin extends Command
{
    public function handle(): int
    {
        $state = $this->argument('state');

        if (! in_array($state, ['on', 'off'], true)) {
            $this->components->error('Use "on" or "off".');

            return self::FAILURE;
        }

        Settings::save(['password_login' => $state === 'on']);

        $this->components->info($state === 'on'
            ? 'Password login is on: password + emailed code.'
            : 'Password login is off: users log in with only an emailed code.');

        return self::SUCCESS;
    }
}
