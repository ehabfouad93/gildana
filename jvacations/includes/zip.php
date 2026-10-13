<?php
declare(strict_types=1);

/**
 * Minimal ZIP reader/writer for .docx files, in plain PHP (zlib only).
 *
 * Avoids depending on the zip extension, which the Docker image does not ship:
 * adding it would mean rebuilding the container on a server shared with other
 * apps. A .docx is an ordinary ZIP of XML parts — store (0) and deflate (8) are
 * all Word ever writes, and template files are far below ZIP64 sizes.
 */

/**
 * @return array<string,string> entry name => uncompressed bytes
 * @throws RuntimeException when the bytes are not a readable ZIP
 */
function jv_zip_read(string $zip): array
{
    $len = strlen($zip);
    if ($len < 22 || substr($zip, 0, 2) !== 'PK') throw new RuntimeException('Not a ZIP file.');

    // End-of-central-directory record: within the last 64 KB + 22 bytes.
    $eocd = strrpos(substr($zip, max(0, $len - 65557)), "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('ZIP directory not found.');
    $eocd += max(0, $len - 65557);
    $e = unpack('vdisk/vcddisk/ventries_here/ventries/Vcd_size/Vcd_offset', substr($zip, $eocd + 4, 16));
    if ($e['cd_offset'] + $e['cd_size'] > $len || $e['entries'] > 5000) throw new RuntimeException('ZIP directory is damaged.');

    $out = [];
    $p = $e['cd_offset'];
    for ($i = 0; $i < $e['entries']; $i++) {
        if (substr($zip, $p, 4) !== "PK\x01\x02") throw new RuntimeException('ZIP directory is damaged.');
        $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voffset', substr($zip, $p + 4, 42));
        $name = substr($zip, $p + 46, $h['nlen']);
        $p += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];

        if ($h['flags'] & 1) throw new RuntimeException('Encrypted ZIP entries are not supported.');
        if (substr($name, -1) === '/') continue;                 // directory
        if (str_contains($name, '..')) throw new RuntimeException('Unsafe ZIP entry name.');

        $lh = $h['offset'];
        if (substr($zip, $lh, 4) !== "PK\x03\x04") throw new RuntimeException('ZIP entry is damaged.');
        $l = unpack('vnlen/vxlen', substr($zip, $lh + 26, 4));
        $raw = substr($zip, $lh + 30 + $l['nlen'] + $l['xlen'], $h['csize']);

        if ($h['method'] === 0)      $data = $raw;
        elseif ($h['method'] === 8)  $data = @gzinflate($raw);
        else throw new RuntimeException('Unsupported ZIP compression.');
        if ($data === false || strlen($data) !== $h['usize'] || ($h['usize'] > 0 && (crc32($data) & 0xFFFFFFFF) !== $h['crc'])) {
            throw new RuntimeException('ZIP entry failed its checksum.');
        }
        $out[$name] = $data;
    }
    return $out;
}

/** @param array<string,string> $files entry name => bytes, written in the given order */
function jv_zip_write(array $files): string
{
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

    $body = ''; $dir = ''; $n = 0;
    foreach ($files as $name => $data) {
        $name = (string) $name;
        $comp = (string) gzdeflate($data, 6);
        $crc  = crc32($data) & 0xFFFFFFFF;
        $off  = strlen($body);
        // Flag 0x0800: names are UTF-8.
        $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0) . $name . $comp;
        $dir  .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($data),
                      strlen($name), 0, 0, 0, 0, 0, $off) . $name;
        $n++;
    }
    return $body . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($dir), strlen($body), 0);
}
