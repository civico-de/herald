---
title: Deployment
order: 70
---

# Deployment

This page explains how to update herald on a live site without breaking anything while the code changes.

herald's CiviRules actions and conditions run inside the save of every record their rules fire on. If one of their classes cannot load, that save fails: the contribution, the registration or the event edit is lost, not only the alert. The scripts in herald's bin directory therefore switch herald's rules off while the code changes. They switch the rules back on only once every handler loads. On a site without CiviRules, each script notes that there is nothing to check and succeeds.

## Steps

A git checkout has no vendor directory. Build it with `composer install --no-dev` before you swap the code. The release archive already contains it.

Run the scripts with `cv scr` before and after the code swap, and stop at the first step that fails:

1. Before the swap: rules-disable.php, then `cv flush`.
2. Swap the extension code.
3. Run pending extension upgrades with `cv ext:upgrade-db`.
4. preflight-rules.php.
5. Only after the preflight passed: rules-reenable.php.
6. Last: note-foreign-rules.php.

```bash
HERALD=/var/www/example.org/ext/herald
cv scr $HERALD/bin/rules-disable.php && cv flush
# … swap the extension code …
cv ext:upgrade-db \
  && cv scr $HERALD/bin/preflight-rules.php \
  && cv scr $HERALD/bin/rules-reenable.php \
  && cv scr $HERALD/bin/note-foreign-rules.php
```

rules-reenable.php does not check whether the preflight passed, so never run it after a failed preflight. The upgrades come before the preflight because an upgrade can rename handler classes that the preflight then loads.

The upgrade to named channels moves the former channel settings, such as the Telegram bot token, into channels named after their type: `webhook`, `ntfy`, `email` and `activity`, plus `telegram` and `pushover` where their token was set. It points every rule action, scheduled job, topic and waiting alert that relied on a setting at those channels. Token and headers that a rule action, job, topic or waiting alert carried become a webhook channel of their own, named `webhook_` and a suffix; deliveries with equal ones share it. Other channel settings in a delivery, such as `email_from`, were never read from there and are dropped. Check Administer » Herald » Channels afterwards. A value set in `civicrm.settings.php` beats the stored one and goes into the channel too; remove those lines afterwards, since herald no longer reads them. On a site with several domains, each domain gets channels from its own settings, and the webhook channels of rule actions and waiting alerts exist in every domain that had a URL to send to: the delivery's own URL or else that domain's default URL. A job's channel exists only in the job's domain. Webhook headers that are no JSON object failed every send before; their channel is created without them and switched off, and the CiviCRM log names it. Set the headers in the channel form and switch it on.

The upgrade to subscribable topics turns every subscription type into a topic of the same name that contacts can subscribe to, in every domain, with the type's label. That covers your own types, every type a contact subscribed to and every type a delivery names; a type herald shipped that nobody subscribed to and nothing names is dropped. A type you had deactivated becomes an offered topic too, so that deliveries to its subscribers keep working; untick "Contacts can subscribe to this topic" if contacts should no longer see it. A topic of that name that already exists keeps its settings and becomes subscribable. A `subscription_type` on a delivery that was not in subscriber mode was never read and is dropped. Every rule action, scheduled job, topic and waiting alert that named a subscription type names the topic in `subscribers_of` instead; a topic that delivers to its own subscribers needs no key. The `subscription_type` of `default` becomes its `subscribers_of`, which topics in subscriber mode keep inheriting. An offered topic reaches its own subscribers, so one that reached the subscribers named by `default` names them in its own entry. The stored subscriptions stay as they are. The CiviCRM log counts the topics created and the deliveries rewritten. Check Administer » Herald » Topics afterwards: a new topic has no entry of its own and uses the default topic.

The upgrade to several subscriptions per contact turns each contact's notification preferences into the new fields. The chosen channel, with the recipient from the field of that channel's type, becomes the contact's "Messages to groups", so group deliveries keep reaching the contact the same way. With subscriptions, it also becomes one row of "Notification subscriptions", with the subscriptions as its topics and the scope. A contact with subscriptions but no channel received nothing before and gets neither. Recipients in fields of other channel types were never read and are not moved; the CiviCRM log counts them per field, without values. On a site with several domains where one channel name has different types, the type of the lowest domain decides which field is moved. The old field group "Notification preferences" and its table go afterwards. The profile "My notification preferences" goes too, with any change an admin made to it: the form "My notifications" at `/civicrm/herald/my-notifications` replaces it, so update links that staff use. The contact summary no longer shows "Messages to groups"; both parts are on the tab "Notifications". Until the upgrade has run, deliveries read the old preferences, so no alert goes missing between the code update and the upgrade.

On the day of the upgrade, a daily digest that already went out can go out a second time, and alerts buffered before the upgrade arrive in a message apart from those buffered after it.

## What each step does

### Switching the rules off

rules-disable.php finds every active rule with a herald trigger, condition or action. It writes their IDs to the file herald_deploy_disabled_rules in CiviCRM's private directory and then switches those rules off. IDs left in the file by an earlier failed deploy are kept. It prints:

```
[herald deploy] disabled 4 herald rule(s) for the swap: 3,7,8,12 (marker now holds 4)
```

### Checking the handlers

preflight-rules.php loads the handler classes the way CiviRules does. It checks that each class exists, can be created without arguments and extends the right CiviRules base class. A trigger without a class of its own must be a post trigger.

It checks herald's own handlers and every handler attached to the rules that were switched off: their trigger and all their active conditions and actions, including those of other extensions. Rules whose trigger is missing or inactive are only noted, because CiviRules never fires them. Without the file from the first step, the preflight checks only herald's own handlers.

On success it prints "[herald preflight] OK: N CiviRules handler(s) checked". On failure it prints "[herald preflight] FAILED:" and exits with 1. The message names the rule, the kind of handler, the class and the reason, followed by the command that switches the rules back on after the fix. The rules stay off until then. The reasons are:

- class not found (autoload failed)
- class failed to load, with the PHP error
- not instantiable
- does not extend the expected CiviRules class
- constructor requires arguments
- constructor threw an exception
- trigger names no class and is not a post trigger

### Switching the rules back on

rules-reenable.php switches the recorded rules back on, removes the file and prints "[herald deploy] re-enabled N herald rule(s)".

### Checking other rules

note-foreign-rules.php runs once herald's rules are back on. It is the only step that loads the classes of rules without a herald handler, and it reports broken ones on stderr without failing. A handler from another extension that is attached to one of herald's rules is not left to this step: the preflight checks it, and if it is broken, the rules stay off. A class that crashes PHP when it loads, such as one with an unimplemented abstract method, does fail this last step and marks the deploy as failed. By then herald's rules are already back on, and this failure does not switch them off again.

## When something goes wrong

- A herald class that cannot load at all crashes PHP in the preflight, if not earlier. The preflight then exits with an error, and herald's rules stay off. With a cold class cache, an earlier step that starts CiviCRM can hit the crash first. `cv flush` shows the class and the file.
- While the file from the first step exists, CiviCRM's system status shows the warning "Herald: rules disabled by a deploy". It lists the rule IDs and the command that switches them back on.
- The next successful deploy switches the rules from a failed deploy back on. That includes a rule an admin switched off on purpose in between.
- A release that retires a handler moves the rules over to its replacement, but not CiviRules' queue of delayed actions. An action queued before the deploy that names a retired handler fails when it runs, and CiviRules logs it. Deploy when no delayed herald action is waiting.
