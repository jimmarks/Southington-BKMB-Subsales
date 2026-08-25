/**
 * PWA Session Tracking Module
 * 
 * Tracks user sessions and activity in the PWA for admin monitoring
 * Sends session start, heartbeat, and end events to WordPress backend
 */

(function() {
  'use strict';
  
  // Generate unique session ID
  function generateSessionId() {
    return 'pwa_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
  }
  
  // Get or create session ID
  function getSessionId() {
    let sessionId = sessionStorage.getItem('pwa_session_id');
    if (!sessionId) {
      sessionId = generateSessionId();
      sessionStorage.setItem('pwa_session_id', sessionId);
    }
    return sessionId;
  }
  
  // Get API base URL
  function getApiBase() {
    return window.apiBase || '/wp-json/order-manager/v1';
  }

  // Cap auto-recreate attempts so a stale/abandoned tab doesn't hammer
  // /pwa-session/start in a loop (e.g. left open overnight with a dead session).
  // 3 minutes: comfortably longer than the 30s heartbeat cadence (so a genuinely
  // missing session gets one recreate try per several heartbeats, not one per heartbeat),
  // short enough that a device that comes back online mid-shift recovers quickly.
  const SESSION_RECREATE_MIN_INTERVAL_MS = 3 * 60 * 1000;
  let lastSessionRecreateAttempt = 0;
  
  // Start PWA session
  async function startSession(userData = {}) {
    const sessionId = getSessionId();
    const url = getApiBase() + '/pwa-session/start';
    
    const payload = {
      sessionId: sessionId,
      userId: userData.userId || null,
      userName: userData.userName || localStorage.getItem('teamMemberName') || '',
      teamId: userData.teamId || localStorage.getItem('teamId') || null,
      teamName: userData.teamName || localStorage.getItem('teamName') || '',
      metadata: {
        userAgent: navigator.userAgent,
        viewport: window.innerWidth + 'x' + window.innerHeight,
        platform: navigator.platform,
        language: navigator.language,
        online: navigator.onLine,
        timestamp: new Date().toISOString()
      }
    };

    try {
      const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (resp.ok) {
        const result = await resp.json();

        return true;
      } else {
        const errorText = await resp.text();
        console.error('[Session] Start failed - Status:', resp.status, 'Response:', errorText);
        return false;
      }
    } catch(e) {
      console.error('[Session] Start error - Network or CORS issue:', e);
      return false;
    }
  }
  
  // Send heartbeat
  async function sendHeartbeat(activity = {}) {
    const sessionId = getSessionId();
    const url = getApiBase() + '/pwa-session/heartbeat';
    
    // Get session expiry from localStorage
    const sessionExpiry = localStorage.getItem('sessionExpiry') || null;
    
    // Get GPS location if available
    let gpsLocation = null;
    if (navigator.geolocation) {
      try {
        // Don't block heartbeat on GPS - use cached position if available
        const position = await new Promise((resolve, reject) => {
          navigator.geolocation.getCurrentPosition(
            resolve,
            reject,
            { timeout: 5000, maximumAge: 60000, enableHighAccuracy: false }
          );
        });
        gpsLocation = {
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
          timestamp: new Date().toISOString()
        };
      } catch (error) {
        // Silently fail - GPS is optional

      }
    }
    
    const payload = {
      sessionId: sessionId,
      activity: activity,
      sessionExpiry: sessionExpiry, // Add expiry time for admin display
      gps: gpsLocation // Add GPS location if available
    };
    
    // Log heartbeat to PWA Logger if available and debug enabled
    if (window.PWALogger && window.PWALogger.debugEnabled) {
      window.PWALogger.log('session', 'Heartbeat sent', {
        session_id: sessionId,
        has_activity: Object.keys(activity).length > 0,
        activity_keys: Object.keys(activity),
        expires_at: sessionExpiry,
        has_gps: gpsLocation !== null,
        gps_accuracy: gpsLocation ? gpsLocation.accuracy : null
      });
    }
    
    try {
      const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      
      if (!resp.ok) {
        // Inspect the error body's code to distinguish "session not found"
        // (server code 'heartbeat_failed', see class-rest-api.php update_pwa_heartbeat())
        // from other errors - only that specific case is worth recreating for.
        let errorCode = null;
        try {
          const errorBody = await resp.json();
          errorCode = errorBody && errorBody.code ? errorBody.code : null;
        } catch (parseErr) {
          // Non-JSON error body - fall through to generic failure handling below.
        }

        if (errorCode === 'heartbeat_failed') {
          const now = Date.now();
          if (now - lastSessionRecreateAttempt < SESSION_RECREATE_MIN_INTERVAL_MS) {
            console.warn('[Session] Heartbeat failed - session not found, but recreated too recently, skipping');
          } else {
            lastSessionRecreateAttempt = now;
            console.warn('[Session] Heartbeat failed - session not found, attempting to recreate session');

            // Get current user data from localStorage
            const userData = {
              userId: localStorage.getItem('userId'),
              userName: localStorage.getItem('userName') || localStorage.getItem('teamMemberName'),
              teamId: localStorage.getItem('selectedTeamId') || localStorage.getItem('teamId'),
              teamName: localStorage.getItem('selectedTeamName') || localStorage.getItem('teamName')
            };

            // Try to recreate session
            const sessionCreated = await startSession(userData);
            if (sessionCreated) {
              console.warn('[Session] Session recreated successfully - retrying original heartbeat');
              if (window.PWALogger) {
                window.PWALogger.log('session', 'Session recreated after heartbeat failure', {
                  session_id: sessionId
                });
              }

              // Re-POST the SAME original heartbeat payload once, so the GPS/activity
              // data it carried isn't silently thrown away now that the session exists again.
              try {
                const retryResp = await fetch(url, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify(payload)
                });
                if (retryResp.ok) {
                  return; // Retry succeeded - done.
                }
                console.warn('[Session] Heartbeat retry after recreate failed - Status:', retryResp.status);
              } catch (retryErr) {
                console.error('[Session] Heartbeat retry after recreate error:', retryErr);
              }
              // Retry didn't succeed - let it drop, same as any other missed heartbeat.
            }
          }
        }

        console.warn('[Session] Heartbeat failed - Status:', resp.status, 'Session:', sessionId);
        if (window.PWALogger) {
          window.PWALogger.log('session', 'Heartbeat failed', {
            session_id: sessionId,
            status: resp.status
          });
        }
      } else {
        // Update PWALogger debug status from heartbeat response
        try {
          const data = await resp.json();
          console.warn('[Session] Heartbeat response:', data);
          
          // Wait for PWALogger to be available (it loads with defer)
          if (typeof data.debugEnabled !== 'undefined') {
            let attempts = 0;
            const maxAttempts = 20; // Max 2 seconds
            const waitForLogger = () => {
              if (window.PWALogger) {
                console.warn('[Session] Updating PWALogger debugEnabled to:', data.debugEnabled);
                window.PWALogger.updateDebugStatus(data.debugEnabled);
              } else if (attempts < maxAttempts) {
                attempts++;
                setTimeout(waitForLogger, 100);
              } else {
                console.warn('[Session] PWALogger never loaded after 2 seconds, giving up');
              }
            };
            waitForLogger();
          } else {
            console.warn('[Session] No debugEnabled in response');
          }
        } catch (e) {
          console.error('[Session] Error parsing heartbeat response:', e);
        }
      }
    } catch(e) {
      console.error('[Session] Heartbeat error:', e);
      if (window.PWALogger) {
        window.PWALogger.logError('session', 'Heartbeat error', e);
      }
    }
  }
  
  // End PWA session
  async function endSession() {
    const sessionId = getSessionId();
    const url = getApiBase() + '/pwa-session/end';
    
    const payload = {
      sessionId: sessionId
    };
    
    try {
      const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      
      if (resp.ok) {

        sessionStorage.removeItem('pwa_session_id');
        return true;
      } else {
        console.warn('[Session] End failed:', await resp.text());
        return false;
      }
    } catch(e) {
      console.error('[Session] End error:', e);
      return false;
    }
  }
  
  // Track activity event
  function trackActivity(action, details = {}) {
    const activity = {
      action: action,
      details: details,
      timestamp: new Date().toISOString()
    };
    
    // Store activity for next heartbeat (debounced)
    const pending = sessionStorage.getItem('pwa_pending_activity');
    const activities = pending ? JSON.parse(pending) : [];
    activities.push(activity);
    
    // Keep only last 10 activities
    if (activities.length > 10) {
      activities.shift();
    }
    
    sessionStorage.setItem('pwa_pending_activity', JSON.stringify(activities));
    
    // Debounced heartbeat (send after 2 seconds of inactivity)
    clearTimeout(window._pwaHeartbeatTimeout);
    window._pwaHeartbeatTimeout = setTimeout(() => {
      const toSend = sessionStorage.getItem('pwa_pending_activity');
      if (toSend) {
        sessionStorage.removeItem('pwa_pending_activity');
        sendHeartbeat({ events: JSON.parse(toSend) });
      }
    }, 2000);
  }
  
  // Auto-heartbeat every 30 seconds
  function startAutoHeartbeat() {
    if (window._pwaHeartbeatInterval) {
      clearInterval(window._pwaHeartbeatInterval);
    }

    
    window._pwaHeartbeatInterval = setInterval(() => {
      sendHeartbeat({ type: 'auto' });
    }, 30000); // 30 seconds
  }
  
  // Stop auto-heartbeat
  function stopAutoHeartbeat() {
    if (window._pwaHeartbeatInterval) {
      clearInterval(window._pwaHeartbeatInterval);
      window._pwaHeartbeatInterval = null;
    }
  }
  
  // Listen for page visibility changes (tab switching, minimizing)
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      trackActivity('page_hidden');
    } else {
      trackActivity('page_visible');
    }
  });
  
  // Listen for online/offline
  window.addEventListener('online', () => {
    trackActivity('online');
  });
  
  window.addEventListener('offline', () => {
    trackActivity('offline');
  });
  
  // Send final heartbeat before unload
  window.addEventListener('beforeunload', () => {
    // Use sendBeacon for reliable delivery
    const sessionId = getSessionId();
    const url = getApiBase() + '/pwa-session/heartbeat';
    const payload = JSON.stringify({
      sessionId: sessionId,
      activity: { action: 'page_unload', timestamp: new Date().toISOString() }
    });
    
    if (navigator.sendBeacon) {
      navigator.sendBeacon(url, payload);
    }
  });
  
  // Export to window
  window.PWASessionTracking = {
    start: startSession,
    end: endSession,
    track: trackActivity,
    heartbeat: sendHeartbeat,
    startAutoHeartbeat: startAutoHeartbeat,
    stopAutoHeartbeat: stopAutoHeartbeat,
    getSessionId: getSessionId
  };

})();
