/**
 * Subsales Import/Restore Modal Handler
 * 
 * Handles file import/restore with live progress updates via AJAX
 */
(function($) {
    'use strict';

    // Modal state
    let importInProgress = false;
    let processingInterval = null;
    let currentProgress = 0;

    // Initialize when DOM is ready
    $(document).ready(function() {
        initializeModalHandlers();
    });

    /**
     * Initialize modal event handlers
     */
    function initializeModalHandlers() {
        // Intercept import form submission
        $('#subsales-import-form').on('submit', function(e) {
            e.preventDefault();
            handleImportSubmit($(this), false);
        });

        // Intercept restore form submission
        $('#subsales-destructive-restore').on('submit', function(e) {
            e.preventDefault();
            handleImportSubmit($(this), true);
        });

        // Close modal handler
        $('#subsales-import-modal .modal-close, #subsales-import-modal .modal-backdrop').on('click', function() {
            if (!importInProgress) {
                closeModal();
            } else {
                if (confirm('Import is still in progress. Are you sure you want to close?')) {
                    closeModal();
                }
            }
        });
    }

    /**
     * Handle form submission
     */
    function handleImportSubmit($form, isRestore) {
        const fileInput = $form.find('input[type="file"]')[0];
        
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            alert('Please select a backup file to import.');
            return;
        }

        const file = fileInput.files[0];
        const restoreTarget = isRestore ? $form.find('select[name="restore_target"]').val() : null;

        // Validate file type
        if (!file.name.match(/\.(zip|csv)$/i)) {
            alert('Please select a valid backup file (.zip or .csv)');
            return;
        }

        openModal(isRestore, file.name);
        uploadAndProcess(file, isRestore, restoreTarget);
    }

    /**
     * Open and initialize the modal
     */
    function openModal(isRestore, fileName) {
        const $modal = $('#subsales-import-modal');
        const $title = $modal.find('.modal-title');
        const $log = $modal.find('.import-log');
        const $errors = $modal.find('.error-sections');
        const $progress = $modal.find('.progress-bar');
        const $close = $modal.find('.modal-close');

        // Set title
        $title.text(isRestore ? 'Restoring Backup' : 'Importing Data');

        // Initialize state
        importInProgress = true;
        $log.empty();
        $errors.empty();
        $progress.css('width', '0%').removeClass('complete error');
        $close.prop('disabled', true).css('opacity', '0.5');

        // Add initial log entry
        addLogEntry('Starting ' + (isRestore ? 'restore' : 'import') + ' of: ' + fileName, 'info');

        // Show modal
        $modal.fadeIn(200);
    }

    /**
     * Close the modal
     */
    function closeModal() {
        stopProcessingAnimation();
        $('#subsales-import-modal').fadeOut(200);
        importInProgress = false;

        // Reload page to refresh data
        setTimeout(function() {
            window.location.reload();
        }, 300);
    }

    /**
     * Upload file and process import
     */
    function uploadAndProcess(file, isRestore, restoreTarget) {
        const formData = new FormData();
        formData.append('action', 'subsales_import_ajax');
        formData.append('nonce', subsalesImportData.nonce);
        formData.append('backup_file', file);
        formData.append('is_restore', isRestore ? '1' : '0');
        if (restoreTarget) {
            formData.append('restore_target', restoreTarget);
        }

        updateProgress(5, 'Starting upload...');
        const uploadLogIndex = addLogEntry('Uploading backup file...', 'info', true);

        // Debug: Verify mini progress bar was created
        console.log('Upload log entry created at index:', uploadLogIndex);

        // Use XMLHttpRequest for upload progress tracking
        const xhr = new XMLHttpRequest();
        
        let lastProgressUpdate = 0;
        
        // Track upload progress
        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                const uploadPercent = Math.round(percentComplete * 0.2); // 0-20% of total
                
                // Throttle updates to every 5%
                if (percentComplete - lastProgressUpdate >= 5 || percentComplete === 100) {
                    lastProgressUpdate = percentComplete;
                    updateProgress(uploadPercent, 'Uploading: ' + percentComplete + '%');
                    updateMiniProgress(uploadLogIndex, percentComplete);

                }
            }
        }, false);
        
        // Track when upload is complete (before response processing)
        xhr.upload.addEventListener('load', function() {
            updateProgress(20, 'Processing on server...');
            updateMiniProgress(uploadLogIndex, 100);
            addLogEntry('File uploaded, processing import...', 'info');
        }, false);

        // Handle streaming progress updates
        var receivedText = '';
        xhr.addEventListener('progress', function(e) {
            // Process streaming response line-by-line
            var newText = xhr.responseText.substring(receivedText.length);
            receivedText = xhr.responseText;
            
            var lines = newText.split('\n');
            lines.forEach(function(line) {
                if (line.trim() === '') return;
                
                // Detect WordPress critical error page
                if (line.includes('<p>There has been a critical error')) {
                    console.error('[CRITICAL] WordPress fatal error detected!');
                    console.error('[CRITICAL] Server returned HTML error page instead of JSON');
                    console.error('[CRITICAL] Full response:', xhr.responseText);
                    addLogEntry('❌ CRITICAL: WordPress fatal PHP error occurred', 'error');
                    addLogEntry('Check WordPress debug.log for details', 'error');
                    updateProgress(100, 'Import failed - PHP error', 'error');
                    $('.modal-close').prop('disabled', false).css('opacity', '1');
                    return;
                }
                
                try {
                    var data = JSON.parse(line);
                    
                    if (data.type === 'progress') {
                        // Real-time progress update
                        if (data.percent !== null) {
                            updateProgress(data.percent, data.message);
                        }
                        addLogEntry(data.message, data.level);
                    } else if (data.type === 'complete') {
                        // Final completion message with error details
                        if (data.success) {
                            updateProgress(100, data.summary || 'Import complete!', 'complete');
                            
                            // Show errors grouped by table if any
                            if (data.errors && Object.keys(data.errors).length > 0) {
                                displayErrors(data.errors);
                            }
                            
                            // Mark import as complete and enable close button
                            importInProgress = false;
                            $('.modal-close').prop('disabled', false).css('opacity', '1');
                            addLogEntry('You can now close this window', 'info');
                        } else {
                            handleError('Import failed');
                        }
                    }
                } catch (e) {
                    console.error('Failed to parse streaming line:', line, e);
                }
            });
        });
        
        // Handle completion
        xhr.addEventListener('load', function() {
            if (xhr.status !== 200) {
                handleError('Server error: ' + xhr.status);
            }
        });

        // Handle errors
        xhr.addEventListener('error', function() {
            console.error('XHR error event');
            handleError('Network error during upload');
        });

        xhr.addEventListener('abort', function() {
            handleError('Upload cancelled');
        });

        // Send request
        xhr.open('POST', subsalesImportData.ajaxUrl);
        xhr.send(formData);
    }

    /**
     * Process the import response
     */
    function processResponse(data) {
        // Stop processing animation
        stopProcessingAnimation();
        
        // Add log entries
        if (data.logs && data.logs.length > 0) {
            data.logs.forEach(function(log) {
                addLogEntry(log.message, log.level || 'info');
            });
        }

        // Update progress
        updateProgress(100, 'Complete');

        // Show errors if any
        if (data.errors && Object.keys(data.errors).length > 0) {
            displayErrors(data.errors);
            addLogEntry('Import completed with ' + countTotalErrors(data.errors) + ' errors', 'warning');
        } else {
            addLogEntry('Import completed successfully', 'success');
        }

        // Show summary
        if (data.summary) {
            addLogEntry('Summary: ' + data.summary, 'success');
        }

        // Enable close button
        completeImport(data.errors && Object.keys(data.errors).length > 0);
    }

    /**
     * Add log entry to the log area
     */
    function addLogEntry(message, level, withProgress) {
        const $log = $('.import-log');
        const timestamp = new Date().toLocaleTimeString();
        const levelClass = 'log-' + level;
        const icon = getLogIcon(level);
        
        withProgress = withProgress || false;

        const $entry = $('<div class="log-entry ' + levelClass + (withProgress ? ' with-progress' : '') + '">');
        
        const $content = $('<div class="log-entry-content">')
            .append('<span class="log-time">' + timestamp + '</span>')
            .append('<span class="log-icon">' + icon + '</span>')
            .append('<span class="log-message">' + escapeHtml(message) + '</span>');
        
        $entry.append($content);
        
        if (withProgress) {
            const $miniProgress = $('<div class="mini-progress">')
                .append('<div class="mini-progress-bar"></div>');
            $entry.append($miniProgress);
        }

        $log.append($entry);
        $log.scrollTop($log[0].scrollHeight);
        
        // Return index for updating progress
        return $log.find('.log-entry').length - 1;
    }
    
    /**
     * Update mini progress bar in a log entry
     */
    function updateMiniProgress(entryIndex, percent) {
        const $entries = $('.import-log .log-entry');
        if (entryIndex >= 0 && entryIndex < $entries.length) {
            const $entry = $entries.eq(entryIndex);
            $entry.find('.mini-progress-bar').css('width', percent + '%');
        }
    }

    /**
     * Get icon for log level
     */
    function getLogIcon(level) {
        const icons = {
            'info': 'ℹ️',
            'success': '✅',
            'warning': '⚠️',
            'error': '❌'
        };
        return icons[level] || 'ℹ️';
    }

    /**
     * Display grouped errors
     */
    function displayErrors(errors) {
        const $container = $('.error-sections');
        $container.empty();

        Object.keys(errors).forEach(function(table) {
            const errorList = errors[table];
            if (!errorList || errorList.length === 0) return;

            // Group errors by type
            const grouped = {};
            errorList.forEach(function(error) {
                if (error.indexOf('Duplicate entry') !== -1) {
                    if (!grouped['Duplicate Key Violations']) {
                        grouped['Duplicate Key Violations'] = [];
                    }
                    grouped['Duplicate Key Violations'].push(error);
                } else {
                    const key = error.substring(0, 50) + '...';
                    if (!grouped[key]) {
                        grouped[key] = [];
                    }
                    grouped[key].push(error);
                }
            });

            // Create collapsible section for this table
            const $section = $('<div class="error-section">')
                .append('<div class="error-header">' +
                    '<span class="error-toggle">▶</span>' +
                    '<strong>' + escapeHtml(table) + '</strong> ' +
                    '<span class="error-count">(' + errorList.length + ' errors)</span>' +
                    '</div>');

            const $content = $('<div class="error-content" style="display: none;">');

            Object.keys(grouped).forEach(function(groupName) {
                const groupErrors = grouped[groupName];
                const $group = $('<div class="error-group">');
                
                if (groupErrors.length > 1) {
                    $group.append('<div class="error-group-title">' +
                        escapeHtml(groupName) + ' (' + groupErrors.length + ' occurrences)' +
                        '</div>');
                    // Show first 3 examples
                    groupErrors.slice(0, 3).forEach(function(err) {
                        $group.append('<div class="error-line">• ' + escapeHtml(err) + '</div>');
                    });
                    if (groupErrors.length > 3) {
                        $group.append('<div class="error-line">... and ' + (groupErrors.length - 3) + ' more</div>');
                    }
                } else {
                    $group.append('<div class="error-line">• ' + escapeHtml(groupErrors[0]) + '</div>');
                }

                $content.append($group);
            });

            $section.append($content);
            $container.append($section);

            // Toggle handler
            $section.find('.error-header').on('click', function() {
                const $toggle = $(this).find('.error-toggle');
                const $content = $(this).next('.error-content');
                
                if ($content.is(':visible')) {
                    $content.slideUp(200);
                    $toggle.text('▶');
                } else {
                    $content.slideDown(200);
                    $toggle.text('▼');
                }
            });
        });
    }

    /**
     * Count total errors
     */
    function countTotalErrors(errors) {
        let count = 0;
        Object.keys(errors).forEach(function(table) {
            count += errors[table].length;
        });
        return count;
    }

    /**
     * Update progress bar
     */
    function updateProgress(percent, message) {
        const $progress = $('.subsales-progress-fill');
        $progress.css('width', percent + '%');
        
        if (message) {
            $('.progress-text').text(message);
        }

        if (percent >= 100) {
            $progress.addClass('complete');
        }
    }

    /**
     * Start fake progress animation during server processing
     */
    function startProcessingAnimation() {
        // Clear any existing interval
        if (processingInterval) {
            clearInterval(processingInterval);
        }
        
        currentProgress = 20; // Start at 20% (after upload)
        
        // Slowly increment progress to show activity
        processingInterval = setInterval(function() {
            if (currentProgress < 90) { // Cap at 90% until real completion
                currentProgress += 1;
                updateProgress(currentProgress, 'Processing import on server...');
            }
        }, 1000); // Update every 1 second
    }

    /**
     * Stop processing animation
     */
    function stopProcessingAnimation() {
        if (processingInterval) {
            clearInterval(processingInterval);
            processingInterval = null;
        }
    }

    /**
     * Handle import completion
     */
    function completeImport(hasErrors) {
        importInProgress = false;
        
        const $close = $('.modal-close');
        const $progress = $('.subsales-progress-fill');
        
        if (hasErrors) {
            $progress.addClass('error');
        }

        $close.prop('disabled', false).css('opacity', '1');
        addLogEntry('You can now close this window', 'info');
    }

    /**
     * Handle errors
     */
    function handleError(message) {
        stopProcessingAnimation();
        addLogEntry('Error: ' + message, 'error');
        updateProgress(100, 'Failed');
        $('.subsales-progress-fill').addClass('error');
        completeImport(true);
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})(jQuery);
