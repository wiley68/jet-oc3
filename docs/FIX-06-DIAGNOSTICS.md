# FIX-06 — TEST diagnostics

Diagnostics are disabled by default. To opt in on the owner-operated test store,
set `define('JETCREDIT_DIAGNOSTICS', true);` in the store's existing PHP config
before OpenCart boot. Set it to `false` or remove it to disable. A defined constant
takes precedence over the optional `module_mt_jet_credit_diagnostics` configuration
key (`1` enables it). No admin settings or installation defaults are changed.

Failures write one `JET_DIAG {json}` record per operation using the OpenCart
registry's `log` service, normally the configured file under `DIR_LOGS`.
The corresponding error JSON includes only an additional `correlation_id`;
existing HTTP status and public error code are preserved. No JavaScript change or
payload capture is needed. Successful operations normally produce no record.
Logger failure is swallowed and does not alter the operation.

For an existing failure, match its Network response correlation ID to that one
log entry. Do not export HAR files or request payloads. A quote integer error
includes bounded `field` and `category` (missing, empty, zero, wrong_type,
invalid_format, out_of_range). Null is categorized as missing. Paths outside the
known application/system roots are reported as `external`; custom exception
classes are grouped as `other_throwable`. No exception message or trace is logged.

`claim_reached` means execution reached the acquisition boundary;
`claim_confirmed` means this owner acquired the claim; `order_creation_began`
means the native create call was about to start. False flags do not prove absence
of a pre-existing claim/order, and true flags do not prove a successful side
effect. Diagnostics never acquire claims, reserve references or retry operations.

Test-store procedure after a separately authorized TEST build/install:

1. Check cart and checkout calculations for both schemes, including a native
   product with minimum zero and valid quantity. Do not submit an application
   merely to collect a calculator log.
2. If a quote fails, record route, HTTP status, public code and correlation ID;
   use the matching field/category to identify the rejected source.
3. For the unresolved Product submission, first reconcile the previous attempt's
   existing records. A new owner-authorized test attempt must be deliberate,
   using test data and the owner-controlled recipient. Do not blindly repeat it.
4. Read the matching entry: `order_status_readiness` plus `invalid_order_status`
   differs from `order_preparation` plus `unexpected_exception`. Do not change
   valid status settings based only on the generic browser message.
5. Disable diagnostics after the test. Never share customer data, credentials,
   signed offers, HMAC/session material, SQL or email bodies.

Validation: `php tests/fix06.php` uses in-memory OC3/SQL/mail doubles only.
Run the established suites in `tests/README.md` and use builder `--source-only`;
FIX-06 does not authorize an installer ZIP, external access or real transport.

## FIX-07 — native checkout/confirm and draft reservation

The same `JETCREDIT_DIAGNOSTICS` flag now covers the `checkout_confirm`
operation. The OCMOD replacement initializes diagnostics before loading the Jet
checkout model. Exceptions preserve HTTP 503 and the exact public body
`Финансирането временно не е достъпно.`. Only a non-sensitive
`X-Jet-Correlation-ID` response header is added when diagnostics capture a failure.
No exception details are added to the page. Successful requests remain silent.

The capability guard checks the updated executable replacement body, including
its instrumentation; incomplete/old guard combinations fail closed. After a
separately authorized new TEST package installation, use the normal OpenCart
Modifications refresh so that the updated XML and runtime guard are applied
together. FIX-07 itself does not authorize a ZIP or a remote installation.

Checkout stages: model_loading, runtime, context, financing_readiness, storage,
authorization, claim_lookup, draft_reservation, draft_reuse,
native_order_creation, persisted_verification, draft_binding, return.

Checkout-only flags:

- `reservation_reached`: the reserveDraft call boundary was reached.
- `reservation_confirmed`: this run received confirmation that it owns the
  durable reservation. False does not prove no INSERT happened: a write or its
  subsequent read may have an ambiguous outcome. Reusing another run's bound
  draft leaves this flag false.
- `native_order_creation_started`: execution reached the native addOrder call;
  it does not prove that an order was created successfully.
- `draft_binding_confirmed`: bindDraft returned successfully, or an existing
  bound draft and its persisted digest were verified successfully. A failure
  after a binding write leaves this false because the caller lacks confirmation.

Owner procedure:

1. Reconcile any earlier attempt before deliberately opening checkout again.
   Native GET checkout/confirm can reserve a draft and create an order even
   without an application submission; do not blindly refresh it for diagnostics.
2. Enable the existing PHP constant in the store config before bootstrap.
3. Identify the failed GET `index.php?route=checkout/confirm` in DevTools Network.
   Record its HTTP status and `X-Jet-Correlation-ID` response header only.
4. Find the same correlation ID in the configured OpenCart error log's JET_DIAG
   entry with `operation=checkout_confirm`. Share only stage, allowlisted code,
   bounded exception class and the four lifecycle booleans. Use the correlation
   ID locally for lookup; do not share request/session/order/customer data.
5. Set `define('JETCREDIT_DIAGNOSTICS', false);` afterward. A false constant
   overrides the optional config key.

If the header is present but no matching entry can be found, capture ran but the
logger may have failed, been unavailable, or written to another configured log.
Logging failure cannot change the HTTP outcome. No header can mean diagnostics
were disabled, correlation initialization failed, diagnostics.php could not be
loaded, the updated OCMOD was not applied, or execution terminated before capture
(e.g. a non-catchable resource failure). The absence of a header alone cannot
identify which case occurred or establish that no draft/order exists. Inspect
only configuration, applied modification state and existing server logs first;
do not enable public PHP error display, dump exception messages or reset drafts.

No environment variable, web-server setting or PHP-FPM environment propagation
is required. As for FIX-06, the host must execute the updated PHP config/source
rather than stale cached files. Read logs through the existing admin/hosting
access; SSH is not required by this procedure.
