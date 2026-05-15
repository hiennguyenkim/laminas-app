document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || 'Ban co chac chan muon thuc hien thao tac nay khong?';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
