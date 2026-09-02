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
            
            console.warn('[PWA Logger] updateDebugStatus called - was:', wasEnabled, 'now:', this.debugEnabled);
            
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
                        timestamp: new Date().toISOString(),
                        url: window.location.href,
                        ...context,
                        // Only add if not already present to avoid overwriting
                        team: context.team || this.teamName,
                        user: context.user || this.userName,
                        session_id: context.session_id || this.sessionId
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
            this.log('ui', `Button clicked: ${buttonText || buttonId}`, {
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

            // What a control is actually called, in the order a person would
            // name it. "Button clicked: +" told nobody which product was tapped.
            function describe(el) {
                if (!el) return 'unknown control';
                const aria = el.getAttribute && el.getAttribute('aria-label');
                if (aria) return aria.trim();

                // a wrapping <label>, or one pointing at this id
                const wrap = el.closest && el.closest('label');
                if (wrap) {
                    const t = wrap.textContent.replace(/\s+/g, ' ').trim();
                    if (t) return t.substring(0, 60);
                }
                if (el.id) {
                    const forLabel = document.querySelector('label[for="' + el.id + '"]');
                    if (forLabel) {
                        const t = forLabel.textContent.replace(/\s+/g, ' ').trim();
                        if (t) return t.substring(0, 60);
                    }
                }
                const own = (el.textContent || '').replace(/\s+/g, ' ').trim();
                if (own) return own.substring(0, 60);
                return el.placeholder || el.name || el.id || (el.tagName || '').toLowerCase();
            }

            // Anything a customer told the seller stays out of the log; that a
            // field was filled in is the useful part, not what was typed.
            const PRIVATE_FIELDS = ['customerName', 'address', 'unitFloorApt', 'cellNumber', 'checkNumber', 'notes'];

            document.addEventListener('click', function(e) {
                const button = e.target.closest('button');
                if (!button || !logger.debugEnabled) return;
                logger.log('ui', 'Tapped ' + describe(button), {
                    control: button.id || null,
                    screen: document.getElementById('appSection') &&
                            !document.getElementById('appSection').classList.contains('hidden')
                            ? 'order' : 'login'
                });
            });

            let inputTimeout;
            document.addEventListener('input', function(e) {
                const el = e.target;
                if (!el.matches('input, textarea, select') || !logger.debugEnabled) return;
                clearTimeout(inputTimeout);
                inputTimeout = setTimeout(function() {
                    const productId = el.getAttribute && el.getAttribute('data-product-id');
                    const label = describe(el);

                    if (productId) {
                        // Quantities are the thing worth reading back later, and
                        // there is nothing private about them.
                        logger.log('ui', label.replace(/ quantity$/, '') + ' set to ' + (el.value === '' ? '0' : el.value), {
                            product: productId,
                            value: el.value === '' ? 0 : Number(el.value)
                        });
                        return;
                    }

                    const isPrivate = PRIVATE_FIELDS.indexOf(el.id) !== -1;
                    logger.log('ui', isPrivate
                        ? label + ' filled in'
                        : label + ' set to ' + (el.value || '(cleared)'), {
                        field: el.id || el.name || null,
                        type: el.type || el.tagName.toLowerCase(),
                        length: isPrivate ? (el.value || '').length : undefined
                    });
                }, 500);
            });

            // Checkboxes and the payment buttons fire change, not input.
            document.addEventListener('change', function(e) {
                const el = e.target;
                if (!el.matches('input[type="checkbox"], input[type="radio"], select') || !logger.debugEnabled) return;
                const label = describe(el);
                if (el.type === 'checkbox' || el.type === 'radio') {
                    logger.log('ui', label + (el.checked ? ' selected' : ' cleared'), {
                        field: el.id || null, checked: !!el.checked
                    });
                } else {
                    logger.log('ui', label + ' set to ' + (el.value || '(none)'), { field: el.id || null });
                }
            });

            console.warn('[PWA Logger] UI instrumentation installed - will log when debugEnabled is true');
        }
    };

    // Expose globally
    window.PWALogger = PWALogger;

})(window);
