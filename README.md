# OSTJiraSync


## Reporting an issue
If you have an issue, please feel free to open an issue in this project and I'll have a look when I'm able. If you do please make sure you follow these guidelines:
1. @wildernesstechie in your issue please. 2600Hz is a telecommunications platform development company. This is just a VERY small piece in that puzzle and I'm not on the main development team. So, I don't get notifications unless you specfically @ me.
2. Please ensure that you can repilcate your issue from scratch using the offical docker image https://hub.docker.com/r/osticket/osticket
3. Please give me the exact steps you used to replicate your issue
4. Please understand that this is a very small part of my job here at 2600Hz. So it may take time for me to look at your issue and when I do if I'm not immeidatly able to replicate using your info from #2 & #3, I'll have to close the issue out until I have better information.

Thanks! I hope you find the plugin useful!

## Install

1. cd to osTicket Plugin Directory
2. git clone https://github.com/2600hz/OSTJiraSync.git jira-sync

## JSON Response String
On the configuration page, most everything is pretty self-explanatory, to me as least :). But, you will see a field for JSON Response String, and this needs quite a bit of explanation. This is where the vast majority of the functionality of this plugin comes into play. The JSON Response String is a JSON array of responses to give when a status changes given an old and new status. This array will be iterated over until a match is found and executed. Each array element must have fields for "new", "old" and "message". "new" and "old" relating to the matching previous and newly updated JIRA statuses respetively. They can be either null, "any" or the status you want to match. "message" is the message to send in the ticket. A simple example would be this:

``` json
[
  {
    "old": null,
    "new": "any",
    "continue": true,
    "message": "Your support agent has subscribed you to the status of a code fix. You will receive automated messages as it progresses."
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
I've cheated a bit here and used the optional "continue" field. More on that in a moment. Basically, this would always tell your client about the automation when they first have a JIRA ticket associated with their osTicket ticket. Then, give them a status update when it's opened and closed.

While this is pretty cool, it's also pretty limited. Here's some optional fields you can use to up your game a bit more...

* continue: Bool (true/false) Default false. Continues processing additional array elements
* private: Bool (true/false) Default false. Makes "message" a internal note, not sent or visible to end-users
* webhook: string (URI) Hits a webhook URI. Useful for further automation
* jiraComment: Makes a comment on the JIRA ticket

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

This would make a private comment on osTicket and a comment on the JIRA ticket whenever a new association is made. Then, it would make an internal note that the automation was triggered. Next, if the ticket is open, it'd hit some URI that prompts your devs to start work on it and privately note about that. The next three array elements update the client about open, in progress and resolved status tickets regardless of previous state. But a little bit of magic happens after that. Lets say, you don't really want to admit to any new subscribers that a ticket had to be reopened because it failed testing. But, you kind of have to for people that have been subscribed for a while. The next two array elements do exactly this! And, finally, the last element makes a private note any time sync got all the way to the bottom of the array without matching at all.

## Variables
You may have noticed in my last example I cheated again. I used variables without tell you about them. So, here we are, last bit of documentation. These are the supported variables for this plugin:

* %ost-number% : The osTicket number for the current ticket
* %ost-id% : The osTicket ID for the current ticket
* %jira-hostname%% : The hostname for your jira instance
* %jira-ticket% : The current JIRA ticket ID/number
* %jira-old-status% : The JIRA ticket status prior to update
* %jira-new-status% : The newly updated JIRA status

Please note, the standard osticket variable system does NOT work in this plugin. Apologies, we just didn't need it. Maybe someone will come along and add it?

Well, that's it. I hope you enjoy this tool!
