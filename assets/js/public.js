(function () {
	'use strict';

	function t(key, fallback) {
		var i18n = (window.tmcConfig && window.tmcConfig.i18n) || {};
		return i18n[key] || fallback;
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.tmc-widget').forEach(initWidget);
	});

	function initWidget(widget) {
		var form = widget.querySelector('.tmc-form');
		if (!form) {
			return;
		}

		loadCaptcha(form);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			submitAll(widget, form);
		});
	}

	// Busca um desafio fresco via REST — imune a page cache.
	function loadCaptcha(form) {
		var questionEl = form.querySelector('.tmc-captcha-question');
		var tokenEl = form.querySelector('.tmc-captcha-token');

		fetch(window.tmcConfig.captchaUrl, {
			headers: { 'X-WP-Nonce': window.tmcConfig.nonce }
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data && data.token && data.question) {
					if (questionEl) { questionEl.textContent = data.question; }
					if (tokenEl) { tokenEl.value = data.token; }
				} else {
					showCaptchaError(form);
				}
			})
			.catch(function () { showCaptchaError(form); });
	}

	function showCaptchaError(form) {
		var feedback = form.querySelector('.tmc-feedback');
		showFeedback(feedback, t('captchaError', 'Não foi possível carregar a verificação. Recarregue a página.'), 'error');
	}

	function submitAll(widget, form) {
		var feedback = form.querySelector('.tmc-feedback');
		var submitBtn = form.querySelector('.tmc-submit');
		var itemId = parseInt(widget.getAttribute('data-item-id'), 10);

		var suggestions = Array.from(form.querySelectorAll('.tmc-field-input'))
			.map(function (el) {
				return {
					metadatum_id: parseInt(el.getAttribute('data-metadatum-id'), 10),
					new_value: el.value.trim()
				};
			})
			.filter(function (f) { return f.new_value.length > 0; });

		if (suggestions.length === 0) {
			showFeedback(feedback, t('fillOne', 'Preencha pelo menos um campo para sugerir.'), 'error');
			return;
		}

		var answerEl = form.querySelector('.tmc-captcha-answer');
		var tokenEl = form.querySelector('.tmc-captcha-token');
		var hpEl = form.querySelector('input[name="tmc_hp"]');

		if (!answerEl || answerEl.value.trim() === '') {
			showFeedback(feedback, t('answerCaptcha', 'Responda a verificação anti-spam antes de enviar.'), 'error');
			return;
		}

		var payload = {
			item_id: itemId,
			suggestions: suggestions,
			submitter_name: getValue(form, 'submitter_name'),
			submitter_email: getValue(form, 'submitter_email'),
			reason: getValue(form, 'reason'),
			captcha_token: tokenEl ? tokenEl.value : '',
			captcha_answer: answerEl.value.trim(),
			hp: hpEl ? hpEl.value : ''
		};

		submitBtn.disabled = true;
		showFeedback(feedback, t('sending', 'Enviando…'), 'info');

		fetch(window.tmcConfig.restUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.tmcConfig.nonce
			},
			body: JSON.stringify(payload)
		})
			.then(function (r) {
				return r.json().then(function (body) { return { ok: r.ok, body: body }; });
			})
			.then(function (res) {
				submitBtn.disabled = false;
				if (res.ok) {
					showFeedback(feedback, res.body.message || t('success', 'Sugestão(ões) enviada(s) com sucesso! Obrigado pela contribuição.'), 'success');
					form.reset();
					loadCaptcha(form); // Novo desafio para outra submissão.
				} else {
					showFeedback(feedback, (res.body && res.body.error) || t('networkError', 'Erro de rede. Tente novamente.'), 'error');
					loadCaptcha(form); // Token consumido; recarrega.
				}
			})
			.catch(function () {
				submitBtn.disabled = false;
				showFeedback(feedback, t('networkError', 'Erro de rede. Tente novamente.'), 'error');
				loadCaptcha(form);
			});
	}

	function getValue(form, name) {
		var el = form.querySelector('[name="' + name + '"]');
		return el ? el.value : '';
	}

	function showFeedback(el, msg, type) {
		if (!el) {
			return;
		}
		el.textContent = msg;
		el.className = 'tmc-feedback tmc-feedback-' + (type || 'info');
	}
})();
