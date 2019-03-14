<?php

namespace Drupal\commerce_coingate\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;

interface RedirectCheckoutInterface extends OffsitePaymentGatewayInterface {

    /**
     * Start Coingate Basic Mode Checkout
     *
     * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
     *   The payment.
     * @param array $extra
     *   Extra data needed for this request, ex.: cancel url, return url, transaction mode, etc....
     *
     * @return mixed
     *   CoinGate Response
     *
     */
    public function createCoinGateInvoice(PaymentInterface $payment, array $extra);

}


