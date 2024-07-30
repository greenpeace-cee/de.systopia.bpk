<?php
/*-------------------------------------------------------+
| SYSTOPIA bPK Extensio                                  |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
|         P. Batroff (batroff@systopia.de)               |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

require_once 'bpk.civix.php';
use CRM_Bpk_ExtensionUtil as E;


/**
 * Add contact search tasks to submit tax excemption XMLs
 *
 * @param string $objectType specifies the component
 * @param array $tasks the list of actions
 *
 * @access public
 */
function bpk_civicrm_searchTasks($objectType, &$tasks) {
  if ($objectType == 'contact') {
    if (CRM_Core_Permission::check('edit all contacts')) {
      $tasks[] = array(
          'title' => E::ts('Generate Tax Submission XML'),
          'class' => 'CRM_Bpk_Form_Task_Submit',
          'result' => false);

      $tasks[] = array(
          'title' => E::ts('Reset BPKs'),
          'class' => 'CRM_Bpk_Form_Task_Reset',
          'result' => false);

      $tasks[] = array(
          'title' => E::ts('Look up BPKs'),
          'class' => 'CRM_Bpk_Form_Task_Resolve',
          'result' => false);
    }
  }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function bpk_civicrm_config(&$config) {
  _bpk_civix_civicrm_config($config);

  require_once 'CRM/Xdedupe/Resolver/BPKSubscriber.php';
  \Civi::dispatcher()->addSubscriber(new CRM_Xdedupe_Resolver_BPKSubscriber());
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function bpk_civicrm_install() {
  _bpk_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function bpk_civicrm_postInstall() {
  CRM_Bpk_Config::installScheduledJob();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function bpk_civicrm_enable() {
  _bpk_civix_civicrm_enable();

  require_once 'CRM/Bpk/CustomData.php';
  $customData = new CRM_Bpk_CustomData('de.systopia.bpk');
  $customData->syncOptionGroup(__DIR__ . '/resources/bpk_error_code_option_group.json');
  $customData->syncOptionGroup(__DIR__ . '/resources/bpk_status_option_group.json');
  $customData->syncCustomGroup(__DIR__ . '/resources/bpk_custom_group.json');
  $customData->syncOptionGroup(__DIR__ . '/resources/bpk_exclusion_activity_type.json');
  $customData->syncCustomGroup(__DIR__ . '/resources/bpk_exclusion_custom_field.json');
}

/**
 * Implements bpk_civicrm_tabset()
 *
 * Will inject the BMF Submissions tab
 */
function bpk_civicrm_tabset($tabsetName, &$tabs, $context) {
  if ($tabsetName == 'civicrm/contact/view' && !empty($context['contact_id'])) {
    // ADD BMF Submissions tab as a new tab
    $params = [];
    $params['contact_id'] = $context['contact_id'];

    $tabs[] = array( 'id'     => 'bmfsa',
      'url'    => CRM_Utils_System::url('civicrm/bmf/submissions', "reset=1&snippet=1&force=1&cid={$params['contact_id']}"),
      'title'  => E::ts('BMF Submissions'),
      'count'  => CRM_Bpk_Submission::getSubmissionCount($params['contact_id']),
      'weight' => 300);
  }
}

/**
 * Add a 'update BPK' action
 */
function bpk_civicrm_summaryActions( &$actions, $contactID ) {
  $actions['sepa_contribution'] = array(
      'title'           => ts('Update BPK'),
      'weight'          => 50,
      'ref'             => 'bpk-update',
      'key'             => 'bpk_update',
      'component'       => 'CiviContribute',
      'href'            => CRM_Utils_System::url('civicrm/bpk/update', "cid={$contactID}"),
      'class'           => 'no-popup',
      'permissions'     => array('edit all contacts')
    );
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function bpk_civicrm_navigationMenu(&$menu) {
  _bpk_civix_insert_navigation_menu($menu, 'Contributions', array(
    'label'      => E::ts('BMF Annual Submission'),
    'name'       => 'bmf_annual',
    'url'        => 'civicrm/bmf/annual',
    'permission' => 'administer CiviCRM',
    'operator'   => 'OR',
    'separator'  => 0,
  ));
  _bpk_civix_navigationMenu($menu);
}

/**
 * Implements hook_civicrm_pageRun().
 *
 * Injects extra update button into summary view
 */
function bpk_civicrm_pageRun(&$page) {
  $page_name = $page->getVar('_name');

  if ($page_name == 'CRM_Contact_Page_View_Summary') {
    // add inline button to update BPK to summary view
    $contact_id = CRM_Utils_Request::retrieve('cid', 'Integer');
    CRM_Core_Resources::singleton()->addVars('bpk', array(
      'resolve_url'  => CRM_Utils_System::url('civicrm/bpk/update', "cid={$contact_id}"),
      'bpk_group_id' => civicrm_api3('CustomGroup', 'getvalue', array('name' => 'bpk', 'return' => 'id'))
    ));

    CRM_Core_Resources::singleton()->addScriptFile('de.systopia.bpk', 'js/summary_view_button.js');
  }
}

/**
 * Implements hook_civicrm_pre().
 *
 * Will make sure that edits to contact/bpks will be
 *  handled correctly
 */
function bpk_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName == 'Individual') {
    CRM_Bpk_DataLogic::processContactPreHook($op, $id, $params);
  }
}

/**
 * POST hook only used to send pending BPK resets
 */
function bpk_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName == 'Individual') {
    CRM_Bpk_DataLogic::sendPendingBPKRequests();
  }
}

/**
 * Implements hook_civicrm_pre().
 *
 * Will make sure that edits to contact/bpks will be
 *  handled correctly
 */
function bpk_civicrm_custom( $op, $groupID, $entityID, &$params ) {
  if ($op == 'edit' || $op == 'create') {
    CRM_Bpk_DataLogic::processCustomHook($op, $groupID, $entityID, $params);
  }
}

/**
 * Implements bpk_civicrm_custom()
 *
 * A contact merge should *always* move all submission records to the new contact
 */
function bpk_civicrm_merge($type, &$data, $mainId = NULL, $otherId = NULL, $tables = NULL) {
  if ($type == 'sqls') {
    // add SQL to move bmfsa records
    if (is_numeric($mainId) && is_numeric($otherId)) {
      $data[] = "UPDATE `civicrm_bmfsa_record` SET `contact_id` = {$mainId} WHERE `contact_id` = {$otherId};";
    }
  }
}
