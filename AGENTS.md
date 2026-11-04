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

## 8. Platform and Runtime Compatibility Policy

### Product compatibility is independent of the test environment

Every module targets an explicitly declared platform family and supported runtime compatibility range.

The current development or test store is only one representative installation.

Never infer the product's supported platform, PHP or database versions from the current workstation, test store, CI environment or agent runtime.

Do not assume that the environment in which code is developed represents the only environment where the product will be installed.

### Platform family support

Support the declared platform family, subject only to explicit, documented and owner-approved exceptions.

For JET-OC3, the declared platform family is OpenCart 3.x.

Do not restrict JET-OC3 to OpenCart 3.0.3.8 or 3.0.3.9 merely because those releases were used for development or testing.

Do not implement exact platform-version whitelists without a proven technical necessity and explicit owner approval.

The platform family declaration does not mean that every version has identical internals. Genuine incompatibilities must be identified and handled safely.

### PHP and database compatibility

The supported PHP and database ranges must be defined from the project's compatibility requirements and relevant platform recommendations.

Do not derive those ranges from locally installed PHP or database versions.

Implement features using constructs and APIs compatible with the project's declared range.

Do not silently raise the minimum supported PHP or database version when introducing new functionality, security fixes or automated tests.

Do not invent compatibility limits when the project has not established them.

A runtime used in automated tests is evidence about that environment, not a universal product requirement.

### PHP Compatibility Baseline

- Develop using syntax and standard APIs compatible with the project's declared minimum PHP version.
- For JET-OC3, use PHP 7.3.33 as the provisional minimum compatibility baseline.
- Do not introduce PHP 7.4+ syntax or APIs without explicit justification and owner approval.
- Avoid known backward-incompatible behavior on newer supported PHP versions.
- Prefer capability checks over exact PHP version checks.
- Do not build separate full test environments for every PHP version.
- Use targeted compatibility checks and existing regression tests.
- Never silently raise the minimum supported PHP version.

The minimum PHP version defines the coding baseline, not the only supported runtime.

### Capability-based compatibility

Prefer validation of actual required interfaces, capabilities, data contracts and security properties.

Do not use exact platform versions, PHP versions, database versions or fixed source fingerprints as substitutes for compatibility when equivalent safety can be established through reliable contract/capability checks.

If exact source verification is genuinely necessary for a security boundary, document the reason and the resulting compatibility limitation.

Do not weaken security validation merely to support more versions.

When safe compatibility cannot be demonstrated, report the issue and request owner approval instead of silently narrowing the product's support range.

### Representative testing

The owner selects representative test stores and development environments.

Do not create separate full testing environments for every supported combination of platform, PHP and database versions by default.

Prefer existing owner-maintained test stores, reusable automated tests and targeted compatibility checks.

Choose testing methods primarily according to total development and testing effort, while preserving required financial integrity, security, privacy and critical regression guarantees.

The owner accepts the residual compatibility risk from combinations not individually tested.

Do not claim that untested combinations have passed testing.

### Compatibility maintenance

When an incompatibility is discovered:

1. Identify the actual incompatible API, behavior or dependency.
2. Determine the smallest safe correction.
3. Preserve compatibility with previously supported environments.
4. Add focused regression coverage where practical.
5. Do not solve the issue by restricting the product to the environment where it was discovered.

### Exceptions and approval

Any new platform or runtime restriction must be technically justified, documented and explicitly approved by the owner.

Never silently narrow the product compatibility contract.

Distinguish compatibility requirements from security support recommendations and end-of-life notices.

### JET-OC3 project requirements

- Platform family: OpenCart 3.x.
- Financing currency: EUR only.
- Language identifiers must not restrict financing.
- Office/Home and test-store runtime versions are representative configurations, not product limits.
- PHP 7.3.33 is the provisional minimum coding baseline. The full supported PHP range and MySQL range require a separate evidence-based decision.
