(function($){
    $(document).ready(function(){
        var frame;
        $('#subsales_header_select').on('click', function(e){
            e.preventDefault();
            if ( frame ) {
                frame.open();
                return;
            }
            frame = wp.media({
                title: 'Select header image',
                button: { text: 'Use this image' },
                multiple: false
            });
            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                $('#subsales_header_image').val( attachment.id );
                $('#subsales_header_preview').html('<img src="'+attachment.url+'" style="max-width:200px;height:auto;border:1px solid #ddd;padding:4px;background:#fff"/>').show();
                $('#subsales_header_remove').show();
            });
            frame.open();
        });

        $('#subsales_header_remove').on('click', function(e){
            e.preventDefault();
            $('#subsales_header_image').val('');
            $('#subsales_header_preview').hide().html('');
            $(this).hide();
        });
    });
})(jQuery);
