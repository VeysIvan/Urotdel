<?php

namespace frontend\modules\urotdel\models;

use common\components\pdf\PdfRenderer;
use common\models\Company;
use common\models\EmployeeCompany;
use common\models\document\Invoice;
use common\models\labor\LaborTemplates;
use common\models\service\Payment;
use common\models\service\PaymentOrder;
use common\models\service\PaymentType;
use common\models\service\UrotdelDocumentItemTariff;
use common\models\service\UrotdelDocumentItemPaymentHelper;
use frontend\models\log\LogHelper;
use frontend\modules\documents\components\InvoiceHelper;
use Yii;
use yii\base\InvalidArgumentException;
use yii\base\Model;
use yii\db\Connection;

/**
 * Class UrotdelDocumentItemPaymentForm
 * @package frontend\modules\urotdel\models
 */
final class UrotdelDocumentItemPaymentForm extends Model
{
    private LaborTemplates $model;
    private EmployeeCompany $employeeCompany;
    private UrotdelDocumentItemTariff $tariff;
    private Company $company;
    private ?Invoice $invoice;
    private ?Payment $payment;
    private $_pdfContent;

    public $paymentTypeId = PaymentType::TYPE_ONLINE;

    public function __construct(LaborTemplates $model, EmployeeCompany $employeeCompany, $config = [])
    {
        if ($model->company_id != $employeeCompany->company_id) {
            throw new InvalidArgumentException();
        }

        $this->model = $model;
        $this->employeeCompany = $employeeCompany;
        $this->company = $employeeCompany->company;

        $this->getTariff();

        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            [
                ['paymentTypeId'], 'required',
                'message' => 'Способ оплаты не указан.',
            ],
            [
                ['paymentTypeId'], 'in',
                'range' => [
                    PaymentType::TYPE_ONLINE,
                ],
                'message' => 'Способ оплаты указан не верно.',
            ],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'tariffId' => 'Тариф',
            'paymentTypeId' => 'Способ оплаты',
            'companyId' => 'На какую компанию выставить счет',
        ];
    }

    /**
     * @return integer
     */
    public function getTariff()
    {
        if (!isset($this->tariff)) {
            $this->tariff = UrotdelDocumentItemTariff::findOne(['document_type' => $this->model->getDocumentType()]);
        }

        return $this->tariff;
    }

    /**
     * @return integer
     */
    public function getCompany()
    {
        return $this->company;
    }

    /**
     * @return integer
     */
    public function getPayment()
    {
        return $this->payment;
    }

    /**
     * @return integer
     */
    public function getPrice()
    {
        return $this->getTariff()->price;
    }

    /**
     * @return string|null
     */
    public function getPdfContent()
    {
        return $this->_pdfContent;
    }

    /**
     * @return Payment
     */
    public function getNewPayment()
    {
        $orderArray = [];
        $payment = new Payment([
            'company_id' => $this->company->id,
            'sum' => 0,
            'type_id' => $this->paymentTypeId,
            'is_confirmed' => false,
            'payment_for' => Payment::FOR_UROTDEL_DOCUMENT_ITEM,
        ]);
        $paymentOrder = new PaymentOrder([
            'company_id' => $this->company->id,
            'urotdel_document_item_tariff_id' => $this->tariff->id,
            'urotdel_document_id' => $this->model->id,
            'price' => $this->tariff->price,
            'discount' => 0,
            'sum' => $this->tariff->price,
        ]);
        $paymentOrder->populateRelation('company', $this->company);
        $paymentOrder->populateRelation('payment', $payment);
        $orderArray[] = $paymentOrder;

        $payment->sum += $paymentOrder->sum;
        $payment->sum .= '';
        $payment->populateRelation('orders', $orderArray);

        return $payment;
    }

    /**
     * @return bool
     * @throws Exception
     */
    private function createPayment()
    {
        $payment = $this->getNewPayment();
        if (!$payment->save()) {
            Yii::$app->session->setFlash('error', 'Ошибка при создании платежа. Попробуйте ещё раз');

            return false;
        }
        foreach ($payment->orders as $order) {
            $order->payment_id = $payment->id;
            if (!$order->save()) {
                Yii::$app->session->setFlash('error', 'Ошибка при создании платежа. Попробуйте ещё раз.');

                return false;
            }
        }
        $this->payment = $payment;

        $invoice = UrotdelDocumentItemPaymentHelper::getInvoice($this->payment, $this->tariff, $this->company);
        if (!$invoice) {
            Yii::$app->session->setFlash('error', 'Ошибка при создании счёта. Попробуйте ещё раз.');

            return false;
        }
        InvoiceHelper::afterSave($invoice->id);
        $this->invoice = $invoice;
        $this->_pdfContent = $this->invoiceRenderer->output(false);

        return true;
    }

    /**
     * @return PdfRenderer
     */
    public function getInvoiceRenderer()
    {
        return Invoice::getRenderer(null, $this->invoice, PdfRenderer::DESTINATION_STRING);
    }

    /**
     * @return bool|mixed
     * @throws \Throwable
     */
    public function makePayment()
    {
        $this->payment = null;
        $this->invoice = null;

        if (!$this->validate()) {
            return false;
        }

        $useTransaction = LogHelper::$useTransaction;
        LogHelper::$useTransaction = false;
        $created = Yii::$app->db->transaction(function (Connection $db) {
            if ($this->createPayment()) {
                return true;
            }
            $db->transaction->rollBack();

            return false;
        });
        LogHelper::$useTransaction = $useTransaction;

        return $created;
    }
}
