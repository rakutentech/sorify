const DEFAULT_PORT = 7420;

let ws = null;
let reconnectTimer = null;
let status = { connected: false, active_session_id: null, event_count: 0 };

// Track unique top-frame hostnames visited during an active recording session.
// Used to snapshot cookies for all relevant domains when recording stops.
const visitedDomains = new Set();
let isRecording = false;

function wsUrl() {
  return `ws://127.0.0.1:${DEFAULT_PORT}`;
}

function broadcastToPopup() {
  chrome.runtime.sendMessage({ action: "status_update", status }).catch(() => {});
}

function scheduleReconnect() {
  if (reconnectTimer) return;
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    connect();
  }, 3000);
}

function connect() {
  if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;

  try {
    ws = new WebSocket(wsUrl());
  } catch {
    scheduleReconnect();
    return;
  }

  ws.onopen = () => {
    status.connected = true;
    ws.send(JSON.stringify({ type: "hello" }));
    broadcastToPopup();
  };

  ws.onmessage = (evt) => {
    try {
      const msg = JSON.parse(evt.data);
      if (msg.type === "status") {
        status = {
          connected: true,
          active_session_id: msg.activeSessionId ?? null,
          event_count: msg.eventCount ?? 0,
        };
        broadcastToPopup();
      }
    } catch {
      // ignore malformed frames
    }
  };

  ws.onclose = () => {
    ws = null;
    status.connected = false;
    broadcastToPopup();
    scheduleReconnect();
  };

  ws.onerror = () => {
    // onclose fires next; reconnect handled there
  };
}

function disconnect() {
  if (ws) ws.close();
  clearTimeout(reconnectTimer);
  reconnectTimer = null;
}

function sendEvent(event) {
  if (!ws || ws.readyState !== WebSocket.OPEN) {
    console.debug("[sorify-recorder] dropped event, socket not open:", event.type);
    return;
  }
  ws.send(JSON.stringify({ type: "event", event }));
  console.debug("[sorify-recorder] forwarded event to bridge:", event.type, event.selector);
}

// --- Cookie capture -------------------------------------------------------
//
// Snapshots cookies for the active tab's domain at recording start (the
// pre-auth baseline) and for all visited domains at recording stop (the
// final authenticated state). Cookie snapshots are sent as separate WS
// frames with type "cookies" and a "phase" of "start" or "stop", written
// to the JSONL by the recorder bridge as {type:"cookies",...} rows.

function extractHostname(url) {
  try {
    return new URL(url).hostname;
  } catch {
    return null;
  }
}

function normalizeCookie(c) {
  // Map chrome.cookies API field names to Playwright's addCookies() shape.
  const out = {
    name: c.name,
    value: c.value,
    domain: c.domain,
    path: c.path || "/",
  };
  if (c.expires && c.expires > 0) out.expires = c.expires;
  if (c.httpOnly) out.httpOnly = true;
  if (c.secure) out.secure = true;
  if (c.sameSite && c.sameSite !== "unspecified") out.sameSite = c.sameSite;
  return out;
}

async function getCookiesForDomain(domain) {
  return new Promise((resolve) => {
    // chrome.cookies.getAll treats domain as a suffix match, so a bare
    // "example.com" also returns cookies set on ".example.com" and
    // sub.example.com — which is exactly what we want.
    chrome.cookies.getAll({ domain }, (cookies) => {
      if (chrome.runtime.lastError) {
        console.debug("[sorify-recorder] cookie getAll error for", domain, chrome.runtime.lastError.message);
        resolve([]);
        return;
      }
      resolve((cookies || []).map(normalizeCookie));
    });
  });
}

async function captureAndSendCookies(phase) {
  let domains = [];
  if (phase === "start") {
    // Snapshot cookies for the active tab's current domain only.
    try {
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (tab && tab.url) {
        const host = extractHostname(tab.url);
        if (host) domains = [host];
      }
    } catch {
      // tabs.query can fail if the window is not available; skip silently.
    }
  } else {
    // Snapshot cookies for all top-frame domains visited during the session.
    domains = [...visitedDomains];
  }

  if (!domains.length) {
    console.debug("[sorify-recorder] no domains to snapshot cookies for (phase:", phase + ")");
    return;
  }

  const cookieMap = new Map();
  for (const domain of domains) {
    const cookies = await getCookiesForDomain(domain);
    for (const c of cookies) {
      // Dedupe by name+domain+path across overlapping domain queries.
      const key = `${c.name}|${c.domain}|${c.path}`;
      cookieMap.set(key, c);
    }
  }

  const cookies = [...cookieMap.values()];
  if (!cookies.length) {
    console.debug("[sorify-recorder] no cookies found for phase:", phase);
    return;
  }

  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({
      type: "cookies",
      phase,
      cookies,
      timestamp: Date.now(),
      domain_count: domains.length,
    }));
    console.debug(`[sorify-recorder] sent ${cookies.length} cookie(s) for ${domains.length} domain(s) (phase: ${phase})`);
  }
}

// --- Per-tab correlation state -------------------------------------------
//
// Navigation (chrome.webNavigation), new-tab (chrome.tabs), and network
// events (relayed from the page via content_script) all happen outside the
// DOM, so they carry no element info of their own. We keep a small ring
// buffer of recent user interactions per tab here — background.js is the
// only piece of this extension that survives a tab's navigation — and stamp
// the nearest prior interaction onto these events as `caused_by`.

const RECENT_INTERACTIONS_LIMIT = 2;
const CAUSED_BY_WINDOW_MS = 5000;
const recentInteractions = new Map(); // tabId -> [{selector, tag_name, text, event_type, timestamp}]
const lastHistoryApiCall = new Map(); // tabId -> {method, timestamp}
const lastNavigationSeen = new Map(); // dedupeKey -> timestamp
const recentNetworkCalls = new Map(); // tabId -> Map(method|url -> timestamps[])

const NAV_DEDUPE_WINDOW_MS = 500;
const NETWORK_DEDUPE_WINDOW_MS = 2000;
const NETWORK_DEDUPE_MAX = 3;
// Hard caps so these Maps can't grow without bound over a long-lived service
// worker (kept awake by the keepalive alarm). lastNavigationSeen keys on
// tabId|frameId|url|navType, so every distinct navigated URL would otherwise
// accumulate an entry for the life of the worker.
const NAV_SEEN_MAX = 500;

function recordInteraction(tabId, event) {
  if (tabId == null) return;
  const list = recentInteractions.get(tabId) || [];
  list.push({
    selector: event.selector,
    tag_name: event.tag_name,
    text: event.text,
    event_type: event.type,
    timestamp: event.timestamp,
  });
  while (list.length > RECENT_INTERACTIONS_LIMIT) list.shift();
  recentInteractions.set(tabId, list);
}

function attachCausedBy(tabId, targetEvent) {
  const list = recentInteractions.get(tabId);
  if (!list || !list.length) return;
  for (let i = list.length - 1; i >= 0; i--) {
    const candidate = list[i];
    if (candidate.timestamp <= targetEvent.timestamp && targetEvent.timestamp - candidate.timestamp <= CAUSED_BY_WINDOW_MS) {
      targetEvent.caused_by = {
        selector: candidate.selector,
        tag_name: candidate.tag_name,
        text: candidate.text,
        event_type: candidate.event_type,
      };
      return;
    }
  }
}

function shouldRateLimitNetwork(tabId, event) {
  const key = `${event.method}|${event.request_url}`;
  const now = event.timestamp || Date.now();
  const tabMap = recentNetworkCalls.get(tabId) || new Map();

  // Prune stale timestamp arrays for any keys older than the window so entries
  // for old URLs don't linger for the life of the tab.
  for (const [k, ts] of tabMap) {
    const fresh = ts.filter((t) => now - t <= NETWORK_DEDUPE_WINDOW_MS);
    if (fresh.length) tabMap.set(k, fresh);
    else tabMap.delete(k);
  }

  const timestamps = (tabMap.get(key) || []).filter((t) => now - t <= NETWORK_DEDUPE_WINDOW_MS);
  timestamps.push(now);
  tabMap.set(key, timestamps);
  recentNetworkCalls.set(tabId, tabMap);
  return timestamps.length > NETWORK_DEDUPE_MAX;
}

function classifyHardNav(transitionType, transitionQualifiers) {
  const qualifiers = transitionQualifiers || [];
  if (qualifiers.includes("forward_back")) return "forward_back";
  if (qualifiers.includes("client_redirect") || qualifiers.includes("server_redirect")) return "redirect";
  switch (transitionType) {
    case "link":
      return "link_click";
    case "typed":
      return "typed_url";
    case "form_submit":
      return "form_submit";
    case "reload":
      return "reload";
    default:
      return transitionType || "other";
  }
}

function historyApiNavType(tabId, timestamp) {
  const last = lastHistoryApiCall.get(tabId);
  if (last && timestamp - last.timestamp <= 200) {
    return last.method === "pushState" ? "pushstate" : "replacestate";
  }
  return "history_api";
}

function handleNavigation(details, navType) {
  const timestamp = Date.now();
  const dedupeKey = `${details.tabId}|${details.frameId}|${details.url}|${navType}`;
  const lastSeen = lastNavigationSeen.get(dedupeKey);
  if (lastSeen && timestamp - lastSeen < NAV_DEDUPE_WINDOW_MS) return;

  // Prune entries older than the dedupe window and enforce a hard cap so the
  // Map can't grow without bound across a long browsing session.
  if (lastNavigationSeen.size > NAV_SEEN_MAX) {
    for (const [key, ts] of lastNavigationSeen) {
      if (timestamp - ts > NAV_DEDUPE_WINDOW_MS) lastNavigationSeen.delete(key);
    }
  }
  lastNavigationSeen.set(dedupeKey, timestamp);

  // Track top-frame hostnames visited during an active recording, for the
  // end-of-session cookie snapshot.
  if (isRecording && details.frameId === 0 && details.url) {
    const host = extractHostname(details.url);
    if (host) visitedDomains.add(host);
  }

  const navEvent = {
    type: "navigation",
    url: details.url,
    timestamp,
    navigation_type: navType,
    is_top_frame: details.frameId === 0,
    frame_url: details.url,
    tab_id: details.tabId,
  };
  attachCausedBy(details.tabId, navEvent);
  sendEvent(navEvent);
}

chrome.webNavigation.onCommitted.addListener((details) => {
  handleNavigation(details, classifyHardNav(details.transitionType, details.transitionQualifiers));
});
chrome.webNavigation.onHistoryStateUpdated.addListener((details) => {
  handleNavigation(details, historyApiNavType(details.tabId, Date.now()));
});
chrome.webNavigation.onReferenceFragmentUpdated.addListener((details) => {
  handleNavigation(details, "hashchange");
});

chrome.tabs.onCreated.addListener((tab) => {
  const navEvent = {
    type: "new_tab_opened",
    timestamp: Date.now(),
    opener_tab_id: tab.openerTabId ?? null,
    new_tab_id: tab.id,
  };
  if (tab.openerTabId != null) attachCausedBy(tab.openerTabId, navEvent);
  sendEvent(navEvent);
});

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status === "complete") {
    sendEvent({
      type: "new_tab_loaded",
      timestamp: Date.now(),
      new_tab_id: tabId,
      new_tab_url: tab.url,
    });
  }
});

chrome.tabs.onRemoved.addListener((tabId) => {
  recentInteractions.delete(tabId);
  lastHistoryApiCall.delete(tabId);
  recentNetworkCalls.delete(tabId);
});

// Keep the service worker waking up periodically so a WS connection dropped
// by MV3's idle-suspend gets re-established without the popup being open.
chrome.alarms.create("sorify-recorder-keepalive", { periodInMinutes: 0.4 });
chrome.alarms.onAlarm.addListener(() => connect());

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.action) {
    case "connect":
      connect();
      sendResponse({ ok: true });
      break;
    case "disconnect":
      disconnect();
      sendResponse({ ok: true });
      break;
    case "start_recording":
      if (ws && ws.readyState === WebSocket.OPEN) {
        visitedDomains.clear();
        isRecording = true;
        chrome.storage.session.set({ sorify_recording: true }).catch(() => {});
        ws.send(JSON.stringify({ type: "start_recording", label: message.label }));
        // Snapshot cookies for the active tab's domain after the session is
        // started by the bridge (so the JSONL session_start row is written
        // first). Fire-and-forget — the WS frame for cookies arrives next.
        captureAndSendCookies("start").catch(() => {});
      }
      sendResponse({ ok: true });
      break;
    case "stop_recording":
      if (ws && ws.readyState === WebSocket.OPEN) {
        // Snapshot cookies for all visited domains before stopping, so the
        // final authenticated state is captured in the JSONL before the
        // session_end row is written.
        captureAndSendCookies("stop")
          .catch(() => {})
          .finally(() => {
            isRecording = false;
            chrome.storage.session.set({ sorify_recording: false }).catch(() => {});
            ws.send(JSON.stringify({ type: "stop_recording" }));
          });
      } else {
        isRecording = false;
        chrome.storage.session.set({ sorify_recording: false }).catch(() => {});
      }
      sendResponse({ ok: true });
      break;
    case "get_status":
      sendResponse(status);
      break;
    case "history_api_call": {
      const tabId = sender.tab ? sender.tab.id : null;
      if (tabId != null) {
        lastHistoryApiCall.set(tabId, { method: message.method, timestamp: Date.now() });
      }
      sendResponse({ ok: true });
      break;
    }
    case "capture_event": {
      const tabId = sender.tab ? sender.tab.id : null;
      const event = message.event;
      event.tab_id = tabId;

      const isInteraction =
        event.type === "click" || event.type === "submit" || (event.type === "keydown" && event.key === "Enter");
      if (isInteraction) recordInteraction(tabId, event);

      if (event.type === "network_request") {
        if (shouldRateLimitNetwork(tabId, event)) {
          sendResponse({ ok: true, dropped: true });
          break;
        }
        attachCausedBy(tabId, event);
      }

      sendEvent(event);
      sendResponse({ ok: true });
      break;
    }
    default:
      sendResponse({ ok: false, error: "unknown_action" });
  }
  return true;
});

connect();
