<?php

namespace Tests\Fakes;

use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\LlmResult;

class FakeLanguageModel implements LanguageModel
{
    /** @var list<array{system: string, input: string, schema: ?array}> */
    public array $calls = [];

    /**
     * @param  \Closure(string $input, ?array $schema): string  $respond
     */
    public function __construct(private \Closure $respond) {}

    public function complete(string $system, string $input, ?array $schema = null): LlmResult
    {
        $this->calls[] = compact('system', 'input', 'schema');

        return new LlmResult(($this->respond)($input, $schema), 'fake-model', 100, 10);
    }

    public function name(): string
    {
        return 'fake-model';
    }
}
