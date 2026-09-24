/* ============================================================
   scanner.js — QR scan station + student self check-in.

   Two modes, one file:
     ZDSPGCScanner.startStation({...})  staff station (camera + manual entry)
     ZDSPGCScanner.startSelf({...})     student phone (camera only)

   Camera decoding uses the vendored html5-qrcode library, with the browser's
   native BarcodeDetector as a fallback. All validation (token signature,
   event window, duplicates, grace period) happens in PHP — this file never
   decides whether a check-in is valid.
   ============================================================ */
(function (global) {
  "use strict";

  var state = {
    mode: "station",
    eventId: null,
    eventCode: "",
    station: "",
    csrf: "",
    endpoint: "",
    scanning: false,
    lastPayload: "",
    lastAt: 0,
    sound: true
  };

  function $(id) { return document.getElementById(id); }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : "";
  }

  /* ---------------- feedback ---------------- */

  function beep(ok) {
    if (!state.sound || !global.AudioContext && !global.webkitAudioContext) { return; }
    try {
      var Ctx = global.AudioContext || global.webkitAudioContext;
      var ctx = new Ctx();
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.type = "sine";
      osc.frequency.value = ok ? 880 : 320;
      gain.gain.value = 0.06;
      osc.start();
      global.setTimeout(function () { osc.stop(); ctx.close(); }, ok ? 130 : 260);
    } catch (e) { /* audio is a nicety, never a requirement */ }
  }

  function setVerdict(kind, headline, lines) {
    var box = $("verdict");
    if (!box) { return; }
    box.className = "verdict " + kind;
    var html = '<div class="stamp">' + headline + "</div>";
    if (lines && lines.length) {
      html += lines.map(function (line) { return '<div class="meta">' + line + "</div>"; }).join("");
    }
    box.innerHTML = html;
  }

  function pushLog(entry) {
    var list = $("scan-log");
    if (!list) { return; }
    var row = document.createElement("div");
    row.className = "log-item " + (entry.kind || "");
    var time = new Date().toLocaleTimeString();
    row.innerHTML = '<span><strong>' + entry.name + "</strong> <span class=\"small muted\">" +
      (entry.detail || "") + "</span></span><span class=\"t\">" + time + "</span>";
    list.prepend(row);
    while (list.children.length > 12) { list.lastChild.remove(); }
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  /* ---------------- server round-trip ---------------- */

  function submit(payload) {
    var body = JSON.stringify(payload);
    return fetch(state.endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": state.csrf
      },
      credentials: "same-origin",
      body: body
    }).then(function (response) {
      return response.json().catch(function () {
        return { ok: false, code: "bad_response", message: "The server sent an unexpected reply." };
      });
    });
  }

  /** "2026-03-12 07:58:11" -> "Mar 12, 2026 · 7:58 AM" */
  function formatStamp(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(value || ""));
    if (!match) { return String(value || ""); }
    var months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    var hour = parseInt(match[4], 10);
    var meridiem = hour >= 12 ? "PM" : "AM";
    var hour12 = (hour % 12) || 12;
    return months[parseInt(match[2], 10) - 1] + " " + parseInt(match[3], 10) + ", " + match[1] +
      " · " + hour12 + ":" + match[5] + " " + meridiem;
  }

  /** Full details shown in the verdict card (ID, course, event, time, status). */
  function detailLines(result) {
    var student = result.student || {};
    var record = result.record || {};
    var event = result.event || {};
    var lines = [];

    if (student.full_name) { lines.push("<strong>" + escapeHtml(student.full_name) + "</strong>"); }

    var who = [];
    if (student.student_no) { who.push(escapeHtml(student.student_no)); }
    if (student.course) { who.push(escapeHtml(student.course)); }
    if (student.year_level) { who.push(escapeHtml(student.year_level)); }
    if (student.section) { who.push("Sec. " + escapeHtml(student.section)); }
    if (who.length) { lines.push(who.join(" · ")); }

    if (event.title) { lines.push(escapeHtml(event.title)); }

    var when = [];
    if (record.checked_in_at) { when.push(escapeHtml(formatStamp(record.checked_in_at))); }
    if (record.status === "on_time") { when.push("On time · Present"); }
    else if (record.status === "late") { when.push("Late · Present"); }
    if (when.length) { lines.push(when.join(" · ")); }

    if (result.message) { lines.push(escapeHtml(result.message)); }
    return lines;
  }

  function renderResult(result) {
    var student = result.student || {};
    var kind, headline, lines;

    switch (result.code) {
      case "on_time":
        kind = "ok"; headline = "Present";
        lines = detailLines(result);
        beep(true);
        break;
      case "late":
        kind = "late"; headline = "Late";
        lines = detailLines(result);
        beep(false);
        break;
      case "duplicate":
        kind = "dup"; headline = "Already in";
        lines = detailLines(result);
        beep(false);
        break;
      default:
        kind = "bad"; headline = "Rejected";
        lines = [escapeHtml(result.message || "Could not record this check-in.")];
        beep(false);
    }
    if (!lines.length && result.message) { lines = [escapeHtml(result.message)]; }

    setVerdict(kind, headline, lines);
    pushLog({
      kind: kind === "ok" ? "" : kind,
      name: student.full_name ? escapeHtml(student.full_name) : "Unknown QR",
      detail: (student.student_no ? escapeHtml(student.student_no) + " · " : "") + escapeHtml(result.message || "")
    });
    updateCounters(result.counters);
  }

  function updateCounters(counters) {
    if (!counters) { return; }
    var map = { "count-total": counters.total, "count-on-time": counters.on_time, "count-late": counters.late };
    Object.keys(map).forEach(function (id) {
      var el = $(id);
      if (el) { el.textContent = map[id]; }
    });
    var last = $("count-last");
    if (last) { last.textContent = counters.last_at || "—"; }
  }

  /* ---------------- decode handling ---------------- */

  var DEDUPE_MS = 2500;

  function handleDecoded(text) {
    text = String(text || "").trim();
    var now = Date.now();
    if (text === "" || state.busy) { return; }
    if (text === state.lastPayload && (now - state.lastAt) < DEDUPE_MS) { return; }
    state.lastPayload = text;
    state.lastAt = now;

    state.busy = true;
    submit({
      token: text,
      event_id: state.eventId,
      method: "qr",
      source: state.mode === "self" ? "self" : "station",
      station: currentStation()
    }).then(renderResult).catch(function () {
      renderResult({ ok: false, code: "network", message: "Cannot reach the server. Check the connection." });
    }).then(function () { state.busy = false; });
  }

  function networkFail() {
    renderResult({ ok: false, code: "network", message: "Cannot reach the server. Check the connection." });
  }

  /* ---------------- manual entry (USB scanners type + Enter) ---------------- */

  function wireManual() {
    var form = $("manual-form");
    if (!form) { return; }
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var input = $("manual-input");
      if (!input) { return; }
      var value = String(input.value || "").trim();
      if (value === "" || state.busy) { return; }

      var looksLikeToken = value.indexOf(".") !== -1 && value.length > 20;
      state.busy = true;
      submit({
        token: looksLikeToken ? value : "",
        student_no: looksLikeToken ? "" : value,
        event_id: state.eventId,
        method: looksLikeToken ? "qr" : "manual",
        source: "station",
        station: currentStation()
      }).then(renderResult).catch(networkFail).then(function () {
        state.busy = false;
        input.value = "";
        input.focus();
      });
    });
  }

  /* ---------------- camera ---------------- */

  function cameraFallback(reason) {
    var reader = $("reader");
    if (reader) {
      reader.innerHTML = '<div class="empty" style="color:#fff; padding:22px 14px;">' + reason + '</div>';
    }
  }

  /** Turn camera/permission errors into instructions a student can act on. */
  function describeCameraError(error) {
    var name = (error && error.name) || (error && error.error && error.error.name) || "";
    var message = (error && error.message) || String(error || "");

    if (!global.navigator.mediaDevices || global.isSecureContext === false ||
        (!global.isSecureContext && location.protocol === "http:")) {
      return "Camera access needs a secure page. Open this site over <strong>https://</strong> " +
        "(or http://localhost) — phones will not grant the camera on plain http.";
    }
    if (name === "NotAllowedError" || name === "PermissionDeniedError" || /permission|denied/i.test(message)) {
      return "Camera permission was blocked. Tap the camera icon in the browser address bar, " +
        "choose <strong>Allow</strong>, then press Start camera again.";
    }
    if (name === "OverconstrainedError" || /overconstrained|constraint/i.test(message)) {
      return "This camera mode is unavailable. Close other camera apps, choose another camera, or try Chrome/Safari.";
    }
    if (name === "NotFoundError" || name === "DevicesNotFoundError") {
      return "No camera was found on this device. Type the student number in the manual box instead.";
    }
    if (name === "NotReadableError" || name === "TrackStartError") {
      return "The camera is already in use by another app. Close it and try again.";
    }
    if (name === "SecurityError" || name === "NotSupportedError") {
      return "This browser blocked the camera. Try Chrome or Safari, and make sure the page is opened over https://.";
    }
    return escapeHtml(message || "Camera unavailable") +
      "<br>Use a USB QR scanner or type the student number instead.";
  }

  function startCamera() {
    if (state.scanning || !$("reader")) { return; }
    if (state.mode === "self" && !global.isSecureContext && location.protocol !== "file:") {
      cameraFallback("Open this page using <strong>HTTPS</strong> on your phone, then tap Start camera. " +
        "A plain http://LAN-address page cannot access a phone camera.");
      return;
    }
    // getUserMedia only exists in secure contexts — fail with guidance, not a blank frame.
    if (!global.navigator.mediaDevices || typeof global.navigator.mediaDevices.getUserMedia !== "function") {
      cameraFallback(describeCameraError(new Error("getUserMedia unavailable")));
      return;
    }
    state.scanning = true;
    var startButton = $("camera-start");
    var stopButton = $("camera-stop");
    if (startButton) { startButton.disabled = true; startButton.setAttribute("aria-busy", "true"); }
    if (stopButton) { stopButton.disabled = false; }
    var starter = global.Html5Qrcode ? startWithHtml5() : startWithNative();
    Promise.resolve(starter).then(function () {
      if (startButton) {
        startButton.disabled = false;
        startButton.removeAttribute("aria-busy");
        var label = startButton.querySelector("span");
        if (label) { label.textContent = "Camera running"; }
      }
    }).catch(function (error) {
      state.scanning = false;
      if (startButton) {
        startButton.disabled = false;
        startButton.removeAttribute("aria-busy");
      }
      if (stopButton) { stopButton.disabled = true; }
      cameraFallback(describeCameraError(error));
    });
  }

  function cameraConfig() {
    // Scale the QR target to the viewport so small phone screens stay comfortable.
    var reader = $("reader");
    var width = (reader && reader.clientWidth) ? reader.clientWidth : 320;
    var side = Math.max(180, Math.min(280, Math.floor(width * 0.62)));
    return { fps: 10, qrbox: { width: side, height: side } };
  }

  function startWithHtml5() {
    state.html5 = new global.Html5Qrcode("reader", { verbose: false });
    var config = cameraConfig();
    var onDecoded = function (decodedText) { handleDecoded(decodedText); };
    var onMiss = function () { /* per-frame decode misses are normal */ };

    // Prefer the rear camera (the one pointed at the QR); if the device has no
    // usable rear camera, fall back to whatever camera it does have.
    return state.html5.start({ facingMode: "environment" }, config, onDecoded, onMiss)
      .catch(function (error) {
        if (typeof global.Html5Qrcode.getCameras !== "function") { throw error; }
        return global.Html5Qrcode.getCameras().then(function (cameras) {
          if (!cameras || !cameras.length) { throw error; }
          var pick = null;
          cameras.forEach(function (camera) {
            var label = String(camera.label || "").toLowerCase();
            if (!pick && /back|rear|environment/.test(label)) { pick = camera; }
          });
          return state.html5.start((pick || cameras[0]).id, config, onDecoded, onMiss);
        });
      });
  }

  function startWithNative() {
    if (!("BarcodeDetector" in global) || !global.navigator.mediaDevices) {
      return Promise.reject(new Error("no QR decoder available in this browser"));
    }
    var reader = $("reader");
    reader.innerHTML = "";
    var video = document.createElement("video");
    video.setAttribute("playsinline", "true");
    video.style.width = "100%";
    reader.appendChild(video);
    state.video = video;

    return global.navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
      .then(function (stream) {
        state.stream = stream;
        video.srcObject = stream;
        return video.play();
      })
      .then(function () {
        var detector = new global.BarcodeDetector({ formats: ["qr_code"] });
        state.timer = global.setInterval(function () {
          detector.detect(video).then(function (codes) {
            if (codes && codes.length) { handleDecoded(codes[0].rawValue); }
          }).catch(function () {});
        }, 350);
      });
  }

  function stopCamera() {
    if (state.html5 && state.scanning) {
      try {
        state.html5.stop().then(function () { state.html5.clear(); }).catch(function () {});
      } catch (e) { /* ignore */ }
    }
    if (state.timer) { global.clearInterval(state.timer); state.timer = null; }
    if (state.stream) {
      state.stream.getTracks().forEach(function (track) { track.stop(); });
      state.stream = null;
    }
    state.scanning = false;
    var startButton = $("camera-start");
    var stopButton = $("camera-stop");
    if (startButton) {
      startButton.disabled = false;
      startButton.removeAttribute("aria-busy");
      var label = startButton.querySelector("span");
      if (label) { label.textContent = "Start camera"; }
    }
    if (stopButton) { stopButton.disabled = true; }
  }

  /* ---------------- live counters ---------------- */

  function startPolling() {
    if (!state.statsUrl || !state.eventId) { return; }
    var tick = function () {
      var url = state.statsUrl + (state.statsUrl.indexOf("?") === -1 ? "?" : "&") +
        "event_id=" + encodeURIComponent(state.eventId);
      fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data || !data.ok) { return; }
          updateCounters(data.counters);
          var list = $("scan-log");
          if (list && list.children.length === 0 && data.recent && data.recent.length) {
            data.recent.slice().reverse().forEach(function (row) {
              pushLog({
                kind: row.status === "late" ? "late" : "",
                name: escapeHtml(row.student_name),
                detail: escapeHtml(row.student_no) + " · " + escapeHtml(formatStamp(row.checked_in_at))
              });
            });
          }
        })
        .catch(function () { /* offline is fine, the station keeps working */ });
    };
    tick();
    global.setInterval(tick, 8000);
  }

  /* ---------------- boot ---------------- */

  /** Always read the station label at submit time so late edits are captured. */
  function currentStation() {
    var el = $("station-input");
    if (el) { state.station = el.value; }
    return state.station;
  }

  function boot(options) {
    options = options || {};
    state.mode = options.mode || "station";
    state.eventId = options.eventId || null;
    state.endpoint = options.endpoint || "";
    state.statsUrl = options.statsUrl || "";
    state.csrf = options.csrf || csrfToken();
    state.sound = options.sound !== false;

    var stationInput = $("station-input");
    if (stationInput) {
      state.station = stationInput.value;
      stationInput.addEventListener("input", function () { state.station = stationInput.value; });
      stationInput.addEventListener("change", function () { state.station = stationInput.value; });
    }

    wireManual();
    startPolling();

    document.addEventListener("click", function (event) {
      var target = event.target;
      if (!target || !target.closest) { return; }
      var trigger = target.closest("#camera-start, #camera-stop, #toggle-sound");
      if (!trigger) { return; }
      event.preventDefault();
      if (trigger.id === "camera-start") { startCamera(); }
      if (trigger.id === "camera-stop") { stopCamera(); }
      if (trigger.id === "toggle-sound") {
        state.sound = !state.sound;
        var label = trigger.querySelector("span");
        if (label) { label.textContent = state.sound ? "Sound on" : "Sound off"; }
      }
    });

    if (options.autostart) { startCamera(); }
  }

  function bootFromReader() {
    var reader = $("reader");
    if (!reader || reader.getAttribute("data-scanner-mode") === null) { return; }

    if (!global.Html5Qrcode && !("BarcodeDetector" in global)) {
      cameraFallback("The QR decoder could not be loaded. Refresh the page and check your internet/cache settings.");
      return;
    }

    boot({
      mode: reader.getAttribute("data-scanner-mode") || "station",
      eventId: parseInt(reader.getAttribute("data-scanner-event-id"), 10) || 0,
      endpoint: reader.getAttribute("data-scanner-endpoint") || "",
      statsUrl: reader.getAttribute("data-scanner-stats-url") || "",
      csrf: reader.getAttribute("data-scanner-csrf") || "",
      sound: reader.getAttribute("data-scanner-sound") !== "false",
      autostart: reader.getAttribute("data-scanner-autostart") === "true"
    });
  }

  function onReady(fn) {
    if (document.readyState !== "loading") { fn(); }
    else { document.addEventListener("DOMContentLoaded", fn, { once: true }); }
  }

  onReady(bootFromReader);

  global.ZDSPGCScanner = {
    boot: boot,
    start: startCamera,
    stop: stopCamera,
    handle: handleDecoded
  };
})(window);
