<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Dns\DnsClient;
use App\Monitoring\Dns\DnsException;
use PHPUnit\Framework\TestCase;

class DnsClientTest extends TestCase
{
    private const ID = 0xBEEF;

    public function test_query_packet_format(): void
    {
        $packet = (new DnsClient(['1.1.1.1']))->buildQuery(0x1234, 'www.example.com', 257);

        $this->assertSame(pack('nnnnnn', 0x1234, 0x0100, 1, 0, 0, 1), substr($packet, 0, 12));
        $this->assertSame("\x03www\x07example\x03com\x00".pack('nn', 257, 1), substr($packet, 12, 21));
        // EDNS0 OPT record advertising a 1232 byte UDP payload.
        $this->assertSame("\x00".pack('nnNn', 41, 1232, 0, 0), substr($packet, 33));
    }

    public function test_rejects_invalid_names_and_types(): void
    {
        $client = new DnsClient(['1.1.1.1']);

        foreach ([['', 'A'], [str_repeat('a', 64).'.com', 'A'], ['example..com', 'A'], ['example.com', 'PTR']] as [$name, $type]) {
            try {
                $client->query($name, $type);
                $this->fail("{$name} {$type} should be rejected");
            } catch (DnsException $e) {
                $this->assertContains($e->reason, ['invalid', 'unsupported']);
            }
        }
    }

    public function test_parses_address_records(): void
    {
        $data = $this->response('example.com', 1, [
            $this->rr('example.com', 1, 300, inet_pton('93.184.215.14')),
            $this->rr('example.com', 1, 300, inet_pton('93.184.215.15')),
        ]);

        $response = (new DnsClient([]))->parse($data, self::ID);

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['93.184.215.14', '93.184.215.15'], $response->values('A'));
        $this->assertSame(300, $response->answers[0]->ttl);

        $v6 = $this->response('example.com', 28, [$this->rr('example.com', 28, 60, inet_pton('2606:2800:21f:cb07:6820:80da:af6b:8b2c'))]);
        $this->assertSame(['2606:2800:21f:cb07:6820:80da:af6b:8b2c'], (new DnsClient([]))->parse($v6, self::ID)->values('AAAA'));
    }

    public function test_parses_compressed_names_in_mx_ns_and_cname(): void
    {
        // Owner names and targets use compression pointers to offset 12
        // (the question name), as real resolvers do.
        $pointer = "\xC0\x0C";
        $data = $this->response('example.com', 15, [
            $this->rr($pointer, 15, 3600, pack('n', 10)."\x04mail".$pointer, raw: true),
            $this->rr($pointer, 15, 3600, pack('n', 20)."\x05mail2".$pointer, raw: true),
            $this->rr($pointer, 2, 3600, "\x03ns1".$pointer, raw: true),
            $this->rr("\x03www".$pointer, 5, 300, $pointer, raw: true),
        ]);

        $response = (new DnsClient([]))->parse($data, self::ID);

        $this->assertSame(['10 mail.example.com', '20 mail2.example.com'], $response->values('MX'));
        $this->assertSame(['ns1.example.com'], $response->values('NS'));
        $this->assertSame('www.example.com', $response->recordsOfType('CNAME')[0]->name);
        $this->assertSame(['example.com'], $response->values('CNAME'));
        $this->assertSame(10, $response->recordsOfType('MX')[0]->data['priority']);
    }

    public function test_parses_txt_and_caa(): void
    {
        $data = $this->response('example.com', 16, [
            $this->rr('example.com', 16, 300, "\x0Bv=spf1 -all"),
            // One record split into two character-strings is concatenated.
            $this->rr('example.com', 16, 300, "\x05hello\x06 world"),
            $this->rr('example.com', 257, 300, "\x00\x05issueletsencrypt.org"),
            $this->rr('example.com', 257, 300, "\x80\x09issuewild;"),
        ]);

        $response = (new DnsClient([]))->parse($data, self::ID);

        $this->assertSame(['hello world', 'v=spf1 -all'], $response->values('TXT'));
        $this->assertSame(['0 issue "letsencrypt.org"', '128 issuewild ";"'], $response->values('CAA'));
    }

    public function test_nxdomain_and_truncation_flags(): void
    {
        $nx = $this->response('missing.example', 1, [], rcode: 3);
        $this->assertTrue((new DnsClient([]))->parse($nx, self::ID)->isNxDomain());

        $tc = $this->response('example.com', 16, [], flags: 0x8380);
        $this->assertTrue((new DnsClient([]))->parse($tc, self::ID)->truncated);
    }

    public function test_rejects_malformed_responses(): void
    {
        $client = new DnsClient([]);
        $valid = $this->response('example.com', 1, [$this->rr('example.com', 1, 300, inet_pton('1.2.3.4'))]);

        $cases = [
            'too short' => [substr($valid, 0, 8), self::ID],
            'id mismatch' => [$valid, 0x1111],
            'not a response' => [pack('n', self::ID).pack('n', 0x0100).substr($valid, 4), self::ID],
            'truncated record' => [substr($valid, 0, -2), self::ID],
            'pointer loop' => [$this->response('example.com', 1, [$this->rr("\xC0\x1D", 1, 1, inet_pton('1.2.3.4'), raw: true)], loopAt: true), self::ID],
            'pointer out of bounds' => [$this->response('example.com', 1, [$this->rr("\xFF\xFF", 1, 1, inet_pton('1.2.3.4'), raw: true)]), self::ID],
            'bad address length' => [$this->response('example.com', 1, [$this->rr('example.com', 1, 1, 'abc')]), self::ID],
        ];

        foreach ($cases as $label => [$data, $id]) {
            try {
                $client->parse($data, $id);
                $this->fail("Expected {$label} to be rejected");
            } catch (DnsException $e) {
                $this->assertSame('malformed', $e->reason, $label);
            }
        }
    }

    public function test_times_out_on_unresponsive_nameserver(): void
    {
        // UDP to a local port nothing listens on yields silence (or an
        // ICMP error), never an answer: the client must give up in time.
        $started = microtime(true);

        try {
            (new DnsClient(['127.0.0.1'], 0.5))->query('example.com', 'A');
            $this->fail('Expected a timeout');
        } catch (DnsException $e) {
            $this->assertContains($e->reason, ['timeout', 'network']);
        }

        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    public function test_full_round_trip_with_tcp_fallback_against_local_nameserver(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }

        // Port 53 needs root; skip quietly when it cannot be bound.
        $udp = @stream_socket_server('udp://127.0.0.2:53', $errno, $error, STREAM_SERVER_BIND);
        $tcp = @stream_socket_server('tcp://127.0.0.2:53', $errno2, $error2);

        if ($udp === false || $tcp === false) {
            $this->markTestSkipped('Cannot bind a local nameserver on port 53');
        }

        $pid = pcntl_fork();

        if ($pid === 0) {
            // Child: answer one UDP query with TC set, then the TCP retry
            // with a full TXT answer.
            $query = stream_socket_recvfrom($udp, 512, 0, $peer);
            $id = unpack('n', $query)[1];
            stream_socket_sendto($udp, pack('nnnnnn', $id, 0x8380, 0, 0, 0, 0), 0, $peer);

            $conn = stream_socket_accept($tcp, 5);
            $length = unpack('n', fread($conn, 2))[1];
            $query = fread($conn, $length);
            $id = unpack('n', $query)[1];
            $answer = $this->response('example.com', 16, [$this->rr('example.com', 16, 60, "\x07big-txt")], id: $id);
            fwrite($conn, pack('n', strlen($answer)).$answer);
            fclose($conn);
            posix_kill(getmypid(), SIGKILL);
        }

        try {
            $response = (new DnsClient(['127.0.0.2'], 2.0))->query('example.com', 'TXT');
            $this->assertSame(['big-txt'], $response->values('TXT'));
            $this->assertSame('127.0.0.2', $response->nameserver);
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($udp);
            fclose($tcp);
        }
    }

    // --- packet builder (independent of the code under test) ---

    private function encodeName(string $name): string
    {
        $out = '';

        foreach (explode('.', $name) as $label) {
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }

    private function rr(string $owner, int $type, int $ttl, string $rdata, bool $raw = false): string
    {
        return ($raw ? $owner : $this->encodeName($owner)).pack('nnNn', $type, 1, $ttl, strlen($rdata)).$rdata;
    }

    private function response(string $qname, int $qtype, array $answers, int $rcode = 0, int $flags = 0x8180, bool $loopAt = false, int $id = self::ID): string
    {
        $header = pack('nnnnnn', $id, $flags | $rcode, 1, count($answers), 0, 0);
        $question = $this->encodeName($qname).pack('nn', $qtype, 1);
        $packet = $header.$question.implode('', $answers);

        if ($loopAt) {
            // Make offset 29 (the answer's owner pointer) point to itself.
            $packet = substr($packet, 0, 29)."\xC0\x1D".substr($packet, 31);
        }

        return $packet;
    }
}
