# JET-OC3: Codex Project Instructions

## 1. Project scope

- Repository: `wiley68/jet-oc3`.
- Product: JetCredit financing module for OpenCart 3.x.
- Preserve approved business logic, financial formulas, customer experience, visual design and integrations.
- Implement only explicitly authorized changes.
- Report unrelated findings without modifying them.

## 2. Development environments

The project is developed on two independent workstations: Office and Home.
Source code is synchronized through Git. Generated installation artifacts are
workstation-local and are not synchronized through Git.

Therefore:

- Local installers may represent older commits.
- A local ZIP/HEAD mismatch is expected after switching workstations.
- Such a mismatch is not automatically a defect or NO-GO.
- Do not rebuild installers merely because the workstation has changed.
- Do not infer missing accepted source changes from an outdated local ZIP.
- Report the actual mismatch accurately.

## 3. Installer policy

For approved implementation tasks requiring packaging, generate both the OpenCart
OCMOD ZIP installer for the currently approved version and its matching `.sha256`
file. These are inseparable deliverables.

Derive artifact filenames from the approved project version and the established
`CC_OpenCart.3.x_JET_v.<version>.ocmod.zip` naming convention, with `.sha256`
appended for the checksum file. Do not hardcode a permanent module version into
this policy.

- Keep exactly these two generated artifacts in `dist/`.
- Do not keep archived installers, backups or build history.
- Verify ZIP CRC, manifest, packaged source byte equality, deterministic rebuilding and SHA-256.
- Do not package tests.
- Do not generate or replace artifacts during ReadOnly audits.
- Do not automatically rebuild installers at session startup.

## 4. Change management

- ReadOnly prohibits changes to source, tests, configuration, documentation and artifacts.
- Keep modifications within the explicitly authorized scope.
- Preserve previous accepted remediations.
- Do not make unrelated fixes or refactorings.
- STOP when the task requires unapproved production changes.
- Do not weaken financial or security validation merely to make a test pass.

## 5. Tests and owner acceptance

- Prefer isolated local tests, mocks and doubles.
- Do not create real financing applications, production orders or bank emails during automated testing.
- Run relevant security and regression suites.
- Clearly distinguish automated test PASS from owner functional/visual E2E PASS.
- Owner acceptance is required before finalizing approved implementations in Git.

## 6. Git policy

- Do not commit, push, tag, bump the version or rewrite history without explicit owner authorization.
- Preserve unrelated working-tree changes.
- Source code in Git is the shared development baseline.
- Generated installers remain local to each workstation.

## 7. Protected application behavior

Preserve unless separately authorized:

- EUR-only financing.
- Jet and Jet Card flows.
- Product, Cart and Checkout distinctions.
- Financial formulas, rounding and promotions.
- Existing order and email output structures.
- Independent bank, administrator and customer notifications.
- Personal-data masking.
- Loaders, debounce, popups and customer-facing wording.
- Native OpenCart product option behavior.

Do not change native OpenCart behavior to simplify JetCredit implementation.
