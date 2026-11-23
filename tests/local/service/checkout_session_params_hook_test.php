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

/**
 * Tests for the checkout_session_params hook dispatched by the checkout service.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

defined('MOODLE_INTERNAL') || die();

use core_payment\local\entities\payable;
use paygw_stripe\hook\checkout_session_params;
use paygw_stripe\tests\fixtures\stripe_testcase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Price;
use Stripe\Product;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');
require_once(__DIR__ . '/../../fixtures/checkout_test_session_factory.php');

/**
 * Tests for the checkout_session_params hook.
 *
 * @covers \paygw_stripe\hook\checkout_session_params
 * @covers \paygw_stripe\local\service\checkout_service
 */
final class checkout_session_params_hook_test extends stripe_testcase {
    /** @var array Parameters the mocked Stripe client received for sessions->create(). */
    private array $payload = [];

    /** @var bool Whether sessions->create() was called. */
    private bool $created = false;

    /**
     * Tests that the hook receives the payment context and that unchanged parameters reach Stripe as they are.
     */
    public function test_hook_receives_context_and_unchanged_params_reach_stripe(): void {
        $received = null;
        $this->redirectHook(checkout_session_params::class, static function (checkout_session_params $hook) use (&$received): void {
            $received = $hook;
        });

        $this->generate_payment($this->create_service());

        $this->assertInstanceOf(checkout_session_params::class, $received);
        $this->assertSame(123, $received->paymentaccountid);
        $this->assertSame('enrol_fee', $received->component);
        $this->assertSame('fee', $received->paymentarea);
        $this->assertSame('42', $received->itemid);
        $this->assertSame(10.50, $received->amount);
        $this->assertSame('EUR', $received->currency);

        $this->assertTrue($this->created);
        $this->assertSame($received->get_params(), $this->payload);
        $this->assertSame('payment', $this->payload['mode']);
        $this->assertSame('cus_test_1', $this->payload['customer']);
        $this->assertSame('42', $this->payload['payment_intent_data']['metadata']['itemid']);
    }

    /**
     * Tests that parameters added by a callback reach Stripe and existing parameters are kept.
     */
    public function test_params_added_by_callback_reach_stripe(): void {
        $this->redirectHook(checkout_session_params::class, static function (checkout_session_params $hook): void {
            $params = $hook->get_params();
            $params['payment_intent_data']['transfer_data'] = ['destination' => 'acct_test_1'];
            $params['payment_intent_data']['application_fee_amount'] = 749;
            $params['excluded_payment_method_types'] = ['paypal'];
            $hook->set_params($params);
        });

        $this->generate_payment($this->create_service());

        $this->assertTrue($this->created);
        $this->assertSame(['destination' => 'acct_test_1'], $this->payload['payment_intent_data']['transfer_data']);
        $this->assertSame(749, $this->payload['payment_intent_data']['application_fee_amount']);
        $this->assertSame(['paypal'], $this->payload['excluded_payment_method_types']);
        // Upstream parameters are still there.
        $this->assertSame('enrol_fee', $this->payload['payment_intent_data']['metadata']['component']);
        $this->assertSame('payment', $this->payload['mode']);
        $this->assertSame('cus_test_1', $this->payload['customer']);
    }

    /**
     * Tests that a callback can refuse the checkout by throwing; no session is created then.
     */
    public function test_callback_exception_prevents_session_creation(): void {
        $this->redirectHook(checkout_session_params::class, static function (checkout_session_params $hook): void {
            throw new \moodle_exception('error');
        });

        try {
            $this->generate_payment($this->create_service());
            $this->fail('The exception of the callback was expected to reach the caller.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        $this->assertFalse($this->created);
    }

    /**
     * Creates a checkout service with mocked collaborators and a mocked sessions->create().
     *
     * @return checkout_service
     */
    private function create_service(): checkout_service {
        $user = $this->getDataGenerator()->create_user(['lang' => 'en']);
        $this->setUser($user);

        $customerservice = $this->createMock(customer_service::class);
        $customerservice->method('get_customer')->willReturn(null);
        $customerservice->method('create_customer')->willReturn(Customer::constructFrom(['id' => 'cus_test_1']));
        $productpricingservice = $this->createMock(product_pricing_service::class);
        $productpricingservice->method('create_product_and_price')
            ->willReturn([Product::constructFrom(['id' => 'prod_test_1']), Price::constructFrom(['id' => 'price_test_1'])]);
        $webhookservice = $this->createMock(webhook_service::class);
        $webhookservice->method('create_webhook')->willReturn(true);

        $this->payload = [];
        $this->created = false;
        $this->mock_sessions()->method('create')
            ->willReturnCallback(function (array $params): Session {
                $this->payload = $params;
                $this->created = true;
                return checkout_test_session_factory::payment_session('cs_hook_1', 'open', 'unpaid');
            });

        $service = new checkout_service($this->client);
        $this->set_private_property($service, 'productpricingservice', $productpricingservice);
        $this->set_private_property($service, 'customerservice', $customerservice);
        $this->set_private_property($service, 'webhookservice', $webhookservice);
        return $service;
    }

    /**
     * Calls generate_payment() with fixed test values.
     *
     * @param checkout_service $service
     * @return string Checkout session id
     */
    private function generate_payment(checkout_service $service): string {
        $config = (object)[
            'enableautomatictax' => 0,
            'defaulttaxbehavior' => 'inclusive',
            'paymentmethodconfiguration' => 'pmc_test_1',
            'allowpromotioncodes' => 0,
        ];
        return $service->generate_payment(
            $config,
            new payable(10.50, 'EUR', 123),
            'Test Stripe Product',
            10.50,
            'enrol_fee',
            'fee',
            '42'
        );
    }

    /**
     * Registers the nested checkout sessions SDK service.
     *
     * @return MockObject Sessions service mock
     */
    private function mock_sessions(): MockObject {
        $checkout = $this->mock_stripe_service('checkout', CheckoutServiceFactory::class);
        $sessions = $this->createMock(SessionService::class);
        $checkout->method('__get')->with('sessions')->willReturn($sessions);
        return $sessions;
    }

    /**
     * Sets a private property on an object.
     *
     * @param object $object
     * @param string $name
     * @param mixed $value
     */
    private function set_private_property(object $object, string $name, $value): void {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
