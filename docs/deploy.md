# Deploying herald

herald's CiviRules actions and conditions run in the write path of every entity their
rules fire on. A handler class that cannot load breaks that write: the contribution, the
registration or the event edit fails, not only the alert. The scripts in `bin/` keep
herald's rules switched off while the code changes and switch them back on only once
every handler loads.

## Steps

A git checkout has no `vendor/`. Build it with `composer install --no-dev` before the swap;
the release archive already carries it.

Wrap the code swap in these steps, the scripts run with `cv scr`, and stop at the first
non-zero exit:

| When | Step |
|---|---|
| Before the swap | `bin/rules-disable.php`, then `cv flush` |
| After the swap | Pending extension upgrades (`cv ext:upgrade-db`) |
| After the upgrades | `bin/preflight-rules.php` |
| After the preflight passed | `bin/rules-reenable.php` |
| Last | `bin/note-foreign-rules.php` |

```bash
cv scr /var/www/html/ext/herald/bin/rules-disable.php && cv flush
# … swap the extension code …
cv ext:upgrade-db \
  && cv scr /var/www/html/ext/herald/bin/preflight-rules.php \
  && cv scr /var/www/html/ext/herald/bin/rules-reenable.php \
  && cv scr /var/www/html/ext/herald/bin/note-foreign-rules.php
```

`rules-reenable.php` does not check whether the preflight passed, so never run it after a
failed preflight. The upgrades come before the preflight because an upgrade can rename
handler classes that the preflight resolves.

## What each step does

**`rules-disable.php`** writes the ids of every active rule with a herald condition or
action to `herald_deploy_disabled_rules` in CiviCRM's private directory, then switches
those rules off. Ids left in the file by an earlier failed deploy are kept.

**`preflight-rules.php`** resolves handler classes the way CiviRules does: the class
exists, `new $class()` works without arguments, it has the right base class, and the
post-trigger default applies only to an object/op trigger without cron. It exits with 1
for a broken handler among herald's registered handlers or among the handlers attached
to the disabled rules whose trigger is active: their trigger and every active condition
and action link, including those of other extensions. The message names rule, kind,
class and reason, plus the command that re-enables herald's rules after the fix; the
rules stay off until then. Disabled rules whose trigger
is missing or inactive are only noted on stderr: CiviRules never fires them, so their
handlers are not checked. Without a marker file the preflight checks only the registered
handlers.

**`rules-reenable.php`** switches the recorded rules back on and removes the file.

**`note-foreign-rules.php`** runs once herald's rules are back on and notes, on stderr,
broken handlers of the other active rules. It is the only step that loads the classes of
rules without herald handlers, so such a class that is a PHP fatal on load (an
unimplemented abstract method, an incompatible signature) fails this last step and marks
the deploy failed, but cannot keep herald's alerts off. A foreign handler attached to one
of herald's rules is checked by the preflight and blocks re-enabling.

## Failure cases

- A herald handler class that cannot load at all is a PHP fatal at the latest in the
  preflight, which then exits non-zero, so herald's rules stay off. With a cold class
  cache, an earlier step that boots CiviCRM can hit the fatal first. `cv flush` names
  class and file.
- While the marker file exists, CiviCRM's system status shows a warning naming the
  disabled rules and the command that re-enables them.
- A marker left by a failed deploy re-enables its rules on the next successful one,
  including a rule an admin switched off on purpose in between.
- A release that retires a handler migrates the rules, but not CiviRules' queue of
  delayed actions: an action queued before the deploy that names a retired handler is
  logged as failed when it runs. Deploy when no delayed herald action is pending.
