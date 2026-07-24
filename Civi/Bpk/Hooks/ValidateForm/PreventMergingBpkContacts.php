<?php

namespace Civi\Bpk\Hooks\ValidateForm;

use Civi;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoSubscriber;
use CRM_Contact_Form_Merge;
use CRM_Bpk_ExtensionUtil as E;

class PreventMergingBpkContacts extends AutoSubscriber {

  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_validateForm' => ['run', -20]];
  }

  public static function run(GenericHookEvent $event): void {
    if ($event->formName !== CRM_Contact_Form_Merge::class) {
      return;
    }

    $firstContactId = (int) $event->form->_cid;
    $secondContactId = (int) $event->form->_oid;
    $firstContactBpk = PreventMergingBpkContacts::getContactBpk($firstContactId);
    $secondContactBpk = PreventMergingBpkContacts::getContactBpk($secondContactId);

    if (empty($firstContactBpk) || empty($secondContactBpk)) {
      return;
    }

    if ($firstContactBpk === $secondContactBpk) {
      return;
    }

    if (empty($event->errors)) {
      $event->errors = [];
    }

    $event->errors['_qf_default'] = E::ts('The selected contacts have different BPKs, meaning they are very likely not the same person. If you\'re 100% sure there is a data error, please remove the BPK from one of the two contacts before trying again.');
  }

  public static function getContactBpk(int $contactId): string {
    $contact = \Civi\Api4\Contact::get(TRUE)
      ->addSelect('bpk.bpk_extern')
      ->addWhere('id', '=', $contactId)
      ->setLimit(1)
      ->execute()
      ->first();

    return !empty($contact['bpk.bpk_extern']) ? (string) $contact['bpk.bpk_extern'] : '';
  }

}
