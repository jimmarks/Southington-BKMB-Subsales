/**
 * PWA Client-Side Logger
 * Checks server debug mode and logs user interactions to WordPress backend
 */
(function(window) {
    'use strict';

    const PWALogger = {
        debugEnabled: false,
        apiBase: '',
        teamName: '',
        userName: '',
        sessionId: '',
        initialized: false,

        /**
         * Initialize the logger with API configuration
         */
        async init(config) {
            this.apiBase = (config.apiBase || '').replace(/\/+$/, '');
            this.teamName = config.teamName || '';
            this.userName = config.userName || '';
            this.sessionId = config.sessionId || '';

            if (!this.apiBase) {
                console.warn('[PWA Logger] No API base URL provided');
                return;
            }

            // Check if debug mode is enabled on server
            // Note: /config endpoint is public, no auth required for debug status
            try {
                const response = await fetch(`${this.apiBase}/config`);

                if (response.ok) {
                    const data = await response.json();
                    // Check both formats for compatibility
                    this.debugEnabled = data.debugLoggingEnabled || data.debug_logging_enabled || false;
                    this.initialized = true;
                    
                    console.warn('[PWA Logger] Initialized with debugEnabled:', this.debugEnabled, 'from config data:', data);

                    if (this.debugEnabled) {

                        this.log('system', 'PWA Logger initialized - Debug mode ACTIVE', {
                            user_agent: navigator.userAgent,
                            online: navigator.onLine,
                            screen: `${window.screen.width}x${window.screen.height}`,
                            has_auth: !!(this.teamName && this.userName)
                        });
                    } else {
                        console.warn('[PWA Logger] Debug mode is OFF - only errors will be logged');
                    }
                } else {
                    console.warn('[PWA Logger] Failed to check debug status, HTTP', response.status);
                    this.initialized = true; // Still initialize for error logging
                }
            } catch (error) {
                console.error('[PWA Logger] Init error:', error);
                this.initialized = true; // Still initialize for error logging
            }
        },
        
        /**
         * Update credentials after login
         */
        updateCredentials(teamName, userName, sessionId) {
            this.teamName = teamName || this.teamName;
            this.userName = userName || this.userName;
            this.sessionId = sessionId || this.sessionId;

            if (this.debugEnabled) {
                this.log('system', 'PWA Logger credentials updated after login', {
                    team: this.teamName,
                    user: this.userName
                });
            }
        },

        /**
         * Update debug status (called when heartbeat returns new config)
         */
        updateDebugStatus(enabled) {
            const wasEnabled = this.debugEnabled;
            this.debugEnabled = !!enabled;
            
            if (wasEnabled !== this.debugEnabled) {
                console.warn('[PWA Logger] Debug mode changed:', wasEnabled, '→', this.debugEnabled);
                
                if (this.debugEnabled) {
                    this.log('system', 'Debug mode enabled - now tracking all interactions', {
                        triggered_by: 'heartbeat_config_update'
                    });
                }
            }
        },

        /**
         * Send log to backend
         */
        async log(category, message, context = {}) {
            console.warn('[PWA Logger] log() called:', {category, message, debugEnabled: this.debugEnabled, initialized: this.initialized});
            
            if (!this.debugEnabled || !this.initialized) {
                console.warn('[PWA Logger] Skipping log - debugEnabled:', this.debugEnabled, 'initialized:', this.initialized);
                return;
            }

            try {
                const logData = {
                    level: 'DEBUG',
                    category: category,
                    message: message,
                    context: {
                        ...context,
                        timestamp: new Date().toISOString(),
                        url: window.location.href,
                        team: this.teamName,
                        user: this.userName,
                        session_id: this.sessionId
                    },
                    source: 'pwa',
                    user_name: this.userName
                };

                // Send to backend (fire and forget, don't block UI)
                fetch(`${this.apiBase}/log`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Team-Name': this.teamName,
                        'X-Access-Code': localStorage.getItem('teamCode') || ''
                    },
                    body: JSON.stringify(logData)
                }).catch(err => {
                    console.error('[PWA Logger] Failed to send log:', err);
                });

                // Also log to console for immediate visibility

            } catch (error) {
                console.error('[PWA Logger] Log error:', error);
            }
        },

        /**
         * Log button click
         */
        logButtonClick(buttonId, buttonText) {
            this.log('ui', 'Button clicked', {
                button_id: buttonId,
                button_text: buttonText
            });
        },

        /**
         * Log form input
         */
        logInput(fieldName, fieldType, value) {
            // Don't log sensitive values, just that input occurred
            this.log('ui', 'Input changed', {
                field: fieldName,
                type: fieldType,
                has_value: !!value,
                length: value ? value.length : 0
            });
        },

        /**
         * Log navigation
         */
        logNavigation(from, to) {
            this.log('navigation', 'Screen changed', {
                from: from,
                to: to
            });
        },

        /**
         * Log API call
         */
        logApiCall(endpoint, method, status) {
            this.log('api', 'API request', {
                endpoint: endpoint,
                method: method,
                status: status
            });
        },

        /**
         * Log error
         */
        logError(category, message, error) {
            if (!this.initialized) return;

            const errorData = {
                level: 'ERROR',
                category: category,
                message: message,
                context: {
                    error: error.message || String(error),
                    stack: error.stack,
                    timestamp: new Date().toISOString(),
                    url: window.location.href,
                    team: this.teamName,
                    user: this.userName
                },
                source: 'pwa',
                user_name: this.userName
            };

            // Always send errors regardless of debug mode
            fetch(`${this.apiBase}/log`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Team-Name': this.teamName,
                    'X-Access-Code': localStorage.getItem('teamCode') || ''
                },
                body: JSON.stringify(errorData)
            }).catch(() => {});

            console.error(`[PWA Error] ${category}: ${message}`, error);
        },

        /**
         * Auto-instrument common interactions
         * Always adds listeners, but checks debugEnabled when logging
         */
        instrumentUI() {
            const logger = this;

            // Log all button clicks (check debugEnabled at click time, not setup time)
            document.addEventListener('click', function(e) {
                const button = e.target.closest('button');
                if (button && logger.debugEnabled) {
                    logger.logButtonClick(
                        button.id || 'unnamed',
                        button.textContent.trim().substring(0, 50)
                    );
                }
            });

            // Log input changes (debounced)
            let inputTimeout;
            document.addEventListener('input', function(e) {
                if (e.target.matches('input, textarea, select') && logger.debugEnabled) {
                    clearTimeout(inputTimeout);
                    inputTimeout = setTimeout(() => {
                        logger.logInput(
                            e.target.name || e.target.id || 'unnamed',
                            e.target.type || e.target.tagName.toLowerCase(),
                            e.target.value
                        );
                    }, 500);
                }
            });

            console.warn('[PWA Logger] UI instrumentation installed - will log when debugEnabled is true');
        }
    };

    // Expose globally
    window.PWALogger = PWALogger;

})(window);
