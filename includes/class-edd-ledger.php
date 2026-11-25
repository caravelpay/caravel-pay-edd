<?php
/**
 * Easy Digital Downloads side of the payment flow.
 *
 * @package CaravelPayEdd
 */

namespace CaravelPay\Edd;

use CaravelPay\Amount;
use CaravelPay\Checkout;
use CaravelPay\Kit\Invoice;
use CaravelPay\Kit\Ledger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * | Checkout status | EDD order                                                        |
 * |-----------------|------------------------------------------------------------------|
 * | paid            | Complete, transaction id = payout tx hash, note with the payout  |
 * | underpaid       | On hold (when the status exists, else stays Pending) + note      |
 * | review          | On hold (when the status exists, else stays Pending) + note      |
 * | expired         | Failed + note (a late payment still completes it)                |
 */
final class EddLedger implements Ledger
{
    const GATEWAY = 'caravel_pay';

    public function invoice(string $ref): ?Invoice
    {
        $order = preg_match('/^[1-9][0-9]{0,18}$/D', $ref) ? edd_get_order((int) $ref) : null;
        if (!$order || self::GATEWAY !== $order->gateway) {
            return null;
        }
        $platform = (string) $order->status;
        if (in_array($platform, array('pending', 'failed', 'abandoned', 'on_hold'), true)) {
            $status = Invoice::UNPAID;
        } elseif (in_array($platform, array('complete', 'publish', 'partially_refunded'), true)) {
            $status = Invoice::PAID;
        } else {
            $status = Invoice::OTHER;
        }
        try {
            $total = Amount::normalize((string) $order->total);
        } catch (\InvalidArgumentException $e) {
            $total = '0.00';
        }
        // No US dollar amount for an order in another currency: it is sent as it is, and Caravel Pay converts it.
        $currency = strtoupper((string) $order->currency);
        return new Invoice($ref, $status, $total, $currency, 'USD' === $currency ? $total : '', (string) $order->email, $platform);
    }

    public function recordPayment(Invoice $invoice, array $checkout, string $txHash, string $nativeAmount, string $fee): bool
    {
        $id = (int) $invoice->ref;
        // The checkout was created for the order's own amount and currency; both must still be the order's.
        $charged = isset($checkout['currency']) && is_string($checkout['currency']) ? $checkout['currency'] : 'USD';
        if (!Amount::equals($nativeAmount, $invoice->nativeAmount) || $charged !== $invoice->nativeCurrency) {
            $this->note($id, sprintf('Caravel Pay received payment for %s ' . $charged . ', but the order total is now %s %s. The order was put on hold instead of being completed; check it before delivering.', $nativeAmount, $invoice->nativeAmount, $invoice->nativeCurrency));
            $this->hold($id);
            return true;
        }
        edd_set_payment_transaction_id($id, $txHash);
        $this->note($id, sprintf('Caravel Pay payment confirmed (checkout %s). %s', $checkout['id'], self::payoutNote($checkout)));
        edd_update_payment_status($id, 'complete');
        $order = edd_get_order($id);
        return $order && in_array($order->status, array('complete', 'publish'), true);
    }

    public function flag(Invoice $invoice, string $status, string $message): void
    {
        $id = (int) $invoice->ref;
        $this->note($id, $message);
        if (in_array($status, array('underpaid', 'review'), true) && Invoice::UNPAID === $invoice->status) {
            $this->hold($id);
        } elseif ('expired' === $status && 'pending' === $invoice->platformStatus) {
            edd_update_payment_status($id, 'failed');
        }
    }

    public function log(string $level, string $message, array $context = array()): void
    {
        if (function_exists('edd_debug_log')) {
            edd_debug_log('Caravel Pay: ' . $message);
        }
        if ('error' === $level && function_exists('edd_record_gateway_error')) {
            edd_record_gateway_error('Caravel Pay', $message);
        }
    }

    private function note(int $id, string $message): void
    {
        edd_insert_payment_note($id, $message);
    }

    /** On hold where EDD has that status; otherwise the order stays Pending (the note explains). */
    private function hold(int $id): void
    {
        $statuses = function_exists('edd_get_payment_statuses') ? edd_get_payment_statuses() : array();
        if (isset($statuses['on_hold'])) {
            edd_update_payment_status($id, 'on_hold');
        }
    }

    private static function payoutNote(array $checkout): string
    {
        $parts = array();
        foreach (Checkout::payouts($checkout) as $payout) {
            $parts[] = $payout['amount'] . ' ' . $payout['asset'] . ', ' . Checkout::explorerUrl($payout['tx_hash']);
        }
        return $parts ? 'Payout: ' . implode('; ', $parts) . '.' : '';
    }
}
