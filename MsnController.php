<?php

namespace api\modules\msn\controllers;

use api\components\rest\CallbackDataProvider;
use api\components\rest\Controller;
use api\modules\msn\forms\CreateModelingForm;
use api\modules\msn\forms\DeficitForm;
use api\modules\msn\forms\MsnForm;
use api\modules\msn\models\search\Msn\MsnSearch;
use api\modules\msn\search\MsnAddressSearch;
use api\modules\msn\serializers\WoMessageSerializer;
use api\modules\msn\services\MsnService;
use api\modules\msn\traits\MsnTrait;
use common\components\export\ExportAction;
use common\modules\core\helpers\ConfigHelper;
use common\modules\msn\jobs\MsnModelingJob;
use common\modules\msn\models\Msn;
use common\modules\msn\models\RecyclableType;
use common\queue\job\JobOverlappingException;
use common\queue\job\JobOverlappingHttpException;
use common\service\msnGarbageSourceLinker\MsnGarbageSourceLinkerService;
use RuntimeException;
use Throwable;
use Yii;
use yii\data\DataProviderInterface;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * @property yii\web\Request $request
 * @property yii\web\Response $response
 */
class MsnController extends Controller
{
    use MsnTrait;

    /**
     * @var MsnService
     */
    private $service;
    /**
     * @var WoMessageSerializer
     */
    private $woMessageSerializer;

    public function __construct(
        $id,
        $module,
        MsnService $service,
        WoMessageSerializer $woMessageSerializer,
        $config = []
    )
    {
        parent::__construct($id, $module, $config);
        $this->service = $service;
        $this->woMessageSerializer = $woMessageSerializer;
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $this->setAccessRules($behaviors, [
            [
                'allow' => true,
                'verbs' => ['GET'],
            ],
            [
                'allow' => true,
                'actions' => ['create', 'create-modeling'],
                'roles' => ['msn-create'],
            ],
            [
                'allow' => true,
                'actions' => ['update'],
                'roles' => ['msn-update'],
            ],
            [
                'allow' => true,
                'actions' => ['delete', 'delete-modeling'],
                'roles' => ['msn-delete'],
            ],
        ]);

        return $behaviors;
    }

    /**
     * @SWG\Get(
     *     path="/msn/msn/export",
     *     produces={"application/json"},
     *     summary = "Мсин",
     *
     *     tags={"Экспорт в excel"},
     *
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "format",
     *         description = "Формат экспорта",
     *         type = "string",
     *         default = "xlsx",
     *         enum = {"xlsx", "docx", "pdf", "shapefile"}
     *     ),
     *
     *     @SWG\Response(
     *         response = 200,
     *         description = "success"
     *     )
     * )
     * */
    public function actions()
    {
        return [
            'export' => [
                'class' => ExportAction::class,
                'dataProvider' => [$this, 'actionList'],
                'extendColumns' => [
                    'latitude' => 'Широта',
                    'longitude' => 'Долгота',
                ],
                'cacheSourceCallback' => function (string $format) {
                    $msnTypeCode = \Yii::$app->request->getQueryParam('msn_type_code');
                    if ($msnTypeCode) {
                        $filePath = \Yii::getAlias('@storage/export-cache/msn/' . $msnTypeCode . '.' . $format);
                        return $filePath;
                    }
                },
                'processors' => [
                    'shapefile' => [
                        'points' => [
                            'x' => 'longitude',
                            'y' => 'latitude',
                            'NAME' => 'name',
                            'ADDRESS' => 'address'
                        ]]],
                'name' => 'Места сбора и накопления',
                'permissionModuleId' => 6,
            ]
        ];
    }

    /**
     * @SWG\Get(
     *     path="/msn",
     *     produces={"application/json"},
     *     summary = "Cписок МСН",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "status",
     *         description = "ID статуса",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "msn_type_id",
     *         description = "ID типа МСН",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "msn_type_code",
     *         description = "Код типа МСН (если нескольк - через запятую, могут быть свои для части регионов):
     *     with_containers - Контейнерная площадка,
     *     with_containers_model - Контейнерная площадка (модельная),
     *     without_containers - Бесконтейнерный сбор,
     *     separate_collection - Пункт сбора вторсырья,
     *     mercurial_collection - Места хранения отработанных ртутьсодержащих ламп,
     *     mercurialtemp_collection - Места хранения отработанных ртутьсодержащих т.,
     *     needed_containers_and_bunkers - Необходимое количестве контейнеров и бункеров,
     *     planned_buy - Планируемые к приобретению,
     *     special_sites_kgo - Специальная площадка для КГО,
     *     individual_collection - Индивидуальный сбор,
     *     recyclables_collection - Пункты сбора вторсырья",
     *         type = "string"
     *     ),
     * @SWG\Parameter(
     *         in = "query",
     *         name = "msn_type_code",
     *         description = "Исключить код типа МСН (несколько, через запятую)",
     *         type = "string"
     *     ),
     * @SWG\Parameter(
     *         in = "query",
     *         name = "garbage_source_id",
     *         description = "ID отходообразователей",
     *         type = "integer",
     *         @SWG\Items(type="integer")
     *     ),
     * @SWG\Parameter(
     *         in = "query",
     *         name = "tko_operator_id",
     *         description = "Оператор (вывоз)",
     *         type = "integer"
     *     ),
     * @SWG\Parameter(
     *         in = "query",
     *         name = "with_coords",
     *         description = "С координатами",
     *         type = "integer",
     *         default = "",
     *         enum = {0, 1}
     *     ),
     * @SWG\Parameter(
     *         in = "query",
     *         name = "mo_id",
     *         description = "ID муниципального образования",
     *         type = "integer",
     *     ),
     * @SWG\Parameter(
     *         in = "query",
     *         name = "year",
     *         description = "Год, в контексте которого необходимы данные",
     *         type = "integer",
     *     ),
     * @SWG\Parameter(ref="#/parameters/q"),
     * @SWG\Parameter(ref="#/parameters/page"),
     * @SWG\Parameter(ref="#/parameters/per-page"),
     * @SWG\Parameter(ref="#/parameters/sort"),
     *
     * @SWG\Response(
     *         response = 200,
     *         description = "success"
     *     )
     * )
     *
     * @return DataProviderInterface
     * @throws UnauthorizedHttpException
     */
    public function actionList(): DataProviderInterface
    {
        $params = Yii::$app->request->queryParams;
        $search = new MsnSearch();
        $dataProvider = $search->search($params);
        $labels = ConfigHelper::getMsnMetaFields($params['msn_type_code'] ?? null);

        return new CallbackDataProvider($dataProvider, [$this, 'view'], $labels);
    }

    /**
     * @param \api\modules\msn\models\Msn $msn
     * @return array
     */
    public function view(\api\modules\msn\models\Msn $msn): array
    {
        $geoObjectName = $msn->geoObject->mo->name_with_type
            ?? $msn->geoObject->name_with_type
            ?? $msn->addres->geoObject->mo->name_with_type
            ?? $msn->addres->geoObject->name_with_type
            ?? null;
        $address = null;
        if (!empty($msn->address)) {
            $address = $msn->address;
        } elseif (!empty($msn->addres)) {
            $address = $msn->addres->format();
        }

        return [
            'id' => $msn->id,
            'address' => $address,
            'address_id' => $msn->address_id,
            'address_detected' => $msn->address_detected,
            'containers_count' => $msn->containers_count,
            'daily_norm' => $msn->daily_norm,
            'storage_location' => $msn->storage_location,
            'responsible_executor' => $msn->responsible_executor,
            'responsible_executor_phone' => $msn->responsible_executor_phone,
            'geo_object' => $geoObjectName,
            'route_period' => $msn->routePeriod,
            'is_separate' => $msn->is_separate,
            'name' => $msn->name,
            'owner' => $msn->owner,
            'status' => $msn->status->name ?? null,
            'volume_count' => $msn->volume_count,
            'orientir' => $msn->orientir,
            'recyclableTypes' => $msn->getRecyclableTypesString(),
            'latitude' => $msn->latitude,
            'longitude' => $msn->longitude,
            'epidemiological_conclusion' => $msn->epidemiological_conclusion,
            'apo_name' => $msn->apo_name,
            'tko_operator_name' => $msn->tko_operator_name,
            'created_at' => $msn->created_at,
            'msn_type' => $msn->msnType->toArray(['id', 'code', 'name'])
        ];
    }

    /**
     * @SWG\Get(
     *     path="/msn/addresses",
     *     produces={"application/json"},
     *     summary = "Получение адресов МСН",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "fkko_ids",
     *         description = "ФККО собираемых отходов (через запятую 1,2,3)",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "danger_classes",
     *         description = "Классы опасности ФККО собираемых отходов (через запятую 1,2,3)",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "container_id",
     *         description = "Вид контейнера",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "container_type_id",
     *         description = "Тип контейнера",
     *         type = "string"
     *     ),
     *
     *     @SWG\Response(
     *         response = 200,
     *         description = "success"
     *     ),
     *     @SWG\Response(
     *         response = 422,
     *         description = "Ошибка валидации"
     *     )
     * )
     *
     * @return MsnAddressSearch|array
     */
    public function actionAddresses()
    {
        $searchModel = new MsnAddressSearch();
        $searchModel->setAttributes($this->request->queryParams);
        if (!$searchModel->validate()) {
            return $searchModel;
        }
        return $searchModel->search();
    }

    /**
     * @SWG\Get(
     *     path="/msn/{id}",
     *     produces={"application/json"},
     *     summary = "Получение МСН по ID",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "path",
     *         name = "id",
     *         description = "ID МСН",
     *         required = true,
     *         type = "integer"
     *     ),
     *
     *     @SWG\Response(
     *         response = 200,
     *         description = "success"
     *     )
     * )
     *
     * @param int $id
     * @return array
     * @throws NotFoundHttpException
     */
    public function actionOne(int $id): array
    {
        return $this->viewOne($this->findModel($id));
    }

    public function viewOne(Msn $msn): array
    {
        $area = $msn->msnContainerArea ?? null;
        if (!empty($msn->address)) {
            $address = $msn->address;
        } elseif (!empty($msn->addres)) {
            $address = $msn->addres->format();
        } else {
            $address = null;
        }

        $geoObjectMO = null;
        if ($msn->geoObject !== null) {
            $geoObjectMO = $msn->geoObject->mo();
        }

        return [
            'id' => $msn->id,
            'area' => $msn->area,
            'documents' => $msn->documents,
            'address' => $address,
            'address_id' => $msn->address_id,
            'latitude' => $msn->getLatitude(),
            'longitude' => $msn->getLongitude(),
            'containers_count' => $msn->containers_count,
            'daily_norm' => $msn->daily_norm,
            'daily_norm_cub' => $msn->daily_norm_cub,
            'storage_location' => $msn->storage_location,
            'responsible_executor' => $msn->responsible_executor,
            'responsible_executor_phone' => $msn->responsible_executor_phone,
            'geo_object' => $msn->geoObject->name ?? null,
            'route_period' => $msn->routePeriod,
            'is_separate' => $msn->is_separate,
            'is_temporarily' => $msn->is_temporarily,
            'name' => $msn->name,
            'owner' => $msn->owner,
            'photo_id' => $msn->photo_id,
            'photoPreview' => $msn->photo ?? null,
            'photo_url' => $msn->photo ? $msn->photo->getUrl() ?? null : null,
            'detail' => $msn->detail,
            'status' => $msn->status->name ?? null,
            'volume_count' => $msn->volume_count,
            'orientir' => $msn->orientir,
            'recyclableTypes' => $msn->recyclableTypes,
            'msn_container_area_fence_id' => $area->msn_container_area_fence_id ?? null,
            'msn_container_area_floor_id' => $area->msn_container_area_floor_id ?? null,
            'msn_container_area_roof_id' => $area->msn_container_area_roof_id ?? null,
            'status_id' => $msn->status_id,
            'msn_by_type_id' => $msn->msn_by_type_id,
            'close_date' => Yii::$app->formatter->format($msn->close_date, 'date'),
            'container_area' => $msn->msnContainerArea,
            'accepted_wastes' => array_map(static function (RecyclableType $recyclableType) {
                return $recyclableType->id;
            }, $msn->recyclableTypes),
            'wastes' => array_map([$this->woMessageSerializer, 'serialize'],
                $msn->woMsnMessagesLast),
            'avg' => $this->woMessageSerializer->avg($msn->woMsnMessagesLast),
            'avgFilling' => 0,
            'garbage_sources' => $msn->garbageSources,
            'operations' => $msn->msnOperations,
            'fkko' => $msn->fkko,
            'collecting_type' => $msn->collectingType,
            'square_container' => $msn->square_container,
            'information_about_container' => $msn->information_about_container,
            'outer_id' => $msn->outer_id,
            'epidemiological_conclusion' => $msn->epidemiological_conclusion,
            'section_presence_for_big_garbage' => $msn->section_presence_for_big_garbage,
            'owner_obj' => $msn->getOwner()->one(),
            'apo_name' => $geoObjectMO->apo->name ?? null,
            'tko_operator_name' => $geoObjectMO->apo->tkoOperator->name ?? null,
            'created_at' => $msn->created_at
        ];
    }

    /**
     * @SWG\Get(
     *     path="msn/route/{id}",
     *     produces={"application/json"},
     *     summary = "Получение Маршрутов по msn_id",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "path",
     *         name = "id",
     *         description = "ID МСН",
     *         required = true,
     *         type = "integer"
     *     ),
     *
     *     @SWG\Response(
     *         response = 200,
     *         description = "success"
     *     )
     * )
     *
     * @param int $id
     * @return CallbackDataProvider
     */
    public function actionRoute(int $id): CallbackDataProvider
    {
        $searchModel = new MsnSearch();
        $dataProvider = $searchModel->searchRouteForMsnId($id);
        return new CallbackDataProvider($dataProvider, [$this, 'viewRoute'], [
            'name' => ['label' => 'Номер маршрута'],
            'route_period' => ['label' => 'Период'],
            'object' => ['label' => 'Объект обращения'],
            'address' => ['label' => 'Месторасположение объекта обращения'],
            'reg_name' => ['label' => 'Региональный оператор'],
            'transporter' => ['label' => 'Транспортировщик'],
        ]);
    }

    public function viewRoute($object): array
    {
        return [
            'id' => $object['id'],
            'name' => $object['name'],
            'route_period' => $object['w_name'] ? $object['route_period'] . ' (' . $object['w_name'] . ')' : $object['route_period'],
            'object' => $object['object'],
            'address' => $object['address'],
            'reg_name' => $object['reg_name'],
            'transporter' => $object['transporter']
        ];
    }

    /**
     * @SWG\Get(
     *     path="msn/maintenance/{id}",
     *     produces={"application/json"},
     *     summary = "Получение данных по обслуживанию объекта",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "path",
     *         name = "id",
     *         description = "ID МСН",
     *         required = true,
     *         type = "integer"
     *     ),
     *
     *     @SWG\Response(
     *         response = 200,
     *         description = "success"
     *     )
     * )
     *
     * @param int $id
     * @return CallbackDataProvider
     */
    public function actionMaintenance(int $id): CallbackDataProvider
    {
        $searchModel = new MsnSearch();
        $dataProvider = $searchModel->searchMaintenance($id);
        return new CallbackDataProvider($dataProvider, [$this, 'viewMaintenance'], [
            'source' => ['label' => 'Местоположение объекта'],
            'tkooper_name' => ['label' => 'Эксплуатирующая организация'],
            'obj_name' => ['label' => 'Объект обращения'],
            'action' => ['label' => 'Назначение'],
        ]);
    }

    public function viewMaintenance($object): array
    {
        return [
            'source' => $object['source'],
            'tkooper_name' => $object['tkooper_name'],
            'obj_name' => $object['obj_name'],
            'action' => $object['action'],
        ];
    }

    /**
     * @param int $id
     * @return Msn
     * @throws NotFoundHttpException
     */
    private function findModel(int $id): Msn
    {
        $model = Msn::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException();
        }

        return $model;
    }

    /**
     * @SWG\Post(
     *     path="/msn",
     *     produces={"application/json"},
     *     summary = "Добавление МСН",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_type_id",
     *         description = "Тип",
     *         required = true,
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_type_code",
     *         description = "Код типа",
     *         type = "string",
     *         enum = {"with_containers", "without_containers", "separate_collection"}
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "status_id",
     *         description = "Статус",
     *         required = true,
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_by_type_id",
     *         description = "Тип контейнерной площадки",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "name",
     *         required = true,
     *         description = "Наименование",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "address_id",
     *         description = "Местоположение",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "geo_object_id",
     *         description = "Муниципальное образование",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "route_period_id",
     *         description = "Периодичность маршрута",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "orientir",
     *         description = "Ориентир",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "detail",
     *         description = "Контактные данные",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "link",
     *         description = "Сайт",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "owner",
     *         description = "Балансодержатель / Организация",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "latitude",
     *         description = "Координаты (широта)",
     *         required = true,
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "longitude",
     *         description = "Координаты (долгота)",
     *         required = true,
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "close_date",
     *         description = "Дата закрытия (Y-m-d)",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "daily_norm",
     *         description = "Суточная норма накопления, тонн",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "daily_norm_cub",
     *         description = "Суточная норма накопления, куб. м.",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_container_area_roof_id",
     *         description = "Вид площадки",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_container_area_fence_id",
     *         description = "Тип ограждения",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_container_area_floor_id",
     *         description = "Тип подстилающей поверхности",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "is_separate",
     *         description = "Наличие раздельного сбора",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "is_temporarily",
     *         description = "Является ли площадкой временного назначения",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "volume_count",
     *         description = "Объем, куб.м.",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "photo_id",
     *         description = "Фото",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "information_about_container",
     *         description = "Информация о собственнике контейнерной площадки",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "square_container",
     *         description = "Площадь контейнерной площадки, кв.м",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "area",
     *         description = "Площадь, м2",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "accepted_wastes[]",
     *         description = "Принимаемые отходы (id)",
     *         type = "array",
     *         @SWG\Items(
     *             type="integer",
     *             @SWG\Property(property="id", type="integer")
     *         )
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "garbages[]",
     *         description = "Отходообразователи (id)",
     *         type = "array",
     *         @SWG\Items(
     *             type="integer",
     *             @SWG\Property(property="id", type="integer")
     *         )
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "recyclable_types[]",
     *         description = "Типы отходов раздельного сбора (id)",
     *         type = "array",
     *         @SWG\Items(
     *             type="integer",
     *             @SWG\Property(property="id", type="integer")
     *         )
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "owner_id",
     *         description = "Идентификатор собственника",
     *         type = "integer"
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="Успешное выполение запроса"
     *     ),
     *     @SWG\Response(
     *         response="400",
     *         description="Ошибка запроса"
     *     )
     * )
     *
     */
    public function actionCreate()
    {
        $form = new MsnForm(
            $this->accepted(),
            $this->garbage(),
            $this->recylable()
        );
        $form->load(Yii::$app->request->post(), '');
        if ($form->validate()) {
            try {
                $msn = $this->service->create($form);
                $this->service->insertFkkoMsn($msn['id']);
                return $this->viewOne($msn);
            } catch (RuntimeException $ex) {
                Yii::$app->errorHandler->logException($ex);
                throw $ex;
            }
        }

        return $form;
    }

    private function accepted(): array
    {
        $value = Yii::$app->request->post('accepted_wastes', []);
        if (!is_array($value)) {
            $value = [$value];
        }

        return $value;
    }

    private function garbage(): array
    {
        $value = Yii::$app->request->post('garbages', []);
        if (!is_array($value)) {
            $value = [$value];
        }

        return $value;
    }

    private function recylable(): array
    {
        $value = Yii::$app->request->post('recyclable_types', []);
        if (!is_array($value)) {
            $value = [$value];
        }

        return $value;
    }

    /**
     * @SWG\Patch(
     *     path="/msn/{id}",
     *     produces={"application/json"},
     *     summary = "Редактирование МСН",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "path",
     *         name = "id",
     *         description = "ID МСН",
     *         required = true,
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_type_id",
     *         description = "Тип",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_type_code",
     *         description = "Код типа",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "detail",
     *         description = "Контактные данные",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "link",
     *         description = "Сайт",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_by_type_id",
     *         description = "Тип контейнерной площадки",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "status_id",
     *         description = "Статус",
     *         required = true,
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "name",
     *         required = true,
     *         description = "Наименование",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "address_id",
     *         description = "Местоположение",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "geo_object_id",
     *         description = "Муниципальное образование",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "route_period_id",
     *         description = "Периодичность маршрута",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "orientir",
     *         description = "Ориентир",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "owner",
     *         description = "Балансодержатель / Организация",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "latitude",
     *         description = "Координаты (широта)",
     *         required = true,
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "longitude",
     *         description = "Координаты (долгота)",
     *         required = true,
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "close_date",
     *         description = "Дата закрытия (Y-m-d)",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "daily_norm",
     *         description = "Суточная норма накопления, тонн",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "daily_norm_cub",
     *         description = "Суточная норма накопления, куб. м.",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_container_area_roof_id",
     *         description = "Вид площадки",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_container_area_fence_id",
     *         description = "Тип ограждения",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "msn_container_area_floor_id",
     *         description = "Тип подстилающей поверхности",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "is_separate",
     *         description = "Наличие раздельного сбора",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "is_temporarily",
     *         description = "Является ли площадкой временного назначения",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "section_presence_for_big_garbage",
     *         description = "Секция для накопления крупногабаритных отходов, куб. м.",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "photo_id",
     *         description = "Фото",
     *         type = "integer"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "information_about_container",
     *         description = "Информация о собственнике контейнерной площадки",
     *         type = "string"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "area",
     *         description = "Площадь, м2",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "square_container",
     *         description = "Площадь контейнерной площадки, кв.м",
     *         type = "number"
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "accepted_wastes[]",
     *         description = "Принимаемые отходы (id)",
     *         type = "array",
     *         @SWG\Items(
     *             type="integer",
     *             @SWG\Property(property="id", type="integer")
     *         )
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "garbages[]",
     *         description = "Отходообразователи (id)",
     *         type = "array",
     *         @SWG\Items(
     *             type="integer",
     *             @SWG\Property(property="id", type="integer")
     *         )
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "recyclable_types[]",
     *         description = "Типы отходов раздельного сбора (id)",
     *         type = "array",
     *         @SWG\Items(
     *             type="integer",
     *             @SWG\Property(property="id", type="integer")
     *         )
     *     ),
     *     @SWG\Parameter(
     *         in = "formData",
     *         name = "owner_id",
     *         description = "Идентификатор собственника",
     *         type = "integer"
     *     ),
     *     @SWG\Response(
     *         response="204",
     *         description="Успешное выполение запроса"
     *     ),
     *     @SWG\Response(
     *         response="400",
     *         description="Ошибка запроса"
     *     )
     * )
     *
     * @param int $id
     * @return Msn|array
     * @throws NotFoundHttpException
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $form = new MsnForm(
            $this->accepted(),
            $this->garbage(),
            $this->recylable()
        );
        $form->load(Yii::$app->request->post(), '');
        if ($form->validate()) {
            try {
                $msn = $this->service->edit($form, $model->id);

                return $this->viewOne($msn);
            } catch (RuntimeException $ex) {
                Yii::$app->errorHandler->logException($ex);
                throw $ex;
            }
        }

        return $form;
    }

    /**
     * @SWG\Delete(
     *     path="/msn/{id}",
     *     produces={"application/json"},
     *     summary = "Удаление МСН",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "path",
     *         name = "id",
     *         description = "ID МСН",
     *         required = true,
     *         type = "integer"
     *     ),
     *
     *     @SWG\Response(
     *         response="204",
     *         description="Успешное выполение запроса"
     *     ),
     *     @SWG\Response(
     *         response="400",
     *         description="Ошибка запроса"
     *     )
     * )
     *
     * @param int $id
     * @return array
     * @throws NotFoundHttpException
     * @throws Throwable
     * @throws StaleObjectException
     */
    public function actionDelete(int $id): array
    {
        $model = $this->findModel($id);
        $this->service->delete($model->id);
        return $this->response204();
    }

    /**
     * @SWG\Get(
     *     path="/msn/msn/deficit",
     *     produces={"application/json"},
     *     summary = "Получение необходимого кол-ва контейнеров и МСН",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in = "query",
     *         name = "tko_operator_id",
     *         description = "ID регионального оператора",
     *         type = "integer",
     *         required = true
     *     ),
     *      @SWG\Parameter(
     *         in = "query",
     *         name = "year",
     *         description = "Год расчета",
     *         type = "integer",
     *         required = true
     *     ),
     *
     *     @SWG\Response(
     *         response="200",
     *         description="Успешное выполение запроса"
     *     ),
     *     @SWG\Response(
     *         response="404",
     *         description="Запись не найдена"
     *     ),
     *     @SWG\Response(
     *         response="422",
     *         description="Ошибка валидации"
     *     )
     * )
     * @return array|DeficitForm
     */
    public function actionDeficit()
    {
        $model = new DeficitForm();
        $model->setAttributes(\Yii::$app->request->getQueryParams());
        if (!$model->validate()) {
            return $model;
        }
        return $this->service->getDeficitMsn($model);
    }

    /**
     * @SWG\POST(
     *     path="/msn/modeling",
     *     produces={"application/json"},
     *     summary="Постановка задачи в очередь на создание модельных МСН и привязывание их к отходообразователям",
     *
     *     tags={"МСН", "Очередь/задачи"},
     *
     *     @SWG\Parameter(
     *         in="formData",
     *         name="radius",
     *         type="integer",
     *         description="Радиус привязки (в метрах)",
     *         default="200"
     *     ),
     *     @SWG\Parameter(
     *         in="formData",
     *         name="area",
     *         type="string",
     *         description="Фильтр по региону"
     *     ),
     *
     *     @SWG\Response(
     *         response="200",
     *         description="Успешное выполнение запроса"
     *     ),
     *     @SWG\Response(
     *         response="422",
     *         description="Ошибка валидации"
     *     ),
     *     @SWG\Response(
     *         response="425",
     *         description="Задача с переданными параметрами уже существует"
     *     )
     * )
     *
     * @return int|array|CreateModelingForm
     */
    public function actionCreateModeling()
    {
        $model = new CreateModelingForm();

        $model->setAttributes($this->request->post());

        if (!$model->validate()) {
            return $model;
        }

        try {
            $jobId = (int)Yii::$app->queue->push(new MsnModelingJob(
                $model->getAttributes()
            ));
            return ['job_id' => $jobId];
        } catch (JobOverlappingException $e) {
            throw new JobOverlappingHttpException($e->getMessage(), $e);
        }
    }

    /**
     * @SWG\DELETE(
     *     path="/msn/modeling",
     *     produces={"application/json"},
     *     summary="Удаление модельных МСН",
     *
     *     tags={"МСН"},
     *
     *     @SWG\Parameter(
     *         in="formData",
     *         name="area",
     *         type="string",
     *         description="Регион"
     *     ),
     *
     *     @SWG\Response(
     *         response="204",
     *         description="Успешное выполнение запроса"
     *     )
     * )
     * @param \common\service\MsnService $msnService
     * @return array
     */
    public function actionDeleteModeling(\common\service\MsnService $msnService): array
    {
        $msnService->deleteModeling($this->request->getBodyParam('area'));

        return $this->response204();
    }
}