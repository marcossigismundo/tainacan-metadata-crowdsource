(function ($) {
	'use strict';

	function t(key, fallback) {
		var i18n = (window.tmcAdmin && window.tmcAdmin.i18n) || {};
		return i18n[key] || fallback;
	}

	$(document).ready(function () {
		$('.tmc-row').on('click', '.tmc-approve', function () {
			handleAction($(this).closest('.tmc-row'), 'approve');
		});

		$('.tmc-row').on('click', '.tmc-reject', function () {
			var notes = window.prompt(t('rejectPrompt', 'Motivo da rejeição (opcional):')) || '';
			handleAction($(this).closest('.tmc-row'), 'reject', notes);
		});
	});

	function handleAction($row, action, notes) {
		var id = $row.data('suggestion-id');
		var url = window.tmcAdmin.restUrl + 'suggestions/' + id + '/' + action;

		$row.find('.tmc-actions button').prop('disabled', true).text(t('processing', 'Processando…'));

		$.ajax({
			url: url,
			method: 'POST',
			headers: { 'X-WP-Nonce': window.tmcAdmin.nonce },
			data: notes ? { notes: notes } : {}
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
})(jQuery);
