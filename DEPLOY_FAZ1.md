# Faz 1 Kurulum Talimatları (Natro cPanel)

Bu adımları sırayla uygula. Her adımda ne tıklayacağını/gireceğini görebilmen için mümkün olduğunca detaylı yazıldı.

## 1) MySQL veritabanı oluştur

1. cPanel → **Veri tabanları → MySQL® Veritabanları**
2. "Yeni Veritabanı" alanına bir isim yaz (örn. `ruf_portal`) → Oluştur.
3. Aynı sayfada "MySQL Kullanıcıları" bölümünden yeni bir kullanıcı oluştur (örn. `ruf_portal_user`), **güçlü bir şifre** üret ve not al.
4. "Kullanıcıyı Veritabanına Ekle" ile az önce oluşturduğun kullanıcıyı veritabanına bağla, yetkilerde **ALL PRIVILEGES** seç.
5. cPanel genelde veritabanı/kullanıcı adının önüne otomatik olarak `cpaneluser_` gibi bir önek ekler — gerçek tam isimler (örn. `natrocpaneluser_ruf_portal`) bir sonraki adımda lazım olacak, not al.

## 2) Şemayı yükle

1. cPanel → **Veri tabanları → phpMyAdmin**
2. Sol menüden az önce oluşturduğun veritabanını seç.
3. Üstteki **SQL** sekmesine gir, bu projedeki `api/schema.sql` dosyasının **tüm içeriğini** kopyala-yapıştır, **Git (Go)** tıkla.
4. Sol menüde 11 tablonun oluştuğunu gör (users, folders, files, shared_links, ...).

## 3) config.php oluştur (sunucuda, ASLA GitHub'a gitmeyecek)

1. cPanel → **Dosyalar → Dosya Yöneticisi** ile `api/` klasörünün konacağı yere git (bir sonraki adımda Git ile bu klasör zaten oluşacak — eğer henüz oluşmadıysa bu adımı Git kurulumundan SONRA yap).
2. `api/config.example.php` dosyasını kopyalayıp `api/config.php` olarak kaydet.
3. İçini gerçek bilgilerle doldur:
   - `db.host` → genelde `localhost`
   - `db.name` → adım 1'deki tam veritabanı adı
   - `db.user` → adım 1'deki tam kullanıcı adı
   - `db.password` → adım 1'de not aldığın şifre
   - `app_secret` → rastgele 32 baytlık bir hex string (bunu yerelinde `php -r "echo bin2hex(random_bytes(32));"` ile üretip buraya yapıştırabilirsin, ya da bana söylersen ben üretirim)
   - `secure_cookies` → `true` bırak (HTTPS zorunlu)

## 4) Kodu GitHub'a koy + cPanel Git ile bağla

1. GitHub'da yeni, **private** bir repo oluştur (örn. `ruf-drive-portal`).
2. Bu projedeki `api/` klasörünü o repoya gönder (ben bu adımı senin adına yapabilirim, GitHub reposunun linkini ver yeter — ya da sen `git push` edersin).
3. cPanel → **Dosyalar → Git™ Version Control** → **Create**.
4. "Clone a Repository" seçip GitHub reposunun URL'sini yapıştır (private repo ise cPanel'in gösterdiği SSH public key'i GitHub reposunun "Deploy keys" kısmına ekle).
5. Repository path'i, `api/` klasörünün son halde nerede duracağını belirler (örn. `public_html/api` ya da `teslim` subdomain'i için ayrılan klasörün altına `/api`).
6. Klonlama bitince "Manage" sayfasına gir, **"Pull or Deploy"** sekmesinden "Update from Remote" ile en güncel hali çek.

## 5) İlk admin hesabını oluştur

1. Tarayıcıda `https://<subdomain-veya-gecici-adres>/api/seed.php` adresine git.
2. Ekranda çıkan **kullanıcı adı + şifreyi** güvenli bir yere kaydet (bir daha gösterilmeyecek).
3. **Hemen ardından** cPanel Dosya Yöneticisi'nden `api/seed.php` dosyasını sil (güvenlik için — bu script bir daha çalışmayacak şekilde tasarlandı ama dosyanın sunucuda durması yine de gereksiz risk).

## 6) Test et

Tarayıcıdan ya da `curl` ile:
```
curl -i https://<adres>/api/me
```
`{"user":null}` dönmeli (henüz giriş yapılmadı). Sonra adım 5'teki bilgilerle:
```
curl -i -c cookies.txt -X POST https://<adres>/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin","password":"<seed.php cikti sifresi>"}'
```
Admin bilgilerini JSON olarak döndürmeli.

---

Bu adımları tamamladığında bana haber ver — Faz 2'ye (frontend'i bu API'ye bağlama) geçeriz.
