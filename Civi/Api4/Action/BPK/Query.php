<?php

namespace Civi\Api4\Action\BPK;

use Civi\Api4\Generic;

/**
 * @see \Civi\Api4\Generic\AbstractQueryAction
 *
 * @package Civi\Api4\Action\BPK
 */
class Query extends Generic\AbstractAction {
  /**
   * The person's first name
   *
   * @var string
   * @required
   */
  protected $firstName;

  /**
   * The person's last name
   *
   * @var string
   * @required
   */
  protected $lastName;

  /**
   * The person's date of birth
   *
   * @var string
   * @required
   */
  protected $birthDate;

  /**
   * The person's postal code
   *
   * @var string
   */
  protected $postalCode;

  /**
   * @param Result $result
   */
  public function _run(Generic\Result $result) {
    $lookup_client = new \CRM_Bpk_SoapLookup([]);

    $bpk_result = $lookup_client->getBpkResult((object) [
      'contact_id' => NULL,
      'first_name' => $this->getFirstName(),
      'last_name' => $this->getLastName(),
      'birth_date' => date('Y-m-d', strtotime($this->getBirthDate())),
      'postal_code' => $this->getPostalCode(),
    ]);

    unset($bpk_result['contact_id']);
    $result[] = $bpk_result;
  }

  /**
   * The returned fields of this action
   *
   * @return array
   */
  public static function fields() {
    return [
      [
        'name' => 'bpk_extern',
        'data_type' => 'String',
      ],
      [
        'name' => 'vbpk',
        'data_type' => 'String',
      ],
      [
        'name' => 'bpk_status',
        'data_type' => 'String',
      ],
      [
        'name' => 'bpk_error_code',
        'data_type' => 'String',
      ],
      [
        'name' => 'bpk_error_note',
        'data_type' => 'String',
      ],
    ];
  }

}

