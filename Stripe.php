<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once 'application/third_party/stripe/init.php';

class Stripe {
    protected $CI;
    public $public_key;
    public $private_key;

    public function __construct() {
        $this->CI = &get_instance();
        $this->public_key = $this->CI->config->item('stripe_pk');
        $this->private_key = $this->CI->config->item('stripe_sk');
        \Stripe\Stripe::setApiKey($this->private_key);
    }

    public function useGathrFoundationStripeKeys() {
        // Use The Gathr Foundation's API keys for donations
        $this->public_key = $this->CI->config->item('stripe_pk_gathr_foundation');
        $this->private_key = $this->CI->config->item('stripe_sk_gathr_foundation');
        \Stripe\Stripe::setApiKey($this->private_key);
    }

    // Returns the Stripe Customer ID of the user given by $user_id, or
    // the currently logged-in user if no user ID is specified.
    public function cust_id($user_id = null) {
        if($user_id === null) {
            $user_id = $this->CI->session->userdata['user_id']; // Default to the currently logged-in user.
        }
        $this->CI->load->model('Users');
        $users = new Users;
        $user_data = $users->get($user_id);
        if($user_data->stripe_cust_id === null) {
            $customer = \Stripe\Customer::create([
                'description' => 'Gathr ID: ' . $user_id,
                'email' => $user_data->email,
            ]);
            $users->set_stripe_cust_id($user_id, $customer->id);
            $user_data->stripe_cust_id = $customer->id;
        }
        return $user_data->stripe_cust_id;
    }

    // Returns the email address of the user given by $user_id, or
    // the currently logged-in user if no user ID is specified.
    public function getUserEmail($user_id = null) {
        if ($user_id === null) {
            $user_id = $this->CI->session->userdata['user_id']; // Default to the currently logged-in user.
        }

        $this->CI->load->model('Users');
        $users = new Users;
        $user_data = $users->get($user_id);
        return $user_data->email;
    }

    public function getPaymentMethods($cust_id = null) {
        if (!$cust_id) $cust_id = $this->cust_id();
        $payment_methods = \Stripe\PaymentMethod::all([
            'customer' => $cust_id,
            'type' => 'card',
        ]);
        if(!is_object($payment_methods) || !isset($payment_methods->data)) {
            return false;
        }
        return $payment_methods['data'];
    }

    public static function getPaymentMethodName($method) {
        return ucwords($method->card['brand']) . ' ending in ' . $method->card->last4;
    }

    public static function getPaymentMethodType($method) {
        return $method->card['brand'];
    }

    public function deletePaymentMethod($pm_id) {
        $client = new \Stripe\StripeClient($this->private_key);
        $client->paymentMethods->detach(
            $pm_id,
            []
        );
    }

    public function retrievePaymentIntent($payment_intent_id) {
        return \Stripe\PaymentIntent::retrieve($payment_intent_id);
    }

    public function retrieveDonationPaymentIntent($payment_intent_id) {
        $this->useGathrFoundationStripeKeys();
        return \Stripe\PaymentIntent::retrieve($payment_intent_id);
    }

    public function getPaymentIntent($user_id = null) {
        return \Stripe\SetupIntent::create([
            'customer' => $this->cust_id($user_id),
        ]);
    }

    public function getPaymentIntentWithAmount($amount, $user_id = null) {
        return \Stripe\PaymentIntent::create([
            'customer' => $this->cust_id($user_id),
            'amount' => $amount,
            'currency' => 'usd',
            'setup_future_usage' => 'off_session',
        ]);
    }

    public function getPaymentIntentWithAmountAndPaymentMethod($amount, $payment_method, $user_id = null) {
        $options = [
            'customer' => $this->cust_id($user_id),
            'amount' => $amount,
            'currency' => 'usd',
            'setup_future_usage' => 'off_session',
            'payment_method' => $payment_method,
        ];
        return \Stripe\PaymentIntent::create($options);
    }

    public function getpk() {
        $cust = \Stripe\Customer::create();
    }

    public function getPaymentIntentWithAmountAndPaymentMethodWithCurrency($reservationData) {
        // $reservationData = array(
        //     'amount' => $amount,
        //     'payment_method' => $payment_method,
        //     'currency' => $currency,
        //     'user_id' => $user_id,
        //     'event_id' => $event_id,
        //     'reservation_unique_identifier' => $reservation_unique_identifier,
        //     'service_fee' => $service_fee,
        //     'tax' => $tax,
        //     'price_without_tax' => $price_without_tax,
        //     'number_of_tickets' => $number_of_tickets
        // );

        try {
            $options = [
                'customer' => $this->cust_id($reservationData['user_id']),
                'amount' => $reservationData['amount'],
                'currency' => $reservationData['currency'],
                'payment_method_types' => ['card'],
                'setup_future_usage' => 'off_session',
                'payment_method' => $reservationData['payment_method'],
                'capture_method' => 'manual',
                'confirmation_method' => 'manual',
                'confirm' => true,
                'metadata' => [
                    'user_id' => $reservationData['user_id'],
                    'event_id' => $reservationData['event_id'],
                    'reservation_unique_identifier' => $reservationData['reservation_unique_identifier'],
                    'service_fee' => $reservationData['service_fee'],
                    'tax' => $reservationData['tax'],
                    'price_without_tax' => $reservationData['price_without_tax'],
                    'number_of_tickets' => $reservationData['number_of_tickets']
                ],
            ];
            return \Stripe\PaymentIntent::create($options);

        }catch (Exception $e) {
            return "failed";
        };
    }

    /*START: Authorize funds and hold a card and capture later*/
    public function authorizePaymentIntentWithAmount($amount, $payment_method, $user_id = null) {
        return \Stripe\PaymentIntent::create([
            'customer' => $this->cust_id($user_id),
            'amount' => $amount,
            'currency' => 'usd',
            'setup_future_usage' => 'off_session',
            'payment_method_types' => ['card'],
            'capture_method' => 'manual',
            'payment_method' => $payment_method
        ]);
    }

    public function captureAuthorizedPaymentWithAmount($pi, $amount, $user_id = null) {
        try{
            $stripe = new \Stripe\StripeClient($this->private_key);
            $stripe->paymentIntents->confirm(
              $pi/*,
              ['payment_method' => 'pm_card_visa']*/
            );
            return json_encode($stripe->paymentIntents->capture($pi/*,['amount_to_capture' => $amount]*/));
        } catch(\Stripe\Error\Card $e) {
           //not triggered
        } catch(\Exception $e) {
           return "failed";
        }
    }
    /*END: Authorize funds and hold a card and capture later*/

    /*START: Autorize and capture funds in sequence*/
    public function captureAmountWithPaymentIntent($pi, $amount, $user_id = null)
    {
        try{
            $stripe = new \Stripe\StripeClient($this->private_key);
            return json_encode($stripe->paymentIntents->capture($pi,['amount_to_capture' => $amount]));
        } catch(\Stripe\Error\Card $e) {
           //not triggered
        } catch(\Exception $e) {
           if ($e->getError()->code == 'payment_intent_unexpected_state')
           {
              return "failed";
           }
        }
    }
    /*END: Autorize and capture funds in sequence*/

    public function cancelAuthorizedPayment($pi) {
        $stripe = new \Stripe\StripeClient($this->private_key);
        $intent = \Stripe\PaymentIntent::retrieve($pi);
        return json_encode($intent->cancel());
    }

    public function retrievePaymentMethod($payment_method)
    {
        $stripe = new \Stripe\StripeClient($this->private_key);
        $result = $stripe->paymentMethods->retrieve(
          $payment_method,
          []
        );
        return json_encode($result);
    }

    public function refundOrder($charge_id, $refund_amount)
    {
        $stripe = new \Stripe\StripeClient($this->private_key);
        $result = $stripe->refunds->create([
          'charge' => $charge_id,
          'amount' => $refund_amount*100,
          'reason' => 'requested_by_customer'
        ]);
        return json_encode($result);
    }

    public function createDonationCheckoutSession($checkoutData) {
        // $checkoutData = array(
        //     'charity' => $charity,
        //     'donation_amount' => $donation_amount,
        //     'user_id' => $user_id,
        //     'event_id' => $event_id,
        //     'return_url' => $return_url
        // );

        $this->useGathrFoundationStripeKeys();

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
              'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                  'name' => 'Donation to ' . $checkoutData['charity']->name,
                ],
                'unit_amount' => $checkoutData['donation_amount'] * 100,
              ],
              'quantity' => 1,
            ]],
            'metadata' => [
                'user_id' => $checkoutData['user_id'],
                'event_id' => $checkoutData['event_id'],
                'charity_ein' => $checkoutData['charity']->ein
            ],
            'mode' => 'payment',
            'success_url' => $checkoutData['return_url'] . '?id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $checkoutData['return_url'] . '?id={CHECKOUT_SESSION_ID}',
            'submit_type' => 'donate'
        ]);

        return array('id' => $session->id);
    }

    public function getCheckoutSession($session_id) {
        return \Stripe\Checkout\Session::retrieve($session_id);
    }

    public function getDonationCheckoutSession($session_id) {
        $this->useGathrFoundationStripeKeys();
        return \Stripe\Checkout\Session::retrieve($session_id);
    }

    public function addMetadataToPaymentIntent($metadata, $payment_intent_id) {
        $payment_intent = \Stripe\PaymentIntent::update(
            $payment_intent_id,
            ['metadata' => $metadata]
        );
        return $payment_intent;
    }

    public function addMetadataToDonationPaymentIntent($metadata, $payment_intent_id) {
        $this->useGathrFoundationStripeKeys();
        $payment_intent = \Stripe\PaymentIntent::update(
            $payment_intent_id,
            ['metadata' => $metadata]
        );
        return $payment_intent;
    }

    public function createBookingPaymentIntent($paymentIntentData) {
        // $paymentIntentData = array(
        //     'amount' => INT,
        //     'payment_method' => STRING,
        //     'user_id' => INT,
        //     'email' => STRING,
        //     'booking_unique_identifier' => STRING
        // );

        return \Stripe\PaymentIntent::create([
            'customer' => $this->cust_id($paymentIntentData['user_id']),
            'amount' => $paymentIntentData['amount'],
            'currency' => 'usd',
            'setup_future_usage' => 'off_session',
            'payment_method_types' => ['card'],
            'capture_method' => 'manual',
            'payment_method' => $paymentIntentData['payment_method'],
            'receipt_email' => $paymentIntentData['email'],
            'statement_descriptor_suffix' => strtoupper($paymentIntentData['booking_unique_identifier'])
        ]);
    }

    public function confirmAndCapturePaymentIntent($payment_intent_id) {
        try {
            $stripe = new \Stripe\StripeClient($this->private_key);
            $stripe->paymentIntents->confirm($payment_intent_id);
            return json_encode( $stripe->paymentIntents->capture($payment_intent_id) );
        }
        catch(\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Exception\CardException will be caught
            error_log('CardException in confirmAndCapturePaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests made to the API too quickly
            error_log('CardException in confirmAndCapturePaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            error_log('InvalidRequestException in confirmAndCapturePaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);

            if ('development' == $this->CI->config->item('environment')) {
                return json_encode(array('status' => 'succeeded', 'dev_bypass' => TRUE));
            }
        }
        catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            error_log('AuthenticationException in confirmAndCapturePaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network communication with Stripe failed
            error_log('CardException in confirmAndCapturePaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\ApiConnectionException\ApiErrorException $e) {
            // Display a very generic error to the user, and maybe send
            error_log('CardException in confirmAndCapturePaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
          // yourself an email
        }
        catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            error_log('Exception in confirmAndCapturePaymentIntent(' . $payment_intent_id . ')');
        }
    }

    public function cancelPaymentIntent($payment_intent_id) {
        try {
            $stripe = new \Stripe\StripeClient($this->private_key);
            return $stripe->paymentIntents->cancel($payment_intent_id);
        }
        catch(\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Exception\CardException will be caught
            error_log('CardException in cancelPaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests made to the API too quickly
            error_log('CardException in cancelPaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            error_log('InvalidRequestException in cancelPaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);

            if ('development' == $this->CI->config->item('environment')) {
                error_log('CATCH');
                return array('status' => 'canceled', 'dev_bypass' => TRUE);
            }
        }
        catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            error_log('AuthenticationException in cancelPaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network communication with Stripe failed
            error_log('CardException in cancelPaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\ApiConnectionException\ApiErrorException $e) {
            // Display a very generic error to the user, and maybe send
            error_log('CardException in cancelPaymentIntent(' . $payment_intent_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
          // yourself an email
        }
        catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            error_log('Exception in cancelPaymentIntent(' . $payment_intent_id . ')');
        }
    }

    public function attachPaymentMethodToUser($user_id, $payment_method_id) {
        try {
            $stripe = new \Stripe\StripeClient($this->private_key);
            return $stripe->paymentMethods->attach($payment_method_id,
                ['customer' => $this->cust_id($user_id)]
            );
        }
        catch(\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Exception\CardException will be caught
            error_log('CardException in attachPaymentMethodToUser(' . $payment_method_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests made to the API too quickly
            error_log('CardException in attachPaymentMethodToUser(' . $payment_method_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            error_log('InvalidRequestException in attachPaymentMethodToUser(' . $payment_method_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);

            if ('development' == $this->CI->config->item('environment')) {
                error_log('CATCH');
                return array('status' => 'canceled', 'dev_bypass' => TRUE);
            }
        }
        catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            error_log('AuthenticationException in attachPaymentMethodToUser(' . $payment_method_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network communication with Stripe failed
            error_log('CardException in attachPaymentMethodToUser(' . $payment_method_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
        }
        catch (\Stripe\ApiConnectionException\ApiErrorException $e) {
            // Display a very generic error to the user, and maybe send
            error_log('CardException in attachPaymentMethodToUser(' . $payment_method_id . ')');
            error_log('Status is: ' . $e->getHttpStatus());
            error_log('Type is: ' . $e->getError()->type);
            error_log('Code is: ' . $e->getError()->code);
            error_log('Param is: ' . $e->getError()->param);
            error_log('Message is: ' . $e->getError()->message);
          // yourself an email
        }
        catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            error_log('Exception in attachPaymentMethodToUser(' . $payment_method_id . ')');
        }
    }
}
