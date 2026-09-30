jQuery(document).ready(function($) {
    // MÉDIATÁR FELTÖLTŐ
    $(document).on('click', '.bm-upload-btn', function(e) {
        e.preventDefault();

        var button = $(this);
        var inputId = button.data('input');
        var previewId = button.data('preview');

        var frame = wp.media({
            title: 'Ikon kiválasztása',
            button: { text: 'Ikon használata' },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();

            $('#' + inputId).val(attachment.url);
            $('#' + previewId).html('<img src="' + attachment.url + '" style="max-width:20px; max-height:20px; display:block;">');
        });

        frame.open();
    });

    // ADMIN SHORTCODE KERESŐ
    $(document).on('keyup', '#bm-shortcode-search', function() {
        var value = $(this).val().toLowerCase();

        $('#bm-shortcode-table tbody tr').each(function() {
            if ($(this).hasClass('bm-always-show')) {
                $(this).show();
                return;
            }

            var text = $(this).find('td:first').text().toLowerCase();
            $(this).toggle(text.indexOf(value) > -1);
        });
    });

    // SHORTCODE MÁSOLÁS
    $(document).on('click', '.bm-copy-btn', function(e) {
        e.preventDefault();

        var btn = $(this);
        var text = btn.attr('data-shortcode');
        var original = btn.html();

        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(text).select();
        document.execCommand('copy');
        $temp.remove();

        btn.html('Másolva!').addClass('copied');

        setTimeout(function() {
            btn.html(original).removeClass('copied');
        }, 2000);
    });

    // MANUÁLIS SVG / IKON PREVIEW FRISSÍTÉS
    $(document).on('input', '.bm-icon-manual-input', function() {
        var input = $(this);
        var preview = input.closest('.bm-unit').find('.bm-icon-preview');
        var value = input.val().trim();

        if (value !== '') {
            preview.html(value);
        }
    });

    // HA A MANUÁLIS SVG MEZŐBE ÍRNAK, TÖRÖLJÜK A FELTÖLTÖTT URL-T
    $(document).on('input', '.bm-icon-manual-input', function() {
        var input = $(this);
        var hiddenUrlInput = input.closest('.bm-unit').find('input[type="hidden"]');

        if (input.val().trim() !== '') {
            hiddenUrlInput.val('');
        }
    });
});