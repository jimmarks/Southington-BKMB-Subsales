(function($){
  $(function(){
    var $root = $('#subsales-address-admin');
    if (!$root.length) return;

    // Nonces are printed by admin/address-management-dashboard.php so this file
    // doesn't depend on what the enqueue-time localize array happens to carry.
    var ajaxUrl = (window.SubsalesZipAdmin && SubsalesZipAdmin.ajaxUrl) || window.ajaxurl;
    var ingestNonce = $root.data('ingest-nonce');
    var reviewNonce = $root.data('review-nonce');
    var generateNonce = $root.data('generate-nonce');

    function errorOf(resp, fallback) {
      if (!resp || !resp.data) return fallback;
      if (typeof resp.data === 'string') return resp.data;
      return resp.data.message || fallback;
    }

    function num(n) { return (Number(n) || 0).toLocaleString(); }

    // =======================================
    // Ingest addresses from CT parcel data
    // =======================================

    var ingestPoll = null;

    function setIngestProgress(percent, text) {
      $('#subsales-ingest-progress-fill').css('width', percent + '%');
      $('#subsales-ingest-progress-percent').text(percent + '%');
      $('#subsales-ingest-progress-text').text(text);
    }

    function startIngestPolling() {
      if (ingestPoll) clearInterval(ingestPoll);
      ingestPoll = setInterval(function(){
        $.post(ajaxUrl, { action: 'subsales_ingest_status', nonce: ingestNonce }).done(function(resp){
          if (!resp || !resp.success || !resp.data) return;
          var s = resp.data;
          setIngestProgress(s.percent || 0, s.message || 'Working…');
        });
      }, 2000);
    }

    function stopIngestPolling() {
      if (ingestPoll) { clearInterval(ingestPoll); ingestPoll = null; }
    }

    function renderIngestSummary(data) {
      var summary = (data && data.summary) || {};
      var zips = summary.zips || {};
      var html = '<h4 style="margin:0 0 10px;">Finished</h4>';
      var names = Object.keys(zips);

      if (names.length) {
        html += '<table class="widefat striped"><thead><tr>' +
                '<th>ZIP code</th><th>Addresses now stored</th><th>Replaced</th>' +
                '</tr></thead><tbody>';
        names.forEach(function(zip){
          var z = zips[zip] || {};
          html += '<tr><td><strong>' + $('<div>').text(zip).html() + '</strong></td>' +
                  '<td>' + num(z.inserted) + '</td>' +
                  '<td>' + num(z.deleted) + '</td></tr>';
        });
        html += '</tbody></table>';
      }

      html += '<ul style="margin:12px 0 0; list-style:disc; padding-left:20px;">';
      html += '<li>' + num(summary.parcels) + ' properties read from the state records.</li>';
      html += '<li>' + num(summary.duplicates) + ' repeat listings (condos, apartments) combined into one address each.</li>';
      html += '<li>' + num(summary.queued) + ' sent to <strong>Needs Review</strong> below.</li>';
      html += '</ul>';

      if (summary.errors && summary.errors.length) {
        html += '<div style="margin-top:12px; padding:10px; background:#fcf0f1; border-left:3px solid #d63638; border-radius:3px;">' +
                '<strong>Some problems came up:</strong><ul style="margin:6px 0 0; list-style:disc; padding-left:20px;">';
        summary.errors.forEach(function(err){
          html += '<li>' + $('<div>').text(err).html() + '</li>';
        });
        html += '</ul></div>';
      }

      html += '<p class="description" style="margin-top:12px;">The seller app\'s address files were refreshed at the same time. Reload this page to see the updated totals.</p>';
      $('#subsales-ingest-results').html(html).show();
    }

    $('#subsales-ingest-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      var zips = $('#subsales_zip_list').val() || '';
      var town = $('#subsales_parcel_town').val() || '';

      if (!zips.replace(/[^0-9]/g, '').length) {
        alert('Enter at least one 5-digit ZIP code first.');
        return;
      }

      if (!confirm('Load addresses for: ' + zips.replace(/\s+/g, ' ').trim() + '\n\n' +
                   'Every address currently stored for those ZIP codes will be replaced with a fresh copy ' +
                   'from Connecticut\'s property records.\n\nThis can take several minutes. Continue?')) return;

      $btn.prop('disabled', true).text('Ingesting…');
      $('#subsales-ingest-results').hide().empty();
      $('#subsales-ingest-progress').show();
      setIngestProgress(1, 'Starting…');
      startIngestPolling();

      $.post(ajaxUrl, {
        action: 'subsales_ingest_zips',
        nonce: ingestNonce,
        zips: zips.replace(/\s+/g, ','),
        town: town
      }).done(function(resp){
        stopIngestPolling();
        if (!resp || !resp.success) {
          setIngestProgress(0, 'Didn\'t finish');
          alert('Could not load addresses: ' + errorOf(resp, 'Unknown error'));
          return;
        }
        setIngestProgress(100, 'Done');
        renderIngestSummary(resp.data);
      }).fail(function(xhr){
        stopIngestPolling();
        setIngestProgress(0, 'Didn\'t finish');
        alert('The request failed (' + xhr.status + ' ' + xhr.statusText + '). Nothing was changed for ZIP codes that hadn\'t started yet.');
      }).always(function(){
        $btn.prop('disabled', false).html('⬇ Ingest Addresses');
      });
    });

    // =======================================
    // Needs Review queue
    // =======================================

    function updateBadge(count) {
      $('#subsales-review-badge')
        .text(Number(count).toLocaleString())
        .toggleClass('in-progress', count > 0)
        .toggleClass('complete', count === 0);
    }

    function dropRow($row) {
      var id = $row.data('id');
      $('.subsales-review-editor[data-id="' + id + '"]').remove();
      $row.fadeOut(300, function(){ $(this).remove(); });
    }

    $(document).on('click', '.subsales-review-toggle', function(){
      var id = $(this).closest('.subsales-review-row').data('id');
      $('.subsales-review-editor[data-id="' + id + '"]').toggle();
    });

    $(document).on('click', '.subsales-review-cancel', function(){
      $(this).closest('.subsales-review-editor').hide();
    });

    $(document).on('click', '.subsales-review-geocode', function(){
      var $btn = $(this);
      var $row = $btn.closest('.subsales-review-row');
      var id = $row.data('id');

      if (!confirm('Ask Google to find this address on the map?\n\nThis costs a small amount, so use it one address at a time.')) return;

      $btn.prop('disabled', true).text('Looking up…');

      $.post(ajaxUrl, { action: 'subsales_review_queue_geocode', nonce: reviewNonce, id: id })
        .done(function(resp){
          if (!resp || !resp.success) {
            alert('Google couldn\'t find it: ' + errorOf(resp, 'Unknown error'));
            return;
          }
          var d = resp.data;
          var $editor = $('.subsales-review-editor[data-id="' + id + '"]');
          $editor.find('.subsales-review-lat').val(d.lat);
          $editor.find('.subsales-review-lng').val(d.lng);
          if (d.zip) $editor.find('.subsales-review-zip').val(d.zip);
          $editor.show();
          alert('Found it' + (d.formatted_address ? ': ' + d.formatted_address : '') + '.\n\n' +
                (d.zip ? 'ZIP code ' + d.zip + ' filled in for you.' : 'We still couldn\'t confirm the ZIP code — pick one below.') +
                '\n\nCheck it over, then press "Save address".');
        })
        .fail(function(xhr){
          alert('Look-up request failed: ' + xhr.status + ' ' + xhr.statusText);
        })
        .always(function(){ $btn.prop('disabled', false).text('Look up'); });
    });

    $(document).on('click', '.subsales-review-save', function(){
      var $btn = $(this);
      var $editor = $btn.closest('.subsales-review-editor');
      var id = $editor.data('id');
      var zip = $.trim($editor.find('.subsales-review-zip').val());
      var lat = $.trim($editor.find('.subsales-review-lat').val());
      var lng = $.trim($editor.find('.subsales-review-lng').val());

      if (!/^\d{5}$/.test(zip)) {
        alert('Pick a ZIP code first.');
        return;
      }
      if (!lat || !lng) {
        alert('This address has no map location yet. Press "Look up" to fill in the latitude and longitude.');
        return;
      }

      $btn.prop('disabled', true).text('Saving…');

      $.post(ajaxUrl, {
        action: 'subsales_review_queue_resolve',
        nonce: reviewNonce,
        id: id,
        zip: zip,
        lat: lat,
        lng: lng,
        house_number: $.trim($editor.find('.subsales-review-house').val()),
        street: $.trim($editor.find('.subsales-review-street').val()),
        city: $.trim($editor.find('.subsales-review-city').val()),
        note: $editor.find('.subsales-review-note').val()
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Could not save: ' + errorOf(resp, 'Unknown error'));
          $btn.prop('disabled', false).text('Save address');
          return;
        }
        updateBadge(resp.data.pending_count);
        dropRow($('.subsales-review-row[data-id="' + id + '"]'));
      }).fail(function(xhr){
        alert('Save request failed: ' + xhr.status + ' ' + xhr.statusText);
        $btn.prop('disabled', false).text('Save address');
      });
    });

    $(document).on('click', '.subsales-review-dismiss', function(){
      var $btn = $(this);
      var $row = $btn.closest('.subsales-review-row');
      var note = prompt('Take this address off the list. Why? (optional)', '');
      if (note === null) return;

      $btn.prop('disabled', true).text('Ignoring…');

      $.post(ajaxUrl, { action: 'subsales_review_queue_dismiss', nonce: reviewNonce, id: $row.data('id'), note: note })
        .done(function(resp){
          if (!resp || !resp.success) {
            alert('Could not do that: ' + errorOf(resp, 'Unknown error'));
            $btn.prop('disabled', false).text('Ignore');
            return;
          }
          updateBadge(resp.data.pending_count);
          dropRow($row);
        })
        .fail(function(xhr){
          alert('Request failed: ' + xhr.status + ' ' + xhr.statusText);
          $btn.prop('disabled', false).text('Ignore');
        });
    });

    // =======================================
    // Regenerate the PWA's per-ZIP JSON
    // =======================================

    $('#subsales-generate-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      if (!confirm('Rebuild the address files that sellers\' phones download?')) return;

      $btn.prop('disabled', true).text('Rebuilding…');

      $.post(ajaxUrl, { action: 'subsales_generate_zip_extracts', nonce: generateNonce })
        .done(function(resp){
          if (!resp || !resp.success) {
            alert('Rebuild failed: ' + errorOf(resp, 'Unknown error'));
            return;
          }
          alert('Done — the seller app now has the latest addresses.');
          setTimeout(function(){ location.reload(); }, 600);
        })
        .fail(function(xhr){
          alert('Rebuild request failed: ' + xhr.status + ' ' + xhr.statusText);
        })
        .always(function(){ $btn.prop('disabled', false).html('🔄 Regenerate PWA Data'); });
    });
  });
})(jQuery);
