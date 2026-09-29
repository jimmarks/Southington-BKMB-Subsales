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
        _uiInstrumented: false,
        _pending: [],
        _snapshotTimer: null,

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
                // Say who is asking: a watch on one seller or one session is
                // answered here, so logging can be on from the first moment
                // rather than waiting for a heartbeat 30 seconds in.
                const who = new URLSearchParams();
                if (this.sessionId) { who.set('session_id', this.sessionId); }
                try {
                    const uid = localStorage.getItem('userId');
                    if (uid) { who.set('user_id', uid); }
                } catch (e) {}
                const qs = who.toString();
                const response = await fetch(`${this.apiBase}/config` + (qs ? '?' + qs : ''));

                if (response.ok) {
                    const data = await response.json();
                    // Check both formats for compatibility
                    this.debugEnabled = data.debugLoggingEnabled || data.debug_logging_enabled || false;
                    this.initialized = true;
                    this.flushPending();
                    this.startSnapshots('logging on at startup');
                    
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
            if (this.debugEnabled) {
                this.flushPending();
                this.startSnapshots('logging switched on');
            } else {
                this.stopSnapshots();
            }
            
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
            
            // Hold rather than drop. Whether logging is on is only known once
            // /config comes back, and the interesting events - the GPS prompt
            // above all - happen in the seconds before that. Dropping them meant
            // watching someone and still catching nothing: exactly what happened
            // on 2026-09-29, GPS asked at 19:23:48, logging on at 19:24:04.
            if (!this.debugEnabled || !this.initialized) {
                this._pending.push({ category, message, context, held_at: new Date().toISOString() });
                if (this._pending.length > 50) { this._pending.shift(); }
                return;
            }
            this._send(category, message, context);
        },

        /**
         * Everything about this device worth knowing when someone is being
         * watched: what it is, what it can do, what it is allowed to do, and
         * what shape the network and storage are in.
         *
         * Nothing here prompts the person for anything. Location is read only
         * when the browser already says permission is granted - asking would
         * put a dialog in front of a seller standing at a door.
         *
         * Deliberately carries no customer or seller detail: this is about the
         * device. Names, numbers and addresses stay out of the debug log.
         */
        async snapshot(reason) {
            if (!this.debugEnabled) { return; }

            const nav = navigator || {};
            const out = {
                reason: reason || 'manual',
                app_version: ((window.SUBSALES_PWA_CONFIG || {}).assetVersion) || null,
                url: window.location.href,

                // Device and browser
                user_agent: nav.userAgent,
                platform: nav.platform || null,
                languages: (nav.languages || []).join(',') || nav.language || null,
                device_memory_gb: nav.deviceMemory || null,
                cpu_cores: nav.hardwareConcurrency || null,
                touch_points: nav.maxTouchPoints || null,

                // How it is being run
                installed_to_home_screen: !!(nav.standalone || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)),
                secure_context: window.isSecureContext,
                screen: (window.screen ? (window.screen.width + 'x' + window.screen.height) : null),
                viewport: window.innerWidth + 'x' + window.innerHeight,
                pixel_ratio: window.devicePixelRatio || null,
                orientation: (window.screen && window.screen.orientation && window.screen.orientation.type) || null,
                dark_mode: !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches),
                reduced_motion: !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches),

                // Time - a wrong clock breaks token expiry and looks like nothing else
                timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone || null),
                device_time: new Date().toISOString(),

                // Network
                online: nav.onLine,
            };

            const conn = nav.connection || nav.mozConnection || nav.webkitConnection;
            if (conn) {
                out.network_type = conn.effectiveType || conn.type || null;
                out.downlink_mbps = conn.downlink || null;
                out.rtt_ms = conn.rtt || null;
                out.save_data = !!conn.saveData;
            }

            // Battery: Chrome on Android has it, iOS does not. Its absence is
            // itself worth recording rather than a silent gap.
            try {
                if (nav.getBattery) {
                    const b = await nav.getBattery();
                    out.battery_percent = Math.round(b.level * 100);
                    out.battery_charging = b.charging;
                } else {
                    out.battery_percent = 'unsupported';
                }
            } catch (e) { out.battery_percent = 'error'; }

            // What the browser will allow, without asking for anything
            try {
                if (nav.permissions && nav.permissions.query) {
                    const names = ['geolocation', 'notifications', 'camera', 'persistent-storage'];
                    for (const name of names) {
                        try {
                            const st = await nav.permissions.query({ name: name });
                            out['permission_' + name.replace('-', '_')] = st.state;
                        } catch (e) { out['permission_' + name.replace('-', '_')] = 'unqueryable'; }
                    }
                }
            } catch (e) {}

            // Where it is - only if already permitted, never prompting
            try {
                if (out.permission_geolocation === 'granted' && nav.geolocation) {
                    const pos = await new Promise((res, rej) => {
                        nav.geolocation.getCurrentPosition(res, rej, { timeout: 8000, maximumAge: 60000 });
                    });
                    out.gps_lat = pos.coords.latitude;
                    out.gps_lng = pos.coords.longitude;
                    out.gps_accuracy_m = Math.round(pos.coords.accuracy);
                    out.gps_age_ms = Date.now() - pos.timestamp;
                }
            } catch (e) {
                out.gps_error_code = e && e.code;
                out.gps_error_message = e && e.message;
            }

            // Storage - a full or evicted store is why offline orders vanish
            try {
                if (nav.storage && nav.storage.estimate) {
                    const est = await nav.storage.estimate();
                    out.storage_used_mb = Math.round((est.usage || 0) / 1048576);
                    out.storage_quota_mb = Math.round((est.quota || 0) / 1048576);
                }
                if (nav.storage && nav.storage.persisted) {
                    out.storage_persisted = await nav.storage.persisted();
                }
            } catch (e) {}

            // Service worker and caches - stale code is a whole class of "it
            // works for me" that this answers in one line.
            try {
                if (nav.serviceWorker) {
                    const reg = await nav.serviceWorker.getRegistration();
                    out.service_worker = reg ? (reg.active ? 'active' : (reg.installing ? 'installing' : 'waiting')) : 'none';
                    out.service_worker_scope = reg ? reg.scope : null;
                    out.service_worker_waiting = !!(reg && reg.waiting);
                }
                if (window.caches && caches.keys) {
                    out.cache_names = (await caches.keys()).join(',');
                }
            } catch (e) {}

            // Queued offline work, counted not read - the contents are orders
            try {
                if (window.SubsalesStorage && typeof window.SubsalesStorage.allQueuedOps === 'function') {
                    const ops = await window.SubsalesStorage.allQueuedOps();
                    out.queued_operations = Array.isArray(ops) ? ops.length : null;
                }
            } catch (e) {}

            this.log('device', 'Device snapshot (' + out.reason + ')', out);
        },

        // One snapshot immediately, then every five minutes while watched: a
        // battery draining, a network degrading or a permission being revoked
        // mid-shift are exactly the things a single snapshot at login misses.
        startSnapshots(reason) {
            if (!this.debugEnabled || this._snapshotTimer) { return; }
            this.snapshot(reason);
            this._snapshotTimer = setInterval(() => {
                if (this.debugEnabled) { this.snapshot('periodic'); } else { this.stopSnapshots(); }
            }, 300000);
        },

        stopSnapshots() {
            if (this._snapshotTimer) { clearInterval(this._snapshotTimer); this._snapshotTimer = null; }
        },

        // Anything held from before logging was known to be on, oldest first.
        flushPending() {
            if (!this.debugEnabled || !this.initialized || !this._pending.length) { return; }
            const held = this._pending.splice(0, this._pending.length);
            held.forEach(e => this._send(e.category, e.message,
                Object.assign({}, e.context, { held_before_logging_was_on: true, happened_at: e.held_at })));
        },

        _send(category, message, context = {}) {
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

            // Called from the page-load bootstrap and again from whichever login
            // handler runs, so it has to be idempotent - a second set of
            // listeners would log every tap twice.
            if (this._uiInstrumented) {
                return;
            }
            this._uiInstrumented = true;

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
