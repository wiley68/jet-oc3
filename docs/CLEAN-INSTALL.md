> IMP-05B-FIX-01 supersedes the previous runtime and installer. Do not use the
> existing dist/ ZIP to test these fixes. No replacement ZIP was built. See
> [implementation report](../tests/IMP-05B-FIX-01.md) before owner review/build.

# Jet OC3 2.0.0 — TEST / UNRELEASED installation

IMP-05B-FIX-01 updates the local source while retaining fresh schema revision 2.
The existing `dist/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip` predates these changes
and is superseded at that historical checkpoint. Current packaging retains only
the ZIP and its checksum; generated reports/history are no longer kept.
A replacement installer requires owner review and a separately authorized build.
The installation procedure below describes that future reviewed package.
See [PURCHASE-LIFECYCLE.md](PURCHASE-LIFECYCLE.md) for generation and reservation rules.

This is owner-operated test source, not a published release or a production
installation approval. Version metadata was explicitly prepared in IMP-05A and
remains 2.0.0; the current fixes are uncommitted. `upload/system/library/jetcredit/version.php` is authoritative;
the XML, manifest and payment controllers must agree. Build and checksum
instructions are in [BUILD-INSTALLER.md](BUILD-INSTALLER.md). The builder writes
only ZIP/checksum and prints TEST / UNRELEASED to the terminal; documentation and
tests are not inside the installer.

## Prerequisites and limits

Use an isolated external **test store** with a restorable file/database backup,
synthetic customer data and an owner-controlled mail capture service. The agent
has not accessed a store, opened a DB connection or delivered mail.

- Target series: **OpenCart 3.x**, one installer. Runtime checks executable native
  mail/checkout capabilities, without a patch-version allowlist. Regression source
  samples are 3.0.3.8 and 3.0.5.1; the actual 3.0.3.9 store, custom themes, checkout
  overrides, events and OCMOD combinations have not been certified.
- Local checks used PHP 8.3.6; this does not certify a full OC3 bootstrap on every
  PHP version. Use an owner-validated OpenCart/PHP/database combination. PHP mysqli,
  cryptographic randomness/HMAC and functioning mail transport are required.
- Default store **0** only. EUR must be installed and active with a valid positive
  rate; visitor session currency must be EUR for financing. The base currency may
  differ. Jet uses native currency conversion for financing, preserves native base
  amounts in orders and does not change global settings/rates. The owner test rates
  of 1 are accepted unchanged. Native payment total settings remain base-currency
  availability floors; module/promotion financing thresholds are EUR.
- HTTPS is required for personal-data submissions. Both the browser and trusted
  native PHP `HTTPS` server flag must reflect TLS; configure the trusted proxy/web
  server accordingly. Forwarded headers alone are not proof of HTTPS.
- Supported totals are the standard subtotal/shipping/tax/coupon/fees/credit/
  reward/voucher-redemption/total chain. Unknown enabled total extensions, purchased
  gift vouchers, recurring products, file options and unknown option modifiers
  fail closed. No financing formula or bank template was changed in IMP-05A.

**2.0.0 provides no upgrade migration from 1.9.1.** Use a clean Jet installation.
Historical Jet orders alone are preserved and do not block installation. Conflicting
legacy financial settings/promotions and partial new schemas require owner review.
Do not delete business records to bypass a diagnostic; no automatic migration occurs.

## Upload, install and configure

1. Verify the ZIP SHA-256 against its sidecar. The filename must
   be `CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip`, with `install.xml` and `upload/`
   at its root. Use the reviewed candidate build, labeled **TEST / UNRELEASED**
   in the builder terminal output.
2. Back up test-store files, database and existing modification state. In native
   **Extensions → Installer**, upload the `.ocmod.zip`. Do not copy modified core
   files manually. Confirm the installer completes and all manifest assets exist.
3. In **Extensions → Modifications**, refresh modifications. Refresh native theme/
   template caches. Inspect modification logs: all eleven operations across eight
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

## Installation state classification after IMP-05B-FIX-01

Historical Jet orders alone are allowed and preserved (`installed_historical_only`).
Extension/permission registrations do not imply a conflicting financial state.
A disabled-status-only setting residue is harmless; obsolete financial settings,
any pre-existing promotions table without a valid new installation, and identifiable
old/unrecognized `cc_jet` OCMOD require owner review. No deletion or 1.9.1 migration
is automatic. Missing new submission storage is normal before fresh install.
Partial schemas, wrong revision, incomplete seed, invalid secret and missing unique
constraints block reactivation. Never reset secrets/counters to retry.

The install hook returns a status object, not HTML or a redirect. The installer
OCMOD checks that result before native success rendering. Apply the reviewed
modification through the normal owner-operated process when a new installer is
approved; old cached modifications cannot provide the new admin status handling.
Opening module settings explicitly keeps normal header/column/footer composition.

## Monetary snapshots

Product pricing (special/discount/options/tax) and the native cart total chain run
in base units. Their final authoritative amounts cross the native currency API
boundary once to EUR. Native order products/taxes/totals stay in base units with
EUR metadata and the actual EUR currency value. Bank product prices and financing
calculations are EUR; banking template bytes/formulas are unchanged.

Checkout compares persisted order and cart in base units and requires recorded
EUR currency_value to match the current currency context. A rate/currency change
invalidates the old quote/draft context; no reserved order is silently rewritten.
Use the existing explicit new-purchase lifecycle where applicable. Completed
claims remain authorized historical replays, without another order/mail/reference.
Conversion context (base/session codes, rates, EUR ID/status) is included in the
source digest; customer identity is not added to signed offer payloads.
