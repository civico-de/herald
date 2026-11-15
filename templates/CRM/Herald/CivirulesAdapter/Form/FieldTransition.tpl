{* herald: Field transition condition form *}
{crmScope extensionKey='herald'}
<h3>{ts}herald: Field transition{/ts}</h3>

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
  <datalist id="herald-transition-fields"></datalist>
</div>
{literal}
<script type="text/javascript">
  CRM.$(function ($) {
    // Offers the fields of the chosen entity; the field input stays free for joins and option suffixes.
    function load() {
      var list = $('#herald-transition-fields').empty();
      var entity = $('#entity').val();
      if (!entity) {
        return;
      }
      CRM.api4(entity, 'getFields', {select: ['name', 'title'], where: [['type', '!=', 'Filter']], orderBy: {title: 'ASC'}})
        .then(function (fields) {
          // An answer for an entity chosen earlier arrives too late to count.
          if ($('#entity').val() !== entity) {
            return;
          }
          list.empty();
          fields.forEach(function (field) {
            list.append($('<option>').attr('value', field.name).text(field.title));
          });
        });
    }
    $('#entity').on('change', load);
    load();
  });
</script>
{/literal}

<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
{/crmScope}
