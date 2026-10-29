<?php

namespace App\Releases;

/**
 * Reads the entry list of a release tarball (tar.gz) without extracting
 * it, and refuses anything but the expected regular files and their two
 * directories: no links, devices, pax headers, absolute paths or "..".
 */
final class ReleaseArchive
{
    private const FILE = '/\A(?:requirements\.txt|serverscope-agent\.service|serverscope_agent\/[a-z_]{1,40}\.py|updater\/serverscope-updater(?:\.service|\.timer)?)\z/';

    private const DIRECTORY = '/\A(?:serverscope_agent|updater)\/\z/';

    public const REQUIRED = [
        'requirements.txt',
        'serverscope-agent.service',
        'serverscope_agent/__init__.py',
        'serverscope_agent/agent.py',
        'serverscope_agent/collector.py',
        'updater/serverscope-updater',
        'updater/serverscope-updater.service',
        'updater/serverscope-updater.timer',
    ];

    /** @return array<int, string> the regular files, sorted */
    public static function entries(string $tarball): array
    {
        $tar = @gzdecode($tarball, 4 * ReleaseManifest::MAX_SIZE);

        if (! is_string($tar) || strlen($tar) % 512 !== 0) {
            throw new InvalidRelease('bad_archive', 'Geen geldig tar.gz-bestand.');
        }

        $files = [];
        $offset = 0;

        while ($offset + 512 <= strlen($tar)) {
            $header = substr($tar, $offset, 512);

            if ($header === str_repeat("\0", 512)) {
                break;
            }

            if (substr($header, 257, 5) !== 'ustar' || ! self::checksumMatches($header)) {
                throw new InvalidRelease('bad_archive', 'Ongeldige tar-header.');
            }

            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $type = $header[156];
            $size = octdec(trim(substr($header, 124, 12), "\0 "));

            if ($prefix !== '') {
                throw new InvalidRelease('bad_archive', 'Onverwacht pad.');
            }

            if ($type === '5' && preg_match(self::DIRECTORY, $name) === 1 && $size === 0) {
                $offset += 512;

                continue;
            }

            if (! in_array($type, ['0', "\0"], true) || preg_match(self::FILE, $name) !== 1 || isset($files[$name])) {
                throw new InvalidRelease('bad_archive', 'Onverwacht bestand: '.substr(preg_replace('/[^\x20-\x7e]/', '?', $name), 0, 100));
            }

            $files[$name] = true;
            $offset += 512 + (int) (ceil($size / 512) * 512);
        }

        $missing = array_diff(self::REQUIRED, array_keys($files));

        if ($missing !== []) {
            throw new InvalidRelease('bad_archive', 'Ontbrekend bestand: '.implode(', ', $missing));
        }

        $names = array_keys($files);
        sort($names, SORT_STRING);

        return $names;
    }

    private static function checksumMatches(string $header): bool
    {
        $stored = octdec(trim(substr($header, 148, 8), "\0 "));
        $sum = array_sum(array_map('ord', str_split(substr_replace($header, '        ', 148, 8))));

        return $stored === $sum;
    }
}
