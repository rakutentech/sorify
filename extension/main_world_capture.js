(() => {
  // Runs in the page's own JS context ("world": "MAIN" in manifest.json) so
  // it can see the real window.fetch/XMLHttpRequest/console — chrome.* APIs
  // are unavailable here, so captured events are handed to content_script.js
  // via postMessage, which relays them into the normal recording pipeline.

  if (window.__sorifyMainWorldInjected) return;
  window.__sorifyMainWorldInjected = true;

  function post(event_type, fields) {
    window.postMessage({ source: "sorify-recorder-main", payload: { event_type, fields } }, "*");
  }

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
