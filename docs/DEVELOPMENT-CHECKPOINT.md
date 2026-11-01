# Development checkpoint — 2.0.0 TEST / UNRELEASED

This checkpoint contains IMP-01 through IMP-05A-R3, including the accepted R2
purchase-generation and durable checkout-reservation remediation. It is not the
final v2.0.0 release. No release tag, GitHub Release or external installation is
part of the checkpoint. Original audit baseline:
`dd36a97a262fe1cd153bf4783dc9d8c583108a56`.

## Continue at the office

If the repository already exists, first inspect local work before pulling:

```bash
git status --short
git fetch origin
git switch main
git pull --ff-only origin main
git rev-parse HEAD
git status --short
```

Do not discard office changes if the first status is dirty. A normal full clone
also works: `git clone git@github.com:wiley68/jet-oc3.git`. Keep the original audit
history available because provenance tests compare pinned source/fixtures against
that commit; avoid a shallow clone or fetch the missing history before testing.
The narrowly scoped `.gitattributes` whitespace exceptions preserve exact upstream
OpenCart fixture bytes; their SHA-256 provenance remains checked by the test suites.
Owned source, harnesses and documentation retain normal whitespace checks.

Read [CLEAN-INSTALL.md](CLEAN-INSTALL.md),
[PURCHASE-LIFECYCLE.md](PURCHASE-LIFECYCLE.md), and
[BUILD-INSTALLER.md](BUILD-INSTALLER.md). Run the complete offline regression
commands in [tests/README.md](../tests/README.md). Existing PHP CLI, Python 3 and
Node.js are required; WSL can use the existing `node.exe` alternative documented
there. No Composer/npm, Docker or full OpenCart install is needed for these tests.

## Local installer output

`dist/` is deliberately Git-ignored and will not arrive through clone/pull.
The current R3 ZIP is preserved on the original workstation:

```text
dist/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip
SHA-256: 376cfe2c54f2d315b8ca113050709a16f0104cf2c79e5cb7b7d72eb8fe82f47a
```

It was built from the uncommitted R2 source before this development checkpoint;
its original build report is retained unchanged and does not claim the checkpoint
SHA as its build origin. Runtime/manifest bytes remain the reviewed R3 source.
The obsolete `artifacts/` directory has been removed; no runtime/build depends on
it. Old phase reports retain historical paths with an explanatory note.

To obtain a local TEST / UNRELEASED installer at the office, either transfer the
verified ZIP/checksum/report separately, or build a candidate from the clean
checkpoint after the offline tests pass:

```bash
bash tools/build-installer.sh candidate
cd dist
sha256sum --check CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.sha256
```

The builder refuses existing ZIP/checksum/report files. Do not remove the verified
bundle merely to rerun a command. Different Python/zlib versions can change ZIP
compression bytes; verify the new sidecar/report and recorded source hashes.
Use candidate mode for continued test work; do not create a release tag.

## Remaining gates

Actual MySQL contention and full target-store/browser/Twig/modification-refresh/
contained-SMTP E2E remain DEFERRED. Fresh schema revision is 2; this is clean-install
only, with no 1.9.1 or earlier-RC migration. Preserve uncertain order/transport
states; never reset claims, references or private installation data to retry.

Do not start IMP-05B, connect to an external store, install the module, send an
application or publish a release as part of resuming this checkpoint. The next
integration task requires its separate owner instruction and isolated test setup.
