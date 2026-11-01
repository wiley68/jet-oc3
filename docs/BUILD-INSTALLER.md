# Jet OC3 installer builder — canonical flat dist/ output

R3 packages the accepted R2 runtime and fresh schema revision 2 as **2.0.0 TEST /
UNRELEASED**. The superseded IMP-05A `artifacts/` directory was removed at the
owner's request after R3 verification. Historical source reports remain in `tests/`.
No runtime or build dependency uses that directory. No final release is published.

Requires existing Bash, Python 3.9+ standard library, PHP CLI and Git. No downloads,
Composer/npm, Docker, database connection, SMTP or OpenCart installation occurs.

## Candidate from current uncommitted source

From the repository root:

```bash
python3 tests/release-manifest.py
python3 tests/builder.py
bash tools/build-installer.sh candidate
```

Both the shell wrapper and Python CLI default to the repository's absolute `dist/`
regardless of caller working directory. Candidate and release output is flat:

```text
/home/wiley/projects/jet-oc3/dist/
  CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip
  CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.sha256
  CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.build-report.json
```

No candidate/release/fingerprint/timestamp subdirectory is created. The fingerprint
remains report metadata. `/dist/` is Git-ignored, including generated ZIPs, checksums,
reports and transient staging data. The builder/tests/docs remain source files.
R3's independent verification report may also be kept directly in `dist/`.

The candidate report says **TEST / UNRELEASED**, records HEAD and dirty status,
sets `git_tag` to null and `working_tree_used` to true. It lists every runtime
path/size/SHA-256, source fingerprint, manifest/builder hashes, version source,
output layout and toolchain. Developer/report edits do not change ZIP bytes.
Packaging never modifies application source or version metadata.

## Explicit manifest and validation

The builder reads only the approved `docs/release-manifest.json`; it never adds
extra discovered files or regenerates the manifest. A reviewed source change must
be explicitly reflected by `python3 tests/release-manifest.py --write`, which
prepares metadata only. R3 packaging changes do not require a runtime manifest edit.

`upload/system/library/jetcredit/version.php` is the authoritative module version.
Both payment controllers use that constant; XML and manifest must agree. Current
version remains **2.0.0**. Historical phase reports and formula provenance can
still refer to 1.9.1. R2's internal fresh schema revision 2 is a separate identifier.

The package contains exactly `install.xml` and files beneath `upload/`, with no
project wrapper directory. The current manifest has 89 entries: install.xml plus
88 upload files, including PHPMailer, private libraries, controllers/models,
language aliases, templates, scripts, images and fonts. ZIP directory entries are
implicit. Tests, docs, builder, Git/IDE data, Windows `:Zone.Identifier`, caches,
logs, credentials and database dumps are excluded. Unexpected runtime files,
traversal, duplicate entries, symlinks, missing includes/assets, wrong hashes,
PHP syntax errors or XML/version mismatches cause refusal.

## Future committed build — after separate approval

After an approved real commit/tag contains the reviewed manifest and source:

```bash
bash tools/build-installer.sh release --ref v2.0.0
```

A full 40-character committed SHA is also accepted. Branches/implicit HEAD are not.
The release mode reads immutable Git objects and never borrows dirty files, creates
a commit/tag or checks out source. It writes the same flat `dist/` filenames and
reports **COMMITTED BUILD / NOT PUBLISHED**. A build is not publication or production
approval. The original audit baseline `dd36a97a262fe1cd153bf4783dc9d8c583108a56`
lacks the candidate manifest and is correctly refused as a complete release source;
the development checkpoint contains the reviewed source/manifest without a release tag.

## Collision and interrupted-build policy

**No existing ZIP, checksum or versioned build report is overwritten**, including
byte-identical artifacts and candidate/release collisions. Any one existing
component refuses the entire build. There is no automatic replacement flag.

Before a future final build using the same version/filename, the owner must
explicitly review and safely archive/remove the previous bundle from `dist/`,
including its verification metadata. Preserve its provenance. This checkpoint does
not remove or replace the current verified `dist/` bundle. Then run the separately
approved release build into the empty canonical target names.

An exclusive output lock prevents simultaneous cooperating builders. Temporary
staging uses the output filesystem; exclusive hard links publish each completed
file without replacing a target, even if another process races the lock. A crash
can leave a partial bundle or `.jet-installer.lock`: inspect and reconcile it
before retrying. No automatic stale-lock takeover or partial-artifact cleanup
occurs. An ordinary successful/refused build removes only its own transient lock
and staging files. No build-ID directories remain after success.

## Isolated deterministic verification

Canonical distributable output is always `dist/`. The explicit `--output` override
exists for tests/repeat verification in fresh temporary directories, with the same
flat layout. For example, from the repository root:

```bash
jet_repeat_dir="$(mktemp -d /tmp/jet-oc3-repeat.XXXXXXXX)"
bash tools/build-installer.sh candidate --output "$jet_repeat_dir"
cmp dist/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip "$jet_repeat_dir/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip"
```

ZIP entries are sorted with fixed timestamps/modes/compression. Identical source
and the recorded Python/zlib toolchain produce identical ZIP bytes. Different
zlib versions can compress differently; compare source hashes as well. Reports
may differ in Git status/provenance while their archive bytes match.

## Independent checks and complete regression

In `dist/`:

```bash
sha256sum --check CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.sha256
python3 -m zipfile -t CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip
python3 -m zipfile -l CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip
```

The builder verifies exact entry inventory, CRC and byte equality against source.
Run the full `python3 tests/builder.py` in R3, including the four archive tests that
were deferred in R2. New cases cover flat output, default wrapper/CLI paths, Git
ignore, locks, partial-bundle refusal and candidate/release collisions. Actual Git
object reads use the baseline; the complete release-pipeline test uses a labeled
immutable adapter double without creating commits/tags. `--source-only` remains
available for tasks that explicitly prohibit ZIP generation.

Follow [CLEAN-INSTALL.md](CLEAN-INSTALL.md) and
[PURCHASE-LIFECYCLE.md](PURCHASE-LIFECYCLE.md) for the isolated owner test. Real
MySQL contention, live modification refresh, browser/Twig and contained SMTP E2E
remain owner validation; no external server or database is accessed by the build.
