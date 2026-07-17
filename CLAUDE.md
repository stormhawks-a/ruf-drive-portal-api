# RUF Drive Portal — Backend

PHP (no framework, no Composer) + MySQL API for a client file-delivery portal.
Google Drive is the real storage backend but must **never** be visible to
customers — no `drive.google.com` URLs, no Drive branding, anywhere in a
customer-facing response.

Repo scope: this is `api/` only. The frontend (`src/`, Vite/React) lives in the
parent project folder and currently has **no git repository at all** — it's
deployed by manually building locally and uploading the `dist/` output via
cPanel File Manager. If that ever needs to change, set up a separate repo for
it; don't assume frontend changes are backed up anywhere until then.

## Architecture

- **Router**: `index.php` is the single entry point. `.htaccess` rewrites
  anything that isn't a real file/dir to it. Route files in `routes/*.php`
  return `[[METHOD, regexPattern, handlerFnName], ...]` arrays and are all
  `glob()`-loaded in `index.php`, so every route handler function is globally
  callable from every other route file — no imports needed between them.
- **Auth**: two independent identities, deliberately on **separate cookies**:
  - `ruf_session` — real ADMIN/EDITOR/CUSTOMER logins, a native PHP session.
  - `ruf_share_session` — anonymous share-link visitors, a self-contained
    AES-256-GCM encrypted cookie (see "PHP sessions" gotcha below for why this
    isn't just a second PHP session).
  Route handlers that must work for both call
  `$user = Auth::currentUser() ?? shared_links_resolve_acting_user();` — the
  latter resolves the *real* customer row behind a "Müşteri" (customer)
  view-mode share link, letting ordinary `Scope`/`AuditLogger` code work
  unchanged for anonymous visitors acting as that customer.
- **Google Drive**: single personal Drive account, OAuth2 refresh token stored
  encrypted in `google_oauth_tokens` (one row). `GoogleDriveClient.php` is raw
  cURL, no SDK. `app_settings.drive_root_folder_id` is the one Drive folder
  everything else lives under.
- **ZIP downloads**: `lib/ZipStreamer.php` is a dependency-free streaming ZIP
  writer (Natro's PHP build may not have `zip` enabled, and `ZipArchive` would
  need buffering to a temp file anyway). Writes **zip64 unconditionally** for
  every entry — see gotcha below.
- **Image thumbnails**: `GET /files/{id}/thumbnail?size=N` proxies Drive's own
  pre-generated thumbnail (always a small JPEG, regardless of source format)
  instead of the original file — used by the frontend for jpg/png previews
  (grid cards, folder mini-collages, the preview modal) so browsing large
  photos is fast. Same auth/scope check as `files_download`, just a different
  byte source. The real download endpoint is untouched and always streams the
  original via `streamFile()`. Drive's `thumbnailLink` needs a fresh
  `files.get` metadata call every time (no caching), so this has the same
  multi-second Drive round-trip latency as any other Drive API call — expect
  it, don't chase it as a bug.
- **Persistent share links are two independent slots per customer**, not one
  "the current link" — `shared_links_get_by_customer` takes a `viewMode`
  query param (`customer` = full panel, `consumer` = download-only) and only
  ever looks at/revokes the matching slot. Renewing one mode must never touch
  the other's still-valid link; if a "renew" or "revoke" flow ever stops
  taking `viewMode` explicitly, this guarantee breaks silently.
- **Background media (`background_settings` / `background_collage_images`)** is
  real server state now, not a leftover-from-the-demo `localStorage` blob — it
  used to live entirely in the browser that last opened Settings, so a
  background configured on one computer was invisible on every other device/
  share-link visitor (the actual bug that motivated building this). Media
  files live in Drive under a lazily-created "Site Arkaplanlari" folder
  (`app_settings.background_media_drive_folder_id`, same lazy-create-once
  pattern as `drive_root_folder_id`); the DB rows only ever hold
  `drive_file_id_1`/`drive_file_id_2` plus text/CTA fields. `GET
  /background-settings` and the two media-streaming routes use the same
  dual-auth check as `files_download` (`Auth::currentUser() ?? share-link
  session`) — any staff login, real customer, or anonymous share-link visitor
  can read it, since the background shows in all three contexts; only
  create/update/delete/upload are `ADMIN`-only. `api/.user.ini` raises
  `upload_max_filesize`/`post_max_size` to 100M for this (PHP's 2-8M defaults
  were fine for tiny JSON bodies but rejected anything but a small photo) — if
  a production upload still fails above a few MB, set the same values via
  cPanel's MultiPHP INI Editor instead, since `.user.ini` only takes effect
  under CGI/FastCGI PHP, not mod_php.
- **Reversible password storage** (`users.password_encrypted`,
  `shared_links.password_encrypted`): AES-256-GCM via `lib/Crypto.php`,
  purely so an admin can look up "what did we set this to" from an edit
  form. `password_hash` (bcrypt) remains the only thing actually checked at
  login — the encrypted copy is never used for auth, and is only ever
  returned by narrow, role-gated, single-record endpoints
  (`GET /users/{id}/password` is `ADMIN`-only), never bundled into list
  responses.
- **Real folder downloads** (no ZIP at all): the frontend uses the File System
  Access API (`showDirectoryPicker`) for Chrome/Edge, falling back to the ZIP
  endpoint for Safari/Firefox, which don't implement that API.
- **Large file uploads (100MB–tens of GB)**: `routes/file_uploads.php` +
  `GoogleDriveClient::createResumableSession/uploadChunk`. The browser (see
  `uploadLargeWithProgress` in `src/api.ts`) slices the file into 16MB chunks
  and `PUT`s them one at a time to `/file-uploads/{id}/chunk`; this server
  relays each chunk straight into a Drive resumable-upload session and never
  buffers more than one chunk in memory, so the file size this can handle
  isn't bounded by PHP's `memory_limit`. `filesApi.createWithProgress()`
  switches to this path automatically above `LARGE_UPLOAD_THRESHOLD_BYTES`
  (80MB) — everything below that still goes through the old single-request
  multipart POST. A dropped chunk retries with exponential backoff and
  re-queries `/file-uploads/{id}` for the real byte count before resending,
  so a flaky connection resumes instead of restarting the whole file.
  **A direct browser→Drive upload (skipping this server as a relay entirely)
  is not possible** — verified with a real browser test against a live Drive
  resumable session: Google's upload endpoint sends no
  `Access-Control-Allow-Origin` header, so the browser blocks it as a CORS
  violation before any bytes move. Don't re-attempt this; it's a Google-side
  limitation, not something fixable from our code.
- **Downloads survive very large files too**: `files_download` sets
  `@set_time_limit(0)` (a many-GB download can outlast PHP's default limit on
  an ordinary connection) and forwards an incoming `Range` header straight to
  Drive's own `alt=media` endpoint, which honors it natively — this is what
  lets a browser's built-in "resume interrupted download" actually work,
  responding `206 Partial Content` with the right `Content-Range`.
- **Download-count stats** (`files.download_count`, `folders.download_count`,
  `GET/DELETE /files/download-stats`, admin-only): a folder download must
  count as **one** row, never one row per file inside it, no matter which of
  the two completely different download paths produced it. `zip_download`
  (Safari/Firefox, or an explicit ZIP request) increments `files.download_count`
  only for individually-picked file ids (`zip_collect_entries`'s
  `directFileIds`) and `folders.download_count` once per requested folder id
  (`processedFolderIds`) — files swept in recursively from inside a folder
  never bump their own count. The **other** download path — Chrome/Edge's
  native "real folder structure" write via `showDirectoryPicker`
  (`src/lib/folderDownload.ts`) — fetches every file individually through
  `files_download`, so those requests pass `?skipCount=1` and the frontend
  separately calls `POST /folders/{id}/register-download` once per top-level
  folder instead. Forgetting either path's special-case makes the stats panel
  explode into dozens of rows the moment someone downloads a folder that way.
  Range-request continuations (a resumed/seeked download re-hitting
  `files_download`) are also excluded from counting (`$isContinuation` check)
  for the same reason — one logical download must stay one count regardless
  of how many HTTP requests it took.
- **Editors can manage CUSTOMER accounts, never ADMIN/EDITOR ones.**
  `users_create/update/delete` accept `EDITOR` now (previously ADMIN-only,
  which silently 403'd the "Yeni Müşteri Ekle" button the frontend already
  showed editors), but every one of those three checks
  `$actor['role'] === 'EDITOR' && target/body role !== 'CUSTOMER'` and 403s —
  an editor can never create, edit, or delete a staff account, including
  their own.
- **PDF preview needed `Content-Disposition: inline`, not `attachment`.**
  `files_download` always forced `attachment` (correct for a real download —
  it's what makes the browser show a save dialog), but that also makes an
  `<iframe>` pointed at the same URL trigger a download prompt instead of
  rendering the file. `?inline=1` (`filesApi.previewUrl()`) switches the
  header for that one case; the PDF `<iframe>` in `PreviewModal.tsx` also
  appends `#toolbar=0&navpanes=0` to hide the browser's own PDF-viewer chrome
  (print/download/annotate toolbar), which otherwise reads as stray buttons
  that have nothing to do with this app.
- **Folder card previews fall back to file-type icons, recursively.** When a
  folder has no direct image children, `getFolderPreviewFallback` (in
  `DriveInterface.tsx`) walks the **entire subtree** (not just direct
  children) looking for the first non-empty type in priority order (video >
  pdf > audio > sheet > doc > other), and only falls back to a plain folder
  icon if there's truly no file anywhere below it — a folder containing only
  subfolders full of videos still shows video icons, not a meaningless
  generic folder glyph. Rendered as the same "big tile in front, second tile
  peeking out behind it, count badge" stack the image-thumbnail preview
  already used, just with `getFileIcon(type, sizeClass)` standing in for a
  thumbnail (that function takes an optional size now specifically so this
  fallback and the tiny list-view row can each ask for their own icon size
  instead of duplicating the type→icon→color mapping).
- **Turkish-text search correctness** (`src/lib/text.ts`, `trLower()`): plain
  `.toLowerCase()` gets Turkish wrong in two independent, easy-to-miss ways —
  don't use it for any search/filter comparison in this codebase. (1) Casing:
  `"İ".toLowerCase()` → `"i̇"` (i + a combining dot, not a plain `"i"`), and
  `"I".toLowerCase()` → `"i"` instead of the correct Turkish `"ı"` — so
  `"FIRAT".toLowerCase()` never matches a search for `"fırat"`.
  `toLocaleLowerCase("tr")` fixes this. (2) Unicode normalization: "ö", "ş",
  "ç", "ğ", "ü" can each be stored as one precomposed character or as a plain
  letter plus a combining accent — visually identical, different string. A
  folder named on a Mac can end up decomposed while typing the same letters
  into the browser's search box produces the composed form, so `"köşe"` won't
  `.includes()`-match a folder actually named "köşe" without normalizing both
  sides first. `trLower()` does `.normalize("NFC")` before lowercasing to
  cover this. Every search/filter comparison in `DriveInterface.tsx` and
  `LogPanel.tsx` goes through this one helper — route any new search feature
  through it too rather than reaching for `.toLowerCase()` directly.

## Deployment (Natro shared hosting)

- Domain: `teslim.workonruf.com`. Document root:
  `/home/u2756030/teslim.workonruf.com/` (frontend `dist/` output lives here).
- Backend deploy path: `/home/u2756030/teslim.workonruf.com/backend/` —
  **not** `/api/`. See gotcha below for why.
- `config.php` (DB creds, `app_secret`, Google OAuth client id/secret) is
  **not in git** (`.gitignore`'d) and lives only on the server / your own
  machine. Copy `config.example.php` and fill in real values.
- cPanel Git™ Version Control's "Repository Path" for this repo *is* the live
  serving directory directly (not a separate internal clone) — "Update from
  Remote" alone puts the latest commit live; the "Deploy" button needs a
  `.cpanel.yml` we don't have and isn't needed here.
- One-time Drive OAuth setup (`oauth_authorize.php` / `oauth_callback.php`)
  was deleted from the repo after production authorization — it had no auth
  guard, so anyone who found the URL could re-run it with their own Google
  account and hijack the Drive connection. Recreate from git history
  (`git log --all --  oauth_authorize.php`) if re-authorization is ever needed,
  and delete it again afterward.

## Hard-won gotchas (read before repeating them)

1. **"api" as a path segment is silently blocked on this Natro account.**
   Something (likely a leftover WAF/security rule from an old, unrelated
   project on the same account — its stale error log referenced a completely
   different `public_html/api/` structure) intercepts *any* request whose path
   contains `/api/`, for *any* subdomain on the account, before it even
   reaches PHP — no error log entry gets written, it just 404s. Cost real time
   because it looked exactly like a broken vhost. The fix was renaming the
   deploy folder to `backend`. If you ever see an unexplained 404 with zero
   corresponding PHP error log entry, suspect this class of thing (WAF/
   security rule) before assuming the code or vhost config is wrong — hitting
   the PHP file *directly* (bypassing `.htaccess` rewriting) and checking
   whether *that* also 404s with no log entry is the fastest way to tell
   "PHP never ran" apart from "PHP ran and errored."

2. **MySQL column names are case-sensitive in PHP, not in SQL.** A column
   created as `NAME` and one created as `name` are the same column to MySQL
   (queries work fine either way) but PDO returns the array key using
   whatever case the column actually has — so `$row['name']` silently comes
   back "undefined" if the column is really `NAME`. This happened across
   *multiple* tables (`users`, `folders`, `files`) because the production
   schema was set up by hand in phpMyAdmin at some point, independently of
   `schema.sql`. If a query result seems to be missing a field that's
   obviously in the row, check the actual column casing with `DESCRIBE
   tablename` before suspecting the query logic.

3. **Production's schema silently drifts from `schema.sql`.** There's no
   migration tracking — `schema.sql` gets ALTERed locally as features are
   built, and those ALTERs have to be manually re-run against production via
   phpMyAdmin. This has repeatedly caused "works locally, 500s in prod" bugs
   (missing `shared_links.view_mode`/`customer_user_id`, missing
   `audit_logs.action` enum values). Before ruling out schema drift as the
   cause of a prod-only error, diff `schema.sql` against `DESCRIBE` output for
   the affected table(s) directly.

4. **PHP can't reliably run two independent sessions in one request.**
   Calling `session_name('a'); session_start(); ...; session_write_close();
   session_name('b'); session_start();` does **not** give you two independent
   session stores — the second `session_name()`/`session_start()` call
   silently no-ops (a PHP warning about "headers already sent" is the tell)
   and just keeps using the first session's id. This is why share-link
   identity is a hand-rolled encrypted cookie (`Auth::loginShareLink()` /
   `Auth::currentShareLinkId()` in `lib/Auth.php`) instead of a second PHP
   session — it needed to coexist with a real login's session in the same
   browser without either clobbering the other (which is exactly what
   happened before: opening a customer's share link in one tab silently logged
   staff out in every other tab, because both were the same session/cookie).

5. **Soft-delete cascades are app logic, not DB cascades, and need care at
   the edges.** `folders.parent_id`/`files.parent_id` *do* have
   `ON DELETE CASCADE`, but that's for *hard* deletes only. Soft-deleting a
   folder (`folders_delete`) has to explicitly walk and mark every descendant
   folder/file with the same `deleted_at` timestamp itself — two real bugs
   came from getting this walk wrong: (a) the delete-cascade query originally
   only touched the folder's own row, so descendants looked "active but
   unreachable" instead of trashed; (b) the restore-cascade query excluded the
   folder's own id from the "which ids do I restore files under" list (to
   avoid a redundant no-op on the folders side), which meant files sitting
   *directly* in the folder being restored — as opposed to in a deeper
   subfolder — never came back. Always use the *same* id list (including the
   root) for both the folders-cascade and the files-cascade in one operation.

6. **ZIP files silently corrupt above 4GB without zip64.** The original
   writer used plain 32-bit size/offset fields (the "3GB total cap" in
   `zip.php` used to exist purely as headroom under that limit). `ZipStreamer`
   now writes zip64 unconditionally for every entry — verified by generating
   a real archive against an actual Drive file and running `unzip -t` plus a
   byte-for-byte diff, not just eyeballing it. Any future change to that file
   should be re-verified the same way; a subtly wrong zip64 implementation can
   look fine for small test files and corrupt only on the specific size
   boundaries that matter.

7. **Local `upload_max_filesize`/`post_max_size` can be much smaller than
   production's.** A local PHP built-in server defaulted to 2MB uploads; a
   5MB test file failed with a generic "Dosya yüklenemedi" (422) that looked
   like an app bug but was just the local php.ini. Check `php -i | grep
   upload_max` before chasing an upload failure past a few MB locally.

8. **Test data discipline: never run destructive Playwright scripts against
   real customer accounts, even by accident.** A broad selector
   (`.first()` on a folder's delete button) matched the wrong element twice
   in one session and soft-deleted the real "Deneme" test customer's own root
   folder both times — each time it looked, at first, like a genuine app bug.
   Always spin up a disposable, uniquely-named test user/customer/folder for
   anything that deletes/restores/moves data, clean it up afterward, and
   double-check DB state (`SELECT ... WHERE name LIKE '%realcustomername%'`)
   before and after any test run that touches real-looking data.

9. **PHP's default timezone silently disagrees with MySQL's.** With no
   `date_default_timezone_set()` call anywhere, PHP defaults to UTC while
   MySQL's `NOW()`/`CURRENT_TIMESTAMP` follow the server's local timezone
   (`Europe/Istanbul`, UTC+3 here). Using PHP's `date('Y-m-d H:i:s')` for a
   value like `deleted_at` while `created_at` was set by MySQL's own
   `CURRENT_TIMESTAMP` produced a `deleted_at` that could read as *before*
   `created_at` by exactly the UTC offset. Fixed by calling
   `date_default_timezone_set('Europe/Istanbul')` once in `bootstrap.php` and,
   more importantly, always using `NOW()` in the SQL itself instead of
   PHP-generated timestamps for anything that's compared against MySQL's own
   timestamp columns.

10. **`window.showDirectoryPicker()` can't be driven by browser automation.**
   It's a native OS dialog, not something Playwright can click through. To
   test the real-folder-download logic (`src/lib/folderDownload.ts`), mock
   `window.showDirectoryPicker` to return a fake in-memory
   `FileSystemDirectoryHandle` (plain JS object tree recording every
   `getFileHandle`/`getDirectoryHandle`/write call) via `page.addInitScript`,
   and assert on the resulting tree — this actually exercises the recursive
   folder-writing logic instead of just trusting it compiles.

11. **A self-imposed timeout, not shared hosting itself, caused a pathological
    upload slowdown.** A real 15GB production upload crawled at 2.5KB/s–2MB/s
    with wild swings. Root cause: both `GoogleDriveClient::uploadChunk`'s own
    curl timeout and `file_uploads_chunk`'s `set_time_limit` were set to 180s
    — comfortably enough on a fast connection, but on Natro's actual
    (slower/variable) outbound leg to Drive, a 64MB chunk could legitimately
    need longer than that, so it kept getting killed mid-flight and retried
    from scratch. Looked exactly like "the hosting is just slow," but the
    fix was raising both to 1800s and shrinking the chunk size (64MB → 16MB,
    `UPLOAD_CHUNK_BYTES` in `src/api.ts`) so any one chunk risks less work.
    If upload speed ever looks erratic (not just uniformly slow) again,
    suspect a timeout-and-retry loop before suspecting raw bandwidth — a
    *uniformly* capped connection doesn't produce that kind of swing.

12. **Mobile layout for the staff (ADMIN/EDITOR) dashboard is a from-scratch
    rebuild, not a shrunk desktop layout** — several rounds of real-device bug
    reports (via screenshots) drove this, so don't casually "simplify" it back
    toward the desktop structure:
    - The bulk-selection action bar (Paylaş/İndir/Sil/Kaldır) is
      `position: fixed` to the viewport bottom on `< md`, with the buttons
      collapsed to icon-only (labels return at `sm:` and up) — the scrollable
      file/folder list needs matching bottom padding (`pb-20` conditionally
      applied) so its last row isn't hidden underneath.
    - The mobile top bar (in `App.tsx`, `md:hidden`) consolidates what used to
      be three separate rows/bars into one: logo+"Ruf'tan" | current user's
      name+role (plain text, deliberately no avatar circle — user preference,
      see below) | the hamburger that opens the nav drawer.
    - The old bottom `RoleSelector` bar is hidden on mobile **only for staff**
      (`!isWeTransferStyle && !isConsumerStyle`) — its two actions (İşlem
      Logları, Çıkış Yap) moved into the drawer's footer instead. It's
      deliberately **not** hidden for real customer logins on mobile: their
      layout (Layout B / WeTransfer-style) has no drawer or equivalent
      replacement, so hiding it there would remove their only way to log out.
    - Avatar-circle-with-initial next to a name+role was removed everywhere it
      showed the *current logged-in user* (top bar, `DriveInterface`'s
      desktop toolbar pill) per explicit user request — plain text only.
      Don't add it back reflexively; it wasn't an oversight.
    - The phone/browser back button used to just exit the app no matter how
      deep you'd navigated, because `activeFolderId`/`showPersonnelView`
      changes never touched browser history at all. Fixed in `App.tsx` by
      mirroring both into `history.pushState`/`popstate` (with a ref flag to
      avoid re-pushing when a `popstate` event itself is what triggered the
      state change) — verified with `page.goBack()` in a real browser, not
      just by reading the code.
    - Uploads silently filter out OS-generated junk (`._*` AppleDouble files —
      macOS creates one per file on non-native filesystems like exFAT/FAT32
      SD cards/USB drives — plus `.DS_Store`, `Thumbs.db`, `desktop.ini`)
      before queueing, in `executeUploadBatch` (`DriveInterface.tsx`) — the
      one choke point every upload path (drag-drop, file picker, folder
      picker) already funnels through.

13. **PHP's default session handling serializes every concurrent request from
    the same logged-in browser, silently.** `session_start()` holds an
    exclusive file lock on the session for the *entire* request, and nothing
    was ever releasing it — so several chunk-upload requests fired in
    parallel from one session (or just two browser tabs open at once) queued
    up behind each other at the PHP level no matter how parallel the client
    side tried to be, with zero error or log entry to point at. Proved it with
    an isolated before/after timing script (two 1.5s-sleep requests: ~3s
    serialized vs ~1.5s once the lock was released) before touching any real
    code. Fixed by calling `Auth::releaseSessionLock()`
    (`session_write_close()`) right after `Auth::startSession()` in
    `bootstrap.php`, for every route — `Auth::login()`/`Auth::logout()` are
    the only two places that ever write `$_SESSION`, so they briefly
    `session_start()` again just to write, then close. Any future route that
    needs to *write* `$_SESSION` mid-request must do the same
    reopen-just-to-write dance, or it'll silently fail to persist (the
    in-memory `$_SESSION` array itself isn't cleared by `session_write_close`,
    only saved-and-unlocked, so reads still work fine without reopening —
    it's specifically writes elsewhere that need this).

14. **Concurrent file uploads help, but push the count/chunk-size up ONE step
    at a time and test with a real multi-file upload after each change** —
    don't jump straight to a high number. `CONCURRENT_UPLOAD_LIMIT`
    (`App.tsx`, `handleAddBatch`) briefly went to 6 with `UPLOAD_CHUNK_BYTES`
    (`src/api.ts`) at 32MB; in production this made real uploads grind to a
    near-halt (other, lighter site requests kept working fine, so it wasn't
    full PHP-FPM worker exhaustion — more likely several 32MB chunk buffers
    plus their own relay-to-Drive CPU/SSL overhead, all at once, starving each
    other on a modest shared VM). Rolled both back to 2 / 16MB. Both knobs
    interact — don't tune one without re-testing the other.

15. **The real upload bottleneck turned out to be neither Drive nor this
    hosting account's outbound bandwidth — it's the browser→server leg
    specifically**, and this took three separate controlled measurements to
    pin down (don't skip straight to a fix based on only one data point next
    time). `routes/diagnostics.php` (temporary, admin-only, delete once this
    is resolved) has two tests: `diagnostics_upload_speed_test` times a raw
    upload from *this server* straight to Drive (optionally N of them at once
    via `curl_multi`, `?concurrent=N`, to tell "Drive throttles per
    connection" apart from "the account's own outbound pipe is the cap");
    `diagnostics_echo_upload` reads-and-discards whatever the browser sends,
    isolating the browser→server hop with Drive completely out of the
    picture. On this Natro account: browser→Drive directly ≈ 15MB/s,
    server→Drive directly ≈ 16-27MB/s, but browser→this server ≈ 1.8-1.9MB/s
    — both endpoints are independently fast, so the bottleneck is specifically
    inbound bandwidth to this hosting account, most likely a network-level
    throttle (mod_cband/CloudLinux I/O limit or similar), confirmed to be
    unrelated to PHP config after Natro raised "PHP limits" and the number
    didn't move (1.91 → 1.82MB/s). **Nothing in this codebase can fix an
    inbound bandwidth cap** — nothing about a bigger chunk size, more
    concurrent uploads, or Drive-side tuning bypasses it, since the ceiling is
    on the hop before any of that code even runs. If this resurfaces, re-run
    both diagnostics before touching upload code again instead of assuming
    it's the same cause as gotcha #11 (that one was a timeout/retry storm,
    not a raw bandwidth ceiling — the two look similar as user reports
    ("uploads are slow") but need completely different fixes, and this
    session burned real time initially misattributing this one to Drive's
    API instead of the hosting account's inbound path).
