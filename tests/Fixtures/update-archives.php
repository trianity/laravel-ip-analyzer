<?php

function syntheticTarEntry(string $name, string $data, string $type = '0'): string
{
    $header = str_pad($name, 100, "\0").sprintf("%07o\0%07o\0%07o\0%011o\0%011o\0", 0600, 0, 0, strlen($data), 1700000000)
        .str_repeat(' ', 8).$type.str_repeat("\0", 100)."ustar\00000".str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
    $sum = array_sum(unpack('C*', $header));
    $header = substr_replace($header, sprintf("%06o\0 ", $sum), 148, 8);

    return $header.$data.str_repeat("\0", (512 - strlen($data) % 512) % 512);
}

function syntheticArchive(array $entries): string
{
    return gzencode(implode('', $entries).str_repeat("\0", 1024));
}

function syntheticPax(string $key, string $value): string
{
    $record = ' '.$key.'='.$value."\n";
    $length = strlen($record) + 1;
    while (strlen((string) $length) + strlen($record) !== $length) {
        $length = strlen((string) $length) + strlen($record);
    }

    return $length.$record;
}
