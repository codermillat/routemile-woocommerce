/**
 * RouteMile admin settings — media library uploader for image-picker fields.
 *
 * Localized strings are supplied via `routewAdminMediaStrings` (see
 * `class-routew-settings.php::render_image_upload_field()`).
 *
 * @package RouteMile
 * @since   1.5.0
 */
(function ($) {
    'use strict';

    $(function () {
        // Upload: open the WP media frame and write the selected URL into the
        // target input + preview element.
        $(document).on('click', '.routew-upload-image-btn', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var targetId = $btn.data('target');
            var strings = (window.routewAdminMediaStrings) || {};
            var frame = wp.media({
                title: strings.selectTitle || 'Select or Upload Logo',
                button: { text: strings.useButton || 'Use this logo' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#' + targetId).val(attachment.url);
                $('#' + targetId + '_preview').html(
                    '<img src="' + attachment.url + '" style="max-width: 200px; max-height: 80px; display: block; margin-bottom: 5px;">'
                );

                // Add a Remove button next to the Upload button if not already there.
                var $existing = $('.routew-remove-image-btn[data-target="' + targetId + '"]');
                if (!$existing.length) {
                    var $remove = $('<button type="button" class="button routew-remove-image-btn" data-target="' + targetId + '"></button>')
                        .text(strings.removeLabel || 'Remove Logo');
                    $btn.after($remove);
                }
            });

            frame.open();
        });

        // Remove: clear input + preview, hide Remove button.
        $(document).on('click', '.routew-remove-image-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var targetId = $btn.data('target');
            $('#' + targetId).val('');
            $('#' + targetId + '_preview').html('');
            $btn.remove();
        });
    });
})(jQuery);
