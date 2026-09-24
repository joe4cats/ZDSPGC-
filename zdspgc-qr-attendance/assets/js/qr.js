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
    var label = button.querySelector("span");
    if (!label) { return; }
    if (!button.dataset.originalLabel) { button.dataset.originalLabel = label.textContent; }
    label.textContent = message;
    button.classList.toggle("copied", success !== false);
    global.clearTimeout(button._statusTimer);
    button._statusTimer = global.setTimeout(function () {
      label.textContent = button.dataset.originalLabel;
      button.classList.remove("copied");
    }, 1800);
  }

  /** Download the canvas inside a .qr element as a PNG file. */
  function download(button) {
    var holder = button.closest("[data-qr]");
    var canvas = holder ? holder.querySelector("canvas") : null;
    if (!canvas) {
      buttonStatus(button, "QR is still loading");
      return;
    }

    var name = (holder.getAttribute("data-qr-name") || "qr-code").replace(/[^a-z0-9_-]+/gi, "-");
    var finish = function (href, revoke) {
      var link = document.createElement("a");
      link.download = name + ".png";
      link.href = href;
      link.style.display = "none";
      document.body.appendChild(link);
      link.click();
      link.remove();
      if (revoke) { global.setTimeout(function () { global.URL.revokeObjectURL(href); }, 1000); }
      buttonStatus(button, "PNG saved");
    };

    // Blob downloads are faster and work well on current phones and desktops.
    if (canvas.toBlob) {
      canvas.toBlob(function (blob) {
        if (blob) { finish(global.URL.createObjectURL(blob), true); }
        else { finish(canvas.toDataURL("image/png"), false); }
      }, "image/png");
    } else {
      finish(canvas.toDataURL("image/png"), false);
    }
  }

  function printQr(button) {
    var holder = button.closest("[data-qr]");
    var text = holder ? holder.getAttribute("data-qr") : "";
    var canvas = holder ? holder.querySelector("canvas") : null;
    if (!text || !canvas) {
      buttonStatus(button, "QR is still loading");
      return;
    }

    var w = global.open("", "_blank", "width=520,height=640");
    if (!w) {
      buttonStatus(button, "Allow pop-ups to print");
      return;
    }
    var label = escapeHtml(holder.getAttribute("data-qr-label") || "QR code");
    var img = canvas.toDataURL("image/png");
    w.document.write(
      '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>QR code</title><style>@page{size:auto;margin:12mm}' +
      'body{font-family:Segoe UI,system-ui,sans-serif;text-align:center;padding:18px;color:#16241c}' +
      'img{width:min(82vw,320px);height:min(82vw,320px);background:#fff}' +
      'code{display:block;margin-top:10px;font-size:10px;word-break:break-all;color:#555}' +
      '@media print{button{display:none}}</style></head><body>' +
      '<h3>' + label + '</h3><img src="' + img + '" alt="' + label + ' QR code">' +
      '<code>' + escapeHtml(text) + '</code><br><button onclick="window.print()">Print</button>' +
      '</body></html>'
    );
    w.document.close();
    w.focus();
    var printed = function () {
      w.removeEventListener("afterprint", printed);
      buttonStatus(button, "Print opened");
      w.close();
    };
    w.addEventListener("afterprint", printed);
    global.setTimeout(function () { w.print(); }, 250);
  }

  function copyToken(button) {
    var holder = button.closest("[data-qr]");
    var text = holder ? holder.getAttribute("data-qr") : "";
    if (!text) { return; }
    if (global.navigator.clipboard && global.isSecureContext) {
      global.navigator.clipboard.writeText(text).then(function () {
        buttonStatus(button, "Token copied");
      }).catch(function () { buttonStatus(button, "Copy was blocked"); });
    } else {
      buttonStatus(button, "Use HTTPS to copy");
    }
  }

  document.addEventListener("DOMContentLoaded", function () { renderAll(document); });

  global.ZDSPGCQr = {
    render: renderAll,
    download: download,
    print: printQr,
    copy: copyToken,
    make: makeQr
  };
})(window);
