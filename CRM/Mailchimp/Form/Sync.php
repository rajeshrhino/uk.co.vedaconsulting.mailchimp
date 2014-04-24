<?php

class CRM_Mailchimp_Form_Sync extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-sync';
  const END_URL    = 'civicrm/mailchimp/sync';
  const END_PARAMS = 'state=done';
  const BATCH_COUNT = 10;

  /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats = CRM_Mailchimp_BAO_MCSync::getSyncStats();
      $this->assign('stats', $stats);
    }
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Sync Contacts'),
      ),
    );

    // Add the Buttons.
    $this->addButtons($buttons);
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // get member count
    $count  = CRM_Mailchimp_Utils::getMemberCountForGroupsToSync();

    // Set the Number of Rounds
    $rounds = ceil($count/self::BATCH_COUNT);

   // Setup a Task in the Queue
    $i = 0;
    while ($i < $rounds) {
      $start = $i * self::BATCH_COUNT;
      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Sync', 'runSync'),
        array($start),
        'Mailchimp Sync - Contacts '. ($start+self::BATCH_COUNT) . ' of ' . $count
      );

      // Add the Task to the Queu
      $queue->createItem($task);
      $i++;
    }

    if ($i > 0) {
      CRM_Mailchimp_BAO_MCSync::resetTable();

      // Setup the Runner
      $runner = new CRM_Queue_Runner(array(
        'title' => ts('Mailchimp Sync'),
        'queue' => $queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
        'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS),
      ));

      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to sync. Make sure mailchimp settings are configured for the groups with enough members.'));
    }
  }

  /**
   * Run the From
   *
   * @access public
   *
   * @return TRUE
   */
  public function runSync(CRM_Queue_TaskContext $ctx, $start) {
    $mcGroupIDs = CRM_Mailchimp_Utils::getGroupIDsToSync();
    if (!empty($mcGroupIDs)) {
      $mcGroups  = CRM_Mailchimp_Utils::getGroupsToSync();

      $groupContact = new CRM_Contact_BAO_GroupContact();
      $groupContact->whereAdd('group_id IN ('.implode(',', $mcGroupIDs).')');
      $groupContact->whereAdd("status = 'Added'");
      $groupContact->limit($start, self::BATCH_COUNT);
      $groupContact->find();

      $emailToIDs = array();
      $mapper = array();
      while ($groupContact->fetch()) {
        $contact = new CRM_Contact_BAO_Contact();
        $contact->id = $groupContact->contact_id;
        $contact->find(TRUE);

        $email = new CRM_Core_BAO_Email();
        $email->contact_id = $groupContact->contact_id;
        $email->is_primary = TRUE;
        $email->find(TRUE);

        $listID = $mcGroups[$groupContact->group_id]['list_id'];
        $groups = $mcGroups[$groupContact->group_id]['group_id'];
        $groupings = array();
        if (!empty($groups)) {
          list($grouping, $group) = explode(CRM_Core_DAO::VALUE_SEPARATOR, trim($groups));
          if ($grouping && $group) {
            $groupings = 
              array(
                array(
                  'name'   => $grouping,
                  'groups' => array($group)
                )
              );
          }
        }

        if ($email->email && 
          ($contact->is_opt_out   == 0) && 
          ($contact->do_not_email == 0) &&
          ($email->on_hold        == 0)
        ) {
          $mapper[$listID]['batch'][] = array(
            'email'       => array('email' => $email->email),
            'merge_vars'  => array(
              'fname'     => $contact->first_name, 
              'lname'     => $contact->last_name,
              'groupings' => $groupings,
            ),
          );
        } 
        if ($email->id) {
          $emailToIDs["{$email->email}"] = $email->id;
        }
      }

      foreach ($mapper as $listID => $vals) {
        $mailchimp = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
        $results   = $mailchimp->batchSubscribe( 
          $listID,
          $vals['batch'], 
          FALSE,
          TRUE, 
          TRUE
        );
        foreach (array('adds', 'updates', 'errors') as $key) {
          foreach ($results[$key] as $data) {
            $email  = $key == 'errors' ? $data['email']['email'] : $data['email'];
            $params = array(
              'email_id'   => $emailToIDs[$email],
              'mc_list_id' => $listID,
              'mc_euid'    => $data['euid'],
              'mc_leid'    => $data['leid'],
              'sync_status' => $key == 'adds' ? 'Added' : ( $key == 'updates' ? 'Updated' : 'Error')
            );
            CRM_Mailchimp_BAO_MCSync::create($params);
          }
        }
      }
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }
}
