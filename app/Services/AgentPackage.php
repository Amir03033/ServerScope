<?php

namespace App\Services;

use RuntimeException;

/**
 * The Python agent as a tarball for install.sh, with its SHA-256.
 *
 * The archive is reproducible: fixed file order, timestamps, owners and
 * modes, and gzip without a timestamp. The same sources always give the
 * same bytes, so concurrent first downloads write identical files. The
 * cache file name contains a hash of the sources: changed agent code
 * gives a new package even without a new version number. Files are
 * written to a temporary name and renamed (atomic).
 *
 * The checksum comes from the same server as the package: it protects
 * against corrupt downloads, not against a compromised server.
 */
class AgentPackage
{
    /** Fixed timestamp for every entry (2024-01-01 00:00:00 UTC). */
    private const MTIME = 1704067200;

    private ?string $bytes = null;

    public function sourcePath(): string
    {
        return rtrim((string) config('serverscope.agent.source_path', base_path('agent')), '/');
    }

    public function version(): string
    {
        $collector = @file_get_contents($this->sourcePath().'/serverscope_agent/collector.py');

        if (! is_string($collector) || ! preg_match('/^AGENT_VERSION\s*=\s*"(\d+\.\d+\.\d+)"/m', $collector, $match)) {
            throw new RuntimeException('Agent version not found in serverscope_agent/collector.py.');
        }

        return $match[1];
    }

    public function filename(): string
    {
        return 'serverscope-agent-'.$this->version().'.tar.gz';
    }

    /**
     * Files in the installed layout (relative to /opt/serverscope-agent),
     * sorted.
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        $files = ['requirements.txt', 'serverscope-agent.service'];

        foreach (glob($this->sourcePath().'/serverscope_agent/*.py') ?: [] as $path) {
            $files[] = 'serverscope_agent/'.basename($path);
        }

        sort($files, SORT_STRING);

        return $files;
    }

    public function sourceHash(): string
    {
        $context = hash_init('sha256');

        foreach ($this->files() as $file) {
            hash_update($context, $file."\0".$this->read($file)."\0");
        }

        return hash_final($context);
    }

    /** Path of the cached package; (re)written when missing or not matching. */
    public function path(): string
    {
        $directory = (string) config('serverscope.agent.cache_path', storage_path('app/agent'));
        $path = $directory.'/serverscope-agent-'.$this->version().'-'.substr($this->sourceHash(), 0, 16).'.tar.gz';
        $bytes = $this->bytes();

        if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $bytes)) {
            return $path;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        $temporary = $path.'.tmp'.bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $bytes) !== strlen($bytes) || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot write {$path}.");
        }

        // Packages of older sources are no longer served.
        foreach (glob($directory.'/serverscope-agent-*.tar.gz') ?: [] as $old) {
            if ($old !== $path) {
                @unlink($old);
            }
        }

        return $path;
    }

    public function checksum(): string
    {
        return hash('sha256', $this->bytes());
    }

    /** The gzip-compressed tar archive. */
    public function bytes(): string
    {
        if ($this->bytes !== null) {
            return $this->bytes;
        }

        $tar = '';

        foreach ($this->files() as $file) {
            $content = $this->read($file);
            $tar .= $this->header($file, strlen($content)).$content.str_repeat("\0", (512 - strlen($content) % 512) % 512);
        }

        // End of archive: two empty blocks.
        $tar .= str_repeat("\0", 1024);

        // gzencode writes no timestamp or file name in the header.
        return $this->bytes = gzencode($tar, 9);
    }

    private function read(string $file): string
    {
        $content = @file_get_contents($this->sourcePath().'/'.$file);

        if (! is_string($content)) {
            throw new RuntimeException("Agent file {$file} is missing.");
        }

        return $content;
    }

    /** A POSIX ustar header for a regular file owned by root (0644). */
    private function header(string $name, int $size): string
    {
        if (strlen($name) > 100) {
            throw new RuntimeException("Path too long for the archive: {$name}");
        }

        $field = fn (string $value, int $length) => str_pad(substr($value, 0, $length), $length, "\0");
        $octal = fn (int $value, int $length) => str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0";

        $header = $field($name, 100)
            .$octal(0644, 8).$octal(0, 8).$octal(0, 8)
            .$octal($size, 12).$octal(self::MTIME, 12)
            .'        ' // checksum placeholder (8 spaces)
            .'0'.$field('', 100)
            ."ustar\0".'00'
            .$field('root', 32).$field('root', 32)
            .$octal(0, 8).$octal(0, 8)
            .$field('', 155);
        $header = str_pad($header, 512, "\0");

        $checksum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }
}
