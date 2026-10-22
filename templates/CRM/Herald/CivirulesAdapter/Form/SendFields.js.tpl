{* herald: shows the send action fields that apply to the chosen way of sending *}
{literal}
<script type="text/javascript">
  CRM.$(function ($) {
    var vars = CRM.vars.heraldSendForm;
    var own = ['delivery_mode', 'subscribers_of', 'group_id', 'email_addresses', 'respect_suspension',
      'email_location_type', 'channel', 'aggregate_minutes'];
    function row(name) {
      return $('.crm-herald-form-block-' + name);
    }
    function refresh() {
      var viaTopic = $('#send_via').val() === 'topic';
      var fanOut = ['subscribers', 'group'].indexOf($('#delivery_mode').val()) !== -1;
      var type = vars.channelTypes[$('#channel').val()] || '';
      row('topic').toggle(viaTopic);
      own.forEach(function (name) {
        row(name).toggle(!viaTopic);
      });
      row('subscribers_of').toggle(!viaTopic && $('#delivery_mode').val() === 'subscribers');
      $.each(vars.fields, function (name, field) {
        // A fan-out reaches each contact on their own channel: every type's presentation keys apply.
        var applies = fanOut ? !field.recipient : field.types.indexOf(type) !== -1;
        row(name).toggle(!viaTopic && applies);
      });
    }
    $('#send_via, #channel, #delivery_mode').on('change', refresh);
    refresh();
  });
</script>
{/literal}
