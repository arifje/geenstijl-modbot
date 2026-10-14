<?php

namespace App\Support;

/**
 * Minimal Composer constraint check for security advisories, which use
 * comparisons like ">=13.0.0,<13.30.0|<12.69.0" ("," = and, "|" = or).
 * Unknown syntax never matches, so it cannot cause a false alarm.
 */
class VersionConstraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);

        if ($version === null) {
            return false;
        }

        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) as $alternative) {
            $parts = preg_split('/\s*,\s*|\s+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY);

            if ($parts !== [] && collect($parts)->every(fn (string $part) => self::matches($version, $part))) {
                return true;
            }
        }

        return false;
    }

    /**
     * "v13.34.0" → "13.34.0"; null for dev branches and other non-releases.
     */
    public static function normalize(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');

        return preg_match('/^\d+(\.\d+){0,3}(-?(alpha|beta|rc|RC|patch|p)\.?\d*)?$/', $version) ? $version : null;
    }

    public static function isStable(string $version): bool
    {
        return (bool) preg_match('/^v?\d+(\.\d+){0,3}$/', trim($version));
    }

    private static function matches(string $version, string $atom): bool
    {
        if (! preg_match('/^(>=|<=|>|<|==|=|!=)?\s*v?(\d+(\.\d+){0,3}(-?\w+)?)$/', $atom, $m)) {
            return false;
        }

        return version_compare($version, $m[2], match ($m[1] ?: '==') {
            '=' => '==',
            default => $m[1] ?: '==',
        });
    }
}
