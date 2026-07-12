-- RUF Drive Portal — MySQL schema (Faz 1)
-- Bu dosyayi phpMyAdmin'de, olusturdugun bos veritabaninin uzerinde "SQL" sekmesinden
-- calistir. MySQL 5.7+ / 8.0 / MariaDB 10.3+ ile uyumlu olacak sekilde yazildi
-- (JSON kolon veya version-specific ozellik kullanilmadi).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users: personel (ADMIN/EDITOR) ve musteriler (CUSTOMER) gercek hesap olarak
-- burada tutulur. CONSUMER (paylasim linki ile gelen dis ziyaretci) burada HIC
-- satir olarak tutulmaz -- tamamen shared_links'e bagli, gecici bir oturumdur.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            VARCHAR(40)  NOT NULL PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NULL,
  username      VARCHAR(100) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('ADMIN','EDITOR','CUSTOMER') NOT NULL,
  avatar_url    VARCHAR(500) NULL,
  folder_id     VARCHAR(40)  NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- folders: sanal klasor agaci. Faz 3'te her satir gercek bir Google Drive
-- klasorune (drive_folder_id) karsilik gelecek; simdilik NULL kalabilir.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS folders (
  id                VARCHAR(40)  NOT NULL PRIMARY KEY,
  name              VARCHAR(255) NOT NULL,
  parent_id         VARCHAR(40)  NULL,
  drive_folder_id   VARCHAR(120) NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at        DATETIME     NULL,
  deleted_by        VARCHAR(40)  NULL,
  KEY idx_folders_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE folders
  ADD CONSTRAINT fk_folders_parent FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE;

ALTER TABLE users
  ADD CONSTRAINT fk_users_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- files: dosya metadata'si. Faz 3'e kadar drive_file_id NULL/placeholder kalir,
-- gercek baytlar Faz 3'te Google Drive'a yuklenmeye baslar.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS files (
  id               VARCHAR(40)  NOT NULL PRIMARY KEY,
  name             VARCHAR(255) NOT NULL,
  original_name    VARCHAR(255) NULL,
  size_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  mime_type        VARCHAR(150) NULL,
  file_type        ENUM('pdf','image','video','audio','doc','sheet','other') NOT NULL DEFAULT 'other',
  parent_id        VARCHAR(40)  NULL,
  owner_id         VARCHAR(40)  NOT NULL,
  drive_file_id    VARCHAR(120) NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME     NULL,
  deleted_by       VARCHAR(40)  NULL,
  KEY idx_files_parent (parent_id),
  KEY idx_files_owner (owner_id),
  CONSTRAINT fk_files_parent FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
  CONSTRAINT fk_files_owner  FOREIGN KEY (owner_id)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- shared_links: dis paylasim baglantilari (CONSUMER erisimi buradan gecer).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shared_links (
  id               VARCHAR(40)  NOT NULL PRIMARY KEY,
  name             VARCHAR(255) NOT NULL,
  created_by_id    VARCHAR(40)  NOT NULL,
  recipient_name   VARCHAR(255) NULL,
  password_hash    VARCHAR(255) NULL,
  expires_at       DATETIME     NULL,
  download_count   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at       DATETIME     NULL,
  -- view_mode: 'consumer' = basit WeTransfer tarzi indirme listesi (tek seviye,
  -- onizlemesiz). 'customer' = musterinin kendi panelindeki gibi tam gezinme +
  -- onizleme (salt-okunur). customer_user_id doluysa bu link belirli bir
  -- musterinin "kalici" paylasim linkidir (Paylas butonundan üretilen/yenilenen).
  view_mode        ENUM('consumer','customer') NOT NULL DEFAULT 'consumer',
  customer_user_id VARCHAR(40)  NULL,
  CONSTRAINT fk_links_creator FOREIGN KEY (created_by_id) REFERENCES users(id),
  CONSTRAINT fk_links_customer FOREIGN KEY (customer_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_link_files (
  shared_link_id VARCHAR(40) NOT NULL,
  file_id        VARCHAR(40) NOT NULL,
  PRIMARY KEY (shared_link_id, file_id),
  CONSTRAINT fk_slf_link FOREIGN KEY (shared_link_id) REFERENCES shared_links(id) ON DELETE CASCADE,
  CONSTRAINT fk_slf_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_link_folders (
  shared_link_id VARCHAR(40) NOT NULL,
  folder_id      VARCHAR(40) NOT NULL,
  PRIMARY KEY (shared_link_id, folder_id),
  CONSTRAINT fk_slfo_link   FOREIGN KEY (shared_link_id) REFERENCES shared_links(id) ON DELETE CASCADE,
  CONSTRAINT fk_slfo_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- audit_logs: islem kayitlari (ip_address artik gercek $_SERVER['REMOTE_ADDR']).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     VARCHAR(40) NULL,
  user_name   VARCHAR(150) NOT NULL,
  user_role   VARCHAR(20) NOT NULL,
  action      ENUM('LOGIN','FILE_UPLOAD','FILE_DELETE','FILE_RENAME','FOLDER_CREATE','FOLDER_RENAME',
                    'FOLDER_RESTORE','FILE_RESTORE',
                    'DRIVE_SYNC','LINK_CREATE','FILE_DOWNLOAD','BULK_DOWNLOAD','FILE_PREVIEW',
                    'PERMISSION_CHANGE','BACKGROUND_CHANGE') NOT NULL,
  details     TEXT NOT NULL,
  ip_address  VARCHAR(45) NULL,
  KEY idx_audit_user (user_id),
  KEY idx_audit_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- background_settings + background_collage_images: arkaplan havuzu. Gercek
-- gorsel/video baytlari Faz 3'te Drive'da tutulur, burada sadece drive_file_id
-- referansi olur.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS background_settings (
  id              VARCHAR(40)  NOT NULL PRIMARY KEY,
  name            VARCHAR(255) NOT NULL,
  type            ENUM('image','video','slider','collage') NOT NULL,
  drive_file_id_1 VARCHAR(120) NULL,
  drive_file_id_2 VARCHAR(120) NULL,
  slider_position TINYINT UNSIGNED NULL,
  title           VARCHAR(255) NULL,
  subtitle        VARCHAR(255) NULL,
  cta_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  cta_style       ENUM('cursor','fixed') NULL,
  cta_label       VARCHAR(100) NULL,
  cta_url         VARCHAR(500) NULL,
  sort_order      INT NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS background_collage_images (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  background_settings_id VARCHAR(40) NOT NULL,
  drive_file_id          VARCHAR(120) NOT NULL,
  sort_order             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_collage_bg FOREIGN KEY (background_settings_id) REFERENCES background_settings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- google_oauth_tokens: TEK satir. Faz 3'e kadar bos kalir.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS google_oauth_tokens (
  id                       TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  refresh_token_encrypted  TEXT NULL,
  access_token             TEXT NULL,
  access_token_expires_at  DATETIME NULL,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- app_settings: tekil anahtar/deger ayarlari (orn. drive_root_folder_id).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
