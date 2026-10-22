{* herald: SendDigest action form *}
{crmScope extensionKey='herald'}
<h3>{ts}herald: Send Digest{/ts}</h3>
<p class="help">{ts 1=$heraldDigestsUrl}Digests and their sources are edited under <a href="%1">Administer » Herald » Digests</a>.{/ts}</p>

<div class="crm-block crm-form-block">
  <table class="form-layout">
    {foreach from=$elementNames item=field}
      {if $form.$field.label}
        <tr class="crm-herald-form-block-{$field}">
          <td class="label">{$form.$field.label}</td>
          <td>{$form.$field.html}</td>
        </tr>
      {/if}
    {/foreach}
  </table>
</div>

{include file="CRM/Herald/CivirulesAdapter/Form/SendFields.js.tpl"}

<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
{/crmScope}
