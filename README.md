# Caravel Pay for Easy Digital Downloads

- Docs: <https://caravelpay.app/docs/integrations#edd>
- Download and checksums: <https://caravelpay.app/plugins/#edd>
- Status: beta. It passes our tests against a stand-in of the platform and has not yet run on a production install. Tell us if anything looks off.
- Questions: [hello@caravelpay.app](mailto:hello@caravelpay.app)

A payment gateway for **Easy Digital Downloads 3.x** (WordPress 6.2+, PHP 7.4+). Buyers pay by card, Apple Pay, Google Pay or USDC on the hosted checkout. Each payment goes to your own Polygon wallet, minus the 2% Caravel fee, in the token the buyer paid with (card payments arrive as USDC).

## Install

1. Download `caravel-pay-edd.zip`.
2. In WordPress, go to **Plugins › Add New Plugin › Upload Plugin**, choose the zip and click **Install Now**. Then click **Activate**.
3. Go to **Downloads › Settings › Payments › Caravel Pay**, paste your **Payout wallet (Polygon)** and click **Save Changes**. A notice confirms *Caravel Pay is connected as mch_…*.
4. Under **Downloads › Settings › Payments › General**, tick **Caravel Pay** in **Active Gateways**.

Requirements: a store currency Caravel Pay supports (**US dollars**, or EUR, GBP, CAD, AUD, SEK, NOK, DKK, CHF, PLN, CZK, JPY and more: those orders are converted to US dollars when the buyer pays), and a site served over **HTTPS**. Also allow `POST` requests to `/wp-json/caravel-pay-edd/v1/webhook`: check your security plugin, firewall or page cache.

## How orders update

At checkout the buyer chooses Caravel Pay. EDD creates a **Pending** order and the buyer is sent to the hosted checkout. When Caravel Pay sends a webhook, the plugin verifies it and **re-fetches the checkout** from the API before changing the order:

| Checkout | EDD order |
|---|---|
| `paid` | **Complete**. The transaction id is the payout tx hash, and a note gives the payout and an explorer link |
| `underpaid` | **On Hold** (Pending if your EDD has no On Hold status), with a note showing how much arrived |
| `review` | **On Hold** / Pending, with a note |
| `expired` | **Failed**, with a note. A payment found later still completes the order |

The confirmation page also checks a pending order with one signed request, so the buyer sees *Complete* even before the webhook arrives. Gateway problems are written to **Downloads › Tools › Logs** (payment errors, and the debug log when enabled).

## Switching from PayGate

PayGate's EDD gateway can stay active while you switch. If it is configured, the Caravel Pay settings show the Polygon wallet it pays out to, so you can paste the same one. Nothing is copied or changed automatically. Test with a small real order (there is no test mode), then untick PayGate under Active Gateways.

There is no "fees tolerance" setting. A short payment is reported as `underpaid` and the order is held, never completed.

## Notes

- **Changing your wallet:** paste the new one and save. New keys are created for it. Checkouts already started finish on the old wallet, and their webhooks still verify for 7 days.
- **Who pays the fees:** the **Who pays the fees** setting under Downloads › Settings › Payments › Caravel Pay. *Seller pays everything* (default): your buyer pays the order total and both fees come off what you receive. *Split: buyer pays the card fee*: the card provider adds its fee to your buyer's total and you pay the 2% Caravel fee. *Buyer pays everything*: the 2% Caravel fee is added to your buyer's total as well, so you receive the full order total. Buyers see a line about it before they pay. Card surcharges are restricted in some countries (for example on consumer cards in the EU and the UK): check your local rules first.
- **Staging:** the `caravel_pay_edd_gateway_config` filter (or a `CARAVEL_PAY_API_BASE` constant) sets another API host.
- **Data:** the plugin keeps its credentials and checkout state in `{prefix}caravelpay_edd_*` tables.

## Download

- [Latest release](https://github.com/caravelpay/caravel-pay-edd/releases/latest): `caravel-pay-edd.zip`, with `SHA256SUMS`.
- The same file is at <https://caravelpay.app/plugins/#edd>, and its checksum is in <https://caravelpay.app/download/SHA256SUMS>.

## Docs and support

- Guide: <https://caravelpay.app/docs/integrations#edd>
- API reference: <https://caravelpay.app/docs/>
- Questions and bug reports: [hello@caravelpay.app](mailto:hello@caravelpay.app), or open an issue here.

## Licence

GPL-2.0-or-later. See `license.txt`.
