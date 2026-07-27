# Releasing a new version

The plugin updates itself from **GitHub Releases** using WordPress's native
`update_plugins_github.com` mechanism (WP 5.8+), keyed off the `Update URI`
header in `twilio-for-hivepress.php`. No third-party update library is bundled —
the updater lives in the main plugin file.

Site owners who have the plugin installed are notified on their **Plugins**
screen and can update with one click, provided each release is published the
way described below.

## One-time facts

- **Plugin slug / install folder / text domain:** `twilio-for-hivepress`
- **Release asset name (must never change):** `twilio-for-hivepress.zip`
- **Repository:** `irapidchris-del/twilio-sms-for-hivepress`

The updater reads `https://api.github.com/repos/OWNER/REPO/releases/latest`,
takes the version from the release tag (a leading `v` is stripped), and uses the
first release asset whose name ends in `.zip` as the update package. The zip must
contain a single top-level `twilio-for-hivepress/` folder.

## How the release workflow works

`.github/workflows/release.yml` (permissions: `contents: write`) runs on two
triggers:

- **`release: published`** — when you publish a GitHub Release in the UI, it
  builds the zip and uploads it to that release as `twilio-for-hivepress.zip`.
- **`workflow_dispatch`** — a manual run with inputs `tag` (required) and
  `notes` (optional). If the release doesn't exist it is created at the current
  commit (`--generate-notes` when no notes are given); if it already exists the
  tag is force-moved to the current commit, the notes are updated when provided,
  and the asset is re-uploaded with `--clobber`.

Either way the build step runs `build.sh`, which `git archive`s the repo into
`dist/twilio-for-hivepress.zip` with a `twilio-for-hivepress/` prefix. Dev files
(`.github`, `build.sh`, `RELEASING.md`, `phpcs.xml`, `tests`) are kept out of the
zip via `.gitattributes` `export-ignore`.

## Releasing from a Claude Code session

`gh` and the raw releases REST API are not available inside a session, so:

1. **Bump the version** so all of these match the tag you will publish:
   - `twilio-for-hivepress.php` → `Version:` header
   - `readme.txt` → `Stable tag:` and a new `== Changelog ==` entry
   - `languages/twilio-for-hivepress.pot` → `Project-Id-Version` (optional)
2. **Commit and merge to the default branch** (`main`) — `workflow_dispatch`
   only runs from workflows on the default branch.
3. **Trigger the workflow** with the GitHub MCP tool `actions_run_trigger`:
   - method: `run_workflow`
   - workflow_id: `release.yml`
   - ref: `main`
   - inputs: `{ "tag": "v1.2.0", "notes": "<changelog markdown>" }`
4. **Verify** with `get_release_by_tag` that the tag, the notes, and the
   `twilio-for-hivepress.zip` asset all landed.

## Releasing manually (from your own machine)

1. Bump the version as above, commit, push to `main`.
2. Either publish a GitHub Release tagged `v<version>` (the workflow attaches the
   asset), or run the workflow from the Actions tab with the tag/notes inputs.

## The permanent forum download link

Because the asset always has the same name, GitHub exposes a permanent
"latest release" URL that instantly downloads the newest version:

```
https://github.com/irapidchris-del/twilio-sms-for-hivepress/releases/latest/download/twilio-for-hivepress.zip
```

Post that link once on the HivePress community forum — it never needs updating.

## Notes

- **Bootstrapping:** auto-updates only work when the *currently installed*
  version already contains this updater. The first version that includes it
  (1.1.0) must be installed manually once (via the link above); every release
  after that updates in place.
- **Manual check:** each site can force an immediate check via the
  "Check for updates" link under the plugin on the Plugins screen, or via
  Dashboard → Updates.
- **Private repo:** the updater uses the public GitHub API and the public asset
  download URL. If the repository is made private, the asset URL will 404 for
  users and the updater must be extended to send an authenticated token.
