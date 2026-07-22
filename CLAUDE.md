# RUF Drive Portal — Backend

PHP (no framework, no Composer) + MySQL API for a client file-delivery portal.
Google Drive is the real storage backend but must **never** be visible to
customers — no `drive.google.com` URLs, no Drive branding, anywhere in a
customer-facing response.

Repo scope: this is `api/` only. The frontend (`src/`, Vite/React) lives in the
parent project folder as its **own separate git repo** (initialized
2026-07-21, root-level, `api/` deliberately excluded via its `.gitignore` so
the two never mix). Deploy is still manual either way: frontend by building locally and pushing
the `dist/` output to the VPS, backend by `git pull` on the VPS from *this*
repo.

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
- **Large file uploads (100MB–tens of GB)**: chunk *bytes* no longer touch this
  server at all — they go browser → Cloudflare Worker (`cloudflare-worker/`,
  deployed independently via `wrangler`, not cPanel) → Drive, because a direct
  browser→Drive upload is a dead end (verified with a real browser test: Drive's
  resumable-upload endpoint sends no `Access-Control-Allow-Origin`, so the
  browser blocks it as CORS before any bytes move — don't re-attempt this, it's
  Google-side), and this server's own inbound bandwidth is hard-capped by Natro
  at ~1.8-1.9MB/s regardless (see gotcha #15) — nowhere near the ~15MB/s
  browser↔Drive / ~16-27MB/s server↔Drive both ends are independently capable
  of. This server (`routes/file_uploads.php`) only ever handles small
  control-plane calls now:
  - `POST /file-uploads` (`file_uploads_start`): same access checks as before
    (`Scope::assertFolderAccessible`), calls
    `GoogleDriveClient::createResumableSession` to open the real Drive session,
    and additionally mints a short-lived HMAC-signed **ticket**
    (`lib/ChunkRelayTicket.php`) authorizing the Worker to relay chunks into
    exactly that session — the ticket embeds the session URI itself, so the
    Worker needs no Google credentials of its own (Drive's session URI is
    already a self-authenticating capability token; notice
    `GoogleDriveClient::uploadChunk`/`queryResumableProgress` never send an
    `Authorization` header to it, only `createResumableSession` does).
  - The browser (`uploadLargeWithProgress` in `src/api.ts`) slices the file
    into 16MB chunks and `PUT`s each one straight to the Worker
    (`CHUNK_RELAY_WORKER_URL`, not `API_BASE`) with `Authorization: Bearer
    <ticket>`; the Worker relays it to Drive server-to-server (no CORS on that
    hop) and streams the body through without buffering.
  - `POST /file-uploads/{id}/finalize` (`file_uploads_finalize`): called once
    the last chunk reports done. **Never trusts the client-supplied
    `driveFileId` at face value** — independently re-fetches it from Drive
    (`GoogleDriveClient::getFileMeta`) and checks size + parent folder match
    before writing the `files` row, since the Worker (and anything the browser
    forwards from it) sits outside our own auth boundary.
  - `GET /file-uploads/{id}` (`file_uploads_status`) is unchanged in spirit —
    still asks Drive directly for real progress via
    `GoogleDriveClient::queryResumableProgress` — but now also mints and
    returns a **fresh** ticket on every call, since a long-running/resumed
    upload can outlive the original one.
  - `filesApi.createWithProgress()` still switches to this whole path
    automatically above `LARGE_UPLOAD_THRESHOLD_BYTES` (80MB); its public
    signature (`Promise<{file, driveSyncOk}>`) is unchanged, so nothing calling
    it needed to change. **Do not lower/remove this threshold to "fix" many-
    small-files-in-a-folder feeling slow** — tried exactly that on 2026-07-19
    and it was a severe regression: the chunked/Worker path costs 3 sequential
    round trips per file (open Drive session, PUT chunk, verify+finalize)
    versus 1 for the old direct multipart POST. That overhead is paid once for
    a single large file and bandwidth dominates, but multiplies across every
    file in a folder of many small ones — on a high-latency (e.g. mobile)
    connection this can be far worse than the original Natro bandwidth cap it
    was meant to fix (observed: a 400MB folder of small files dropped to
    20-30KB/s). If this needs revisiting, pick a threshold from real
    round-trip-vs-bandwidth breakeven math and verify with an actual
    many-small-files test before shipping. See `cloudflare-worker/README.md`
    for deploy steps —
    if `CHUNK_RELAY_WORKER_URL` (`src/api.ts`) and the Worker's actual deployed
    URL ever drift apart, every large upload fails outright (visibly, not
    silently — a network error while checking Content-Length/hitting an
    unreachable host is not something that could be mistaken for slow-but-working).
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
- **Move and recursive copy** (`files_update`/`folders_update` accept an
  optional `parentId`; `files_copy`/`folders_copy` are separate endpoints):
  both mirror the change to real Drive (`GoogleDriveClient::moveFile`/
  `copyFile`), never just the DB row. Copy is a genuinely independent
  duplicate — a real `files.copy` Drive API call per file, a real
  `files.create` folder per subfolder — never a second DB row pointing at the
  same `drive_file_id`, so deleting one copy can never affect the other.
  `folders_copy_subtree` recurses the whole tree top-down (parent created
  before its children, so each child copy has a real new Drive parent to
  land in) and accumulates every created row into `&$createdFolders`/
  `&$createdFiles` by reference, because unlike a move, the frontend has no
  local copy of any of these brand-new rows to optimistically patch — the
  whole batch comes back in one response instead. Both move and copy reject
  (422) moving/copying a folder into itself or its own descendant
  (`folders_collect_descendant_ids` cycle check) — without it the destination
  becomes part of the subtree still being walked, recursing forever.
- **Sort (date/name/size/type) is entirely client-side** (`DriveInterface.tsx`,
  `sortBy` state) — never sent to the server, since the whole folder/file list
  for a view is already loaded. Folders and files are sorted independently
  (`sortFolders`/`sortFiles`) and folders always render in their own section
  above files regardless of `sortBy`, structurally, not by sort order — a
  folder has no meaningful "type" of its own, so `sortBy === "type"` falls
  back to name for folders specifically, on purpose.
- **Right-click context menus are two different menus, not one**, both driven
  by the same empty-space-vs-card hit-test (`target.closest("[data-select-id],
  button, input, a")`) also used by drag-to-select: right-clicking a card
  opens the per-item menu (rename/move/copy/download/delete, plus bulk actions
  when the card is part of an active multi-selection); right-clicking
  anywhere else opens the Sırala/Yeni Klasör menu. Dragging a card onto a
  folder (native HTML5 DnD, custom `application/x-ruf-move` dataTransfer type
  so it's never confused with a real OS file drop) moves it the same way the
  "Taşı..." menu item does — `handleDragOver`/`handleDrop` on the container
  ignore anything carrying that MIME type so the "Dosyaları Buraya Bırakın"
  upload overlay never flashes during an internal drag.
- **The bottom `RoleSelector` bar is gone entirely for staff** (ADMIN/EDITOR)
  — İndirme İstatistikleri/İşlem Logları/Çöp Sepeti/Arkaplan Ayarları/Çıkış
  Yap now live as one stacked column in the left sidebar (`sidebarNav` in
  `App.tsx`), same on mobile and desktop. `RoleSelector` itself still renders,
  unchanged, for real customer/consumer logins — they have no sidebar
  equivalent, so removing it there would remove their only way to log out.
- **Collage backgrounds have 12 fixed size/depth slots, not a compact list.**
  `background_collage_images.sort_order` is reused as a **slot index** (0-11,
  smallest/frontmost to largest/backmost), not an upload-order counter —
  `(background_settings_id, sort_order)` has a `UNIQUE KEY` so re-uploading
  into an already-filled slot deletes the old Drive file + row and inserts the
  new one at the *same* slot (`background_settings_add_collage`), rather than
  appending. `background_settings_serialize` always returns a sparse
  12-element `collageImages` array (`null` for an empty slot) built from
  whichever `sort_order` values actually have rows — the frontend renders all
  12 slots every time, so a photo's slot index alone determines its size and
  mouse-parallax sensitivity, regardless of when it was uploaded. Every other
  collage knob (`collage_colors` as a comma-joined hex list, `collage_min/max_
  size`, `collage_min/max_sensitivity`, `collage_scale`, `collage_spread`,
  `collage_headline_*`) is a plain nullable column on `background_settings`
  itself, deliberately not JSON (matches this schema's existing no-JSON-column
  convention) — all default to sensible values in `background_settings_
  serialize` when `NULL`, so a collage created before a given knob existed
  still renders reasonably. `CollageBackground.tsx`'s own placement logic:
  each slot's depth `i/(11)` interpolates size (`collageMinSize`↔`collage
  MaxSize`, further scaled by `collageScale`%) and parallax sensitivity
  (`collageMaxSensitivity`↔`collageMinSensitivity`) in one continuous
  gradient instead of two discrete buckets. Positions blend two independently
  adjustable knobs: `collageDistribution` (0=symmetric hand-placed anchors,
  100=collision-avoided random placement in real pixel space, computed via
  rejection sampling so large/backmost photos never pile on top of each
  other and steer clear of the dead-center headline) and `collageSpread`
  (0=every position collapses toward dead-center, deliberately allowing
  overlap, 100=the original edge-biased spread) — both interpolate via the
  same `lerp`, and are orthogonal to each other.
- **`BackgroundCta` has three mutually exclusive styles** (`ctaStyle`:
  `"cursor" | "fixed" | "fullBackground"`), all sharing one `ctaLabel`/
  `ctaUrl` pair. `"fullBackground"` (added alongside the mouse-tracking fix
  in gotcha #24) shows no visible tag/button at all — the whole backdrop
  becomes one `inset-0` click target — for a client who'd rather every empty
  pixel of the background be clickable than hunt for a small tag. `App.tsx`'s
  two render sites both fall back to `"cursor"` only when `ctaStyle` is
  neither `"fixed"` nor `"fullBackground"`; a naive `=== "fixed" ? "fixed" :
  "cursor"` ternary (the original shape, before this style existed) would
  have silently downgraded `"fullBackground"` to `"cursor"` — worth
  remembering if a fourth style is ever added here.
- **Tüketici (consumer) share links can optionally be read-only-browsable**
  (`shared_links.allow_preview`, only ever meaningful when `view_mode =
  'consumer'`): unlike a `'customer'` view-mode link (which grants real
  edit rights — rename/move/delete/share — via `actsAsCustomer` in
  `DriveInterface.tsx`), `allowPreview` only upgrades the flat download list
  to a folder-navigable, file-previewable view with **zero** edit rights.
  `shared_links_collect_content` already recursively collects every
  descendant folder/file for *any* link regardless of view mode, so no
  backend data-fetching change was needed for this — it's purely a frontend
  routing decision (`App.tsx`'s `showsBrowsablePanel` extends
  `showsCustomerPanel`'s condition, but `actsAsCustomer` deliberately does
  **not**, since `viewMode` stays `'consumer'`). The read-only sidebar (Layout
  B) still needed explicit `showsCustomerPanel`-gating in a few spots that
  aren't inside `DriveInterface` itself and so don't get its automatic
  `isStaff`/`actsAsCustomer` protection for free: the Çöp Sepeti chip and the
  floating bulk-action bar's "Paylaş" button both would have leaked to a
  read-only preview visitor otherwise.
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
- **Trash is soft-delete-only in the app itself; `routes/trash.php`'s
  `GET /trash/purge?token=...` is the only thing that ever hard-deletes a
  trashed folder/file** (DB row + real Drive bytes), for anything past 30
  days. It's not called from anywhere in the app — it's designed to be pinged
  once a day by an external cPanel Cron Job, shared-secret-authenticated
  (`cron_secret` in `config.php`) since a cron job has no session to check
  against. See gotcha #26 for the deploy-dependency trap this surfaced.

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
- One-time (per environment) cPanel Cron Job needed for the 30-day trash purge
  (see gotcha #26): add `cron_secret` to `config.php` (not in git), then in
  cPanel → Cron Jobs, run once daily:
  `curl -s "https://teslim.workonruf.com/backend/trash/purge?token=YOUR_CRON_SECRET"`
  (or `wget -q -O /dev/null "..."` — either works, cPanel's UI takes a plain
  command line). Nothing purges, ever, until both the config value and this
  cron job exist — the route fails closed (401) with no `cron_secret` set.

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
    API instead of the hosting account's inbound path). **Update:** Natro
    support later confirmed this cap is a fixed shared-hosting policy, not
    something they'll lift — the actual fix that shipped is the Cloudflare
    Worker relay described in "Large file uploads" above, which routes chunk
    bytes around this server entirely rather than trying to widen the pipe
    into it.

16. **Safari's `<video>` needs a real Range/206 response to play at all** —
    Chrome/Firefox tolerate a single 200-with-the-whole-body response, Safari
    doesn't and just shows a blank/broken player. `files_download` already
    forwarded `Range` correctly; `background_settings_stream_media` (the route
    that actually serves uploaded background videos) didn't, so background
    videos silently failed only in Safari while looking completely fine
    everywhere else. Fixed by giving it the same `files_parse_range_header`ed
    206 path, using `GoogleDriveClient::getFileSize` for the `Content-Range`
    total (background rows have no cached `size_bytes` column). Any future
    route that streams bytes to a `<video>`/`<audio>` tag needs this same
    treatment, not just images.

17. **`GoogleDriveClient`'s generic `request()` had no cURL timeout at all** —
    found while testing locally with a stale/expired OAuth token: every Drive
    metadata call (create/delete/move/copy/getFileSize) hung until the
    connection eventually dropped on its own, and because the local dev
    server (`php -S`, single-threaded) processes one request at a time, that
    one hang blocked every other request behind it, including completely
    unrelated ones. The same failure mode applies to real PHP-FPM workers in
    production — a hung Drive call just ties up a worker forever instead of
    failing fast. Fixed with `CURLOPT_CONNECTTIMEOUT => 10` /
    `CURLOPT_TIMEOUT => 30` on that one method (it's only ever used for small
    metadata calls, never big transfers, so 30s is always generous — don't
    add this same blanket timeout to `streamFileTo`, which legitimately needs
    to run long for a big file).

18. **Deploy order matters when a change adds new `audit_logs.action` ENUM
    values.** Every move/copy/etc. handler calls `AuditLogger::log()` near
    the end, unguarded by try/catch — if the ENUM doesn't yet contain the
    value being inserted (e.g. `FILE_MOVE` before the migration below has
    run), that call throws, the request 500s, and the response looks like a
    total failure to the frontend even though the actual DB mutation (the
    file's new `parent_id`, etc.) already committed a few lines earlier with
    no transaction wrapping it — a confusing half-succeeded state. Always run
    the `ALTER TABLE audit_logs MODIFY action ENUM(...)` migration in
    phpMyAdmin *before* deploying backend code that logs a new action value,
    never after.

19. **This repo (`stormhawks-a/ruf-drive-portal-api`) is a public GitHub
    repository, on purpose** — chosen specifically so cPanel's Git™ Version
    Control could pull it over plain HTTPS without setting up an SSH deploy
    key. `config.php` (DB creds, `app_secret`, Google OAuth client secret) is
    `.gitignore`'d and never touched this repo, so nothing directly
    sensitive is exposed — but the actual business logic (Drive integration,
    role/permission boundaries, DB schema) is fully public. This was a
    deliberate, discussed tradeoff, not an oversight — don't "fix" it by
    flipping visibility without asking first, since doing so could break the
    existing cPanel pull flow (would need an SSH deploy key set up instead).

20. **`pointer-events: none` on a component's own root div doesn't help if an
    *ancestor* wrapper still defaults to `auto`.** `BackgroundCta`'s "cursor"
    style used to attach its own mousemove listener to a full-cover
    `pointer-events-auto` div — which silently ate every mouse move meant for
    the collage's own photos underneath, freezing their parallax any time a
    CTA was enabled. First fix attempt (making `BackgroundCta`'s own root
    `pointer-events-none`, tracking the mouse via a `window`-level listener
    instead so hit-testing doesn't matter for *tracking*) verifiably fixed the
    tag itself (confirmed by reading its computed position at three different
    mouse coordinates) but the photos **still** didn't move — because
    `App.tsx` wraps `<BackgroundCta>` in its own `<div className="absolute
    inset-0 z-[5]">`, which has no pointer-events class of its own and so
    defaults to `auto`, still intercepting the hit-test one level up. Real fix
    needed `pointer-events-none` on *that* wrapper too. Lesson: when tracing a
    "some other element is eating my pointer events" bug, check
    `document.elementFromPoint(x, y)` (or the full ancestor chain's computed
    `pointer-events`) rather than assuming the fix is complete once the
    component you edited looks right in isolation — every ancestor between
    the suspected blocker and the real DOM root needs the same treatment.

21. **A debounced-save UI with several independently-editable fields on the
    same record needs its "merge the server's response back into local
    state" step to read from a ref, not a render-closured variable.** The
    collage settings section added ~10 simultaneously-tunable fields (colors,
    distribution, headline text/font/color/size, sensitivity/size ranges,
    scale, spread) to `SettingsModal.tsx`, each with its own per-field
    debounce timer (`handleUpdateBackground`). Two of those debounces landing
    within the same ~500ms window is now the normal case, not an edge case —
    and `replaceInPool`'s original implementation rebuilt the pool from
    whatever `localPool` its enclosing render had closed over, so whichever
    response arrived *second* would silently overwrite the *first* field's
    just-saved value with a stale pre-edit snapshot. Fixed by keeping a
    `localPoolRef` updated synchronously on every optimistic edit and reading
    from `.current` inside `replaceInPool`, instead of the closured
    `localPool` — verified by editing the headline text, waiting for its save
    to land, then immediately dragging an unrelated slider, and confirming
    the headline text was still there afterward.

22. **An async "add a new card" button needs a busy-guard, or a fast
    double-click (or even a single Playwright `.click()` retried while a
    modal is still animating in) can create two.** `handleAddCollage` had no
    protection against firing twice; real repeated test runs occasionally
    produced two identical "Kolaj" background rows from what looked like one
    click. Fixed with a simple `addingCollage` state disabling the button
    (and swapping its icon for a spinner) until the create request resolves —
    the same pattern should be applied to `handleAddMedia`/`handleAddSlider`
    if this class of bug ever shows up there too, they were not touched this
    time since it hadn't actually been observed on them.

23. **Deploy order matters for a new column too, not just a new ENUM value**
    (see #18) — `collage_scale`/`collage_spread` shipped in code before the
    corresponding `ALTER TABLE` had actually been run against production.
    Symptom was subtle and easy to misdiagnose: the Settings modal's own live
    preview looked completely correct (it renders from local React state,
    updated optimistically *before* the save request even completes), while a
    real customer opening the panel in a separate tab/device saw the old
    values, because the `PUT` that was supposed to persist the change failed
    silently against the missing columns (`.catch(() => {})` in
    `handleUpdateBackground` swallows the error) and the server never had
    anything new to serve. Lesson for next time a symptom is phrased as
    "works in the editor/preview but not for the real visitor": suspect a
    missing migration on the specific fields involved before anything else —
    it produces exactly this "looks fine to me, broken for everyone else"
    shape of bug.

24. **Fixing the pointer-events ancestor bug (#20) wasn't the end of the CTA/
    parallax story** — `CollageBackground.tsx` itself still tracked the mouse
    via a plain React `onMouseMove` on its own root div. That's an
    element-scoped listener: whenever the cursor sits directly over
    `BackgroundCta`'s round tag (which must stay `pointer-events-auto` to be
    clickable), the browser's real hit-test target *is* the tag, not the
    collage's div underneath, so the collage's own `onMouseMove` simply never
    fires for that patch of screen and the photos freeze right under the tag.
    Fixed by moving the collage's tracking to a `window`-level `mousemove`
    listener too (the same trick already used inside `BackgroundCta` itself),
    which fires regardless of what element is hit-tested. Same fix also
    solved a second, unrelated complaint for free: photos used to snap back to
    dead-center on `onMouseLeave`. Removing that handler and instead clamping
    the tracked position to the container's own rect means the offset simply
    saturates at whichever edge the cursor exits from and stops changing
    there — the photos freeze exactly where they were at the moment of exit
    and resume the instant the cursor re-enters from anywhere, with no special
    re-entry logic needed. General lesson: any "mouse-driven visual effect
    freezes under an interactive overlay" bug should be suspected as this same
    element-scoped-listener-vs-pointer-events-hit-test conflict, and the fix
    is almost always "track on `window` instead of on the specific element."

25. **Previewing a file counted as downloading it.** `files_download` is one
    shared route for two very different things: a real "save this file"
    download, and the PDF/video preview modal streaming the same bytes inline
    (`?inline=1`) purely to render in an `<iframe>`/`<video>`. The
    `Content-Disposition` header already branched on `inline`, but the
    audit-log call and the `download_count`/`shared_links.download_count`
    increments above it didn't — so opening a PDF preview (or just letting a
    video autoplay in the modal) silently logged a `FILE_DOWNLOAD` entry and
    inflated the download-stats leaderboard, with no real download ever
    having happened. Fixed by computing the `inline` flag once, up front, and
    skipping both the log and both counters whenever it's set — a real
    download (no `?inline`) is unaffected. Reported by a staff user seeing an
    unexplained download entry in "İşlem Logları" for a document they'd only
    opened to look at. The schema already has an unused `FILE_PREVIEW` audit
    action enum value sitting there for exactly this distinction, never
    wired up — worth using if "who previewed what" ever becomes a wanted
    feature, but deliberately left alone here since the actual ask was just
    "stop mislabeling a preview as a download," not "start tracking previews
    too."

26. **"Silinen öğeler 30 gün saklanır" was pure UI copy — nothing ever actually
    purged anything.** `folders_delete`/`files_delete` are soft-deletes only
    (`deleted_at`), and there was no code anywhere that ever hard-deleted an
    old trashed row, in Drive or the DB — items sat in trash forever,
    contradicting what the UI told the customer. Also surfaced a real,
    separate bug while investigating: the customer-panel trash chip
    (`hasOwnTrash` in `App.tsx`) correctly hides itself when there's nothing
    of the viewer's own in the trash — but this only works if the *data
    fetch itself* still includes trashed rows in the first place, which
    depends on already-shipped-but-maybe-not-yet-deployed fixes to
    `folders_delete`'s cascade and `shared_links_collect_content`'s
    `$includeDeleted` flag (both from commit `2cb6739`) — if trash
    disappears on refresh, suspect a stale backend deploy before touching
    the frontend check itself again. Added `routes/trash.php`
    (`GET /trash/purge?token=...`, shared-secret auth since a cron job has no
    session) that hard-deletes both DB rows *and* the real Drive file/folder
    for anything with `deleted_at` older than 30 days, meant to be called by
    a cPanel Cron Job once a day (see Deployment section) — this app has no
    persistent process to run a real scheduler, so an external HTTP ping is
    the only option on this host.

27. **A row's own delete icon must check whether it's part of a larger
    selection, or "delete" only ever means "delete this one row."** The
    customer-panel sidebar (`App.tsx`) lets several folders be checked at
    once, but each row's trash icon only ever carried its own id — clicking
    delete on one checked row deleted only that row, silently leaving the
    other checked ones untouched, which read as "select multiple, delete
    button doesn't delete the selection." Fixed in the shared delete-confirm
    modal's confirm handler: if the clicked item is itself part of a
    multi-item selection, delete everything selected (`Promise.all` over both
    `wtSelectedFolderIds`/`wtSelectedFileIds`); otherwise just the one row, so
    deleting an unchecked row never reaches into an unrelated selection sitting
    elsewhere. Second half of the same fix: every delete path now strips the
    deleted id(s) out of the selection state afterward — without this, a
    deleted item kept showing up "checked" the next time it was rendered,
    most visibly popping up pre-selected inside Çöp Sepeti (which reads the
    same lifted `wtSelectedFolderIds`/`wtSelectedFileIds` DriveInterface
    receives as props) even though nobody had touched a checkbox there.

28. **A one-shot CSS animation needs an explicit resting state, or it freezes
    at "fully visible" once it ends.** The sidebar's download button plays a
    light-sweep shimmer (`ruf-btn-shimmer` keyframes) across itself the
    instant its label flips between "Tümünü İndir" and "Seçilenleri İndir".
    The shimmer `<span>` had no `animation-fill-mode: forwards` and no
    explicit resting `opacity` class — so the instant the 0.7s animation
    finished, the element snapped back to its *un-animated* CSS (fully
    opaque, untransformed), leaving a permanent vertical light bar sitting on
    the button forever instead of disappearing. Fixed by adding `opacity-0`
    as the element's own resting class; the keyframes still override it
    (0 → 1 → 0) while running, then it correctly reverts to invisible once
    they stop. General lesson: any one-shot CSS animation that isn't meant to
    freeze on its last frame needs the *rest state* styled explicitly on the
    element itself — don't rely on "the animation will just end and disappear."

29. **The customer-panel sidebar redesign ("Kompakt Liste") was ported to the
    Tüketici (Consumer) panel too, reusing the same patterns but keeping that
    panel's own dark navy/amber identity** (never switched to the customer
    panel's pastel/blue palette — the two are deliberately different themes,
    only the *interaction* patterns are shared): identity info with no card
    box behind it (`mix-blend-difference` text instead of a shadow, so it
    inverts against whatever photo is directly behind it rather than relying
    on a fixed shadow that only sometimes has contrast), a centered
    "Dökümanlar" label, folder/file rows with a tinted icon badge, and one
    shared single "download everything / download selected" button pattern
    with the one-shot shimmer (separate shimmer-tracking state per panel —
    `wtDownloadBtnShimmer` for the customer panel, `consumerDownloadBtnShimmer`
    for Tüketici — since they're two independent selection scopes). A pastel
    yellow icon-only share button sits beside the customer panel's download
    button (disabled/dim until something's checked, since sharing only ever
    applies to a selection, never to "everything" the way download defaults);
    deliberately **not** added to the Tüketici panel, which must stay
    download-only — no preview, edit, rename, delete, or re-sharing. The old
    floating "N Öğe Seçildi" pill (with its own İndir/Paylaş buttons) was
    removed entirely from the customer panel; its only remaining job is
    showing the running count + a clear-selection button, since download and
    share both moved into the sidebar's own controls.

30. **`POST /files/stream`'in gerçek hızı bir Nginx ayarına bağlı, ve bu ayar
    hiçbir yerde otomatik değil.** 2026-07-21'de eklenen bu endpoint
    (`files_create_streaming`, bkz. "Large file uploads" bölümünün küçük-dosya
    muadili) PHP tarafında dosyayı hiç buffer'lamadan `php://input`'tan
    okuyup Drive'a akıtıyor — ama bu VPS'in Nginx+Apache reverse-proxy
    zincirinde Nginx, isteğin gövdesini varsayılan olarak (`proxy_request_
    buffering on`, Nginx'in kendi öntanımlısı) PHP'ye ulaşmadan önce
    **tamamen** buffer'lıyor, tam olarak PHP tarafında kaldırdığımız sorunu
    bir kat aşağıda aynen geri getiriyor. Gerçek ölçümde bu fark 3.55MB/s
    (Nginx buffer'lıyor) ile 5.03MB/s (buffer kapalı) arasında — yani
    unutulursa uygulama **hatasız çalışmaya devam eder**, sadece sessizce
    ~%30 yavaşlar, hiçbir log/hata bunu göstermez (bu codebase'in tekrar eden
    "çalışıyor ama sessizce yavaş" deseni — bkz. gotcha #15). Gerekli tek
    satır: `proxy_request_buffering off;`, sadece `location = /backend/files/
    stream` bloğu için (domain geneline değil). Gerçek, kanıtlanmış hâli
    `api/deploy/nginx-streaming-location.conf`'ta duruyor — VPS migration
    henüz tamamlanmadığı için (bkz. proje kökündeki `CLAUDE.md`) sunucu bir
    daha kurulur/HestiaCP alan adı "rebuild" edilirse, bu dosyanın içeriği
    domain'in `nginx.ssl.conf_*` include mekanizmasına (HestiaCP'nin kendi
    ürettiği `nginx.ssl.conf`'u doğrudan düzenlemeden) yeniden eklenmeli —
    aksi hâlde kimse fark etmeden hız sessizce eski seviyesine döner. Aynı
    aileden ikinci bir risk: bu yol artık `post_max_size`'a da bağımlı
    (Natro için yazılmış `api/.user.ini`'nin 100M değeri sadece CGI/FastCGI
    SAPI'de geçerli) — yeni sunucuda daha düşük bir `post_max_size`/mod_php
    kombinasyonu varsa 80MB'a yakın küçük dosyalar genel bir 502 ile
    başarısız olur, sebebi buradan bakılmadan anlaşılmaz.

31. **`POST /files/stream` tek, parçalanmamış bir HTTP isteğini dosyanın
    tamamı boyunca açık tutuyor — bu, gerçek dünyada (özellikle birden çok
    dosya eşzamanlı yüklenirken) ara sıra tek başına başarısız olabiliyor,
    hiçbir sabit boyut eşiğiyle ilişkili değil.** 2026-07-21'de, gerçek bir
    162 dosyalık/2.9GB'lık klasör yüklemesinde, 8MB'dan 71MB'a kadar farklı
    boyutlarda dosyalarda tekrarlayan, tarayıcıda 524 (Cloudflare) olarak
    görünen ama origin logunda çoğunlukla düz bir Apache "400 Bad Request"
    (uygulamanın KENDİ `Response::error()`'ından değil — 769 baytlık, sabit,
    Apache'nin kendi varsayılan hata sayfası) olarak kayıtlı başarısızlıklar
    görüldü. Araştırıldı ve ELENEN ihtimaller: `post_max_size` (sunucuda zaten
    2-3GB, sorun değil) ve Apache'nin `RequestReadTimeout body=10,minrate=500`
    ayarı (canlıda, isteği kasıtlı olarak çok yavaş/25-35 saniye tamamen
    duraklatarak gönderen kontrollü testler bile başarılı oldu — Cloudflare
    büyük gövdeleri origin'e iletmeden önce kendi tarafında tamponluyor
    gibi görünüyor, yani origin gerçek yavaşlığı çoğu zaman hiç görmüyor).
    VPS CPU'sunun darboğaz olmadığı da doğrulandı (4 eşzamanlı 20MB yükleme
    sırasında `top` %100 idle gösterdi) — buna rağmen bu testte de aynı aile
    hatalardan biri (504 Gateway Timeout) hızlı, kararlı bir bağlantıdan bile
    tekrar üretildi. Net sonuç: kesin tek bir alt-katman sebebi
    (Apache/Cloudflare/PHP-FPM sınırındaki tam mekanizma) doğrulanamadı, ama
    mimari risk açık — büyük dosya/Worker yolunun aksine (16MB'lık parçalar,
    biri başarısız olursa sadece o parça etkilenir) bu yolda parçalama veya
    tekrar deneme YOK, tek bir uzun bağlantı her şeyi taşıyor. **Düzeltme
    (kök sebebi tam izole etmek yerine dayanıklılık ekleyerek): `src/App.tsx`
    `handleAddBatch`'teki `runWorker`, artık her dosyayı en fazla 3 kez dener
    (1.5sn aralıklarla) ve bir dosya üç denemeden sonra da başarısız olursa
    ARTIK TÜM KUYRUĞU DURDURMUYOR — sadece o dosyayı başarısız listesine ekleyip
    devam ediyor, tüm dosyalar bir kez denendikten sonra hâlâ başarısız olanlar
    için kısa bir bekleme sonrası TEK TEK (concurrency=1) ikinci bir tur daha
    deniyor, ancak bu ikinci turdan sonra da başarısız kalanları tek bir özet
    mesajla bildiriyor (bkz. `src/App.tsx` `attemptUpload`/`runPass`).** Gerçek
    kullanıcı iptali (`UploadCancelled`) hâlâ ayrık tutuluyor — tekrar
    denenmiyor, tüm kuyruğu hemen durduruyor.
    Aynı araştırmada gözlemlenen ikinci bulgu — **2026-07-21 içinde aynı gün
    çözüldü**: eşzamanlı çoklu-dosya yüklemede toplam hız ~1.6-2.4MB/s'de tavan
    yapıyordu; istemci hızı (20 Mbps ofis bağlantısından bile aynı tavan), VPS
    CPU'su (idle), origin→Drive ham hızı (izole testte 9.62MB/s, sürdürülen
    testte 30MB/s'e kadar) bunların HİÇBİRİ açıklamıyordu. Google Drive kota
    teorisi de test edilip ELENDİ: VPS'in kendi bağlantısından aynı tam yol
    (Cloudflare → Nginx → Apache → PHP → Drive) tek akışta 10-15MB/s, 4
    eşzamanlı akışta ~15MB/s'e ulaştı (CPU yine idle) — yani sunucu tarafında
    hiçbir tavan yok. Kesin kanıt: AYNI iki bağımsız yükleme mimarisi (bu
    `/files/stream` yolu VE tamamen ayrı >80MB Worker/chunked yolu) gerçek ofis
    cihazlarından (hem wifi hem ethernet) test edildiğinde ikisi de aynı
    ~2-2.4MB/s'e çarptı. **Sonuç: darboğaz bu uygulamada, sunucuda, Cloudflare
    ayarlarında ya da Drive'da değil — istemci cihazının Cloudflare'e olan
    gerçek ağ yolunda (ISP/routing).** Bizim tarafımızda düzeltilecek bir şey
    yok, tekrar araştırılmasına gerek yok.

32. **`src/lib/folderDownload.ts` (gerçek, ziplenmemiş klasör indirme —
    `showDirectoryPicker`) 2026-07-21'e kadar dosyaları TEK TEK, biri bitmeden
    diğerine geçmeden indiriyordu — bu artık `DOWNLOAD_CONCURRENCY = 4`'lük
    bir worker-pool'a çevrildi.** Sebep, aynı gün gotcha #31'deki yükleme
    araştırmasının bir yan ürünü: gerçek bir 3.9GB'lık klasör indirmesinde
    5MB altı dosyalar ortalama sadece ~1MB/s gösterdi (TCP/TLS "ısınma"
    süresini geçemeden dosya zaten bitiyor), oysa aynı klasördeki büyükçe
    dosyalar 7-40MB/s'e ulaşıyordu — yükleme tarafındaki bulgunun aksine
    (orada ağın kendisi sert bir tavandı, eşzamanlılık pek yardımcı olmuyordu),
    burada bağlantının gerçekte çok daha hızlı olabildiği açıkça görülüyordu,
    sadece küçük dosyalarda kullanılamıyordu. Uygulama: `collectFolderEntries`
    önce tüm ağacı gezip (ucuz, veri taşımaz) gerçek alt klasörleri oluşturuyor
    ve her dosyayı düz bir kuyruğa (`QueuedEntry[]`) topluyor; asıl indirme
    (`writeFileEntry`) bu kuyruktan 4 worker ile paralel çekiliyor.
    `onFolderDownloaded` (bkz. gotcha yukarıda, indirme sayaç mantığı) artık
    her üst klasör için "kalan dosya sayısı" sayacı sıfırlanınca tetikleniyor,
    eski koddaki gibi son dosyadan hemen sonra değil — boş bir klasör bu
    sayaca hiç girmediği için ayrıca, `collectFolderEntries` 0 dosya
    bulduğunda `onFolderDownloaded` hemen orada çağrılıyor (aksi hâlde hiç
    tetiklenmez).

33. **"Üzerine Yaz" (upload-conflict Overwrite) artık dosyayı gerçekten yerinde
    günceller — eskiyi çöpe atıp yenisini ayrı bir satır olarak oluşturmuyor.**
    2026-07-21'de eklenen ilk sürüm kasıtlı olarak trash-then-recreate
    yapıyordu (bkz. tasarım notu), ama kullanıcı bunun sonucunda "eskiyi çöp
    kutusundan geri yüklersem ne olur" gibi kafa karıştırıcı bir durum
    yarattığını fark etti. Yeni `PUT /files/{id}/content` (`files_update_
    content`, `routes/files.php`) aynı satırı/`id`'yi koruyarak sadece
    baytları/`size_bytes`/`mime_type`/`drive_file_id`'yi günceller —
    `download_count` ve tüm denetim geçmişi aynı kalır, çöp kutusuna hiçbir
    şey düşmez. `GoogleDriveClient::createResumableUpdateSession()` aynı
    `createResumableSession()`'ın PATCH'li hâli (POST yerine, `files/{fileId}`
    üzerinde, `name`/`parents` metadata'sı olmadan) — döndürdüğü oturum
    URI'si `uploadChunk()`/`uploadFileStreaming()` ile birebir aynı şekilde
    kullanılabiliyor, ayrı bir yükleme mekanizması gerekmedi. Var olan satırın
    `drive_file_id`'si NULL ise (ör. geçmiş bir OAuth kesintisinden dolayı hiç
    Drive'a gitmemiş) sessizce normal bir create-session'a düşüyor — yine aynı
    satırı güncelliyor, sadece Drive tarafında ilk kez gerçek bir dosya
    oluşturuyor. **Sadece küçük-dosya eşiğinin (`LARGE_UPLOAD_THRESHOLD_BYTES`,
    80MB) altındaki dosyalar için** — üstündeki dosyalar hâlâ eski trash-then-
    recreate davranışını kullanıyor, çünkü >80MB parçalı/Worker yolunun henüz
    "yerinde güncelleme" karşılığı yok. Frontend tarafında `DriveInterface.tsx`
    `executeUploadBatch`'in "overwrite" dalı, çakışan girişleri silmek yerine
    `PendingFileUpload.overwriteFileId` ile işaretliyor; `App.tsx`
    `handleAddBatch`'teki `attemptUpload` bu alanı görünce
    `filesApi.updateContentWithProgress()`'i çağırıyor ve sonucu `createdFiles`
    yerine ayrı bir `updatedFiles` listesine yazıyor — `finally` bloğu
    `updatedFiles`'ı `id` eşleşmesiyle var olan `files` state'ine gömüyor
    (yeni satır olarak eklemiyor). **Güncelleme (2026-07-22):** "Üzerine Yaz"
    butonunun metni ("eskisi çöp kutusuna gider") bu değişiklikten sonra da
    eskisi gibi kalmıştı — artık doğru şekilde "eski veriler geri
    döndürülemez" diyor (`UploadConflictDialog.tsx`), çünkü artık gerçekten
    çöp kutusuna giden bir şey yok.

34. **`files_download` artık çöpteki bir dosyayı asla vermiyor — bu daha önce
    sadece ön yüzde (UI) gizleniyordu, sunucu tarafında hiçbir engel yoktu.**
    Çöp Sepeti görünümündeyken indirme butonu/menü öğesi zaten gizleniyordu
    (`DriveInterface.tsx`'te her yerde `effectiveFolderId !== 'TRASH'`
    kontrolü), ama bu sadece arayüz seviyesinde bir gizlemeydi — `GET
    /files/{id}/download`'a doğrudan bir istek (ya da bayatlamış bir
    seçim durumu, bkz. gotcha altında) hâlâ dosyanın gerçek baytlarını
    veriyordu. `files_download`'a artık en başta `$file['deleted_at'] !==
    null && !$isInlinePreview` kontrolü eklendi — `?inline=1` ile önizleme
    (dosyayı silmeden önce göz atma) kasıtlı olarak etkilenmiyor, sadece
    gerçek "indir" isteği 404 ile reddediliyor. `zip_download`'un kendi
    dosya toplama fonksiyonu (`zip_collect_entries`) bunu zaten doğru
    yapıyordu (`AND deleted_at IS NULL` filtresiyle) — eksik olan tek yer
    tekil dosya indirme yoluydu.

35. **`deleted_by` artık her zaman gerçek işlemi yapan kullanıcı — klasörün
    "sahibi" olan müşteriye değil.** Önceki tasarım (`Scope::
    resolveOwningCustomerId`, artık kaldırıldı) kasıtlıydı: bir personel/
    editör bir müşterinin klasörü İÇİNDEN bir şey silince, `deleted_by`
    o müşterinin id'sine ayarlanıyordu — amaç, müşterinin çöp kutusuna
    bakan birinin "kim sildi" sorusuna kafa karıştırıcı bir personel adı
    yerine tanıdık bir isim görmesiydi. Kullanıcı (gerçek yönetici hesabıyla
    test ederken) bunun TAM TERSİNİ istediğini açıkça belirtti: personel/
    editör ne silerse silsin (nerede olursa olsun) kendi gerçek adı
    yazmalı, sadece müşteri kendi girişiyle/paylaşım linkiyle kendi
    klasöründen bir şey silerse o müşterinin adı yazmalı. `files_delete` ve
    `folders_delete`'teki `$owningCustomerId` ikamesi tamamen kaldırıldı —
    `deletedById` artık her zaman düz `$user['id']`. Bunun doğal sonucu:
    Çöp Sepeti'ndeki "Müşteriler" filtre sekmesi artık SADECE müşterilerin
    kendi eylemleriyle sildiklerini gösteriyor, personelin bir müşteri
    klasöründen sildiklerini değil (bunlar artık doğru şekilde "Personel"/
    "Editörler" sekmelerinde görünüyor) — bu, istenen davranışın doğal ve
    doğru bir sonucu, ayrıca düzeltilmesi gereken bir yan etki değil.

36. **Yükleme/indirme iptali artık native `window.confirm()`/`alert()`
    kullanmıyor — bu, kullanıcı isteğiyle bilinçli bir tasarım kararı.**
    `TransferTray.tsx`'in "İptal Et" butonu artık kutunun kendi içinde
    inline bir "Emin misiniz? [Vazgeç] [Evet, iptal et]" satırına dönüşüyor
    (`confirmingCancel` state), ve onaylanınca yine kutunun kendi boş
    (idle) durumu "Yükleme/İndirme iptal edildi." yazıyor
    (`cancelledUpload`/`cancelledDownload` state) — App.tsx'teki genel hata
    `alert()`'leri artık gerçek bir iptali ASLA göstermiyor (bkz. gotcha 37
    ile birlikte okunmalı). Tarayıcının native dialog'ları bu projede
    kasıtlı olarak "çirkin/güvenilmez" bulunup kaldırıldı — yeni bir
    onay/bilgilendirme ihtiyacı çıkarsa native `confirm`/`alert` yerine bu
    aynı in-UI-state deseni tekrar kullanılmalı.

37. **`xhr.abort()`, hiç `.send()` edilmemiş bir XMLHttpRequest üzerinde
    çağrılırsa `onabort` HİÇ tetiklenmiyor — bu, promise'i sonsuza kadar
    askıda bırakıp tüm yükleme kuyruğunu (ve tepsiyi) "yükleniyor" durumunda
    kilitleyen gerçek bir production hatasıydı.** `src/api.ts`'teki
    `createWithProgress`, bir dosya yeniden denenirken (gotcha 31'deki
    retry mantığı) tam da kullanıcı "İptal Et"e bastığı anda yeni bir XHR
    açıyor — `signal.aborted` zaten `true` olduğu için eski kod
    `xhr.abort(); return;` yapıp promise'i hiç `resolve`/`reject`
    etmiyordu (spec gereği: `abort()`, `send()` edilmemiş bir istekte
    "isteği sonlandır" adımlarını hiç çalıştırmıyor, çünkü istek zaten hiç
    "başlamamış"). Gerçek tarayıcı testiyle bulundu (yerelde bilinen
    Drive-502 hatasının retry döngüsünü tetiklediği bir senaryoda) —
    düzeltme: `signal.aborted` zaten true ise `xhr.abort()` çağırmak
    yerine doğrudan `reject(new UploadCancelled())` çağır. Herhangi bir
    yeni XHR-tabanlı iptal edilebilir istek eklenirse bu aynı deseni
    tekrar etmemek gerekir.

38. **Çöpe atılmış (`deletedAt`) bir dosya/klasörü ağaçtan toplayan/boyut
    hesaplayan HER yer bunu KENDİ BAŞINA filtrelemek zorunda — tek bir
    merkezi "elek" yok, bu yüzden aynı hata bu oturumda 3 ayrı yerde ayrı
    ayrı bulunup düzeltildi.** `App.tsx` ve `DriveInterface.tsx`'in kendi
    ayrı `getFolderSize` kopyaları (müşteri kartındaki toplam boyut) VE
    `src/lib/folderDownload.ts` (gerçek klasör indirme — `collectFolderEntries`,
    `allSelectedFolderIds` yürüyüşü, `filesInSelectedFolders`/
    `directlySelectedFiles`) hiçbiri `!f.deletedAt` kontrolü yapmıyordu —
    sonuç: silinen dosyaların baytları müşteri kartında hâlâ görünüyordu VE
    "hepsini indir" çöpteki dosyaları da indirmeye çalışıp (bkz. gotcha 34,
    sunucu artık bunu reddediyor) ilerleme çubuğunun asla %100'e
    ulaşmamasına yol açıyordu. Yeni bir dosya/klasör ağacı toplama/
    hesaplama kodu yazılırsa, `files`/`folders` state'inin HER ZAMAN
    silinmiş öğeleri de içerdiği (sadece `deletedAt` alanıyla işaretli)
    unutulmamalı — filtrelemek çağıranın sorumluluğu.

39. **Tailwind'de bir flex elemanına `shrink-0` koymak, o elemanın kendi
    İÇİNDEKİ `flex-wrap`'in hiçbir faydasını sağlamaz — dıştaki flex satırı
    onu hâlâ tam (sarmasız) genişliğiyle yerleştirmeye çalışır.** Çöp
    Sepeti başlığı (`DriveInterface.tsx`, `Global Geri Dönüşüm` bloğu),
    sekmeler+"Çöp Kutusunu Boşalt" butonunu saran kapsayıcıya hem
    `flex-wrap` hem `shrink-0` birden verilmişti — `flex-wrap` içeride
    sarmayı SERBEST bırakıyor ama `shrink-0` dıştaki flex satırına "beni
    asla küçültme" diyor, yani tarayıcı yine de bu kapsayıcıya sarmasız
    tam genişliğini veriyor ve `flex-wrap` hiç devreye girmiyor — kardeş
    eleman (başlık metni) sıkışıp her kelime ayrı satıra düşüyordu.
    Düzeltme: kapsayıcıdan `shrink-0`'ı kaldırmak (düğmelerin kendisi hâlâ
    `shrink-0` tutuyor, tek tek sıkışıp okunaksızlaşmasınlar diye) — bu,
    dıştaki flex satırının kapsayıcıya makul bir genişlik ayırmasına, o
    genişlik yetmediğinde de `flex-wrap`'in gerçekten devreye girip
    düğmeleri alt satıra kaydırmasına izin veriyor. Gerçek tarayıcıda 900px
    ve 390px (gerçek mobil) genişliklerde doğrulandı — ayrıca "Çöp Kutusunu
    Boşalt" butonunun mobilde bulunamaması da aynı kökün bir belirtisiydi
    (eski hâlde `overflow-x-auto` + gizli scrollbar vardı, buton görünür
    alanın dışına taşıyordu, kaydırılması gerektiğine dair hiçbir işaret
    yoktu).

40. **Brute-force koruması eklendi (`api/lib/RateLimiter.php`): `/login` ve
    `/shared-links/{id}/unlock` artık sınırsız deneme kabul etmiyor.** DB'de
    tek bir tablo (`login_throttle`, sadece `bucket_key` + `created_at`) her
    başarısız denemeyi bir satır olarak tutuyor; `RateLimiter::guard()` bir
    bucket'ta son N dakikada M'den fazla satır varsa 429 döndürüyor (şifre
    kontrolünden ÖNCE, bcrypt maliyetine bile girmeden). Login iki ayrı
    bucket kullanıyor — `login_id:{eposta}` (5 deneme/15dk, tek hesabı hedef
    alan saldırıya karşı) ve `login_ip:{ip}` (20 deneme/15dk, tek kaynaktan
    çok hesaba saldırıya karşı); paylaşım linki `share_unlock:{linkId}:{ip}`
    (8/15dk) kullanıyor — link başına değil link+ip başına, çünkü aynı linki
    paylaşan birden fazla gerçek alıcı birbirini kilitlememeli. Başarılı
    girişte kendi bucket'ı temizleniyor (`RateLimiter::clear()`), yoksa
    doğru şifreyi giren biri az önceki yanlış denemeler yüzünden kilitli
    kalabilirdi.

41. **Yukarıdaki RateLimiter'ın CANLIDA ilk denemesi tamamen sessiz şekilde
    işe yaramadı — 6 art arda yanlış giriş denemesi hep 401 döndürdü, 429 hiç
    tetiklenmedi.** Kök neden, `bootstrap.php`'nin en üstündeki
    `date_default_timezone_set('Europe/Istanbul')` yorumunun zaten uyardığı
    AYNI sınıf hata: bu VPS'te MySQL'in kendi saati **UTC** (`@@session.time_zone
    = SYSTEM`, sunucu saati UTC), ama `RateLimiter::countRecent()` pencere
    sınırını (`cutoff`) PHP'nin `date()` fonksiyonuyla hesaplıyordu — o da
    PHP'nin zorladığı Europe/Istanbul (UTC+3) saatini kullanıyor. Sonuç: her
    `guard()` çağrısı, "şimdi"yi gerçek UTC'den ~3 saat ileride sanıyor, bu
    yüzden az önce UTC ile eklenmiş satırı bile "süresi dolmuş" sanıp siliyor
    — yani sayaç neredeyse hiç birikemiyor. Yerel geliştirme ortamında bu hiç
    fark edilmedi çünkü bu Mac'in sistem saati zaten Europe/Istanbul, yani
    PHP ile yerel MySQL'in saatleri tesadüfen örtüşüyordu; canlıda VPS'in
    sistem saati UTC olduğu için ayrıştı. **Düzeltme**: hem yazma
    (`recordFailure`, `created_at`'i artık DB'nin `DEFAULT CURRENT_TIMESTAMP`'ine
    bırakmıyor, açıkça `gmdate()` ile UTC yazıyor) hem okuma (`countRecent`'in
    `cutoff` hesabı da `gmdate()`) tarafı UTC'de, birbirinden bağımsız her iki
    sunucunun yerel saat ayarından — sabit bir referansta buluşuyor. **Ders**:
    bir tabloya PHP tarafından yazılan bir zaman damgası, aynı tabloda başka
    bir PHP çağrısıyla karşılaştırılacaksa, ikisi de `gmdate()`/UTC kullanmalı
    — `date()` (yerel saat) ile DB'nin kendi `DEFAULT CURRENT_TIMESTAMP`'i
    (DB'nin kendi saati) asla aynı varsayımla güvenilemez, ikisi de farklı
    sunucularda farklı ayarlanmış olabilir. Bu tür bir hatayı canlıda fark
    etmenin tek yolu gerçek bir uçtan uca deneme oldu (kod incelemesiyle
    görülemezdi) — bkz. proje kuralı: her önemli değişiklik gerçek ortamda
    doğrulanmalı.

42. **Güvenlik response header'ları iki ayrı yerde ayarlanıyor, birbirine
    karıştırılmamalı.** `api/bootstrap.php` her API yanıtına (JSON, dosya
    indirme) `X-Content-Type-Options`/`X-Frame-Options`/`Referrer-Policy`/HSTS
    ekliyor — ama CSP'yi burada AYARLAMIYOR, çünkü gerçek HTML sayfasını
    (React uygulamasının kendisi) bu PHP script'i değil, Apache doğrudan
    `public_html`'den sunuyor; `api/` tamamen ayrı bir alt yol/istek. Asıl
    sayfayı çerçeveleme/script çalıştırma kısıtlarının (CSP,
    `frame-ancestors`) etkili olacağı yer `public/.htaccess` — Vite build'i bu
    dosyayı `dist/`'e olduğu gibi kopyaladığı için mevcut deploy akışına
    (`tar dist/ -> public_html`) hiç dokunmadan otomatik gidiyor. CSP,
    uygulamanın gerçekten kullandığı harici kaynaklara göre kuruldu: Google
    Fonts (`fonts.googleapis.com`/`fonts.gstatic.com`, `index.css`'teki
    `@import`), Cloudflare chunk-relay Worker
    (`ruf-upload-relay.muslumkazdal.workers.dev`, büyük dosya yüklemesi),
    `style-src 'unsafe-inline'` (arkaplan/kolaj özelleştirmesinin dinamik
    inline `style={{...}}` renk/font/boyut değerleri için gerekli). **Deploy
    önce Report-Only header'la yapıldı** (`Content-Security-Policy-Report-Only`
    — hiçbir şeyi bloklamaz, sadece konsola loglar), gerçek production'da
    Playwright ile gezilip konsolda tek bir gerçek ihlal bulundu:
    `static.cloudflareinsights.com` (Cloudflare'in zone kendi proxy'sinden
    otomatik enjekte ettiği analytics beacon script'i — bizim kodumuzdan
    gelmiyor) — bu `script-src`'e eklenip tekrar Report-Only'de doğrulandı
    (sıfır ihlal), ancak SONRA gerçek `Content-Security-Policy` (enforcing)
    header'ına geçildi. Bu iki aşamalı yaklaşım kasıtlı: yanlış bir CSP tüm
    siteyi (login sayfası dahil) beyaz ekrana düşürebilir, Report-Only aşaması
    bunu sıfır riskle canlıda test etmeyi sağladı.
