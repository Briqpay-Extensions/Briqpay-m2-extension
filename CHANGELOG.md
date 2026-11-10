# Changelog

All notable changes to the Briqpay Payments module for Magento 2 are documented here,
starting with 2.0.0. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-11-10

### Changed

**Platform**

- Requires PHP 8.2 to 8.5 and Magento Open Source / Adobe Commerce 2.4.6 or later.
  PHP 8.1 no longer receives security fixes; stores on it stay on 1.x until PHP is
  updated. `composer.json` declares the Magento modules the extension uses. Purchases
  complete on PHP 8.5.
- Amounts are rounded to whole cents the way Magento rounds prices, with the same
  result on every supported PHP version.
- The module reports its own version (2.0.0) to Briqpay and to Magento's module list,
  also after an upgrade from 1.x.
- The module's columns and table are declared as a declarative schema (db_schema.xml).
  An upgrade from 1.x changes the
  order table's Briqpay session id column to an indexed varchar(64), which rewrites
  that table once (plan the upgrade for a quiet moment on a large store).
- The log level defaults to INFO. Test mode is on by default for a new install; a store
  that used the live API before the upgrade without storing the setting stays live.
- Requests to Briqpay time out after 60 seconds. The module uses its own HTTP client and
  log handler for Briqpay's calls and log only, and leaves Magento's to other modules.
- Invoices, credit memos, cancellations and Briqpay's notices use the Briqpay settings
  of the order's store, for shops with several websites or store views, also when
  Magento's dependency injection leaves the store helper unset.
- Allowed countries and minimum and maximum order totals can be set for Briqpay in the
  admin, and the purchase decision applies them, as Magento's checkout does.

**Amounts sent to Briqpay**

- Every session, capture and refund line carries exactly the amounts Magento charges,
  incl. and excl. VAT, line by line, so carts and invoices match Briqpay to the minor
  unit with no rounding lines (credit memos: see Order management): discounts in the
  checkout (also those applied after tax, sent without VAT as Magento calculates them),
  discounts on
  shipping, fixed discounts spread over several lines, WEEE (recycling fee) lines for
  quantities above one, and the addresses' countries. A cart whose Magento total has
  no Briqpay line (store credit, a gift card, reward points, a fee from another module)
  is refused with a logged error naming the amount and the totals, so nothing invented
  ever reaches the books.
- Every capture and refund line's VAT matches its tax rate. Shipping is sent at the VAT
  rate of the order's products that its VAT matches, and where Magento splits a line's
  VAT unevenly over invoices or credit memos the VAT is shared with other lines at the
  same rate; amounts and total VAT are unchanged, and a difference Magento's own VAT
  cannot fit is noted on the order. A refund that empties a captured line leaves none
  of its VAT behind: where Magento's per-line VAT would leave a minor unit on the
  capture with no amount for it (EUR at 19 %, say), the refund's line takes it and the
  difference is noted, so later credit memos are not refused for it. A last credit
  memo whose total Magento rounds down by a fraction of a minor unit refunds what the
  captures still hold.
- Bundles with dynamic pricing are sent as their individual products, each with its own
  VAT rate; several lines of the same product at different prices (custom options) are
  captured and refunded line by line. Orders placed or authorised before upgrading are
  captured and refunded as before.
- Briqpay takes whole quantities only. A cart with a product sold by weight or length
  (a decimal quantity) is not offered Briqpay as a payment method, and a session asked
  for anyway is refused with the product and quantity in the log.
- A cart line whose image Magento can only resolve to its placeholder is sent without
  an image.

**Checkout**

- The Briqpay payment module follows the same rule as Magento's own Place Order
  button: it is open while Magento has a billing address for the cart, and locked while
  the address form is open or an address is being saved, for physical and virtual
  carts alike. A hint beside the form says to click Update. The address the customer
  selects is saved to the cart and to Briqpay before the module opens, and changes made
  after the payment step opened (an edited address, a typed or changed guest email, on
  carts with and without shipping) always reach Briqpay before the customer can pay;
  nothing is saved during the purchase itself, which Briqpay would refuse. The lock is
  applied once the module has rendered, and again whenever it renders anew after
  another payment method was selected or a page reload, as Briqpay keeps a lock across
  page loads.
- Carts with only virtual or downloadable products can be paid with Briqpay. A guest
  returning to a virtual cart with a remembered email and address pays without
  re-entering them; a billing form being filled in keeps the payment module locked
  until it is saved with Update, and the hint says so. A returning guest's remembered
  email is known to the checkout from the start.
- The checkout keeps one Briqpay session per cart, and switching to another payment
  method and back asks Briqpay's decision once per purchase.
- A stored session is replaced by a new one when the shop's address changed, when the
  shop's credentials can no longer read it (going live from the playground, a rotated
  secret, an expired session), when the store's currency or country is not the one the
  session was created for, or, in a business shop, when the billing company changed
  since the session was created (Briqpay keeps a session's company from its creation).
  The payment window on the page is rendered for the new session at once. A completed
  session, or one with a payment attempt on it, is never replaced for the cart's data
  (company, currency, country).
- A session the shop replaced while the customer was paying it (another tab) is not
  lost: its payment reaches the order placed from it, places the order itself when the
  cart has none, or is noted on the order the cart was paid for through another
  session. The return to the shop also looks at sessions the checkout replaced, and the
  purchase decision runs under the cart's lock.
- A purchase being paid is left alone. Once a purchase is approved, the session is not
  updated, replaced or locked by any tab for up to 15 minutes, until the tab that
  started it reports that the payment ended without an order (the customer cancelled
  it). A purchase on that session from another tab or window is refused, without
  anything sent to Briqpay that could disturb the running payment, and the page says
  to finish or cancel the payment there, or to try again in 15 minutes; only the tab
  that started the payment may try again, with what changed since checked first. A tab
  learns from the shop whether a payment is running when it loads, when it is shown
  again and when its window gets the focus back, so it can lock the payment window at
  once when it must, and it releases its lock on a session before it shows another
  one.
- Business checkouts send the company name from the customer's addresses: for a guest,
  the company entered on the shipping step serves as the company and the billing
  company name until the billing address has its own; every later update of the
  session carries the names too. On a virtual cart only the billing company counts. A
  business cart open during the upgrade from 1.x gets a new session once.
- A storefront Briqpay order is placed only by the Briqpay checkout, once the payment is
  made.
- The purchase is declined before payment when Magento could not create the order (an
  item out of stock, an order below the minimum amount, a total that differs from what
  Briqpay authorised). A payment that still cannot become an order (an item sold out
  during payment) is cancelled at Briqpay and the customer returns to the cart, able to
  pay the same cart again. The purchase check sets Briqpay as the cart's payment
  method, so the order does not depend on the checkout having saved it.
- A decision for a session the cart has replaced is refused, and the page catches up
  with the current session. A business purchase is refused when the cart's billing
  company is not the session's. A refusal the customer can recover from, and a return
  to the shop without a payment, are logged as warnings.
- Each payment becomes one order, whether the customer's return or Briqpay's notice of
  the payment comes first; the confirmation page shows the order either way, and a
  confirmation page opened for a cart without a Briqpay payment returns to the cart.
  An order created in the background belongs to the logged-in customer who paid. An
  order whose amount, lines or (in a business shop) billing company differ from what
  Briqpay was paid for is put on hold with a note naming the difference.
- A failure while deciding a purchase or showing the confirmation is answered with a
  message to the customer; the checkout overlay is removed when a payment is cancelled.

**Payment links for admin orders**

- Orders created in the admin can be paid with Briqpay: the order is placed in the
  "pending payment" state with the status "Awaiting Briqpay payment" (not Magento's
  "pending_payment", which its cron cancels after a few hours), and the order view
  offers "Create payment link", which opens Briqpay's
  hosted payment page for the order's cart and addresses (consumer or business). The
  merchant sends the link to the customer, who lands on a confirmation page in the shop
  after paying; the order moves on when the payment is made, as a storefront order
  does, and is captured and refunded the same way. The page uses the store view's
  locale, terms page and webhook addresses. Settings: the page title, a logo and
  whether the page lists the order lines (Payment Methods > Briqpay > Briqpay Advanced
  Features).
- "Update payment link" sends the order's current details to Briqpay again and keeps
  the link, so an earlier copy of it cannot be paid a second time. Where Briqpay does
  not take the update, and for a link for the other customer type, a new link is made;
  a payment on an earlier link is noted on the order and logged.
- Cancelling or editing an order whose link was never paid works without Briqpay
  (there is nothing there to cancel; the note says the link stays open). When Briqpay
  cannot be reached to confirm that, nothing is cancelled and the admin is asked to try
  again, as the link may just have been paid. An order
  waiting for its link cannot be invoiced, online or offline, before the payment. A
  link paid while its order is on hold is written to the order and continues when the
  hold is removed, and a payment on a link reaches its order even after Magento's cron
  has removed the order's cart.

**Order management**

- Captures, refunds and cancellations follow Briqpay's records of the order exactly,
  including partial and discounted orders, shipping and adjustment refunds, and
  invoices created through the API, the command line or other modules. Each capture
  and refund is checked against Briqpay's current record, including changes made in
  the Briqpay backoffice, before it is sent; one Briqpay would refuse, or that would
  not equal its invoice or credit memo, is stopped with a message saying why. For
  orders authorised before upgrading, carts with WEEE lines and items without their
  own document amounts, a capture that differs from its invoice is noted on the order
  instead. Invoices and credit memos with nothing to capture or refund are not sent.
- An invoice whose lines Briqpay already holds as an approved capture without an
  invoice (an earlier attempt whose answer was lost, or a capture made in the Briqpay
  backoffice) is linked to that capture, also when Briqpay holds nothing more of the
  line; nothing is captured twice. For orders authorised before upgrading, a capture
  that differs from its invoice total is noted on the order.
- A capture the payment method made by itself (Swish) is invoiced in Magento as soon as
  the order exists, also when its webhook arrived before the order did or twice at
  once, when it covers the whole order and nothing is invoiced yet; any other such
  capture is noted on the order for the merchant to invoice. Briqpay's capture status
  updates are applied to the matching invoice; a rejected capture, and a capture made
  outside Magento, are noted on the order.
- Refunds are made per capture, and a credit memo spanning several captures is refunded
  from each. A credit memo is refunded at Magento's credit memo total even where
  Magento's own lines differ from it (up to two minor units per line, a cart rule that
  also discounts shipping, a rounded adjustment memo; any amount where Magento's total
  is not a whole number of minor units): the difference is bridged from the capture, or
  from the order's other captures when the credit memo's own has nothing left, on a
  line labelled "Difference to the Magento credit memo total" that carries VAT in
  proportion, and the admin is told and the order notes it. Where Magento splits a
  credit memo over invoices differently from the captures, the part one capture lacks
  is refunded from another, on labelled lines without VAT, and the order notes it.
- A refund never carries more VAT than its capture still holds, and takes all of it
  when it takes all of the capture's amount. An adjustment refund closes a capture to
  the minor unit, incl. and excl. VAT, also where Briqpay's total excl. VAT is a minor
  unit off the lines' sum, where the capture gave a minor unit to another credit memo,
  and in currencies where Magento's per-line VAT adds up above the rate's reach (EUR at
  19 %, say), where the adjustment goes over two lines. Adjustment refunds go to
  Briqpay without VAT, as Magento books them, as far as the captures allow; any part
  that must carry VAT is noted on the order. An adjustment fee comes off the lines the
  credit memo refunds, on their captures, and reduces the refund's VAT in proportion.
- A credit memo for more than Briqpay holds captured, or for an invoice whose capture
  the payment provider rejected, is refused naming the amount or the capture, and
  nothing of it is refunded. If refunding one of several captures fails
  after another was refunded, the credit memo is kept and the order notes what is left
  to refund.
- Cancelling an order uses the Briqpay reference stored on the order and is the one
  way to release an authorisation. Cancelling a partly invoiced order works with
  payment methods that cannot release the remaining amount; the admin is told to
  release it with the payment provider.

**Webhooks**

- Briqpay's order webhook moves an order forward from its pending states only; an order
  on hold, complete, closed or cancelled keeps its state. A payment Briqpay rejects or
  cancels (both handled alike) cancels a pending order through Magento itself, so stock
  and totals follow; an order Magento cannot cancel is noted.
- A webhook without the expected data is answered with an error. The order's e-mail is
  taken from the billing address, the quote or Briqpay's billing e-mail, whichever has
  one.
- A payment method chosen inside Briqpay is named in the order e-mail from the order
  itself, also for orders whose cart Magento has cleaned up; the method's own title is
  shown when Briqpay recorded none.

### Removed

- The "Strict rounding" setting: amounts are exact, so there is nothing to round.
- Magento's "Void" action for Briqpay orders: it has no counterpart at Briqpay;
  cancelling the order releases the authorisation.

### Added

- An admin warning when the server's PHP lacks the fixes for CVE-2026-109618 and
  CVE-2026-109619, and `bin/magento briqpay:php:check` for deploy scripts. A build with
  a backported fix can be confirmed in the module's advanced settings. The module never
  stops working because of it.
- Automated quality checks, run in CI on every push: PHPUnit unit tests, the Magento 2
  coding standard with PHP-compatibility checks and PHPStan on the rewritten parts of
  the module, and PHP Mess Detector on the tests.
- Tests that pin how the amounts of checkout carts are calculated.

### Security

- Security improvements.

## [1.2.2]

Releases up to and including 1.2.2 are not listed in this changelog.
