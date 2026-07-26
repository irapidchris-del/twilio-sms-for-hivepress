# Releasing a new version

The plugin updates itself from **GitHub Releases** using the bundled
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library. Site owners who have the plugin installed will be notified on their
**Plugins** screen and can update with one click — provided each release is
published the way described below.

## One-time facts

- **Plugin slug / install folder:** `twilio-for-hivepress`
- **Release asset name (must never change):** `twilio-for-hivepress.zip`
- **Repository:** `irapidchris-del/twilio-sms-for-hivepress`

The updater is configured with `REQUIRE_RELEASE_ASSETS`, so it will **only**
offer an update when a release has an asset named `twilio-for-hivepress.zip`.
If you forget to attach it, users simply see no update (rather than a broken one).

## Release steps

1. **Bump the version** in three places so they all match the release tag:
   - `twilio-for-hivepress.php` → `Version:` header
   - `readme.txt` → `Stable tag:`
   - `readme.txt` → add a `== Changelog ==` entry
   - (optional) `languages/twilio-for-hivepress.pot` → `Project-Id-Version`

2. **Commit** the bump on your release branch and merge to `main`.

3. **Build the zip** from the merged commit:

   ```bash
   ./build.sh
   ```

   This writes `dist/twilio-for-hivepress.zip` (the release asset) and
   `dist/twilio-for-hivepress-<version>.zip` (a versioned copy for your records).
   Both unpack to a `twilio-for-hivepress/` folder — no version in the folder or
   main file name — so WordPress never shows a mismatch warning.

4. **Create a GitHub Release:**
   - Tag: `v<version>` (e.g. `v1.2.0`) — the leading `v` is fine; the updater
     strips it. The tag version must match the plugin header `Version`.
   - Attach `dist/twilio-for-hivepress.zip` as a release asset (filename exactly
     `twilio-for-hivepress.zip`).
   - Publish (not a draft, and not marked pre-release).

That's it. Within ~12 hours WordPress sites will see the update; an admin can
force an immediate check via **Dashboard → Updates → Check again**.

## The permanent forum download link

Because the release asset always has the same name, GitHub exposes a permanent
"latest release" URL that instantly downloads the newest version:

```
https://github.com/irapidchris-del/twilio-sms-for-hivepress/releases/latest/download/twilio-for-hivepress.zip
```

Post that link once on the HivePress community forum — it never needs updating.
It ends in `.zip` and downloads the current release every time.

## Notes

- **Bootstrapping:** auto-updates only work when the *currently installed*
  version already contains this updater. The first version that includes it
  (1.1.0) must be installed manually once (via the link above); every release
  after that updates in place.
- **Private repo:** the updater uses the public GitHub API. If the repo is ever
  made private, you must supply a token via
  `$checker->setAuthentication( 'ghp_...' )` in the main file, or the release
  asset download will 404 for users.
- **"View details" popup:** WordPress builds the plugin-details screen from
  `readme.txt` (sections, changelog, "Tested up to"). Keep it current.
