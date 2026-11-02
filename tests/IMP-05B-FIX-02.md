# IMP-05B-FIX-02 — Admin Tabs и Settings Save

## A. Baseline и обхват

HEAD `2cc75057219f3c76a95b4107e9f78f44825daedc`, branch `main`, версия
`2.0.0 TEST / UNRELEASED`. Начало: 26 modified tracked + 3 untracked, 0 staged.
FIX-01 е запазен. Краен статус: 28 modified tracked + 6 untracked, 0 staged.

Тази задача променя:

- `upload/admin/view/template/extension/module/mt_jet_credit.twig`
- `upload/admin/controller/extension/module/mt_jet_credit.php`
- `upload/system/library/jetcredit/admin_settings.php`
- `docs/release-manifest.json` — hashes/sizes, непроменени 89 runtime entries
- `tests/README.md`
- Нови `tests/admin-tabs.py`, `tests/admin-settings.php` и този отчет.

Няма реален DB достъп/промени, външен магазин, реални emails, bank applications,
нов ZIP, version bump, commit/push/tag. SQL и persistence в тестовете са doubles.
Owner evidence за предходните installation/layout/missing-table проблеми е PASS;
това е предоставено от собственика, не независимо проверен live store резултат.

## B. Потвърдена причина за E2E-F06

В предишния Twig, непосредствено преди втория pane, има един излишен `</div>`
(ред 333 преди FIX-02). При премахването на legacy currency-mode group е останало
допълнително затваряне. То присъства в HEAD и не е въведено от FIX-01: Twig не е
променян в FIX-01. 1.9.1 е имал currency group с необходимите собствени closures.

Първоначалната структура е `.tab-content > form > .tab-pane`, но излишният край
изважда останалите panes от тази структура при DOM repair. Локален DOMDocument/
XPath probe върху неутрализирани Twig expressions установи:

| Pane | ancestor form преди поправката |
|---|---:|
| Управление | 1 |
| Функционални настройки | 0 |
| Визуални настройки | 0 |
| Лихвени филтри | 0 |

Custom CSS е скривал само `.tab-content > form > .tab-pane`, затова извадените
panes не се управляват от тези visibility selectors. Nav target IDs са правилни;
причината не е нужда от Bootstrap 4 `show` classes или нов tab plugin.

## C. Минимална markup поправка

Премахнат е излишният closing div. Form обгръща tab-content, а четирите tab-pane
елемента са негови директни деца — стандартен Bootstrap 3 contract. Премахнати са
само obsolete display overrides за стария wrapper. Запазени са четирите имена,
IDs, control groups, styles и native `data-toggle="tab"`/`.tab('show')` поведение.
Само първият nav item/pane е active при първоначален render.

Всички named controls и promotion inputs остават във form-html. Header/sidebar/
footer са включени веднъж. Session storage за запомнен таб е optional и отказът
му не прекъсва admin JS. При invalid задължителен shop ID се отваря неговият таб,
за да може браузърът да покаже/фокусира полето. Няма redesign.

## D. Потвърдена възпроизводима причина за E2E-F07

Предишният DOM е оставял банковия email извън формата: XPath за named recipient
control във form връща 0. Нормален submit на такава структура пропуска functional
fields, включително mail и shop ID. Точният първи отказ в
`JetCreditAdminSettings::module()` е `array_key_exists` за липсващ
`module_mt_jet_credit_mail`, с `invalid_recipient_configuration`.

Това е различно от подаден празен низ: `recipients('')` вече е валиден manual mode.
Няма изходно доказателство, че празен email е бил невалиден. Предишният controller
е заменял всички причини с едно общо съобщение, скривайки missing-field branch.

Допълнителни потвърдени save дефекти: празни optional numeric/bool/select стойности
са могли да отказват вместо да избират defaults; enabled module save е валидирал
чуждите payment settings и могъл да откаже при непопълнен payment order status.
Shop ID е бил задължителен само при enabled module, вместо според новото owner
изискване да е единственият задължителен user-entered параметър.

Реалният POST/exception от магазина не е достъпван. Горните причини са доказани
локално върху доставения source/DOM и възпроизведени в тестове; възможни допълнителни
store-specific откази ще се виждат чрез новите конкретни diagnostics.

## E. Validation и persistence

- Празен email се пази точно като `''` → manual mode. Валиден nonempty email/list
  се приема; whitespace/невалиден email/неправилен тип се отказва както преди.
- Липсващият recipient control не се заменя тихо с default: дава
  `[missing_recipient_field]`, защото означава непълна/повредена форма.
- Shop ID се trim-ва и валидира server-side, задължителен и при disabled module;
  има HTML required/maxlength. Грешката е `[invalid_shop_identifier]`.
- Празните optional controls използват съществуващите безопасни defaults. Изрично
  невалидни nonempty стойности остават отказани с име на полето, без отражение на
  неговото съдържание. Missing optional controls могат да запазят наличната config.
- Добавени са allowlisted field diagnostics за recipient/ID/optional/protected
  settings. Permission/token и schema errors имат отделни безопасни кодове.
  Не се изписват raw SQL, secret, стойности на полета или customer identity.
- Премахната е module-save зависимостта от чужди payment order-status настройки.
  Payment settings validate и runtime submission/order readiness НЕ са отслабени:
  неподготвен gateway не може да изпълни нова заявка.
- Schema/secret readiness, default-store scope, authentication/modify/user_token,
  protected fields и persistence allowlist са запазени. След успешен redirect
  controller-ът се връща без второ рендериране. Secret/counter не се reset-ват.
- Преводите съдържат heading_title; общата стара грешка е била литерал в controller,
  не превод. Поправката запазва съществуващия български UI стил без language redesign.

## F. Регресия

| Suite | Резултат |
|---|---|
| admin-tabs.py | PASS, 340 checks; strict tag balance, IDs/targets, pane/field/form ownership, DOMDocument probe, Bootstrap contract |
| admin-settings.php | PASS, 78 нови checks + 73 installation = 151 cumulative |
| install.php | PASS, 73 |
| currency.php | PASS, 272 |
| financial.php | PASS, 205 |
| submission.php | PASS, 594; manual_registered и bank golden fixtures |
| purchase.php | PASS, 380 |
| security.php | PASS, 246 |
| escaping.py | PASS, 356 |
| submission-contracts.py | PASS, 136 checks; 107 PHP files linted |
| ocmod.py | PASS, 317; native admin/mail/checkout fixtures за 3.0.3.8/3.0.5.1 |
| compatibility.py | PASS, 223; manifest 89 entries |
| security-ui.cjs | PASS, 148; actual inline admin JS syntax и handlers чрез Windows Node |
| builder.py --source-only | PASS, 15 executed / 6 archive tests skipped по забраната за ZIP |
| Bash syntax / XML parsing / git diff --check | PASS |

Settings тестовете изпълняват реалния controller с persistence/DB doubles: explicit
empty/valid/invalid/missing recipient, shop ID, optional blanks, unchanged/repeated
save, modified save, token/permission refusal, missing/partial/wrong-revision schema,
непроменени secret/counter/seed counts и никакъв settings write при отказ.

## G. Remaining owner E2E

Проверка на реален OC3 3.0.3.9: четирите таба и запомнен active tab, single layout,
обхват на form POST, фокус на missing shop ID, запис и повторно отваряне с празен
email, промяна на optional настройки и конкретни safe errors. Проверете normal
Bootstrap styling, валиден nonempty recipient и manual registration в контролиран
test setup след нов пакет. Тук не е изпращана заявка/email.

Няма инсталиран пълен Twig/browser engine; static Twig structure и DOM parser PASS
не се представят като browser E2E. Реалните MySQL/cache-refresh/SMTP gates остават.

## H. Препоръка

GO за owner review и след одобрение отделен rebuild на TEST installer. Текущият ZIP
с SHA-256 `3973c5f48cca48a91cacc11780da74883f7dbc2f819fc97197f30e89acfe9c82`
не включва FIX-02 и е superseded спрямо новия source; запазен е непроменен.
Няма нов ZIP и няма production/final-release approval. Спирам след отчета.

## Source references

- `upload/admin/view/template/extension/module/mt_jet_credit.twig:274` — `<form action=`
- `upload/admin/view/template/extension/module/mt_jet_credit.twig:275` — `<div class="tab-content"`
- `upload/admin/view/template/extension/module/mt_jet_credit.twig:276` — `id="mt-jet-tab-upravlenie"`
- `upload/admin/view/template/extension/module/mt_jet_credit.twig:341` — `id="module_mt_jet_credit_id"`
- `upload/admin/view/template/extension/module/mt_jet_credit.twig:650` — `.on('invalid'`
- `upload/admin/controller/extension/module/mt_jet_credit.php:9` — `public function index()`
- `upload/admin/controller/extension/module/mt_jet_credit.php:279` — `private function validate()`
- `upload/system/library/jetcredit/admin_settings.php:9` — `public static function module(`
- `upload/system/library/jetcredit/admin_settings.php:40` — `public static function diagnostic(`
