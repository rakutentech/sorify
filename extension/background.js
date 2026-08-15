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

// Keep the service worker waking up periodically so a WS connection dropped
// by MV3's idle-suspend gets re-established without the popup being open.
chrome.alarms.create("sorify-recorder-keepalive", { periodInMinutes: 0.4 });
chrome.alarms.onAlarm.addListener(() => connect());

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
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
    case "capture_event":
      sendEvent(message.event);
      sendResponse({ ok: true });
      break;
    default:
      sendResponse({ ok: false, error: "unknown_action" });
  }
  return true;
});

connect();
