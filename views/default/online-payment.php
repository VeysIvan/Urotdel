<?php

use yii\helpers\Html;

/*
 * @var \yii\web\View $this
 * @var \frontend\modules\subscribe\forms\OnlinePaymentForm $paymentForm
 */

$this->title = 'Онлайн платёж';
?>
<div style="display: none;">
    <form id="robokassa-form"
          action="<?= Yii::$app->params['robokassa']['merchantFormAction']; ?>" method="POST">
        <?php foreach ($paymentForm->getFormFields() as $name => $value) {
            echo Html::hiddenInput($name, $value);
        } ?>
        <br/>
        <br/>

        <div class="row">
            <div class="button-bottom-page-lg col-sm-1 col-xs-1" style="width: 13.5%;">
                <?= Html::submitButton('<span class="ladda-label">Оплатить</span><span class="ladda-spinner"></span>', [
                    'class' => 'btn btn-primary widthe-100 hidden-md hidden-sm hidden-xs ladda-button',
                    'data-style' => 'expand-right',
                ]); ?>
                <?= Html::submitButton('<i class="fa fa-rub"></i>', [
                    'class' => 'btn btn-primary widthe-100 hidden-lg',
                    'title' => 'Оплатить',
                ]); ?>
            </div>
            <div class="button-bottom-page-lg col-sm-1 col-xs-1">
            </div>
            <div class="button-bottom-page-lg col-sm-1 col-xs-1">
            </div>
            <div class="button-bottom-page-lg col-sm-1 col-xs-1">
            </div>
            <div class="button-bottom-page-lg col-sm-1 col-xs-1">
            </div>
            <div class="button-bottom-page-lg col-sm-1 col-xs-1">
            </div>
            <div class="button-bottom-page-lg col-sm-1 col-xs-1">
                <?= Html::a('Отменить', ['@subscribeUrl'], [
                    'class' => 'btn btn-primary widthe-100 hidden-md hidden-sm hidden-xs',
                ]); ?>
                <?= Html::a('<i class="fa fa-reply fa-2x"></i>', ['@subscribeUrl'], [
                    'class' => 'btn btn-primary widthe-100 hidden-lg',
                    'title' => 'Отменить',
                ]); ?>
            </div>
        </div>
    </form>
</div>
<script type="text/javascript">
    document.getElementById("robokassa-form").submit();
</script>
