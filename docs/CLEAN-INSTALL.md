# Jet OC3 2.0.0 — R3 TEST / UNRELEASED installation candidate

R3 packages the accepted R2 runtime and fresh schema revision 2. The canonical
installer is `dist/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip`, with its checksum and
versioned build report alongside it. Verify the R3 report before installation.
The earlier IMP-05A ZIP was **SUPERSEDED** and its obsolete `artifacts/` directory
was removed after R3 verification. Use only the current package in `dist/`. See [PURCHASE-LIFECYCLE.md](PURCHASE-LIFECYCLE.md) for the corrected generation
and checkout reservation contract. The instructions below apply to the R3 test
candidate, never the superseded archive.

This is owner-operated test source, not a published release or a production
installation approval. Version metadata was explicitly prepared in IMP-05A and
remains uncommitted. `upload/system/library/jetcredit/version.php` is authoritative;
the XML, manifest and payment controllers must agree. Build and checksum
instructions are in [BUILD-INSTALLER.md](BUILD-INSTALLER.md). Keep the build report
and this guide beside the ZIP; documentation and tests are not inside the installer.

## Prerequisites and limits

Use an isolated external **test store** with a restorable file/database backup,
synthetic customer data and an owner-controlled mail capture service. The agent
has not accessed a store, opened a DB connection or delivered mail.

- Supported source fixtures: **OpenCart 3.0.3.8 or 3.0.5.1**, standard default theme
  and native checkout. Other versions, themes, checkout overrides, event handlers
  and OCMOD combinations require separate validation.
- Local checks used PHP 8.3.6; this does not certify a full OC3 bootstrap on every
  PHP version. Use an owner-validated OpenCart/PHP/database combination. PHP mysqli,
  cryptographic randomness/HMAC and functioning mail transport are required.
- Default store **0** only. Active base currency **EUR, value 1**, and visitor
  session currency **EUR**. Configure the native currency settings before install;
  Jet never converts existing records or changes global currency settings.
- HTTPS is required for personal-data submissions. Both the browser and trusted
  native PHP `HTTPS` server flag must reflect TLS; configure the trusted proxy/web
  server accordingly. Forwarded headers alone are not proof of HTTPS.
- Supported totals are the standard subtotal/shipping/tax/coupon/fees/credit/
  reward/voucher-redemption/total chain. Unknown enabled total extensions, purchased
  gift vouchers, recurring products, file options and unknown option modifiers
  fail closed. No financing formula or bank template was changed in IMP-05A.

**2.0.0 provides no upgrade migration from 1.9.1.** Use a clean Jet installation.
Legacy Jet tables/settings/orders without the protected clean-install marker are
refused. Do not delete business records to bypass this guard. Use a separate clean
test store or obtain a separately reviewed migration.

## Upload, install and configure

1. Verify the ZIP SHA-256 against its sidecar and build report. The filename must
   be `CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip`, with `install.xml` and `upload/`
   at its root. Check the report says **TEST / UNRELEASED**.
2. Back up test-store files, database and existing modification state. In native
   **Extensions → Installer**, upload the `.ocmod.zip`. Do not copy modified core
   files manually. Confirm the installer completes and all manifest assets exist.
3. In **Extensions → Modifications**, refresh modifications. Refresh native theme/
   template caches. Inspect modification logs: all ten operations across seven
   explicit file blocks must apply. Confirm both native mail guards and the native
   checkout integration exist in the active modification cache. Missing or altered
   anchors are incompatible; do not edit guards out to make submissions work.
4. In **Extensions → Extensions → Modules**, install and edit the Jet module using
   a logged-in default-store administrator with access/modify permissions. A clean
   install creates schema revision 2, including InnoDB `jet_submission`, the promotion table and one counter/
   secret/marker row. Defaults are disabled; recipient is explicitly empty (manual).
   A partial installation is refused without silently resetting or deleting it.
5. Set the test merchant identifier, EUR minimum/terms/rates and desired display
   settings. Save recipient mode explicitly: **empty string = manual**; a validated
   comma-separated list = automatic. Missing, whitespace-only or malformed values
   are invalid. Manual never falls back to shop email and consumes no bank reference.
6. In the Payments list, install/configure **both Jet and Jet Card**. Select an
   existing valid nonzero order-status ID for each method, with appropriate minimum,
   geo zone and sort order. Also configure a valid native default order status.
   These are shop registration statuses; transport acceptance is not loan approval.
7. Configure the controlled test mail environment below. Enable the needed module
   financing flags and both payment methods only on the isolated test store. Verify
   product/cart buttons and payment eligibility in EUR. The checkout method's own
   flag and the corresponding module financing flag must both be enabled.

The private key is generated once by clean installation (or taken from a valid
pre-provisioned `JETCREDIT_OFFER_SECRET`) and persisted in the protected counter
row. An optional constant must match that row. Never expose it in a build report,
JavaScript, screenshots or ordinary settings POST. Reactivation validates existing
records without resetting claims, reference sequence, promotions or secret.

## Safe test email setup — owner only

Before enabling automatic mode, configure the native OpenCart SMTP settings to an
**owner-controlled capture-only SMTP sink** with external forwarding/relay disabled.
Use only the sink's test transport credentials in server configuration. Restrict
outbound SMTP/network delivery at the test environment boundary. Jet uses the native
SMTP hostname/port/auth settings; its three mail categories use separate messages.

Use exclusively owner-controlled test bank recipients, store/admin address and
synthetic customer addresses. No bank production address, real customer identity or
real financing application may be used. Start in manual mode, verify zero bank
messages, then test automatic mode against the sink. Manual mode can still produce
safe admin/customer notifications: it is not a global email-disable switch.

Verify the sink receives one bank-format message and separate safe admin/customer
notifications; bank recipients must not appear in the other messages. Observe
native non-Jet customer/merchant email as a control. Transport acceptance means
only SMTP acceptance, not bank approval or guaranteed final delivery. If containment
cannot be guaranteed, keep automatic tests deferred. The agent sends no test mail.

## Owner E2E matrix

First run [MYSQL-TEST.md](MYSQL-TEST.md) on a separate approved empty local schema;
do not run that destructive fixture harness against the store database. Then record
OpenCart/PHP/DB/theme/OCMOD versions and execute the following on the test store.

| Scenario | Expected evidence |
| --- | --- |
| Product, Cart, Jet checkout, Jet Card checkout; both schemes and recipient modes | Correct EUR offer from server; one registration/order; correct configured status; owned success receipt; manual consumes no reference; automatic has one unique reference and sink-only bank message |
| Options, quantity discounts, special, tax, coupon, shipping and standard credits | Amounts match native products/options/totals; thresholds/terms/down payment follow existing OC3 rules; bank golden format unchanged |
| Browser price/rate tampering, expired token, stale cart/order, wrong currency, invalid identity/consent or CSRF | Controlled refusal before claim/order/mail/counter side effects |
| Double click, refresh, two tabs and simultaneous same intent | One owner run; same completed result on replay; no duplicate bank attempt or native draft creation from refresh |
| Explicit second purchase in the same session, including same product; old forms/receipts; duplicate new-purchase clicks | One persisted successor and fresh offer/order; historical outcome retained; old offers cannot execute as the new identity |
| Bound checkout snapshot/method change, concurrent confirms and crash boundaries | Refusal/held reservation, no second addOrder or binding; unchanged valid draft reused; only completed prior applications can advance |
| Competing distinct intents in separate sessions | Unique, increasing bank references; no lost counter updates |
| Native non-Jet checkout and ordinary mail; Jet finalization | Non-Jet behavior retained; only exact Jet-owned finalization suppresses native customer/merchant mail; no voucher purchase bypass |
| Controlled preflight failure, interruption after reserve, SMTP uncertainty, history failure, partial native order | Durable review-required state; no automatic resend, reference reuse, takeover or false success; owner reconciles recorded order/reference with sink |
| Changed/new cart rows in another tab; direct product flow | Only matching purchased rows removed; new/changed rows and unrelated order session survive; product flow leaves cart alone |
| Success GET, copied/expired/wrong-session receipt | Owned receipt reads only; no native success cart clearing; invalid receipt denied |
| Disable/uninstall/reactivate on the test store | Business records, private marker/secret and counter preserved; no reset or automatic replay |

A completed quote displays its previous result and an explicit **“Започни нова
покупка на изплащане”** action. Only that HTTPS/CSRF-protected POST can advance to
a new durable purchase generation. Refresh/cart changes do not renew identity.
Old forms/offers/receipts stay attached to the original application; historical
manual outcomes never become automatic. A new purchase receives a fresh validated
offer and, at checkout, a new reserved native draft. Before claim, changing an
already bound draft's financial snapshot or payment method is refused; restore
the original snapshot/method or obtain owner review. No automatic second addOrder,
takeover, deletion or reset is permitted. See the lifecycle guide for crash cases.

The earlier unreleased schema revision 1 is incompatible with this clean-install
source. Use a separate clean test store/snapshot for revision 2; no upgrade migration
or ad-hoc schema ALTER is provided. Never delete business evidence to make install
pass. Native uninstall continues to preserve all claims, generations, drafts,
numbering and private installation data.

## HTTP 503 and rollback

For HTTP 503, check HTTPS/PHP flag, exact supported native OC3 version, refreshed
modification cache and actual mail/checkout capability markers, all runtime files,
clean-install marker/key consistency, InnoDB/unique indexes/counter seed and valid
settings. Missing capabilities must stay closed. Invalid offer/input may instead
produce 400; uncertain existing submissions produce review-required/409. Do not
print personal payloads, keys or raw SMTP errors while troubleshooting.

On failed test installation, disable Jet financing/payment flags first. Preserve
claim/order/reference evidence and reconcile uncertain delivery before any retry.
Take a diagnostic backup, then use native Installer/Modifications to disable/remove
the candidate modification and refresh modification/theme caches. Native module
uninstall preserves business data; it is not a purge. Restore the **entire isolated
test snapshot** if a fresh retry is needed; do not issue ad-hoc DROP/reset commands
or overwrite 1.9.1 records. Verify ordinary non-Jet checkout/mail after rollback.

Real MySQL contention, full browser/Twig/cache-refresh E2E, controlled SMTP behavior
and target-store conflicts remain owner validation gates before any final release.
