(function($){
  $(function(){
    // Generate button handler
    $('#subsales-generate-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      if (!confirm('Generate per-ZIP JSON extracts from your address database? This will create files for PWA offline access.')) return;
      $btn.prop('disabled', true).html('📦 Generating...');
      var $log = $('#subsales-generate-log'); $log.show().text('Starting generation from database...\n');
      $.post(SubsalesZipAdmin.ajaxUrl, { action: 'subsales_generate_zip_extracts', nonce: SubsalesZipAdmin.nonce }).done(function(resp){
        if (!resp || !resp.success) {
          var errMsg = resp && resp.data ? resp.data : 'Unknown error';
          $log.append('Error: ' + errMsg + '\n');
          alert('Generation failed: ' + errMsg);
        } else {
          var data = resp.data; $log.append('Generation complete:\n');
          for (var zip in data) {
            if (!data.hasOwnProperty(zip)) continue;
            $log.append(zip + ': ' + JSON.stringify(data[zip]) + '\n');
          }
          alert('Generation completed! Extract files are ready for PWA use.');
          // reload fragments of page to show files
          setTimeout(function(){ location.reload(); }, 800);
        }
      }).fail(function(xhr){
        $log.append('AJAX failure: ' + xhr.status + ' ' + xhr.statusText + '\n');
        alert('Generation request failed. Check server logs.');
      }).always(function(){ $btn.prop('disabled', false).html('📦 Generate Extracts'); });
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
    // ===============================================
    // BACKGROUND MATCHING MODE (only mode now)
    // ===============================================
    var bgStatusTimer = null;
    
    // Background Match Button - Start/Stop/Resume
    $('#subsales-bg-match-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      
      // Check current background status
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_bg_match_status',
        nonce: SubsalesZipAdmin.bgMatchNonce
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Failed to check background job status.');
          return;
        }
        
        var status = resp.data.status;
        
        if (status === 'running') {
          // Job is running - offer to stop
          if (confirm('Background matching is currently running. Do you want to STOP it?')) {
            stopBackgroundJob($btn);
          }
        } else if (status === 'paused') {
          // Job is paused - offer to resume
          if (confirm('Resume background matching job?')) {
            resumeBackgroundJob($btn);
          }
        } else {
          // Idle or complete - start new job
          if (confirm('Start background address matching? The job will run in the background and you can continue working.')) {
            startBackgroundJob($btn);
          }
        }
      });
    });
    
    function startBackgroundJob($btn) {
      $btn.prop('disabled', true).html('<span class="bg-match-btn-icon">⏳</span> <span class="bg-match-btn-text">Starting...</span>');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_bg_match_start',
        nonce: SubsalesZipAdmin.bgMatchNonce
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Failed to start background job: ' + (resp.data || 'Unknown error'));
          $btn.prop('disabled', false).html('<span class="bg-match-btn-icon">🔄</span> <span class="bg-match-btn-text">Match with Overpass</span>');
        } else {
          alert('Background matching started!\n\n' + resp.data.message + '\n\nYou can now continue working. The job will run in the background.');
          $btn.html('<span class="bg-match-btn-icon">⏸️</span> <span class="bg-match-btn-text">Stop Matching</span>');
          
          // Start polling for status
          startStatusPolling();
          location.reload();
        }
      }).fail(function(xhr){
        alert('Failed to start background job: ' + xhr.status + ' ' + xhr.statusText);
        $btn.prop('disabled', false).html('<span class="bg-match-btn-icon">🔄</span> <span class="bg-match-btn-text">Match with Overpass</span>');
      });
    }
    
    function stopBackgroundJob($btn) {
      $btn.prop('disabled', true).html('<span class="bg-match-btn-icon">⏳</span> <span class="bg-match-btn-text">Stopping...</span>');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_bg_match_stop',
        nonce: SubsalesZipAdmin.bgMatchNonce
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Failed to stop background job: ' + (resp.data || 'Unknown error'));
        } else {
          alert('Background matching stopped.\n\n' + resp.data.message);
          stopStatusPolling();
          location.reload();
        }
      }).always(function(){
        $btn.prop('disabled', false).html('<span class="bg-match-btn-icon">▶️</span> <span class="bg-match-btn-text">Resume Matching</span>');
      });
    }
    
    function resumeBackgroundJob($btn) {
      $btn.prop('disabled', true).html('<span class="bg-match-btn-icon">⏳</span> <span class="bg-match-btn-text">Resuming...</span>');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_bg_match_resume',
        nonce: SubsalesZipAdmin.bgMatchNonce
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Failed to resume background job: ' + (resp.data || 'Unknown error'));
          $btn.prop('disabled', false).html('<span class="bg-match-btn-icon">▶️</span> <span class="bg-match-btn-text">Resume Matching</span>');
        } else {
          alert('Background matching resumed!');
          $btn.html('<span class="bg-match-btn-icon">⏸️</span> <span class="bg-match-btn-text">Stop Matching</span>');
          startStatusPolling();
        }
      });
    }
    
    function startStatusPolling() {
      if (bgStatusTimer) clearInterval(bgStatusTimer);
      
      // Poll every 3 seconds
      bgStatusTimer = setInterval(function(){
        updateBackgroundStatus();
      }, 3000);
      
      // Update immediately
      updateBackgroundStatus();
    }
    
    function stopStatusPolling() {
      if (bgStatusTimer) {
        clearInterval(bgStatusTimer);
        bgStatusTimer = null;
      }
    }
    
    function updateBackgroundStatus() {
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_bg_match_status',
        nonce: SubsalesZipAdmin.bgMatchNonce
      }).done(function(resp){
        if (!resp || !resp.success) return;
        
        var data = resp.data;
        var $widget = $('#bg-status-widget');
        var $statusText = $('#bg-status-text');
        var $statusPercent = $('#bg-status-percent');
        var $progressFill = $('#bg-progress-fill');
        var $statusDetails = $('#bg-status-details');
        var $btn = $('#subsales-bg-match-btn');
        
        // Update UI based on status
        if (data.is_running) {
          $statusText.text('🔄 Background matching in progress...');
          $btn.html('<span class="bg-match-btn-icon">⏸️</span> <span class="bg-match-btn-text">Stop Matching</span>').prop('disabled', false);
        } else if (data.is_complete) {
          $statusText.text('✅ Background matching complete');
          $btn.html('<span class="bg-match-btn-icon">🔄</span> <span class="bg-match-btn-text">Match with Overpass</span>').prop('disabled', false);
          stopStatusPolling();
        } else if (data.status === 'paused') {
          $statusText.text('⏸️ Background matching paused');
          $btn.html('<span class="bg-match-btn-icon">▶️</span> <span class="bg-match-btn-text">Resume Matching</span>').prop('disabled', false);
        } else if (data.has_error) {
          $statusText.text('❌ Background matching error');
          $btn.html('<span class="bg-match-btn-icon">🔄</span> <span class="bg-match-btn-text">Retry</span>').prop('disabled', false);
          stopStatusPolling();
        }
        
        // Update progress
        $statusPercent.text(data.percent + '%');
        $progressFill.css('width', data.percent + '%');
        $statusDetails.text(
          data.progress.processed.toLocaleString() + ' / ' + 
          data.progress.total.toLocaleString() + ' addresses'
        );
        
        // Show/hide widget
        if (data.status !== 'idle') {
          $widget.show();
        } else {
          $widget.hide();
        }
      });
    }
    
    // Auto-start polling if job is running on page load
    $(document).ready(function(){
      // Check status on page load to determine if polling should start
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_bg_match_status',
        nonce: SubsalesZipAdmin.bgMatchNonce
      }).done(function(resp){
        if (resp && resp.success && resp.data.is_running) {
          // Job is running, start polling
          startStatusPolling();
        }
      });
    });

    // =======================================
    // Upload Handler with Progress Tracking
    // =======================================
    
    $('#upload-address-form').on('submit', function(e){
      e.preventDefault();
      
      var $form = $(this);
      var $btn = $('#upload-submit-btn');
      var fileInput = $('#address_file')[0];
      
      if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select a file to upload.');
        return;
      }
      
      var file = fileInput.files[0];
      var formData = new FormData();
      formData.append('action', 'subsales_upload_address_file');
      formData.append('nonce', SubsalesZipAdmin.uploadNonce);
      formData.append('address_file', file);
      
      // Close modal
      $('#upload-modal').hide();
      $('#upload-modal-overlay').removeClass('open');
      
      // Show progress widget
      var $progress = $('#subsales-upload-progress');
      var $progressFill = $('#upload-progress-fill');
      var $progressText = $('#upload-progress-text');
      var $progressPercent = $('#upload-progress-percent');
      var $log = $('#upload-log');
      
      $progress.show();
      $progressFill.css('width', '5%');
      $progressText.text('Uploading file...');
      $progressPercent.text('5%');
      $log.text('Starting upload...\n');
      
      // Disable button
      $btn.prop('disabled', true).text('Uploading...');
      
      // Upload file via AJAX
      $.ajax({
        url: SubsalesZipAdmin.ajaxUrl,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        xhr: function() {
          var xhr = new window.XMLHttpRequest();
          // Upload progress
          xhr.upload.addEventListener('progress', function(e){
            if (e.lengthComputable) {
              var percentComplete = Math.round((e.loaded / e.total) * 30); // 0-30%
              $progressFill.css('width', percentComplete + '%');
              $progressPercent.text(percentComplete + '%');
            }
          }, false);
          return xhr;
        }
      }).done(function(resp){
        if (!resp || !resp.success) {
          $log.append('ERROR: ' + (resp && resp.data ? resp.data : 'Unknown error') + '\n');
          $progressText.text('Upload failed');
          alert('Upload failed: ' + (resp && resp.data ? resp.data : 'Unknown error'));
          $btn.prop('disabled', false).text('Upload and Process');
        } else {
          $log.append('File uploaded successfully!\n');
          $progressFill.css('width', '40%');
          $progressPercent.text('40%');
          $progressText.text('Processing file...');
          
          // Poll for processing status
          var pollInterval = setInterval(function(){
            $.post(SubsalesZipAdmin.ajaxUrl, {
              action: 'subsales_upload_status',
              nonce: SubsalesZipAdmin.uploadNonce
            }).done(function(statusResp){
              if (!statusResp || !statusResp.success) {
                clearInterval(pollInterval);
                $log.append('ERROR: Could not get status\n');
                $progressText.text('Status check failed');
                $btn.prop('disabled', false).text('Upload and Process');
                return;
              }
              
              var status = statusResp.data;
              $log.append(status.message + '\n');
              $progressFill.css('width', status.percent + '%');
              $progressPercent.text(status.percent + '%');
              $progressText.text(status.status_text);
              
              if (status.complete) {
                clearInterval(pollInterval);
                $btn.prop('disabled', false).text('Upload and Process');
                
                if (status.success) {
                  $log.append('\n✅ Processing complete!\n');
                  setTimeout(function(){ location.reload(); }, 1500);
                } else {
                  $log.append('\n❌ Processing failed.\n');
                }
              }
            });
          }, 1000); // Poll every second
        }
      }).fail(function(xhr){
        $log.append('AJAX ERROR: ' + xhr.status + ' ' + xhr.statusText + '\n');
        $progressText.text('Upload failed');
        alert('Upload request failed. Check server logs.');
        $btn.prop('disabled', false).text('Upload and Process');
      });
    });

    // =======================================
    // Reassign ZIP Codes Button Handler
    // =======================================
    
    $('#subsales-reassign-zips-btn').on('click', function(e){
      e.preventDefault();
      
      var $btn = $(this);
      
      if (!confirm('Re-run ZIP code assignment for all addresses with coordinates?\n\nThis will use the border-based spatial lookup (fast) to reassign ZIP codes based on lat/lng coordinates.')) {
        return;
      }
      
      $btn.prop('disabled', true).html('🔄 Processing...');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_reassign_zips',
        nonce: SubsalesZipAdmin.reassignZipsNonce
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('ZIP reassignment failed: ' + (resp && resp.data ? resp.data : 'Unknown error'));
          $btn.prop('disabled', false).html('🔄 Reassign ZIP Codes');
        } else {
          var data = resp.data;
          alert('✅ ' + data.message + '\n\nTotal: ' + data.total.toLocaleString() + '\nUpdated: ' + data.updated.toLocaleString() + '\nFailed: ' + data.failed);
          location.reload();
        }
      }).fail(function(xhr){
        alert('ZIP reassignment request failed: ' + xhr.status + ' ' + xhr.statusText);
        $btn.prop('disabled', false).html('🔄 Reassign ZIP Codes');
      });
    });
  });
})(jQuery);

