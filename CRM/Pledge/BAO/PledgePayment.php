<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use Civi\Api4\Contribution;
use Civi\Api4\Pledge;
use Civi\Api4\PledgePayment;
use Civi\Core\HookInterface;
use Civi\Core\Event\GenericHookEvent;

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class CRM_Pledge_BAO_PledgePayment extends CRM_Pledge_DAO_PledgePayment implements HookInterface {

  /**
   * Get pledge payment details.
   *
   * @param int $pledgeId
   *   Pledge id.
   *
   * @return array
   *   associated array of pledge payment details
   */
  public static function getPledgePayments($pledgeId) {
    $query = "
SELECT    civicrm_pledge_payment.id id,
          scheduled_amount,
          scheduled_date,
          reminder_date,
          reminder_count,
          actual_amount,
          receive_date,
          civicrm_pledge_payment.currency,
          civicrm_option_value.name as status,
          civicrm_option_value.label as label,
          civicrm_contribution.id as contribution_id
FROM      civicrm_pledge_payment

LEFT JOIN civicrm_contribution ON civicrm_pledge_payment.contribution_id = civicrm_contribution.id
LEFT JOIN civicrm_option_group ON ( civicrm_option_group.name = 'contribution_status' )
LEFT JOIN civicrm_option_value ON ( civicrm_pledge_payment.status_id = civicrm_option_value.value AND
                                    civicrm_option_group.id = civicrm_option_value.option_group_id )
WHERE     pledge_id = %1
";

    $params[1] = [$pledgeId, 'Integer'];
    $payment = CRM_Core_DAO::executeQuery($query, $params);

    $paymentDetails = [];
    while ($payment->fetch()) {
      $paymentDetails[$payment->id]['scheduled_amount'] = $payment->scheduled_amount;
      $paymentDetails[$payment->id]['scheduled_date'] = $payment->scheduled_date;
      $paymentDetails[$payment->id]['reminder_date'] = $payment->reminder_date;
      $paymentDetails[$payment->id]['reminder_count'] = $payment->reminder_count;
      $paymentDetails[$payment->id]['total_amount'] = $payment->actual_amount;
      $paymentDetails[$payment->id]['receive_date'] = $payment->receive_date;
      $paymentDetails[$payment->id]['status'] = $payment->status;
      $paymentDetails[$payment->id]['label'] = $payment->label;
      $paymentDetails[$payment->id]['id'] = $payment->id;
      $paymentDetails[$payment->id]['contribution_id'] = $payment->contribution_id;
      $paymentDetails[$payment->id]['currency'] = $payment->currency;
    }

    return $paymentDetails;
  }

  /**
   * Create pledge payments.
   *
   * @param array $params
   *
   * @return CRM_Pledge_DAO_PledgePayment
   */
  public static function createMultiple(array $params) {
    $transaction = new CRM_Core_Transaction();
    $overdueStatusID = CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_PledgePayment', 'status_id', 'Overdue');
    $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_PledgePayment', 'status_id', 'Pending');
    $currency = $params['currency'] ?? CRM_Core_Config::singleton()->defaultCurrency;
    //calculate the scheduled date for every installment
    $now = date('Ymd') . '000000';
    $statues = $prevScheduledDate = [];
    $prevScheduledDate[1] = CRM_Utils_Date::processDate($params['scheduled_date']);

    if (CRM_Utils_Date::overdue($prevScheduledDate[1], $now)) {
      $statues[1] = $overdueStatusID;
    }
    else {
      $statues[1] = $pendingStatusId;
    }

    for ($i = 1; $i < $params['installments']; $i++) {
      $prevScheduledDate[$i + 1] = self::calculateNextScheduledDate($params, $i);
      if (CRM_Utils_Date::overdue($prevScheduledDate[$i + 1], $now)) {
        $statues[$i + 1] = $overdueStatusID;
      }
      else {
        $statues[$i + 1] = $pendingStatusId;
      }
    }

    if ($params['installment_amount']) {
      $params['scheduled_amount'] = round($params['installment_amount'], CRM_Utils_Money::getCurrencyPrecision($currency));
    }
    else {
      $params['scheduled_amount'] = round(($params['amount'] / $params['installments']), 2);
    }

    for ($i = 1; $i <= $params['installments']; $i++) {
      // calculate the scheduled amount for every installment.
      if ($i == $params['installments']) {
        $params['scheduled_amount'] = $params['amount'] - ($i - 1) * $params['scheduled_amount'];
      }
      if (!isset($params['contribution_id']) && $params['installments'] > 1) {
        $params['status_id'] = $statues[$i];
      }

      $params['scheduled_date'] = $prevScheduledDate[$i];
      $payment = self::add($params);
      if (is_a($payment, 'CRM_Core_Error')) {
        $transaction->rollback();
        return $payment;
      }

      // we should add contribution id to only first payment record
      if (isset($params['contribution_id'])) {
        unset($params['contribution_id']);
        unset($params['actual_amount']);
      }
    }

    // update pledge status
    self::updatePledgePaymentStatus($params['pledge_id']);

    $transaction->commit();
    return $payment;
  }

  /**
   * Create individual pledge payment.
   *
   * @param array $params
   *
   * @return CRM_Pledge_DAO_PledgePayment
   * @throws \CRM_Core_Exception
   */
  public static function create(array $params): CRM_Pledge_DAO_PledgePayment {
    // set currency for CRM-1496
    if (empty($params['id']) && !isset($params['currency'])) {
      $params['currency'] = CRM_Core_Config::singleton()->defaultCurrency;
    }
    return self::writeRecord($params);
  }

  /**
   * Add pledge payment.
   *
   * @deprecated - use the api which will use create (soon).
   *
   * @param array $params
   *   Fields in line with the database entity.
   *
   * @return CRM_Pledge_DAO_PledgePayment
   * @throws \CRM_Core_Exception
   */
  public static function add(array $params): CRM_Pledge_DAO_PledgePayment {
    return self::create($params);
  }

  /**
   * Retrieve DB object and copy to defaults array.
   *
   * @param array $params
   *   Array of criteria values.
   * @param array $defaults
   *   Array to be populated with found values.
   *
   * @return self|null
   *   The DAO object, if found.
   *
   * @deprecated
   */
  public static function retrieve($params, &$defaults) {
    return self::commonRetrieve(self::class, $params, $defaults);
  }

  /**
   * Delete pledge payment.
   *
   * @param int $id
   * @deprecated
   * @return bool
   */
  public static function del($id) {
    return (bool) self::deleteRecord(['id' => $id]);
  }

  /**
   * Delete all pledge payments.
   *
   * @param int $id
   *   Pledge id.
   *
   * @return bool
   */
  public static function deletePayments($id) {
    if (!CRM_Utils_Rule::positiveInteger($id)) {
      return FALSE;
    }

    $transaction = new CRM_Core_Transaction();

    $payment = new CRM_Pledge_DAO_PledgePayment();
    $payment->pledge_id = $id;

    if ($payment->find()) {
      while ($payment->fetch()) {
        //also delete associated contribution.
        if ($payment->contribution_id) {
          CRM_Contribute_BAO_Contribution::deleteContribution($payment->contribution_id);
        }
        self::del($payment->id);
      }
    }

    $transaction->commit();

    return TRUE;
  }

  /**
   * On delete contribution record update associated pledge payment and pledge.
   *
   * @param int $contributionID
   *   Contribution id.
   *
   * @return bool
   */
  public static function resetPledgePayment($contributionID) {
    $transaction = new CRM_Core_Transaction();

    $payment = new CRM_Pledge_DAO_PledgePayment();
    $payment->contribution_id = $contributionID;
    if ($payment->find(TRUE)) {
      $payment->contribution_id = 'null';
      $payment->status_id = CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_Pledge', 'status_id', 'Pending');
      $payment->scheduled_date = NULL;
      $payment->reminder_date = NULL;
      $payment->scheduled_amount = $payment->actual_amount;
      $payment->actual_amount = 'null';
      $payment->save();

      //update pledge status.
      $pledgeID = $payment->pledge_id;
      $pledgeStatusID = self::calculatePledgeStatus($pledgeID);
      CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_Pledge', $pledgeID, 'status_id', $pledgeStatusID);

    }

    $transaction->commit();
    return TRUE;
  }

  /**
   * Update Pledge Payment Status.
   *
   * @param int $pledgeID
   *   Id of pledge.
   * @param array $paymentIDs
   *   Ids of pledge payment(s) to update.
   * @param int $paymentStatusID
   *   Payment status to set.
   * @param int $pledgeStatusID
   *   Pledge status to change (if needed).
   * @param float|int $actualAmount , actual amount being paid
   * @param bool $adjustTotalAmount
   *   Is amount being paid different from scheduled amount?.
   * @param bool $isScriptUpdate
   *   Is function being called from bin script?.
   *
   * @return int
   *   $newStatus, updated status id (or 0)
   */
  public static function updatePledgePaymentStatus(
    $pledgeID,
    $paymentIDs = NULL,
    $paymentStatusID = NULL,
    $pledgeStatusID = NULL,
    $actualAmount = 0,
    $adjustTotalAmount = FALSE,
    $isScriptUpdate = FALSE
  ) {
    $totalAmountClause = '';
    $paymentContributionId = NULL;
    $editScheduled = FALSE;

    // get all statuses
    $allStatus = CRM_Core_OptionGroup::values('pledge_status',
      FALSE, FALSE, FALSE, NULL, 'name', TRUE
    );

    // if we get do not get contribution id means we are editing the scheduled payment.
    if (!empty($paymentIDs)) {
      $editScheduled = FALSE;
      $payments = implode(',', $paymentIDs);
      $paymentContributionId = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment',
        $payments,
        'contribution_id',
        'id'
      );

      if (!$paymentContributionId) {
        $editScheduled = TRUE;
      }
    }

    // if payment ids are passed, we update payment table first, since payments statuses are not dependent on pledge status
    $pledgeStatusName = CRM_Core_PseudoConstant::getName('CRM_Pledge_BAO_Pledge', 'status_id', $pledgeStatusID);
    if ((!empty($paymentIDs) || $pledgeStatusName == 'Cancelled') && (!$editScheduled || $isScriptUpdate)) {
      if ($pledgeStatusName == 'Cancelled') {
        $paymentStatusID = $pledgeStatusID;
      }

      self::updatePledgePayments($pledgeID, $paymentStatusID, $paymentIDs, $actualAmount, $paymentContributionId, $isScriptUpdate);
    }
    if (!empty($paymentIDs) && $actualAmount) {
      $payments = implode(',', $paymentIDs);
      $pledgeScheduledAmount = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment',
        $payments,
        'scheduled_amount',
        'id'
      );

      $pledgeStatusId = self::calculatePledgeStatus($pledgeID);
      // Actual Pledge Amount
      $actualPledgeAmount = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_Pledge',
        $pledgeID,
        'amount',
        'id'
      );
      // while editing scheduled  we need to check if we are editing last pending
      $lastPending = FALSE;
      if (!$paymentContributionId) {
        $checkPendingCount = self::getOldestPledgePayment($pledgeID, 2);
        if ($checkPendingCount['count'] == 1) {
          $lastPending = TRUE;
        }
      }

      // check if this is the last payment and adjust the actual amount.
      if ($pledgeStatusId && $pledgeStatusId == array_search('Completed', $allStatus) || $lastPending) {
        // last scheduled payment
        if ($actualAmount >= $pledgeScheduledAmount) {
          $adjustTotalAmount = TRUE;
        }
        elseif (!$adjustTotalAmount) {
          // actual amount is less than the scheduled amount, so enter new pledge payment record
          $pledgeFrequencyUnit = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_Pledge', $pledgeID, 'frequency_unit', 'id');
          $pledgeFrequencyInterval = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_Pledge', $pledgeID, 'frequency_interval', 'id');
          $pledgeScheduledDate = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment', $payments, 'scheduled_date', 'id');
          $scheduled_date = CRM_Utils_Date::processDate($pledgeScheduledDate);
          $date['year'] = (int) substr($scheduled_date, 0, 4);
          $date['month'] = (int) substr($scheduled_date, 4, 2);
          $date['day'] = (int) substr($scheduled_date, 6, 2);
          $newDate = date('YmdHis', mktime(0, 0, 0, $date['month'], $date['day'], $date['year']));
          $ScheduledDate = CRM_Utils_Date::format(CRM_Utils_Date::intervalAdd($pledgeFrequencyUnit,
            $pledgeFrequencyInterval, $newDate
          ));
          $pledgeParams = [
            'status_id' => array_search('Pending', $allStatus),
            'pledge_id' => $pledgeID,
            'scheduled_amount' => ($pledgeScheduledAmount - $actualAmount),
            'scheduled_date' => $ScheduledDate,
          ];
          $payment = self::add($pledgeParams);
          // while editing schedule,  after adding a new pledge payemnt update the scheduled amount of the current payment
          if (!$paymentContributionId) {
            CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', $payments, 'scheduled_amount', $actualAmount);
          }
        }
      }
      elseif (!$adjustTotalAmount) {
        // not last schedule amount and also not selected to adjust Total
        $paymentContributionId = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment',
          $payments,
          'contribution_id',
          'id'
        );
        self::adjustPledgePayment($pledgeID, $actualAmount, $pledgeScheduledAmount, $paymentContributionId, $payments, $paymentStatusID);
        // while editing schedule,  after adding a new pledge payemnt update the scheduled amount of the current payment
        if (!$paymentContributionId) {
          CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', $payments, 'scheduled_amount', $actualAmount);
        }
        // after adjusting all payments check if the actual amount was greater than the actual remaining amount , if so then update the total pledge amount.
        $pledgeStatusId = self::calculatePledgeStatus($pledgeID);
        $balanceQuery = "
 SELECT sum( civicrm_pledge_payment.actual_amount )
 FROM civicrm_pledge_payment
 WHERE civicrm_pledge_payment.pledge_id = %1
 AND civicrm_pledge_payment.status_id = 1
 ";
        $totalPaidParams = [1 => [$pledgeID, 'Integer']];
        $totalPaidAmount = CRM_Core_DAO::singleValueQuery($balanceQuery, $totalPaidParams);
        $remainingTotalAmount = ($actualPledgeAmount - $totalPaidAmount);
        if (($pledgeStatusId && $pledgeStatusId == array_search('Completed', $allStatus)) && (($actualAmount > $remainingTotalAmount) || ($actualAmount >= $actualPledgeAmount))) {
          $totalAmountClause = ", civicrm_pledge.amount = {$totalPaidAmount}";
        }
      }
      if ($adjustTotalAmount) {
        $newTotalAmount = ($actualPledgeAmount + ($actualAmount - $pledgeScheduledAmount));
        $totalAmountClause = ", civicrm_pledge.amount = {$newTotalAmount}";
        if (!$paymentContributionId) {
          CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', $payments, 'scheduled_amount', $actualAmount);
        }
      }
    }

    $cancelDateClause = $endDateClause = NULL;
    // update pledge and payment status if status is Completed/Cancelled.
    if ($pledgeStatusID && $pledgeStatusID == array_search('Cancelled', $allStatus)) {
      $paymentStatusID = $pledgeStatusID;
      $cancelDateClause = ", civicrm_pledge.cancel_date = CURRENT_TIMESTAMP ";
    }
    else {
      // get pledge status
      $pledgeStatusID = self::calculatePledgeStatus($pledgeID);
    }

    if ($pledgeStatusID == array_search('Completed', $allStatus)) {
      $endDateClause = ", civicrm_pledge.end_date = CURRENT_TIMESTAMP ";
    }

    // update pledge status
    $query = "
UPDATE civicrm_pledge
 SET   civicrm_pledge.status_id = %1
       {$cancelDateClause} {$endDateClause} {$totalAmountClause}
WHERE  civicrm_pledge.id = %2
";

    $params = [
      1 => [$pledgeStatusID, 'Integer'],
      2 => [$pledgeID, 'Integer'],
    ];

    CRM_Core_DAO::executeQuery($query, $params);

    return $pledgeStatusID;
  }

  /**
   * Calculate the base scheduled date. This function effectively 'rounds' the $params['scheduled_date'] value
   * to the first payment date with respect to the frequency day  - ie. if payments are on the 15th of the month the date returned
   * will be the 15th of the relevant month. Then to calculate the payments you can use intervalAdd ie.
   * CRM_Utils_Date::intervalAdd( $params['frequency_unit'], $i * ($params['frequency_interval']) , calculateBaseScheduledDate( &$params )))
   *
   * @param array $params
   *
   * @return string
   *   Next scheduled date in the format YmdHis
   */
  public static function calculateBaseScheduleDate(&$params) {
    $date = [];
    $scheduled_date = CRM_Utils_Date::processDate($params['scheduled_date']);
    $date['year'] = (int) substr($scheduled_date, 0, 4);
    $date['month'] = (int) substr($scheduled_date, 4, 2);
    $date['day'] = (int) substr($scheduled_date, 6, 2);
    // calculation of schedule date according to frequency day of period
    // frequency day is not applicable for daily installments
    if ($params['frequency_unit'] != 'day' && $params['frequency_unit'] != 'year') {
      if ($params['frequency_unit'] != 'week') {
        // CRM-18316: To calculate pledge scheduled dates at the end of a month.
        $date['day'] = $params['frequency_day'];
        $lastDayOfMonth = date('t', mktime(0, 0, 0, $date['month'], 1, $date['year']));
        if ($lastDayOfMonth < $date['day']) {
          $date['day'] = $lastDayOfMonth;
        }
      }
      elseif ($params['frequency_unit'] == 'week') {

        // for week calculate day of week ie. Sunday,Monday etc. as next payment date
        $dayOfWeek = date('w', mktime(0, 0, 0, $date['month'], $date['day'], $date['year']));
        $frequencyDay = $params['frequency_day'] - $dayOfWeek;

        $scheduleDate = explode("-", date('n-j-Y', mktime(0, 0, 0, $date['month'],
          $date['day'] + $frequencyDay, $date['year']
        )));
        $date['month'] = $scheduleDate[0];
        $date['day'] = $scheduleDate[1];
        $date['year'] = $scheduleDate[2];
      }
    }
    $newdate = date('YmdHis', mktime(0, 0, 0, $date['month'], $date['day'], $date['year']));
    return $newdate;
  }

  /**
   * Calculate next scheduled pledge payment date. Function calculates next pledge payment date.
   *
   * @param array $params
   *   must include frequency unit & frequency interval
   * @param int $paymentNo
   *   number of payment in sequence (e.g. 1 for first calculated payment (treat initial payment as 0)
   * @param string $basePaymentDate
   *   date to calculate payments from. This would normally be the
   *   first day of the pledge (default) & is calculated off the 'scheduled date' param. Returned date will
   *   be equal to basePaymentDate normalised to fit the 'pledge pattern' + number of installments
   *
   * @return string
   *   formatted date
   */
  public static function calculateNextScheduledDate(&$params, $paymentNo, $basePaymentDate = NULL) {
    $interval = $paymentNo * ($params['frequency_interval']);
    if (!$basePaymentDate) {
      $basePaymentDate = self::calculateBaseScheduleDate($params);
    }

    //CRM-18316 - change $basePaymentDate for the end dates of the month eg: 29, 30 or 31.
    if ($params['frequency_unit'] == 'month' && in_array($params['frequency_day'], [29, 30, 31])) {
      $frequency = $params['frequency_day'];
      extract(date_parse($basePaymentDate));
      $lastDayOfMonth = date('t', mktime($hour, $minute, $second, $month + $interval, 1, $year));
      // Take the last day in case the current month is Feb or frequency_day is set to 31.
      if (in_array($lastDayOfMonth, [28, 29]) || $frequency == 31) {
        $frequency = 0;
        $interval++;
      }
      $basePaymentDate = [
        'M' => $month,
        'd' => $frequency,
        'Y' => $year,
      ];
    }

    return CRM_Utils_Date::format(
      CRM_Utils_Date::intervalAdd(
        $params['frequency_unit'],
        $interval,
        $basePaymentDate
      )
    );
  }

  /**
   * Calculate the pledge status.
   *
   * @param int $pledgeId
   *   Pledge id.
   *
   * @return int
   *   $statusId calculated status id of pledge
   * @throws \API_Exception
   */
  public static function calculatePledgeStatus(int $pledgeId): int {
    if (count(Pledge::get(FALSE)
      ->addWhere('id', '=', $pledgeId)
      ->addWhere('status_id:name', '=', 'Cancelled')->execute())) {
      // Return Canceled if the pledge is cancelled.
      return (int) CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_Pledge', 'status_id', 'Cancelled');
    }

    $pledgePaymentStatuses = (array) PledgePayment::get(FALSE)->addWhere('pledge_id', '=', $pledgeId)->setSelect(['status_id', 'status_id:name'])->execute()->indexBy('status_id:name');
    if (!empty($pledgePaymentStatuses['Overdue'])) {
      return (int) CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_Pledge', 'status_id', 'Overdue');
    }
    if (count($pledgePaymentStatuses) === 1 && !empty($pledgePaymentStatuses['Completed'])) {
      return (int) CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_Pledge', 'status_id', 'Completed');
    }
    if (!empty($pledgePaymentStatuses['Completed'])) {
      // In this case some are completed but not all (or it would have returned just above).
      return (int) CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_Pledge', 'status_id', 'In Progress');
    }
    return (int) CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_Pledge', 'status_id', 'Pending');
  }

  /**
   * Update pledge payment table.
   *
   * @param int $pledgeId
   *   Pledge id.
   * @param int $paymentStatusId
   *   Payment status id to set.
   * @param array $paymentIds
   *   Payment ids to be updated.
   * @param float|int $actualAmount , actual amount being paid
   * @param int $contributionId
   *   , Id of associated contribution when payment is recorded.
   * @param bool $isScriptUpdate
   *   , is function being called from bin script?.
   *
   */
  public static function updatePledgePayments(
    $pledgeId,
    $paymentStatusId,
    $paymentIds = NULL,
    $actualAmount = 0,
    $contributionId = NULL,
    $isScriptUpdate = FALSE
  ) {
    $allStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $paymentClause = NULL;
    if (!empty($paymentIds)) {
      $payments = implode(',', $paymentIds);
      $paymentClause = " AND civicrm_pledge_payment.id IN ( {$payments} )";
    }
    elseif ($paymentStatusId == array_search('Cancelled', $allStatus)) {
      $completedStatus = array_search('Completed', $allStatus);
      $paymentClause = " AND civicrm_pledge_payment.status_id != {$completedStatus}";
    }
    $actualAmountClause = NULL;
    $contributionIdClause = NULL;
    if (isset($contributionId) && !$isScriptUpdate) {
      $contributionIdClause = ", civicrm_pledge_payment.contribution_id = {$contributionId}";
      $actualAmountClause = ", civicrm_pledge_payment.actual_amount = {$actualAmount}";
    }

    $query = "
UPDATE civicrm_pledge_payment
SET    civicrm_pledge_payment.status_id = {$paymentStatusId}
       {$actualAmountClause} {$contributionIdClause}
WHERE  civicrm_pledge_payment.pledge_id = %1
       {$paymentClause}
";

    CRM_Core_DAO::executeQuery($query, [1 => [$pledgeId, 'Integer']]);
  }

  /**
   * Update pledge payment table when reminder is sent.
   *
   * @param int $paymentId
   *   Payment id.
   */
  public static function updateReminderDetails($paymentId) {
    $query = "
UPDATE civicrm_pledge_payment
SET civicrm_pledge_payment.reminder_date = CURRENT_TIMESTAMP,
    civicrm_pledge_payment.reminder_count = civicrm_pledge_payment.reminder_count + 1
WHERE  civicrm_pledge_payment.id = {$paymentId}
";
    $dao = CRM_Core_DAO::executeQuery($query);
  }

  /**
   * Get oldest pending or in progress pledge payments.
   *
   * @param int $pledgeID
   *   Pledge id.
   *
   * @param int $limit
   *
   * @return array
   *   associated array of pledge details
   */
  public static function getOldestPledgePayment($pledgeID, $limit = 1) {
    // get pending / overdue statuses
    $pledgeStatuses = CRM_Core_OptionGroup::values('pledge_status',
      FALSE, FALSE, FALSE, NULL, 'name'
    );

    // get pending and overdue payments
    $status[] = array_search('Pending', $pledgeStatuses);
    $status[] = array_search('Overdue', $pledgeStatuses);

    $statusClause = " IN (" . implode(',', $status) . ")";

    $query = "
SELECT civicrm_pledge_payment.id id, civicrm_pledge_payment.scheduled_amount amount, civicrm_pledge_payment.currency, civicrm_pledge_payment.scheduled_date,civicrm_pledge.financial_type_id
FROM civicrm_pledge, civicrm_pledge_payment
WHERE civicrm_pledge.id = civicrm_pledge_payment.pledge_id
  AND civicrm_pledge_payment.status_id {$statusClause}
  AND civicrm_pledge.id = %1
ORDER BY civicrm_pledge_payment.scheduled_date ASC
LIMIT 0, %2
";

    $params[1] = [$pledgeID, 'Integer'];
    $params[2] = [$limit, 'Integer'];
    $payment = CRM_Core_DAO::executeQuery($query, $params);
    $count = 1;
    $paymentDetails = [];
    while ($payment->fetch()) {
      $paymentDetails[] = [
        'id' => $payment->id,
        'amount' => $payment->amount,
        'currency' => $payment->currency,
        'schedule_date' => $payment->scheduled_date,
        'financial_type_id' => $payment->financial_type_id,
        'count' => $count,
      ];
      $count++;
    }
    return end($paymentDetails);
  }

  /**
   * @param int $pledgeID
   * @param $actualAmount
   * @param $pledgeScheduledAmount
   * @param int $paymentContributionId
   * @param int $pPaymentId
   * @param int $paymentStatusID
   */
  public static function adjustPledgePayment($pledgeID, $actualAmount, $pledgeScheduledAmount, $paymentContributionId = NULL, $pPaymentId = NULL, $paymentStatusID = NULL) {
    $allStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $paymentStatusName = CRM_Core_PseudoConstant::getName('CRM_Pledge_BAO_PledgePayment', 'status_id', $paymentStatusID);
    if ($paymentStatusName == 'Cancelled'|| $paymentStatusName == 'Refunded') {
      $query = "
SELECT civicrm_pledge_payment.id id
FROM  civicrm_pledge_payment
WHERE civicrm_pledge_payment.contribution_id = {$paymentContributionId}
";
      $paymentsAffected = CRM_Core_DAO::executeQuery($query);
      $paymentIDs = [];
      while ($paymentsAffected->fetch()) {
        $paymentIDs[] = $paymentsAffected->id;
      }
      // Reset the affected values by the amount paid more than the scheduled amount
      foreach ($paymentIDs as $key => $value) {
        $payment = new CRM_Pledge_DAO_PledgePayment();
        $payment->id = $value;
        if ($payment->find(TRUE)) {
          $payment->contribution_id = 'null';
          $payment->status_id = array_search('Pending', $allStatus);
          $payment->scheduled_date = NULL;
          $payment->reminder_date = NULL;
          $payment->scheduled_amount = $pledgeScheduledAmount;
          $payment->actual_amount = 'null';
          $payment->save();
        }
      }

      // Cancel the initial paid amount
      CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', reset($paymentIDs), 'status_id', $paymentStatusID, 'id');
      CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', reset($paymentIDs), 'actual_amount', $actualAmount, 'id');

      // Add new payment after the last payment for the pledge
      $allPayments = self::getPledgePayments($pledgeID);
      $lastPayment = array_pop($allPayments);

      $pledgeFrequencyUnit = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_Pledge', $pledgeID, 'frequency_unit', 'id');
      $pledgeFrequencyInterval = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_Pledge', $pledgeID, 'frequency_interval', 'id');
      $pledgeScheduledDate = $lastPayment['scheduled_date'];
      $scheduled_date = CRM_Utils_Date::processDate($pledgeScheduledDate);
      $date['year'] = (int) substr($scheduled_date, 0, 4);
      $date['month'] = (int) substr($scheduled_date, 4, 2);
      $date['day'] = (int) substr($scheduled_date, 6, 2);
      $newDate = date('YmdHis', mktime(0, 0, 0, $date['month'], $date['day'], $date['year']));
      $ScheduledDate = CRM_Utils_Date::format(CRM_Utils_Date::intervalAdd($pledgeFrequencyUnit, $pledgeFrequencyInterval, $newDate));
      $pledgeParams = [
        'status_id' => array_search('Pending', $allStatus),
        'pledge_id' => $pledgeID,
        'scheduled_amount' => $pledgeScheduledAmount,
        'scheduled_date' => $ScheduledDate,
      ];
      $payment = self::add($pledgeParams);
    }
    else {
      $nextPledgeInstallmentDue = self::getOldestPledgePayment($pledgeID);
      if (!$paymentContributionId) {
        // means we are editing payment scheduled payment, so get the second pending to update.
        $nextPledgeInstallmentDue = self::getOldestPledgePayment($pledgeID, 2);
        if (($nextPledgeInstallmentDue['count'] != 1) && ($nextPledgeInstallmentDue['id'] == $pPaymentId)) {
          $nextPledgeInstallmentDue = self::getOldestPledgePayment($pledgeID);
        }
      }

      if ($nextPledgeInstallmentDue) {
        // not the last scheduled payment and the actual amount is less than the expected , add it to oldest pending.
        if (($actualAmount != $pledgeScheduledAmount) && (($actualAmount < $pledgeScheduledAmount) || (($actualAmount - $pledgeScheduledAmount) < $nextPledgeInstallmentDue['amount']))) {
          $oldScheduledAmount = $nextPledgeInstallmentDue['amount'];
          $newScheduledAmount = $oldScheduledAmount + ($pledgeScheduledAmount - $actualAmount);
          // store new amount in oldest pending payment record.
          CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment',
            $nextPledgeInstallmentDue['id'],
            'scheduled_amount',
            $newScheduledAmount
          );
          if (CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment', $nextPledgeInstallmentDue['id'], 'contribution_id', 'id')) {
            CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment',
              $nextPledgeInstallmentDue['id'],
              'contribution_id',
              $paymentContributionId
            );
          }
        }
        elseif (($actualAmount > $pledgeScheduledAmount) && (($actualAmount - $pledgeScheduledAmount) >= $nextPledgeInstallmentDue['amount'])) {
          // here the actual amount is greater than expected and also greater than the next installment amount, so update the next installment as complete and again add it to next subsequent pending payment
          // set the actual amount of the next pending to '0', set contribution Id to current contribution Id and status as completed
          $paymentId = [$nextPledgeInstallmentDue['id']];
          self::updatePledgePayments($pledgeID, array_search('Completed', $allStatus), $paymentId, 0, $paymentContributionId);
          CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', $nextPledgeInstallmentDue['id'], 'scheduled_amount', 0, 'id');
          if (!$paymentContributionId) {
            // means we are editing payment scheduled payment.
            $oldestPaymentAmount = self::getOldestPledgePayment($pledgeID, 2);
          }
          $newActualAmount = round(($actualAmount - $pledgeScheduledAmount), CRM_Utils_Money::getCurrencyPrecision());
          $newPledgeScheduledAmount = $nextPledgeInstallmentDue['amount'];
          if (!$paymentContributionId) {
            $newActualAmount = ($actualAmount - $pledgeScheduledAmount);
            $newPledgeScheduledAmount = $oldestPaymentAmount['amount'];
            // means we are editing payment scheduled payment, so update scheduled amount.
            CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment',
              $oldestPaymentAmount['id'],
              'scheduled_amount',
              $newActualAmount
            );
          }
          if ($newActualAmount > 0) {
            self::adjustPledgePayment($pledgeID, $newActualAmount, $newPledgeScheduledAmount, $paymentContributionId);
          }
        }
      }
    }
  }

  /**
   * Override buildOptions to hack out some statuses.
   *
   * @todo instead of using & hacking the shared optionGroup contribution_status use a separate one.
   *
   * @param string $fieldName
   * @param string $context
   * @param array $props
   *
   * @return array|bool
   */
  public static function buildOptions($fieldName, $context = NULL, $props = []) {
    $result = parent::buildOptions($fieldName, $context, $props);
    if ($fieldName == 'status_id') {
      $result = CRM_Pledge_BAO_Pledge::buildOptions($fieldName, $context, $props);
      $result = array_diff($result, ['Failed', 'In Progress']);
    }
    return $result;
  }

  /**
   * Update pledge payments based on contribution updates.
   *
   * - Disconnect pledge payments from cancelled or failed contributions.
   * - Complete Completed payments
   *
   * Test cover in testCancelOrderWithPledge, testCompleteTransactionUpdatePledgePayment.
   *
   * @param \Civi\Core\Event\GenericHookEvent $event
   *
   * @throws \API_Exception
   */
  public static function on_hook_civicrm_post(GenericHookEvent $event): void {
    if (!CRM_Core_Component::isEnabled('CiviPledge')) {
      return;
    }
    if ($event->entity === 'Contribution' && $event->action === 'edit' && !empty($event->object->contribution_status_id)) {
      $contributionStatus = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $event->object->contribution_status_id);

      if (!in_array($contributionStatus, ['Failed', 'Cancelled', 'Completed'], TRUE)) {
        return;
      }
      $contributionID = $event->object->id;
      if ($contributionStatus === 'Completed' && empty($event->object->total_amount)) {
        // This is precautionary as it is likely it is always loaded in the BAO.
        $event->object->total_amount = Contribution::get(FALSE)->addWhere('id', '=', $contributionID)->addSelect('total_amount')->execute()->first()['total_amount'];
      }

      // Check first since just doing an update could be locking under load.
      $pledgePayment = PledgePayment::get(FALSE)
        ->addWhere('contribution_id', '=', $contributionID)
        ->setSelect(['id', 'pledge_id', 'scheduled_date', 'scheduled_amount', 'status_id:name', 'pledge_id.status_id'])
        ->execute()
        ->first();
      if (!empty($pledgePayment)) {
        if ($pledgePayment['status_id:name'] === 'Completed' && $contributionStatus === 'Completed') {
          return;
        }
        PledgePayment::update(FALSE)->setValues([
          'contribution_id' => $contributionStatus === 'Completed' ? $contributionID : NULL,
          'actual_amount' => $contributionStatus === 'Completed' ? $event->object->total_amount : NULL,
          'status_id:name' => $contributionStatus === 'Completed' ? 'Completed' : 'Pending',
          // We need to set these fields for now because the PledgePayment::create
          // function doesn't handled updates well at the moment. Test cover
          // in testCancelOrderWithPledge.
          'scheduled_date' => $pledgePayment['scheduled_date'],
          'installment_amount' => $pledgePayment['scheduled_amount'],
          'installments' => 1,
          'pledge_id' => $pledgePayment['pledge_id'],
        ])->addWhere('id', '=', $pledgePayment['id'])->execute();
        if ($contributionStatus === 'Completed') {
          // Check if this completes the pledge.
          // Ideally we would listen to PledgePayment update for this.
          // The risk could be a code loop? For now just do for completed.
          $pledgeExpectedStatus = self::calculatePledgeStatus($pledgePayment['pledge_id']);
          if ($pledgeExpectedStatus !== $pledgePayment['pledge_id.status_id']) {
            Pledge::update(FALSE)->addWhere('id', '=', $pledgePayment['pledge_id'])->setValues(['status_id', '=', $pledgeExpectedStatus])->execute();
          }
        }
      }
    }
  }

}
