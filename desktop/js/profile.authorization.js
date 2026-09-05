"use strict";

(function () {
  const root = document.getElementById("div_localhomeconnect_profile_authorization");
  if (!root || root.hasAttribute("data-initialized")) return;
  root.setAttribute("data-initialized", "1");

  const endpoint = "plugins/localhomeconnect/core/ajax/localhomeconnect.ajax.php";
  const openButton = root.querySelector("#bt_localhomeconnect_open_singlekey");
  const redirectInput = root.querySelector("#in_localhomeconnect_authorization_redirect");
  const completeButton = root.querySelector("#bt_localhomeconnect_complete_authorization");
  let flowId = "";

  function request(action, data, success, failure) {
    domUtils.ajax({
      type: "POST",
      url: endpoint,
      data: Object.assign({ action: action }, data || {}),
      dataType: "json",
      error: function (requestObject, status, error) {
        if (failure) failure();
        handleAjaxError(requestObject, status, error);
      },
      success: function (response) {
        if (response.state !== "ok") {
          jeedomUtils.showAlert({ message: response.result, level: "danger" });
          if (failure) failure();
          return;
        }
        success(response.result);
      },
    });
  }

  function updateCompletionState() {
    const value = redirectInput.value.trim();
    completeButton.disabled = flowId === "" || !/(?:hcauth:\/\/auth\/prod|https:\/\/api\.home-connect\.com\/security\/oauth\/redirect_target)\?/i.test(value);
  }

  openButton.addEventListener("click", function (event) {
    if (openButton.classList.contains("disabled")) event.preventDefault();
  });

  redirectInput.addEventListener("input", updateCompletionState);

  completeButton.addEventListener("click", function () {
    const redirectUrl = redirectInput.value.trim();
    if (flowId === "" || redirectUrl === "") return;

    const originalContent = completeButton.innerHTML;
    completeButton.disabled = true;
    openButton.classList.add("disabled");
    openButton.setAttribute("aria-disabled", "true");
    completeButton.innerHTML = '<i class="fas fa-sync fa-spin"></i> {{Récupération des profils et des clés...}}';

    function restoreButtons() {
      completeButton.innerHTML = originalContent;
      if (flowId !== "") {
        openButton.classList.remove("disabled");
        openButton.setAttribute("aria-disabled", "false");
      }
      updateCompletionState();
    }

    request("completeBrowserProfileAuthorization", {
      flowId: flowId,
      redirectUrl: redirectUrl,
    }, function (profiles) {
      const imported = Array.isArray(profiles) ? profiles.length : 0;
      flowId = "";
      redirectInput.value = "";
      window.dispatchEvent(new CustomEvent("localhomeconnect:profiles-updated"));
      jeedomUtils.showAlert({ message: imported + " {{profil(s) et clé(s) récupéré(s)}}", level: "success" });
      window.setTimeout(function () {
        jeeDialog.get("#md_localhomeconnect_profile_authorization")?.destroy();
      }, 400);
    }, restoreButtons);
  });

  request("beginBrowserProfileAuthorization", {}, function (authorization) {
    if (!authorization || !authorization.flowId || !authorization.url) {
      jeedomUtils.showAlert({ message: "{{Home Connect n’a pas fourni d’autorisation exploitable}}", level: "danger" });
      return;
    }
    flowId = authorization.flowId;
    openButton.href = authorization.url;
    openButton.classList.remove("disabled");
    openButton.setAttribute("aria-disabled", "false");
    openButton.innerHTML = '<i class="fas fa-external-link-alt"></i> {{Ouvrir SingleKey dans un nouvel onglet}}';
    updateCompletionState();
  }, function () {
    openButton.innerHTML = '<i class="fas fa-exclamation-triangle"></i> {{Préparation impossible}}';
  });
})();
