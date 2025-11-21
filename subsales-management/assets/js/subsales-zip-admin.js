(function($){
  $(function(){
    $('#subsales-generate-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      if (!confirm('Generate per-ZIP address extracts now? This may take a while and will query OpenStreetMap Overpass API.')) return;
      $btn.prop('disabled', true).text('Generating...');
      var $log = $('#subsales-generate-log'); $log.show().text('Starting generation...\n');
      $.post(SubsalesZipAdmin.ajaxUrl, { action: 'subsales_generate_zip_extracts', nonce: SubsalesZipAdmin.nonce }).done(function(resp){
        if (!resp || !resp.success) {
          $log.append('Error: ' + JSON.stringify(resp) + '\n');
          alert('Generation failed. See log.');
        } else {
          var data = resp.data; $log.append('Generation complete:\n');
          for (var zip in data) {
            if (!data.hasOwnProperty(zip)) continue;
            $log.append(zip + ': ' + JSON.stringify(data[zip]) + '\n');
          }
          alert('Generation completed. See list of extract files below.');
          // reload fragments of page to show files
          setTimeout(function(){ location.reload(); }, 800);
        }
      }).fail(function(xhr){
        $log.append('AJAX failure: ' + xhr.status + ' ' + xhr.statusText + '\n');
        alert('Generation request failed. Check server logs.');
      }).always(function(){ $btn.prop('disabled', false).text('Generate extracts'); });
    });
  });
})(jQuery);
