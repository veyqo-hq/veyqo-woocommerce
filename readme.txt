=== Veyqo Payments for WooCommerce ===
Contributors: veyqo
Tags: payments, paystack, flutterwave, nigeria, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: MIT

Take WooCommerce payments through Paystack, Flutterwave, Stripe and more, with one integration, smart routing and failover.

== Description ==

Veyqo connects your store to your own payment provider accounts. Customers pay
on the provider's secure page (card, bank transfer, USSD). Your money settles
directly from the provider to you; Veyqo never holds it.

* Routing: send each payment to the best provider, cheapest first, with failover.
* Orders are marked paid only after Veyqo confirms the payment server-side.
* Signed webhooks confirm orders even if the customer closes the tab.
* Refunds from the WooCommerce order screen.

== Installation ==

1. Upload the plugin and activate it.
2. WooCommerce → Settings → Payments → Veyqo: paste your test secret key.
3. In Veyqo → Webhooks, add the URL shown in the settings, subscribed to
   payment.succeeded and payment.failed. Paste its signing secret.
4. Place a test order. When it works, add your live key and turn test mode off.

== Changelog ==

= 0.1.0 =
* First release.
