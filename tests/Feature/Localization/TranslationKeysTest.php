<?php

namespace Tests\Feature\Localization;

use App\Support\Locale;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Fails when a translation is missing in en or nl, when a key used in the
 * code does not exist, or when the placeholders of a key differ.
 */
class TranslationKeysTest extends TestCase
{
    /** Searched for __('…'), trans('…'), trans_choice('…') and @lang('…'). */
    private const SOURCES = ['resources/views', 'app', 'resources/js'];

    public function test_english_and_dutch_have_the_same_files_and_keys(): void
    {
        $files = fn (string $locale) => collect(File::files(lang_path($locale)))->map->getFilenameWithoutExtension()->sort()->values()->all();
        $this->assertSame($files('en'), $files('nl'), 'lang/en and lang/nl must contain the same files.');

        foreach ($files('en') as $file) {
            $en = array_keys(Arr::dot(require lang_path("en/{$file}.php")));
            $nl = array_keys(Arr::dot(require lang_path("nl/{$file}.php")));

            $this->assertSame([], array_values(array_diff($en, $nl)), "Missing in lang/nl/{$file}.php");
            $this->assertSame([], array_values(array_diff($nl, $en)), "Missing in lang/en/{$file}.php");
        }
    }

    public function test_values_are_filled_and_keep_their_placeholders(): void
    {
        foreach (collect(File::files(lang_path('en')))->map->getFilenameWithoutExtension() as $file) {
            $en = Arr::dot(require lang_path("en/{$file}.php"));
            $nl = Arr::dot(require lang_path("nl/{$file}.php"));

            foreach ($en as $key => $value) {
                if (is_array($value) && $value === []) {
                    continue;
                }

                $this->assertNotSame('', trim((string) $value), "Empty: en {$file}.{$key}");
                $this->assertNotSame('', trim((string) ($nl[$key] ?? '')), "Empty: nl {$file}.{$key}");
                $this->assertSame($this->placeholders((string) $value), $this->placeholders((string) ($nl[$key] ?? '')), "Placeholders differ: {$file}.{$key}");
            }
        }
    }

    public function test_every_key_used_in_the_code_exists_in_both_languages(): void
    {
        $missing = [];

        foreach ($this->usedKeys() as [$key, $dynamic, $where]) {
            foreach (Locale::SUPPORTED as $locale) {
                $exists = $dynamic
                    // __('status.'.$code): the group before the variable part must exist.
                    ? is_array(Lang::get(rtrim($key, '.'), [], $locale, false))
                    : Lang::hasForLocale($key, $locale);

                if (! $exists) {
                    $missing[] = "{$locale}: {$key} ({$where})";
                }
            }
        }

        $this->assertSame([], $missing, "Missing translations:\n".implode("\n", $missing));
    }

    public function test_the_scanner_finds_keys(): void
    {
        // Guards against a regex that silently matches nothing.
        $keys = array_column($this->usedKeys(), 0);

        $this->assertContains('nav.main', $keys);
        $this->assertContains('auth.login.heading', $keys);
        $this->assertContains('status_page.headline.', $keys);
    }

    /** @return list<array{string, bool, string}> */
    private function usedKeys(): array
    {
        $found = [];
        $pattern = '/(?:__|trans|trans_choice|@lang|Lang::get|Lang::has)\(\s*([\'"])([a-z0-9_]+\.[a-z0-9_.\-]*)\1(\s*\.)?/i';

        foreach (self::SOURCES as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                if (! preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $dynamic = isset($match[3]) && $match[3] !== '' || str_ends_with($match[2], '.');
                    $found[] = [$match[2], $dynamic, $file->getRelativePathname()];
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function placeholders(string $value): array
    {
        preg_match_all('/:([a-z_]+)/i', $value, $matches);
        $names = array_map('strtolower', $matches[1]);
        sort($names);

        return $names;
    }
}
