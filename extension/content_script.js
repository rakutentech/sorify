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

  function send(type, el, extra) {
    chrome.runtime
      .sendMessage({
        action: "capture_event",
        event: {
          type,
          url: location.href,
          timestamp: Date.now(),
          ...describe(el),
          ...extra,
        },
      })
      .then(() => console.debug("[sorify-recorder] sent", type))
      .catch((err) => console.debug("[sorify-recorder] send failed", type, err));
  }

  console.debug("[sorify-recorder] content script loaded on", location.href);

  document.addEventListener("click", (e) => send("click", e.target), true);
  document.addEventListener("submit", (e) => send("submit", e.target), true);

  document.addEventListener(
    "change",
    (e) => {
      const el = e.target;
      const isPassword = el.type === "password";
      send("change", el, { value: isPassword ? "***redacted***" : el.value });
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
      }
    },
    true,
  );

  let lastUrl = location.href;
  new MutationObserver(() => {
    if (location.href !== lastUrl) {
      lastUrl = location.href;
      chrome.runtime
        .sendMessage({
          action: "capture_event",
          event: { type: "navigation", url: location.href, timestamp: Date.now() },
        })
        .catch(() => {});
    }
  }).observe(document, { subtree: true, childList: true });
})();
