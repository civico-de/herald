{* herald: shows the send action fields that apply to the chosen way of sending *}
{literal}
<script type="text/javascript">
  CRM.$(function ($) {
    var vars = CRM.vars.heraldSendForm;
    var own = ['delivery_mode', 'subscribers_of', 'group_id', 'email_addresses', 'respect_suspension',
      'email_location_type', 'scope_entity', 'channel', 'aggregate_minutes'];
    // The delivery modes a field is used in; the others apply in every mode.
    var modes = {
      subscribers_of: ['subscribers'],
      scope_entity: ['subscribers'],
      group_id: ['group'],
      email_addresses: ['addresses'],
      respect_suspension: ['subscribers', 'group'],
      email_location_type: ['subscribers', 'group'],
      channel: ['direct', 'addresses']
    };
    function row(name) {
      return $('.crm-herald-form-block-' + name);
    }
    function refresh() {
      var viaTopic = $('#send_via').val() === 'topic';
      var mode = $('#delivery_mode').val();
      var fanOut = ['subscribers', 'group'].indexOf(mode) !== -1;
      var type = vars.channelTypes[$('#channel').val()] || '';
      row('topic').toggle(viaTopic);
      own.forEach(function (name) {
        row(name).toggle(!viaTopic && (!modes[name] || modes[name].indexOf(mode) !== -1));
      });
      // A topic's route decides its mode, so a scope stays offered there.
      if (viaTopic) {
        row('scope_entity').show();
      }
      var shownTypes = {};
      $.each(vars.fields, function (name, field) {
        // A fan-out reaches each contact on their own channel: every type's presentation keys apply.
        var applies = !viaTopic && (fanOut ? !field.recipient : field.types.indexOf(type) !== -1);
        var group = row(name).data('heraldType');
        // A direct delivery shows its one type open, since its recipient is among the fields.
        row(name).toggle(applies && (!fanOut || !!expanded[group]));
        if (applies) {
          shownTypes[group] = true;
        }
      });
      $('.crm-herald-type-heading').each(function () {
        var group = $(this).data('heraldType');
        var open = !fanOut || !!expanded[group];
        $(this).toggle(!!shownTypes[group]);
        $(this).find('button').prop('disabled', !fanOut).attr('aria-expanded', open ? 'true' : 'false');
        $(this).find('.crm-i').toggleClass('fa-chevron-down', open).toggleClass('fa-chevron-right', !open);
      });
    }
    // A type starts open when one of its fields holds a value.
    var expanded = {};
    $.each(vars.fields, function (name) {
      var value = $('#' + name).val();
      if (value !== undefined && value !== null && value !== '') {
        expanded[row(name).data('heraldType')] = true;
      }
    });
    $('.crm-herald-type-toggle').on('click', function () {
      var group = $(this).closest('tr').data('heraldType');
      expanded[group] = !expanded[group];
      refresh();
    });
    $('#send_via, #channel, #delivery_mode').on('change', refresh);
    refresh();
  });
</script>
{/literal}
