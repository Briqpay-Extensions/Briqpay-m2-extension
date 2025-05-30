require([
  "jquery",
  "mage/url",
  "Magento_Checkout/js/model/quote",
  "Magento_Checkout/js/view/billing-address",
  "Magento_Checkout/js/view/payment/default",
], function ($, urlBuilder, quote, BillingAddress) {
  ;(function () {
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

    // Function to execute custom Briqpay logic
    function executeBriqpayLogic() {
      window._briqpay.v3.onReady(function () {
        try {
          window._briqpay.v3.unlockModule("payment")
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
              // Revert styles on #briqpay
              const briqpay = document.getElementById("briqpay")
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
            var guestEmailEncoded = btoa(quote.guestEmail)
            var endpointUrl = urlBuilder.build(
              "briqpay/decision?hash=" + guestEmailEncoded
            )

            // Make AJAX POST call to your new endpoint
            $.ajax({
              url: endpointUrl,
              method: "POST",
              contentType: "application/json",
              data: JSON.stringify(data),
              success: function (response) {
                window._briqpay.v3.resumeDecision()
              },
              error: function (error) {
                console.error("Error making decision:", error)
                window._briqpay.v3.resumeDecision()
              },
            })
          })
        } catch (error) {
          console.error("Error executing Briqpay function:", error)
        }
      })
    }

    // Handle changes to the billing address view model
    var billingAddressViewModel = BillingAddress()
    billingAddressViewModel.isAddressSameAsShipping.subscribe(function (
      isSameAsShipping
    ) {
      if (!isSameAsShipping && isOnPaymentPage()) {
        // Billing address is shown, lock the Briqpay module
        if (isBriqpaySelected()) {
          if (window._briqpay && window._briqpay.v3) {
            window._briqpay.v3.lockModule("payment", true)
          }
        }
      } else {
        // Billing address is hidden, unlock the Briqpay module
        if (isBriqpaySelected()) {
          if (window._briqpay && window._briqpay.v3) {
            window._briqpay.v3.unlockModule("payment")
          }
        }
      }
    })

    var previousHash = window.location.hash

    window.addEventListener("hashchange", function () {
      const currentHash = window.location.hash

      if (previousHash === "#payment" && currentHash === "#shipping") {
        window._briqpay.v3.lockModule("payment", true)
        window._briqpay.v3.unlockModule("payment")
      }

      // Update the previous hash
      previousHash = currentHash
    })

    // Handle billing address updates
    $(document).on(
      "change",
      "#co-billing-form input, #co-billing-form select",
      function () {
        if (isBriqpaySelected()) {
          if (window._briqpay && window._briqpay.v3) {
            window._briqpay.v3.unlockModule("payment")
          }
        }
      }
    )

    // Unlock Briqpay when the update button is clicked
    $(document).on("click", ".action-update", function () {
      if (isBriqpaySelected()) {
        if (window._briqpay && window._briqpay.v3) {
          window._briqpay.v3.unlockModule("payment")
        }
      }
    })

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
