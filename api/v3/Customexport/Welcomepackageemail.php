<?php

/**
 * Welcomepackageemail.Export API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_customexport_welcomepackageemail_spec(&$spec) {
}

/**
 * Welcomepackageemail.Export API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_customexport_welcomepackageemail($params) {
  $exporter = new CRM_Customexport_Welcomepackageemail();
  $result = $exporter->export();
  if (empty($result['is_error'])) {
    return civicrm_api3_create_success(1, $params, 'Customexport', 'Welcomepackageemail');
  }
  else {
    if (!$result['message']) {
      $result['message'] = 'An error occurred during Welcomepackageemail Export';
    }
    throw new API_Exception(/*errorMessage*/ $result['message'], /*errorCode*/ $result['error_code']);
  }
}