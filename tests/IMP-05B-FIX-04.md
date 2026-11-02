# IMP-05B-FIX-04 — Product popup / Jet-Woo parity

## A. Baseline и променени файлове

HEAD `2cc75057219f3c76a95b4107e9f78f44825daedc`, branch main, версия
`2.0.0 TEST / UNRELEASED`. Начален cumulative статус: 28 modified + 6 untracked,
0 staged. Краен cumulative статус: 32 modified + 10 untracked, 0 staged.
FIX-01/FIX-02/FIX-03 се запазват. Няма ZIP, version bump, commit/push/tag,
external store access, реална DB операция, email или bank application.

Тази задача променя:

- `install.xml` — product/cart popup loader, installment-row wrappers и button markup.
- `upload/catalog/model/extension/mt_jet_credit/financial.php` — само display suffix и private rendered profile prefill.
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js` — loader, form state, back navigation, stale callbacks.
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.css` — scoped reference styles, responsive/focus/loader/disabled states.
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.css` — import на съществуващия shared stylesheet за общия popup loader.
- `docs/release-manifest.json` — актуални hashes/sizes; същите 89 runtime/XML entries.
- `tests/financial-bootstrap.php`, `tests/security-ui.cjs`, `tests/README.md`.
- Нови `tests/prefill.php`, `tests/popup-contracts.py`, `tests/fixtures/jet-woo-ui-reference.json` и този отчет.

## B. Действителен Jet-Woo източник

Jet-Woo не е наличен локално. Използван е разрешеният GitHub connector само с read
операции. Няма промяна/commit/push в Jet-Woo. Reference версия 2.0.0, commit
`8126bdd49046669583ab8e8c02f7bf2124a46521`:

- [CSS](https://github.com/wiley68/jet-woo/blob/8126bdd49046669583ab8e8c02f7bf2124a46521/css/jetcredit_product.css)
- [Product JS](https://github.com/wiley68/jet-woo/blob/8126bdd49046669583ab8e8c02f7bf2124a46521/js/jetcredit_product.js)
- [Shared offers / popupLoading](https://github.com/wiley68/jet-woo/blob/8126bdd49046669583ab8e8c02f7bf2124a46521/js/jetcredit_offers.js)
- [Markup и profile mapping](https://github.com/wiley68/jet-woo/blob/8126bdd49046669583ab8e8c02f7bf2124a46521/includes/functions.php)
- [Plugin version](https://github.com/wiley68/jet-woo/blob/8126bdd49046669583ab8e8c02f7bf2124a46521/jetcredit.php)

Прочетени са действителните file contents. Временните read-only reference копия са
проверени срещу GitHub blob SHA; provenance/полезните metrics са в JSON fixture.
Не се включва Woo код или reference fixture в installer-а.

| Компонент | Reference | OC3 след FIX-04 |
|---|---|---|
| Standard installment line | 14pt / 600 / 20px | Същото, по-силен Jet-scoped selector |
| Wide installment line | CSS variable, default 14px / line-height 1.2; inherited weight | Същите size/line-height, explicit 600 по owner emphasis изискването |
| Labels | 16pt / 500; 12pt / 14px mobile | Същите metrics; breakpoint 767px |
| Inputs | 20px / 600 / 48px; green 1px border / radius 3px | Същите metrics; explicit right alignment |
| Scheme/term select | Отделни select styles | Запазени 20px/600/48px и left alignment |
| Loader | 48px ring, 4px green border, 0.8s, translucent overlay | Същите metrics, scoped overlay, hidden accessible text, reduced-motion |
| Prefill | Current WP user + phone_number meta | Current OC3 customer getter APIs; няма WordPress lookup |

Reference label font-size вече е бил 16pt и в OC3; поправката не измисля друг
размер, а уеднаквява weight 500, responsive breakpoint и scoped precedence.

## C. E2E-F09 / F10

По-силни selectors само под Jet container/popup предотвратяват обикновени theme
class overrides. Standard installment line е 14pt/600; wide line следва admin font
variable (default 14px), explicit 600 и 1.2 line-height. Целият installment row е
скрит преди валиден preview, не остава празно `x евро`; schemes се показват отделно.
Responsive width/overflow behavior и съществуващите admin font настройки се пазят.

`frontendData()` връща user-facing `jet_sign = евро`. Popup/button labels използват
този escaped display value. Разбираемите JS notices използват евро. Technical
`EUR` остава в quote JSON, server calculations, currency conversion, signed offers,
order metadata и bank payloads. Няма finance formatting/formula промяна.

## D. E2E-F11 / F12

Нови CSS rules са scoped, без нова външна dependency. Запазена е специфичната
select typography/alignment; личните и amount inputs са bold/right според owner
изискването. Видими focus outlines и error borders остават. Responsive rows/buttons
се подреждат вертикално на тесен екран.

Loader markup има role=status / aria-live=polite. `loading()` управлява popup class
и aria-busy. Quote loading се задава преди AJAX; current success/error, close,
abort/destroy го изчистват. Quotes имат 30s timeout. Sequence/alive guards не позволяват
стар/отменен отговор да върне стара оферта или да скрие нов loader. Submission
loading следва busy и се чисти при success/failure, без автоматично повторно изпращане.
Отделна local submit revision не позволява закъснял първи отговор да надпише активен
explicit status replay. Backend HMAC/claim/replay semantics не са променени.

Споделеният cart popup използва същия JS/markup; неговият CSS импортира наличния
product stylesheet, така че loader-ът не остава като неоформен видим текст.

## E. E2E-F13 — причина и поправка

OC3 markup е задавал permanent inline opacity 0.5 на buy/buy2. JS е сменял само
`disabled`, оставяйки активния бутон видимо блед. Освен това submission handler е
проверявал offer/capability, но не е използвал същия consent/personal predicate
като UI state.

Премахнат е fixed inline opacity. Native disabled property и визуалното състояние
идват от общи `ready()`, `consentReady()`, `personalReady()` и `canSubmit()`.
Самият `submit()` използва `canSubmit()`, така че programmatic click не прескача
непопълнени данни/съгласия. Step 1 пази двете съгласия; Step 2 пази и третото
съществуващо съгласие. Не е отслабена server-side consent проверката.

Client EGN shape съответства на вече съществуващата server политика; няма нов
checksum/национален-ID режим. Pending retry пази оригиналния immutable POST за
проверка на резултата, както преди. Loading/busy предотвратява double submission.

## F. E2E-F14 — причина и поправка

`back2_jetcredit` съществува в product/cart XML, но е липсвал JS binding.
Добавен е един namespaced handler, който само превключва Step 2 → Step 1.
Не прави refresh/AJAX, не ресетва полета/term/down payment/offer, не създава native
order/claim и не изпраща bank application. Forward използва текущата валидна оферта.
Expired quotes не се съживяват чрез навигация. Remount премахва старите handlers.
Busy/pending изпълнение не се прекъсва чрез back/close, за да не се изгуби
видимостта на uncertain application state.

## G. E2E-F15 — източник и privacy

Преди поправката `frontendData()` изрично е задавал празни четирите profile полета.
Сега само за authenticated customer и rendered product/cart popup се използват:
`getFirstName()`, `getLastName()`, `getEmail()`, `getTelephone()`.

Методите са потвърдени в официалния
[OC3 3.0.3.9 Customer source](https://github.com/opencart/opencart/blob/3.0.3.9/upload/system/library/cart/customer.php).
Не се чете друг customer, исторически order или национална идентификация. Няма
EGN getter/prefill. Липсващи/невалидни profile fields остават празни. Logged-out
customer получава празни editable полета. Няма нов endpoint; initial server-rendered
HTML се пази с `Cache-Control: private, no-store`, а Twig escaping е запазено.

Quote DTOs/HMAC payload/URLs/logs не получават profile данни. JS не преизпълнява
prefill при back/forward/refresh, така че corrections на клиента не се презаписват.
Съществуващият checkout order/customer binding не се заменя с profile prefill.

## H. Автоматизирани тестове

Всичко е local/isolated, не реален store/MySQL/SMTP E2E.

| Suite | Резултат |
|---|---|
| popup-contracts.py | PASS, 244; scoped metrics/loader/markup/editability/reference |
| prefill.php | PASS, 29; current profile, guest, no EGN, no PII in quote/token, private cache header |
| security-ui.cjs | PASS, 205; actual loader/disabled/consent/navigation/replay callbacks и JS syntax |
| security.php | PASS, 246 |
| financial.php | PASS, 205; financial goldens |
| currency.php | PASS, 272 |
| submission.php | PASS, 594; bank goldens/manual/replay/reference |
| purchase.php | PASS, 380 |
| install.php | PASS, 73 |
| admin-settings.php | PASS, 172 cumulative |
| admin-tabs.py | PASS, 340 |
| escaping.py | PASS, 356 |
| submission-contracts.py | PASS, 137; 108 PHP files linted |
| ocmod.py | PASS, 317; representative native admin/mail/checkout fixtures |
| compatibility.py | PASS, 223; manifest 89 entries |
| builder.py --source-only | PASS, 15 executed / 6 archive tests skipped |
| XML/CSS/HTML structure / git diff --check | PASS |

JS тестовете проверяват непосредствен loader, abort/close/destroy, late responses,
out-of-order quote/submit callbacks, всички consent revocations, invalid email,
истински disabled state и opacity, repeated forward/back, immutable term/offer,
запазени typed values, липса на navigation AJAX и duplicate handlers/submits.

## I. Разлики и оставащи рискове

Не се твърди pixel-identical parity или certification на произволна OC3 тема.
Woo и OC3 имат различни wrappers/theme CSS. Две съзнателни разлики:

1. Reference `.jet_left` profile inputs са left-aligned; owner инструкцията за
   right alignment има предимство в OC3. Select exception остава left-aligned.
2. Wide secondary weight в reference е inherited; OC3 го фиксира на 600, за да
   изпълни изискването за bold и да не зависи от surrounding theme.

Пълен browser/Twig render, responsive screenshots на конкретната тема, keyboard/
focus/animation и реална OC3 3.0.3.9 profile session трябва да се потвърдят от owner.
Реални DB contention, modification-cache refresh и contained SMTP остават отложени.
След бъдещ deployment трябва да се използват обновените CSS/JS/OCMOD assets и кешове.
Няма нова финансова формула, threshold, promotion priority или bank template.

## J. Препоръка

GO за owner review и след това отделно одобрен TEST build/E2E на product popup.
Не е създаван ZIP и не е извършван deploy. Съществуващият BUILD-02 ZIP не включва
FIX-03/FIX-04 и е запазен непроменен за provenance. Няма production/final approval.

## Source map

- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js:40` — `function loading(value)`
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js:59` — `function canSubmit()`
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js:158` — `function closePopup()`
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js:164` — `'#back2_jetcredit'`
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js:198` — `submittedRevision =`
- `upload/catalog/model/extension/mt_jet_credit/financial.php:28` — `function frontendData(`
- `upload/catalog/model/extension/mt_jet_credit/financial.php:59` — `Cache-Control: private, no-store`
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.css:502` — `FIX-04:`
- `install.xml:125` — `class="jet_offer_loader"`
