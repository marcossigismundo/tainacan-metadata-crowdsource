(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var widgets = document.querySelectorAll('.tmc-widget');
        widgets.forEach(initWidget);
    });

    function initWidget(widget) {
        var form = widget.querySelector('.tmc-form');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitAll(widget, form);
        });
    }

    function submitAll(widget, form) {
        var feedback = form.querySelector('.tmc-feedback');
        var submitBtn = form.querySelector('.tmc-submit');
        var itemId = parseInt(widget.getAttribute('data-item-id'), 10);

        // Coleta apenas campos preenchidos (cada um vira uma sugestão separada)
        var filled = Array.from(form.querySelectorAll('.tmc-field-input'))
            .map(function (el) {
                return {
                    metadatum_id: parseInt(el.getAttribute('data-metadatum-id'), 10),
                    new_value: el.value.trim(),
                };
            })
            .filter(function (f) { return f.new_value.length > 0; });

        if (filled.length === 0) {
            showFeedback(feedback, 'Preencha pelo menos um campo para sugerir.', 'error');
            return;
        }

        // Token hCaptcha (widget oficial injeta o input hidden "h-captcha-response")
        var captchaInput = form.querySelector('textarea[name="h-captcha-response"]');
        var captchaToken = captchaInput ? captchaInput.value : '';
        if (!captchaToken) {
            showFeedback(feedback, 'Confirme o CAPTCHA antes de enviar.', 'error');
            return;
        }

        var shared = {
            item_id: itemId,
            submitter_name: (form.querySelector('[name="submitter_name"]') || {}).value || '',
            submitter_email: (form.querySelector('[name="submitter_email"]') || {}).value || '',
            reason: (form.querySelector('[name="reason"]') || {}).value || '',
            hcaptcha_token: captchaToken,
        };

        submitBtn.disabled = true;
        showFeedback(feedback, 'Enviando ' + filled.length + ' sugestão(ões)...', 'info');

        var promises = filled.map(function (f) {
            var payload = Object.assign({}, shared, {
                metadatum_id: f.metadatum_id,
                new_value: f.new_value,
            });

            return fetch(window.tmcConfig.restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.tmcConfig.nonce,
                },
                body: JSON.stringify(payload),
            }).then(function (r) {
                return r.json().then(function (body) {
                    return { ok: r.ok, body: body };
                });
            });
        });

        Promise.all(promises).then(function (results) {
            submitBtn.disabled = false;
            var ok = results.filter(function (r) { return r.ok; }).length;
            var fail = results.length - ok;

            if (fail === 0) {
                showFeedback(feedback, 'Sugestão(ões) enviada(s) com sucesso! Obrigado pela contribuição.', 'success');
                form.reset();
                if (window.hcaptcha && typeof window.hcaptcha.reset === 'function') {
                    window.hcaptcha.reset();
                }
            } else {
                var firstError = (results.find(function (r) { return !r.ok; }) || {}).body || {};
                showFeedback(
                    feedback,
                    ok + ' enviada(s), ' + fail + ' falharam. ' + (firstError.error || ''),
                    'error'
                );
            }
        }).catch(function () {
            submitBtn.disabled = false;
            showFeedback(feedback, 'Erro de rede. Tente novamente.', 'error');
        });
    }

    function showFeedback(el, msg, type) {
        if (!el) return;
        el.textContent = msg;
        el.className = 'tmc-feedback tmc-feedback-' + (type || 'info');
    }
})();
