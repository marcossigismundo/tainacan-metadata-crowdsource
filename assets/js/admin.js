(function ($) {
	'use strict';

	function t(key, fallback) {
		var i18n = (window.tmcAdmin && window.tmcAdmin.i18n) || {};
		return i18n[key] || fallback;
	}

	// Serializa o valor editável (multivalorado => juntado por "||").
	function serializeValue(el) {
		if (el.getAttribute('data-multiple') === '1') {
			return el.value
				.split(/\r?\n/)
				.map(function (s) { return s.trim(); })
				.filter(function (s) { return s.length > 0; })
				.join('||');
		}
		return el.value.trim();
	}

	$(document).ready(function () {
		$(document).on('click', '.tmc-approve', function () {
			handleAction($(this).closest('.tmc-row'), 'approve');
		});

		$(document).on('click', '.tmc-reject', function () {
			var notes = window.prompt(t('rejectPrompt', 'Motivo da rejeição (opcional):')) || '';
			handleAction($(this).closest('.tmc-row'), 'reject', notes);
		});

		$(document).on('click', '.tmc-thank', function () {
			handleThank($(this).closest('.tmc-submission'), $(this));
		});

		$(document).on('click', '.tmc-diff-toggle', function (e) {
			e.preventDefault();
			var $diff = $(this).nextAll('.tmc-diff').first();
			$diff.prop('hidden', !$diff.prop('hidden'));
		});
	});

	function handleAction($row, action, notes) {
		var id = $row.data('suggestion-id');
		var url = window.tmcAdmin.restUrl + 'suggestions/' + id + '/' + action;

		var data = {};
		if (notes) {
			data.notes = notes;
		}

		// Ao aprovar, envia o valor curado pelo gestor só se ele de fato editou.
		if (action === 'approve') {
			var ta = $row.find('.tmc-edit-value').get(0);
			if (ta) {
				var serialized = serializeValue(ta);
				var original = ta.getAttribute('data-original') || '';
				if (serialized !== original) {
					data.final_value = serialized;
				}
			}
		}

		$row.find('.tmc-actions button').prop('disabled', true).text(t('processing', 'Processando…'));

		$.ajax({
			url: url,
			method: 'POST',
			headers: { 'X-WP-Nonce': window.tmcAdmin.nonce },
			data: data
		}).done(function () {
			$row.fadeOut(200, function () { $(this).remove(); });
		}).fail(function (xhr) {
			var msg = (xhr.responseJSON && xhr.responseJSON.error) || t('unknownError', 'Erro desconhecido');
			window.alert(t('failPrefix', 'Falha:') + ' ' + msg);
			$row.find('.tmc-actions button')
				.prop('disabled', false)
				.each(function (i, btn) {
					$(btn).text($(btn).hasClass('tmc-approve') ? t('approve', 'Aprovar') : t('reject', 'Rejeitar'));
				});
		});
	}

	function handleThank($submission, $btn) {
		var sid = $submission.data('submission-id');
		if (!sid) {
			return;
		}

		var message = window.prompt(t('thankPrompt', 'Mensagem de agradecimento (deixe em branco para a mensagem padrão):'));
		if (message === null) {
			return; // Cancelado.
		}

		var url = window.tmcAdmin.restUrl + 'submissions/' + encodeURIComponent(sid) + '/thank';
		var original = $btn.text();
		$btn.prop('disabled', true).text(t('thanking', 'Enviando…'));

		$.ajax({
			url: url,
			method: 'POST',
			headers: { 'X-WP-Nonce': window.tmcAdmin.nonce },
			data: { message: message }
		}).done(function () {
			$btn.replaceWith('<span class="tmc-thanked">' + t('thanked', '✓ Agradecido') + '</span>');
		}).fail(function (xhr) {
			var msg = (xhr.responseJSON && xhr.responseJSON.error) || t('unknownError', 'Erro desconhecido');
			window.alert(t('failPrefix', 'Falha:') + ' ' + msg);
			$btn.prop('disabled', false).text(original);
		});
	}
})(jQuery);
