/* ============================================================
   qr.js — client-side QR rendering helper.
   Wraps the vendored qrcode-generator library (MIT, © Kazuhiko Arase)
   so PHP can simply print:  <div class="qr" data-qr="TOKEN"></div>

   The token itself is produced and signed by PHP — this file only draws it.
   ============================================================ */
(function (global) {
  "use strict";

  function makeQr(text, size) {
    size = size || 4;
    if (typeof global.qrcode !== "function") {
      return null;
    }
    // type 0 = auto-select the smallest version that fits the payload.
    var qr = global.qrcode(0, "M");
    qr.addData(text, "Byte");
    qr.make();
    return qr;
  }

  /** Render one element's data-qr payload into a canvas. */
  function renderElement(el) {
    var text = el.getAttribute("data-qr");
    if (!text) { return; }
    var qr = makeQr(text);
    if (!qr) { return; }

    var modules = qr.getModuleCount();
    var cell = parseInt(el.getAttribute("data-qr-cell") || "6", 10);
    var quiet = 2; // quiet zone in modules (spec requires 4; 2 stays readable and compact)
    var px = (modules + quiet * 2) * cell;

    var canvas = el.querySelector("canvas");
    if (!canvas) {
      canvas = document.createElement("canvas");
      el.appendChild(canvas);
    }
    canvas.width = px;
    canvas.height = px;
    canvas.setAttribute("role", "img");
    canvas.setAttribute("aria-label", "QR code");
    canvas.dataset.qrText = text;

    var ctx = canvas.getContext("2d");
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, px, px);
    ctx.fillStyle = "#0d1b2a";
    for (var r = 0; r < modules; r++) {
      for (var c = 0; c < modules; c++) {
        if (qr.isDark(r, c)) {
          ctx.fillRect((c + quiet) * cell, (r + quiet) * cell, cell, cell);
        }
      }
    }
    el.classList.add("qr-ready");
  }


  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }

  function renderAll(root) {
    (root || document).querySelectorAll("[data-qr]").forEach(renderElement);
  }


  function buttonStatus(button, message, success) {
    var label = button ? button.querySelector("span") : null;
    if (!button || !label) { return; }
    if (!button.dataset.originalLabel) { button.dataset.originalLabel = label.textContent; }
    label.textContent = message;
    button.classList.toggle("copied", success !== false);
    global.clearTimeout(button._statusTimer);
    button._statusTimer = global.setTimeout(function () {
      label.textContent = button.dataset.originalLabel;
      button.classList.remove("copied");
    }, 1800);
  }

  function resolveQr(button) {
    if (!button) { return null; }
    var targetId = button.getAttribute("data-save-qr-target") || button.getAttribute("data-print-qr-target");
    if (targetId) {
      var explicit = document.getElementById(targetId);
      if (explicit) { return explicit; }
    }
    return button.closest("[data-qr]");
  }

  function buttonBusy(button, message) {
    var label = button.querySelector("span");
    if (!label) { return; }
    if (!button.dataset.originalLabel) { button.dataset.originalLabel = label.textContent; }
    label.textContent = message;
    button.disabled = true;
  }

  function buttonRelease(button, message) {
    var label = button.querySelector("span");
    button.disabled = false;
    if (!label) { return; }
    label.textContent = message || button.dataset.originalLabel || label.textContent;
    button.classList.add("copied");
  }

  /** Save the rendered QR canvas directly to the browser Downloads folder. */
  function download(button) {
    var holder = resolveQr(button);
    var canvas = holder ? holder.querySelector("canvas") : null;
    if (!holder || !canvas) {
      buttonStatus(button, "QR is still loading", false);
      return;
    }

    var name = ((holder.getAttribute("data-qr-name") || "qr-code").replace(/[^a-z0-9_-]+/gi, "-") || "qr-code") + ".png";
    buttonBusy(button, "Saving QR…");

    // Keep this as a direct download: never invoke the mobile share sheet here.
    var downloadUrl = function (href, revoke) {
      var link = document.createElement("a");
      link.download = name;
      link.rel = "noopener";
      link.href = href;
      link.style.display = "none";
      document.body.appendChild(link);
      link.click();
      link.remove();
      if (revoke) { global.setTimeout(function () { global.URL.revokeObjectURL(href); }, 5000); }
      buttonRelease(button, "Saved to Downloads");
    };

    if (canvas.toBlob) {
      canvas.toBlob(function (blob) {
        if (blob) { downloadUrl(global.URL.createObjectURL(blob), true); }
        else { downloadUrl(canvas.toDataURL("image/png"), false); }
      }, "image/png");
    } else {
      downloadUrl(canvas.toDataURL("image/png"), false);
    }
  }

  function printQr(button) {
    var holder = resolveQr(button);
    var text = holder ? holder.getAttribute("data-qr") : "";
    var canvas = holder ? holder.querySelector("canvas") : null;
    if (!holder || !text || !canvas) {
      buttonStatus(button, "QR is still loading", false);
      return;
    }

    buttonBusy(button, "Opening print view…");
    document.body.classList.add("printing-qr");
    Array.prototype.forEach.call(document.querySelectorAll("[data-print-qr]"), function (item) {
      item.classList.toggle("qr-print-selected", item === holder);
    });

    var finished = false;
    var cleanup = function () {
      if (finished) { return; }
      finished = true;
      document.body.classList.remove("printing-qr");
      var selected = document.querySelector(".qr-print-selected");
      if (selected) { selected.classList.remove("qr-print-selected"); }
      buttonRelease(button, "Print view ready");
      global.setTimeout(function () {
        if (button.dataset.originalLabel) {
          var label = button.querySelector("span");
          if (label) { label.textContent = button.dataset.originalLabel; }
          button.classList.remove("copied");
        }
      }, 2500);
    };

    global.addEventListener("afterprint", cleanup, { once: true });
    // afterprint does not fire consistently on mobile, so always restore the page.
    global.setTimeout(cleanup, 3000);
    global.setTimeout(function () { global.print(); }, 80);
  }

  function copyToken(button) {
    var text = "";
    var holder = resolveQr(button);
    if (holder) { text = holder.getAttribute("data-qr") || ""; }
    if (!text && button) { text = button.getAttribute("data-copy") || ""; }
    if (!text) {
      buttonStatus(button, "Nothing to copy", false);
      return;
    }
    if (global.navigator.clipboard && global.isSecureContext) {
      buttonBusy(button, "Copying…");
      global.navigator.clipboard.writeText(text).then(function () {
        buttonRelease(button, "Token copied");
      }).catch(function () {
        buttonStatus(button, "Copy was blocked", false);
        button.disabled = false;
      });
    } else {
      buttonStatus(button, "Use HTTPS to copy", false);
    }
  }

  function wireQrButtons(root) {
    (root || document).querySelectorAll("[data-print-qr-target],[data-save-qr-target]").forEach(function (button) {
      if (button.dataset.qrWired) { return; }
      button.dataset.qrWired = "true";
      button.addEventListener("click", function () {
        if (button.hasAttribute("data-print-qr-target")) { printQr(button); }
        else { download(button); }
      });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    renderAll(document);
    wireQrButtons(document);
  });

  global.ZDSPGCQr = {
    render: renderAll,
    download: download,
    print: printQr,
    copy: copyToken,
    make: makeQr
  };
})(window);
