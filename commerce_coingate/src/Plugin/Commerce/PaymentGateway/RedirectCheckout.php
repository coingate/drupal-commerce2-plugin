<?php

namespace Drupal\commerce_coingate\Plugin\Commerce\PaymentGateway;

use CoinGate\CoinGate;
use CoinGate\Merchant;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;

require_once(__DIR__ . '/../../../CoinGateApi/vendor/autoload.php');

/**
 * Provides the QuickPay offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "coingate_redirect_checkout",
 *   label = @Translation("CoinGate(Redirect to Coingate)"),
 *   display_label = @Translation("CoinGate"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_coingate\PluginForm\RedirectCheckoutForm",
 *   },
 * )
 */
class RedirectCheckout extends OffsitePaymentGatewayBase implements RedirectCheckoutInterface
{
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        EntityTypeManagerInterface $entity_type_manager,
        PaymentTypeManager $payment_type_manager,
        PaymentMethodTypeManager $payment_method_type_manager,
        TimeInterface $time
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager,
            $payment_method_type_manager, $time);
    }


    public function defaultConfiguration()
    {
        return [
                'coingate_api_auth_token' => '',
                'coingate_receive_currency' => '',
                'coingate_test_mode' => '',
            ] + parent::defaultConfiguration();
    }


    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $form['coingate_api_auth_token'] = [
            '#type' => 'textfield',
            '#title' => $this->t('API Auth Token'),
            '#description' => $this->t('write something smart'),
            '#default_value' => $this->configuration['coingate_api_auth_token'],
            '#required' => true,
        ];

        $form['coingate_receive_currency'] = [
            '#type' => 'select',
            '#title' => $this->t('Receive Currency'),
            '#description' => 'Select Receive Currency',
            '#options' => [
                $this->t('Bitcoin (฿)'),
                $this->t('USDT (₮)'),
                $this->t('Euros (€)'),
                $this->t('US Dollars ($)'),
                $this->t('Do not convert')
            ],
            '#default_value' => $this->configuration['coingate_receive_currency'],
            '#required' => true,
        ];

        return $form;
    }

    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateConfigurationForm($form, $form_state);

        $values = $form_state->getValue($form['#parents']);

        $authentication = [
            'auth_token' => $values['coingate_api_auth_token'],
            'environment' => $this->getTestModeValue($values['mode'])
        ];

        $testResponse = CoinGate::testConnection($authentication);

        if ($testResponse !== true) {
            return $form_state->setErrorByName('coingate_api_auth_token', $testResponse);
        }
    }


    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        if (!$form_state->getErrors()) {

            parent::submitConfigurationForm($form, $form_state);
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['coingate_api_auth_token'] = $values['coingate_api_auth_token'];
            $this->configuration['coingate_receive_currency'] = $values['coingate_receive_currency'];
        }
    }

    public function onReturn(OrderInterface $order, Request $request)
    {
        if ($this->checkCallbackExist($order) !== true) {

            throw new PaymentGatewayException("There was a problem with your request please contact merchant.");
        }
    }

    public function onCancel(OrderInterface $order, Request $request)
    {
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->load($order->id());
        $payment->setRemoteState('canceled');
        $payment->setState('canceled');
        $payment->save();
    }

    public function onNotify(Request $request)
    {
        $callback = $_POST;

        $configuration = $this->getConfiguration();

        $coingateAuthentication = [
            'auth_token' => $configuration['coingate_api_auth_token'],
            'environment' => $this->getTestModeValue($configuration['mode'])
        ];

        $coingateOrder = Merchant\Order::find($callback['id'], [], $coingateAuthentication);

        if (!$coingateOrder->status || $coingateOrder->status === false) {
            throw new PaymentGatewayException("payment invalid");
        } else {
            $status = $coingateOrder->status;
        }

        switch ($status) {
            case 'paid':
                $status = 'Completed';
                break;
            case 'pending':
                $status = "Pending";
                break;
            case 'invalid':
                $status = "Voided";
                break;
            case 'expired':
                $status = "Expired";
                break;
            case 'canceled':
                $status = "Cancelled";
                break;
            case 'refunded':
                $status = "Refunded";
                break;
            case 'new':
                $status = "New";
                break;
            case 'confirming':
                $status = "Pending";
                break;
        }

        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->load($callback['order_id']);

        $payment->getState();
        $payment->setRemoteId($coingateOrder->id);
        $payment->setRemoteState($coingateOrder->status);
        $payment->setState($status);
        $payment->save();
    }


    public function createCoinGateInvoice(PaymentInterface $payment, array $extra)
    {
        $order = $payment->getOrder();

        /** @var \Drupal\commerce_payment\PaymentStorageInterface $paymentStorage */
        $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

        $paymentAmount = $payment->getAmount();

        $payment = $paymentStorage->create([
            'state' => 'Open',
            'amount' => $payment->getAmount(),
            'payment_gateway' => $this->entityId,
            'payment_method' => 'coingate',
            'order_id' => $order->id(),
            'test' => $this->getMode() == 'test',
            'authorized' => $this->time->getRequestTime(),
        ]);

        $payment->save();

        $configuration = $this->getConfiguration();

        $coingateAuthentication = [
            'auth_token' => $configuration['coingate_api_auth_token'],
            'environment' => $this->getTestModeValue($configuration['mode'])
        ];

        $coingateOrder = [
            'order_id' => $payment->id(),
            'price_amount' => $paymentAmount->getNumber(),
            'price_currency' => $paymentAmount->getCurrencyCode(),
            'receive_currency' => $this->getReceiveCurrencyValue($configuration['coingate_receive_currency']),
            'cancel_url' => $extra['cancel_url'],
            'callback_url' => $this->getNotifyURL()->toString(),
            'success_url' => $extra['return_url'],
            'title' => "Order Id: " . $order->id(),
            'token' => $coingateAuthentication['auth_token']
        ];

        try {

            $coinGateResponse = CoinGate::request('/orders', 'POST', $coingateOrder, $coingateAuthentication);
            return $coinGateResponse;

        } catch (\Exception $exception) {

            return 'Error: ' . $exception->getMessage() . '. Please contact the seller for further information.';
        }
    }

    private function checkCallbackExist(OrderInterface $order)
    {
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->load($order->id());

        return $payment->getRemoteState() !== null ? true : false;
    }

    private function getReceiveCurrencyValue($value)
    {
        switch ($value) {
            case 0:
                return 'BTC';
                break;
            case 1:
                return 'USDT';
                break;
            case 2:
                return 'EUR';
                break;
            case 3:
                return 'USD';
                break;
            case 4:
                return 'DO_NOT_CONVERT';
                break;
        }
    }

    private function getTestModeValue($value)
    {
        if ($value === 'test') {
            return 'sandbox';
        } elseif ($value === 'live') {
            return 'live';
        }
    }
}

