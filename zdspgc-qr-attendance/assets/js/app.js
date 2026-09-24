/* ============================================================
   app.js — shared UI behaviour for every signed-in page.

   Keeps the HTML thin: confirm dialogs, client-side table filtering,
   print buttons, copy-to-clipboard, auto-refreshing counters.
   Everything security-related still happens server-side in PHP.
   ============================================================ */
(function (global) {
  "use strict";

  function onReady(fn) {
    if (document.readyState !== "loading") { fn(); }
    else { document.addEventListener("DOMContentLoaded", fn); }
  }

  /** Ask before destructive actions: <form data-confirm="message"> */
  function wireConfirms() {
    document.querySelectorAll("form[data-confirm]").forEach(function (form) {
      form.addEventListener("submit", function (event) {
        if (!global.confirm(form.getAttribute("data-confirm"))) {
          event.preventDefault();
        }
      });
    });
    document.querySelectorAll("a[data-confirm]").forEach(function (link) {
      link.addEventListener("click", function (event) {
        if (!global.confirm(link.getAttribute("data-confirm"))) { event.preventDefault(); }
      });
    });
  }

  /** Live filter box: <input data-filter-table="#tableId"> */
  function wireFilters() {
    document.querySelectorAll("[data-filter-table]").forEach(function (input) {
      var table = document.querySelector(input.getAttribute("data-filter-table"));
      if (!table) { return; }
      input.addEventListener("input", function () {
        var needle = input.value.toLowerCase().trim();
        var shown = 0;
        table.querySelectorAll("tbody tr").forEach(function (row) {
          var hit = row.textContent.toLowerCase().indexOf(needle) !== -1;
          row.style.display = hit ? "" : "none";
          if (hit) { shown++; }
        });
        var counter = document.querySelector(input.getAttribute("data-filter-count") || "");
        if (counter) { counter.textContent = shown + " shown"; }
      });
    });
  }

  /** Toggle a panel: <button data-toggle="#id"> */
  function wireToggles() {
    document.querySelectorAll("[data-toggle]").forEach(function (button) {
      button.addEventListener("click", function () {
        var target = document.querySelector(button.getAttribute("data-toggle"));
        if (!target) { return; }
        var hidden = target.classList.toggle("hidden");
        button.setAttribute("aria-expanded", hidden ? "false" : "true");
      });
    });
  }

  function wireCopy() {
    document.querySelectorAll("[data-copy]").forEach(function (button) {
      button.addEventListener("click", function () {
        var value = button.getAttribute("data-copy");
        if (!global.navigator.clipboard) { return; }
        global.navigator.clipboard.writeText(value).then(function () {
          var old = button.getAttribute("data-label") || button.textContent;
          button.textContent = "Copied!";
          global.setTimeout(function () { button.textContent = old; }, 1200);
        });
      });
    });
  }

  function wirePrint() {
    document.querySelectorAll("[data-print]").forEach(function (button) {
      button.addEventListener("click", function () { global.print(); });
    });
  }

  /** Fill datetime-local inputs with a sensible default when empty. */
  function wireDateTimeDefaults() {
    document.querySelectorAll("input[data-default-hours]").forEach(function (input) {
      if (input.value) { return; }
      var hours = parseFloat(input.getAttribute("data-default-hours") || "0");
      var base = new Date(Date.now() + hours * 3600 * 1000);
      var pad = function (n) { return String(n).padStart(2, "0"); };
      input.value = base.getFullYear() + "-" + pad(base.getMonth() + 1) + "-" + pad(base.getDate()) +
        "T" + pad(base.getHours()) + ":" + pad(base.getMinutes());
    });
  }

  /** Auto-dismiss flash messages after a while. */
  function wireFlashes() {
    document.querySelectorAll(".flash:not(.login-lockout)").forEach(function (flash) {
      global.setTimeout(function () {
        flash.style.transition = "opacity .4s";
        flash.style.opacity = "0";
        global.setTimeout(function () { flash.remove(); }, 400);
      }, 6000);
    });
  }

  /** Responsive sidebar: off-canvas drawer on small screens. */
  function wireSidebar() {
    var body = document.body;
    if (!document.querySelector(".sidebar")) { return; }
    var menuBtn = document.getElementById("menu-btn");
    var backdrop = document.getElementById("sidebar-backdrop");

    function setExpanded(open) {
      if (menuBtn) { menuBtn.setAttribute("aria-expanded", open ? "true" : "false"); }
    }
    function closeNav() {
      body.classList.remove("nav-open");
      setExpanded(false);
    }
    if (menuBtn) {
      menuBtn.addEventListener("click", function () {
        var open = body.classList.toggle("nav-open");
        setExpanded(open);
      });
    }
    if (backdrop) { backdrop.addEventListener("click", closeNav); }
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") { closeNav(); }
    });
    // Navigating from the drawer should close it.
    document.querySelectorAll(".sidebar a").forEach(function (link) {
      link.addEventListener("click", closeNav);
    });
    // Growing the viewport past the drawer breakpoint must clear the lock.
    var wide = global.matchMedia("(min-width: 961px)");
    var onChange = function (event) { if (event.matches) { closeNav(); } };
    if (wide.addEventListener) { wide.addEventListener("change", onChange); }
    else if (wide.addListener) { wide.addListener(onChange); }
  }

  /** Count down a server-enforced login lockout and refresh when it expires. */
  function wireLoginLockout() {
    var notice = document.querySelector("[data-lockout-seconds]");
    if (!notice) { return; }
    var remaining = Math.max(0, parseInt(notice.getAttribute("data-lockout-seconds"), 10) || 0);
    var timer = notice.querySelector("[data-lockout-timer]");
    if (!timer) { return; }

    function render() {
      var minutes = Math.floor(remaining / 60);
      var seconds = remaining % 60;
      timer.textContent = minutes + ":" + String(seconds).padStart(2, "0");
      if (remaining <= 0) {
        global.location.reload();
      } else {
        global.setTimeout(function () { remaining -= 1; render(); }, 1000);
      }
    }
    render();
  }

  onReady(function () {
    wireConfirms();
    wireFilters();
    wireToggles();
    wireCopy();
    wirePrint();
    wireDateTimeDefaults();
    wireFlashes();
    wireSidebar();
    wireLoginLockout();
  });

  global.ZDSPGC = { onReady: onReady };
})(window);
