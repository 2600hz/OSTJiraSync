<?php
define ( 'JIRASYNC_LOG', __DIR__ . '/log.txt' );

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.signal.php');
require_once('class.dispatcher.php');
require_once('class.dynamic_forms.php');
require_once('class.osticket.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('vendor/autoload.php');

require_once ('config.php');// Ensure the config is loaded first



class jiraSync extends Plugin {
    const DEBUG = TRUE;

    var $config_class = 'jiraSyncConfig'; // Tell the plugin system how to find our config

    function bootstrap() {
        // Fetch the config from the parent Plugin class
        $config = $this->getConfig();
        Signal::connect('model.created', array($this, "receiveModelCreateSignal"));
        
        // Listen for cron Signal, which only happens at end of class.cron.php:
        Signal::connect('cron', array($this, "cronRun"));
    }
    
    /**
     * Receives model creation signal
     * We might want to do a few things depending on what exactly is received.
     * This function makes those decisions and routes to appropriate functions
     * @param object $object The object that was created
     * @param array $data The data for the associated object
     */    
    function receiveModelCreateSignal($object, $data){
        // some sanity checks...
        if(!$config = $this->getConfig()){ return null; }
        if(!$jiraTikNumFieldId = $config->get('jira-ticket-var-id')){ return null; }
        if(!$jiraTikStatusFieldId = $config->get('jira-status-var-id')){ return null; }
       
        $object_as_array = (array) $object;
        if(property_exists($object, "ht")){
            if(isset($object->ht['data'])) {
                // not what we are looking for if there's no event ID...
                if(!isset($object->ht['event_id'])) { return null; }
                // not what we are looking for if the event ISNT an edited event
                if(!$object->ht['event_id'] == Event::getIdByName('edited')) { return null; }
                // not what we are looking for if there's no thread ID
                if(!isset($object->ht['thread_id'])) { return null; }
                
                $thread_id = $object->ht['thread_id'];
                $thread = Thread::lookup($thread_id);
                
                // Just make sure this thread type is actually for a ticket (there are other types)
                if($thread->getObjectType() !== "T") { return null; }
                
                $ticket_id = $thread->getObjectId();
                
                $object_data = json_decode($object->ht['data'], true);
                
                // Looks good! lets see if this is for a change to the JIRA ticket number field
                if(isset($object_data['fields'][$jiraTikNumFieldId])){
                    $oldFieldData = $object_data['fields'][$jiraTikNumFieldId][0];
                    $newFieldData = $object_data['fields'][$jiraTikNumFieldId][1];
                    
                    if($oldFieldData != $newFieldData){
                       $this->updateJiraTracking($ticket_id, $newFieldData, $oldFieldData);
                    }
                }
                // LLets see if this is for a change to the JIRA status field
                if(isset($object_data['fields'][$jiraTikStatusFieldId])){
                    $oldFieldData = $object_data['fields'][$jiraTikStatusFieldId][0];
                    $newFieldData = $object_data['fields'][$jiraTikStatusFieldId][1];
                    
                    if($oldFieldData != $newFieldData){
                        $this->snapBackJiraStatusChanges($ticket_id, $newFieldData, $oldFieldData);
                    }
                }
            }
        }
        
    }
    
    function updateJiraTracking($ostTicketId, $currentJiraTicketNumber = null, $previousJiraTicketNumber = null) {
		global $ost;

		try {
			// load config
			if(!$config = $this->getConfig()) {
				return null; 
			}

			if(!$jiraTicketNumberFieldId = $config->get('jira-ticket-var-id')) {
				return null;
			}

			if(!$jiraTicketStatusFieldId = $config->get('jira-status-var-id')) {
				return null;
			}

			if(!$jiraStatusResponses = $config->get('jira-json-responses')) {
				return null;
			} else {
				$jiraStatusResponses = json_decode($jiraStatusResponses, true);

				if(json_last_error()) {
					return null;
				}
			}

			if(!$jiraHost = $config->get('jira-host')) {
				return null;
			}

			if(!$jiraUser = $config->get('jira-user')) {
				return null;
			}

			if(!$jiraPassword = Crypto::decrypt($config->get('jira-password'), SECRET_SALT)) {
				return null;
			}

			// define $currentJiraTicketNumber - sanitize and normalize
			$currentJiraTicketNumber = preg_replace('/[^a-zA-Z0-9-]+/', '', $currentJiraTicketNumber);


			// define $previousJiraTicketNumber - sanitize and normalize
			$previousJiraTicketNumber = preg_replace('/[^a-zA-Z0-9-]+/', '', $previousJiraTicketNumber);

			// define $ticket
			$ticket = Ticket::lookup($ostTicketId);

			// define $jiraTicketNumberField
			$jiraTicketNumberField = $ticket->getField($jiraTicketNumberFieldId);

			// define $jiraTicketStatusField
			$jiraTicketStatusField = $ticket->getField($jiraTicketStatusFieldId);

			if(!$currentJiraTicketNumber) {
				// define $currentJiraTicketNumber
				if(!($currentJiraTicketNumber = $jiraTicketNumberField->getAnswer()->getValue())) {
					// unable to retrieve currentJiraTicketNumber from ticket
					return null;
				}
			}
			
			// jira ticket identity must be explicitly removed
			if($currentJiraTicketNumber && $previousJiraTicketNumber) {
				$jiraTicketNumberField->setValue($previousJiraTicketNumber);
				$jiraTicketNumberField->save(true);

				$ticket->LogNote('Jira Sync Tool', 'Please do not change the JIRA ticket number once set! If you need to do this, please delete the current value first, then change it to another value. This change has been reverted. Thank you!', null);

				return null;
			}

			// no current jira ticket number, remove status from ticket, remove sync
			if(!$currentJiraTicketNumber && $previousJiraTicketNumber) {
				$jiraTicketStatusField->setValue(null);
				$jiraTicketStatusField->save(true);
				return null;
			}
			
			// Kill off banned projects...
			if(!empty($config->get('banned-projects'))){
				// gets the project of the ticket by stripping off
				// everythign after the first - char
				// Then upper cases it
				$project = strtoupper(substr($currentJiraTicketNumber, 0, strpos($currentJiraTicketNumber, "-")));
				$bannedProjects = explode(",", strtoupper($config->get('banned-projects')));
				
				if(in_array($project, $bannedProjects)) {
					// unset jira ticket number field
					$jiraTicketNumberField->setValue(null);
					$jiraTicketNumberField->save();

					// unset jira status field
					$jiraTicketStatusField->setValue(null);
					$jiraTicketStatusField->save();

					$ticket->LogNote('Jira Sync Tool', sprintf('The %s project type is not allowed to be synced by plugin settings.', $project), null);

					return null;
				}
			}

			$jiraTicketNumberField->setValue($currentJiraTicketNumber);
			$jiraTicketNumberField->save();

			/*
            // strip off any non printable characters from the JIRA ticket
            if (urlencode($jiraTicketNum) !== $jiraTicketNum){
                $printableJira = trim(preg_replace('/[^A-Za-z0-9\-]/', '', $jiraTicketNum));
                if(urlencode($printableJira) === $printableJira){
                    $field = $ticket->getField($jiraTikNumFieldId);
                    $field->setValue($printableJira);
                    $field->save();
                    $jiraTicketNum = $printableJira;
                } else {				
                    $field = $ticket->getField($jiraTikNumFieldId);
                    $field->setValue(null);
                    $field->save();
                    $field = $ticket->getField($jiraTikStatusFieldId);
                    $field->setValue(null);
                    $field->save();
                    $ticket->LogNote('Jira Sync Tool', 'The ticket number contained special characters and could not be fixed.'.urlencode($printableJira), null);
			
                }
            }
				*/

			/*
			if(!$currentJiraTicketNumber) {
				// define $currentJiraTicketNumber
				if(!($currentJiraTicketNumber = $jiraTicketNumberField->getAnswer()->getValue())) {
					// unable to retrieve currentJiraTicketNumber from ticket
					return null;
				}
			}
			*/
 
			if(!$jiraTicketNumberField || !$jiraTicketStatusField) {                       
				// define $forms
				$forms = DynamicFormEntry::forTicket($ticket->getId());

				// iterate over all forms in a ticket
				foreach($forms as $ticketForm) {
					if($ticketForm->getTitle() !== 'Ticket Details') {
						continue;
					}

					// define $form - form in interation is a "Ticket Details" form
					$form = $ticketForm;

					// add missing fields
					$form->addMissingFields();

					// save form
					$form->save(true);
				}
			}
			
			// define $previousJiraStatus
			$previousJiraStatus = $jiraTicketStatusField->getAnswer()->getValue();
			
			// getting current JIRA status from JIRA
			if(!($currentJiraStatus = $this->getJiraStatus($currentJiraTicketNumber, $jiraHost, $jiraUser, $jiraPassword))) {
				// unable to retrieve current jira status
				return null;
			}

			// define $debug
			//$debug = ['jira' => ['current' => $currentJiraStatus, 'previous' => $previousJiraStatus]];
			
			// current and previous jira status match - do nothing
			if($currentJiraStatus == $previousJiraStatus) {
				return null;
			}
			
			if ($jiraStatus == 'unavailable') {
				// unset jira ticket number
				$jiraTicketNumberField->setValue(null);
				$jiraTicketNumberField->save();

				// unset jira status
				$jiraTicketStatusField->setValue(null);
				$jiraTicketStatusField->save();

				$ticket->LogNote('Jira Sync Tool', 'The input JIRA ticket was invalid. So, it has been removed. Please try again', null);
			} else {
				// update the current status osTicket field and save it!
				$jiraTicketStatusField->setValue($currentJiraStatus);
				$jiraTicketStatusField->save();

				// Set the osTicket status (if the status isn't empty that is)
				if($config->get('jira-ost-status')) {
					$ticket->status = $config->get('jira-ost-status');
					$ticket->save();
				}
                                
                // Send a webook if this is a previously unseen JIRA ticket
                // empty($previousJiraStatus) is included so that this isn't sent on every single status change :)
                // only on the original one
                if($this->isJiraUnseen($currentJiraTicketNumber, $ostTicketId) && empty($previousJiraStatus)){
                    // If it's configured that is...
                    if(!empty($config->get('jira-unseen-ticket-webhook'))){
                        
                        $webhook = $config->get('jira-unseen-ticket-webhook');
                        $webhook = str_replace("%ost-number%", $ticket->getNumber(), $webhook);
                        $webhook = str_replace("%ost-id%", $ticket->getId(), $webhook);
                        $webhook = str_replace("%jira-hostname%", $jiraHost, $webhook);
                        $webhook = str_replace("%jira-ticket%", $currentJiraTicketNumber, $webhook);
                        $webhook = str_replace("%jira-old-status%", $previousJiraStatus, $webhook);
                        $webhook = str_replace("%jira-new-status%", $currentJiraStatus, $webhook);
                        file_get_contents($webhook);
                    }
                }
                                
				// Find the first matching response and send it!
				foreach ($jiraStatusResponses as $statusResponse)
				{
					$oldStatusMatched = false;
					$newStatusMatched = false;
					
					if(is_null($statusResponse['old']) &&  empty($previousJiraStatus) ){
						$oldStatusMatched = true;
					}
					if($statusResponse['old'] === "any"){
						$oldStatusMatched = true;
					}
					if($statusResponse['old'] === $previousJiraStatus){
						$oldStatusMatched = true;
					}
					if(is_null($statusResponse['new']) &&  empty($currentJiraStatus) ){
						$newStatusMatched = true;
					}
					if($statusResponse['new'] === "any"){
						$newStatusMatched = true;
					}
					if($statusResponse['new'] === $currentJiraStatus){
						$newStatusMatched = true;
					}
					
					// if both old status and new status match , send the related reply!
					if($oldStatusMatched && $newStatusMatched){
						// replace supported varables.
						$message = $statusResponse['message'];
						$message = str_replace("%ost-number%", $ticket->getNumber(), $message);
						$message = str_replace("%ost-id%", $ticket->getId(), $message);
						$message = str_replace("%jira-hostname%", $jiraHost, $message);
						$message = str_replace("%jira-ticket%", $currentJiraTicketNumber, $message);
						$message = str_replace("%jira-old-status%", $previousJiraStatus, $message);
						$message = str_replace("%jira-new-status%", $currentJiraStatus, $message);
						$this->postReplyTicket($ostTicketId, $message, $statusResponse['private']);
						
						if(!empty($statusResponse['webhook'])){
							// replace supported varables for webhook too (might need to make a function for this soon)
							$webhook = $statusResponse['webhook'];
							$webhook = str_replace("%ost-number%", $ticket->getNumber(), $webhook);
							$webhook = str_replace("%ost-id%", $ticket->getId(), $webhook);
							$webhook = str_replace("%jira-hostname%", $jiraHost, $webhook);
							$webhook = str_replace("%jira-ticket%", $currentJiraTicketNumber, $webhook);
							$webhook = str_replace("%jira-old-status%", $previousJiraStatus, $webhook);
							$webhook = str_replace("%jira-new-status%", $currentJiraStatus, $webhook);
							file_get_contents($webhook);
						}
						if(!empty($statusResponse['jiraComment'])){
							// replace supported varables for jiraStatus too (Ok, last one and I'm functionizing it...)
							$jiraComment = $statusResponse['jiraComment'];
							$jiraComment = str_replace("%ost-number%", $ticket->getNumber(), $jiraComment);
							$jiraComment = str_replace("%ost-id%", $ticket->getId(), $jiraComment);
							$jiraComment = str_replace("%jira-hostname%", $jiraHost, $jiraComment);
							$jiraComment = str_replace("%jira-ticket%", $currentJiraTicketNumber, $jiraComment);
							$jiraComment = str_replace("%jira-old-status%", $previousJiraStatus, $jiraComment);
							$jiraComment = str_replace("%jira-new-status%", $currentJiraStatus, $jiraComment);
							$result = $this->makeJiraComment($currentJiraTicketNumber, $jiraHost, $jiraUser, $jiraPassword, $jiraComment);
							if($result !== true) {
								// post private ticket reply with error
								$this->postReplyTicket($ostTicketId, sprintf("Error posting JIRA comment: ",$result), true);
							}
						}
						// if $statusResponse['continue'] isn't true, return null to prevent any other replies
						if(!$statusResponse['continue']){
							return null;
						}
					}
				}
			}
		} catch(Exception $e) {
                    $ost->logError('JiraSync updateJiraTracking exception occurred', $e->getMessage(), true);
		}
    }
    
    function postReplyTicket($ticketId, $reply, $private=false){
        // load config
        if(!$config = $this->getConfig()){ return null; }
        
        $ticket = Ticket::lookup($ticketId);
        
        // log as a note if $private is true
        if($private){
            $ticket->LogNote('Jira Sync Tool', $reply, null);
                
        } else {
            // checks to see if robot-account can be loaded
            if(!$robot = Staff::lookup($config->get('robot-account'))){
                // if not, tries to load the assignee as the robot account
                $assignee = $ticket->getAssignee();
                if ($assignee instanceof Staff) {
                    $robot = $assignee;
                } else {
                    // If there's no robot AND no assignee, log a note!
                    $ticket->LogNote('Jira Sync Tool', sprintf('Attempted to send a message on the assignee\'s behalf, but there is no assignee. Please set an assignee and update client with this message.<br>Message:<br><br>%s',$reply), null);
                }
            }

            if ($robot instanceof Staff) {
                global $thisstaff;
                $thisstaff = $robot;
                $errors = '';
                $ticket->postReply(['response' => $reply, "reply-to" => "all"], $errors, true, false);
                $thisstaff = null;
            }
        }
            
    }
    
    function makeJiraComment($jiraId, $jiraHost, $jiraUser, $jiraPassword, $message) {
        try {
            $issueService = new JiraRestApi\Issue\IssueService(
                new JiraRestApi\Configuration\ArrayConfiguration(
                        [
                            'jiraHost' => $jiraHost,
                            'jiraUser' => $jiraUser,
                            'jiraPassword' => $jiraPassword
                        ]));
            // define $comment
            $comment = new JiraRestApi\Issue\Comment;
            $comment->setBody($message);
            if($issueService->addComment($jiraId, $comment)){
                return true;
            }   
        } catch (JiraRestApi\JiraException $e) {
             return $e->getMessage();
        }   
    }
    
    function getJiraStatus($jiraId, $jiraHost, $jiraUser, $jiraPassword) {
        try {
            $issueService = new JiraRestApi\Issue\IssueService(
                    new JiraRestApi\Configuration\ArrayConfiguration(
                            [
                                'jiraHost' => $jiraHost,
                                'jiraUser' => $jiraUser,
                                'jiraPassword' => $jiraPassword
                            ]));

            $queryParam = [
                'fields' => [
                    'default' => '*all',
                ],
                'expand' => [
                    'renderedFields',
                    'names',
                    'schema',
                    'transitions',
                    'operations',
                    'editmeta',
                    'changelog',
                ]
            ];

            $issue = $issueService->get($jiraId, $queryParam);

            $status = strtolower($issue->fields->status->name);
            return $status;
        } catch (JiraRestApi\JiraException $e) {
            switch ($e->getCode()) {
                case 404:
                    return 'unavailable';
                    break;
            }
        }
    }
    
    function cronRun($object, $data){
        $config = $this->getConfig();
        if ($this->is_time_to_run($config)) {
            // We need to get the select name for the field we use to track JIRA
            // ticket numbers. This is the var where we will store it.
            // Next few lines find it
            $jiraTicketNumberFieldSelectName = null;
            // get the generic Ticket Form
            $ticket_form = DynamicForm::objects()->filter(array('type'=>'T'))[0];
            // Finds the field used to track the JIRA ticket number
            foreach ($ticket_form->getDynamicFields() as $field) {
                if($field->getField()->getId() == $config->get('jira-ticket-var-id'))
                {
                    // gets the select name from it and saves it
                    $jiraTicketNumberFieldSelectName = $field->getField()->getSelectName();
                }
            }
            
            // finds all opened ticket that have a something in the JIRA ticket field
            // Then, runs updates for them!!
            if(!is_null($jiraTicketNumberFieldSelectName)){
                $jira_tickets = Ticket::objects()->filter(array('status__state' => 'open'))
                        ->filter(Q::not(array(sprintf('cdata__%s__exact', $jiraTicketNumberFieldSelectName) => '')))
                        ->filter(array(sprintf('cdata__%s__exact', $jiraTicketNumberFieldSelectName) => false));
                
                foreach($jira_tickets as $ticket){
                    // do da update!
                    $this->updateJiraTracking($ticket->getId());
                }
                
            }
        }
        
    }
    
    function snapBackJiraStatusChanges($ticket_id, $newFieldData, $oldFieldData) {
        // Automatically reverts manual changes to JIRA status!
        
        // load config
        if(!$config = $this->getConfig()){ return null; }
        if(!$jiraTikStatusFieldId = $config->get('jira-status-var-id')){ return null; }
        // Gets the ticket
        $ticket = Ticket::lookup($ticket_id);
        $field = $ticket->getField($jiraTikStatusFieldId);
        $field->setValue($oldFieldData);
        $field->save();
        
        $ticket->LogNote('Jira Sync Tool',sprintf('The JIRA status was manually changed from "%s" to "%s". Please do not do this! This has been automatically reverted to "%s".', $oldFieldData, $newFieldData, $oldFieldData));
    }
    
    function isJiraUnseen($jiraTicketNum, $ostTicketId){
        // load config
        if(!$config = $this->getConfig()){ return null; }
        
        $isNewJiraTicket = True;
        
        // We need to get the select name for the field we use to track JIRA
        // ticket numbers. This is the var where we will store it.
        // Next few lines find it
        $jiraTicketNumberFieldSelectName = null;
        
        // get the generic Ticket Form
        $ticket_form = DynamicForm::objects()->filter(array('type'=>'T'))[0];
        
        // Finds the field used to track the JIRA ticket number
        foreach ($ticket_form->getDynamicFields() as $field) {
            if($field->getField()->getId() == $config->get('jira-ticket-var-id'))
            {
                // gets the select name from it and saves it
                $jiraTicketNumberFieldSelectName = $field->getField()->getSelectName();
            }
        }
        
        $cdataField = sprintf('cdata__%s__exact', $jiraTicketNumberFieldSelectName);
        
        $jira_tickets = Ticket::objects()->filter(array($cdataField => $jiraTicketNum));
        
        foreach($jira_tickets as $ticket){
            // got to make sure it's not our own osticket ticket we found :) 
            if($ticket->ht['ticket_id'] !== $ostTicketId) {
                $isNewJiraTicket = False;
                break;
            }
        }
        
        return $isNewJiraTicket;
    }

    // credit for this function: 
    // https://github.com/clonemeagain/plugin-autocloser/blob/master/class.CloserPlugin.php
    private function is_time_to_run(PluginConfig $config) {
		// define last_run
        $last_run = $config->get('last-run');

		// define $freq_in_config
		$freq_in_config = (int) $config->get('purge-frequency');
		
		// if current time is greater than or equal to next run time (last run + frequency interval)
		if(time() >= $last_run + ($freq_in_config * 3600)) {
			// reset last run time
			$config->set('last-run', time());

			return true;
		}
		
		return false;
    }

}
