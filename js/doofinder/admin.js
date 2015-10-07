(function() {
    'use strict';

    document.observe("dom:loaded", function() {
        try {
            var $td = $('row_doofinder_cron_schedule_settings_time').select('td.value')[0];
            $td.innerHTML = $td.innerHTML.replace(/&nbsp;:&nbsp;/g, '<span class="df-separator"></span>');
            $td.select('.df-separator:last')[0].hide();
            $td.select('select:last')[0].hide();
        } catch (e) {}

        if ($('doofinder_cron_feed_settings')) {
            var changed = false;
            new Form.Observer('config_edit_form', 0.3, function(form, value) {
                if (changed) return;
                $('messages').insert('<p class="notice-msg doofinder-alert">Configuration has changed. Feed will be regenerated after the save.</p>');
                form.insert('<input type="hidden" name="reset" value="1"/>');
                changed = true;
            });
        }
    });

})();
