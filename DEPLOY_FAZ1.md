# Faz 1 Kurulum Talimatları (Natro cPanel)

Bu adımları sırayla uygula. Her adımda ne tıklayacağını/gireceğini görebilmen için mümkün olduğunca detaylı yazıldı.

## 1) MySQL veritabanı oluştur

1. cPanel → **Veri tabanları → MySQL® Veritabanları**
2. "Yeni Veritabanı" alanına bir isim yaz (örn. `ruf_portal`) → Oluştur.
3. Aynı sayfada "MySQL Kullanıcıları" bölümünden yeni bir kullanıcı oluştur (örn. `ruf_portal_user`), **güçlü bir şifre** üret ve not al.
4. "Kullanıcıyı Veritabanına Ekle" ile az önce oluşturduğun kullanıcıyı veritabanına bağla, yetkilerde **ALL PRIVILEGES** seç.
5. cPanel genelde veritabanı/kullanıcı adının önüne otomatik olarak `cpaneluser_` gibi bir önek ekler — gerçek tam isimler (örn. `natrocpaneluser_ruf_portal`) bir sonraki adımda lazım olacak, not al. 

u2756030_ruf_portal

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

## 7) DNS (Cloudflare) — teslim.workonruf.com'u yayına almak için

`workonruf.com`'un gerçek DNS yönetimi Natro'da değil, **Cloudflare**'de (nameserver'lar Cloudflare'e ait). Bu yüzden Natro cPanel'de subdomain oluşturmak DNS'i otomatik güncellemiyor — Cloudflare'de manuel bir kayıt gerekiyor:

1. Cloudflare → DNS → Records → **Add record**: Type `A`, Name `teslim`, IPv4 `94.73.151.149` (Natro sunucu IP'si).
2. SSL: Natro tarafında bu hesapta **hiçbir alan adı için AutoSSL/ücretsiz sertifika yok** (paylaşımlı hosting kısıtlaması, "Run AutoSSL" butonu bile yok). Çözüm: Cloudflare proxy'sini (turuncu bulut) aç, **SSL/TLS → Overview → Flexible** moduna al. Böylece tarayıcı-Cloudflare arası HTTPS çalışır, Cloudflare-Natro arası düz HTTP kullanılır (Natro'da sertifika gerekmez).

## 8) Bilinen sorun / Natro desteği gerekiyor (11 Temmuz 2026 itibarıyla ÇÖZÜLMEDİ)

Yukarıdaki 1-4. adımlar tamamlandı: veritabanı, şema, `config.php`, GitHub + cPanel Git klonlama — hepsi doğru ve doğrulandı (Dosya Yöneticisi'nde `api/` altında tüm dosyalar mevcut, doğru izinlerle 644/755).

Ancak **5. ve 6. adımlara hiç geçilemedi** çünkü `https://teslim.workonruf.com/api/...` altındaki HİÇBİR dosyaya (hatta düz bir `.txt` test dosyasına bile) ulaşılamıyor — hepsi 404 dönüyor. Bu, `.htaccess`/PHP ile ilgili değil (düz metin dosyası da aynı hatayı veriyor); Natro'nun web sunucusunun (Apache/LiteSpeed) bu alt alan adı için sanal sunucu (vhost) yapılandırmasını doğru kurmamış/güncellememiş olması ihtimali yüksek.

Denenen ve işe yaramayan self-servis çözümler:
- cPanel'de alt alan adını silip aynı Belge Kök Dizini (`/teslim.workonruf.com`) ile yeniden oluşturmak (vhost'u "resetlemek")
- Cloudflare Flexible SSL ile SSL'i devre dışı bırakıp saf HTTP üzerinden test etmek

**Sıradaki adım**: Natro destek/canlı desteğe şu talebi iletmek:
> "teslim.workonruf.com alt alan adının Belge Kök Dizini `/teslim.workonruf.com` olarak ayarlı ve içinde gerçek dosyalar var (örn. `/teslim.workonruf.com/api/ping.txt`), fakat bu adrese giriş yaptığımda "404 Not Found" alıyorum. Alt alan adını silip yeniden oluşturdum ama sorun devam ediyor. Bu alt alan adı için Apache/LiteSpeed sanal sunucu (vhost) yapılandırmasını kontrol edip yeniden oluşturabilir misiniz (rebuildhttpdconf)?"

Natro bunu çözdükten sonra buradan, 5. adımdan devam edilecek.

---

Bu adımları tamamladığında bana haber ver — Faz 2'ye (frontend'i bu API'ye bağlama) geçeriz.
