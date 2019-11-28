<?php
/*-------------------------------------------------------+
| SYSTOPIA bPK Extension                                 |
| Copyright (C) 2019 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Bpk_ExtensionUtil as E;

use Civi\API\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use \Civi\Core\Event\GenericHookEvent;

/**
 * Implements a resolver to resolve conflicts in the MoreGreetings fields
 */
class CRM_Xdedupe_Resolver_BPKResolver extends CRM_Xdedupe_Resolver {

    /**
   * get the name of the finder
   * @return string name
   */
  public function getName() {
    return E::ts("BPK Picker");
  }

  /**
   * get an explanation what the finder does
   * @return string name
   */
  public function getHelp() {
    return E::ts("If there is a valid BPK with (exactly) one of the contacts, that contact's first name, last name and birth date will be copied to the main contact. In addition, that BPK record will, of course, be moved to the main contact. The vBPK of the main contact, however, will be kept if set.");
  }

  /**
   * Report the contact attributes that this resolver requires
   *
   * @return array list of contact attributes
   */
  public function getContactAttributes() {
    return ['first_name', 'last_name', 'birth_date'];
  }

  /**
   * Check if one of the contacts has a valid BPK record. If so, make sure this record ends up with the
   *  main contact, and set the contact's first_name, last_name and birth_date to that of the BPK contact
   *
   * If both or none of the contacts have a valid BPK record, do nothing.
   *
   * @param $main_contact_id    int     the main contact ID
   * @param $other_contact_ids  array   other contact IDs
   * @return boolean TRUE, if there was a conflict to be resolved
   * @throws Exception if the conflict couldn't be resolved
   */
  public function resolve($main_contact_id, $other_contact_ids) {
    $all_contact_ids = array_merge($other_contact_ids, [$main_contact_id]);
    $main_contact_vBPK = $this->getVBPK($main_contact_id);
    $contacts_with_valid_bpks = $this->getContactsWithValidBPKRecords($all_contact_ids);

    // we can't merge multiple valid BPKs
    if (count($contacts_with_valid_bpks) > 1) {
      throw new Exception("Cannot merge multiple valid BPKs");
    }

    if (count($contacts_with_valid_bpks) == 1) {
      # ONLY _one_ of the contacts has a valid BPK record => we can act
      $contact_id_with_valid_bpk = reset($contacts_with_valid_bpks);

      // delete all other records
      foreach ($all_contact_ids as $contact_id) {
        if ($contact_id != $contact_id_with_valid_bpk) {
          $this->deleteBPKRecord($contact_id);
        }
      }

      // update first name, last name and birth date
      $this->copyVerifiedContactData($contact_id_with_valid_bpk, $main_contact_id);

      // the last step has created a new record
      $this->deleteBPKRecord($main_contact_id);

      // now move record to main contact
      $this->moveBPKRecord($contact_id_with_valid_bpk, $main_contact_id);

      // set vBPK to main contact
      if (!empty($main_contact_vBPK)) {
        $this->setVBPK($main_contact_id, $main_contact_vBPK);
      }
    }

    return count($contacts_with_valid_bpks) > 0;
  }


  /**
   * Copy first name, last_name and birth date from the first contact to the second
   * @param $from_contact_id integer contact ID
   * @param $to_contact_id   integer contact ID
   */
  protected function copyVerifiedContactData($from_contact_id, $to_contact_id) {
    $contact_update = [];
    $from_contact = $this->getContext()->getContact($from_contact_id);
    $to_contact   = $this->getContext()->getContact($to_contact_id);

    // find the differences
    foreach (['first_name', 'last_name', 'birth_date'] as $attribute) {
      if ($from_contact[$attribute] != $to_contact[$attribute]) {
        $contact_update[$attribute] = $from_contact[$attribute];
      }
    }

    if (!empty($contact_update)) {
      // there are differences
      $this->addMergeDetail(E::ts("Attributes %1 copied from BPK record holder contact [%2]", [
          1 => implode(',', array_keys($contact_update)),
          2 => $from_contact_id]));

      // run the update
      $contact_update['id'] = $to_contact_id;
      if (isset($contact_update['birth_date'])) {
        $contact_update['birth_date'] = date('Y-m-d', strtotime($contact_update['birth_date']));
      }
      civicrm_api3('Contact', 'create', $contact_update);

      // purge cache
      $this->getContext()->unloadContact($to_contact_id);
    }
  }


  /**
   * Return the contact_ids of all contacts within the set
   *  that have a valid BPK
   *
   * @param $contact_ids array contact IDs
   * @return array contact IDs
   * @throws Exception|CiviCRM_API3_Exception if bpk_status field not found or loading failed
   */
  protected function getContactsWithValidBPKRecords($contact_ids) {
    $status_field = CRM_Bpk_CustomData::getCustomFieldKey('bpk', 'bpk_status');
    if (!$status_field) {
      throw new Exception("bpk_status field not found!");
    }

    // find contacts with the right status
    $query = civicrm_api3('Contact', 'get', [
        'id'           => ['IN' => $contact_ids],
        $status_field  => ['IN' => [CRM_Bpk_DataLogic::STATUS_MANUAL, CRM_Bpk_DataLogic::STATUS_RESOLVED]],
        'option.limit' => 0,
        'return'       => 'id',
        'sequential'   => 0,
    ]);

    // return only the contact IDs with valid BPKs
    return array_keys($query['values']);
  }

  /**
   * Delete the entire BPK record of the given contact
   *
   * @param $contact_id integer contact ID
   */
  protected function deleteBPKRecord($contact_id) {
    $contact_id = (int) $contact_id;
    $config = CRM_Bpk_Config::singleton();
    $table_name = $config->getTableName();
    CRM_Core_DAO::executeQuery("DELETE FROM {$table_name} WHERE entity_id = {$contact_id}");
  }

  /**
   * Move the entire BPK record from one contact to another
   *
   * @param $contact_id_old integer source contact ID
   * @param $contact_id_new integer target contact ID
   */
  protected function moveBPKRecord($contact_id_old, $contact_id_new) {
    $contact_id_old = (int) $contact_id_old;
    $contact_id_new = (int) $contact_id_new;
    if ($contact_id_old != $contact_id_new) {
      $config = CRM_Bpk_Config::singleton();
      $table_name = $config->getTableName();
      CRM_Core_DAO::executeQuery("UPDATE {$table_name} SET entity_id = {$contact_id_new} WHERE entity_id = {$contact_id_old}");
      $this->addMergeDetail(E::ts("Moved BPK record from contact [%1]", [
          1 => $contact_id_old]));
    }
  }

  /**
   * Get the vBPK of a contact
   *
   * @param $contact_id integer contact ID
   * @return string vPBK
   * @throws Exception|CiviCRM_API3_Exception if vBPK field not found or loading failed
   */
  protected function getVBPK($contact_id) {
    $vBPK_field = CRM_Bpk_CustomData::getCustomFieldKey('bpk', 'vbpk');
    if (!$vBPK_field) {
      throw new Exception("vBPK field not found!");
    }
    if ($contact_id) {
      try {
        return (string) civicrm_api3('Contact', 'getvalue', [
            'id'     => $contact_id,
            'return' => $vBPK_field]);
      } catch (Exception $ex) {
        // probably just not found
      }
    }
    return NULL;
  }

  /**
   * Set the vBPK of a contact
   *
   * @param $contact_id integer contact ID
   * @param $value      string  new vPBK
   * @throws Exception|CiviCRM_API3_Exception if vBPK field not found or setting failed
   */
  protected function setVBPK($contact_id, $value) {
    $vBPK_field = CRM_Bpk_CustomData::getCustomFieldKey('bpk', 'vbpk');
    if (!$vBPK_field) {
      throw new Exception("vBPK field not found!");
    }
    if ($contact_id) {
      $old_value = self::getVBPK($contact_id);
      if ($old_value != $value) {
        civicrm_api3('Contact', 'create', [
            'id'        => $contact_id,
            $vBPK_field => $value]);
        if ($old_value) {
          $this->addMergeDetail(E::ts("vPBK '%1' from transferred BPK dropped in favour of main contact's own one.", [
              1 => $old_value]));
        }
      }
    }
  }
}
