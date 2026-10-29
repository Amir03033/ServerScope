<?php

namespace App\Releases;

use RuntimeException;

/**
 * A release that must not be imported, served or installed. $reason is a
 * fixed code (also used by the updater on customer servers).
 */
class InvalidRelease extends RuntimeException
{
    public const REASONS = [
        'no_trusted_key' => 'Er is geen vertrouwde releasesleutel ingesteld.',
        'bad_key' => 'Een releasesleutel in de configuratie is geen geldige Ed25519-sleutel.',
        'bad_signature' => 'De handtekening is ongeldig of gemaakt met een onbekende sleutel.',
        'bad_manifest' => 'Het manifest heeft niet het verwachte formaat.',
        'bad_checksum' => 'De tarball komt niet overeen met het manifest (SHA-256 of grootte).',
        'bad_archive' => 'De tarball bevat onverwachte of onveilige bestanden.',
        'bad_bundle' => 'De bundel bevat niet precies manifest, manifest.sig en de tarball.',
        'duplicate' => 'Deze versie is al geïmporteerd.',
    ];

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct(trim((self::REASONS[$reason] ?? $reason).' '.$detail));
    }
}
