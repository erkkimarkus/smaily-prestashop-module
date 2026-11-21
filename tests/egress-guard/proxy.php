<?php
// A CONNECT-only egress proxy for a dev shop container (install.sh): a tunnel to any
// *.sendsmaily.net:443 goes to the local TLS front of the Smaily mock (127.0.0.1:8443), and a
// tunnel to intelligence.smaily.com:443, the Campaign Intelligence engine, to the front of the
// engine mock (127.0.0.1:8444); every other destination is refused with 403, so no request of the
// shop leaves the container. Plain HTTP is refused too. Each decision is appended to
// /tmp/smaily-egress.log (host and verdict only).
declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:3128', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$log = static function (string $line): void {
    file_put_contents('/tmp/smaily-egress.log', date('c') . ' ' . $line . "\n", FILE_APPEND);
};
$pairs = [];
while (true) {
    $read = [$server];
    foreach ($pairs as $pair) {
        $read[] = $pair[0];
        $read[] = $pair[1];
    }
    $write = $except = null;
    if (stream_select($read, $write, $except, 60) === false) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = @stream_socket_accept($server, 5);
            if ($client === false) {
                continue;
            }
            $head = '';
            while (!str_contains($head, "\r\n\r\n") && ($chunk = fread($client, 4096)) !== false && $chunk !== '') {
                $head .= $chunk;
            }
            $front = null;
            if (preg_match('~^CONNECT ([A-Za-z0-9.-]+):(\d+) ~', $head, $m) === 1 && $m[2] === '443') {
                if (preg_match('~(^|\.)sendsmaily\.net$~i', $m[1]) === 1) {
                    $front = ['tcp://127.0.0.1:8443', 'mock'];
                } elseif (strtolower($m[1]) === 'intelligence.smaily.com') {
                    $front = ['tcp://127.0.0.1:8444', 'engine mock'];
                }
            }
            if ($front !== null) {
                $upstream = @stream_socket_client($front[0], $e, $s, 5);
                if ($upstream === false) {
                    fwrite($client, "HTTP/1.1 502 Bad Gateway\r\n\r\n");
                    fclose($client);
                    continue;
                }
                $log('TUNNEL ' . strtolower($m[1]) . ' -> ' . $front[1]);
                fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
                $pairs[] = [$client, $upstream];
            } else {
                $target = preg_match('~^(\S+) (\S+)~', $head, $r) === 1 ? $r[1] . ' ' . parse_url($r[2], PHP_URL_HOST) ?: $r[2] : '?';
                $log('REFUSED ' . $target);
                fwrite($client, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n");
                fclose($client);
            }
            continue;
        }
        foreach ($pairs as $i => [$a, $b]) {
            if ($socket !== $a && $socket !== $b) {
                continue;
            }
            $other = $socket === $a ? $b : $a;
            $data = fread($socket, 65536);
            if ($data === false || ($data === '' && feof($socket))) {
                fclose($a);
                fclose($b);
                unset($pairs[$i]);
            } elseif ($data !== '') {
                fwrite($other, $data);
            }
        }
    }
}
