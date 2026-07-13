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
