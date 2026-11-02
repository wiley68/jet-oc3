> Local continuation: IMP-05B-FIX-01 changes are uncommitted on top of
> 2cc75057219f3c76a95b4107e9f78f44825daedc. The old ZIP is superseded.
> Do not run the historical build instructions until owner review authorizes a
> replacement. See [current report](../tests/IMP-05B-FIX-01.md).

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

`dist/` is Git-ignored and does not arrive through clone/pull. It contains only
`CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip` and its `.sha256` sidecar. Generated build
reports and `dist-history/` were removed at the owner's request; the builder does
not recreate them. Historical phase reports describe their earlier checkpoints.

Transfer the verified ZIP/checksum pair separately or, when a new build is
requested and the offline tests pass, run:

```bash
bash tools/build-installer.sh candidate
cd dist
sha256sum --check CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.sha256
```

The builder refuses an existing ZIP or checksum and does not silently replace or
archive them. A replacement needs explicit removal of the prior pair. Compare ZIP
bytes in temporary directories for repeated verification. Different Python/zlib
versions can change compression bytes. See [BUILD-INSTALLER.md](BUILD-INSTALLER.md).
Current local source can contain UI changes made after the existing ZIP; cleaning
outputs does not rebuild it. Candidate mode prints TEST / UNRELEASED to the terminal.

## Remaining gates

Actual MySQL contention and full target-store/browser/Twig/modification-refresh/
contained-SMTP E2E remain DEFERRED. Fresh schema revision is 2; this is clean-install
only, with no 1.9.1 or earlier-RC migration. Preserve uncertain order/transport
states; never reset claims, references or private installation data to retry.

Do not start IMP-05B, connect to an external store, install the module, send an
application or publish a release as part of resuming this checkpoint. The next
integration task requires its separate owner instruction and isolated test setup.
