<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Support;

final class IpAddress
{
    public static function normalize(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bytes = inet_pton($ip);
        if (strlen($bytes) === 16 && substr($bytes, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $bytes = substr($bytes, 12);
        }

        return inet_ntop($bytes);
    }

    public static function matches(string $ip, string $network, int $prefix): bool
    {
        $address = inet_pton($ip);
        $base = inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $full = intdiv($prefix, 8);
        $bits = $prefix % 8;

        return substr($address, 0, $full) === substr($base, 0, $full)
            && ($bits === 0 || ((ord($address[$full]) ^ ord($base[$full])) & (0xFF << (8 - $bits))) === 0);
    }

    public static function isPublic(string $ip): bool
    {
        // Conservative policy: IPv6 global unicast only, excluding special-purpose blocks.
        $ranges = str_contains($ip, ':')
            ? ['2001::/23', '2001:db8::/32', '2002::/16', '2620:4f:8000::/48', '3fff::/20']
            : ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
                '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15',
                '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        if (str_contains($ip, ':') && ! self::matches($ip, '2000::', 3)) {
            return false;
        }
        foreach ($ranges as $range) {
            [$network, $prefix] = explode('/', $range);
            if (self::matches($ip, $network, (int) $prefix)) {
                return false;
            }
        }

        return true;
    }
}
