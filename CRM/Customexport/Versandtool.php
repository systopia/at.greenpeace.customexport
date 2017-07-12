<?php

class CRM_Customexport_Versandtool extends CRM_Customexport_Base {

  private $contactsBatch = array(); // Array to hold batches of contacts

  private $batchSize; // Number of contacts in each batch (all batches output to the same csv file)
  private $batchOffset; // The current batch offset
  private $totalContacts; // The total number of contacts meeting criteria
  private $exportFile; // Details of the file used for export

  private $customFields;

  function __construct($batchSize = 100) {
    if (!$this->getExportSettings('versandtool_exports')) {
      throw new Exception('Could not load versandtoolExports settings - did you define a default value?');
    };
    $this->getCustomFields();
    $this->getLocalFilePath();

    $this->batchSize = $batchSize;
  }

  /**
   * Get the metadata for all the custom fields in the group webshop_information
   */
  private function getCustomFields() {
    $customFields = civicrm_api3('CustomField', 'get', array(
      'custom_group_id' => "webshop_information",
    ));
    // Store by name so we can find them easily later
    foreach ($customFields['values'] as $key => $values) {
      $this->customFields[$values['name']] = $values;
    }
  }

  /**
   * Export all contacts meeting criteria
   */
  public function export() {
    $this->totalContacts = $this->getContactCount();
    $this->batchOffset = 0;

    while ($this->batchOffset < $this->totalContacts) {
      // Export each batch to csv
      $this->_exportComplete = FALSE;
      if (!$this->getValidContacts($this->batchSize, $this->batchOffset)) {
        $result['is_error'] = TRUE;
        $result['message'] = 'No valid contacts found for export';
        return $result;
      }
      $this->exportToCSV();
      if (!$this->_exportComplete) {
        $result['is_error'] = TRUE;
        $result['message'] = 'Error during exportToCSV';
        return $result;
      }
      // Increment batch
      $this->batchOffset = $this->batchOffset + $this->batchSize;
    }

    // Once all batches exported:
    $this->upload();
  }

  /**
   * Get the count of all contacts meeting criteria
   *
   * @return bool
   */
  private function getContactCount() {
    $contactCount = civicrm_api3('Contact', 'getcount', array(
      'contact_type' => "Individual",
      'do_not_email' => 0,
      'is_opt_out' => 0,
    ));
    if (empty($contactCount['is_error'])) {
      return $contactCount['result'];
    }
    return FALSE;
  }

  /**
   * Get batch of contacts who are Individuals; do_not_email, user_opt_out is not set
   * Retrieve in batches for performance reasons
   * @param $limit
   * @param $offset
   *
   * @return bool
   */
  private function getValidContacts($limit, $offset) {
    $contacts = civicrm_api3('Contact', 'get', array(
      'contact_type' => "Individual",
      'options' => array('limit' => $limit, 'offset' => $offset),
      'do_not_email' => 0,
      'is_opt_out' => 0,
    ));

    if (empty($contacts['is_error']) && ($contacts['count'] > 0)) {
      $this->contactsBatch = $contacts['values'];
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Export the activities array to csv file
   * We export each order_type based on what we find in settings
   * If default is specified in settings we export all order_types that are not listed separately using this type.
   */
  private function exportToCSV() {
    // Write to a csv file in tmp dir
    $date = new DateTime();

    // order_type => optionvalue_id(order_type),
    // file => csv file name (eg. export),
    // remote => remote server (eg. sftp://user:pass@server.com/dir/)
    if (!isset($this->settings['default']))
      return FALSE;

    $this->exportFile = $this->settings['default'];
    $this->exportFile['outfilename'] = $this->exportFile['file'] . '_' . $date->format('YmdHis'). '.csv';
    $this->exportFile['outfile'] = $this->localFilePath . '/' . $this->exportFile['outfilename'];
    $this->exportFile['hasContent'] = FALSE; // Set to TRUE once header is written

    $startContactId = $this->batchOffset;
    $endContactId = $this->batchSize+$this->batchOffset;
    $emails = $this->getBulkEmailAddresses($startContactId, $endContactId);
    $addresses = $this->getPrimaryAddresses($startContactId, $endContactId);
    $phones = $this->getPrimaryPhones($startContactId, $endContactId);
    $groupC = $this->getContactGroupStatus($startContactId, $endContactId,'Community NL');
    $groupD = $this->getContactGroupStatus($startContactId, $endContactId,'Donation Info');
    $this->filterExternalContactIds($this->contactsBatch);
    $surveys = $this->getContactSurveys($startContactId, $endContactId);

    foreach($this->contactsBatch as $id => $contact) {
      // Build an array of values for export
      // Required fields:
      // Kontakt-Hash;E-Mail;Salutation;Firstname;Lastname;Birthday;Title;ZIP;City;Country;Address;
      // Contact_ID;Telephone;PersonID_IMB;Package_id;Segment_id;Community NL;Donation Info;Campaign_Topic;Petition
      // CiviCRM Kontakt-Hash;Bulk E-Mail falls vorhanden, ansonsten Primäre E-Mail-Adresse;Prefix;First Name;Last Name;Date of Birth;Title;ZIP code (primary);City (primary);countyr code (primary;Street Address AND Supplemental Address (primary);
      // CiviCRM Contakt-ID;phone number (primary);The old IMB Contact ID – should be filled if contact has an external CiviCRM ID that starts with „IMB-“;to be ignored for daily/regular export;to be ignored for daily/regular export;Contact status (added, removed, none) of  Group „Community NL“;
      // Contact status (added, removed, none) of  Group „Donation Info “;fill with external campaign identifiers of the linked survey (linked via activity) (each value only once);fill with internal CiviCRM „Survey ID“ if any activity of the contact is connected to a survey  (each value only once)

      $fields = array(
        'Kontakt-Hash' => CRM_Contact_BAO_Contact_Utils::generateChecksum($id), // CiviCRM Kontakt-Hash
        'E-Mail' => $emails[$id], // Bulk E-Mail falls vorhanden, ansonsten Primäre E-Mail-Adresse
        'Salutation' => $contact['individual_prefix'], // Prefix
        'Firstname' => $contact['first_name'], // First Name
        'Lastname' => $contact['last_name'], // Last Name
        'Birthday' => $contact['birth_date'], // Date of Birth
        'Title' => $contact['formal_title'], // Title
        'ZIP' => $contact['postal_code'], // ZIP code (primary)
        'City' => $contact['city'], // City (primary)
        'Country' => $contact['country'], // Country code (primary)
        'Address' => $addresses[$id], // Street Address AND Supplemental Address (primary)
        'Contact_ID' => $id, // CiviCRM Contakt-ID
        'Telephone' => $phones[$id], // phone number (primary)
        'PersonID_IMB' => $contact['external_identifier'], // The old IMB Contact ID – should be filled if contact has an external CiviCRM ID that starts with „IMB-“
        'Package_id' => '', // to be ignored for daily/regular export
        'Segment_id' => '', // to be ignored for daily/regular export
        'Community_ NL' => $groupC[$id], // Contact status (added, removed, none) of  Group „Community NL“
        'Donation Info' => $groupD[$id], // Contact status (added, removed, none) of  Group „Donation Info “
        'Campaign_Topic' => $surveys[$id]['external_identifier'], // fill with external campaign identifiers of the linked survey (linked via activity) (each value only once)
        'Petition' => $surveys[$id]['survey_id'], // fill with internal CiviCRM „Survey ID“ if any activity of the contact is connected to a survey  (each value only once)
      );

      // Build the row
      $csv = implode(',', array_values($fields));

      // Write header on first line
      if (!$this->exportFile['hasContent']) {
        $header = implode(',', array_keys($fields));
        file_put_contents($this->exportFile['outfile'], $header.PHP_EOL, FILE_APPEND | LOCK_EX);
        $this->exportFile['hasContent'] = TRUE;
      }

      file_put_contents($this->exportFile['outfile'], $csv.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    // Set to TRUE on successful export
    $this->_exportComplete = TRUE;
  }

  /**
   * Returns an array of [contact_id]=>email
   * @param $startContactId
   * @param $endContactId
   *
   * @return array
   */
  private function getBulkEmailAddresses($startContactId, $endContactId) {
    // Get list of email addresses for contact
    // We sort by is_bulkmail and then is_primary so we don't have to search the whole array,
    //  as we can just match the first one
    $emails = civicrm_api3('Email', 'get', array(
      'contact_id' => array('BETWEEN' => array($startContactId, $endContactId-$startContactId)),
      'options' => array('sort' => "contact_id ASC", 'limit' => 0),
    ));

    $emailData = array();
    if ($emails['count'] > 0) {
      // As we sorted by is_bulkmail and then is_primary the first record will always be the one we want
      $contactId = 0;
      foreach ($emails['values'] as $id => $email) {
        // Each contact has multiple emails, we sorted by contact Id so check each email for contact
        if ($email['contact_id'] != $contactId) {
          // If contact doesn't match we're looking at a new contact
          $contactId = $email['contact_id'];
          $bulkFound=FALSE;
          $primaryFound=FALSE;
        };
        if (!empty($email['is_bulkmail'])) {
          // If we have a bulkmail address use it
          $bulkFound = TRUE;
          $emailData[$email['contact_id']] = $email['email'];
        }
        if (!empty($email['is_primary']) && !$bulkFound) {
          // If we don't have a bulkmail address set to primary
          $primaryFound = TRUE;
          $emailData[$email['contact_id']] = $email['email'];
        }
        if (!$bulkFound && !$primaryFound) {
          // Set this as the email address, will get overwritten if primary or bulkmail is set.
          $emailData[$email['contact_id']] = $email['email'];
        }
      }
    }
    return $emailData;
  }

  /**
   * Returns an array of [contact_id]=>phone
   * @param $startContactId
   * @param $endContactId
   *
   * @return array
   */
  private function getPrimaryPhones($startContactId, $endContactId) {
    $phoneData = array();
    $phones = civicrm_api3('Phone', 'get', array(
      'contact_id' => array('BETWEEN' => array($startContactId, $endContactId-$startContactId)),
      'is_primary' => 1,
      'options' => array('limit' => 0),
    ));

    if ($phones['count'] > 0) {
      foreach ($phones['values'] as $id => $phone) {
        $phoneData[$phone['contact_id']] = $phone['phone'];
      }
    }
    return $phoneData;
  }

  /**
   * Returns an array of [contact_id]=>address(street_address,sup1,sup2)
   * @param $startContactId
   * @param $endContactId
   *
   * @return array
   */
  private function getPrimaryAddresses($startContactId, $endContactId) {
    // Get list of postal addresses for contact.
    // We sort by is_primary so we can just match the first one
    $addresses = civicrm_api3('Address', 'get', array(
      'contact_id' => array('BETWEEN' => array($startContactId, $endContactId-$startContactId)),
      'is_primary' => 1,
      'options' => array('limit' => 0),
    ));
    $addressData = array();
    if ($addresses['count'] > 0) {
      foreach ($addresses['values'] as $id => $address) {
        $newAddress = $address['street_address'];
        // Append supplemental address fields separated by commas if defined
        if (!empty($address['supplemental_address_1'])) {
          $newAddress = $address . ', ' . $address['supplemental_address_1'];
        }
        if (!empty($address['supplemental_address_2'])) {
          $newAddress = $address . ', ' . $address['supplemental_address_2'];
        }
        $addressData[$address['contact_id']] = $newAddress;
      }
    }
    return $addressData;
  }

  /**
   * Filter the external identifier if it starts with "IMB-", if not set to ''
   * @param $contacts array
   *
   * @return string
   */
  private function filterExternalContactIds(&$contacts) {
    foreach ($contacts as $id => $data) {
      if (substr($data['external_identifier'], 0, 4) != 'IMB-') {
        $contacts['id']['external_identifier'] = '';
      }
    }
  }

  /**
   * Returns an array of [contact_id]=>group status (eg. Added)
   * @param $startContactId
   * @param $endContactId
   * @param $groupName
   *
   * @return array
   */
  private function getContactGroupStatus($startContactId, $endContactId, $groupName) {
    // Get the group Id
    $group = civicrm_api3('Group', 'get', array(
      'name' => $groupName,
      'options' => array('limit' => 1),
    ));
    $groups = array();
    if ($group['count'] > 0) {
      $sql="
SELECT contact_id,status FROM `civicrm_group_contact` gcon 
WHERE gcon.contact_id BETWEEN %1 AND %2 AND gcon.group_id=%3";
      $params[1] = array($startContactId, 'Integer');
      $params[2] = array($endContactId-$startContactId, 'Integer');
      $params[3] = array($group['id'], 'Integer');
      $dao = CRM_Core_DAO::executeQuery($sql,$params);
      while ($dao-fetch()) {
        $groups[$dao->contact_id] = $dao->status;
      }
    }
    return $groups;
  }

  /**
   * Returns an array of [contact_id]=>(external_identifier=>campaign_extid1,campaign_extid2.., survey_id=>surveyid1,surveyid2..)
   * @param $startContactId
   * @param $endContactId
   *
   * @return array
   */
  private function getContactSurveys($startContactId,$endContactId) {
    //'Campaign_Topic' => // fill with external campaign identifiers of the linked survey (linked via activity) (each value only once)
    //'Petition' => // fill with internal CiviCRM „Survey ID“ if any activity of the contact is connected to a survey  (each value only once)
    $surveys = array();
    $sql="
SELECT GROUP_CONCAT(DISTINCT acamp.external_identifier) AS external_identifier,GROUP_CONCAT(DISTINCT act.source_record_id) as survey_id,acon.contact_id 
  FROM `civicrm_activity` act 
LEFT JOIN `civicrm_activity_contact` acon ON act.id=acon.activity_id 
LEFT JOIN `civicrm_campaign` acamp ON act.campaign_id=acamp.id 
WHERE act.activity_type_id=28 AND acon.record_type_id=3 AND acon.contact_id BETWEEN %1 AND %2 
GROUP BY acon.contact_id";
    $params[1] = array($startContactId, 'Integer');
    $params[2] = array($endContactId, 'Integer');
    $dao = CRM_Core_DAO::executeQuery($sql,$params);
    while ($dao->fetch()) {
      $surveys[$dao->contact_id] = array(
        'external_identifier' => $dao->external_identifier,
        'survey_id' => $dao->survey_id,
      );
    }
    return $surveys;
  }

  /**
   * Upload the given file using method (default sftp)
   *
   * @param string $method
   */
  private function upload() {
    if ($this->_exportComplete) {
      // Check if any data was found
      if (!$this->exportFile['hasContent']) {
        return FALSE;
      }

      // We have data, so upload the file
      $uploader = new CRM_Customexport_Upload($this->exportFile['outfile']);
      $uploader->setServer($this->exportFile['remote'] . $this->exportFile['outfilename'], TRUE);
      if ($uploader->upload() != 0) {
        $this->exportFile['uploaded'] = FALSE;
        $this->exportFile['uploadError'] = $uploader->getErrorMessage();
      }
      else {
        $this->exportFile['uploaded'] = TRUE;
      }
    }
  }
}