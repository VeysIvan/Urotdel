<?php

use yii\bootstrap\Nav;

$this->beginContent('@frontend/views/layouts/main.php')

?>
<div class="debt-report-content nav-finance">
    <div class="nav-tabs-row mb-2">
        <?= Nav::widget([
            'id' => 'debt-report-menu',
            'options' => ['class' => 'nav nav-tabs nav-tabs_indents_else nav-tabs_border_bottom_grey w-100 mr-3'],
            'items' => [
                [
                    'label' => 'Трудовые документы',
                    'url' => ['/urotdel/default/labor'],
                    'active' => Yii::$app->controller->action->id == 'labor',
                    'options' => ['class' => 'nav-item'],
                    'linkOptions' => ['class' => 'nav-link'],
                ],
                [
                    'label' => 'Опрос',
                    'url' => ['/urotdel/default/survey'],
                    'active' => Yii::$app->controller->action->id == 'survey',
                    'options' => ['class' => 'nav-item'],
                    'linkOptions' => ['class' => 'nav-link'],
                ],
            ],
        ]); ?>
    </div>
    <div class="finance-index">
        <?= $content; ?>
    </div>
</div>
<?php $this->endContent(); ?>

