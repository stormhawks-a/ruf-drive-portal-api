<?php

// Tek seferlik: Google Drive hesabini bu uygulamaya yetkilendirmek icin bu dosyayi
// taraycida ac, Google hesabinla giris yap ve izin ver. Basarili olursa
// oauth_callback.php refresh token'i veritabanina kaydedecek.

require __DIR__ . '/bootstrap.php';

header('Location: ' . GoogleOAuth::buildAuthUrl());
exit;
