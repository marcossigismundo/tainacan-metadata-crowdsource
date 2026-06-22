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

		var overlay = widget.querySelector('.tmc-modal-overlay');
		if (overlay) {
			setupModal(widget, form, overlay);
		} else {
			loadCaptcha(form);
		}

		// Restaurar o valor original de um campo (edição estilo wiki).
		widget.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.tmc-field-reset') : null;
			if (!btn) {
				return;
			}
			var field = btn.closest('.tmc-field');
			var ta = field && field.querySelector('.tmc-field-input');
			if (!ta) {
				return;
			}
			var original = ta.getAttribute('data-original') || '';
			ta.value = ta.getAttribute('data-multiple') === '1' ? original.split('||').join('\n') : original;
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			submitAll(widget, form);
		});
	}

	// Serializa um campo na forma canônica (multivalorado => juntado por "||").
	function serializeField(el) {
		if (el.getAttribute('data-multiple') === '1') {
			return el.value
				.split(/\r?\n/)
				.map(function (s) { return s.trim(); })
				.filter(function (s) { return s.length > 0; })
				.join('||');
		}
		return el.value.trim();
	}

	// ---- Modal -------------------------------------------------------------

	function setupModal(widget, form, overlay) {
		var openBtn = widget.querySelector('.tmc-open-modal');

		function open() {
			overlay.hidden = false;
			document.body.classList.add('tmc-modal-open');
			loadCaptcha(form);
			var first = form.querySelector('.tmc-field-input');
			if (first) {
				first.focus();
			}
		}

		function close() {
			overlay.hidden = true;
			document.body.classList.remove('tmc-modal-open');
			if (openBtn) {
				openBtn.focus();
			}
		}

		if (openBtn) {
			openBtn.addEventListener('click', open);
		}

		overlay.querySelectorAll('.tmc-modal-close').forEach(function (el) {
			el.addEventListener('click', close);
		});

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				close();
			}
		});

		document.addEventListener('keydown', function (e) {
			if ((e.key === 'Escape' || e.key === 'Esc') && !overlay.hidden) {
				close();
			}
		});
	}

	// ---- CAPTCHA -----------------------------------------------------------

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

	// ---- Submit ------------------------------------------------------------

	function submitAll(widget, form) {
		var feedback = form.querySelector('.tmc-feedback');
		var submitBtn = form.querySelector('.tmc-submit');
		var itemId = parseInt(widget.getAttribute('data-item-id'), 10);

		// Só os campos alterados em relação ao valor original (e não vazios).
		var suggestions = Array.from(form.querySelectorAll('.tmc-field-input'))
			.map(function (el) {
				var value = serializeField(el);
				return {
					metadatum_id: parseInt(el.getAttribute('data-metadatum-id'), 10),
					new_value: value,
					changed: value.length > 0 && value !== (el.getAttribute('data-original') || '')
				};
			})
			.filter(function (f) { return f.changed; })
			.map(function (f) { return { metadatum_id: f.metadatum_id, new_value: f.new_value }; });

		if (suggestions.length === 0) {
			showFeedback(feedback, t('fillOne', 'Altere ao menos um campo para enviar uma sugestão.'), 'error');
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
					loadCaptcha(form);
				} else {
					showFeedback(feedback, (res.body && res.body.error) || t('networkError', 'Erro de rede. Tente novamente.'), 'error');
					loadCaptcha(form);
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
