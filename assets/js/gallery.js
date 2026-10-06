(function ($) {
    'use strict';

    $(function () {
        var $list = $('#scem_gallery_list');
        var $add = $('#scem_gallery_add');
        var $count = $('#scem_gallery_count');
        var config = window.scemGallery || {};
        var max = parseInt(config.max, 10) || 20;
        var frame = null;

        if (!$list.length || !$add.length) {
            return;
        }

        function ids() {
            return $list.children().map(function () {
                return String($(this).data('id'));
            }).get();
        }

        function refresh() {
            var total = ids().length;

            $count.text(total + ' / ' + max);
            $add.prop('disabled', total >= max);
        }

        function addItem(attachment) {
            if (ids().length >= max || ids().indexOf(String(attachment.id)) !== -1) {
                return;
            }

            var sizes = attachment.sizes || {};
            var thumb = (sizes.thumbnail || sizes.medium || sizes.full || {}).url || attachment.url;
            var $item = $('<li class="scem-gallery-item"></li>').attr('data-id', attachment.id).data('id', attachment.id);

            $('<img>').attr({ src: thumb, alt: '' }).appendTo($item);
            $('<input type="hidden" name="scem[gallery][]">').val(attachment.id).appendTo($item);
            $('<button type="button" class="scem-gallery-remove" aria-label="Remove image">&times;</button>').appendTo($item);
            $list.append($item);
        }

        $list.sortable({ items: '> li', tolerance: 'pointer', cursor: 'move' });

        $list.on('click', '.scem-gallery-remove', function () {
            $(this).closest('li').remove();
            refresh();
        });

        $add.on('click', function () {
            if (!frame) {
                frame = wp.media({
                    title: config.title || 'Choose images',
                    button: { text: config.button || 'Add' },
                    library: { type: 'image' },
                    multiple: 'add'
                });

                frame.on('select', function () {
                    frame.state().get('selection').each(function (attachment) {
                        addItem(attachment.toJSON());
                    });

                    refresh();
                });
            }

            frame.open();
        });

        refresh();
    });
}(jQuery));
