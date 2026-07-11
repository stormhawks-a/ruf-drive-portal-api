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

    /** Streams a Drive file's bytes directly to the current HTTP response (never exposes the Drive URL). */
    public static function streamFile(string $fileId): void
    {
        $ch = curl_init(self::API_BASE . '/files/' . urlencode($fileId) . '?alt=media');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . GoogleOAuth::getAccessToken()],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
                echo $chunk;
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
