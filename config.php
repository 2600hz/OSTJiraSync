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
            'banned-projects' => new TextboxField([
                'id' => 'banned-projects',
                'label' => 'Banned Projects',
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
            // skipping non-short-answer fields
            // can't find a way to dierectly check they field, type
            // So, since short answer fields should contain a size AND length config
            // We will skip any fields that don't
            if (!array_key_exists('size', $field->getField()->getConfiguration())) {
                continue;
            }
            if (!array_key_exists('length', $field->getField()->getConfiguration())) {
                continue;
            }
            
            // adds fields that qualify
            $JiraFieldChoices[$field->getField()->getId()] = $field->getField()->getLabel();
            
        }
        
        return $JiraFieldChoices;
         
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