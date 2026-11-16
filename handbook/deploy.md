---
title: Deployment
order: 70
---

# Deployment

herald's CiviRules actions and conditions run inside the save of the record their rule fires on. If one of their classes cannot load, that save fails, and the contribution or registration is lost with the alert. The scripts in `bin/` therefore switch herald's rules off while the code changes and back on only once every handler loads. On a site without CiviRules they have nothing to do and succeed.

## Steps

A git checkout needs `composer install --no-dev` before the swap; the release archive already contains `vendor/`. Stop at the first step that fails:

```bash
HERALD=/var/www/example.org/ext/herald
cv scr $HERALD/bin/rules-disable.php && cv flush
# … swap the extension code …
cv ext:upgrade-db \
  && cv scr $HERALD/bin/preflight-rules.php \
  && cv scr $HERALD/bin/rules-reenable.php \
  && cv scr $HERALD/bin/note-foreign-rules.php
```

- **rules-disable.php** switches off every active rule with a herald trigger, condition or action and records the IDs in `herald_deploy_disabled_rules` in CiviCRM's private directory.
- **preflight-rules.php** loads herald's handlers and every handler of those rules, including other extensions', the way CiviRules does. On failure it names the rule, the class and the reason, and the rules stay off. It runs after the upgrades, because an upgrade can rename handler classes.
- **rules-reenable.php** switches the recorded rules back on. It does not check the preflight, so never run it after a failed one.
- **note-foreign-rules.php** reports broken handlers of other rules on stderr without failing. Only a class that crashes PHP fails it, and herald's rules stay on.

## When something goes wrong

- While rules are off, the system status shows "Herald: rules disabled by a deploy" with the rule IDs and the command that switches them back on. The next successful deploy also switches them back on, including a rule an admin switched off in between.
- A class that crashes PHP stops the deploy at the preflight or earlier; `cv flush` names the class and the file.
- A release that retires a handler moves the rules to its replacement, but not CiviRules' queue of delayed actions. Deploy when no delayed herald action is waiting.
