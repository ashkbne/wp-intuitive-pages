(function($){
    function toggleRow($btn){
        var expanded = $btn.attr('aria-expanded') === 'true';
        var $ul = $('#' + $btn.attr('aria-controls'));
        if (!expanded) {
            // expanding
            $btn.text('–').attr('aria-expanded','true');
            if ($ul.data('loaded') !== 1) {
                $ul.empty().append('<li class="ip-loading">'+ ip.strings.loading +'</li>');
                $.post(ip.ajaxurl, {
                    action: 'ip_get_children',
                    nonce: ip.nonce,
                    parent: $ul.data('parent'),
                    orderby: $('select[name=orderby]').val(),
                    order: $('select[name=order]').val()
                }, function(resp){
                    $ul.empty();
                    if (resp && resp.success && resp.data && resp.data.html){
                        $ul.append(resp.data.html);
                        $ul.data('loaded', 1);
                    } else {
                        $ul.append('<li class="ip-empty">'+ ip.strings.no_children +'</li>');
                    }
                });
            }
        } else {
            // collapsing
            $btn.text('+').attr('aria-expanded','false');
        }
        $ul.toggle();
    }

    $(document).on('click', '.ip-toggle', function(e){
        e.preventDefault();
        toggleRow($(this));
    });

    // Parent quick jump
    $('#ip-parent-search-btn').on('click', function(){
        var q = $('#ip-parent-search').val().trim();
        if (!q) return;
        $.post(ip.ajaxurl, {action: 'ip_find_parent', nonce: ip.nonce, q: q}, function(resp){
            if (resp && resp.success && resp.data && resp.data.results && resp.data.results.length){
                // Navigate to the first result (simple UX). Could be expanded to a dropdown list.
                window.location = resp.data.results[0].url;
            } else {
                alert(ip.strings.no_results);
            }
        });
    });

    // Enter key triggers jump
    $('#ip-parent-search').on('keypress', function(e){
        if (e.which === 13) {
            e.preventDefault();
            $('#ip-parent-search-btn').click();
        }
    });
})(jQuery);
