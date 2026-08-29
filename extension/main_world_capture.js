(() => {
  // Runs in the page's own JS context ("world": "MAIN" in manifest.json) so
  // it can see the real window.fetch/XMLHttpRequest/console — chrome.* APIs
  // are unavailable here, so captured events are handed to content_script.js
  // via postMessage, which relays them into the normal recording pipeline.

  if (window.__sorifyMainWorldInjected) return;
  window.__sorifyMainWorldInjected = true;

  // Whether a recording is actually in progress. Relayed from the content
  // script (which can read chrome.storage) via postMessage. When false, the
  // patched fetch/XHR/console.error stay inert — no postMessage, no payload
  // building — so the page pays no recording overhead outside a session.
  let isRecording = false;

  // Announce readiness so the content script pushes us the current flag.
  window.postMessage({ source: "sorify-recorder-main", payload: { ready: true } }, "*");

  function post(event_type, fields) {
    if (!isRecording) return;
    window.postMessage({ source: "sorify-recorder-main", payload: { event_type, fields } }, "*");
  }

  // Receive the recording flag (and changes) from the content script.
  window.addEventListener("message", (e) => {
    if (e.source !== window) return;
    const data = e.data;
    if (!data || data.source !== "sorify-recorder-content" || !data.payload) return;
    if (typeof data.payload.recording === "boolean") {
      isRecording = data.payload.recording;
    }
  });

  function safeStringify(value) {
    try {
      return JSON.stringify(value);
    } catch {
      return String(value);
    }
  }

  const originalFetch = window.fetch;
  if (originalFetch) {
    window.fetch = function (input, init) {
      const start = performance.now();
      const method = (init && init.method) || (input && input.method) || "GET";
      const url = typeof input === "string" ? input : input && input.url;
      return originalFetch.apply(this, arguments).then(
        (response) => {
          post("network_request", {
            method,
            request_url: url,
            status: response.status,
            ok: response.ok,
            duration_ms: Math.round(performance.now() - start),
          });
          return response;
        },
        (err) => {
          post("network_request", {
            method,
            request_url: url,
            status: null,
            ok: false,
            duration_ms: Math.round(performance.now() - start),
          });
          throw err;
        },
      );
    };
  }

  const originalOpen = XMLHttpRequest.prototype.open;
  const originalSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url, ...rest) {
    this.__sorifyMethod = method;
    this.__sorifyUrl = url;
    return originalOpen.call(this, method, url, ...rest);
  };
  XMLHttpRequest.prototype.send = function (...args) {
    const start = performance.now();
    this.addEventListener("loadend", () => {
      post("network_request", {
        method: this.__sorifyMethod,
        request_url: this.__sorifyUrl,
        status: this.status,
        ok: this.status >= 200 && this.status < 400,
        duration_ms: Math.round(performance.now() - start),
      });
    });
    return originalSend.apply(this, args);
  };

  window.addEventListener("error", (e) => {
    post("page_error", { message: e.message, filename: e.filename, lineno: e.lineno });
  });

  window.addEventListener("unhandledrejection", (e) => {
    const reason = e.reason;
    post("page_error", {
      message: reason && reason.message ? reason.message : String(reason),
      filename: null,
      lineno: null,
    });
  });

  const originalConsoleError = console.error;
  console.error = function (...args) {
    post("console_error", {
      message: args.map((a) => (typeof a === "string" ? a : safeStringify(a))).join(" "),
      stack: null,
    });
    return originalConsoleError.apply(this, args);
  };
})();
