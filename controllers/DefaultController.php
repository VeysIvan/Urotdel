<?php

namespace frontend\modules\urotdel\controllers;

use common\components\phpWord\urotdel\PhpWordUrotdelEmployeeTerminationLetter;
use common\models\Company;
use common\models\document\status\UrotdelStatus;
use common\models\employee\EmployeeRole;
use common\models\EmployeeCompany;
use \common\models\Contractor;
use common\models\labor\LaborTemplates;
use common\components\pdf\PdfRenderer;
use common\models\TimeZone;
use common\models\UrotdelVisit;
use common\models\service\PaymentType;
use common\services\Urotdel\UrotdelIsPaid;
use frontend\modules\documents\components\DocumentBaseController;
use frontend\components\StatisticPeriod;
use frontend\models\Documents;
use common\models\file\File as Scan;
use frontend\models\log\LogEntityType;
use frontend\models\log\LogEvent;
use frontend\models\log\LogHelper;
use frontend\modules\documents\assets\DocumentPrintAsset;
use frontend\modules\documents\forms\InvoiceSendForm;
use frontend\modules\documents\models\LaborSearch;
use frontend\modules\subscribe\forms\OnlinePaymentForm;
use frontend\modules\urotdel\models\LaborDocumentsFrom;
use frontend\modules\urotdel\models\UrotdelDocumentItemPaymentForm;
use frontend\modules\urotdel\models\VisitForm;
use frontend\rbac\UserRole;
use frontend\rbac\permissions;
use common\models\file;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\widgets\ActiveForm;

final class DefaultController extends DocumentBaseController
{
    public $layout = 'documentoved';
    /**
     * @var int
     */
    public $type;

    /**
     * @var int
     */
    public $typeDocument = Documents::DOCUMENT_LABOR;
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => [
                            permissions\Urotdel::ADMIN,
                        ]
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['post'],
                    'many-delete' => ['post'],
                    'update-status' => ['post'],
                    'document-online-payment' => ['post'],

                    // files
                    'file-upload' => ['post'],
                    'file-delete    ' => ['post'],
                ],
            ],
        ];
    }

    public function actions()
    {
        return array_merge(parent::actions(), [
            'file-upload' => [
                'class' => file\actions\FileUploadAction::className(),
                'model' => LaborTemplates::class,
                'noLimitCountFile' => true,
                'folderPath' => 'documents' . DIRECTORY_SEPARATOR . LaborTemplates::$uploadDirectory,
            ],
            'file-list' => [
                'class' => file\actions\FileListAction::className(),
                'model' => LaborTemplates::class,
                'fileLinkCallback' => function (file\File $file, $ownerModel) {
                    return Url::to(['file-get', 'id' => $ownerModel->id, 'file-id' => $file->id,]);
                },
            ],
            'create-employee' => [
                'class' => \frontend\components\CompanyEmployeeFormAction::class,
                'model' => function ($action) {
                    return \common\models\CompanyEmployee::newInstance(Yii::$app->user->identity->company);
                },
                'successResult' => function ($model) {
                    return $this->asJson([
                        'id' => $model->id,
                        'lastname' => $model->lastname,
                        'firstname' => $model->firstname,
                        'patronymic' => $model->patronymic,
                        'position' => $model->position
                    ]);
                }
            ]
        ]);
    }

    public function actionDocuments(string $file = 'file_name')
    {
        return $this->render('documents/' . $file);
    }

    /**
     * @return string|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionIndex($type = null)
    {
        $company = Yii::$app->user->identity->company;
        $searchModel = new LaborSearch([
            'company_id' => $company->id,
        ]);


        $searchModel->populateRelation('company', $company);

        $dataProvider = $searchModel->search(Yii::$app->request->queryParams, StatisticPeriod::getSessionPeriod());

        $dataProvider->pagination->pageSize = \frontend\components\PageSize::get();

        return $this->render('labor', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * @return string|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionPjax()
    {
        $post = Yii::$app->request->post();
        $layout = $post['LaborTemplates']['rule'];
        $this->redirect(yii\helpers\Url::toRoute(['create', 'layout' => $layout]));
    }

    /**
     * @return string|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionCreate($layout)
    {
        $model = new LaborTemplates(Yii::$app->user->identity->company->id, Yii::$app->user->id);
        $company = Yii::$app->user->identity->company;
        $model->type = $layout;
        $this->layout = null;
        $postParams = Yii::$app->request->post();
        $model->setAttribute('uid', $model::generateUid());
        if (Yii::$app->request->isPost && $model->load($postParams)) {
            if ($model->save() && $model->updateStatus(UrotdelStatus::STATUS_CREATED)) {
                (new UrotdelIsPaid($company))->check($model);
                Yii::$app->session->setFlash('success', 'Документ был успешно создан.');

                return $this->redirect(Url::to(['view', 'type' => $model->type, 'id' => $model->id]));
            }
            var_dump($model->errors);
            die;
            Yii::$app->session->setFlash('error', 'Произошла ошибка при создании документа');
            return $this->redirect(Yii::$app->request->referrer);
        }

        return $this->render('layouts/_layout' . $layout, [
            'layout' => $layout,
            'model' => $model,
            'company' => $company,
            'is_old' => 0
        ]);
    }
    /**
     * @return string
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionViewExampleDocument($type = '1')
    {
        return '<html><head></head><body style="margin: 0px;"><div text-align="center"><embed width="100%" height="100%" src="' . Url::to('@web/images/urotdel/document_example_' . $type . '.pdf?') . '"/></div></body></html>';
    }
    /**
     * @param int $contractor_id
     * @return object|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionFindContractorModel($contractorId)
    {
        $contractor = Contractor::findOne($contractorId);
        Yii::$app->response->format = Response::FORMAT_JSON;

        if ($contractor !== null) {
            return $contractor->getContractorAccount()->getAttributes();
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }

    /**
     * @param $id
     * @param $layout
     * @return string|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id, false);
        $layout = $model->type;
        $company = Yii::$app->user->identity->company;

        $model_employee = new EmployeeCompany([
            'scenario' => 'create',
            'send_email' => false,
            'company_id' => $company->id,
            'employee_role_id' => EmployeeRole::ROLE_EMPLOYEE,
            'time_zone_id' => TimeZone::DEFAULT_TIME_ZONE,
            'is_product_admin' => true,
            'date_hiring' => date('d.m.Y'),
        ]);
        $this->layout = null;
        if ($model->status_id != UrotdelStatus::STATUS_SIGNED) {
            if (Yii::$app->request->isPost && $model->load(Yii::$app->request->post())) {

                if ($model->save() && $model->updateStatus(UrotdelStatus::STATUS_UPDATED)) {
                    Yii::$app->session->setFlash('success', 'Документ был успешно отредактирован.');
                    return $this->redirect(Url::to(['view', 'type' => $model->type, 'id' => $model->id]));
                }

                Yii::$app->session->setFlash('error', 'Произошла ошибка при редактировании документа.');
                return $this->redirect(Yii::$app->request->referrer);
            }
        } else {
            throw new HttpException(404, "Данный документ уже подписан, его редактирование запрещено");
        }

        return $this->render('layouts/_layout' . $layout, [
            'layout' => $layout,
            'model' => $model,
            'model_employee' => $model_employee,
            'company' => $company,
            'is_old' => 1
        ]);
    }

    /**
     * @param $type
     * @param $id
     * @return string
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionView($id)
    {
        $this->layout = null;
        $model = $this->findModel($id, false);
        if (!$model->is_paid) {
            (new UrotdelIsPaid(Yii::$app->user->identity->company))->check($model);
        }

        return $this->render('view', [
            'model' => $model,
            'layout' => $model->type,
            'payment' => $model->is_paid ? null : new UrotdelDocumentItemPaymentForm(
                $model,
                Yii::$app->user->identity->currentEmployeeCompany
            ),
        ]);
    }

    /**
     * @param $id
     * @param $template
     * @param $old_record
     * @return string|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionDelete($id, $template, $old_record)
    {
        if ($old_record == 1) {
            $document = $this->findModel($id, false);
            if ($document->delete()) {
                Yii::$app->session->setFlash('success', 'Документ был удален.');
            } else {
                Yii::$app->session->setFlash('error', 'Ошибка удалении.');
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @return string|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionManyDelete()
    {
        if (Yii::$app->request->isPost && Yii::$app->request->post()) {
            $data = Yii::$app->request->post();
            foreach ($data as $records) {
                $count = count($records);
                $i = 0;
                foreach ($records as $key => $record) {
                    $document = $this->findModel($key, false, false);
                    if ($document && $document->delete()) {
                        $i++;
                    }
                }
            }
            if ($count == $i) {
                Yii::$app->session->setFlash('success', 'Удалено ' . $i . ' из ' . $count . ' документов.');
            } else {
                Yii::$app->session->setFlash('error', 'Ошибка при удалении писем. ' . 'Было удалено ' . $i . ' из ' . $count);
            }

            return $this->redirect('/urotdel/default/index');
        }
        return false;
    }

    /**
     * @param $actionType
     * @param $type
     * @param $multiple
     * @return string|void
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionManyDocumentPrint($actionType, $multiple)
    {
        $ID = explode(',', $multiple);

        $model = $this->findModels($ID);


        return $this->_documentPrint($model, $actionType, '@frontend/themes/kub/modules/urotdel/views/default/pdf-view', [
            'addStamp' => ($actionType === 'pdf') ? Yii::$app->request->get('addStamp', Yii::$app->user->identity->company->pdf_signed) : false,
        ]);
    }

    /**
     * @param $actionType
     * @param $id
     * @param $type
     * @param $filename
     * @param null $print
     * @return string|void
     */
    public function actionDocumentPrint($actionType, $id, $type, $filename, $print = null)
    {
        $model = $this->findModel($id);
        $isAddStamp = ($actionType === 'pdf') ? Yii::$app->request->get('addStamp', Yii::$app->user->identity->company->pdf_signed) : false;
        $params = [
            'addStamp' => $isAddStamp,
        ];
        $viewL = Yii::$app->request->get('viewL');
        if ($viewL !== null) {
            $params['viewL'] = $viewL;
        }
        $this->layout = null;
        return $this->_documentPrint(
            $model,
            $actionType,
            '@frontend/themes/kub/modules/urotdel/views/default/pdf-view',
            $params,
            $print
        );
    }

    /**
     * @param $model
     * @param $actionType
     * @param $view
     * @param array $params
     * @return string|void
     */
    protected function _documentPrint($model, $actionType, $view, $params = [], $print = null)
    {
        $params['documentFormat'] = 'A4-P';
        return parent::_documentPrint($model, DocumentPrintAsset::class, 'pdf', $view, $params);
        foreach ($model as $document) {
            if ($document->status_id != UrotdelStatus::STATUS_SIGNED) {
                $document->updateStatus(UrotdelStatus::STATUS_PRINTED);
            } else {
                $document->status_id = UrotdelStatus::STATUS_PRINTED;
                LogHelper::logUrotdel($document, LogEntityType::TYPE_DOCUMENT, LogEvent::LOG_EVENT_UPDATE_STATUS);
            }
            $document_number = $document->document_number;
        }
        $renderer = new PdfRenderer([
            'view' => $view,
            'params' => array_merge([
                'model' => $model,
                'actionType' => $actionType,
                'print' => $print
            ], $params),
            'destination' => PdfRenderer::DESTINATION_STRING,
            'filename' => $document->getFileName() . ".pdf",
            'displayMode' => ArrayHelper::getValue($params, 'displayMode', PdfRenderer::DISPLAY_MODE_FULLPAGE),
            'format' => ArrayHelper::getValue($params, 'documentFormat', 'A4-P'),
        ]);

        switch ($actionType) {
            case 'pdf':
                return $renderer->output();
            case 'print':
            default:
                if ($this->action->id != 'out-view') {
                    Yii::$app->view->registerJs('window.print();');
                }
                return $renderer->renderHtml();
        }
    }

    /**
     * @param $id
     * @param $type
     * @return mixed
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionDocx($id = null, $uid = null)
    {
        $model = $this->findModel($id);

        if ($model) {

            $docTypes = [
                \common\components\phpWord\urotdel\PhpWordUrotdelEmployeeMaterialLiability::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelExtraAgreement::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelFamiliarizationSheetRegulatotyAct::class,
                PhpWordUrotdelEmployeeTerminationLetter::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelTransferToRemoteWork::class,
                PhpWordUrotdelEmployeeTerminationLetter::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelOrderAccountantAssignment::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelEmployeeDisciplinaryActionLetter::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelEmployeeRewarding::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelEmployeeHiringLetter::class,
                PhpWordUrotdelEmployeeTerminationLetter::class,
                PhpWordUrotdelEmployeeTerminationLetter::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelEmploymentContract::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelTradeSecretClause::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelMaintainTradeSecret::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelOrderCreateRegulatoryAct::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelOrderDirectorAssignment::class,
                \common\components\phpWord\urotdel\PhpWordUrotdelDraftboardNotification::class,
            ];

            $word = new $docTypes[$model->type - 1]($model); //array index starts with 0

            return $word->getFile();
        } else {
            throw new NotFoundHttpException('Запрошенная страница не существует.');
        }
    }

    /**
     * @param $actionType
     * @param $id
     * @param $type
     * @param $filename
     * @param null $print
     * @return string|void
     */
    public function actionDocumentDownload($actionType, $id = null, $print = null)
    {
        $model = $this->findModel($id);

        $isAddStamp = ($actionType === 'pdf') ? Yii::$app->request->get('addStamp', Yii::$app->user->identity->company->pdf_signed) : false;
        $params = [
            'addStamp' => $isAddStamp,
        ];
        $viewL = Yii::$app->request->get('viewL');
        if ($viewL !== null) {
            $params['viewL'] = $viewL;
        }
        return $this->_documentDownload(
            $model,
            $actionType,
            '@frontend/themes/kub/modules/urotdel/views/default/pdf-view',
            $params,
            $print);
    }

    /**
     * @param $model
     * @param $actionType
     * @param $view
     * @param array $params
     * @return string|void
     */
    protected function _documentDownload($model, $actionType, $view, $params = [], $print = null)
    {
        $renderer = new PdfRenderer([
            'view' => $view,
            'params' => array_merge([
                'model' => $model,
                'actionType' => $actionType,
                'print' => $print
            ], $params),
            'destination' => PdfRenderer::DESTINATION_BROWSER_DOWNLOAD,
            'filename' => $model->getFileName() . ".pdf",
            'displayMode' => ArrayHelper::getValue($params, 'displayMode', PdfRenderer::DISPLAY_MODE_FULLPAGE),
            'format' => ArrayHelper::getValue($params, 'documentFormat', 'A4'),
        ]);

        if (isset($params['viewL']))
            return $renderer->outputTwoOrientations();
        else
            return $renderer->output();
    }

    /**
     * @param $type
     * @param $id
     *
     * @return array
     * @throws NotFoundHttpException
     */
    public function actionCommentInternal($type, $id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model = $this->findModel($id, Yii::$app->user->identity->company->id);

        $model->comment_internal = Yii::$app->request->post('comment_internal', '');
        $model->save(true, ['comment_internal']);

        $model = $this->findModel($id, Yii::$app->user->identity->company->id);

        return ['value' => $model->comment_internal];
    }

    /**
     * @param $id
     * @param $type
     * @return Response|array
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionSendManyInOne($type, $id)
    {
        /* @var LaborTemplates $model */
        $model = $this->findModel($id, Yii::$app->user->identity->company->id);

        $sendForm = new InvoiceSendForm(Yii::$app->user->identity->currentEmployeeCompany, [
            'model' => $model,
            'textRequired' => true,
        ]);

        if (Yii::$app->request->isAjax && $sendForm->load(Yii::$app->request->post())) {
            Yii::$app->response->format = Response::FORMAT_JSON;

            return ActiveForm::validate($sendForm);
        }

        if ($sendForm->load(Yii::$app->request->post()) && $sendForm->validate()) {

            $sendWithDocs = Yii::$app->request->post('send_with_documents');
            $ids = Yii::$app->request->post('ids', [$model->id]);

            $models = [];
            foreach ($ids as $id) {
                $models[] = $this->findModel($id, Yii::$app->user->identity->company->id);
            }

            $saved = $sendForm->sendLaborManyInOne($models, $sendWithDocs);

            foreach ($models as $model) {
                if ($model->status_id != UrotdelStatus::STATUS_SIGNED) {
                    $model->updateStatus(UrotdelStatus::STATUS_SENDED);
                } else {
                    $model->status_id = UrotdelStatus::STATUS_SENDED;
                    LogHelper::logUrotdel($model, LogEntityType::TYPE_DOCUMENT, LogEvent::LOG_EVENT_UPDATE_STATUS);
                }
            }

            if (is_array($saved)) {
                Yii::$app->session->setFlash('success', 'Отправлено ' . $saved[0] . ' из ' . $saved[1] . ' писем.');
            } else {
                Yii::$app->session->setFlash('error', 'Ошибка при отправке писем.');
            }
        }
        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * @return array|Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionManySend()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        /** @var Company $sender */
        $company = Yii::$app->user->identity->company;
        /** @var EmployeeCompany $sender */
        $sender = Yii::$app->user->identity->currentEmployeeCompany;

        $sendWithDocuments = Yii::$app->request->get('send_with_documents');

        $contractors = null;
        $documents = Yii::$app->request->post('Invoice');
        $send = 0;
        foreach ($documents as $id => $document) {
            if ($document['checked']) {
                /* @var $model LaborTemplates */
                $model = $this->findModel($id, true, false);
                if (empty($model) || !Yii::$app->user->can(permissions\document\Document::VIEW, [
                    'ioType' => $model->type,
                    'model' => $model,
                ])) {
                    continue;
                }
                if (($sendTo = $model->contractor->someEmail) &&
                    $model->sendAsEmail($sender, $sendTo, null, null, null, null, null, false, $sendWithDocuments)
                ) {
                    $send += 1;
                } else {
                    $contractors[$model->contractor->id] = Html::a($model->contractor->nameWithType, Url::to([
                        '/contractor/view',
                        'type' => $model->contractor->type, 'id' => $model->contractor->id
                    ]));
                }
            }
        }
        $message = 'Отправлено ' . $send . ' из ' . count($documents) . ' писем.';
        if ($send == count($documents)) {
            Yii::$app->session->setFlash('success', $message);
        }
        $notSend = false;
        if ($contractors) {
            $notSend = 'Для отправки счетов, необхоидмо заполнить E-mail у покупателей<br>';
            foreach ($contractors as $contractorName) {
                $notSend .= $contractorName . '<br>';
            }
        }
        return $notSend ? ['notSend' => $notSend, 'message' => $message] :
            $this->redirect(Yii::$app->request->referrer ?: ['labor']);
    }

    public function actionGetManySendMessagePanel()
    {
        $typeDocument = Yii::$app->request->post('typeDocument');
        $sendWithDocs = Yii::$app->request->post('sendWithDocs');
        $useContractor = Yii::$app->request->post('useContractor');
        $ids = Yii::$app->request->post('ids');

        if (!$ids) {
            throw new NotFoundHttpException('Empty Ids');
        }

        $models = [];
        foreach ($ids as $id) {
            if ($model = $this->findModel($id, true, false)) {
                $models[] = $model;
            }
        }

        return $this->renderAjax('@frontend/themes/kub/modules/urotdel/views/default/_many_send_message_form', [
            'models' => $models,
            'showSendPopup' => false,
            'typeDocument' => $typeDocument,
            'sendWithDocs' => $sendWithDocs,
            'useContractor' => $useContractor
        ]);
    }

    /**
     * Document files preview widget
     *
     * @return mixed
     */
    public function actionFiles($type, $id)
    {
        $model = $this->findModel($id);
        return $this->renderAjax('files', [
            'model' => $model,
            'files' => Scan::find()->where(['owner_id' => $model->id, 'owner_table' => LaborTemplates::tableName()])->all()
        ]);
    }

    /**
     * Update document status
     *
     * @return array|Response|boolean
     */
    public function actionUpdateStatus($id, $status_id)
    {
        $model = $this->findModel($id);
        if ($model->updateStatus($status_id)) {
            if ($status_id == UrotdelStatus::STATUS_SIGNED) {
                Yii::$app->session->setFlash('success', 'Документ подписан.');
            }
            return $this->redirect(Yii::$app->request->referrer);
        }
        return false;
    }

    /**
     * Return previous document status
     *
     * @return array|Response|boolean
     */
    public function actionReturnPreviousStatus($id)
    {
        $model = $this->findModel($id);
        if ($model->returnStatus()) {
            return $this->redirect(Yii::$app->request->referrer);
        }
        return false;
    }

    /**
     * @return string|void
     */
    public function actionSurvey()
    {
        $form = new LaborDocumentsFrom();
        if ($form->load(Yii::$app->request->post()) && $form->send(Yii::$app->user->identity)) {
            Yii::$app->session->setFlash('success', 'Ваши пожелания успешно отправлены.');

            return $this->redirect('survey');
        }

        return $this->render('survey', [
            'model' => $form,
        ]);
    }

    /**
     * @return int
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionVisit(): int
    {
        $visitId = Yii::$app->request->post('visitId');
        $visit = null;
        if ($visitId !== null) {
            $visit = $this->findVisitModel((int)$visitId);
        }

        $form = new VisitForm();
        $form->duration = Yii::$app->request->post('duration');
        $visit = $form->save(Yii::$app->user->identity->company, $visit);
        if ($visit !== null) {
            return $visit->id;
        }

        return 0;
    }

    /**
     * @return string
     * @throws \Throwable
     */
    public function actionDocumentOnlinePayment($id)
    {
        $model = new UrotdelDocumentItemPaymentForm(
            $document = $this->findModel($id, false),
            Yii::$app->user->identity->currentEmployeeCompany
        );

        if ($model->load(Yii::$app->request->post())) {
            if ($model->validate() && $model->makePayment()) {
                if ($model->paymentTypeId == PaymentType::TYPE_ONLINE) {
                    if ($pdf = $model->getPdfContent()) {
                        $uid = uniqid();
                        $session = Yii::$app->session;
                        $session->set('paymentDocument.uid', $uid);
                        $session->set("paymentDocument.{$uid}", $pdf);
                    }
                    $paymentForm = new OnlinePaymentForm([
                        'scenario' => OnlinePaymentForm::SCENARIO_SEND,
                        'company' => $model->company,
                        'payment' => $model->payment,
                        'user' => Yii::$app->user->identity,
                    ]);

                    return $this->renderPartial('online-payment', [
                        'paymentForm' => $paymentForm,
                    ]);
                }
            } else {
                Yii::$app->session->setFlash('error', 'Ошибка при создании платежа. Попробуйте ещё раз.');
            }
        }

        return $this->redirect(Yii::$app->request->referrer ? : ['view', 'id' => $document->id]);
    }

    /**
     * @param int $visitId
     * @return UrotdelVisit|null
     */
    private function findVisitModel(int $visitId): ?UrotdelVisit
    {
        return UrotdelVisit::findOne(['id' => $visitId]);
    }

    /**
     * @param int $visitId
     * @return UrotdelVisit|null
     */
    private function canBePaidFree(LaborTemplates $model): bool
    {
        $company = Yii::$app->user->identity->company;
        if (isset(Yii::$app->params['labor.free_docs_count']) && is_int(Yii::$app->params['labor.free_docs_count'])) {
            $freeLimit = max(0, Yii::$app->params['labor.free_docs_count']);
            if ($freeLimit) {
                $query = $company->getLaborTemplates()->andWhere(['is_paid' => true])->limit(1);
                if ($offset = $freeLimit - 1) {
                    $query->offset($offset);
                }
                if ($model->id) {
                    $query->andWhere(['not', ['id' => $model->id]]);
                }

                return !$query->exists();
            }
        }

        return false;
    }

    /**
     * @param $id
     * @return array|ActiveRecord
     * @throws NotFoundHttpException
     */
    public function findModels($id, $paid = true): array
    {
        $company = Yii::$app->user->identity->company ?? null;

        return $company ? LaborTemplates::find()
            ->where(['id' => $id, 'is_deleted' => false, 'company_id' => $company->id])
            ->andFilterWhere(['is_paid' => $paid ? 1 : null])
            ->all() : [];
    }

    /**
     * @param $id
     * @return array|ActiveRecord
     * @throws NotFoundHttpException
     */
    public function findModel($id, $paid = true, $throw = true): LaborTemplates
    {
        $company = Yii::$app->user->identity->company ?? null;
        $model = $company ? LaborTemplates::find()
            ->where(['id' => $id, 'is_deleted' => false, 'company_id' => $company->id])
            ->andFilterWhere(['is_paid' => $paid ? 1 : null])
            ->one() : null;

        if ($model === null && $throw) {
            throw new NotFoundHttpException('Model loading failed. Model not found.');
        }

        return $model;
    }

    /**
     * @param $uid
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionOutView($uid, $view = 'print')
    {
        $this->layout = 'out-view';
        /* @var LaborTemplates $model */
        $model = LaborTemplates::find()
            ->andWhere([
                'uid' => $uid,
                'is_paid' => true,
                'is_deleted' => false,
            ])
            ->one();

        if ($model === null || !$model->company || $model->company->blocked) {
            throw new NotFoundHttpException('Запрошенная страница не существует');
        }

        return $this->render('out-view', [
            'model' => $model,
            'isOutView' => true,
            'addStamp' => true,
        ]);
    }
}
