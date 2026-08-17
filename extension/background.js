const DEFAULT_PORT = 7420;

let ws = null;
let reconnectTimer = null;
let status = { connected: false, active_session_id: null, event_count: 0 };

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
  lastNavigationSeen.set(dedupeKey, timestamp);

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
        ws.send(JSON.stringify({ type: "start_recording", label: message.label }));
      }
      sendResponse({ ok: true });
      break;
    case "stop_recording":
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: "stop_recording" }));
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
