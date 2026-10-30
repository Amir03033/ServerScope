<?php

namespace App\Releases;

/**
 * The signed manifest of an agent release (scripts/release/build.sh).
 * Exactly these fields, in this order, one "name=value" per line with a
 * final newline: anything else is refused, so the bytes that were signed
 * can only be read one way (here and in the updater on customer servers).
 */
final class ReleaseManifest
{
    public const FORMAT = 'serverscope-agent-release-1';

    public const PRODUCT = 'serverscope-agent';

    /** Largest tarball accepted (bytes). */
    public const MAX_SIZE = 10485760;

    public const VERSION_PATTERN = '(?:0|[1-9]\d{0,3})\.(?:0|[1-9]\d{0,3})\.(?:0|[1-9]\d{0,3})';

    /** @var array<string, string> field => pattern of its value */
    private const FIELDS = [
        'format' => '/\A'.self::FORMAT.'\z/',
        'product' => '/\A'.self::PRODUCT.'\z/',
        'version' => '/\A'.self::VERSION_PATTERN.'\z/',
        'filename' => '/\Aserverscope-agent-'.self::VERSION_PATTERN.'\.tar\.gz\z/',
        'sha256' => '/\A[a-f0-9]{64}\z/',
        'size' => '/\A[1-9]\d{0,7}\z/',
        'commit' => '/\A[a-f0-9]{40}\z/',
        'released_at' => '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',
        'min_updater_version' => '/\A[1-9]\d{0,2}\z/',
        'key_id' => '/\A[a-f0-9]{16}\z/',
    ];

    private function __construct(
        public readonly string $raw,
        public readonly string $version,
        public readonly string $filename,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $commit,
        public readonly string $releasedAt,
        public readonly int $minUpdaterVersion,
        public readonly string $keyId,
    ) {}

    public static function parse(string $raw): self
    {
        if (! str_ends_with($raw, "\n") || str_contains($raw, "\r")) {
            throw new InvalidRelease('bad_manifest');
        }

        $lines = explode("\n", substr($raw, 0, -1));

        if (count($lines) !== count(self::FIELDS)) {
            throw new InvalidRelease('bad_manifest');
        }

        $values = [];

        foreach (array_keys(self::FIELDS) as $index => $field) {
            $prefix = $field.'=';

            if (! str_starts_with($lines[$index], $prefix)) {
                throw new InvalidRelease('bad_manifest', "Veld {$field} ontbreekt of staat niet op zijn plek.");
            }

            $value = substr($lines[$index], strlen($prefix));

            if (preg_match(self::FIELDS[$field], $value) !== 1) {
                throw new InvalidRelease('bad_manifest', "Ongeldige waarde voor {$field}.");
            }

            $values[$field] = $value;
        }

        if ($values['filename'] !== 'serverscope-agent-'.$values['version'].'.tar.gz') {
            throw new InvalidRelease('bad_manifest', 'De bestandsnaam hoort niet bij de versie.');
        }

        if ((int) $values['size'] > self::MAX_SIZE || strtotime($values['released_at']) === false) {
            throw new InvalidRelease('bad_manifest');
        }

        return new self($raw, $values['version'], $values['filename'], $values['sha256'], (int) $values['size'],
            $values['commit'], $values['released_at'], (int) $values['min_updater_version'], $values['key_id']);
    }

    public static function isVersion(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A'.self::VERSION_PATTERN.'\z/', $value) === 1;
    }

    /** [major, minor, patch] of a valid version. */
    public static function versionParts(string $version): array
    {
        return array_map('intval', explode('.', $version));
    }
}
