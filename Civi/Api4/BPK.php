<?php

namespace Civi\Api4;

/**
 * BPK API
 *
 * Used to query BPKs for contacts
 *
 * @package Civi\Api4
 */
class BPK extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return Action\BPK\Query
   */
  public static function query($checkPermissions = TRUE) {
    $query = new Action\BPK\Query(__CLASS__, __FUNCTION__);
    return $query->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Action\Setting\GetFields
   */
  public static function getFields($checkPermissions = TRUE) {
    return (
      new Generic\BasicGetFieldsAction(
        __CLASS__,
        __FUNCTION__,
        function (Generic\BasicGetFieldsAction $getFields) {
          return [];
        })
    )->setCheckPermissions($checkPermissions);
  }

}
