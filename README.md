# OSTJiraSync

## Install

1. cd to osTicket Plugin Directory
2. git clone https://github.com/2600hz/OSTJiraSync.git jira-sync
3. cd jira-sync
4. git clone https://github.com/lesstif/php-jira-rest-client.git

## JSON Response String
In the configuration page, most everything is pretty self-explanitory, to me as least :). But, you will see a field for JSON Response String and this needs quite a bit of explanation. This is where the vast majority of the functionality of this plugin comes into play. The JSON Response String is a JSON array of responses to give when a status changes given and old and new status. This array will be iterated over until a match is found and executed. Each array element must have fields for "new", "old" and "message". "new" and "old" related to the matching previous an newly updated JIRA status and can be either null, "any" or the status you want to match. "message" is the message to send in the ticket. A simple example would be this:

``` json
[
  {
    "old": null,
    "new": "any",
    "continue": true,
    "message": "Your support agent has subsribed you to the status of a code fix. You will recieve automated messages as it progresses."
  },
  {
    "old": "any",
    "new": "Open",
    "message": "Your cod fix is currently in OPEN status."
  },
  {
    "old": "any",
    "new": "Closed",
    "message": "Your cod fix is currently in CLOSED status."
  }
]
```
I've cheated a bit here and used the optional "continue". More on that in a moment. But, basically this would tell your client when they have been subscribed to a new JIRA ticket. Then, give them a status update when it's opened and closed.

While this is pretty cool, it's also pretty limited. Here's some optional fields you can use to up your game a bit more...

* continue: Bool (true/false) Default false. Continues processing additional array elements
* private: Bool (true/false) Default false. Makes "message" a internal note, not sent or visable to end users
* webhook: string (URI) Hits a webhook URI. Useful for futher automation
* jiraComment: Makes a comment onto the JIRA ticket

Lets explore how powerful this can get with this example

``` json
[
  {
    "old": null,
    "new": "any",
    "continue": true,
    "private": true,
    "jiraComment": "osTicket #%ost-number% has been associated with this JIRA ticket. https://ost_install/scp/tickets.php?id=%ost-id%",
    "message": "Subscribed successfully to %jira-ticket% updates. Direct link to JIRA ticket: %jira-hostname%/browse/%jira-ticket%"
  },
  {
    "old": null,
    "new": "any",
    "message": "Hello! Your support representative has subscribed you to updates from a JIRA ticket. Look out for updates!"
  },
  {
    "old": "any",
    "new": "open",
    "continue": true,
    "private": true,
    "webhook": "https://somedomain.invalid/work_me_automation",
    "message": "A dev is being paged to work this!"
  },
  {
    "old": "any",
    "new": "open",
    "message": "Your JIRA ticket is currently in OPEN status. Meaning we are going to work it, but no one has started working it quite yet."
  },
  {
    "old": "any",
    "new": "in progress",
    "message": "Your JIRA ticket is in progress! Someone is working on the code now!"
  },
  {
    "old": "any",
    "new": "resolved",
    "message": "The code is complete, just need to test!"
  },
  {
    "old": "resolved",
    "new": "reopened",
    "webhook": "https://somedomain.invalid/rework_me_automation",
    "message": "Your code was believed to be complete, but failed testing. Sorry, we will update you asap."
  },
  {
    "old": "any",
    "new": "reopened",
    "webhook": "https://somedomain.invalid/rework_me_automation",
    "message": "Your JIRA ticket is currently in OPEN status. Meaning we are going to work it, but no one has started working it quite yet."
  },
  
  {
    "old": "any",
    "new": "any",
    "private": true,
    "message": "Hm, dont know how to handle going from %jira-old-status% status to %jira-old-status% status"
  },
]
```

This would make a private comment on osTicket and a comment on the JIRA ticket whenever a new association is made. Then, it would make an internal note that the automation was triggered. Next, if the ticket is open, it'd hit some URI that prompts your devs to start work on it and privatly note about that. The next two array elements update the client about open, in progress and resolved status tickets regardless of previous state. But a little bit of magic happens after that. Lets say, you don't really want to admit to any new subscribers that a ticket had to be reopened because it failed testing. But, you kind of have to for people that have been subscribed for a while. The next two array elements do exactly this! And, finally, the last element makes a private note any time sync got all the way to the bottom of the array without matching at all.

## Variables
You may have noticed in my last example I cheated again. I used varibles without tell you about them. So, here we are, last bit of documentation. These are the supported varibles for this plugin:

* %ost-number% : The osTicket number for the current ticket
* %ost-id% : The osTicket ID for the current ticket
* %jira-hostname%% : The hostname for your jira instance
* %jira-ticket% : The current JIRA ticket ID/number
* %jira-old-status% : The JIRA ticket status prior to update
* %jira-new-status% : The newly updated JIRA status

Please note: The stanadard osticket varible system does NOT work. Appologies, we just didn't need it. Maybe someone will come along and add it?

Well, that's it. I hope you enjoy this tool!
