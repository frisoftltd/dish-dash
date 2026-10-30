/* Dish Dash — WhatsApp Settings: test-send + webhook-test buttons */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('dd-wa-test-send');
        if (btn) btn.addEventListener('click', sendTest);

        var webhookBtn = document.getElementById('dd-wa-webhook-test-send');
        if (webhookBtn) webhookBtn.addEventListener('click', sendWebhookTest);

        var deleteBtn = document.getElementById('dd-wa-webhook-test-delete');
        if (deleteBtn) deleteBtn.addEventListener('click', deleteTestMessages);
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

    function sendWebhookTest() {
        var btn      = document.getElementById('dd-wa-webhook-test-send');
        var spinner  = document.getElementById('dd-wa-webhook-test-spinner');
        var resultEl = document.getElementById('dd-wa-webhook-test-result');

        resultEl.textContent = '';
        resultEl.style.color = '';

        btn.disabled = true;
        spinner.style.display = 'inline-block';

        var fd = new FormData();
        fd.append('action', 'dd_whatsapp_test_webhook');
        fd.append('nonce', ddWhatsAppAdmin.webhookNonce);

        fetch(ddWhatsAppAdmin.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var d = (data.data && data.data.data) ? data.data.data : {};
                    resultEl.innerHTML =
                        '✅ HTTP ' + d.http_status + '<br>' +
                        'Conversation created_at: ' + d.conversation_created + '<br>' +
                        'Message created_at: ' + d.message_created + '<br>' +
                        'Difference: ' + d.diff_minutes + ' minute(s)';
                    resultEl.style.color = '#1e8e3e';
                } else {
                    resultEl.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'Webhook test failed.');
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

    function deleteTestMessages() {
        var btn      = document.getElementById('dd-wa-webhook-test-delete');
        var resultEl = document.getElementById('dd-wa-webhook-test-result');

        resultEl.textContent = '';
        resultEl.style.color = '';

        btn.disabled = true;

        var fd = new FormData();
        fd.append('action', 'dd_whatsapp_delete_test_messages');
        fd.append('nonce', ddWhatsAppAdmin.cleanupNonce);

        fetch(ddWhatsAppAdmin.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var d = (data.data && data.data.data) ? data.data.data : {};
                    resultEl.textContent = '🗑️ Deleted ' + d.deleted_messages + ' message(s), ' + d.deleted_conversations + ' conversation(s).';
                    resultEl.style.color = '#1e8e3e';
                } else {
                    resultEl.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'Delete failed.');
                    resultEl.style.color = '#c0392b';
                }
            })
            .catch(function (err) {
                resultEl.textContent = '❌ Request failed: ' + err;
                resultEl.style.color = '#c0392b';
            })
            .finally(function () {
                btn.disabled = false;
            });
    }
})();
