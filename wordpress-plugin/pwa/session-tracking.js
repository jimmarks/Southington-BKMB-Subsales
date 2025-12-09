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
        console.warn('[Session] Heartbeat failed - Status:', resp.status, 'Session:', sessionId);
        if (window.PWALogger) {
          window.PWALogger.log('session', 'Heartbeat failed', {
            session_id: sessionId,
            status: resp.status
          });
        }
      } else {

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
