<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace paygw_stripe\hook;

/**
 * Hook dispatched right before a Stripe Checkout Session for a one-time payment is created.
 *
 * Callbacks can read the payment context and add or change Checkout Session parameters,
 * e.g. payment_intent_data, metadata or excluded_payment_method_types. A callback can refuse
 * the checkout by throwing a moodle_exception; no session is created then.
 *
 * @package    paygw_stripe
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to extend the parameters of a Stripe Checkout Session before it is created.')]
#[\core\attribute\tags('payment')]
final class checkout_session_params {
    /**
     * Constructor.
     *
     * @param int $paymentaccountid Payment account id of the payable.
     * @param string $component Component of the payable.
     * @param string $paymentarea Payment area of the payable.
     * @param string $itemid Item id of the payable.
     * @param float $amount Amount to be charged.
     * @param string $currency Currency code.
     * @param array $params Checkout Session parameters as they will be sent to Stripe.
     */
    public function __construct(
        /** @var int Payment account id of the payable. */
        public readonly int $paymentaccountid,
        /** @var string Component of the payable. */
        public readonly string $component,
        /** @var string Payment area of the payable. */
        public readonly string $paymentarea,
        /** @var string Item id of the payable. */
        public readonly string $itemid,
        /** @var float Amount to be charged. */
        public readonly float $amount,
        /** @var string Currency code. */
        public readonly string $currency,
        /** @var array Checkout Session parameters. */
        private array $params
    ) {
    }

    /**
     * Returns the Checkout Session parameters.
     *
     * @return array
     */
    public function get_params(): array {
        return $this->params;
    }

    /**
     * Replaces the Checkout Session parameters.
     *
     * @param array $params
     */
    public function set_params(array $params): void {
        $this->params = $params;
    }
}
