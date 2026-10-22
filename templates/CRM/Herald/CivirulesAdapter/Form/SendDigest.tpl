{* herald: SendDigest action form *}
{crmScope extensionKey='herald'}
<h3>{ts}herald: Send Digest{/ts}</h3>
<p class="help">{ts 1=$heraldDigestsUrl}Digests and their sources are edited under <a href="%1">Administer » Herald » Digests</a>.{/ts}</p>

{include file="CRM/Herald/CivirulesAdapter/Form/SendFields.tpl"}

<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
{/crmScope}
