<?php

namespace Tests\Unit;

use App\Services\Analysis\CommentAnalyzer;
use Tests\TestCase;

class QuoteVerificationTest extends TestCase
{
    private function verify(string $quote, string $content): string
    {
        return app(CommentAnalyzer::class)->verifiedQuote($quote, $content);
    }

    public function test_it_keeps_a_literal_quote(): void
    {
        $this->assertSame('ik weet waar je woont', $this->verify('ik weet waar je woont', "Pas op,\nik  weet waar je woont."));
    }

    public function test_it_accepts_omissions(): void
    {
        $this->assertSame('Pas op... woont', $this->verify('Pas op... woont', 'Pas op, ik weet waar je woont.'));
    }

    public function test_it_falls_back_to_the_comment_when_the_quote_is_made_up(): void
    {
        $this->assertSame('Pas op, ik weet waar je woont.', $this->verify('ik vermoord je', 'Pas op, ik weet waar je woont.'));
    }

    public function test_it_keeps_masked_quotes(): void
    {
        $this->assertSame('Hij woont op [afgeschermd]', $this->verify('Hij woont op [afgeschermd]', 'Hij woont op Dorpsstraat 1'));
    }

    public function test_it_limits_length(): void
    {
        $this->assertSame(163, mb_strlen($this->verify('', str_repeat('a', 300))));
    }
}
