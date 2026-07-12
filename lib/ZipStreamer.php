<?php

/**
 * Minimal, dependency-free streaming ZIP writer — Natro's shared PHP build may
 * not have the zip extension enabled, and ZipArchive would require buffering
 * everything to a temp file first anyway. This writes STORE-method (no
 * compression) entries directly to the HTTP response as bytes arrive from
 * Drive, so memory use stays flat regardless of total archive size. Uses the
 * "data descriptor" trailer (ZIP spec bit 3) so per-file CRC32/size never has
 * to be known before the bytes are streamed.
 *
 * Always writes ZIP64 format (unconditionally, even for small entries) —
 * customer video files routinely exceed the plain ZIP format's 4GB-per-field
 * limit (32-bit size/offset), and mixing "zip64 only for the big ones" adds a
 * lot of conditional-format complexity for no real benefit; every mainstream
 * unzip tool (Windows Explorer, macOS Archive Utility, 7-Zip, unzip) reads
 * zip64 archives fine regardless of whether they need it.
 */
final class ZipStreamer
{
    private const SIG_LOCAL = 0x04034b50;
    private const SIG_DATA_DESCRIPTOR = 0x08074b50;
    private const SIG_CENTRAL = 0x02014b50;
    private const SIG_ZIP64_EOCD = 0x06064b50;
    private const SIG_ZIP64_EOCD_LOCATOR = 0x07064b50;
    private const SIG_EOCD = 0x06054b50;
    private const FLAG_UTF8_AND_DESCRIPTOR = 0x0808; // bit3: data descriptor follows, bit11: UTF-8 names
    private const VERSION_ZIP64 = 45; // 4.5 — minimum version that understands zip64

    /**
     * Exact final byte count of the archive this would produce, given each entry's
     * name and (already-known, from DB) size — lets the caller set a real
     * Content-Length header so the browser's own download UI shows accurate
     * progress/ETA, without needing to buffer anything to compute it.
     *
     * @param array<int, array{name: string, size: int}> $entries
     */
    public static function calculateTotalSize(array $entries): int
    {
        $total = 0;
        foreach ($entries as $entry) {
            $nameLen = strlen($entry['name']);
            $total += 30 + $nameLen + 20; // local header (30) + name + zip64 extra field (20)
            $total += (int) $entry['size']; // file bytes (STORE = no compression)
            $total += 4 + 4 + 8 + 8; // data descriptor: sig + crc32 + zip64 csize + zip64 usize
            $total += 46 + $nameLen + 28; // central directory header (46) + name + zip64 extra field (28)
        }
        $total += 56; // zip64 EOCD record
        $total += 20; // zip64 EOCD locator
        $total += 22; // standard EOCD record (comment length 0)
        return $total;
    }

    /** @param array<int, array{name: string, driveFileId: string, size: int}> $entries */
    public static function stream(array $entries, string $downloadName): void
    {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        header('Content-Length: ' . self::calculateTotalSize($entries));
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

            // Zip64 extra field (local): header id + data size + placeholder 8-byte
            // uncompressed/compressed sizes (real values live in the data descriptor
            // that follows the file bytes, per the bit-3 "streamed" convention).
            $zip64ExtraLocal = pack('v', 0x0001) . pack('v', 16) . pack('P', 0) . pack('P', 0);

            $localHeader = pack('V', self::SIG_LOCAL)
                . pack('v', self::VERSION_ZIP64)
                . pack('v', self::FLAG_UTF8_AND_DESCRIPTOR)
                . pack('v', 0)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', 0)          // crc32 — deferred to data descriptor
                . pack('V', 0xFFFFFFFF) // compressed size — zip64 marker
                . pack('V', 0xFFFFFFFF) // uncompressed size — zip64 marker
                . pack('v', $nameLen)
                . pack('v', strlen($zip64ExtraLocal))
                . $nameBytes
                . $zip64ExtraLocal;
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

            // Zip64 data descriptor: crc32 stays 4 bytes, sizes are 8 bytes each.
            $dataDescriptor = pack('V', self::SIG_DATA_DESCRIPTOR)
                . pack('V', $crc32)
                . pack('P', $size)
                . pack('P', $size);
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
            // Zip64 extra field (central): real 8-byte uncompressed size, compressed
            // size, and local-header offset — this is what actually lets a reader
            // locate/verify entries whose true size or offset exceeds 4GB.
            $zip64ExtraCentral = pack('v', 0x0001) . pack('v', 24)
                . pack('P', $c['size'])
                . pack('P', $c['size'])
                . pack('P', $c['offset']);

            $header = pack('V', self::SIG_CENTRAL)
                . pack('v', self::VERSION_ZIP64)
                . pack('v', self::VERSION_ZIP64)
                . pack('v', self::FLAG_UTF8_AND_DESCRIPTOR)
                . pack('v', 0)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $c['crc32'])
                . pack('V', 0xFFFFFFFF) // compressed size — zip64 marker
                . pack('V', 0xFFFFFFFF) // uncompressed size — zip64 marker
                . pack('v', $nameLen)
                . pack('v', strlen($zip64ExtraCentral))
                . pack('v', 0)  // file comment length
                . pack('v', 0)  // disk number start
                . pack('v', 0)  // internal file attributes
                . pack('V', 0)  // external file attributes
                . pack('V', 0xFFFFFFFF) // relative offset of local header — zip64 marker
                . $c['name']
                . $zip64ExtraCentral;
            echo $header;
            $centralSize += strlen($header);
        }

        $zip64EocdOffset = $centralStart + $centralSize;
        $entryCount = count($central);

        // Zip64 End of Central Directory record — the authoritative entry
        // count/sizes/offsets when any of them don't fit in the classic 32-bit
        // fields (which is unconditionally the case here, see class docblock).
        echo pack('V', self::SIG_ZIP64_EOCD)
            . pack('P', 44) // size of remaining zip64 EOCD record (fixed portion, no extensible data sector)
            . pack('v', self::VERSION_ZIP64)
            . pack('v', self::VERSION_ZIP64)
            . pack('V', 0) // number of this disk
            . pack('V', 0) // disk with start of central directory
            . pack('P', $entryCount) // entries on this disk
            . pack('P', $entryCount) // total entries
            . pack('P', $centralSize)
            . pack('P', $centralStart);

        // Zip64 End of Central Directory locator — points the reader at the
        // zip64 EOCD record above.
        echo pack('V', self::SIG_ZIP64_EOCD_LOCATOR)
            . pack('V', 0) // disk with start of zip64 EOCD
            . pack('P', $zip64EocdOffset)
            . pack('V', 1); // total number of disks

        // Standard End of Central Directory record — kept for compatibility with
        // tools that only look here first; size/offset fields are zip64 sentinels
        // (0xFFFFFFFF) since the real values live in the zip64 EOCD above.
        echo pack('V', self::SIG_EOCD)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', min($entryCount, 0xFFFF))
            . pack('v', min($entryCount, 0xFFFF))
            . pack('V', 0xFFFFFFFF)
            . pack('V', 0xFFFFFFFF)
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
