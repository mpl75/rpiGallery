# rpiGallery

Lightweight PHP photo gallery for Raspberry Pi. Single-page app: index.php (UI + API), crawler.php (background processor), gallery.css, config.json.

## Architecture

- **index.php**: Auth, folder/image browsing, lightbox, crawler control API, share links, video/image serving, album merging (see Merging Albums)
- **crawler.php**: Background process (nohup), auto-files the Inbox first (see Photo Import), adopts photos that moved between albums, then generates thumbnails + fullsize previews, extracts EXIF (date, camera, GPS, owner), saves data.json per folder, and sweeps orphaned thumbnails at the end
- **gallery.css**: Dark theme, CSS variables in :root, rem units with 12px base on `html`
- **config.json**: Not in git (contains bcrypt hashes). See config.example.json for structure

## Deployment Model

Runs on a single Raspberry Pi behind Apache with PHP 7.4 — **write PHP 7.4-compatible code**, no arrow-function-only syntax in the hot paths, no `str_contains`/`match`. Target it, do not assume the version your local CLI reports.

- Apache rewrite: `/gallery/...` -> `/rpiGallery/index.php?path=...`. Everything routes through index.php; only that plus whitelisted static assets are reachable
- Photo source, thumbnail and fullsize directories all come from `config.json` (`rootGallery`, `thumbnailsFolder`, `fullsizeFolder`) — never hardcode a path. `thumbnails`/`fullsize` inside the web dir are symlinks to bulk storage
- Source archive is a shared directory owned by a dedicated group with setgid, writable by both the human users and Apache. The crawler runs as the web user, never as root, and must not require it (see Photo Import)
- Media originals live outside the repo; the repo mirrors 1:1 onto the web dir

Host-specific details — actual hostnames, disk layout, group names, OS and kernel versions, service configuration and operational playbooks — are in **`INFRA.local.md`**, which is deliberately not in git and not deployed. Read it before touching anything on the machine itself.

## Deploy

Use `./sync-rpi.sh` — one-way rsync mirror of the repo to `/var/www/html/rpiGallery/`.

```bash
./sync-rpi.sh -n   # dry-run: show what would change, change nothing
./sync-rpi.sh      # real deploy (rsync --delete + chown www-data)
```

- Runs `--rsync-path="sudo rsync"` (passwordless sudo on the Pi), then chowns to www-data (skips symlinks + .well-known). Local macOS rsync is openrsync, so no `--chown`; ownership is fixed in a post-transfer pass.
- `--delete` mirrors the repo, but protected excludes are never uploaded or deleted: `config.json` (real hashes + authSecret live only on server), `thumbnails`/`fullsize` (symlinks to RAID), `.well-known` (Let's Encrypt), `shares.json`, `crawler.json/.pid/.stop`, `*.log`.
- Repo structure maps 1:1 onto the server web dir. `classStorage` is a git submodule (clone with `--recursive`).

`config.json` is server-authoritative — never deploy it from local. To change it, edit on the Pi as www-data.

## Key Design Decisions

- data.json versioning: `_version` field in data.json, `dataVersion` in config.json. Bump config version -> crawler refreshes metadata without regenerating thumbnails
- index.php must `unset($data['_version'])` after loading data.json (two places: folder view + file serving)
- Files renamed to yyyy-mm-dd_hh-mm-ss.jpg based on EXIF DateTimeOriginal
- Crawler saves data.json after each file (crash resilient)
- Video support: serve originals (no transcoding on slow ARM), thumbnail via ffmpeg
- **Video timestamps are the one place timezones matter.** Photos copy `DateTimeOriginal` out of EXIF verbatim (already local wall clock); videos derive a Unix timestamp and *format* it, so they follow the process default timezone. crawler.php pins it via `config.timezone` (default Europe/Prague) right after loading classStorage, which sets the default to GMT at include time. Without that pin every video came out shifted by the UTC offset -- wrong displayed time, wrong sort order (index.php sorts by `dateTaken`), and Inbox albums off by a day for anything shot after midnight
- Video date priority: filename (local time, no conversion needed -- both `2026-08-03_20-03-40` and Android `20251013_130938`) > `com.apple.quicktime.creationdate` > ffprobe `creation_time` (UTC)
- Session-based auth with bcrypt (TV can't handle Basic Auth)
- Remember-me cookie `auth`: HMAC token `user|exp|sig`, TTL 90 days (TOKEN_TTL). Signed with `config.authSecret` (dedicated random key; fallback to first user's hash). On cookie restore, user is re-looked-up in config and `admin` is read from config, never from the token. Changing authSecret invalidates all remember-me cookies (sessions survive)
- Share links: /gallery/s/HASH with expiry (7/30/90 days), no auth needed, no prev/next folder nav

## Merging Albums

A multi-day event that the Inbox split into per-day albums can be pulled back into one album without regenerating anything.

- **In the app**: album view -> "Sloučit do" (admin only) moves originals, thumbnails and fullsize previews into a sibling album and merges the data.json entries. Refused while the crawler is running, and for albums containing subfolders. Name collisions get a `_1` suffix (original and thumbnail independently)
- **Manual moves** (Finder/SMB/ssh) are recognised too: `buildMoveIndex()` indexes every data.json entry whose source file is gone, and a new file in another album is matched by name+size+mtime, or by content fingerprint if it was renamed on the way. The thumbnail, preview and backup record are relocated instead of regenerated
- `hash` in data.json is the first-64 kB md5 fingerprint that guards those matches. Ambiguous matches (two entries with the same key) are dropped -- regenerating beats attaching the wrong thumbnail
- `sweepOrphans()` runs at the end of every **complete** crawler pass (skipped after a stop) and deletes thumbnails/previews/data.json entries whose source is gone, plus stray files nothing points at. Before it existed, an album emptied out completely kept its thumbnails forever -- the in-loop cleanup only reaches folders that still hold media
- Adoption and sweep activity is logged to `moves.log`; counts land in crawler.json (`adoptedFiles`, `sweptFiles`, `sweptFolders`)
- **Prevention beats merging**: a subfolder in the Inbox is filed as one album regardless of how many days it spans (only loose files in the Inbox root get split per day)

## Photo Import (Inbox)

Users drop photos into `Archiv/Inbox` (config `inboxFolder`); the crawler auto-files them at the start of each run (`processInbox()` in crawler.php), then thumbnails as usual. Inbox is hidden from the gallery listing.

- Nesting from config `archiveNesting` (`["Y","Y-m"]`, PHP `date()` formats) -> `rok/rok-měsíc/<album>`
- Subfolder in Inbox = one album (name = subfolder; date from leading `yyyy-mm-dd` in the name, else earliest photo's EXIF, prepended to the name if missing)
- Loose files in Inbox root = filed by EXIF date; merge into an existing `yyyy-mm-dd*` album for that date if one exists (`findExistingAlbum`, first writable match), else a bare `yyyy-mm-dd` album
- Crawler runs as www-data and **never as root**. It creates dest album dirs (owned www-data, group rpiGallery via setgid -> both users can add) and moves files with `rename()` (preserves the real owner michal/sarka). POSIX: a non-owner can't `chgrp`, so file groups are left as-is (viewing needs only other-read); a whole foreign folder moved into Inbox wholesale is filed via a folder rename but keeps its source group
- **Rejected alternative:** a sudo root helper reaching into the 700 private folders — a web compromise could rewrite the (www-data-writable) helper and run it as root. Inbox stays entirely within the already-shared Archiv, needs no root/sudo, and never touches the private folders

## CSS Rules

- NEVER use letter-spacing (user is a trained typographer)
- Minimum font size: 12px
- rem units with 12px base on `html` element (not body - rem references html)
- Color variables in :root, never hardcode colors outside :root definitions
- No emojis in code unless asked

## UI Rules

- Show only: date, camera model (not Make+Model), owner
- No technical EXIF (aperture, ISO, focal length etc.)
- Camera aliases in config for internal model codes (e.g. "A059P" -> "Nothing Phone (3a) Pro")
- Keep Czech diacritics in filenames

## Azure Backup

Originals are uploaded once to Archive tier (`config.azureBackup`), `restore.php` drives rehydrate/download.

- `backupBlob` in data.json records the blob path actually used. A moved photo keeps its original blob path -- an Archive-tier blob cannot be copied or overwritten server-side without rehydration, so re-uploading just to match the new album would cost twice and orphan the old blob
- `_index/<path>/data.json` mirrors each folder index to Azure (default on, `azureBackup.backupIndex`). Without it the blob-path -> album mapping lives only on the RAID. Kept off Archive tier so it stays readable and overwritable; `restore.php archive` skips the prefix
- Uploads are skipped for entries with `backedUp` or `backupSkipped`, so adopted/merged photos are never sent twice

## Current State

- dataVersion: 8 (7 added the `hash` fingerprint for move detection; 8 re-derives video dates in the correct timezone). config.json on the Pi is authoritative -- check the real value there before assuming. Bumping it triggers a one-time metadata refresh: EXIF re-read + hash backfill + video date re-derivation, no thumbnail regeneration
- `mappedName` is not recomputed by a metadata refresh, so videos fixed by the v8 refresh keep a thumbnail filename derived from the old wrong time. Internal only -- display and sorting both use `dateTaken`
- EXIF data in data.json: DateTimeOriginal, Camera, Orientation, Width, Height, gps {lat, lon}
- Other data.json fields: mtime, filesize, hash, mappedName, owner, type, backedUp, backupBlob, backupSkipped, hidden
- Video extensions: mp4 (but video processing is slow on RPi)
- GPS extracted from JPEG EXIF, stored as `exif.gps: {lat, lon}` - ready for future map feature
