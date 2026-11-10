require([
  "jquery",
  "mage/url",
  "Magento_Checkout/js/model/quote",
  "Magento_Checkout/js/view/billing-address",
  "Magento_Checkout/js/view/payment/default",
  "Magento_Ui/js/model/messageList",
  "mage/translate",
  "Magento_Checkout/js/checkout-data",
  "Magento_Customer/js/model/customer",
], function (
  $,
  urlBuilder,
  quote,
  BillingAddress,
  paymentDefault,
  messageList,
  $t,
  checkoutData,
  customer
) {
  ;(function () {
    // The guest's remembered email, or null. checkout-data throws before Magento's
    // customer-data storage is ready (a brand-new visitor), so it is read guardedly.
    function rememberedEmail() {
      try {
        return (!customer.isLoggedIn() && checkoutData.getInputFieldEmailValue()) || null
      } catch (e) {
        return null
      }
    }

    // The guest's email: validated by Magento, or remembered from an earlier visit.
    function guestEmail() {
      if (customer.isLoggedIn()) {
        return null
      }
      return quote.guestEmail || rememberedEmail()
    }

    // A returning guest's email is remembered in the form but Magento sets
    // quote.guestEmail only once the field is validated again. Magento's own save of the
    // payment method at load (the sales-rule mixin) needs it, so it is set here first.
    if (!customer.isLoggedIn() && !quote.guestEmail && rememberedEmail()) {
      quote.guestEmail = rememberedEmail()
    }

    // Function to check if Briqpay is selected
    function isBriqpaySelected() {
      return $('input[name="payment[method]"]:checked').val() === "briqpay"
    }

    function isOnPaymentPage() {
      return window.location.hash === "#payment"
    }

    function isOnShippingPage() {
      return window.location.hash === "#shipping"
    }

    // Function to validate only Briqpay's T&C checkboxes
    function validateBriqpayTermsAndConditions() {
      var isValid = true
      var briqpaySection = $("div.payment-method._active") // Target the active payment method
      var tcEnabled = window.checkoutConfig.payment?.briqpay
        ?.terms_conditions_enabled
        ? window.checkoutConfig.payment.briqpay.terms_conditions_enabled
        : false
      if (
        tcEnabled &&
        briqpaySection.length &&
        briqpaySection.find(".checkout-agreements-block").length
      ) {
        var briqpayAgreements = briqpaySection.find(
          '.checkout-agreements-block input[type="checkbox"]'
        )

        // Validate Briqpay's agreement checkboxes
        briqpayAgreements.each(function () {
          var $checkbox = $(this)

          if (!$checkbox.is(":checked")) {
            isValid = false

            // Trigger native Magento 2 validation
            $checkbox.addClass("mage-error")
            $checkbox.validation()
            $checkbox.valid()
          } else {
            // Remove error class and hide message if correct
            $checkbox.removeClass("mage-error")
            $checkbox
              .closest(".checkout-agreement")
              .find(".validation-advice")
              .remove() // Hide the validation message
          }
        })
      }

      return isValid
    }

    // Shows the hint beside the billing form of the active payment method, where the
    // customer looks; Magento's message area is hidden behind the Briqpay checkout.
    function showBillingHint() {
      var text = $t("Enter the billing address and click Update before paying.")
      var form = $(".payment-method._active .billing-address-form").first()
      if ($(".briqpay-billing-hint").length) {
        return
      }
      var hint = $('<div class="message message-error error briqpay-billing-hint" role="alert"></div>').text(text)
      if (form.length) {
        form.before(hint)
      } else {
        messageList.addErrorMessage({ message: text })
      }
    }

    // Function to execute custom Briqpay logic
    var subscribed = false
    function executeBriqpayLogic() {
      window._briqpay.v3.onReady(function () {
        try {
          paymentLock = null
          syncPaymentLock()
          // onReady can run more than once (another payment method selected, then
          // Briqpay again); the handlers below are subscribed only once.
          if (subscribed) {
            return
          }
          subscribed = true
          if (window.checkoutConfig.payment?.briqpay?.briqpay_overlay) {
            _briqpay.subscribe("paymentProcessStarted", function () {
              const briqpay = document.getElementById("briqpay-checkout-window")
              if (briqpay) {
                briqpay.style.position = "relative"
                briqpay.style.zIndex = "9999"
              }

              // Set styles on #briqpay-overlay
              const overlay = document.getElementById("briqpay-overlay")
              if (overlay) {
                overlay.style.display = "block"
                overlay.style.top = "0"
                overlay.style.left = "0"
                overlay.style.width = "100%"
                overlay.style.height = "100%"
                overlay.style.position = "absolute"
                overlay.style.background = "white"
                overlay.style.opacity = "0.8" 
                overlay.style.zIndex = "9998"
              }
            })
            _briqpay.subscribe("paymentProcessCancelled", function () {
              // Revert the styles paymentProcessStarted set on the checkout window
              const briqpay = document.getElementById("briqpay-checkout-window")
              if (briqpay) {
                briqpay.style.position = ""
                briqpay.style.zIndex = ""
              }

              // Hide and reset styles on #briqpay-overlay
              const overlay = document.getElementById("briqpay-overlay")
              if (overlay) {
                overlay.style.display = "none"
                overlay.style.top = ""
                overlay.style.left = ""
                overlay.style.width = ""
                overlay.style.height = ""
                overlay.style.position = ""
                overlay.style.background = ""
                overlay.style.opacity = ""
                overlay.style.zIndex = ""
              }
            })
          }

          // The customer left Briqpay's payment flow without paying: the session may
          // be refreshed again.
          _briqpay.subscribe("paymentProcessCancelled", function () {
            window.briqpayDecisionInFlight = false
            // This tab's approved payment ended: changes made meanwhile reach the
            // session before the next try.
            if (window.briqpayCheckout) {
              window.briqpayCheckout.paymentEnded(null)
            }
          })

          _briqpay.subscribe("make_decision", async function (data) {
            if (window.checkoutConfig.payment?.briqpay?.customDecisionLogic) {
              const promiseForResponse = new Promise((resolve) => {
                document.addEventListener(
                  "briqpayDecisionResponse",
                  function (e) {
                    resolve(e.detail) // Resolve with the event detail
                  },
                  { once: true }
                )
              })

              const event = new CustomEvent("briqpayDecision", {
                detail: { data: data },
              })
              document.dispatchEvent(event)

              const customDecisionResponse = await Promise.race([
                promiseForResponse,
                new Promise((resolve) =>
                  setTimeout(() => {
                    resolve({ decision: true })
                  }, 10000)
                ),
              ])

              if (!customDecisionResponse.decision) {
                window._briqpay.v3.resumeDecision()
                return // Exit early
              }
            }

            if (!isBriqpaySelected()) {
              window._briqpay.v3.resumeDecision() // Resume decision process
              return
            }

            // Execute validation outside the event listener
            if (!validateBriqpayTermsAndConditions()) {
              window._briqpay.v3.resumeDecision() // Resume decision process
              return // Exit early if T&C validation fails
            }

            // Use urlBuilder to construct the correct endpoint URL
            var endpointUrl = urlBuilder.build("briqpay/decision")
            // From here until the payment ends, the session is the one being paid:
            // nothing refreshes or replaces it meanwhile.
            window.briqpayDecisionInFlight = true

            // Nothing is saved or refreshed while the purchase is being decided.
            // The guest's email goes in the body, never in the URL.
            $.ajax({
              url: endpointUrl,
              method: "POST",
              contentType: "application/json",
              data: JSON.stringify(
                $.extend({}, data, {
                  // Validated by Magento, or remembered from an earlier visit.
                  guestEmail: guestEmail(),
                  // This tab's earlier approval on the session, if any.
                  approval: window.briqpayCheckout ? window.briqpayCheckout.approvalFor(data.sessionId) : null,
                })
              ),
              success: function (response) {
                // The shop's token for the purchase it may have marked on the session
                // (also when Briqpay's answer to the approval was lost).
                if (response && response.approval && window.briqpayCheckout) {
                  window.briqpayCheckout.paymentApproved(data.sessionId, response.approval)
                  // The shop holds the session for the payment 15 minutes at most; then
                  // the page asks whether it still runs.
                  setTimeout(askAgain, 15 * 60 * 1000 + 1000)
                }
                window._briqpay.v3.resumeDecision()
                // A refused purchase: the session is brought in line with the cart
                // (or replaced by the cart's current one) so the next try can pass.
                // Anything but an approval (a refusal, an error answer) ends the attempt.
                if (response && response.message) {
                  // Briqpay's window only says to try again; the shop says why.
                  messageList.addErrorMessage({ message: response.message })
                }
                if (!response || response.decision !== true) {
                  window.briqpayDecisionInFlight = false
                  // The shop ended this tab's earlier approval on the session, if any,
                  // while deciding; the session is brought up to date for the next try.
                  if (window.briqpayCheckout) {
                    window.briqpayCheckout.refreshSession()
                  }
                }
              },
              error: function (error) {
                console.error("Error making decision:", error && error.status)
                window.briqpayDecisionInFlight = false
                window._briqpay.v3.resumeDecision()
              },
            })
          })
        } catch (error) {
          console.error("Error executing Briqpay function:", error)
        }
      })
    }

    // The payment module is open exactly while Magento has a billing address for the
    // quote and nothing is still saving it. That is the rule Magento's own Place Order
    // button follows (Magento_Checkout/js/view/payment/default.js): the address is set
    // on "Update" or when a saved one is selected, and cleared while its form is open
    // for editing, for physical and virtual carts alike. Nothing else decides it.
    function billingAddressReady() {
      var checkout = window.briqpayCheckout
      return !!quote.billingAddress() && !(checkout && checkout.busy())
    }

    // The lock applies only while the payment window is rendered (not before it has
    // rendered, nor while another payment method is selected). Each render fires
    // onReady, which syncs the lock again.
    function paymentModuleMounted() {
      return !!document.querySelector("iframe#briqpay-iframe, iframe.briqpay-iframe")
    }

    // What the module was last told, or null while that is unknown: before it has
    // rendered, and whenever it renders anew. An unknown state is always set afresh,
    // both ways.
    var paymentLock = null
    // Another tab may be paying the session: the page asks the shop whether a payment
    // is running before it locks the window. Every session answer says so, and the page
    // asks again when the tab is shown again or the window gets the focus back. An
    // answer is reused for a short time only; without one the page asks first and
    // locks on the answer.
    var RUNNING_ANSWER_MS = 10000
    var NOT_RUNNING_ANSWER_MS = 3000
    var runningQuestion = null
    // Set when the answer under way may predate the tab being shown again.
    var runningQuestionStale = false
    function knownRunning() {
      var known = window.briqpayRunning
      if (!known || Date.now() - known.at > (known.running ? RUNNING_ANSWER_MS : NOT_RUNNING_ANSWER_MS)) {
        return null
      }
      return known.running
    }
    function askWhetherPaymentRuns() {
      if (runningQuestion || !isBriqpaySelected() || !paymentModuleMounted()) {
        return
      }
      runningQuestion = $.ajax({
        url: urlBuilder.build("briqpay/index/running"),
        type: "POST",
        contentType: "application/json",
        data: "{}",
      })
        .done(function (response) {
          var running = !!(response && response.paymentRunning)
          window.briqpayRunning = { running: running, at: Date.now() }
          if (running) {
            // Checked again once the answer runs out: the payment may end meanwhile.
            setTimeout(syncPaymentLock, RUNNING_ANSWER_MS + 100)
          } else if (window.briqpayDecisionInFlight) {
            // This page's own approved payment is no longer running (it ended without
            // the page hearing of it, or its mark ran out): the page may update the
            // session and lock it again.
            window.briqpayDecisionInFlight = false
            if (window.briqpayCheckout) {
              window.briqpayCheckout.refreshSession()
            }
          }
        })
        .fail(function () {
          // Unknown: the page locks as it would without the question.
          window.briqpayRunning = { running: false, at: Date.now() }
        })
        .always(function () {
          runningQuestion = null
          if (runningQuestionStale) {
            runningQuestionStale = false
            window.briqpayRunning = null
            askWhetherPaymentRuns()
            return
          }
          syncPaymentLock()
        })
    }
    function askAgain() {
      window.briqpayRunning = null
      // What this page last set may have been changed from another tab: it is set
      // afresh with the answer.
      paymentLock = null
      if (runningQuestion) {
        runningQuestionStale = true
      }
      askWhetherPaymentRuns()
    }
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) {
        askAgain()
      }
    })
    // Focus moves into Briqpay's own window and back on every click there; focus coming
    // back after the window lost it to something other than that asks again (a switch
    // between tabs is caught by visibilitychange). The short life of a "not running"
    // answer covers what this misses.
    var leftThePage = false
    window.addEventListener("blur", function () {
      var active = document.activeElement
      leftThePage = !(active && active.tagName === "IFRAME")
    })
    window.addEventListener("focus", function () {
      if (leftThePage) {
        leftThePage = false
        askAgain()
      }
    })

    function syncPaymentLock() {
      if (!window._briqpay || !window._briqpay.v3 || !isBriqpaySelected()) {
        return
      }
      var running = knownRunning()
      // A purchase on the session is being paid (here, or in another tab): the window
      // is not locked meanwhile.
      var ready = billingAddressReady() || running === true || !!window.briqpayDecisionInFlight
      if (!paymentModuleMounted()) {
        paymentLock = null
      } else if (window.briqpaySwapPending) {
        // The window of the session being left: the new one syncs itself once rendered.
      } else if (!ready && paymentLock !== true) {
        if (running === false) {
          window._briqpay.v3.lockModule("payment", true)
          paymentLock = true
        } else {
          askWhetherPaymentRuns()
        }
      } else if (ready && paymentLock !== false) {
        window._briqpay.v3.unlockModule("payment")
        paymentLock = false
      }
      if (ready) {
        $(".briqpay-billing-hint").remove()
      } else if (!quote.billingAddress()) {
        showBillingHint()
      }
    }

    quote.billingAddress.subscribe(syncPaymentLock)
    document.addEventListener("briqpay:busy", syncPaymentLock)
    $(document).on("change", 'input[name="payment[method]"]', syncPaymentLock)
    window.addEventListener("hashchange", syncPaymentLock)

    // Listen for changes to the payment method
    $('input[name="payment[method]"]').on("change", function () {
      if (isBriqpaySelected()) {
        validateBriqpayTermsAndConditions() // Validate when Briqpay is selected
      }
    })

    // Handle updates to billing address form fields
    $(document).on(
      "change",
      "#co-billing-form input, #co-billing-form select",
      function () {
        if (isBriqpaySelected()) {
          validateBriqpayTermsAndConditions() // Validate whenever billing address form is updated
        }
      }
    )

    // Initial trigger for when the page loads and Briqpay is selected by default
    if (isBriqpaySelected()) {
      validateBriqpayTermsAndConditions()
    }

    // Check if the Briqpay script is present and then set the onload event
    var scripts = document.getElementsByTagName("script")
    var briqpayScriptTag

    for (var i = 0; i < scripts.length; i++) {
      if (scripts[i].src === "https://api.briqpay.com/briq.min.js") {
        briqpayScriptTag = scripts[i]
        break
      }
    }

    if (briqpayScriptTag) {
      if (window._briqpay && window._briqpay.v3) {
        executeBriqpayLogic()
      } else {
        briqpayScriptTag.onload = executeBriqpayLogic
      }
    } else {
      console.error("Briqpay script tag not found.")
    }
  })()
})
