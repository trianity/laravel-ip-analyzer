<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Support;

use Illuminate\Translation\Translator;

/** @internal Presentation-only translations; never changes application locale settings. */
final class Messages
{
    public function __construct(private readonly Translator $translator) {}

    /** @param array<string, string|int|float> $replace */
    public function get(string $key, array $replace = []): string
    {
        $key = 'ip-analyzer::messages.'.$key;
        $text = $this->translator->get($key, $replace, $this->locale($key), false);

        return is_string($text) ? $text : $key;
    }

    /** @param array<string, string|int|float> $replace */
    public function choice(string $key, int $count, array $replace = []): string
    {
        $key = 'ip-analyzer::messages.'.$key;
        $locale = $this->locale($key);
        // Do not let choice() consult the application's fallback for an unknown key.
        if (! $this->translator->has($key, $locale, false)) {
            return $key;
        }

        return $this->translator->choice($key, $count, $replace, $locale);
    }

    private function locale(string $key): string
    {
        $locale = $this->translator->getLocale();

        return $this->translator->has($key, $locale, false) ? $locale : 'en';
    }
}
