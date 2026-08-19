<?php

require_once 'aeceamembership.civix.php';

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function aeceamembership_civicrm_config(&$config) {
  _aeceamembership_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function aeceamembership_civicrm_install() {
  return _aeceamembership_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function aeceamembership_civicrm_enable() {
  return _aeceamembership_civix_civicrm_enable();
}

/**
 * Calculate the new Membership End date
 * @param $userID
 * @param $newEndDate
 * @param $contributionId
 * @param $date
 * @param $financialTypeId
 * @return array|int|void
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
*/
function aeceamembership_calc_Membership($userID, $newEndDate, $contributionId, $date, $financialTypeId) {

  // Membership: Monthly Membership IATS (EAC: id=30) -> Financial Type: Monthly Contributions iATS
  // Membership: Monthly Membership (PAC) (EAC: id=9) -> Financial Type: Monthly Contributions
  // KG JUNE 2024 NEW: Annual Membership EAC (id=33) -> Financial Type: KG AeceaExtension

  $result = civicrm_api3('MembershipType', 'get', [
    'sequential' => 1,
    'return' => ["name"],
    'financial_type_id' => $financialTypeId,
  ]);
  if ($result['count'] != 1 || $result['is_error'] != 0) {
    return;
  };
  $membershipTypeName = $result['values']['0']['name'];
  $AeceaMembershipType_id = $result['values']['0']['id'];

  // No need to continue unless we are dealing with one of the Monthly Contribution Financial Types:
  if ($membershipTypeName !== 'Monthly Membership IATS' AND $membershipTypeName !== 'Monthly Membership (PAC)' AND $membershipTypeName !== 'Annual Membership EAC') {
    return;
  }

  $createNewMembership = TRUE;

  $params = array(
    'contact_id' => $userID,
    'version' => 3,
  );

  require_once 'api/api.php';
  $result = civicrm_api('membership', 'get', $params);
  $memStatus = "";
  if ($result['is_error'] == 0) {
    if ($result['count'] == 0) {
    } elseif ($result['count'] == 1) {
      $membershipId = $result['id'];
      $memStatus = $result['values'][$membershipId]['status_id'];
      $furthest_endDate = $result['values'][$membershipId]['end_date'];
    } elseif ($result['count'] > 1) {
      $furthest_endDate = "";
      foreach ($result['values'] as $k => $single_membership) {
        if (isset($single_membership['end_date'])) {
          if (($furthest_endDate == "") || $single_membership['end_date'] > $furthest_endDate) {
            $furthest_endDate = $single_membership['end_date'];
            $membershipId = $single_membership['id'];
            $memStatus = $single_membership['status_id'];
            $test = 1;
          }
        } else {
	      // no end date means we have a lifetime Membership - use that!
	      $membershipId = $single_membership['id'];
	      $memStatus = $single_membership['status_id'];
	      break;
	     }
      }
    }
  }

  $new_id = $current_id = $grace_id = $expired_id = '';

  $params = array('name' => 'New', 'version' => 3);
  $result_status = civicrm_api('membership_status', 'get', $params);
  if ($result_status['is_error'] == 0) {
    $new_id = $result_status['id'];
  }
  $params = array('name' => 'Current', 'version' => 3);
  $result_status = civicrm_api('membership_status', 'get', $params);
  if ($result_status['is_error'] == 0) {
    $current_id = $result_status['id'];
  }
  $params = array('name' => 'Grace', 'version' => 3);
  $result_status = civicrm_api('membership_status', 'get', $params);
  if ($result_status['is_error'] == 0) {
    $grace_id = $result_status['id'];
  }
  $params = array('name' => 'Expired', 'version' => 3);
  $result_status = civicrm_api('membership_status', 'get', $params);
  if ($result_status['is_error'] == 0) {
    $expired_id = $result_status['id'];
  }

  if (!empty($contributionId)) {
    if ($memStatus == $new_id || $memStatus == $current_id || $memStatus == $grace_id) {
      $pathway = 1;
      $source = 'Pathway 1 - Status was: ' . $memStatus . '; Membership updated via API: post create contribution ID: ' . $contributionId;
      $params = array(
        'contact_id' => $userID,
        'id' => $membershipId,
        'membership_type_id' => $AeceaMembershipType_id,
        'end_date' => $newEndDate,
        'source' => $source,
        'version' => 3,
      );
      $result = civicrm_api('membership', 'create', $params);
      require_once 'api/v3/MembershipStatus.php';
      $newStatus = civicrm_api3_membership_status_calc(array('membership_id' => $membershipId));
      $params = array(
        'contact_id' => $userID,
        'id' => $membershipId,
        'membership_type_id' => $AeceaMembershipType_id,
        'end_date' => $newEndDate,
        'source' => $source,
        'status_id' => $newStatus['id'],
        'version' => 3,
      );
      $result = civicrm_api('membership', 'create', $params);
      $test = 1;
    }
    else if ($memStatus == $expired_id || ($createNewMembership && empty($memStatus))) {
      $pathway = 2;
      $source = 'Pathway 2 - Status was: ' . $memStatus . 'ContributionType was: ' . $financialTypeId . '; Membership created via API: post create contribution ID: ' . $contributionId;
      $params = array(
        'contact_id' => $userID,
        'membership_type_id' => $AeceaMembershipType_id,
        'join_date' => $date,
        'start_date' => $date,
        'end_date' => $newEndDate,
        'source' => $source,
        'status_id' => 1,
        'version' => 3,
      );
      $result = civicrm_api('membership', 'create', $params);
      $test = 1;
    }
  }
  return $result;
}
