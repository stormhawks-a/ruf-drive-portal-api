<?php

// Google'in oauth_authorize.php sonrasi geri yonlendirdigi adres. Kodu token'a
// cevirir ve sifrelenmis refresh token'i veritabanina kaydeder.

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

if (isset($_GET['error'])) {
    echo "Google yetkilendirmeyi reddetti: " . $_GET['error'] . "\n";
    exit;
}

$code = $_GET['code'] ?? null;
if ($code === null) {
    echo "Eksik 'code' parametresi.\n";
    exit;
}

try {
    GoogleOAuth::handleCallback($code);
    echo "Basarili! Google Drive hesabi bu uygulamaya baglandi.\n";
    echo "Refresh token sifrelenerek veritabanina kaydedildi, bir daha bu adimi tekrarlamana gerek yok.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Hata: " . $e->getMessage() . "\n";
}
