<?php

/**
 * Minimal, dependency-free Google Drive v3 client (raw cURL, no SDK/vendor folder).
 * Every call is server-to-server; the browser never talks to Google directly.
 */
final class GoogleDriveClient
{
    private const API_BASE = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';

    public static function createFolder(string $name, ?string $parentId = null): string
    {
        $metadata = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        if ($parentId !== null) {
            $metadata['parents'] = [$parentId];
        }

        $response = self::request('POST', self::API_BASE . '/files?fields=id', $metadata);
        return $response['id'];
    }

    /** Uploads raw bytes and returns the new Drive file id. */
    public static function uploadFile(string $name, ?string $parentId, string $mimeType, string $contents): string
    {
        $metadata = ['name' => $name];
        if ($parentId !== null) {
            $metadata['parents'] = [$parentId];
        }

        $boundary = 'ruf_drive_' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mimeType}\r\n\r\n"
            . $contents . "\r\n"
            . "--{$boundary}--";

        $ch = curl_init(self::UPLOAD_BASE . '/files?uploadType=multipart&fields=id');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . GoogleOAuth::getAccessToken(),
                'Content-Type: multipart/related; boundary=' . $boundary,
            ],
        ]);
        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = json_decode((string) $responseBody, true);
        if ($status >= 400 || !isset($data['id'])) {
            throw new RuntimeException('Drive yukleme basarisiz (HTTP ' . $status . '): ' . $responseBody);
        }
        return $data['id'];
    }

    /**
     * Opens a resumable upload session for a file whose bytes will arrive in chunks
     * (see routes/file_uploads.php) — the server never has to hold the whole file in
     * memory or a single HTTP request. Returns the one-time session URI that every
     * subsequent chunk PUT targets.
     */
    public static function createResumableSession(string $name, ?string $parentId, string $mimeType, int $totalBytes): string
    {
        $metadata = ['name' => $name];
        if ($parentId !== null) {
            $metadata['parents'] = [$parentId];
        }

        $ch = curl_init(self::UPLOAD_BASE . '/files?uploadType=resumable&fields=id');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($metadata),
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . GoogleOAuth::getAccessToken(),
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: ' . $mimeType,
                'X-Upload-Content-Length: ' . $totalBytes,
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if ($response === false || $status >= 400) {
            throw new RuntimeException('Drive resumable oturumu baslatilamadi (HTTP ' . $status . ').');
        }
        $headers = substr($response, 0, $headerSize);
        if (!preg_match('/^Location:\s*(\S+)/mi', $headers, $m)) {
            throw new RuntimeException('Drive oturum adresi alinamadi.');
        }
        return trim($m[1]);
    }

    /**
     * PUTs one chunk of a resumable session. $start is this chunk's byte offset in
     * the overall file. Returns bytesReceived (Drive's own count, read back from its
     * response — never assumed) and, once Drive has every byte, the finished file id.
     */
    public static function uploadChunk(string $sessionUri, string $chunkData, int $start, int $totalBytes): array
    {
        $end = $start + strlen($chunkData) - 1;
        $ch = curl_init($sessionUri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $chunkData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            // A slow outbound leg from shared hosting to Drive can legitimately take
            // longer than a short timeout to push one whole chunk through — 180s was
            // cutting real-but-slow transfers off mid-flight, which then had to be
            // retried from scratch and looked like the upload was crawling overall.
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_HTTPHEADER => [
                'Content-Range: bytes ' . $start . '-' . $end . '/' . $totalBytes,
                'Content-Length: ' . strlen($chunkData),
            ],
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException('Drive baglantisi basarisiz: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if ($status === 200 || $status === 201) {
            $data = json_decode($body, true);
            return ['done' => true, 'fileId' => $data['id'] ?? null, 'bytesReceived' => $totalBytes];
        }
        if ($status === 308) {
            $bytesReceived = $end + 1;
            if (preg_match('/^Range:\s*bytes=0-(\d+)/mi', $headers, $m)) {
                $bytesReceived = ((int) $m[1]) + 1;
            }
            return ['done' => false, 'fileId' => null, 'bytesReceived' => $bytesReceived];
        }
        throw new RuntimeException('Drive parca yuklemesi basarisiz (HTTP ' . $status . '): ' . $body);
    }

    /**
     * Asks Drive how many bytes of a resumable session it has actually persisted —
     * used to recover the true resume point if our own bytes_received bookkeeping
     * ever falls behind (e.g. the PHP process died right after Drive accepted a
     * chunk but before our DB update ran).
     */
    public static function queryResumableProgress(string $sessionUri, int $totalBytes): int
    {
        $ch = curl_init($sessionUri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Range: bytes */' . $totalBytes,
                'Content-Length: 0',
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);

        if ($status === 200 || $status === 201) {
            return $totalBytes;
        }
        if ($status === 308 && preg_match('/^Range:\s*bytes=0-(\d+)/mi', $headers, $m)) {
            return ((int) $m[1]) + 1;
        }
        return 0;
    }

    /** Streams a Drive file's bytes directly to the current HTTP response (never exposes the Drive URL). */
    public static function streamFile(string $fileId): void
    {
        self::streamFileTo($fileId, function (string $chunk): void {
            echo $chunk;
        });
    }

    /**
     * Streams a Drive file's bytes chunk-by-chunk through a callback instead of
     * echoing directly — lets callers (e.g. the ZIP writer) both forward the bytes
     * and track running size/CRC32 without ever buffering the whole file in memory.
     * An optional Range (e.g. "bytes=1000-1999") is forwarded to Drive as-is —
     * Drive's own alt=media endpoint honors it and returns just that slice, which is
     * what lets files_download resume an interrupted download instead of restarting
     * a possibly tens-of-GB file from byte zero.
     */
    public static function streamFileTo(string $fileId, callable $onChunk, ?string $range = null): void
    {
        $headers = ['Authorization: Bearer ' . GoogleOAuth::getAccessToken()];
        if ($range !== null) {
            $headers[] = 'Range: ' . $range;
        }
        $ch = curl_init(self::API_BASE . '/files/' . urlencode($fileId) . '?alt=media');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($onChunk) {
                $onChunk($chunk);
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($status >= 400) {
            throw new RuntimeException('Drive indirme basarisiz (HTTP ' . $status . ').');
        }
    }

    public static function deleteFile(string $fileId): void
    {
        self::request('DELETE', self::API_BASE . '/files/' . urlencode($fileId));
    }

    /**
     * Streams Drive's own pre-generated thumbnail (a small JPEG, regardless of the
     * original format) instead of the full original file — used for in-app previews
     * of jpg/png so large photos load fast; the real download endpoint still streams
     * the original via streamFile(). Returns false if Drive has no thumbnail yet
     * (e.g. a file uploaded moments ago), so the caller can fall back to the original.
     */
    public static function streamThumbnail(string $fileId, int $size = 480): bool
    {
        $meta = self::request('GET', self::API_BASE . '/files/' . urlencode($fileId) . '?fields=thumbnailLink');
        $thumbnailUrl = $meta['thumbnailLink'] ?? null;
        if ($thumbnailUrl === null) {
            return false;
        }
        // thumbnailLink comes back sized like "...=s220"; request the size we actually need.
        $thumbnailUrl = preg_replace('/=s\d+$/', '=s' . $size, $thumbnailUrl, 1) ?? $thumbnailUrl;

        $ch = curl_init($thumbnailUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status >= 400 || $body === false) {
            return false;
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        echo $body;
        return true;
    }

    private static function request(string $method, string $url, ?array $jsonBody = null): array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . GoogleOAuth::getAccessToken()];

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
        ];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($status >= 400) {
            throw new RuntimeException("Drive API istegi basarisiz ({$method} {$url}, HTTP {$status}): {$body}");
        }
        return $body ? (json_decode($body, true) ?? []) : [];
    }
}
