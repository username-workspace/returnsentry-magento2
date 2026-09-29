# CLAUDE.md: ReturnSentry Magento 2 module

`ReturnSentry_Connector` connects a Magento shop to the ReturnSentry `/v1` API. The repository root `CLAUDE.md` still applies: zero comments, braces on every `if`, Conventional Commits, legal by design. `README.md` is the user documentation (French is the reference, the English half mirrors it section for section); update both halves in the same commit as the behaviour they describe.

## Invariants

| Invariant | Enforced in |
|---|---|
| Decision points hook the service layer only, so Luma, Hyvä, GraphQL and REST behave the same. Anything the Luma front does has a GraphQL and REST equivalent. | `etc/events.xml` (`sales_model_service_quote_submit_before`, `*_save_commit_after`), `etc/di.xml` (plugins on `MethodList`, `RefundAdapterInterface`), `etc/schema.graphqls`, `etc/webapi.xml` |
| `returnsentry:connect` turns the module on only after a passing connection test at that scope. A first connection saves with the module off, and a failed test leaves it off; a re-run on a connected scope never turns it off, even when its test fails, so syncs and stored refund stops go on. It never replaces a different saved pseudonymization secret without `--force-salt`. A profile never carries the address, keys or salt, and can only turn the module off. | `Console/Command/Connect.php`, `Model/Settings.php` (`CONNECTION_PATHS`, `check()`) |
| Every decision point starts in Observe; Observe applies nothing and shows the shopper nothing, only "would have" in the admin. | `etc/config.xml`, `Model/Config.php` (`mode()`), `Model/Checkout/CartReturnTerms.php` (terms only in Enforce) |
| Calls fail open within their budget. During a refund outage, a stored fraud-evidence hold, a deny, or a partial approval below the amount keeps the credit memo stopped. A refused key or URL (401, 403, 404) fails open loudly: order comment, panel line, system message. | `Model/Checkout/Assessor.php` (`checkout_timeout_ms`, fail-open cache), `Model/Refund/Gate.php` (`withoutAnswer()`, `storedStop()`), `Model/Http/ApiException.php` (`refusesKeyOrUrl()`), `Model/System/RefundControlAlert.php`, `Model/Http/Client.php` |
| A refund stop is a `RefundStoppedException` (a `CouldNotRefundException`) carrying the decision, reasons, deadline and evidence link; `RefundOrderInterface` and `RefundInvoiceInterface` rethrow that same object, so code calling them can tell a ReturnSentry stop from any other failure. | `Exception/RefundStoppedException.php`, `Model/Refund/Gate.php` (`stop()`), `Plugin/ExplainRefundStop.php` |
| `returnTermsDisplayed` is true only on the placement call, and the order stores terms from the same value. | `Observer/ApplyCheckoutDecision.php`, `Model/Checkout/Assessor.php` (`assess()` defaults to false), `Model/Checkout/ReturnTermsDisplay.php` (attestation plus served paid terms) |
| Only shopper channels are assessed: not the admin, not admin or integration tokens. | `Model/Checkout/Assessor.php` (`STAFF_CHANNELS`), `etc/adminhtml/events.xml`, `etc/adminhtml/di.xml` |
| An anonymous REST call is a guest to Magento, like a shopper, so an external checkout (Global-e) is kept out by its payment method: placing an order paid with an excluded method is neither assessed nor stopped, and its credit memos ask no authorization; everything is still synced. The payment method list, which Magento computes when the shipping method is set, comes before any payment method is chosen, so it is still assessed and filtered. | `Model/Config.php` (`isExcludedPaymentMethod()`), `Observer/ApplyCheckoutDecision.php`, `Model/Refund/Gate.php` |
| No personal data read from the device without consent; withdrawal clears `rs_consent`, and `rs_vid` outside exempt pages; the visitor ID is validated and kept on orders only for a connected store view. | `Model/Checkout/VisitorId.php`, `Observer/CaptureVisitorId.php`, `view/frontend/templates/beacon.phtml`, `ViewModel/Beacon.php` (exempt pages), `packages/beacon/src/index.ts` (`consent(false)`) |
| Data kept in Magento has a retention: the visitor ID leaves orders after `Retention::TRACKER_RETENTION_MONTHS`, refund authorizations leave with their order, inspections leave once no send is pending for them. | `Model/Retention.php`, `Cron/ApplyRetention.php` |
| Inspections reported by merchant code go through the durable retry store and wait in `returnsentry_inspection` only until ReturnSentry received them or refused their content, or until the nightly retention after their send was abandoned; the report and its queued send commit together; a refused key or URL is retried like an outage. | `Model/InspectionReporter.php`, `Model/Returns/InspectionStore.php`, `Model/Sync/Sender.php` (`sendInspection()`), `Model/Sync/Publisher.php` (durable types), `Model/Http/ApiException.php` (`retryable()`) |
| Deleting a customer, or `CustomerErasureInterface::eraseByEmail()` for a flow that anonymizes instead, erases them in ReturnSentry, retried until confirmed (`eraseByEmail()` by email and by the ID of each account at that email); their orders stop being sent. `eraseByEmail()` also clears the module's data on their orders and marks them `returnsentry_erased`. | `Observer/PublishSync.php` (`erasure`), `Model/CustomerErasure.php`, `Model/Sync/Publisher.php` (durable types), `Model/Sync/Sender.php` (`erasureQuery()`), `Model/Sync/Consumer.php`, `Model/Mapper/OrderMapper.php` (`hasErasedCustomer()`) |
| One checkout makes one call when nothing changes: the order number is reserved at the first assessment. | `Model/Checkout/OrderNumber.php`, `Model/Checkout/Assessor.php`, `Model/Checkout/PaymentRestriction.php` (`placingAssessment()`) |

## Where each concern lives

| Concern | Files |
|---|---|
| Configuration and scopes | `etc/adminhtml/system.xml`, `etc/config.xml`, `Model/Config.php`, `Model/Config/Backend/*`, `Model/Settings.php` (profiles, saves through the admin save model) |
| Setup commands | `Console/Command/Connect.php` (reuses `Model/ConnectionTester.php`), `Console/Command/ApplyProfile.php` |
| Checkout decision | `Observer/ApplyCheckoutDecision.php`, `Model/Checkout/Assessor.php`, `Model/Checkout/PaymentRestriction.php`, `Plugin/RestrictPaymentMethods.php` |
| Visitor ID and consent | `Model/Checkout/VisitorId.php`, `Model/Checkout/ConsentCookie.php`, `Observer/CaptureVisitorId.php` |
| Return terms | `Model/Checkout/CartReturnTerms.php`, `Model/Checkout/ReturnTermsDisplay.php`, `Model/Resolver/ReturnTerms.php`, `view/frontend/web/js/view/return-terms.js`, `ViewModel/AnnouncedReturnTerms.php`, `view/frontend/layout/returnsentry_email_return_terms.xml` |
| Sync, queue, retries | `Observer/Publish*.php`, `Model/Sync/*`, `Cron/RetrySync.php`, `etc/communication.xml`, `etc/queue_*.xml`, `etc/crontab.xml` |
| Record mapping | `Model/Mapper/*` |
| Returns and warehouse inspections | `Model/Returns/*`, `Model/ReturnReporter.php`, `Api/ReturnReporterInterface.php`, `Model/InspectionReporter.php`, `Api/InspectionReporterInterface.php`, `Api/Data/Inspection*.php` |
| Refund authorization and override | `Plugin/AuthorizeRefund.php`, `Plugin/ExplainRefundStop.php`, `Model/Refund/*`, `Exception/RefundStoppedException.php` |
| Backfill | `Console/Command/Backfill.php`, `Model/Backfill.php` |
| Beacon | `ViewModel/Beacon.php`, `view/frontend/templates/beacon.phtml`, `Model/Csp/BeaconPolicyCollector.php` |
| Admin panels and system messages | `ViewModel/RiskPanel.php`, `view/adminhtml/**`, `view/base/ui_component/customer_form.xml`, `Block/Adminhtml/**`, `Model/System/*` |
| Retention | `Model/Retention.php`, `Cron/ApplyRetention.php`, `etc/crontab.xml` |
| Shopper and admin texts | `i18n/en_US.csv`, `i18n/fr_FR.csv` (add every new phrase to both) |

## Conventions

- The public PHP contract is `Api/*` and `Exception/*`, marked `@api`: change it additively only.
- Api/Data classes are concrete with constructor-promoted properties. A class or interface exposed through `etc/webapi.xml` keeps a `@return` annotation on every public method: Magento's webapi reflection reads types from it.
- Direct database writes go through `ResourceConnection`, as in `Model/Refund/AuthorizationStore.php`.
- A new `sales_order` column goes in `etc/db_schema.xml` and `etc/db_schema_whitelist.json`.
- A change to a legal point (consent, announced terms, erasure, retention) updates `docs/legal.md` in the same commit.
- A release raises `version` in `composer.json` (semver). On `main`, the `magento-module` job of `.github/workflows/ci.yml` replaces the content of the public repository `username-workspace/returnsentry-magento2` with the tracked files of this folder, without history, and tags a new version there as bare `X.Y.Z`, the only tag form Composer reads besides `vX.Y.Z`.
- Everything under `integrations/magento2` is published publicly by CI: no merchant names, customer data, internal hosts or secrets in its files.

## Gate

```bash
cd integrations/magento2
composer install
composer check
```

`composer check` runs php -l, phpcs (Magento 2 standard), phpstan level 6 and the PHPUnit `unit` suite. The storefront script `view/frontend/templates/beacon.phtml` is tested by `Test/Js/beacon.test.mjs`, run from the repository root by `pnpm test` or `node --test 'integrations/magento2/Test/Js/*.test.mjs'`. Call phpstan directly only as `vendor/bin/phpstan analyse --memory-limit=2G`. `composer test:e2e` needs `RS_E2E_URL` and `RS_E2E_SECRET_KEY` pointing to a running API.

End to end in a disposable Mage-OS shop (`tools/magento-e2e/README.md`), from the repository root, with the working tree of this module:

```bash
tools/magento-e2e/e2e.sh up && tools/magento-e2e/e2e.sh api
tools/magento-e2e/e2e.sh module --worktree && tools/magento-e2e/e2e.sh seed
tools/magento-e2e/e2e.sh test all
```

A behaviour change the check catalog does not cover yet gets a new check there.
