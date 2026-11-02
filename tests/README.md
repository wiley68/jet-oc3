> FIX-07: `python3 tests/ocmod.py` also executes `fix07-native.php` through the
> actual modified native confirm fixtures; no ZIP is authorized in this task.

> FIX-06: run `php tests/fix06.php` for minimum parity and privacy-safe diagnostic
> regression checks. See [diagnostic procedure](../docs/FIX-06-DIAGNOSTICS.md).
> Use builder `--source-only`; this task does not authorize an installer ZIP.

> Current installer output: only the ZIP and its `.sha256` in flat `dist/`.
> No generated build/verification reports or `dist-history/` are retained.
> Run `python3 tests/builder.py` to verify the two-file output and safety checks;
> tests build in temporary directories. Historical phase notes below retain their
> original scope. See [builder guide](../docs/BUILD-INSTALLER.md).

> IMP-05B-FIX-05 adds 400 ms down-payment debounce, stale-offer safeguards,
> empty-to-zero editing and scoped Step 1/Step 2 styles. Run the same full local
> regression set with `python3 tests/popup-contracts.py` and
> `node.exe < tests/security-ui.cjs` (or native `node`). No ZIP is authorized;
> use `python3 tests/builder.py --source-only`. See [FIX-05 report](IMP-05B-FIX-05.md).

> IMP-05B-FIX-04 adds `python3 tests/popup-contracts.py` and `php tests/prefill.php`.
> Shared UI behavior is covered by `node.exe < tests/security-ui.cjs` (or native
> `node`). Jet-Woo reference provenance is pinned in
> `fixtures/jet-woo-ui-reference.json`; see [FIX-04 report](IMP-05B-FIX-04.md).
> Use `builder.py --source-only`; no new installer is authorized in FIX-04.

> IMP-05B-FIX-02 adds `python3 tests/admin-tabs.py` and
> `php tests/admin-settings.php`. No replacement ZIP is authorized in FIX-02;
> use builder `--source-only`. See [FIX-02 report](IMP-05B-FIX-02.md).

> Current continuation: [IMP-05B-FIX-01](IMP-05B-FIX-01.md). Add
> `php tests/currency.php` to regression. Use `python3 tests/builder.py --source-only`
> in place of the archive-producing command below until a rebuild is authorized.
> `ocmod.py` also runs native admin-install probes. Unknown 3.x labels on existing
> fixtures test capability policy, not actual source/integration certification.

# IMP-01 / IMP-02 / IMP-03 / IMP-04 / IMP-05A regression

Run from the repository root with existing PHP CLI, Python 3 and Node.js:

```bash
php tests/security.php
php tests/financial.php
php tests/submission.php
php tests/install.php
php tests/purchase.php
python3 tests/escaping.py
python3 tests/submission-contracts.py
python3 tests/ocmod.py
python3 tests/compatibility.py
python3 tests/builder.py
bash -n tools/build-installer.sh
node < tests/security-ui.cjs
git diff --check
```

In this WSL environment native Node is absent. Existing Windows Node can run the
same dependency-free suite with `node.exe < tests/security-ui.cjs` when WSL interop
is permitted. `JET_OC3_ROOT` optionally selects the repository path seen by Node.
No npm, Composer, OpenCart installation, network, real DB or mail is required.

- `security.php`: actual IMP-01 admin guards, validation, SQL and view preparation
  with authentication/DB doubles; synthetic SQL mutations affect PHP arrays only.
  Promotion decimal values now remain strings until the strict financial parser.
- `financial.php` / `financial-bootstrap.php`: actual financial core, HMAC library,
  pricing adapter, controllers and payment models with isolated OC3 doubles.
  Fixtures in `fixtures/financial-oc3.json` were captured from the original OC3
  1.9.1 formula before replacement; they are independent of WooCommerce.
- `escaping.py`: explicit Twig output contracts, hostile PHP HTML/JSON encoding,
  parsed markup, OCMOD XML and all three injected PHP fragments. Protected IMP-01
  admin mutations/model hashes are recorded in `fixtures/imp01-protected.json`.
  Phase-specific financial/mail byte-equality expectations have been replaced by
  checks for the authorized IMP-02 behavior; install/uninstall remain protected.
- `security-ui.cjs`: actual delegated admin and unified product/cart/checkout
  quote handlers, safe diagnostics, selected options, expiry, stale replies and
  unconditional UI submission gates, using DOM/jQuery/AJAX doubles.

IMP-01 coverage remains for JET-001/002 admin-only promotion mutations, JET-006
escaping and safe option/error rendering, and JET-014 absence of raw HTTP/PII
output. Legacy checkout retry/submission callbacks were removed in IMP-02 and
replaced by quote retry plus fail-closed submission; tests reflect that change.

Full Twig compilation, real browsers/themes, native MySQL and live OC3 routing
remain integration checks. No dependencies were installed to simulate them.
Sequential promotion duplicate checks still lack database concurrency protection.
See [IMP-02 report](IMP-02.md) for configuration, boundaries and deferred work.

IMP-03 adds `submission.php` / `submission-bootstrap.php`: actual model/service,
SQL repository and order adapter with in-memory statement/OC3/transport doubles.
They cover both schemes in product/cart/checkout, automatic/manual recipients,
consents/identity, verified offers, authorized replay, competing claims, reference
reservation/rollback/uncertain commit, partial MyISAM orders, mail/history/cleanup
failures and independent notification states. The real bundled PHPMailer is also
exercised through `preSend()` only; its real `postSend()` is never invoked.

`submission-contracts.py` pins financial/bank golden fixture bytes, checks bank
fragment hashes against the original Git baseline, validates SQL/privacy/gate
contracts, and lints all 50 runtime/test/fixture PHP files. The native mail-index
fixture is an unmodified cached OC3 3.0.3.8 method in a test-only trait.

IMP-04 conditionally activates all four public HTTP submission paths after clean
installation, EUR configuration, the pinned native mail/checkout modification,
and valid module/payment settings. Missing capabilities or an insecure HTTP submission context still return HTTP 503.
`SScope` / `SSubmission` remain isolated test doubles, not activation flags.

- `install.php` exercises actual clean-install definitions, secret/counter/marker
  initialization, legacy detection, interrupted setup, reactivation, protected
  settings and preserving uninstall against SQL doubles.
- `ocmod.py` applies the exact XML to SHA-pinned official OC3 3.0.3.8 and 3.0.5.1
  source snapshots using their verified line-search/ignoreif semantics. It checks
  every anchor, operation error scope, idempotence, missing-anchor abort, snippets
  and full modified PHP syntax. It then executes `native-integration.php` against
  the applied native controllers: original index/alert callbacks, actual checkout
  confirm/order construction, native Request::clean, public Jet controllers, replay
  and safe success. Native options use nested form data; legacy JSON is decoded at
  its native request boundary, and order text preserves safe native HTML encoding.
  DB/order storage and SMTP remain doubles. `native-unavailable.php` verifies 503
  on incomplete guards and unsupported builds. The full live modification-cache
  refresh and browser/Twig rendering remain store integration checks.
- `compatibility.py` checks owned runtime includes, catalog/admin language assets,
  escaping, deprecated sanitizers and the exact release source manifest.
- `release-manifest.py --write` regenerates a reviewable list of allowed sources
  and hashes; without the flag it only checks. It never creates a ZIP.
- `mysql-concurrency.php` defaults to DEFERRED without connecting anywhere. The
  owner-only executable test and disposable DB boundary are described in
  `docs/MYSQL-TEST.md`. Actual MySQL concurrency is not implied by mock results.

Bank/financial golden bytes remain pinned. A phase-specific assertion that all
HTTP routes were unconditionally gated was replaced by real controller/native
fixture activation tests plus negative readiness tests. Checkout intent now spans
regenerated draft order IDs; the old test treating server-side remapping as an
unauthorized customer change was replaced by native refresh/gateway-switch tests.
Session/customer/intent replay isolation remains covered.

IMP-05A explicitly prepares uncommitted 2.0.0 metadata and a TEST / UNRELEASED
installer, with one authoritative runtime version constant. Historical phase
reports describe their own earlier checkpoints. `builder.py` tests exact manifest
coverage, paths/roots/hashes/version, deterministic ZIP bytes, source preservation,
overwrite refusal, PHP/XML validation and committed-source isolation. Its full
release-pipeline test uses a labeled immutable Git adapter double; no commit/tag
is created. Actual Git-object reads use the existing baseline independently.

The expanded owner-only MySQL harness covers actual fresh installation, concurrent
claims/references, rollback, committed attempt persistence, blocked replay, manual
registration without increment and preserving reactivation, with real SQL storage
but non-delivering order/mail seams. It remains DEFERRED without an approved DB.

See [IMP-04 report](IMP-04.md), [IMP-05A report](IMP-05A.md),
[clean-install guide](../docs/CLEAN-INSTALL.md),
[builder guide](../docs/BUILD-INSTALLER.md) and
[exact release manifest](../docs/release-manifest.json). No final publication,
database connection, real mail, external store execution, commit or tag occurred.

R2 corrects the purchase lifecycle: see [PURCHASE-LIFECYCLE.md](../docs/PURCHASE-LIFECYCLE.md).
`purchase.php` covers explicit generations, historical receipts/replay, owner
isolation, transition overlap/rollback/uncertain commit, native draft reservation,
validated reuse and crash holds through actual source with SQL/order/mail doubles.
`security-ui.cjs` covers actual new-purchase/delayed-response/retry callbacks. The
old native assertion permitting a second draft on gateway change now requires a
controlled refusal with the same identity and no second order. Existing submission
claim guards and golden fixtures are unchanged.

R2 forbids all ZIP generation: `builder.py --source-only` runs source validation
and skips four archive-producing tests. Run the complete builder suite only in the
next approved build task. `tools/build-installer.py` itself was not modified. The
IMP-05A archive/report were historical, superseded artifacts and were subsequently
removed at the owner's request after R3 verification; phase source reports remain. Module
version stays 2.0.0; fresh schema revision is 2 and no migration is provided.

R3 authorizes the full builder suite, including ZIP-generation tests. Canonical
outputs are directly in `dist/` for candidate and release modes, Git-ignored and
protected against any existing bundle component/candidate-release collision.
Run `bash tools/build-installer.sh candidate` for the current uncommitted source;
use explicit `--output` only for isolated temporary verification. See
[BUILD-INSTALLER.md](../docs/BUILD-INSTALLER.md) for the two-file output policy and future
committed-source mode. Historical IMP-05A/R2 reports describe their own checkpoints.

Development checkpoint: use [DEVELOPMENT-CHECKPOINT.md](../docs/DEVELOPMENT-CHECKPOINT.md)
for the next work session. Legacy artifact paths in old phase reports record their
original checkpoints; current output is only `dist/`. The immutable audit baseline
is pinned in provenance/preservation tests so committing the new source does not
change what those tests compare. Generated packages/checksums and Windows download
metadata are excluded from version control.
