(function ($) {
    'use strict';

    $(document).ready(function () {
        $('.tmc-row').on('click', '.tmc-approve', function () {
            handleAction($(this).closest('.tmc-row'), 'approve');
        });

        $('.tmc-row').on('click', '.tmc-reject', function () {
            var notes = prompt('Motivo da rejeição (opcional):') || '';
            handleAction($(this).closest('.tmc-row'), 'reject', notes);
        });
    });

    function handleAction($row, action, notes) {
        var id = $row.data('suggestion-id');
        var url = window.tmcAdmin.restUrl + 'suggestions/' + id + '/' + action;

        $row.find('.tmc-actions button').prop('disabled', true).text('Processando...');

        $.ajax({
            url: url,
            method: 'POST',
            headers: { 'X-WP-Nonce': window.tmcAdmin.nonce },
            data: notes ? { notes: notes } : {},
        }).done(function () {
            $row.fadeOut(200, function () { $(this).remove(); });
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Erro desconhecido';
            alert('Falha: ' + msg);
            $row.find('.tmc-actions button')
                .prop('disabled', false)
                .each(function (i, btn) {
                    $(btn).text($(btn).hasClass('tmc-approve') ? 'Aprovar' : 'Rejeitar');
                });
        });
    }
})(jQuery);
