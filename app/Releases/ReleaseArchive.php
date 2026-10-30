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

        if (! is_string($tar)) {
            throw new InvalidRelease('bad_archive', 'Geen geldig tar.gz-bestand.');
        }

        $files = [];

        foreach (self::read($tar, 'bad_archive') as [$name, $type]) {
            if ($type === '5' && preg_match(self::DIRECTORY, $name) === 1) {
                continue;
            }

            if ($type !== '0' || preg_match(self::FILE, $name) !== 1 || isset($files[$name])) {
                throw new InvalidRelease('bad_archive', 'Onverwacht bestand: '.self::printable($name));
            }

            $files[$name] = true;
        }

        $missing = array_diff(self::REQUIRED, array_keys($files));

        if ($missing !== []) {
            throw new InvalidRelease('bad_archive', 'Ontbrekend bestand: '.implode(', ', $missing));
        }

        $names = array_keys($files);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The three files of an upload bundle (uncompressed tar from
     * scripts/release/build.sh): manifest, manifest.sig and the tarball.
     *
     * @return array{manifest: string, signature: string, tarball: string, tarball_name: string}
     */
    public static function bundle(string $tar): array
    {
        $files = [];

        foreach (self::read($tar, 'bad_bundle') as [$name, $type, $content]) {
            $name = preg_replace('#\A\./#', '', $name);

            if ($type !== '0' || isset($files[$name]) || preg_match('/\A(?:manifest|manifest\.sig|serverscope-agent-'.ReleaseManifest::VERSION_PATTERN.'\.tar\.gz)\z/', $name) !== 1) {
                throw new InvalidRelease('bad_bundle', 'Onverwacht bestand: '.self::printable($name));
            }

            $files[$name] = $content;
        }

        $tarballs = preg_grep('/\.tar\.gz\z/', array_keys($files));

        if (count($files) !== 3 || ! isset($files['manifest'], $files['manifest.sig']) || count($tarballs) !== 1) {
            throw new InvalidRelease('bad_bundle');
        }

        $name = reset($tarballs);

        return ['manifest' => $files['manifest'], 'signature' => $files['manifest.sig'], 'tarball' => $files[$name], 'tarball_name' => $name];
    }

    /**
     * Every entry of an uncompressed ustar archive. Types other than
     * regular files ('0') and directories ('5') are returned as they are
     * and refused by the callers; the prefix field must be empty.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> [name, type, content]
     */
    private static function read(string $tar, string $reason): array
    {
        if (strlen($tar) % 512 !== 0) {
            throw new InvalidRelease($reason, 'Geen geldig tar-bestand.');
        }

        $entries = [];
        $offset = 0;

        while ($offset + 512 <= strlen($tar)) {
            $header = substr($tar, $offset, 512);

            if ($header === str_repeat("\0", 512)) {
                return $entries;
            }

            if (substr($header, 257, 5) !== 'ustar' || ! self::checksumMatches($header) || rtrim(substr($header, 345, 155), "\0") !== '') {
                throw new InvalidRelease($reason, 'Ongeldige tar-header.');
            }

            $sizeField = trim(substr($header, 124, 12), "\0 ");

            if (preg_match('/\A[0-7]{1,11}\z/', $sizeField) !== 1) {
                throw new InvalidRelease($reason, 'Ongeldige tar-header.');
            }

            $size = (int) octdec($sizeField);
            $type = $header[156] === "\0" ? '0' : $header[156];

            if ($offset + 512 + $size > strlen($tar)) {
                throw new InvalidRelease($reason, 'Afgebroken tar-bestand.');
            }

            if ($type === '5' && $size !== 0) {
                throw new InvalidRelease($reason, 'Ongeldige map.');
            }

            $entries[] = [rtrim(substr($header, 0, 100), "\0"), $type, substr($tar, $offset + 512, $size)];
            $offset += 512 + (int) (ceil($size / 512) * 512);
        }

        throw new InvalidRelease($reason, 'Het tar-bestand heeft geen geldig einde.');
    }

    private static function printable(string $name): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $name), 0, 100);
    }

    private static function checksumMatches(string $header): bool
    {
        $stored = octdec(trim(substr($header, 148, 8), "\0 "));
        $sum = array_sum(array_map('ord', str_split(substr_replace($header, '        ', 148, 8))));

        return $stored === $sum;
    }
}
