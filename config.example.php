<?php
// Bu dosyayi "config.php" olarak kopyala ve gercek degerlerini gir.
// config.php ASLA git'e eklenmemeli (.gitignore'da) ve mumkunse public_html'in
// disinda tutulmali (bkz. api/bootstrap.php icindeki yukleme yolu).

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'ruf_portal',
        'user'     => 'ruf_portal_user',
        'password' => 'CHANGE_ME',
    ],
    // openssl_encrypt/decrypt icin 32 baytlik rastgele bir anahtar.
    // Uretmek icin: php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'CHANGE_ME_32_BYTE_HEX_KEY',
    // cPanel Cron Job'un /trash/purge?token=... cagirirken kullanacagi paylasilan
    // sifre — gercek bir oturum degil, sadece bu tek endpoint'i disaridan
    // korumak icin. Uretmek icin: php -r "echo bin2hex(random_bytes(16));"
    'cron_secret' => 'CHANGE_ME_RANDOM_TOKEN',
    'session_name' => 'ruf_session',
    // Prod'da mutlaka true olmali (HTTPS zorunlu). Sadece yerel http test icin false.
    'secure_cookies' => true,
    // Google Cloud Console > APIs & Services > Credentials'tan alinir (Faz 3).
    'google' => [
        'client_id' => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',
        // oauth_authorize.php/oauth_callback.php'nin calistigi tam adresle birebir
        // eslesmeli (Google Cloud Console'daki "Authorized redirect URIs" ile ayni).
        'redirect_uri' => 'http://localhost:8899/oauth_callback.php',
    ],
];
