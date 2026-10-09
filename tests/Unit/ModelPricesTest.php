<?php

namespace Tests\Unit;

use App\Support\ModelPrices;
use Tests\TestCase;

class ModelPricesTest extends TestCase
{
    public function test_built_in_prices_include_haiku_5_5(): void
    {
        $this->assertSame([0.10, 0.50], ModelPrices::for('claude-haiku-5-5'));
    }

    public function test_all_providers_are_covered(): void
    {
        $this->assertSame([10.0, 50.0], ModelPrices::for('claude-fable-5-1'));
        $this->assertSame([0.10, 0.50], ModelPrices::for('gpt-6-luna'));
        $this->assertSame([0.30, 2.50], ModelPrices::for('gemini-2.5-flash'));
        $this->assertSame([0.30, 2.50], ModelPrices::for('models/gemini-2.5-flash'));
    }

    public function test_dated_model_ids_use_the_price_of_their_model(): void
    {
        $this->assertSame([1.0, 5.0], ModelPrices::for('claude-haiku-4-5-20251001'));
        $this->assertSame([4.0, 20.0], ModelPrices::for('claude-opus-5-5-20260901'));
        $this->assertSame([2.50, 10.0], ModelPrices::for('gpt-4o-2024-08-06'));
        // A dated id with its own price keeps it.
        $this->assertSame([5.0, 15.0], ModelPrices::for('gpt-4o-2024-05-13'));
    }

    public function test_unknown_models_have_no_price(): void
    {
        $this->assertNull(ModelPrices::for('some-new-model'));
        $this->assertNull(ModelPrices::for(null));
        // A newer version does not get the price of an older one.
        $this->assertNull(ModelPrices::for('claude-opus-4-9'));
        $this->assertNull(ModelPrices::for('claude-haiku-4-55'));
    }

    public function test_prices_from_settings_extend_and_override(): void
    {
        config(['modbot.llm.price_overrides' => [
            ['model' => 'my-local-model', 'input' => '0.25', 'output' => '2'],
            ['model' => 'claude-haiku-5-5', 'input' => 0.2, 'output' => 1],
            ['model' => '', 'input' => 1, 'output' => 1],
        ]]);

        $this->assertSame([0.25, 2.0], ModelPrices::for('my-local-model'));
        $this->assertSame([0.2, 1.0], ModelPrices::for('claude-haiku-5-5'));
    }
}
