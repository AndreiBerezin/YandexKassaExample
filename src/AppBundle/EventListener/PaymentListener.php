<?php

namespace AppBundle\EventListener;

use AppBundle\Entity\Accounts;
use JMS\Payment\CoreBundle\Model\PaymentInterface;
use JMS\Payment\CoreBundle\PluginController\Event\PaymentStateChangeEvent;

use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class PaymentListener
 * @package AppBundle\EventListener
 */
class PaymentListener
{
    use ContainerAwareTrait;

    public function onPaymentStateChange(PaymentStateChangeEvent $event)
    {
        $payment = $event->getPayment();
        $instruction = $event->getPaymentInstruction();

        $container = $this->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $account = $em->getRepository('AppBundle:Accounts')->findOneBy(['paymentInstruction' => $instruction]);

        if (!is_null($account)) {
            if ($event->getNewState() == PaymentInterface::STATE_DEPOSITED) {
                $account->setStatus(Accounts::ACCOUNT_STATUS_SUCCESS_PAID);
                // send email to user
            } else {
                $account->setStatus(Accounts::ACCOUNT_STATUS_FAIL_PAID);
            }
            $account->setUpdatedAt(new \DateTime());
            $em->persist($account);
            $em->flush($account);
        }
    }

    /**
     * @return ContainerInterface
     */
    private function getContainer()
    {
        return $this->container;
    }
}