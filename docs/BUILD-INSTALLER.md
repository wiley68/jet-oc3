# Jet OC3 installer builder — ZIP and SHA-256 only

The canonical output contains exactly two files:

```text
dist/
  CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip
  CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.sha256
```

The builder does not create JSON/Markdown build reports, verification reports or
`dist-history/`. Previous generated reports/history were removed at the owner's
request. Historical phase reports in `tests/` describe their original checkpoints;
the current output policy is this document. `dist/` remains Git-ignored.

## Candidate from current local source

Requires existing Bash, Python 3.9+, PHP CLI and Git. No downloads, Composer/npm,
Docker, database connection, SMTP or OpenCart installation occurs.

```bash
python3 tests/release-manifest.py
python3 tests/builder.py
bash tools/build-installer.sh candidate
```

The wrapper and Python CLI default to the repository's absolute `dist/`, regardless
of the caller's working directory. Both candidate and release output are flat.
Candidate mode packages the current uncommitted source and prints **TEST /
UNRELEASED**, the ZIP path and SHA-256 to the terminal. No report file is written.

`upload/system/library/jetcredit/version.php` is the authoritative module version;
XML, payment controllers and manifest must agree. The version remains **2.0.0**.
Packaging does not modify application source, version metadata or Git state.

## Manifest and validation

The builder reads the reviewed `docs/release-manifest.json`. It never adds extra
files or silently regenerates the manifest. After an approved runtime change:

```bash
python3 tests/release-manifest.py --write
```

That command updates metadata only and never creates a ZIP. The 89-entry manifest
contains `install.xml` and 88 files under `upload/`, with no wrapper directory.
Tests, docs, builder, Git/IDE data, Windows download metadata, caches, logs,
credentials and database dumps are excluded.

The builder checks exact inventory, sizes/SHA-256, referenced includes/assets,
XML/version consistency and runtime PHP syntax. It rejects symlinks, duplicate
entries, traversal paths, unexpected or missing sources, invalid PHP/XML and hash
mismatches. ZIP CRC and source byte equality are verified before publication.

## Collision and interrupted builds

No existing ZIP or checksum is overwritten, including byte-identical files and
candidate/release collisions. Either existing component blocks publication.
Replacement requires an explicit owner instruction to remove the previous pair;
there is no automatic backup/history or silent replacement.

An exclusive output lock prevents cooperating concurrent builds. Completed files
are published with exclusive hard links, so a race cannot overwrite a target.
Temporary staging and the lock are removed after an ordinary success/refusal.
After a crash, inspect any partial pair or `.jet-installer.lock` before retrying;
there is no automatic stale-lock takeover or deletion of existing files.

## Independent deterministic verification

The explicit `--output` override is for tests/repeat verification in a fresh
temporary directory. It uses the same two-file flat layout:

```bash
jet_repeat_dir="$(mktemp -d /tmp/jet-oc3-repeat.XXXXXXXX)"
bash tools/build-installer.sh candidate --output "$jet_repeat_dir"
cmp dist/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip "$jet_repeat_dir/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip"
```

ZIP entries are sorted with fixed timestamps, modes and compression. Identical
source and the same Python/zlib toolchain produce identical ZIP bytes. Different
zlib versions can compress differently.

Verify the current pair from `dist/`:

```bash
sha256sum --check CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.sha256
python3 -m zipfile -t CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip
```

`python3 tests/builder.py` exercises exact output layout, manifest, source
preservation, CRC/corruption, determinism, overwrite refusal, locks and committed
source isolation. `--source-only` is available when ZIP generation is prohibited.
Tests use temporary directories and do not replace the canonical pair.

## Future committed-source build

After a separately approved real commit/tag contains the reviewed source/manifest:

```bash
bash tools/build-installer.sh release --ref v2.0.0
```

A full 40-character committed SHA is also accepted; branches and implicit HEAD
are refused. Release mode reads immutable Git objects, never borrows dirty files
and never creates a commit/tag or checks out source. It prints **COMMITTED BUILD /
NOT PUBLISHED** and writes only ZIP/checksum. Building is not publication.

Follow [CLEAN-INSTALL.md](CLEAN-INSTALL.md) and
[PURCHASE-LIFECYCLE.md](PURCHASE-LIFECYCLE.md) for owner-operated tests. Real MySQL
contention, live modification refresh and browser/Twig E2E remain owner checks.
