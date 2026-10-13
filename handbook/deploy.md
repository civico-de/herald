---
title: Deployment
order: 70
---

# Deployment

This page explains how to update herald on a live site without breaking anything while the code changes.

herald's CiviRules actions and conditions run inside the save of every record their rules fire on. If one of their classes cannot load, that save fails: the contribution, the registration or the event edit is lost, not only the alert. The scripts in herald's bin directory therefore switch herald's rules off while the code changes. They switch the rules back on only once every handler loads.

## Steps

A git checkout has no vendor directory. Build it with `composer install --no-dev` before you swap the code. The release archive already contains it.

Run the scripts with `cv scr`, wrap the code swap in them, and stop at the first step that fails:

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

## What each step does

### Switching the rules off

rules-disable.php finds every active rule with a herald condition or action. It writes their IDs to the file herald_deploy_disabled_rules in CiviCRM's private directory and then switches those rules off. IDs left in the file by an earlier failed deploy are kept. It prints:

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

note-foreign-rules.php runs once herald's rules are back on. It reports broken handlers of the other active rules on stderr, without failing. It is the only step that loads the classes of rules without a herald handler. A class that crashes PHP when it loads, such as one with an unimplemented abstract method, fails this last step and marks the deploy as failed. It cannot keep herald's alerts off, because they are already back on. A handler from another extension that is attached to one of herald's rules is checked by the preflight and does block switching the rules back on.

## When something goes wrong

- A herald class that cannot load at all crashes PHP no later than in the preflight. The preflight then exits with an error, and herald's rules stay off. With a cold class cache, an earlier step that starts CiviCRM can hit the crash first. `cv flush` shows the class and the file.
- While the file from the first step exists, CiviCRM's system status shows the warning "Herald: rules disabled by a deploy". It lists the rule IDs and the command that switches them back on.
- The next successful deploy switches the rules from a failed deploy back on. That includes a rule an admin switched off on purpose in between.
- A release that retires a handler moves the rules over to its replacement, but not CiviRules' queue of delayed actions. An action queued before the deploy that names a retired handler fails when it runs, and CiviRules logs it. Deploy when no delayed herald action is waiting.
