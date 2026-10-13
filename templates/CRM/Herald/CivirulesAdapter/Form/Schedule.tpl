{* herald: Schedule trigger form *}
{crmScope extensionKey='herald'}
<h3>{$ruleTriggerHeader}</h3>
{if $ruleTriggerHelp}
  <div class="help">{$ruleTriggerHelp}</div>
{/if}

<div class="crm-block crm-form-block">
  <table class="form-layout">
    <tr class="crm-herald-form-block-weekdays">
      <td class="label">{$form.weekdays.label}</td>
      <td>{$form.weekdays.html}<div class="description">{ts}Leave empty to send every day.{/ts}</div></td>
    </tr>
    <tr class="crm-herald-form-block-hour">
      <td class="label">{$form.hour.label}</td>
      <td>{$form.hour.html}<div class="description">{ts}In CiviCRM's timezone.{/ts}</div></td>
    </tr>
  </table>
</div>

<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
{/crmScope}
