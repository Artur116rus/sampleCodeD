<?php

namespace api\modules\msn\models\search\Msn;

use api\modules\msn\models\Msn;
use common\behaviors\TransformSeriesBehavior;
use common\modules\core\data\SqlDataProvider;
use common\modules\core\helpers\QueryHelper;
use common\modules\msn\models\MsnGarbageSource;
use common\modules\msn\models\MsnType;
use common\VisibleForUserTrait;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\helpers\ArrayHelper;

class MsnSearch extends Model
{
    use VisibleForUserTrait;

    /**
     * @var array
     */
    public $msn_type_code;
    /**
     * @var array
     */
    public $not_msn_type_code;
    /**
     * @var string|null Поисковая строка
     */
    public $q = null;

    /**
     * @var integer
     */
    public $garbage_source_id;
    public $tko_operator_id;
    public $msn_type_id;
    public $geo_object;
    public $name;
    public $address;
    public $status;
    public $with_coords;
    /**
     * Муниципальное образование
     *
     * @var integer
     */
    public $mo_id;
    /**
     * Год в контексте которого необходимы данные (только там, где есть срез годов)
     *
     * @var integer
     */
    public $year;

    /**
     * @return array
     */
    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['msn_type_code', 'not_msn_type_code'], 'each', 'rule' => 'string'],
            ['with_coords', 'boolean'],
            [['q', 'geo_object', 'name', 'address'], 'string'],
            [['garbage_source_id', 'tko_operator_id', 'msn_type_id', 'status', 'mo_id', 'year'], 'integer'],
        ]);
    }

    public function behaviors()
    {
        return [
            'class' => TransformSeriesBehavior::class,
            'attributes' => ['msn_type_code', 'not_msn_type_code']
        ];
    }

    /**
     * @param array $params
     * @return ActiveDataProvider
     */
    public function search(array $params): ActiveDataProvider
    {
        $year = $this->year ?? date('Y');
        $this->load($params, '');
        $query = Msn::find()
            ->select([
                'msn.*',
                'ROUND(SUM(mc.volume * mc.count)::numeric, 2) AS volume_count',
                'SUM(mc."count") containers_count',
                'apo.name AS apo_name',
                'tko_operator.name AS tko_operator_name'
            ])
            ->leftJoin('msn_container mc', 'msn.id = mc.msn_id AND mc.year = :year')
            ->joinWith('msnType mt')
            ->joinWith('geoObject')
            ->joinWith('geoObject.mo mo')
            ->leftJoin('apo_geo_object', 'COALESCE(geo_object.mo_id, geo_object.id) = apo_geo_object.geo_object_id')
            ->leftJoin('apo', "apo_geo_object.apo_id = apo.id AND :year BETWEEN apo.year_start AND apo.year_finish AND apo.status='fact'")
            ->leftJoin('tko_operator', 'apo.tko_operator_id = tko_operator.id')
            ->joinWith('addres')
            ->joinWith('status')
            ->with('routePeriod')
            ->with('addres.geoObject')
            ->with('recyclableTypes')
            ->with('woMsnMessages')
            ->addParams([':year' => $year]);

        $this->applyVisibleRules($query, 'msn');

        if ($this->with_coords) {
            $query->andWhere('latitude IS NOT NULL AND longitude IS NOT NULL');
        }
        if ($this->year) {
            $withGarbageSourceQuery = MsnGarbageSource::find()
                ->distinct()
                ->select('msn_id')
                ->andWhere(['year' => $year])
                ->andFilterWhere(['garbage_source_id' => $this->garbage_source_id]);

            $query->withQuery($withGarbageSourceQuery, 'gs_refs')
                ->innerJoin('gs_refs', 'msn.id = gs_refs.msn_id');
        }

        $query
            ->andFilterWhere(['OR', ['geo_object.id' => $this->mo_id], ['geo_object.mo_id' => $this->mo_id]])
            ->andFilterWhere(['ilike', 'msn.name', $this->name])
            ->andFilterWhere(['ilike', 'geo_object.name', $this->geo_object])
            ->andFilterWhere(['ilike', 'address.source', $this->address])
            ->andFilterWhere(['NOT IN', 'msn.msn_type_id', $this->msn_type_id ?? MsnType::getIdByCode($this->not_msn_type_code)])
            ->andFilterWhere([
                'msn.status_id' => $this->status,
                'msn.msn_type_id' => $this->msn_type_id ?? MsnType::getIdByCode($this->msn_type_code),
                'msn.tko_operator_id' => $this->tko_operator_id,
            ]);

        QueryHelper::bindContextSearch($query, [
            'msn.name',
            'msn.address',
            'msn.owner',
            'msn.orientir',
            'geo_object.name_with_type',
            'geo_object.name',
            'address.source',
            'address.value',
            'address.settlement_with_type',
            'address.unrestricted_value',
            'address.street',
            'address.house',
        ], $this->q);

        $query->groupBy([
            'msn.id',
            'geo_object.mo_id',
            'geo_object.name',
            'mo.name',
            'apo.name',
            'tko_operator.name'
        ]);

        return $this->getDataProvider($query);
    }

    /**
     * @param Query $query
     * @return ActiveDataProvider
     */
    private function getDataProvider(Query $query): ActiveDataProvider
    {
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);
        $dataProvider->sort->defaultOrder = [
            'id' => SORT_DESC
        ];

        $dataProvider->sort->attributes['geo_object'] = [
            'asc'  => ['(CASE WHEN geo_object.mo_id IS NOT NULL THEN mo.name ELSE geo_object.name END)' => SORT_ASC],
            'desc' => ['(CASE WHEN geo_object.mo_id IS NOT NULL THEN mo.name ELSE geo_object.name END)' => SORT_DESC],
        ];
        $dataProvider->sort->attributes['volume_count'] = [
            'asc'  => ['COALESCE(SUM(mc."volume"), 0)' => SORT_ASC],
            'desc' => ['COALESCE(SUM(mc."volume"), 0)' => SORT_DESC],
        ];
        $dataProvider->sort->attributes['containers_count'] = [
            'asc'  => ['COALESCE(SUM(mc."count"), 0)' => SORT_ASC],
            'desc' => ['COALESCE(SUM(mc."count"), 0)' => SORT_DESC],
        ];
        $dataProvider->sort->attributes['apo.name'] = [
            'asc'  => ['apo.name' => SORT_ASC],
            'desc' => ['apo.name' => SORT_DESC],
        ];
        $dataProvider->sort->attributes['tko_operator.name'] = [
            'asc'  => ['tko_operator.name' => SORT_ASC],
            'desc' => ['tko_operator.name' => SORT_DESC],
        ];

        return $dataProvider;
    }

    /**
     * @param int $id
     * @return SqlDataProvider
     */
    public function searchRouteForMsnId(int $id): SqlDataProvider
    {
        $sql = 'WITH target_route as (
                    SELECT *
                    FROM route_point
                    WHERE msn_id = :id
                )
                SELECT r.id AS id,
                       r.name AS name,
                       rp.name AS route_period,
                       w.name AS w_name,
                       t.name AS object,
                       a.source AS address,
                       reg_operator.name AS reg_name,
                       transporter.name AS transporter
                FROM route_point
                         INNER JOIN route r ON route_point.route_id = r.id
                         LEFT JOIN weekday w ON r.weekday_id = w.id
                         LEFT JOIN route_period rp ON r.period_id = rp.id
                         LEFT JOIN tko_object t ON route_point.tko_object_id = t.id
                         LEFT JOIN tko_operator as reg_operator ON reg_operator.id = r.tko_operator_regional_id
                         LEFT JOIN tko_operator as transporter ON transporter.id = r.tko_operator_transporter_id
                         LEFT JOIN address a on t.address_id = a.id
                WHERE route_id IN (SELECT route_id FROM target_route)';

        return new SqlDataProvider([
            'sql' =>  Yii::$app->db->createCommand($sql, ['id' => $id])->getRawSql(),
            'pagination' => false
        ]);
    }

    public function searchMaintenance(int $id)
    {
        $currentYear = date('Y');
        $sql = 'WITH RECURSIVE r AS (
                    SELECT g.id, g.parent_id
                    FROM msn m
                        INNER JOIN geo_object g on m.geo_object_id = g.id
                    WHERE m.id = :id
                    UNION ALL
                    SELECT go.id, go.parent_id FROM geo_object go
                                          INNER JOIN r ON go.id =  r.parent_id
                ),geo_id AS (
                    SELECT * FROM r WHERE parent_id IS NULL
                )
                SELECT STRING_AGG(action.name, \', \') as action, adr.source,
                   object.name as obj_name, tkooper.name as tkooper_name
                FROM geo_id
                        INNER JOIN threads t on geo_id.id = t.geo_object_id
                        INNER JOIN threads_points tp on t.id = tp.thread_id
                        INNER JOIN tko_object object on tp.tko_object_id = object.id
                        INNER JOIN address adr on adr.id = object.address_id
                        LEFT JOIN tko_operator tkooper on object.tko_operator_id = tkooper.id
                        LEFT JOIN tko_object_action toa on object.id = toa.tko_object_id
                        LEFT JOIN tko_action action on toa.tko_action_id = action.id
                WHERE t.year = :year  AND tp.delta = 1
                GROUP BY object.id, adr.source, object.name,tkooper.name';

        return new SqlDataProvider([
            'sql' =>  Yii::$app->db->createCommand($sql, ['id' => $id, 'year' => $currentYear])->getRawSql(),
            'pagination' => false
        ]);
    }
}