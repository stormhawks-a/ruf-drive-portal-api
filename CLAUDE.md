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
