<?php

/**
 * Minimal, dependency-free streaming ZIP writer — Natro's shared PHP build may
 * not have the zip extension enabled, and ZipArchive would require buffering
 * everything to a temp file first anyway. This writes STORE-method (no
 * compression) entries directly to the HTTP response as bytes arrive from
 * Drive, so memory use stays flat regardless of total archive size. Uses the
 * "data descriptor" trailer (ZIP spec bit 3) so per-file CRC32/size never has
 * to be known before the bytes are streamed.
 */
final class ZipStreamer
{
    private const SIG_LOCAL = 0x04034b50;
    private const SIG_DATA_DESCRIPTOR = 0x08074b50;
    private const SIG_CENTRAL = 0x02014b50;
    private const SIG_EOCD = 0x06054b50;
    private const FLAG_UTF8_AND_DESCRIPTOR = 0x0808; // bit3: data descriptor follows, bit11: UTF-8 names

    /** @param array<int, array{name: string, driveFileId: string}> $entries */
    public static function stream(array $entries, string $downloadName): void
    {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        [$dosTime, $dosDate] = self::dosDateTime(time());
        $offset = 0;
        $central = [];

        foreach ($entries as $entry) {
            $nameBytes = $entry['name'];
            $nameLen = strlen($nameBytes);
            $localHeaderOffset = $offset;

            $localHeader = pack('V', self::SIG_LOCAL)
                . pack('v', 20)
                . pack('v', self::FLAG_UTF8_AND_DESCRIPTOR)
                . pack('v', 0)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', 0)
                . pack('V', 0)
                . pack('V', 0)
                . pack('v', $nameLen)
                . pack('v', 0)
                . $nameBytes;
            echo $localHeader;
            $offset += strlen($localHeader);

            $crcCtx = hash_init('crc32b');
            $size = 0;
            GoogleDriveClient::streamFileTo($entry['driveFileId'], function (string $chunk) use (&$size, $crcCtx): void {
                $size += strlen($chunk);
                hash_update($crcCtx, $chunk);
                echo $chunk;
            });
            $crc32 = hexdec(hash_final($crcCtx));
            $offset += $size;

            $dataDescriptor = pack('V', self::SIG_DATA_DESCRIPTOR)
                . pack('V', $crc32)
                . pack('V', $size)
                . pack('V', $size);
            echo $dataDescriptor;
            $offset += strlen($dataDescriptor);

            $central[] = [
                'name' => $nameBytes,
                'crc32' => $crc32,
                'size' => $size,
                'offset' => $localHeaderOffset,
            ];

            @flush();
        }

        $centralStart = $offset;
        $centralSize = 0;
        foreach ($central as $c) {
            $nameLen = strlen($c['name']);
            $header = pack('V', self::SIG_CENTRAL)
                . pack('v', 20)
                . pack('v', 20)
                . pack('v', self::FLAG_UTF8_AND_DESCRIPTOR)
                . pack('v', 0)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $c['crc32'])
                . pack('V', $c['size'])
                . pack('V', $c['size'])
                . pack('v', $nameLen)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('V', 0)
                . pack('V', $c['offset'])
                . $c['name'];
            echo $header;
            $centralSize += strlen($header);
        }

        echo pack('V', self::SIG_EOCD)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', count($central))
            . pack('v', count($central))
            . pack('V', $centralSize)
            . pack('V', $centralStart)
            . pack('v', 0);
        @flush();
    }

    /** @return array{0: int, 1: int} [dosTime, dosDate] */
    private static function dosDateTime(int $timestamp): array
    {
        $d = getdate($timestamp);
        $year = max($d['year'], 1980);
        $dosTime = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $dosDate = (($year - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        return [$dosTime, $dosDate];
    }
}
