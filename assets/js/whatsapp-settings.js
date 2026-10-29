/* Dish Dash — WhatsApp Settings: test-send button */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('dd-wa-test-send');
        if (!btn) return;
        btn.addEventListener('click', sendTest);
    });

    function sendTest() {
        var btn      = document.getElementById('dd-wa-test-send');
        var spinner  = document.getElementById('dd-wa-test-spinner');
        var resultEl = document.getElementById('dd-wa-test-result');
        var to       = document.getElementById('dd-wa-test-to').value.trim();
        var template = document.getElementById('dd-wa-test-template').value.trim();
        var lang     = document.getElementById('dd-wa-test-lang').value.trim();

        resultEl.textContent = '';
        resultEl.style.color = '';

        if (!to || !template) {
            resultEl.textContent = 'Enter a recipient number and a template name.';
            resultEl.style.color = '#c0392b';
            return;
        }

        btn.disabled = true;
        spinner.style.display = 'inline-block';

        var fd = new FormData();
        fd.append('action', 'dd_whatsapp_test_send');
        fd.append('nonce', ddWhatsAppAdmin.nonce);
        fd.append('to', to);
        fd.append('template_name', template);
        fd.append('language_code', lang || 'en_US');

        fetch(ddWhatsAppAdmin.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var messageId = data.data && data.data.data ? data.data.data.message_id : '';
                    resultEl.textContent = '✅ Sent' + (messageId ? ' — message id ' + messageId : '');
                    resultEl.style.color = '#1e8e3e';
                } else {
                    resultEl.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'Send failed.');
                    resultEl.style.color = '#c0392b';
                }
            })
            .catch(function (err) {
                resultEl.textContent = '❌ Request failed: ' + err;
                resultEl.style.color = '#c0392b';
            })
            .finally(function () {
                btn.disabled = false;
                spinner.style.display = 'none';
            });
    }
})();
