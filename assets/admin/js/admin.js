/**
 * WPCalibrate Searchable PDF Library - Admin Scripts
 */
(function ($) {
    'use strict';

    $(function () {
        // 1. Media Library PDF Selector
        var mediaFrame;
        var $selectBtn    = $('#wpcal-open-media-modal');
        var $removeBtn    = $('#wpcal-remove-attachment');
        var $attIdInput   = $('#wpcal_attachment_id');
        var $previewBox   = $('#wpcal-attachment-preview');
        var $selectBox    = $('#wpcal-select-controls');
        var $previewName  = $('#wpcal-preview-name');
        var $previewSize  = $('#wpcal-preview-size');

        if ($selectBtn.length) {
            $selectBtn.on('click', function (e) {
                e.preventDefault();

                if (mediaFrame) {
                    mediaFrame.open();
                    return;
                }

                mediaFrame = wp.media({
                    title: wpcalSplAdmin.i18n.selectPdf,
                    button: { text: wpcalSplAdmin.i18n.choosePdf },
                    multiple: false,
                    library: { type: 'application/pdf' }
                });

                mediaFrame.on('select', function () {
                    var attachment = mediaFrame.state().get('selection').first().toJSON();
                    if (attachment && attachment.id) {
                        $attIdInput.val(attachment.id);
                        $previewName.text(attachment.filename || attachment.title);
                        if (attachment.filesizeHumanReadable) {
                            $previewSize.text(attachment.filesizeHumanReadable);
                        } else {
                            $previewSize.text('');
                        }
                        $selectBox.hide();
                        $previewBox.show();

                        // Auto-fill title if empty
                        var $titleInput = $('#title');
                        if ($titleInput.length && !$titleInput.val()) {
                            $titleInput.val(attachment.title || attachment.filename);
                        }
                    }
                });

                mediaFrame.open();
            });

            if ($removeBtn.length) {
                $removeBtn.on('click', function (e) {
                    e.preventDefault();
                    $attIdInput.val('');
                    $previewBox.hide();
                    $selectBox.show();
                });
            }
        }

        // 2. Custom Field Type switch
        var $fieldTypeSelect = $('#field_type');
        var $optionsRow      = $('#options-row');
        if ($fieldTypeSelect.length) {
            $fieldTypeSelect.on('change', function () {
                if ($(this).val() === 'select') {
                    $optionsRow.slideDown(150);
                } else {
                    $optionsRow.slideUp(150);
                }
            }).trigger('change');
        }

        // 3. Access Mode radio change
        var $accessRadios = $('input[name="wpcal_access_mode"]');
        var $rolesList    = $('#wpcal-roles-list');
        if ($accessRadios.length && $rolesList.length) {
            $accessRadios.on('change', function () {
                if ($(this).val() === 'roles') {
                    $rolesList.slideDown(150);
                } else {
                    $rolesList.slideUp(150);
                }
            });
        }

        // 4. AJAX Reindex Single Document
        var $reindexBtn     = $('#wpcal-reindex-btn');
        var $reindexSpinner = $('#wpcal-reindex-spinner');

        if ($reindexBtn.length) {
            $reindexBtn.on('click', function (e) {
                e.preventDefault();
                var docId = $(this).data('doc-id');
                if (!docId) return;

                $reindexSpinner.addClass('is-active');
                $reindexBtn.prop('disabled', true);

                $.post(wpcalSplAdmin.ajaxUrl, {
                    action: 'wpcal_spl_reindex_single',
                    nonce: wpcalSplAdmin.nonce,
                    doc_id: docId
                })
                .done(function (res) {
                    if (res.success) {
                        alert(res.data.message);
                        window.location.reload();
                    } else {
                        alert(res.data ? res.data.message : 'Error reindexing');
                    }
                })
                .fail(function () {
                    alert('Network error while requesting re-indexing.');
                })
                .always(function () {
                    $reindexSpinner.removeClass('is-active');
                    $reindexBtn.prop('disabled', false);
                });
            });
        }

        // Reindex triggers in list table
        $('.wpcal-reindex-trigger').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var docId = $btn.data('doc-id');
            if (!docId) return;

            $btn.prop('disabled', true).text('Queueing...');
            $.post(wpcalSplAdmin.ajaxUrl, {
                action: 'wpcal_spl_reindex_single',
                nonce: wpcalSplAdmin.nonce,
                doc_id: docId
            })
            .done(function (res) {
                if (res.success) {
                    $btn.text('Queued!');
                } else {
                    alert(res.data ? res.data.message : 'Error');
                    $btn.prop('disabled', false).text('Re-index');
                }
            })
            .fail(function () {
                alert('Request failed');
                $btn.prop('disabled', false).text('Re-index');
            });
        });
    });
})(jQuery);
