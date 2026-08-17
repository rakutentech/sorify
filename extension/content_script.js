(() => {
  function cssEscape(value) {
    return String(value).replace(/([ #.;?%&,+*~':"!^$[\]()=>|/\\])/g, "\\$1");
  }

  // Auto-generated / utility class names are unstable across builds
  // (css-in-js hashes, atomic utility classes) and make a bad selector
  // discriminator — filter them out before using classes in cssPath.
  function isDynamicClass(cls) {
    return (
      /^(css|sc|jsx|emotion)-/.test(cls) ||
      /[0-9a-f]{5,}/i.test(cls) ||
      /^[a-z]{1,2}[0-9]*$/.test(cls)
    );
  }

  function stableClasses(el) {
    return Array.from(el.classList).filter((c) => !isDynamicClass(c)).slice(0, 3);
  }

  function cssPath(el) {
    const parts = [];
    let node = el;
    let steps = 0;
    while (node && node.nodeType === 1 && steps < 6) {
      // Anchor the walk on the nearest ancestor with a stable identifier
      // instead of always climbing a fixed number of levels — this keeps
      // paths short and meaningful instead of spanning irrelevant wrapper
      // divs all the way toward <body>.
      if (node !== el) {
        const testId =
          node.getAttribute("data-testid") ||
          node.getAttribute("data-test-id") ||
          node.getAttribute("data-test");
        if (testId) {
          parts.unshift(`[data-testid="${testId}"]`);
          break;
        }
        if (node.id) {
          parts.unshift(`#${cssEscape(node.id)}`);
          break;
        }
      }

      let part = node.tagName.toLowerCase();
      const classes = stableClasses(node);
      if (classes.length) part += "." + classes.map(cssEscape).join(".");

      const parent = node.parentElement;
      if (parent) {
        const siblings = Array.from(parent.children).filter((c) => c.tagName === node.tagName);
        if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(node) + 1})`;
      }
      parts.unshift(part);
      node = parent;
      steps++;
    }
    return parts.join(" > ");
  }

  const TEXTUAL_TAGS = ["BUTTON", "A", "LABEL", "SUMMARY", "OPTION", "LI", "TH"];

  const CAPTURED_ATTRS = [
    "id",
    "class",
    "name",
    "type",
    "placeholder",
    "title",
    "alt",
    "href",
    "role",
    "aria-label",
    "disabled",
    "checked",
  ];

  function collectAttributes(el) {
    const attrs = {};
    for (const name of CAPTURED_ATTRS) {
      const val = el.getAttribute(name);
      if (val !== null) attrs[name] = val;
    }
    return attrs;
  }

  function boundingInfo(el) {
    try {
      const rect = el.getBoundingClientRect();
      const style = window.getComputedStyle(el);
      return {
        bounding_rect: {
          x: Math.round(rect.x),
          y: Math.round(rect.y),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
        },
        is_visible:
          rect.width > 0 &&
          rect.height > 0 &&
          style.display !== "none" &&
          style.visibility !== "hidden",
      };
    } catch {
      return { bounding_rect: null, is_visible: null };
    }
  }

  function matchedCount(selector) {
    try {
      return document.querySelectorAll(selector).length;
    } catch {
      return null;
    }
  }

  function describe(el) {
    if (!el || el.nodeType !== 1) {
      return {
        selector: null,
        selector_strategy: null,
        tag_name: null,
        text: null,
        attributes: null,
        bounding_rect: null,
        is_visible: null,
        matched_count: null,
        outer_html_snippet: null,
      };
    }

    const testId =
      el.getAttribute("data-testid") ||
      el.getAttribute("data-test-id") ||
      el.getAttribute("data-test");
    const text = (el.innerText || el.textContent || "").trim().slice(0, 80);
    const role = el.getAttribute("role");
    const tag = el.tagName.toLowerCase();
    const escapedText = text.replace(/"/g, '\\"');

    let selector;
    let strategy;
    if (testId) {
      selector = `[data-testid="${testId}"]`;
      strategy = "testid";
    } else if (el.id) {
      selector = `#${cssEscape(el.id)}`;
      strategy = "id";
    } else if (el.getAttribute("name")) {
      selector = `${tag}[name="${el.getAttribute("name")}"]`;
      strategy = "name";
    } else if (el.getAttribute("placeholder")) {
      selector = `${tag}[placeholder="${el.getAttribute("placeholder")}"]`;
      strategy = "placeholder";
    } else if (el.getAttribute("aria-label")) {
      selector = `[aria-label="${el.getAttribute("aria-label")}"]`;
      strategy = "aria-label";
    } else if (role) {
      selector = text ? `[role="${role}"]:has-text("${escapedText}")` : `[role="${role}"]`;
      strategy = "role";
    } else if (tag === "img" && el.getAttribute("alt")) {
      selector = `img[alt="${el.getAttribute("alt")}"]`;
      strategy = "alt";
    } else if (text && TEXTUAL_TAGS.includes(el.tagName)) {
      selector = `${tag}:has-text("${escapedText}")`;
      strategy = "text";
    } else if (tag === "a" && el.getAttribute("href")) {
      selector = `a[href="${el.getAttribute("href").replace(/"/g, '\\"')}"]`;
      strategy = "href";
    } else if (el.getAttribute("title")) {
      selector = `[title="${el.getAttribute("title")}"]`;
      strategy = "title";
    } else {
      selector = cssPath(el);
      strategy = "css";
    }

    return {
      selector,
      selector_strategy: strategy,
      tag_name: tag,
      text: text || null,
      attributes: collectAttributes(el),
      ...boundingInfo(el),
      matched_count: matchedCount(selector),
      outer_html_snippet: el.outerHTML.slice(0, 200),
    };
  }

  const IS_TOP_FRAME = window === window.top;

  function send(type, el, extra) {
    chrome.runtime
      .sendMessage({
        action: "capture_event",
        event: {
          type,
          url: location.href,
          timestamp: Date.now(),
          frame_url: location.href,
          is_top_frame: IS_TOP_FRAME,
          ...describe(el),
          ...extra,
        },
      })
      .then(() => console.debug("[sorify-recorder] sent", type))
      .catch((err) => console.debug("[sorify-recorder] send failed", type, err));
  }

  console.debug("[sorify-recorder] content script loaded on", location.href);

  // --- Assertion/DOM-diff hints --------------------------------------------
  //
  // After a user action, buffer for a short window and diff what changed in
  // the DOM (toasts, modals, error banners, title/url) so the recording can
  // drive real expect() assertions later instead of only replaying actions.

  const ASSERTION_WINDOW_MS = 800;
  let pendingAssertion = null;
  let assertionTimer = null;

  function isTextBearing(el) {
    if (!el || el.nodeType !== 1) return false;
    return (el.innerText || el.textContent || "").trim().length > 0;
  }

  function describeCandidate(el) {
    const d = describe(el);
    return { selector: d.selector, text: d.text, tag_name: d.tag_name };
  }

  function maybeTrackAppeared(node) {
    if (!pendingAssertion || !isTextBearing(node)) return;
    if (!boundingInfo(node).is_visible) return;
    pendingAssertion.appeared.set(node, describeCandidate(node));
  }

  function maybeTrackDisappeared(node) {
    if (!pendingAssertion || !isTextBearing(node)) return;
    pendingAssertion.disappeared.set(node, describeCandidate(node));
  }

  function scheduleAssertionHint(causedBy) {
    if (!pendingAssertion) {
      pendingAssertion = {
        causedBy,
        startTitle: document.title,
        startUrl: location.href,
        appeared: new Map(),
        disappeared: new Map(),
      };
    }
    clearTimeout(assertionTimer);
    assertionTimer = setTimeout(flushAssertionHint, ASSERTION_WINDOW_MS);
  }

  function flushAssertionHint() {
    const pending = pendingAssertion;
    pendingAssertion = null;
    if (!pending) return;

    const appeared = Array.from(pending.appeared.values()).slice(0, 3);
    const disappeared = Array.from(pending.disappeared.values()).slice(0, 3);
    const titleChanged = pending.startTitle !== document.title ? { from: pending.startTitle, to: document.title } : null;
    const urlChanged = pending.startUrl !== location.href ? { from: pending.startUrl, to: location.href } : null;

    if (!appeared.length && !disappeared.length && !titleChanged && !urlChanged) return;

    send("assertion_hint", null, {
      appeared,
      disappeared,
      title_changed: titleChanged,
      url_changed: urlChanged,
      caused_by: pending.causedBy,
    });
  }

  function triggerAssertionWindow(type, el) {
    const d = describe(el);
    scheduleAssertionHint({ selector: d.selector, tag_name: d.tag_name, text: d.text, event_type: type });
  }

  new MutationObserver((mutations) => {
    if (!pendingAssertion) return;
    for (const mutation of mutations) {
      if (mutation.type === "childList") {
        mutation.addedNodes.forEach(maybeTrackAppeared);
        mutation.removedNodes.forEach(maybeTrackDisappeared);
      } else if (mutation.type === "attributes") {
        maybeTrackAppeared(mutation.target);
      }
    }
  }).observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ["style", "class", "hidden"],
  });

  // --- Core action listeners ------------------------------------------------

  document.addEventListener(
    "click",
    (e) => {
      send("click", e.target);
      triggerAssertionWindow("click", e.target);
    },
    true,
  );

  document.addEventListener(
    "submit",
    (e) => {
      send("submit", e.target);
      triggerAssertionWindow("submit", e.target);
    },
    true,
  );

  document.addEventListener(
    "change",
    (e) => {
      const el = e.target;

      if (el.type === "file") {
        const files = Array.from(el.files || []).map((f) => ({ name: f.name, size: f.size, type: f.type }));
        send("change", el, { upload: true, files });
        triggerAssertionWindow("change", el);
        return;
      }

      if (el.tagName === "SELECT") {
        const selected = Array.from(el.selectedOptions || []).map((o) => ({ value: o.value, label: o.text }));
        send("change", el, { select_multiple: el.multiple, selected_options: selected });
        triggerAssertionWindow("change", el);
        return;
      }

      const isPassword = el.type === "password";
      send("change", el, { value: isPassword ? "***redacted***" : el.value });
      triggerAssertionWindow("change", el);
    },
    true,
  );

  const inputTimers = new WeakMap();
  document.addEventListener(
    "input",
    (e) => {
      const el = e.target;
      clearTimeout(inputTimers.get(el));
      const timer = setTimeout(() => {
        const isPassword = el.type === "password";
        send("input", el, { value: isPassword ? "***redacted***" : el.value });
      }, 400);
      inputTimers.set(el, timer);
    },
    true,
  );

  document.addEventListener(
    "keydown",
    (e) => {
      if (["Enter", "Tab", "Escape"].includes(e.key)) {
        send("keydown", e.target, { key: e.key });
        if (e.key === "Enter") triggerAssertionWindow("keydown", e.target);
      }
    },
    true,
  );

  // --- History API navigation signal ----------------------------------------
  //
  // chrome.webNavigation.onHistoryStateUpdated (handled in background.js)
  // can't tell pushState apart from replaceState on its own — this just
  // signals which one fired so background.js can label the resulting
  // navigation event correctly. Actual navigation detection/emission now
  // lives entirely in background.js (chrome.webNavigation), since it's the
  // only piece of this extension that survives the content script being
  // torn down on a hard navigation.

  const _pushState = history.pushState;
  const _replaceState = history.replaceState;
  history.pushState = function (...args) {
    chrome.runtime.sendMessage({ action: "history_api_call", method: "pushState" }).catch(() => {});
    return _pushState.apply(this, args);
  };
  history.replaceState = function (...args) {
    chrome.runtime.sendMessage({ action: "history_api_call", method: "replaceState" }).catch(() => {});
    return _replaceState.apply(this, args);
  };

  // --- Drag and drop ---------------------------------------------------------

  let dragSourceEl = null;
  document.addEventListener("dragstart", (e) => { dragSourceEl = e.target; }, true);
  document.addEventListener(
    "drop",
    (e) => {
      if (dragSourceEl) {
        send("drag_and_drop", e.target, { source: describe(dragSourceEl) });
        triggerAssertionWindow("drag_and_drop", e.target);
      }
      dragSourceEl = null;
    },
    true,
  );
  document.addEventListener("dragend", () => { dragSourceEl = null; }, true);

  // --- Focus -------------------------------------------------------------

  const FOCUSABLE_TAGS = ["INPUT", "TEXTAREA", "SELECT", "BUTTON", "A"];
  document.addEventListener(
    "focus",
    (e) => {
      const el = e.target;
      if (!el || el.nodeType !== 1) return;
      if (!FOCUSABLE_TAGS.includes(el.tagName) && !el.hasAttribute("tabindex")) return;
      send("focus", el);
    },
    true,
  );

  // --- Hover (dwell-debounced so mouse transit doesn't spam events) --------

  const HOVER_DWELL_MS = 350;
  const hoverTimers = new WeakMap();
  document.addEventListener(
    "pointerenter",
    (e) => {
      const el = e.target;
      if (!el || el.nodeType !== 1) return;
      const timer = setTimeout(() => send("hover", el), HOVER_DWELL_MS);
      hoverTimers.set(el, timer);
    },
    true,
  );
  document.addEventListener(
    "pointerleave",
    (e) => {
      const timer = hoverTimers.get(e.target);
      if (timer) {
        clearTimeout(timer);
        hoverTimers.delete(e.target);
      }
    },
    true,
  );

  // --- Bridge from the MAIN-world capture script (network/console) --------
  //
  // main_world_capture.js runs in the page's own JS context (chrome.* APIs
  // are unavailable there), so it hands events to us via postMessage and we
  // relay them through the normal send() pipeline.

  window.addEventListener("message", (e) => {
    if (e.source !== window) return;
    const data = e.data;
    if (!data || data.source !== "sorify-recorder-main" || !data.payload) return;
    const { event_type, fields } = data.payload;
    if (!event_type) return;
    send(event_type, null, fields || {});
  });
})();
