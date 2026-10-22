{* herald: the fields of a send action form, the channel-type options under a heading per type *}
<div class="crm-block crm-form-block">
  <table class="form-layout">
    {assign var=heraldGroup value=''}
    {foreach from=$elementNames item=field}
      {if $form.$field.label}
        {if isset($heraldFieldTypes.$field)}
          {assign var=heraldType value=$heraldFieldTypes.$field}
        {else}
          {assign var=heraldType value=''}
        {/if}
        {if $heraldType && $heraldType != $heraldGroup}
          <tr class="crm-herald-type-heading" data-herald-type="{$heraldType}">
            <td colspan="2">
              <h4>
                <button type="button" class="crm-hover-button crm-herald-type-toggle" aria-expanded="true">
                  <i class="crm-i fa-chevron-down" aria-hidden="true"></i>
                  {$heraldTypeLabels.$heraldType|escape}
                </button>
              </h4>
            </td>
          </tr>
        {/if}
        {assign var=heraldGroup value=$heraldType}
        <tr class="crm-herald-form-block-{$field}"{if $heraldType} data-herald-type="{$heraldType}"{/if}>
          <td class="label">{$form.$field.label}</td>
          <td>
            {$form.$field.html}
            {if isset($heraldDescriptions.$field)}
              <div class="description">{$heraldDescriptions.$field|escape}</div>
            {/if}
          </td>
        </tr>
      {/if}
    {/foreach}
  </table>
</div>

{include file="CRM/Herald/CivirulesAdapter/Form/SendFields.js.tpl"}
