<?php
/*-------------------------------------------------------+
| SYSTOPIA bPK Extension                                 |
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


abstract class CRM_Bpk_Lookup {

  protected $success     = 0;
  protected $failed      = 0;
  protected $contact_ids = array();
  protected $params      = NULL;
  protected $config      = NULL;

  protected function __construct($params = NULL) {
    // TODO: if
    $this->params = $params;
    $this->config = CRM_Bpk_Config::singleton();
  }

  /**
   * Run a bPK lookup / store for eligible contacts
   *
   * @return array with results: ['success' => <count>, 'failed' => <count>]
   */
  public static function doSoapLookup($params) {
    $runner = new CRM_Bpk_SoapLookup($params);

    // step 1: select eligible contacts
    $select_sql = $runner->createSelectionQuery();

    // step 2: resolve
    $runner->executeLookupFor($select_sql);

    return $runner->getResult();
  }

  /**
   * Generate a simple public result structure
   */
  protected function getResult() {
    return array(
      'success'     => $this->success,
      'failed'      => $this->failed,
      'contact_ids' => empty($this->contact_ids) ? 'none' : implode(',', $this->contact_ids)
    );
  }

  /**
   * Generate a SQL query to select the pending contacts
   */
  protected function createSelectionQuery() {
    // extract limit
    $limit = $this->config->getDefaultLimit();
    if (isset($this->params['limit'])) {
      $limit = (int) $this->params['limit'];
    }
    $limit_sql = "LIMIT {$limit}";

    // BPKs with status=Unknown
    $status_unknown_clause = "bpk_group.status IS NULL OR bpk_group.status = 1 OR bpk_group.status = ''";
    // BPKs with either:
    // - status<>Resolved but bpk_extern or vbpk is set
    // - status=Resolved but either bpk_extern or vbpk is not set
    $invalid_state_clause = "
      (bpk_group.status <> 3 AND (bpk_extern <> '' OR bpk_group.vbpk <> ''))
      OR
      (bpk_group.status = 3 AND (bpk_extern = '' OR bpk_extern IS NULL OR bpk_group.vbpk = '' OR bpk_group.vbpk IS NULL))";
    // BPKs with status=Error and retryable error code
    $status_error_clause = "bpk_group.status = 5 AND (bpk_group.error_code = 'XXXX' OR bpk_group.error_code LIKE 'F5%')";
    $retry_interval_days = Civi::settings()->get('bpk_lookup_retry_interval') ?? 90;
    // BPK is not Resolved/Manual and lookup_date is older than bpk_lookup_retry_interval
    $retry_clause = "bpk_group.status <> 3 AND bpk_group.status <> 2 AND (bpk_group.lookup_date IS NULL OR bpk_group.lookup_date <= NOW() - INTERVAL {$retry_interval_days} DAY)";

    // contact_id (for testing)
    if (empty($this->params['contact_id'])) {
      // generate WHERE clause
      // bpk queries must always have first_name, last_name and birth_date
      $where_clauses[] = "contact.birth_date IS NOT NULL";
      $where_clauses[] = "contact.birth_date <> ''";
      $where_clauses[] = "contact.first_name IS NOT NULL";
      $where_clauses[] = "contact.first_name <> ''";
      $where_clauses[] = "contact.last_name  IS NOT NULL";
      $where_clauses[] = "contact.last_name  <> ''";

      // ...the contact should be an individual
      $where_clauses[] = "contact.contact_type = 'Individual'";

      // ...not in the trash
      $where_clauses[] = "contact.is_deleted = 0";

      $where_clauses[] = "(
           ({$status_unknown_clause})
        OR ({$invalid_state_clause})
        OR ({$status_error_clause})
        OR ({$retry_clause})
      )";

    } else {
      // this is a single contact call:
      $contact_id = (int) $this->params['contact_id'];
      $where_clauses[] = "contact.id = {$contact_id}";
    }

    $table_name = $this->config->getTableName();
    $where_sql  = implode(') AND (', $where_clauses);

    // select BPKs to lookup in the following order:
    // 1. BPKs that have not yet been resolved (Status=Unknown)
    // 2. BPK records in an invalid state (status does not make sense in combination with BPK value)
    // 3. Status=Error
    // 4. Status=No Match/Error/Ambiguous and lookup_date is older than bpk_lookup_retry_interval
    $sql = "SELECT
             contact.id         AS contact_id,
             contact.first_name AS first_name,
             contact.last_name  AS last_name,
             contact.birth_date AS birth_date,
             CASE
               WHEN ({$status_unknown_clause}) THEN 1
               WHEN ({$invalid_state_clause}) THEN 2
               WHEN ({$status_error_clause}) THEN 3
               WHEN ({$retry_clause}) THEN 4
             END AS priority
            FROM civicrm_contact contact
            LEFT JOIN {$table_name} AS bpk_group ON bpk_group.entity_id = contact.id
            WHERE (({$where_sql}))
            GROUP BY contact.id
            ORDER BY priority
            {$limit_sql}";
    return $sql;
  }

  /**
   * Run the lookup based on the query
   */
  protected function executeLookupFor($sql) {
    // Actually execute query for results
    $contact = CRM_Core_DAO::executeQuery($sql);
    while ($contact->fetch()) {
      // query the contact
      $result = $this->getBpkResult($contact);

      // update stats
      if (empty($result['contact_id'])) {
        throw new Exception("Internal error: incomplete result");
      }

      $this->contact_ids[] = $result['contact_id'];
      if (empty($result['bpk_error_code'])) {
        $this->success += 1;

      } else {
        $this->failed += 1;
      }

      // store the data
      $this->storeResult($result);
    }
  }

  /**
   * Perform the actual bpk lookup for the contact
   *
   * @param $contact DAO object with first_name, last_name, birth_date
   *
   * @return array with the following parameters:
   *               contact_id       Contact ID
   *               bpk_extern       bPK            (empty string if not resolved)
   *               vbpk             vbPK           (empty string if not resolved)
   *               bpk_status       status         (OptionGroup bpk_status)
   *               bpk_error_code   error code     (empty string if no error)
   *               bpk_error_note   error message  (empty string if no error)
   */
  protected abstract function getBpkResult($contact);

  /*
   * get a set of contact; limit is 200/min
   *
   * Request shall only be executed on contact at a time though
   */
  protected function getBpkMultiResult($contacts) {
    // todo: implement as loop, but override in subclass
    foreach ($contacts as $contact) {
      $result = $this->getBpkResult($contact);

    }
  }

  /**
   * Store result in contact
   */
  protected function storeResult($result) {
    $update = array(
      'id'                 => $result['contact_id'],
      'bpk.bpk_extern'     => $result['bpk_extern'],
      'bpk.vbpk'           => $result['vbpk'],
      'bpk.bpk_status'     => $result['bpk_status'],
      'bpk.bpk_error_code' => $result['bpk_error_code'],
      'bpk.bpk_error_note' => $result['bpk_error_note'],
      'bpk.bpk_lookup_date' => date('YmdHis')
    );
    CRM_Bpk_CustomData::resolveCustomFields($update);
    civicrm_api3('Contact', 'create', $update);
  }

  /**
   * reset contact's BPK information
   */
  public static function resetBPK($contact_id) {
    $update = array(
      'id'                 => $contact_id,
      'bpk.bpk_extern'     => '',
      'bpk.vbpk'           => '',
      'bpk.bpk_status'     => 1, // unknown
      'bpk.bpk_error_code' => '',
      'bpk.bpk_error_note' => 'reset: ' . date('Y-m-d'),
    );
    CRM_Bpk_CustomData::resolveCustomFields($update);
    civicrm_api3('Contact', 'create', $update);
  }
}
