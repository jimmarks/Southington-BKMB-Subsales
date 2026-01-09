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

    // ZIP Reassignment button handler (bulk update with progress tracking)
    $('#subsales-reassign-zips-btn').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      
      if (!confirm('This will re-assign ZIP codes to ALL addresses using reverse geocoding.\n\n⚠️ WARNING: This will use Google Maps API credits.\nEstimate: ~18,000 addresses = ~18,000 API calls.\n\nThis process may take 15-20 minutes. Continue?')) return;
      
      $btn.prop('disabled', true).html('🔄 Starting...');
      
      // Create progress display
      var $progress = $('<div id="zip-reassign-progress" style="margin-top:20px; padding:20px; background:#fff; border-left:4px solid #2271b1; box-shadow:0 1px 3px rgba(0,0,0,0.1);"></div>');
      $progress.html('<h3 style="margin:0 0 15px;">🔄 Reassigning ZIP Codes</h3>' +
        '<div style="margin-bottom:15px;">' +
          '<div style="display:flex; justify-content:space-between; font-size:12px; color:#646970; margin-bottom:4px;">' +
            '<span id="reassign-progress-text">Initializing...</span>' +
            '<span id="reassign-progress-percent">0%</span>' +
          '</div>' +
          '<div class="subsales-progress-bar">' +
            '<div id="reassign-progress-fill" class="subsales-progress-fill" style="width:0%;"></div>' +
          '</div>' +
        '</div>' +
        '<div id="reassign-log" style="max-height:300px; overflow-y:auto; font-family:monospace; font-size:12px; white-space:pre-wrap; background:#f6f7f7; padding:10px; border-radius:3px;"></div>');
      
      $btn.after($progress);
      
      var offset = 0;
      var totalUpdated = 0;
      var totalFailed = 0;
      
      function processBatch() {
        $.post(SubsalesZipAdmin.ajaxUrl, {
          action: 'subsales_reassign_zips',
          nonce: SubsalesZipAdmin.reassignZipsNonce,
          batch_size: 50,
          offset: offset
        }).done(function(resp){
          if (!resp || !resp.success) {
            $('#reassign-log').append('ERROR: ' + (resp.data || 'Unknown error') + '\n');
            alert('ZIP reassignment failed: ' + (resp.data || 'Unknown error'));
            $btn.prop('disabled', false).html('🔄 Reassign ZIP Codes');
            return;
          }
          
          var data = resp.data;
          
          if (data.complete) {
            // Done!
            $('#reassign-progress-text').text('Complete!');
            $('#reassign-progress-percent').text('100%');
            $('#reassign-progress-fill').css('width', '100%');
            $('#reassign-log').append('\n✅ ZIP reassignment complete!\n');
            $('#reassign-log').append('Total processed: ' + data.processed + '\n');
            $('#reassign-log').append('Successfully updated: ' + totalUpdated + '\n');
            $('#reassign-log').append('Failed: ' + totalFailed + '\n');
            
            alert('ZIP reassignment complete!\n\nProcessed: ' + data.processed + ' addresses\nUpdated: ' + totalUpdated + '\nFailed: ' + totalFailed + '\n\nPage will reload to show updated statistics.');
            
            setTimeout(function(){ location.reload(); }, 1500);
            return;
          }
          
          // Update progress
          offset = data.processed;
          totalUpdated += data.updated || 0;
          totalFailed += data.failed || 0;
          
          $('#reassign-progress-text').text(data.message);
          $('#reassign-progress-percent').text(data.progress + '%');
          $('#reassign-progress-fill').css('width', data.progress + '%');
          $('#reassign-log').append('[' + data.progress + '%] ' + data.message + ' (Updated: ' + data.updated + ', Failed: ' + data.failed + ')\n');
          $('#reassign-log').scrollTop($('#reassign-log')[0].scrollHeight);
          
          $btn.html('🔄 Processing... ' + data.progress + '%');
          
          // Process next batch
          setTimeout(processBatch, 100);
          
        }).fail(function(xhr){
          $('#reassign-log').append('AJAX ERROR: ' + xhr.status + ' ' + xhr.statusText + '\n');
          alert('ZIP reassignment request failed: ' + xhr.status + ' ' + xhr.statusText);
          $btn.prop('disabled', false).html('🔄 Reassign ZIP Codes');
        });
      }
      
      // Start processing
      processBatch();
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

    // ZIP Boundaries Upload handler
    $('#upload-boundaries-form').on('submit', function(e){
      e.preventDefault();
      
      var formData = new FormData();
      var fileInput = $('#boundaries_file')[0];
      
      if (!fileInput.files || !fileInput.files[0]) {
        alert('Please select a file');
        return;
      }
      
      formData.append('action', 'subsales_upload_zip_boundaries');
      formData.append('nonce', SubsalesZipAdmin.uploadNonce);
      formData.append('boundaries_file', fileInput.files[0]);
      
      var $btn = $('#boundaries-submit-btn');
      var $progress = $('#boundaries-upload-progress');
      var $progressFill = $('#boundaries-progress-fill');
      var $progressText = $('#boundaries-progress-text');
      var $log = $('#boundaries-log');
      
      $btn.prop('disabled', true).text('Processing...');
      $progress.show();
      $log.text('Uploading ZIP boundaries shapefile...\n');
      
      $.ajax({
        url: SubsalesZipAdmin.ajaxUrl,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        xhr: function() {
          var xhr = new window.XMLHttpRequest();
          xhr.upload.addEventListener('progress', function(e){
            if (e.lengthComputable) {
              var percent = Math.round((e.loaded / e.total) * 50); // Upload is 50% of total
              $progressFill.css('width', percent + '%');
              $progressText.text('Uploading... ' + percent + '%');
            }
          }, false);
          return xhr;
        }
      }).done(function(resp){
        $progressFill.css('width', '100%');
        
        if (!resp || !resp.success) {
          var errorMsg = 'Upload failed';
          if (resp && resp.data) {
            errorMsg = resp.data;
          }
          $log.append('\n❌ ERROR: ' + errorMsg + '\n');
          alert('Upload failed: ' + errorMsg);
          $btn.prop('disabled', false).text('Upload and Process');
          $progress.hide();
          return;
        }
        
        var data = resp.data;
        $progressText.text('✓ Complete!');
        $log.append('\n✅ Success!\n');
        $log.append('Extracted ' + data.zip_count + ' ZIP boundary polygons\n');
        $log.append('ZIPs loaded: ' + data.zips.join(', ') + '\n');
        
        if (data.missing_zips && data.missing_zips.length > 0) {
          $log.append('\n⚠️ Warning: Some configured ZIPs not found in boundary file:\n');
          $log.append('   ' + data.missing_zips.join(', ') + '\n');
        }
        
        alert('ZIP boundaries loaded successfully!\n\n' + 
              'Loaded ' + data.zip_count + ' ZIP codes: ' + data.zips.join(', ') + '\n\n' +
              'You can now upload address data and ZIPs will be assigned automatically.');
        
        // Reload page to update UI
        setTimeout(function(){ location.reload(); }, 1000);
        
      }).fail(function(xhr){
        var errorMsg = 'HTTP ' + xhr.status + ': ' + xhr.statusText;
        
        // Try to parse JSON error response
        try {
          var resp = JSON.parse(xhr.responseText);
          if (resp && resp.data) {
            errorMsg = resp.data;
          }
        } catch(e) {
          // Not JSON, use status text
        }
        
        $log.append('\n❌ ERROR: ' + errorMsg + '\n');
        alert('Upload request failed:\n\n' + errorMsg + '\n\nPlease check:\n- File is a valid ZIP file\n- ZIP codes are configured in settings\n- File size is within server limits');
        $btn.prop('disabled', false).text('Upload and Process');
        $progress.hide();
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
    
    // =======================================
    // Census Configuration Save Handler
    // =======================================
    
    $('#save-census-config-btn').on('click', function(e){
      e.preventDefault();
      
      var $btn = $(this);
      var year = $('#census_year').val();
      var state = $('#census_state').val().trim().toUpperCase();
      var zipFilter = $('#census_zip_filter').val().trim();
      var urlPattern = $('#census_url_pattern').val().trim();
      
      $btn.prop('disabled', true).text('Saving...');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_save_census_config',
        nonce: SubsalesZipAdmin.uploadNonce,
        census_year: year,
        census_state: state,
        census_zip_filter: zipFilter,
        census_url_pattern: urlPattern
      }).done(function(resp){
        if (!resp || !resp.success) {
          alert('Failed to save configuration: ' + (resp && resp.data ? resp.data : 'Unknown error'));
        } else {
          alert('✅ Census configuration saved successfully!');
        }
      }).fail(function(xhr){
        alert('Configuration save request failed: ' + xhr.status + ' ' + xhr.statusText);
      }).always(function(){
        $btn.prop('disabled', false).text('Save Configuration');
      });
    });
    
    // =======================================
    // Census Auto-Download Handler
    // =======================================
    
    $('#auto-download-boundaries-btn').on('click', function(e){
      e.preventDefault();
      
      console.log('=== Census Auto-Download Button Clicked ===');
      
      var $btn = $(this);
      var $progress = $('#census-download-progress');
      var $progressFill = $('#census-progress-fill');
      var $progressText = $('#census-progress-text');
      var $log = $('#census-log');
      
      if (!confirm('Auto-download Census ZCTA boundaries?\n\nThis will:\n• Download the Census shapefile for your configured year\n• Filter to only your configured ZIP codes\n• Import boundary polygons automatically\n\nThis may take 2-5 minutes. Continue?')) {
        console.log('User cancelled');
        return;
      }
      
      console.log('Starting Census download...');
      console.log('AJAX URL:', SubsalesZipAdmin.ajaxUrl);
      console.log('Nonce:', SubsalesZipAdmin.uploadNonce ? 'present' : 'MISSING');
      
      $btn.prop('disabled', true).html('🚀 Downloading...');
      $progress.show();
      $progressText.text('Downloading from Census Bureau...');
      $progressFill.css('width', '10%');
      $log.text('Starting Census auto-download...\n');
      
      var startTime = Date.now();
      
      console.log('Sending AJAX request...');
      
      $.post(SubsalesZipAdmin.ajaxUrl, {
        action: 'subsales_auto_download_census',
        nonce: SubsalesZipAdmin.uploadNonce
      }).done(function(resp){
        console.log('AJAX done - response:', resp);
        if (!resp || !resp.success) {
          var errorMsg = resp && resp.data ? resp.data : 'Unknown error';
          console.error('Census download FAILED:', errorMsg);
          console.error('Full response:', resp);
          
          $progressText.text('❌ Failed');
          $progressFill.css('width', '0%');
          $log.append('\n❌ ERROR: ' + errorMsg + '\n');
          
          alert('Census auto-download failed:\n\n' + errorMsg + '\n\nPlease check:\n• Census configuration is correct\n• Server has internet access\n• Census URL is valid\n\nYou can try manual upload instead.');
        } else {
          console.log('Census download SUCCESS:', resp.data);
          var data = resp.data;
          var elapsed = Math.round((Date.now() - startTime) / 1000);
          
          $progressText.text('✅ Complete!');
          $progressFill.css('width', '100%');
          $log.append('\n✅ SUCCESS!\n');
          $log.append('ZIP codes loaded: ' + data.zip_count + '\n');
          $log.append('ZIPs: ' + data.zips.join(', ') + '\n');
          
          if (data.missing_zips && data.missing_zips.length > 0) {
            $log.append('\n⚠️ Missing ZIPs (not in Census data): ' + data.missing_zips.join(', ') + '\n');
          }
          
          $log.append('Records skipped (filtered): ' + data.skipped.toLocaleString() + '\n');
          $log.append('Time elapsed: ' + elapsed + ' seconds\n');
          
          var alertMsg = '✅ Census boundaries loaded successfully!\n\n';
          alertMsg += 'ZIP codes imported: ' + data.zip_count + '\n';
          alertMsg += 'Time: ' + elapsed + ' seconds\n';
          
          if (data.missing_zips && data.missing_zips.length > 0) {
            alertMsg += '\n⚠️ Warning: Some configured ZIPs were not found in Census data:\n' + data.missing_zips.join(', ');
          }
          
          alertMsg += '\n\nPage will reload to show updated boundary status.';
          
          alert(alertMsg);
          
          setTimeout(function(){ location.reload(); }, 1500);
        }
      }).fail(function(xhr){
        console.error('AJAX request FAILED');
        console.error('Status:', xhr.status);
        console.error('Status Text:', xhr.statusText);
        console.error('Response Text:', xhr.responseText);
        
        var errorMsg = 'HTTP ' + xhr.status + ': ' + xhr.statusText;
        
        try {
          var resp = JSON.parse(xhr.responseText);
          console.error('Parsed response:', resp);
          if (resp && resp.data) {
            errorMsg = resp.data;
          }
        } catch(e) {
          console.error('Could not parse response as JSON');
        }
        
        $progressText.text('❌ Failed');
        $progressFill.css('width', '0%');
        $log.append('\n❌ ERROR: ' + errorMsg + '\n');
        
        alert('Census auto-download request failed:\n\n' + errorMsg + '\n\nPlease check:\n• Server has internet access\n• Census website is accessible\n• PHP limits are sufficient\n\nYou can try manual upload instead.');
      }).always(function(){
        $btn.prop('disabled', false).html('🚀 Auto-Download Census Boundaries');
      });
    });
  });
})(jQuery);

