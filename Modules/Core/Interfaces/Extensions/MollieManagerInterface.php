<?php
declare(strict_types=1);
/**
 * MollieManager Interface
 *
 * Contract for Mollie payment integration: per-site API credentials with a
 * test/live toggle, payment creation, webhook handling, capture and refund,
 * and the local evidence trail a card dispute needs.
 *
 * Extension implementations provide the live API client, idempotent webhook
 * handling and the durable record trail. The default stub provides a no-op
 * surface that reports itself unconfigured — a site without the extension
 * must be unable to take a payment, never able to take one it cannot settle.
 *
 * The shape of this contract is set by two facts about Mollie that an
 * integration gets wrong by default:
 *
 * 1. The webhook body is a single `id` and the classic payment webhook is
 *    unsigned. The authoritative state is only ever what a GET returns, so
 *    every implementation re-fetches and none of them trusts the body.
 * 2. A card chargeback can arrive up to 180 days after the purchase, and the
 *    merchant's defence is records. So the evidence trail is part of the
 *    contract, not an optional extra, and it must outlive a TTL.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

/**
 * Interface MollieManagerInterface
 */
interface MollieManagerInterface extends ModuleInterface
{
    /**
     * Store the API credential for one mode. The key is written to the
     * per-site SECRETS keyspace, never to options, never returned, never
     * logged. Mollie ties an idempotency key to the credential that created
     * it, so rotating a key invalidates in-flight idempotency — rotate
     * between batches, not during one.
     *
     * @param string $mode 'test' or 'live'
     * @return bool False if the mode is unknown or the key fails its shape check
     */
    public function setApiKey(string $siteId, string $mode, string $apiKey): bool;

    /**
     * Which mode this site is transacting in: 'test', 'live', or
     * 'unconfigured' when no key is stored for the selected mode.
     */
    public function getMode(string $siteId): string;

    /**
     * Select the active mode. Refuses 'live' when no live key is stored —
     * a site that believes it is live and is not takes real orders it
     * never charges for.
     */
    public function setMode(string $siteId, string $mode): bool;

    /**
     * A redacted view of what is configured: which modes hold a key, the
     * active mode, and a non-reversible fingerprint per key. NEVER the key.
     */
    public function getCredentialStatus(string $siteId): array;

    /**
     * Prove the credential works by calling Mollie, rather than by having
     * been written. Returns ['ok' => bool, 'mode' => string, 'error' => ?string].
     * A stored key is a claim; a successful call is proof.
     */
    public function verifyCredential(string $siteId): array;

    /**
     * Payment methods actually enabled for this profile and amount — ask
     * Mollie rather than hardcoding a list, because availability depends on
     * amount, currency, locale and the profile's own activation state.
     */
    public function listMethods(string $siteId, array $options = []): array;

    /**
     * Create a payment.
     *
     * $request requires:
     *   'amount'      => ['currency' => 'EUR', 'value' => '10.00'] or
     *                    ['currency' => 'EUR', 'minor' => 1000]
     *                    — a float is REFUSED; binary floats cannot hold money.
     *   'description' => what the cardholder will see on their statement.
     *                    Max 255. This is the single largest lever on
     *                    "I do not recognise this charge" chargebacks, so it
     *                    is required and a configured descriptor is prefixed.
     *   'redirectUrl' => where the customer returns.
     * Optional: 'method', 'locale', 'metadata', 'cancelUrl', 'sequenceType',
     *   'customerId', 'lines', 'billingAddress', 'shippingAddress'.
     *
     * The webhook URL is set by this manager, not the caller.
     * An Idempotency-Key is generated per call unless $request['idempotencyKey']
     * is supplied, so a network retry cannot double-charge.
     *
     * A local record is written BEFORE the checkout URL is returned: a payment
     * we cannot evidence is a payment we cannot defend.
     *
     * @return array ['ok'=>bool,'id'=>?string,'status'=>?string,'checkoutUrl'=>?string,'record'=>?array,'error'=>?string]
     */
    public function createPayment(string $siteId, array $request): array;

    /**
     * Fetch a payment's authoritative state from Mollie. $embed may name
     * 'refunds', 'chargebacks', 'captures'.
     */
    public function getPayment(string $siteId, string $paymentId, array $embed = []): array;

    /**
     * Capture an authorized payment. Capturing at FULFILMENT rather than at
     * authorisation is a documented chargeback control for anything that
     * takes more than 24 hours to fulfil — the customer is charged when they
     * get the thing, so there is nothing to dispute in the gap.
     *
     * @param array|null $amount null captures the full authorized amount
     */
    public function capturePayment(string $siteId, string $paymentId, ?array $amount = null): array;

    /**
     * Refund, fully or partially. A refund costs the fee; a chargeback costs
     * the fee, a dispute fee, the staff time and the scheme's fraud ratio.
     * When a dispute is plausible, refunding first is almost always cheaper —
     * so this is first-class surface, not an afterthought.
     */
    public function refundPayment(string $siteId, string $paymentId, ?array $amount = null, string $description = ''): array;

    /**
     * Handle one webhook delivery.
     *
     * The classic payment webhook body is a single `id` parameter and carries
     * NO signature, so the body is treated as a hint and nothing else: this
     * method re-fetches the payment from the API and acts on that. When a
     * webhook secret is configured, an `X-Mollie-Signature` header is verified
     * (HMAC-SHA256 over the raw body, constant-time compare) and a present but
     * invalid signature is rejected.
     *
     * Mollie retries up to 10 times across 26 hours, so handling is idempotent:
     * the same delivery applied twice leaves one record and fires side effects
     * once. An id we do not recognise returns ok — otherwise Mollie retries for
     * 26 hours over a payment that was never ours — but is counted.
     *
     * @param array  $post    the parsed body
     * @param array  $headers request headers, for signature verification
     * @param string $rawBody the unmodified body; required for HMAC
     * @return array ['ok'=>bool,'status'=>int,'paymentId'=>?string,'applied'=>bool,'reason'=>?string]
     */
    public function handleWebhook(string $siteId, array $post, array $headers = [], string $rawBody = ''): array;

    /** The URL this manager answers webhooks on, as sent to Mollie. */
    /**
     * Split an amount into parts that sum to it exactly.
     *
     * Rounding each share independently loses or invents a unit, and a cent
     * nobody owns is what an accountant finds a year later. Largest remainder,
     * computed by the exact-decimal authority rather than in PHP.
     *
     * @param array<string,mixed> $amount  ["currency"=>"EUR","value"=>"10.00"] or ["currency"=>"EUR","minor"=>1000]
     * @param array<int,int>      $weights non-negative integers, not all zero
     * @return array{ok:bool,parts:array<int,string>|null,error:string|null}
     */
    public function splitAmount(array $amount, array $weights): array;

    public function getWebhookUrl(string $siteId): string;

    /**
     * The local record for one payment: what was bought, what the customer
     * was shown, when, from where, and every status transition with its
     * timestamp. This is chargeback evidence, and a card dispute can arrive
     * up to 180 days after the purchase — which is longer than any TTL in
     * this estate, so these records are declared for durable persistence
     * rather than left on a stream.
     */
    public function getPaymentRecord(string $siteId, string $paymentId): ?array;

    /** Records matching a filter: 'status', 'since', 'until', 'limit'. */
    public function listPaymentRecords(string $siteId, array $filter = []): array;

    /**
     * Chargebacks seen for this site, newest first, each joined to its local
     * record so the evidence is in one place when the dispute window opens.
     */
    public function listChargebacks(string $siteId, array $filter = []): array;
}
