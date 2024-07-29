<?php
declare(strict_types=1);

namespace frontend\modules\urotdel\models;

use common\models\employee\Employee;
use Yii;
use yii\base\Model;

final class LaborDocumentsFrom extends Model {

    public static array $documentsMap = [
        0 => 'Дополнительное соглашение к трудовому договору',
        1 => 'Лист ознакомления с локальным нормативным актом',
        2 => 'Приказ о назначении директора',
        3 => 'Приказ о назначении главного бухгалтера',
        4 => 'Приказ об отстранении работника',
        5 => 'Акт об отказе работника дать письменные объяснения',
        6 => 'Приказ о создании локального нормативного акта',
        7 => 'Приказ об увольнении',
        8 => 'Приказ о наложении дисциплинарного взыскания',
        9 => 'Приказ о премировании',
        10 => 'Приказ о приёме на работу',
        11 => 'Трудовой договор',
        12 => 'Договор полной материальной ответственности',
        13 => 'Обязательство о соблюдении коммерческой тайны',
        14 => 'Положение о коммерческой тайне',
    ];

    public $documents;

    public $otherWishes;

    public function rules(): array {
        return [
            [['documents'], 'required',
                'when' => fn($model): bool => empty($this->otherWishes),
                'message' => 'Нужно выбрать минимум один вариант или заполнить поле "Ваши пожелания"',
            ],
            [['otherWishes'], 'safe'],
            [['documents'], 'validateDocuments'],
        ];
    }

    public function validateDocuments(string $attribute): void {
        if (!empty(array_diff($this->documents, array_keys(self::$documentsMap)))) {
            $this->addError($attribute, "error.{$attribute}_invalid");
        }
    }

    public function send(Employee $user): bool {
        if (!$this->validate()) {
            return false;
        }

        $message = implode("\n", [
            "ID Компании: {$user->currentEmployeeCompany->company->id}",
            "Компания: {$user->currentEmployeeCompany->company->getShortName()}",
            "ФИО: {$user->currentEmployeeCompany->getFio(true)}",
            "E-mail: {$user->currentEmployeeCompany->getEmail()}",
        ]);
        if (!empty($this->documents)) {
            $wishes = null;
            foreach ($this->documents as $document) {
                $wishes[] = self::$documentsMap[$document];
            }

            $message .= "\nПожелания: " . implode(', ', $wishes);
        }

        if (!empty($this->otherWishes)) {
            $message .= "\nДополнительные пожелания: {$this->otherWishes}";
        }

        Yii::$app->mailer->compose()
            ->setFrom(Yii::$app->params['emailList']['infoEmailFrom'])
            ->setTo('support@kub-24.ru')
            ->setSubject('Пожелания по ЮрОтделу')
            ->setTextBody($message)
            ->send();

        return true;
    }

}
