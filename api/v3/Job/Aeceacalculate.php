<?php

  /**
   *
   * @param array $params
   * @return array API result descriptor
   * @see civicrm_api3_create_success
   * @see civicrm_api3_create_error
   * @throws API_Exception
   */
  function civicrm_api3_job_aeceacalculate($params) {

    // Pull up all of >= yesterday's contribution of Financial Type = (see $params) that are Completed:
    // -7 days is regular interval
    // KG: do NOT ask for 'financial_type_id' in the 'return' because it will come up empty
    $yesterday = date("Y-m-d", strtotime( '-5 days' ) );
    $resultContributions = civicrm_api3('Contribution', 'get', array(
      'sequential' => 1,
      'return' => ["contact_id", "receive_date"],
      'financial_type_id' => array($params['financialtype']),
      'contribution_status_id' => ["Completed"],
      'receive_date' => array('>=' => $yesterday),
      'options' => array(
        'limit' => 0,
      ),
    ));

    $output = [];
    $counter = 0;
    foreach ($resultContributions['values'] as $singleContribution) {
      $contactId = '';
      $contributionID = '';
      $financialTypeId ='';
      $date = '';

      $contactId = $singleContribution['contact_id'];
      $contributionID = $singleContribution['contribution_id'];
      $financialTypeId = $singleContribution['financial_type_id'];
      $date = $singleContribution['receive_date'];

      // Calculate new end date
      if (!empty($date)) {
        if ($params['financialtype'] == 'KG Payment') {
          $c = strtotime(date("Y-m-d", strtotime($date)) . " +1 month");
        // }
        // elseif ($params['financialtype'] == 'Other Contributions') {
        //   $c = strtotime(date("Y-m-d", strtotime($date)) . " +3 month");
        };

        $newEndDate = date("Y-m-d", $c);

        // format $date
        $receive_date = substr($date, 0, 10);
      }

      $createMembership = aeceamembership_calc_Membership($contactId, $newEndDate, $contributionID, $receive_date, $financialTypeId);
      $membershipId = $createMembership['id'];
      $output[] = ts('Membership for contact id %1 %2 %3 %4 %5', array(1 => $contactId, 2 => $financialTypeId, 3 => $membershipId, 4 => $receive_date, 5 => $newEndDate));
      ++$counter;
    }

    return civicrm_api3_create_success(
      ts(
        '%1 contribution record(s) were processed.',
        array(
          1 => $counter
        )
      ) . "<br />" . implode("<br />", $output)
    );

  }
