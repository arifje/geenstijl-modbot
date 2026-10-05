<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Analysis\AnthropicLanguageModel;
use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\PromptRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromptRepository::class);

        $this->app->bind(LanguageModel::class, function () {
            $config = config('modbot.llm');

            return new AnthropicLanguageModel(
                client: new Client(apiKey: $config['api_key']),
                model: $config['model'],
                effort: $config['effort'],
                maxTokens: $config['max_tokens'],
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
