(function($){
  $(function(){
    // Generate button handler
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

    // Delete button handler (delegated for dynamically loaded content)
    $(document).on('click', '.subsales-delete-zip', function(e){
      e.preventDefault();
      var $btn = $(this);
      var zip = $btn.data('zip');
      if (!zip) return;
      
      if (!confirm('Delete address extract for ZIP ' + zip + '? This will remove the JSON file and update the index.')) return;
      
      $btn.prop('disabled', true).text('Deleting...');
      
      $.post(SubsalesZipAdmin.ajaxUrl, { 
        action: 'subsales_delete_zip_extract', 
        nonce: SubsalesZipAdmin.deleteNonce,
        zip: zip
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Delete failed: ' + (resp.data || 'Unknown error'));
          $btn.prop('disabled', false).text('Delete');
        } else {
          alert('ZIP extract ' + zip + ' deleted successfully.');
          // Remove the row from table
          $btn.closest('tr').fadeOut(400, function(){ $(this).remove(); });
        }
      }).fail(function(xhr){
        alert('Delete request failed: ' + xhr.status + ' ' + xhr.statusText);
        $btn.prop('disabled', false).text('Delete');
      });
    });

    // Extract OpenAddresses ZIP codes button handler
    $('#subsales-extract-zips-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      
      if (!confirm('This will scan the full Connecticut file and extract only addresses for your configured ZIP codes. Continue?')) return;
      
      $btn.prop('disabled', true).text('Extracting...');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_extract_openaddresses_zips',
        nonce: SubsalesZipAdmin.nonce
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Extraction failed: ' + (resp.data || 'Unknown error'));
        } else {
          alert('Success! ' + resp.data.message + '\nFile size: ' + resp.data.file_size);
          location.reload();
        }
      }).fail(function(xhr){
        alert('Extraction request failed: ' + xhr.status + ' ' + xhr.statusText);
      }).always(function(){
        $btn.prop('disabled', false).text('Extract ZIP Codes');
      });
    });

    // Search address button handler
    $('#subsales-search-address-btn').on('click', function(e){
      e.preventDefault();
      var address = prompt('Enter address to search (e.g., "123 ABC St"):');
      if (!address) return;
      
      var $btn = $(this);
      $btn.prop('disabled', true).text('Searching...');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_search_address',
        nonce: SubsalesZipAdmin.searchNonce,
        address: address
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Search failed: ' + (resp.data || 'Unknown error'));
        } else {
          var data = resp.data;
          var msg = 'Search Results for "' + address + '":\n\n';
          msg += 'Total results: ' + data.total + '\n';
          if (data.zips_searched) {
            msg += 'Searched in ZIP codes: ' + data.zips_searched.join(', ') + '\n\n';
          } else {
            msg += '\n';
          }
          
          for (var source in data.sources) {
            var sourceData = data.sources[source];
            msg += source.toUpperCase() + ': ' + sourceData.count + ' result(s)\n';
            if (sourceData.results && sourceData.results.length > 0) {
              sourceData.results.slice(0, 3).forEach(function(r, i){
                msg += '  ' + (i+1) + '. ' + r.label + '\n';
              });
              if (sourceData.results.length > 3) {
                msg += '  ... and ' + (sourceData.results.length - 3) + ' more\n';
              }
            }
            msg += '\n';
          }
          
          if (data.total === 0) {
            msg += '\nAddress NOT found in generated extracts.\n';
            msg += 'This address may not exist in your local ZIP extract files. Try generating extracts first.';
          } else {
            msg += '\nAddress FOUND in your generated extracts!';
          }
          
          alert(msg);
        }
      }).fail(function(xhr){
        alert('Search request failed: ' + xhr.status + ' ' + xhr.statusText);
      }).always(function(){
        $btn.prop('disabled', false).text('Search Address');
      });
    });

    // Refresh ZIP Index button handler
    $('#subsales-refresh-index-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      
      $btn.prop('disabled', true).text('Refreshing...');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_refresh_zip_index',
        nonce: SubsalesZipAdmin.refreshIndexNonce
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('ZIP index refresh failed: ' + (resp.data || 'Unknown error'));
        } else {
          var msg = resp.data.message + '\n\nZIPs in index: ';
          if (resp.data.zips && resp.data.zips.length > 0) {
            msg += resp.data.zips.join(', ');
          } else {
            msg += '(none)';
          }
          alert(msg);
        }
      }).fail(function(xhr){
        alert('Refresh index request failed: ' + xhr.status + ' ' + xhr.statusText);
      }).always(function(){
        $btn.prop('disabled', false).text('Refresh ZIP Index');
      });
    });
  });
})(jQuery);
