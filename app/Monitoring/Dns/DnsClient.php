<?php

namespace App\Monitoring\Dns;

/**
 * Minimal stub resolver speaking the DNS wire protocol (RFC 1035) to
 * configured recursive nameservers.
 *
 * PHP's dns_get_record() offers no timeout control, cannot tell a
 * timeout from NXDOMAIN and does not support CAA on every platform, so
 * monitoring uses this client instead. It sends one question over UDP
 * (with EDNS0, RFC 6891), retries over TCP when the answer is truncated,
 * enforces a hard timeout per nameserver and validates every offset
 * while parsing.
 */
class DnsClient implements Resolver
{
    private const TYPE_CODES = [
        'A' => 1, 'NS' => 2, 'CNAME' => 5, 'SOA' => 6, 'MX' => 15,
        'TXT' => 16, 'AAAA' => 28, 'OPT' => 41, 'CAA' => 257,
    ];

    /**
     * @param  array<int, string>  $nameservers  IP addresses of recursive resolvers
     */
    public function __construct(
        private array $nameservers,
        private float $timeoutSeconds = 3.0,
    ) {
        $this->nameservers = array_values(array_filter(
            $nameservers,
            fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false,
        ));
    }

    public function query(string $name, string $type): DnsResponse
    {
        $type = strtoupper($type);

        if (! isset(self::TYPE_CODES[$type]) || $type === 'OPT') {
            throw new DnsException("Unsupported record type {$type}.", 'unsupported');
        }

        $name = rtrim(strtolower(trim($name)), '.');

        if ($name === '' || strlen($name) > 253) {
            throw new DnsException('Invalid DNS name.', 'invalid');
        }

        if ($this->nameservers === []) {
            throw new DnsException('No DNS nameservers configured.', 'config');
        }

        $lastError = null;

        foreach ($this->nameservers as $nameserver) {
            try {
                $response = $this->ask($nameserver, $name, $type);

                // SERVFAIL/REFUSED from one resolver: try the next one.
                if (in_array($response->rcode, [DnsResponse::SERVFAIL, DnsResponse::REFUSED], true)) {
                    $lastError = new DnsException(
                        $response->rcode === DnsResponse::SERVFAIL ? 'Nameserver returned SERVFAIL.' : 'Nameserver refused the query.',
                        $response->rcode === DnsResponse::SERVFAIL ? 'servfail' : 'refused',
                    );

                    continue;
                }

                return $response;
            } catch (DnsException $exception) {
                $lastError = $exception;
            }
        }

        throw $lastError ?? new DnsException('DNS lookup failed.');
    }

    private function ask(string $nameserver, string $name, string $type): DnsResponse
    {
        $id = random_int(0, 0xFFFF);
        $packet = $this->buildQuery($id, $name, self::TYPE_CODES[$type]);

        $raw = $this->sendUdp($nameserver, $packet);
        $response = $this->parse($raw, $id, $nameserver);

        if ($response->truncated) {
            $raw = $this->sendTcp($nameserver, $packet);
            $response = $this->parse($raw, $id, $nameserver);
        }

        return $response;
    }

    public function buildQuery(int $id, string $name, int $typeCode): string
    {
        // Header: ID, flags (RD), QDCOUNT=1, ANCOUNT=0, NSCOUNT=0, ARCOUNT=1 (OPT)
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 1);

        foreach (explode('.', $name) as $label) {
            $length = strlen($label);

            if ($length === 0 || $length > 63) {
                throw new DnsException('Invalid DNS label.', 'invalid');
            }

            $packet .= chr($length).$label;
        }

        $packet .= "\0".pack('nn', $typeCode, 1);

        // EDNS0 OPT pseudo-record: root name, type 41, UDP payload 1232.
        $packet .= "\0".pack('nnNn', 41, 1232, 0, 0);

        return $packet;
    }

    private function sendUdp(string $nameserver, string $packet): string
    {
        $address = str_contains($nameserver, ':') ? "udp://[{$nameserver}]:53" : "udp://{$nameserver}:53";
        $socket = @stream_socket_client($address, $errno, $error, $this->timeoutSeconds);

        if ($socket === false) {
            throw new DnsException("Could not reach nameserver {$nameserver}.", 'network');
        }

        try {
            $this->applyTimeout($socket);

            if (@fwrite($socket, $packet) !== strlen($packet)) {
                throw new DnsException('Could not send DNS query.', 'network');
            }

            $data = @fread($socket, 65535);

            if ($data === false || $data === '') {
                if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                    throw new DnsException("Nameserver {$nameserver} timed out.", 'timeout');
                }

                throw new DnsException('Empty DNS response.', 'network');
            }

            return $data;
        } finally {
            fclose($socket);
        }
    }

    private function sendTcp(string $nameserver, string $packet): string
    {
        $address = str_contains($nameserver, ':') ? "tcp://[{$nameserver}]:53" : "tcp://{$nameserver}:53";
        $socket = @stream_socket_client($address, $errno, $error, $this->timeoutSeconds);

        if ($socket === false) {
            throw new DnsException("Could not reach nameserver {$nameserver} over TCP.", 'network');
        }

        try {
            $this->applyTimeout($socket);
            fwrite($socket, pack('n', strlen($packet)).$packet);

            $prefix = $this->readExactly($socket, 2);
            $length = unpack('n', $prefix)[1];

            return $this->readExactly($socket, $length);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function readExactly($socket, int $length): string
    {
        $data = '';
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (strlen($data) < $length) {
            if (microtime(true) > $deadline) {
                throw new DnsException('DNS TCP read timed out.', 'timeout');
            }

            $chunk = @fread($socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                    throw new DnsException('DNS TCP read timed out.', 'timeout');
                }

                if (feof($socket)) {
                    throw new DnsException('DNS TCP connection closed early.', 'network');
                }

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    /** @param resource $socket */
    private function applyTimeout($socket): void
    {
        $seconds = (int) floor($this->timeoutSeconds);
        $micro = (int) (($this->timeoutSeconds - $seconds) * 1_000_000);
        stream_set_timeout($socket, $seconds, $micro);
    }

    /**
     * Parse a DNS response message.
     */
    public function parse(string $data, int $expectedId, ?string $nameserver = null): DnsResponse
    {
        if (strlen($data) < 12) {
            throw new DnsException('DNS response too short.', 'malformed');
        }

        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($data, 0, 12));

        if ($header['id'] !== $expectedId) {
            throw new DnsException('DNS response ID mismatch.', 'malformed');
        }

        if (($header['flags'] & 0x8000) === 0) {
            throw new DnsException('DNS message is not a response.', 'malformed');
        }

        $rcode = $header['flags'] & 0x000F;
        $truncated = ($header['flags'] & 0x0200) !== 0;
        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            $this->readName($data, $offset);
            $offset += 4;
            $this->ensureLength($data, $offset);
        }

        $answers = [];

        for ($i = 0; $i < $header['an']; $i++) {
            $record = $this->readRecord($data, $offset);

            if ($record !== null) {
                $answers[] = $record;
            }
        }

        return new DnsResponse($rcode, $answers, $truncated, $nameserver);
    }

    private function readRecord(string $data, int &$offset): ?DnsRecord
    {
        $name = $this->readName($data, $offset);
        $this->ensureLength($data, $offset + 10);

        $meta = unpack('ntype/nclass/Nttl/nlength', substr($data, $offset, 10));
        $offset += 10;
        $rdataOffset = $offset;
        $end = $offset + $meta['length'];
        $this->ensureLength($data, $end);
        $offset = $end;

        $type = array_search($meta['type'], self::TYPE_CODES, true);

        if ($type === false || $type === 'OPT') {
            return null;
        }

        $ttl = $meta['ttl'] > 0x7FFFFFFF ? 0 : $meta['ttl'];
        $rdata = substr($data, $rdataOffset, $meta['length']);
        $cursor = $rdataOffset;

        [$value, $parsed] = match ($type) {
            'A' => $this->ipRecord($rdata, 4),
            'AAAA' => $this->ipRecord($rdata, 16),
            'NS', 'CNAME' => (function () use ($data, &$cursor) {
                $target = $this->readName($data, $cursor);

                return [$target, ['target' => $target]];
            })(),
            'MX' => (function () use ($data, &$cursor, $end) {
                $this->ensureLength($data, $cursor + 2, $end);
                $priority = unpack('n', substr($data, $cursor, 2))[1];
                $cursor += 2;
                $exchange = $this->readName($data, $cursor);

                return ["{$priority} {$exchange}", ['priority' => $priority, 'exchange' => $exchange]];
            })(),
            'TXT' => $this->txtRecord($rdata),
            'CAA' => $this->caaRecord($rdata),
            'SOA' => (function () use ($data, &$cursor, $end) {
                $mname = $this->readName($data, $cursor);
                $rname = $this->readName($data, $cursor);
                $this->ensureLength($data, $cursor + 20, $end);
                $f = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', substr($data, $cursor, 20));

                return ["{$mname} {$rname} {$f['serial']}", ['mname' => $mname, 'rname' => $rname, ...$f]];
            })(),
        };

        return new DnsRecord($name, $type, $ttl, $value, $parsed);
    }

    private function ipRecord(string $rdata, int $length): array
    {
        if (strlen($rdata) !== $length) {
            throw new DnsException('Invalid address record length.', 'malformed');
        }

        $ip = inet_ntop($rdata);

        return [$ip, ['address' => $ip]];
    }

    private function txtRecord(string $rdata): array
    {
        $strings = [];
        $i = 0;
        $length = strlen($rdata);

        while ($i < $length) {
            $size = ord($rdata[$i]);
            $i++;

            if ($i + $size > $length) {
                throw new DnsException('Invalid TXT record.', 'malformed');
            }

            $strings[] = substr($rdata, $i, $size);
            $i += $size;
        }

        // Character-strings of one record are concatenated (RFC 7208 §3.3).
        $text = implode('', $strings);

        return [$text, ['text' => $text]];
    }

    private function caaRecord(string $rdata): array
    {
        if (strlen($rdata) < 2) {
            throw new DnsException('Invalid CAA record.', 'malformed');
        }

        $flags = ord($rdata[0]);
        $tagLength = ord($rdata[1]);

        if (2 + $tagLength > strlen($rdata)) {
            throw new DnsException('Invalid CAA record.', 'malformed');
        }

        $tag = strtolower(substr($rdata, 2, $tagLength));
        $value = substr($rdata, 2 + $tagLength);

        return ["{$flags} {$tag} \"{$value}\"", ['flags' => $flags, 'tag' => $tag, 'value' => $value]];
    }

    /**
     * Read a (possibly compressed) domain name and advance $offset past it.
     */
    private function readName(string $data, int &$offset): string
    {
        $labels = [];
        $cursor = $offset;
        $jumped = false;
        $jumps = 0;
        $length = strlen($data);

        while (true) {
            $this->ensureLength($data, $cursor + 1);
            $size = ord($data[$cursor]);

            if (($size & 0xC0) === 0xC0) {
                $this->ensureLength($data, $cursor + 2);

                if (++$jumps > 20) {
                    throw new DnsException('DNS name compression loop.', 'malformed');
                }

                $pointer = unpack('n', substr($data, $cursor, 2))[1] & 0x3FFF;

                if (! $jumped) {
                    $offset = $cursor + 2;
                }

                if ($pointer >= $length) {
                    throw new DnsException('Invalid DNS compression pointer.', 'malformed');
                }

                $cursor = $pointer;
                $jumped = true;

                continue;
            }

            if (($size & 0xC0) !== 0) {
                throw new DnsException('Unsupported DNS label type.', 'malformed');
            }

            $cursor++;

            if ($size === 0) {
                break;
            }

            $this->ensureLength($data, $cursor + $size);
            $labels[] = strtolower(substr($data, $cursor, $size));
            $cursor += $size;

            if (count($labels) > 127) {
                throw new DnsException('DNS name too long.', 'malformed');
            }
        }

        if (! $jumped) {
            $offset = $cursor;
        }

        return implode('.', $labels);
    }

    private function ensureLength(string $data, int $required, ?int $limit = null): void
    {
        if ($required > strlen($data) || ($limit !== null && $required > $limit)) {
            throw new DnsException('Truncated DNS response.', 'malformed');
        }
    }
}
