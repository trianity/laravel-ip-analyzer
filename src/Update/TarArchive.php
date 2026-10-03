<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final class TarArchive
{
    public function extract(string $download, string $stage, string $edition, UpdateOptions $options): ExtractedArchive
    {
        $tar = $stage.'/expanded.tar';
        $this->inflate($download, $tar, $options->integer('max_expanded_bytes'));
        $file = @fopen($tar, 'rb');
        if ($file === false) {
            throw new UpdateFailure('disk_error');
        }
        $candidate = null;
        $notices = [];
        $pending = [];
        try {
            $entries = 0;
            while (true) {
                $header = $this->read($file, 512);
                if ($header === str_repeat("\0", 512)) {
                    if ($pending || $this->read($file, 512) !== str_repeat("\0", 512)) {
                        throw new UpdateFailure('invalid_tar');
                    }
                    while (! feof($file)) {
                        $rest = fread($file, 8192);
                        if ($rest === false || trim($rest, "\0") !== '') {
                            throw new UpdateFailure('invalid_tar');
                        }
                    }
                    break;
                }
                if (++$entries > $options->integer('max_entries')) {
                    throw new UpdateFailure('too_many_entries');
                }
                $expected = $this->octal(substr($header, 148, 8));
                $actual = array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)));
                if ($expected !== $actual || ! in_array(substr($header, 257, 6), ["ustar\0", 'ustar '], true)) {
                    throw new UpdateFailure('invalid_tar');
                }
                $name = $this->field(substr($header, 0, 100));
                $prefix = $this->field(substr($header, 345, 155));
                if ($prefix !== '') {
                    $name = $prefix.'/'.$name;
                }
                $this->safeName($name);
                $type = $header[156];
                $size = $this->octal(substr($header, 124, 12));
                if ($size > $options->integer('max_expanded_bytes')) {
                    throw new UpdateFailure('archive_too_large');
                }
                if (in_array($type, ['x', 'g', 'L'], true)) {
                    if ($size > 65536) {
                        throw new UpdateFailure('invalid_tar_metadata');
                    }
                    $data = $this->read($file, $size);
                    $this->read($file, (512 - $size % 512) % 512);
                    if ($type === 'L') {
                        $path = rtrim($data, "\0\n");
                        $this->safeName($path);
                        $pending['path'] = $path;
                    } else {
                        $meta = $this->pax($data);
                        if ($type === 'g' && (isset($meta['path']) || isset($meta['size']))) {
                            throw new UpdateFailure('invalid_tar_metadata');
                        }
                        if ($type === 'x') {
                            $pending = array_replace($pending, $meta);
                        }
                    }

                    continue;
                }
                if (! in_array($type, ['0', "\0", '5'], true)) {
                    throw new UpdateFailure('unsafe_archive_entry');
                }
                $name = $pending['path'] ?? $name;
                $size = isset($pending['size']) ? (int) $pending['size'] : $size;
                $pending = [];
                $this->safeName($name);
                if ($size > $options->integer('max_expanded_bytes') || ($type === '5' && $size !== 0)) {
                    throw new UpdateFailure('invalid_tar');
                }
                $output = null;
                if ($type !== '5') {
                    $base = basename($name);
                    if (str_ends_with(strtolower($base), '.mmdb')) {
                        if ($base !== $edition.'.mmdb' || $candidate !== null) {
                            throw new UpdateFailure('ambiguous_database');
                        }
                        $candidate = $stage.'/candidate.mmdb';
                        $output = $candidate;
                    } elseif (preg_match('/^(LICENSE|COPYRIGHT|README)(?:\.[A-Za-z0-9_-]+)?$/D', $base)) {
                        if (isset($notices[$base])) {
                            throw new UpdateFailure('ambiguous_notice');
                        }
                        $output = $stage.'/notice-'.count($notices);
                        $notices[$base] = $output;
                    }
                }
                $destination = $output === null ? null : @fopen($output, 'xb');
                if ($destination === false) {
                    throw new UpdateFailure('disk_error');
                }
                try {
                    for ($left = $size; $left > 0;) {
                        $data = $this->read($file, min(8192, $left));
                        $left -= strlen($data);
                        if ($destination !== null && @fwrite($destination, $data) !== strlen($data)) {
                            throw new UpdateFailure('disk_error');
                        }
                    }
                } finally {
                    if (is_resource($destination)) {
                        fclose($destination);
                    }
                }
                $this->read($file, (512 - $size % 512) % 512);
            }
            if ($candidate === null) {
                throw new UpdateFailure('missing_database');
            }

            return new ExtractedArchive($candidate, $notices);
        } finally {
            fclose($file);
            @unlink($tar);
        }
    }

    private function inflate(string $download, string $tar, int $limit): void
    {
        $input = @fopen($download, 'rb');
        if ($input === false) {
            throw new UpdateFailure('disk_error');
        }
        $output = null;
        try {
            if ($this->read($input, 2) !== "\x1f\x8b") {
                throw new UpdateFailure('unsupported_archive');
            }
            rewind($input);
            $output = @fopen($tar, 'xb');
            if ($output === false) {
                throw new UpdateFailure('disk_error');
            }
            $context = inflate_init(ZLIB_ENCODING_GZIP);
            $bytes = 0;
            $inputBytes = 0;
            while (! feof($input)) {
                $compressed = fread($input, 8192);
                if ($compressed === false) {
                    throw new UpdateFailure('invalid_gzip');
                }
                if ($compressed === '') {
                    break;
                }
                $inputBytes += strlen($compressed);
                $data = @inflate_add($context, $compressed, ZLIB_SYNC_FLUSH);
                if ($data === false) {
                    throw new UpdateFailure('invalid_gzip');
                }
                $bytes += strlen($data);
                if ($bytes > $limit) {
                    throw new UpdateFailure('archive_too_large');
                }
                if ($data !== '' && @fwrite($output, $data) !== strlen($data)) {
                    throw new UpdateFailure('disk_error');
                }
                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    if (inflate_get_read_len($context) !== $inputBytes || fread($input, 1) !== '') {
                        throw new UpdateFailure('invalid_gzip');
                    }
                    break;
                }
            }
            if (inflate_get_status($context) !== ZLIB_STREAM_END || $bytes % 512 !== 0) {
                throw new UpdateFailure('invalid_gzip');
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /** @param resource $file */
    private function read($file, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        $result = '';
        while (strlen($result) < $length) {
            $chunk = fread($file, $length - strlen($result));
            if ($chunk === false || $chunk === '') {
                throw new UpdateFailure('truncated_archive');
            }
            $result .= $chunk;
        }

        return $result;
    }

    private function octal(string $field): int
    {
        $value = trim($field, " \0");
        if ($value === '' || ! preg_match('/^[0-7]{1,12}$/D', $value)) {
            throw new UpdateFailure('invalid_tar');
        }

        return (int) octdec($value);
    }

    private function field(string $value): string
    {
        $trimmed = rtrim($value, "\0");
        if (str_contains($trimmed, "\0")) {
            throw new UpdateFailure('unsafe_archive_entry');
        }

        return $trimmed;
    }

    private function safeName(string $name): void
    {
        if ($name === '' || preg_match('/[\x00-\x1f\x7f\\\\:]/', $name) || str_starts_with($name, '/')
            || in_array('..', explode('/', $name), true)) {
            throw new UpdateFailure('unsafe_archive_entry');
        }
    }

    /** @return array<string, string> */
    private function pax(string $data): array
    {
        $values = [];
        while ($data !== '') {
            $space = strpos($data, ' ');
            if ($space === false || ! ctype_digit(substr($data, 0, $space))) {
                throw new UpdateFailure('invalid_tar_metadata');
            }
            $length = (int) substr($data, 0, $space);
            if ($length <= $space + 2 || $length > strlen($data) || $data[$length - 1] !== "\n") {
                throw new UpdateFailure('invalid_tar_metadata');
            }
            $pair = explode('=', substr($data, $space + 1, $length - $space - 2), 2);
            if (count($pair) !== 2 || ! in_array($pair[0], ['path', 'size', 'mtime', 'atime', 'ctime', 'uid', 'gid', 'uname', 'gname', 'comment', 'charset'], true)) {
                throw new UpdateFailure('unsupported_tar_metadata');
            }
            if ($pair[0] === 'path') {
                $this->safeName($pair[1]);
            }
            if ($pair[0] === 'size' && ! preg_match('/^[0-9]{1,10}$/D', $pair[1])) {
                throw new UpdateFailure('invalid_tar_metadata');
            }
            $values[$pair[0]] = $pair[1];
            $data = substr($data, $length);
        }

        return $values;
    }
}
