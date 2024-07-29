<?php
declare(strict_types=1);

namespace frontend\modules\urotdel\models;

use common\models\Company;
use common\models\UrotdelVisit;
use Webmozart\Assert\Assert;
use yii\base\Model;

final class VisitForm extends Model {

    public $duration;

    public function __construct($config = []) {
        parent::__construct($config);
    }

    public function rules(): array {
        return [
            [['duration'], 'required'],
            [['duration'], 'integer', 'min' => 1],
        ];
    }

    public function save(Company $company, ?UrotdelVisit $visit): ?UrotdelVisit {
        if (!$this->validate()) {
            return null;
        }

        if ($visit !== null) {
            $visit->duration += $this->duration;
            Assert::true($visit->save(), 'Cannot update urotdel visit record.');

            return $visit;
        }

        $visit = new UrotdelVisit();
        $visit->company_id = $company->id;
        $visit->duration = $this->duration;
        Assert::true($visit->save(), 'Cannot save urotdel visit record.');

        return $visit;
    }

}
