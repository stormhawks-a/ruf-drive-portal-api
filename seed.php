<?php

// TEK SEFERLIK kurulum scripti: ilk gercek ADMIN hesabini olusturur.
// Hem terminalden ("php seed.php") hem de taraycidan bu dosyaya girerek
// calistirilabilir. Calistirdiktan sonra GUVENLIK ICIN BU DOSYAYI SIL
// (ya da en azindan .htaccess ile erisimi engelle) -- rastgele bir sifre
// uretip EKRANDA GOSTERIR, bir daha asla geri okunamaz.

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$existing = Db::queryOne("SELECT COUNT(*) as c FROM users WHERE role = 'ADMIN'");
if ((int) $existing['c'] > 0) {
    echo "Zaten en az bir ADMIN hesabi var, tekrar calistirilmadi.\n";
    echo "Yeni personel/musteri eklemek icin uygulama icindeki 'Personel Yonetimi' ekranini kullan.\n";
    exit;
}

// --- BURAYI DUZENLE ---
$adminName = 'Yönetici';
$adminEmail = 'admin@workonruf.com';
$adminUsername = 'admin';
// ----------------------

$password = bin2hex(random_bytes(6));
$id = Ids::generate('user');

Db::execute(
    'INSERT INTO users (id, name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)',
    [$id, $adminName, $adminEmail, $adminUsername, Auth::hash($password), 'ADMIN']
);

echo "Admin hesabı oluşturuldu.\n\n";
echo "Kullanıcı adı / e-posta: {$adminUsername} / {$adminEmail}\n";
echo "Şifre (SADECE ŞİMDİ gösteriliyor, bir daha gösterilmeyecek): {$password}\n\n";
echo "Bu şifreyi güvenli bir yere kaydet, sonra bu dosyayı (api/seed.php) sunucudan SİL.\n";
