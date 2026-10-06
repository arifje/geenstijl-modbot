<?php

namespace App\Providers;

use App\Mail\AhaSendTransport;
use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\LanguageModelFactory;
use App\Services\Analysis\PromptRepository;
use App\Services\Reporting\AhaSendMailer;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromptRepository::class);

        // Resolved per run, so a provider switched in the admin is used by the next run.
        $this->app->bind(LanguageModel::class, fn () => app(LanguageModelFactory::class)->make());
    }

    public function boot(): void
    {
        Settings::apply();

        // MAIL_MAILER=ahasend sends Laravel mail (login codes) through the AhaSend API.
        Mail::extend('ahasend', fn () => new AhaSendTransport(app(AhaSendMailer::class)));
    }
}
