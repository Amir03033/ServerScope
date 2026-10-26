<?php

namespace App\Services\Websites;

/**
 * Minimal robots.txt parser (RFC 9309): Sitemap declarations and the
 * Allow/Disallow rules of the group that applies to our user agent
 * ("serverscope", otherwise "*"). The longest matching rule wins; on a
 * tie Allow wins. Supports the * and $ wildcards.
 */
final class RobotsTxt
{
    /** @var array<int, string> */
    public array $sitemaps = [];

    /** @var array<int, array{allow: bool, pattern: string}> */
    private array $rules = [];

    public static function parse(string $content, string $agent = 'serverscope'): self
    {
        $robots = new self;
        $groups = [];
        $lastWasAgent = false;

        foreach (preg_split('/\R/', substr($content, 0, 512_000)) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if ($value !== '') {
                    $robots->sitemaps[] = $value;
                }

                continue;
            }

            if ($field === 'user-agent') {
                // Consecutive user-agent lines share one group.
                if ($lastWasAgent && $groups !== []) {
                    $groups[array_key_last($groups)]['agents'][] = strtolower($value);
                } else {
                    $groups[] = ['agents' => [strtolower($value)], 'rules' => []];
                }

                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;

            if (in_array($field, ['allow', 'disallow'], true) && $groups !== []) {
                $groups[array_key_last($groups)]['rules'][] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
        }

        $byAgent = [];

        foreach ($groups as $group) {
            foreach (array_unique($group['agents']) as $name) {
                $byAgent[$name] = [...($byAgent[$name] ?? []), ...$group['rules']];
            }
        }

        $rules = null;

        foreach ($byAgent as $name => $groupRules) {
            if ($name !== '*' && str_contains($agent, $name)) {
                $rules = [...($rules ?? []), ...$groupRules];
            }
        }

        $robots->rules = array_values(array_filter($rules ?? $byAgent['*'] ?? [], fn ($r) => ! (! $r['allow'] && $r['pattern'] === '')));
        $robots->sitemaps = array_values(array_unique($robots->sitemaps));

        return $robots;
    }

    public function isAllowed(string $pathAndQuery): bool
    {
        $best = null;

        foreach ($this->rules as $rule) {
            if ($rule['pattern'] === '' || ! $this->matches($rule['pattern'], $pathAndQuery)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '/'));

        return preg_match('/^'.$regex.($anchored ? '$' : '').'/', $path) === 1;
    }
}
