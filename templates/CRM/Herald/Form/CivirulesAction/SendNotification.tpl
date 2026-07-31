{* herald: SendNotification action form *}
{crmScope extensionKey='herald'}
<h3>{ts}herald: Send Notification{/ts}</h3>

<div class="crm-block crm-form-block">
  <table class="form-layout">
    {foreach from=$form.elementNames item=field}
      {if $form.$field.label}
        <tr class="crm-herald-form-block-{$field}">
          <td class="label">{$form.$field.label}</td>
          <td>{$form.$field.html}</td>
        </tr>
      {/if}
    {/foreach}
  </table>
</div>

<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
{/crmScope}
