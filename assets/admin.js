(function () {
  function onReady(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback);
      return;
    }

    callback();
  }

  function getConfig() {
    return window.agSyncBridgeAdmin || {};
  }

  function getLabel(name, fallback) {
    var config = getConfig();
    var labels = config.labels || {};
    return labels[name] || fallback;
  }

  function findConfirmationInput(form) {
    var fieldName = form.getAttribute("data-confirm-field");
    if (fieldName) {
      return form.querySelector('[name="' + fieldName + '"]');
    }

    return form.querySelector('input[type="text"]');
  }

  function validateConfirmation(form) {
    var expected = form.getAttribute("data-confirm") || "";
    if (!expected) {
      return true;
    }

    var input = findConfirmationInput(form);
    if (!input || input.value.trim() === expected) {
      return true;
    }

    window.alert("Conferma non valida. Digita esattamente: " + expected);
    input.focus();
    return false;
  }

  function updateProgressBar(bar, progress) {
    var safeProgress = Math.max(0, Math.min(100, parseInt(progress || 0, 10)));
    bar.style.width = safeProgress + "%";
    bar.setAttribute("aria-valuenow", String(safeProgress));
  }

  function stripBom(text) {
    return (text || "").replace(/^\uFEFF/, "");
  }

  function decodeHtml(text) {
    var element = document.createElement("textarea");
    element.innerHTML = text;
    return element.value;
  }

  function extractServerMessage(text) {
    var clean = stripBom(String(text || "")).trim();
    if (!clean) {
      return "";
    }

    var htmlMatch = clean.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
    if (htmlMatch && htmlMatch[1]) {
      clean = htmlMatch[1];
    }

    clean = clean
      .replace(/<script[\s\S]*?<\/script>/gi, " ")
      .replace(/<style[\s\S]*?<\/style>/gi, " ")
      .replace(/<[^>]+>/g, " ");

    clean = decodeHtml(clean).replace(/\s+/g, " ").trim();
    return clean;
  }

  function parseJsonResponse(text) {
    var clean = stripBom(String(text || "")).trim();
    if (!clean) {
      throw new Error(getLabel("invalidResponse", "Risposta non valida ricevuta dal server."));
    }

    try {
      return JSON.parse(clean);
    } catch (directError) {
      var firstBrace = clean.indexOf("{");
      var lastBrace = clean.lastIndexOf("}");

      if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
        try {
          return JSON.parse(clean.slice(firstBrace, lastBrace + 1));
        } catch (ignoredObjectError) {}
      }

      var firstBracket = clean.indexOf("[");
      var lastBracket = clean.lastIndexOf("]");

      if (firstBracket !== -1 && lastBracket !== -1 && lastBracket > firstBracket) {
        try {
          return JSON.parse(clean.slice(firstBracket, lastBracket + 1));
        } catch (ignoredArrayError) {}
      }
    }

    var serverMessage = extractServerMessage(clean);
    throw new Error(serverMessage || getLabel("invalidResponse", "Risposta non valida ricevuta dal server."));
  }

  onReady(function () {
    var config = getConfig();
    var asyncForms = document.querySelectorAll(".ag-sync-bridge-async-form");
    var panel = document.getElementById("ag-sync-bridge-operation-panel");
    var statusText = document.getElementById("ag-sync-bridge-operation-status");
    var logBox = document.getElementById("ag-sync-bridge-live-log");
    var progressBar = document.getElementById("ag-sync-bridge-progress-bar");
    var activeRequest = null;
    var operationPending = false;
    var operationResolved = false;
    var pollTimer = null;
    var pollInFlight = false;

    function setPanelState(message, progress, logs, statusClass) {
      if (!panel || !statusText || !logBox || !progressBar) {
        return;
      }

      panel.hidden = false;
      panel.classList.remove(
        "ag-sync-bridge-operation-panel--error",
        "ag-sync-bridge-operation-panel--success"
      );

      if (statusClass) {
        panel.classList.add(statusClass);
      }

      statusText.textContent = message || getLabel("working", "Operazione in corso...");
      updateProgressBar(progressBar, progress);

      if (Array.isArray(logs) && logs.length) {
        logBox.textContent = logs.join("\n");
      }
    }

    function buildStatusUrl() {
      var params = new URLSearchParams();
      params.set("action", "ag_sync_bridge_operation_status");
      params.set("nonce", config.statusNonce || "");
      return (config.ajaxUrl || window.ajaxurl || "") + "?" + params.toString();
    }

    function pollStatus() {
      if (!config.statusNonce || !config.ajaxUrl) {
        return Promise.resolve();
      }
      if (pollInFlight) {
        return Promise.resolve();
      }
      pollInFlight = true;

      return window.fetch(buildStatusUrl(), {
        credentials: "same-origin",
        method: "GET",
        headers: {
          Accept: "application/json"
        }
      }).then(function (response) {
        return response.text().then(function (text) {
          return parseJsonResponse(text);
        });
      }).then(function (payload) {
        if (operationResolved) {
          return;
        }

        if (!payload || !payload.success || !payload.data) {
          return;
        }

        var state = payload.data.state || {};
        var operation = state.current_operation || {};
        if (!operation.operation) {
          return;
        }
        var logs = payload.data.logs || [];
        var progress = typeof operation.progress === "number" ? operation.progress : 0;
        var message = operation.message || getLabel("working", "Operazione in corso...");
        var status = operation.status || "running";
        if (status === "complete") {
          operationResolved = true;
          setPanelState(message, 100, logs, "ag-sync-bridge-operation-panel--success");
          stopPolling();
        } else if (["failed", "error", "cancelled", "rollback_required", "reconciled"].indexOf(status) !== -1) {
          operationResolved = true;
          setPanelState(message, progress, logs, "ag-sync-bridge-operation-panel--error");
          stopPolling();
        } else {
          setPanelState(message, progress, logs);
        }
      }).catch(function () {
        if (operationPending || operationResolved) {
          return;
        }

        setPanelState(getLabel("connectionError", "Impossibile leggere lo stato dell operazione."), 0, null, "ag-sync-bridge-operation-panel--error");
      }).finally(function () {
        pollInFlight = false;
      });
    }

    function startPolling() {
      stopPolling();
      pollStatus();
      pollTimer = window.setInterval(pollStatus, 1500);
    }

    function stopPolling() {
      if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
      }
    }

    function setFormsDisabled(disabled) {
      asyncForms.forEach(function (form) {
        var buttons = form.querySelectorAll('button, input[type="submit"]');
        buttons.forEach(function (button) {
          button.disabled = disabled;
        });
      });
    }

    asyncForms.forEach(function (form) {
      form.addEventListener("submit", function (event) {
        if (activeRequest) {
          event.preventDefault();
          return;
        }

        if (!validateConfirmation(form)) {
          event.preventDefault();
          return;
        }

        event.preventDefault();

        var formData = new window.FormData(form);
        var operationLabel = form.getAttribute("data-operation-label") || getLabel("working", "Operazione in corso...");
        formData.append("agsb_async", "1");

        operationPending = true;
        operationResolved = false;
        setFormsDisabled(true);
        setPanelState(operationLabel, 3, null);
        startPolling();

        var requestUrl = config.ajaxUrl || form.action;

        activeRequest = window.fetch(requestUrl, {
          method: "POST",
          body: formData,
          credentials: "same-origin",
          headers: {
            "X-AGSB-Async": "1",
            Accept: "application/json"
          }
        }).then(function (response) {
          return response.text().then(function (text) {
            var payload = parseJsonResponse(text);

            if (!response.ok || !payload.success) {
              var errorMessage = payload.data && payload.data.message ? payload.data.message : getLabel("failed", "Operazione interrotta con errore.");
              var error = new Error(errorMessage);
              error.payload = payload;
              throw error;
            }

            return payload;
          });
        }).then(function (payload) {
          var logs = payload.data && payload.data.logs ? payload.data.logs : null;
          operationResolved = true;
          stopPolling();
          setPanelState(payload.data.message || getLabel("completed", "Operazione completata."), 100, logs, "ag-sync-bridge-operation-panel--success");
          return payload;
        }).catch(function (error) {
          var payload = error.payload || {};
          var logs = payload.data && payload.data.logs ? payload.data.logs : null;
          operationResolved = false;
          setPanelState(error.message || getLabel("working", "Verifica dello stato remoto in corso..."), 85, logs);
          pollStatus();
        }).finally(function () {
          activeRequest = null;
          operationPending = false;
          setFormsDisabled(false);
        });
      });
    });

    if (panel && !panel.hidden) {
      startPolling();
    }
  });
}());
