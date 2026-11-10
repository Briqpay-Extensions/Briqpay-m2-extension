define([
  "Magento_Checkout/js/view/payment/default",
  "jquery",
  "mage/url",
  "ko",
  "Magento_Checkout/js/model/quote",
  "Magento_Checkout/js/model/totals",
  "Magento_Checkout/js/model/payment/method-list",
  "Magento_Checkout/js/model/payment/additional-validators",
  "Magento_Checkout/js/action/set-billing-address",
  "Magento_Ui/js/model/messageList",
  "Magento_Customer/js/model/customer",
  "Magento_Checkout/js/checkout-data",
  "mage/translate",
], function (
  Component,
  $,
  urlBuilder,
  ko,
  quote,
  totals,
  paymentMethodList,
  additionalValidators,
  setBillingAddressAction,
  messageList,
  customer,
  checkoutData,
  $t
) {
  "use strict"

  // Per-tab memory of this tab's own approved payment. It lives in the page and is
  // parked in sessionStorage only while the page unloads, so a reload of the tab keeps
  // it but a duplicated tab (browsers copy sessionStorage) does not inherit it.
  var APPROVED_KEY = "briqpay-approved-session"
  var ENDED_KEY = "briqpay-ended-session"
  var TAB_KEYS = [APPROVED_KEY, ENDED_KEY]
  var tabMemory = {}
  try {
    TAB_KEYS.forEach(function (key) {
      tabMemory[key] = window.sessionStorage.getItem(key) || null
      window.sessionStorage.removeItem(key)
    })
  } catch (e) {
    // No storage: kept in the page only.
  }
  window.addEventListener("pagehide", function () {
    try {
      TAB_KEYS.forEach(function (key) {
        if (tabMemory[key]) {
          window.sessionStorage.setItem(key, tabMemory[key])
        } else {
          window.sessionStorage.removeItem(key)
        }
      })
    } catch (e) {
      // No storage: lost with the page.
    }
  })
  // A page restored from the back/forward cache does not run this module again: what
  // was parked is taken back, so a tab duplicated afterwards does not inherit it.
  window.addEventListener("pageshow", function (event) {
    if (!event.persisted) {
      return
    }
    try {
      TAB_KEYS.forEach(function (key) {
        tabMemory[key] = window.sessionStorage.getItem(key) || tabMemory[key] || null
        window.sessionStorage.removeItem(key)
      })
    } catch (e) {
      // No storage: the page's own memory stands.
    }
  })
  function tabRead(key) {
    return tabMemory[key] || null
  }
  function tabStore(key, value) {
    tabMemory[key] = value || null
  }

  // The guest's email: validated by Magento, or remembered from an earlier visit and
  // not touched since (then quote.guestEmail is still unset). checkout-data throws
  // before Magento's customer-data storage is ready, so it is read guardedly.
  function guestEmail() {
    if (customer.isLoggedIn()) {
      return null
    }
    try {
      return quote.guestEmail || checkoutData.getInputFieldEmailValue() || null
    } catch (e) {
      return quote.guestEmail || null
    }
  }

  return Component.extend({
    defaults: {
      template: "Briqpay_Payments/payment/briqpay",
    },

    initialize: function () {
      var self = this
      if (!quote.guestEmail && guestEmail()) {
        quote.guestEmail = guestEmail()
      }
      this._super()
      this.message = ko.observable($t("Loading the payment window..."))
      this.lastSavedBillingAddress = null
      this.billingSave = null
      this.billingSaving = null
      this.sessionInFlight = null
      this.sessionQueued = null
      this.sessionQueuedPromise = null
      this.busyCount = 0
      // briqpay.js keeps the payment module locked while a save is under way.
      window.briqpayCheckout = {
        busy: function () {
          return self.busyCount > 0
        },
        refreshSession: this.refreshSession.bind(this),
        // Briqpay approved a purchase this tab started on the session; the shop's
        // approval identifies it ("session|approval").
        paymentApproved: function (sessionId, approval) {
          tabStore(APPROVED_KEY, approval ? sessionId + "|" + approval : null)
        },
        // The approval this tab got for a purchase on the session, sent with its next
        // try there: only this tab may try again while that payment may be running.
        approvalFor: function (sessionId) {
          // The approval just reported as ended counts too, until the shop recorded it.
          var approved = (tabRead(APPROVED_KEY) || tabRead(ENDED_KEY) || "").split("|")
          return approved[0] === sessionId && approved[1] ? approved[1] : null
        },
        // A payment ended without an order (cancelled). Only the approval this tab got
        // is reported: the session then no longer waits for it and is brought up to
        // date. Another tab's payment on the same session is never ended from here.
        paymentEnded: function (sessionId) {
          var approved = tabRead(APPROVED_KEY)
          if (approved && (!sessionId || approved.split("|")[0] === sessionId)) {
            tabStore(APPROVED_KEY, null)
            tabStore(ENDED_KEY, approved)
          }
          return self.refreshSession()
        },
      }
      // A virtual cart has no shipping step, where Magento saves the addresses, and
      // its billing form only saves on "Update": the address the customer already has
      // selected is saved first, so Briqpay and the order get it.
      $.when(this.saveBillingAddress()).always(function () {
        self.loadMessage()
      })
      this.qouteChange()
      // Retrieve the title from configuration
      this.title = window.checkoutConfig.payment?.briqpay?.title
        ? window.checkoutConfig.payment.briqpay.title
        : "Payment Methods"
      this.hasMultiplePaymentMethods = ko.computed(function () {
        // Check the length of active payment methods
        return paymentMethodList().length > 1
      }, this)
      return this
    },

    loadMessage: function () {
      var self = this
      // A session answer renders itself (adoptSession); only a failure is shown here,
      // and it leaves no session rendered, so the next answer renders again.
      this.refreshSession()
        .done(function (response) {
          if (!response || !response.sessionId) {
            self.renderedSessionId = null
            self.message(self.loadFailedText())
          }
        })
        .fail(function () {
          self.renderedSessionId = null
          self.message(self.loadFailedText())
        })
    },

    // Shown in place of the payment window when no session could be loaded; plain
    // text, never anything the server sent.
    loadFailedText: function () {
      return $("<div/>")
        .text($t("The payment window could not be loaded. Reload the page or choose another payment method."))
        .prop("outerHTML")
    },

    /**
     * Renders the payment window for the session the answer names, unless that is the
     * one on the page. The shop starts a new session for the cart when it must (a
     * changed company, a cart Briqpay no longer knows), and a purchase decided against
     * a session that is no longer the cart's would be refused. Briqpay's script picks
     * the new window up and fires onReady again, which syncs the lock.
     */
    adoptSession: function (response) {
      if (!response || !response.sessionId || !response.message) {
        return
      }
      // Whether a payment runs on this session (here or in another tab): the page never
      // locks one that does.
      window.briqpayRunning = { running: !!response.paymentRunning, at: Date.now() }
      if (response.sessionId !== this.renderedSessionId) {
        var self = this
        var unlocked = false
        // The window is unlocked before the page switches to another session.
        if (window._briqpay && window._briqpay.v3
          && document.querySelector("iframe#briqpay-iframe, iframe.briqpay-iframe")) {
          try {
            window._briqpay.v3.unlockModule("payment")
            unlocked = true
          } catch (e) {
            // No module to unlock.
          }
        }
        this.renderedSessionId = response.sessionId
        // The new window is rendered shortly after the unlock.
        if (unlocked) {
          // Nothing is locked meanwhile.
          window.briqpaySwapPending = true
          setTimeout(function () {
            if (self.renderedSessionId === response.sessionId) {
              window.briqpaySwapPending = false
              self.message(response.message)
            }
          }, 300)
        } else {
          window.briqpaySwapPending = false
          this.message(response.message)
        }
      }
    },

    /**
     * Asks the server for the quote's Briqpay session (created or updated). One request
     * runs at a time; calls made meanwhile share a single follow-up request that starts
     * when the running one ends, so the session always ends up matching the quote and
     * two requests never create two sessions.
     */
    refreshSession: function () {
      var self = this
      // A payment is running on the session: it is left unchanged until the payment
      // ends.
      if (window.briqpayDecisionInFlight) {
        return $.Deferred().resolve({}).promise()
      }
      // Magento refreshes the totals (which asks for a refresh here) as soon as an
      // address is selected, before its save has finished: the session is read from the
      // quote, so the refresh waits for the save.
      if (this.billingSaving && this.billingSaving.state && this.billingSaving.state() === "pending") {
        var afterSave = $.Deferred()
        this.billingSaving.always(function () {
          self.refreshSession().then(afterSave.resolve, afterSave.reject)
        })
        return afterSave.promise()
      }
      if (this.sessionInFlight) {
        if (!this.sessionQueued) {
          this.sessionQueued = $.Deferred()
          this.sessionQueuedPromise = this.whileBusy(this.sessionQueued.promise())
        }
        return this.sessionQueuedPromise
      }
      var request = function () {
        // Sent with every request until the shop says it recorded it.
        var paymentEnded = tabRead(ENDED_KEY)
        return $.ajax({
          url: urlBuilder.build("briqpay/index/session"),
          type: "POST",
          contentType: "application/json",
          // The guest's email goes in the body, never in the URL.
          data: JSON.stringify({ guestEmail: guestEmail(), paymentEnded: paymentEnded }),
        })
      }
      this.sessionInFlight = this.whileBusy(request().done(function (response) {
        if (response && response.paymentEnded === true) {
          tabStore(ENDED_KEY, null)
        }
        self.adoptSession(response)
      })).always(function () {
        self.sessionInFlight = null
        var queued = self.sessionQueued
        if (queued) {
          self.sessionQueued = null
          self.sessionQueuedPromise = null
          self.refreshSession().then(queued.resolve, queued.reject)
        }
      })
      return this.sessionInFlight
    },

    briqpayTitle: function () {
      return this.hasMultiplePaymentMethods() ? this.title : ""
    },

    /**
     * Marks a save as under way until the promise settles, and tells briqpay.js.
     */
    whileBusy: function (promise) {
      var self = this
      this.busyCount++
      document.dispatchEvent(new CustomEvent("briqpay:busy"))
      $.when(promise).always(function () {
        self.busyCount--
        document.dispatchEvent(new CustomEvent("briqpay:busy"))
      })
      return promise
    },

    /**
     * Saves the selected billing address to the quote, once per address. Resolves at
     * once when there is nothing to save. Magento's own "Update" saves it for a form; a
     * saved address selected from the list is not saved by Magento until the order is
     * placed, so it is saved here, and the session refresh waits for it.
     */
    saveBillingAddress: function () {
      var address = quote.billingAddress()
      if (!address) {
        return $.Deferred().resolve().promise()
      }
      // A guest's address is saved with the email, which Magento requires; before the
      // customer has entered it, nothing is saved yet.
      if (!customer.isLoggedIn()) {
        if (!guestEmail()) {
          return $.Deferred().resolve().promise()
        }
        address.email = guestEmail()
      }
      var key = JSON.stringify(address)
      if (key === this.lastSavedBillingAddress) {
        return this.billingSave || $.Deferred().resolve().promise()
      }
      this.lastSavedBillingAddress = key
      // The save, then the session refresh with the saved address: Magento's totals
      // event, which also asks for a refresh, can arrive after the save, and a decision
      // in between would compare the session before its update.
      var self = this
      var done = $.Deferred()
      this.billingSaving = setBillingAddressAction(messageList)
      $.when(this.billingSaving).always(function () {
        $.when(self.refreshSession()).always(function () {
          done.resolve()
        })
      })
      this.billingSave = this.whileBusy(done.promise())
      return this.billingSave
    },

    qouteChange: function () {
      var self = this
      var initialTotalsSet = false

      // A newly selected billing address on a virtual cart is saved; the totals it
      // changes then refresh the Briqpay session below.
      quote.billingAddress.subscribe(function () {
        self.saveBillingAddress()
      })

      // A guest's email typed or changed after the address was saved: Magento sets
      // quote.guestEmail as the field is left, nothing else announces it. Saved then,
      // with the module locked meanwhile; a purchase compares what was saved.
      $(document).on("change blur", "#customer-email", function () {
        setTimeout(function () {
          self.saveBillingAddress()
        }, 0)
      })

      // Observe changes to the totals object
      totals.totals.subscribe(function () {
        if (initialTotalsSet) {
          //Suspend the iframe
          if (window._briqpay && window._briqpay.v3) {
            window._briqpay.v3.suspend()
          }
          self.refreshSession().always(function () {
            if (window._briqpay && window._briqpay.v3) {
              window._briqpay.v3.resume()
            }
          })
        } else {
          initialTotalsSet = true
        }
      })
    },
  })
})
