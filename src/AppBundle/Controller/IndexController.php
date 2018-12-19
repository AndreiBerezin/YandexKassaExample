<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Accounts;
use AppBundle\Form\Account\AddForm;
use JMS\Payment\CoreBundle\Form\ChoosePaymentMethodType;
use JMS\Payment\CoreBundle\Model\PaymentInstructionInterface;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\PluginController\Result;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class IndexController
 * @package AppBundle\Controller
 * @author Andrei Berezin <yago.spb@gmail.com>
 */
class IndexController extends Controller
{
    /**
     * @Route("/", name="index")
     * @Template("@App/Index/index.html.twig")
     */
    public function indexAction()
    {
        return ['message' => ''];
    }

    /**
     * @Route("/index/account/add", name="index_account_add")
     * @Template("@App/Index/account_add.html.twig")
     */
    public function accountAddction(Request $request)
    {
        $form = $this->createForm(AddForm::class);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $this->get('session')->set('pay_data', $form->getData());

            return $this->redirect($this->generateUrl('index_account_confirm'));
        }

        return [
            'form' => $form->createView()
        ];
    }

    /**
     * @Route("/index/account/confirm", name="index_account_confirm")
     * @Template("@App/Index/account_confirm.html.twig")
     */
    public function accountConfirmAction(Request $request)
    {
        $payData = $this->get('session')->get('pay_data', ['sum' => 0]);
        $form = $this->createForm(
            ChoosePaymentMethodType::class,
            null,
            [
                'amount' => $payData['sum'],
                'currency' => 'RUB',
                'default_method' => 'yandexkassa'
            ]
        );
        $form->handleRequest($request);
        if ($form->isValid()) {
            /** @var PaymentInstructionInterface $instruction */
            $instruction = $form->getData();
            $instruction->getExtendedData()->set('customerNumber', '666');
            // more info in https://tech.yandex.ru/money/doc/payment-solution/payment-form/payment-form-receipt-docpage/
            $onlineReceipt = [
                'customerContact' => '+79091112233', //phone or email
                'taxSystem' => 1, // not required param
                'items' => [
                    [
                        'quantity' => 1,
                        'price' => [
                            'amount' => $payData['sum']
                        ],
                        'tax' =>  3,
                        'text' => 'Test commodity name',
                        'paymentSubjectType' => 'commodity',
                        'paymentMethodType' => 'full_prepayment'
                    ]
                ]
            ];
            $instruction->getExtendedData()->set('ym_merchant_receipt', json_encode($onlineReceipt));
            $instruction->getExtendedData()->set('return_url', $this->generateUrl('index_account_finish', ['status' => '0']));
            $instruction->getExtendedData()->set('cancel_url', $this->generateUrl('index_account_finish', ['status' => '1']));

            $this->get('payment.plugin_controller')->createPaymentInstruction($instruction);

            $account = new Accounts();
            $account->setSum($payData['sum']);
            $account->setStatus(Accounts::ACCOUNT_STATUS_NOT_PAID);
            $account->setUpdatedAt(new \DateTime());

            $account->setPaymentInstruction($instruction);
            $em = $this->get('doctrine.orm.entity_manager');
            $em->persist($account);
            $em->flush($account);


            if (null === $pendingTransaction = $instruction->getPendingTransaction()) {
                $payment = $this->get('payment.plugin_controller')->createPayment(
                    $instruction->getId(),
                    $instruction->getAmount() - $instruction->getDepositedAmount()
                );
            } else {
                $payment = $pendingTransaction->getPayment();
            }
            /** @var Result $result */
            $result = $this->get('payment.plugin_controller')->approveAndDeposit(
                $payment->getId(),
                $payment->getTargetAmount()
            );
            if (Result::STATUS_PENDING === $result->getStatus()) {
                $ex = $result->getPluginException();

                if ($ex instanceof ActionRequiredException) {
                    $action = $ex->getAction();

                    if ($action instanceof VisitUrl) {
                        return new RedirectResponse($action->getUrl());
                    }

                    throw $ex;
                }
            } elseif (Result::STATUS_SUCCESS !== $result->getStatus()) {
                throw new \RuntimeException('Transaction was not successful: ' . $result->getReasonCode());
            }
        }

        return [
            'payData' => $payData,
            'form' => $form->createView()
        ];
    }

    /**
     * @Route("/index/account/finish/{status}", name="index_account_finish")
     */
    public function accountFinishAction($status)
    {
        if ($status === '0') {
            $message = 'Payment successfully finish';
        } else {
            $message = 'Error with payment';
        }

        return $this->redirect($this->generateUrl('index', ['message' => $message]));
    }
}
