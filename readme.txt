=== Caravel Pay for Easy Digital Downloads ===
Tags: payments, card, apple pay, google pay, usdc
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card, Apple Pay, Google Pay and USDC in Easy Digital Downloads. Payouts go to your own Polygon wallet, minus the 2% Caravel fee.

== Description ==

Buyers pay on a hosted checkout by card, Apple Pay, Google Pay or directly in USDC, USDC.e or POL on Polygon. Each payment is sent to your payout wallet automatically, minus the 2% Caravel fee, in the token the buyer paid with (card payments arrive as USDC). Your buyer pays the order total. Most card providers keep their own fee out of it, the Caravel fee is 2% of the order total, and you receive the rest. There is no account to create: paste your wallet and save.

Requires Easy Digital Downloads 3.x. The store can price in US dollars, or in EUR, GBP, CAD, AUD, SEK, NOK, DKK, CHF, PLN, CZK, JPY and other supported currencies: those orders are converted to US dollars when the buyer pays.

= External service =

This plugin sends order data needed for payment (order reference, amount, buyer email, return and webhook URLs) to the Caravel Pay API at https://api.caravelpay.app when a buyer pays, and your site address, payout wallet and optional contact email when you save the settings. Caravel Pay terms of service: https://caravelpay.app/terms
Caravel Pay privacy policy: https://caravelpay.app/privacy

== Installation ==

1. Upload caravel-pay-edd.zip under Plugins > Add New Plugin > Upload Plugin and activate it.
2. Go to Downloads > Settings > Payments > Caravel Pay, paste your Polygon payout wallet and save. The notice confirms "Caravel Pay is connected".
3. Enable Caravel Pay under Downloads > Settings > Payments > General > Active Gateways.

== Frequently Asked Questions ==

= How do orders update? =

Paid: Complete, with the payout transaction hash as the transaction id. Underpaid or needs review: On Hold (or Pending) with a note. Expired: Failed with a note; a payment found later still completes it. The status always comes from a signed request to the Caravel Pay API, never from the webhook body alone.

= Switching from PayGate? =

If PayGate's EDD gateway is configured, the Caravel Pay settings show the wallet it pays out to, so you can paste the same one. Nothing is copied or changed automatically, and both gateways can stay active while you switch.

== Changelog ==

= 1.2.1 =
* The fee is described the same way everywhere: the 2% Caravel fee. Payments work exactly as in 1.2.0.

= 1.2.0 =
* Stores that price in euros, pounds, kronor and other supported currencies can use Caravel Pay: the order total is converted to US dollars when the buyer pays, and buyers are told at checkout.
* New setting "Exchange-rate buffer" (default 0).

= 1.1.0 =
* New setting "Who pays the fees": Seller pays everything (default), Split: buyer pays the card fee, or Buyer pays everything.

= 1.0.0 =
* First release.
