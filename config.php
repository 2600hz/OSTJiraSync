<?php

require_once(INCLUDE_DIR.'/class.plugin.php');
require_once(INCLUDE_DIR.'/class.forms.php');

class jiraSyncConfig extends PluginConfig{
    function getOptions() {
        $disabled_staff = [];
        foreach (Staff::objects() as $s) {
            if(!$s->isactive){
                $disabled_staff[$s->getId()] = $s->getName();
            }
        }

        return array(
            'frequency' => new ChoiceField(
                    array(
                        'label' => 'Update Frequency',
                        'choices' => [
                            '0' => 'Every Cron',
                            '1' => 'Every Hour',
                            '2' => 'Every 2 Hours',
                            '6' => 'Every 6 Hours',
                            '12' => 'Every 12 Hours',
                            '24' => 'Every 1 Day'
                            ],
                        'default' => '2',
                        'hint' => "How often should we update JIRA statuses?"
                        ),
                    ),
            'jira-ost-status' => new ChoiceField(
                array(
                    'id' => 'jira-ost-status',
                    'label' => 'osTicket status for JIRA tickets',
                    'hint' => 'Would you like to automatically assign an osTicket status for any valid JIRA ticket?<br>Set that status here. Or leave as "select" if not.',
                    'choices' => $this->getOsTicketStatus()
                )
             ),
            'jira-json-responses' => new TextareaField(
                array(
                    'id' => 'jira-json-responses',
                    'label' => 'JSON Response String',
                    'hint' => 'Contruct/paste an JSON array here to specify what responses should be sent in response to status updates.',
                    'configuration' => array('html'=>false, 'rows'=>2, 'cols'=>40),
                )
             ),
            'robot-account' => new ChoiceField(
                array(
                    'id' => 'robot-account',
                    'choices' => $disabled_staff,
                    'label' => 'Robot Account',
                    'hint' => 'Please create/select an account for sending replies, account MUST be locked, still works.<br>Leave as "select" to attempt to use the assigned staff to send replies. (not reccomended)',
                )
             ),
            'jira-unseen-ticket-webhook' => new TextboxField([
                'id' => 'jira-unseen-ticket-webhook',
                'label' => 'Unseen Ticket Webhook (optional)',
                'hint' => 'This webhook will be triggered when a previously unseen JIRA ticket is added to osTicket.',
                'configuration' => array(
                    'html' => FALSE,
                    'size' => 40,
                    'length' => 256
                )
            ]),
            'banned-projects' => new TextboxField([
                'id' => 'banned-projects',
                'label' => 'Banned Projects (optional)',
                'hint' => 'Comma separated projects that are NOT allowed to be synced. For instance, if you did not want PRJA or PRJB tickets to be synced, you could enter “PRJA,PRJB” (without the quotes).',
                'configuration' => array(
                    'html' => FALSE,
                    'size' => 40,
                    'length' => 256
                )
            ]),
            'sb-jira-ticket-fields' => new SectionBreakField([
                'id' => 'sb-jira-ticket-fields',
                'label' => "Jira Ticket Fields",
                'hint' => 'Please setup two short answer fields to track JIRA ticket number and JIRA ticket status, then select them here'
                . '<br>You can do this under Admin Panel > Manage > Forms > Ticket Details'
            ]),
            'jira-ticket-var-id' => new ChoiceField(
                array(
                    'id' => 'jira-ticket-var-id',
                    'label' => 'JIRA Ticket Number Field',
                    'choices' => $this->getJiraFieldChoices()
                )
            ),
            'jira-status-var-id' => new ChoiceField(
                array(
                    'id' => 'jira-status-var-id',
                    'label' => 'JIRA Status Field',
                    'hint' => 'The plug-in will undo any manual changes to this field.<br>For a better user experience, consider making it immutable in: Field Config > Settings',
                    'choices' => $this->getJiraFieldChoices()
                )
            ),
            'sb-jira-creds' => new SectionBreakField([
                'id' => 'sb-jira-creds',
                'label' => "JIRA Credentials",
                'hint' => 'Please input your JIRA credentials here.'
            ]),
            'jira-host' => new TextboxField([
                'label' => 'Hostname',
                'configuration' => array(
                    'html' => FALSE,
                    'size' => 40,
                    'length' => 256
                )
            ]),
            'jira-user' => new TextboxField([
                'label' => 'Username',
                'configuration' => array(
                    'html' => FALSE,
                    'size' => 40,
                    'length' => 256
                )
            ]),
            'jira-password' => new TextboxField([
                'label' => 'Password',
                'hint' => 'You should probably be using an API key here',
                'widget' => 'PasswordWidget',
                'configuration' => array(
                    'html' => FALSE,
                    'size' => 40,
                    'length' => 256
                )
            ]),
        );
    }

    function pre_save(&$config, &$errors) {
        global $msg;

        // Checks jira-json-responses for invalud JSON structure
        $jiraResponses = json_decode($config['jira-json-responses']);
        if(json_last_error() !== JSON_ERROR_NONE){
            $errors['err'] = "Please check your JSON Response String. The current value is not valid JSON.";
            return false;
        }

        // checks for non-number keys in the decoded array (these aren't allowed)
        if(array_keys($jiraResponses) !== range(0, count($jiraResponses) - 1)) {
            $errors['err'] = "Please check your JSON Response String. It should not be an assoicative array at the root.";
            return false;
        }

        // iterates over all JSON array elements insuring reqired keys are all there
        // and also that there aren't any un-supported keys
        foreach($jiraResponses as $line => $response){
            $reqiredKeys = ['old', 'new', 'message'];
            $optionalKeys = ['continue', 'private','webhook','jiraComment'];
            // Checks to insure all required keys are in the line
            foreach($reqiredKeys as $reqiredKey) {
                if (!array_key_exists($reqiredKey, $response)) {
                    $errors['err'] = sprintf("Error in element %d of your JSON Response String. "
                            . "There is no '%s' key. "
                            . "This is required. (Array is 0 based)", $line, $reqiredKey);
                    return false;
                }
            }
            foreach($response as $key => $value) {
                if (!in_array($key, $reqiredKeys)  && !in_array($key, $optionalKeys)) {
                    $errors['err'] = sprintf("Error in element %d of your JSON Response String. "
                            . "'%s' is not a supported field, "
                            . "please remove it. (Array is 0 based)", $line, $key);
                    return false;
                }
            }
        }

        // Makes sure the Jira ticket and jira status fields aren't the SAME!
        if($config['jira-ticket-var-id'] === $config['jira-status-var-id'])
        {
            $errors['err'] = "Your JIRA Ticket Number Field and JIRA Status Field cannot be the same.";
            return false;
        }

        // Validates JIRA creds
        $jiraCredsCheckResults = $this->validateJiraCreds($config['jira-host'], $config['jira-user'], $config['jira-password']);
        if($jiraCredsCheckResults !== true){
            $errors['err'] = $jiraCredsCheckResults;
            return false;
        }

        // Encrypts/saves password
        $config['jira-password'] = Crypto::encrypt($config['jira-password'],
                SECRET_SALT);

        return true;
     }

      /**
         * This plug-in requires that you setup two "short answer" type fields
         * One to store the associated JIRA ticket number
         * and another to track the last known status of the JIRA ticket
         * This function provides choices for the end-user to select the fields
         */
     function getJiraFieldChoices(){
                 // There's only one "ticket" type dynamic form, but
        // this is the easiest way to get it :)
        $ticket_form = DynamicForm::objects()->filter(array('type'=>'T'))[0];
        $JiraFieldChoices = array();

        foreach ($ticket_form->getDynamicFields() as $field) {
            // skipping built-in ticket fields
            if (in_array($field->getField()->getSelectName(), ['subject','message', 'priority'], true )){
                continue;
            }
            // gets the type of form for this field
            $formtype = FormField::getFieldType($field->get('type'))[0];
            // don't add unless it's a short answer type
            if ($formtype !== "Short Answer") {
                continue;
            }
            // adds fields that qualify
            $JiraFieldChoices[$field->getField()->getId()] = $field->getField()->getLabel();

        }

        return $JiraFieldChoices;

     }

     function validateJiraCreds($jiraHost, $jiraUser, $jiraPassword) {
         try {
            $curl = curl_init();

            $basic_creds = base64_encode($jiraUser . ":" . $jiraPassword);

            curl_setopt_array($curl, array(
              CURLOPT_URL => sprintf('%s/rest/api/3/announcementBanner', $jiraHost),
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => '',
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 0,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => 'GET',
              CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . $basic_creds,
                'Content-Type: application/json'
              ),
            ));
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($httpCode === 200) {
                return true;
            } else {
                return sprintf("The supplied JIRA credentials or Jira host do not seem to be correct. Please check and try again. HTTP CODE: %d Response: %s", $httpCode, $response);
            }
            
             } catch (Exception $e) {
                 return sprintf("JIRA credentials failed with a  code %d and message:<br>".PHP_EOL." %s .",$e->getCode(),$e->getMessage());
             }
    }

     function getOsTicketStatus() {
        $statuses = TicketStatusList::getStatuses();
        $status_choices = array();

        foreach ($statuses as $status) {
            $status_choices[$status->getId()] = $status->getName();
        }
        return $status_choices;
    }

}
