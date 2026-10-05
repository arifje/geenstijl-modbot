<?php

namespace App\Enums;

enum Priority: string
{
    case High = 'high';
    case Medium = 'medium';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Hoog',
            self::Medium => 'Middel',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::High => '🔴',
            self::Medium => '🟠',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Medium => 1,
        };
    }
}
