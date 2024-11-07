<?php

namespace api\modules\msn\traits;

use api\exception\NotFoundException;
use api\modules\msn\models\MsnContainer;
use api\modules\msn\models\MsnContainerArea;
use api\modules\msn\models\MsnContainerAreaDetail;
use api\modules\msn\models\MsnGarbageSource;
use api\modules\msn\models\MsnOperationDetail;
use api\modules\msn\models\MsnService;
use common\modules\msn\models\Msn;

trait MsnTrait
{
    /**
     * @param int $id
     * @return Msn
     * @throws NotFoundException
     */
    private function checkAndGetMsn($id)
    {
        $model = Msn::findOne($id);
        if (null === $model) {
            throw new NotFoundException();
        }
        return $model;
    }


    /**
     * @param int $id
     * @return MsnContainer
     * @throws NotFoundException
     */
    private function checkAndGetContainer(int $id): MsnContainer
    {
        $model = MsnContainer::findOne($id);
        if (null === $model) {
            throw new NotFoundException();
        }
        return $model;
    }

    /**
     * @param Msn $msn
     * @param int $id
     * @return MsnContainerArea
     * @throws NotFoundException
     */
    private function checkAndGetContainerArea(Msn $msn, $id)
    {
        $model = MsnContainerArea::findOne($id);
        if (null === $model || $msn->msn_container_area_id !== $model->id) {
            throw new NotFoundException();
        }
        return $model;
    }

    /**
     * @param MsnContainerArea $containerArea
     * @param int $containerDetailId
     * @return MsnContainerAreaDetail
     * @throws NotFoundException
     */
    private function checkAndGetContainerDetail(MsnContainerArea $containerArea, $containerDetailId): MsnContainerAreaDetail
    {
        $model = MsnContainerAreaDetail::findOne($containerDetailId);
        if (null !== $model && $containerArea->id !== $model->msn_container_area_id) {
            throw new NotFoundException();
        }
        return $model;
    }



    /**
     * @param int $id
     * @return MsnOperationDetail
     * @throws NotFoundException
     */
    private function checkAndGetOperationDetail($id)
    {
        $model = MsnOperationDetail::findOne($id);
        if (null === $model) {
            throw new NotFoundException();
        }
        return $model;
    }

    /**
     * @param int $id
     * @return MsnService
     * @throws NotFoundException
     */
    private function checkAndGetMsnService($id)
    {
        $model = MsnService::findOne($id);
        if (null === $model) {
            throw new NotFoundException();
        }
        return $model;
    }
}