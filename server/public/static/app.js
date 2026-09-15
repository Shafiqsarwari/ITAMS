document.addEventListener("DOMContentLoaded", () => {
  let pendingConfirmForm = null;
  const sidebar = document.getElementById("appSidebar");
  const sidebarToggle = document.getElementById("sidebarToggle");
  const sidebarClose = document.getElementById("sidebarClose");
  const sidebarBackdrop = document.getElementById("sidebarBackdrop");

  const setSidebarOpen = (open) => {
    sidebar?.classList.toggle("is-open", open);
    document.body.classList.toggle("sidebar-open", open);
    sidebarToggle?.setAttribute("aria-expanded", open ? "true" : "false");
    if (sidebarBackdrop) {
      sidebarBackdrop.hidden = !open;
    }
  };

  sidebarToggle?.addEventListener("click", () => setSidebarOpen(true));
  sidebarClose?.addEventListener("click", () => setSidebarOpen(false));
  sidebarBackdrop?.addEventListener("click", () => setSidebarOpen(false));
  sidebar?.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => setSidebarOpen(false));
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      setSidebarOpen(false);
    }
  });

  let activeDeviceActions = null;
  const positionDeviceActionsMenu = (toggle, menu) => {
    if (!toggle || !menu || !menu.classList.contains("show")) return;

    const rect = toggle.getBoundingClientRect();
    const menuWidth = menu.offsetWidth || 160;
    const menuHeight = menu.offsetHeight || 120;
    const gutter = 8;
    const left = Math.max(
      gutter,
      Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - gutter),
    );
    const belowTop = rect.bottom + 4;
    const top =
      belowTop + menuHeight <= window.innerHeight - gutter
        ? belowTop
        : Math.max(gutter, rect.top - menuHeight - 4);

    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
  };

  const closeDeviceActionsMenu = () => {
    if (!activeDeviceActions) return;

    const { menu, placeholder, toggle, reposition } = activeDeviceActions;
    window.removeEventListener("resize", reposition);
    window.removeEventListener("scroll", reposition, true);
    menu.classList.remove("show", "device-actions-menu-floating");
    menu.removeAttribute("style");
    toggle.setAttribute("aria-expanded", "false");
    placeholder.parentNode?.insertBefore(menu, placeholder);
    placeholder.remove();
    activeDeviceActions = null;
  };

  document.querySelectorAll(".device-actions").forEach((dropdown) => {
    const toggle = dropdown.querySelector(".device-actions-toggle");
    const menu = dropdown.querySelector(".device-actions-menu");
    if (!toggle || !menu) return;

    const placeholder = document.createComment("device-actions-menu");
    const reposition = () => positionDeviceActionsMenu(toggle, menu);

    toggle.removeAttribute("data-bs-toggle");
    toggle.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      if (activeDeviceActions?.toggle === toggle) {
        closeDeviceActionsMenu();
        return;
      }

      closeDeviceActionsMenu();
      menu.parentNode?.insertBefore(placeholder, menu);
      document.body.appendChild(menu);
      menu.classList.add("show", "device-actions-menu-floating");
      toggle.setAttribute("aria-expanded", "true");
      activeDeviceActions = {
        menu,
        placeholder,
        toggle,
        reposition,
        parentModal: dropdown.closest(".modal.show"),
      };
      positionDeviceActionsMenu(toggle, menu);
      window.addEventListener("resize", reposition);
      window.addEventListener("scroll", reposition, true);
    });

    menu.addEventListener("click", (event) => {
      if (event.target.closest("button, a")) {
        closeDeviceActionsMenu();
      }
    });
  });

  document.addEventListener("click", (event) => {
    if (!activeDeviceActions) return;
    if (
      activeDeviceActions.menu.contains(event.target) ||
      activeDeviceActions.toggle.contains(event.target)
    )
      return;
    closeDeviceActionsMenu();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeDeviceActionsMenu();
    }
  });

  const sidebarDesktopMedia = window.matchMedia("(min-width: 1024px)");
  const handleSidebarDesktopChange = (event) => {
    if (event.matches) {
      setSidebarOpen(false);
    }
  };
  if (typeof sidebarDesktopMedia.addEventListener === "function") {
    sidebarDesktopMedia.addEventListener("change", handleSidebarDesktopChange);
  } else if (typeof sidebarDesktopMedia.addListener === "function") {
    sidebarDesktopMedia.addListener(handleSidebarDesktopChange);
  }

  const deleteModalEl = document.getElementById("confirmDeleteModal");
  const deleteTitle = document.getElementById("confirmDeleteModalLabel");
  const deleteMessage = document.getElementById("confirmDeleteMessage");
  const deleteButton = document.getElementById("confirmDeleteButton");
  const deleteModal =
    deleteModalEl && window.bootstrap
      ? new bootstrap.Modal(deleteModalEl)
      : null;

  document.querySelectorAll("[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (!deleteModal || !deleteMessage || !deleteButton) {
        if (!confirm(form.dataset.confirm)) {
          event.preventDefault();
        }
        return;
      }

      if (form.dataset.confirmed === "1") {
        delete form.dataset.confirmed;
        form.dataset.confirmReady = "1";
        return;
      }

      event.preventDefault();
      pendingConfirmForm = form;
      const action =
        form.dataset.confirmAction === "remove" ? "remove" : "delete";
      if (deleteTitle) {
        deleteTitle.textContent =
          action === "remove" ? "Confirm Remove" : "Confirm Delete";
      }
      deleteMessage.textContent = form.dataset.confirm || "Are you sure?";
      deleteButton.innerHTML =
        action === "remove"
          ? '<i class="bi bi-box-arrow-right"></i> Remove'
          : '<i class="bi bi-trash"></i> Delete';
      deleteButton.classList.toggle("btn-danger", action === "delete");
      deleteButton.classList.toggle("btn-warning", action === "remove");
      deleteModal.show();
    });
  });

  deleteButton?.addEventListener("click", () => {
    if (!pendingConfirmForm) return;
    pendingConfirmForm.dataset.confirmed = "1";
    deleteModal?.hide();
    pendingConfirmForm.requestSubmit();
  });

  const blockedUserActionEl = document.getElementById("blockedUserActionModal");
  const blockedUserActionMessage = document.getElementById(
    "blockedUserActionMessage",
  );
  const blockedUserActionModal =
    blockedUserActionEl && window.bootstrap
      ? new bootstrap.Modal(blockedUserActionEl)
      : null;
  document.addEventListener("click", (event) => {
    const blockedAction = event.target.closest("[data-blocked-user-action]");
    if (!blockedAction) return;

    event.preventDefault();
    event.stopPropagation();
    const message =
      blockedAction.dataset.blockedUserAction || "This action is not allowed.";
    if (!blockedUserActionModal || !blockedUserActionMessage) {
      alert(message);
      return;
    }

    blockedUserActionMessage.textContent = message;
    blockedUserActionModal.show();
  });

  const maintenanceEmptyHtml = () => `
        <div class="empty-state">
            <i class="bi bi-tools"></i>
            <strong>No maintenance changes</strong>
            <span>RAM, hard drive, BIOS, and OS changes will appear after future agent inventory uploads.</span>
        </div>`;

  document
    .querySelectorAll(
      "[data-maintenance-delete-form], [data-maintenance-bulk-delete-form]",
    )
    .forEach((form) => {
      form.addEventListener("submit", async (event) => {
        if (form.dataset.confirm && form.dataset.confirmReady !== "1") {
          return;
        }
        delete form.dataset.confirmReady;
        event.preventDefault();
        const isBulk = form.matches("[data-maintenance-bulk-delete-form]");
        const selected = isBulk
          ? bulkItemsForForm(form).filter((input) => input.checked)
          : [];
        if (isBulk && selected.length === 0) {
          updateBulkDeleteState(form);
          return;
        }
        const button = form.querySelector('button[type="submit"]');
        const row = form.closest("tr");
        const modal = form.closest("[data-device-id]");

        button?.setAttribute("disabled", "disabled");
        try {
          const response = await fetch(form.action, {
            method: "POST",
            body: new FormData(form),
            headers: {
              Accept: "application/json",
            },
          });
          const payload = await response.json().catch(() => ({}));
          if (!response.ok || payload.ok === false) {
            throw new Error(
              payload.error || "Could not delete maintenance record.",
            );
          }

          if (isBulk) {
            selected.forEach((input) => {
              const selectedRow =
                input.closest("[data-maintenance-id]") ||
                modal?.querySelector(`[data-maintenance-id="${input.value}"]`);
              selectedRow?.remove();
            });
          } else {
            row?.remove();
          }
          const remaining = modal
            ? [...modal.querySelectorAll("[data-maintenance-id]")]
            : [];
          const badge = modal?.querySelector(".maintenance-count-badge");
          if (badge) {
            badge.textContent = `${remaining.length.toLocaleString()} change${remaining.length === 1 ? "" : "s"}`;
          }
          if (remaining.length === 0) {
            const body = modal?.querySelector("[data-maintenance-body]");
            if (body) {
              body.innerHTML = maintenanceEmptyHtml();
            }
          }
          updateBulkDeleteState(form);
          showFlash(
            `${payload.deleted || selected.length || 1} maintenance record${(payload.deleted || selected.length || 1) === 1 ? "" : "s"} deleted.`,
            "success",
          );
        } catch (error) {
          showFlash(
            error.message || "Could not delete maintenance record.",
            "danger",
          );
          updateBulkDeleteState(form);
        } finally {
          button?.removeAttribute("disabled");
          updateBulkDeleteState(form);
        }
      });
    });

  const appMessageEl = document.getElementById("appMessageModal");
  const appMessageTitle = document.getElementById("appMessageModalLabel");
  const appMessageBody = document.getElementById("appMessageModalBody");
  const appMessageIcon = document.getElementById("appMessageModalIcon");
  const appMessageModal =
    appMessageEl && window.bootstrap ? new bootstrap.Modal(appMessageEl) : null;
  const appMessageQueue = [];
  let appMessageActive = false;
  const messageTitle = (type) => {
    if (type === "success") return "Success";
    if (type === "warning") return "Attention";
    if (type === "danger") return "Action Needed";
    return "Message";
  };
  const messageIcon = (type) => {
    if (type === "success") return "bi-check2";
    if (type === "warning") return "bi-exclamation-lg";
    if (type === "danger") return "bi-shield-exclamation";
    return "bi-info-lg";
  };
  const showNextMessage = () => {
    const item = appMessageQueue.shift();
    if (!item) return;

    const message = String(item.message || "");
    if (!appMessageModal || !appMessageTitle || !appMessageBody) {
      alert(message);
      appMessageActive = false;
      showNextMessage();
      return;
    }

    appMessageTitle.textContent = messageTitle(item.type);
    appMessageBody.textContent = message;
    appMessageEl.dataset.messageType = item.type || "info";
    if (appMessageIcon) {
      appMessageIcon.innerHTML = `<i class="bi ${messageIcon(item.type)}"></i>`;
    }
    appMessageActive = true;
    appMessageModal.show();
  };
  const showFlash = (message, type = "success") => {
    appMessageQueue.push({ type, message });
    if (!appMessageActive) {
      showNextMessage();
    }
  };
  appMessageEl?.addEventListener("hidden.bs.modal", () => {
    appMessageActive = false;
    showNextMessage();
  });
  try {
    const flashMessages = JSON.parse(
      document.getElementById("appFlashMessages")?.textContent || "[]",
    );
    if (Array.isArray(flashMessages)) {
      flashMessages.forEach((item) => showFlash(item.message, item.type));
    }
  } catch (error) {
    console.warn("Could not load flash messages.", error);
  }

  document.querySelectorAll("[data-login-flash]").forEach((message) => {
    let hidden = false;
    const hideMessage = () => {
      if (hidden) return;
      hidden = true;
      message.classList.add("is-hiding");
      message.addEventListener("transitionend", () => message.remove(), {
        once: true,
      });
      window.setTimeout(() => message.remove(), 300);
    };

    window.setTimeout(() => {
      hideMessage();
    }, 5000);

    document
      .querySelectorAll("#loginIdentity, #loginPassword")
      .forEach((field) => {
        field.addEventListener("focus", hideMessage, { once: true });
      });
  });

  const syncOverlay = document.getElementById("softwareSyncOverlay");
  const syncDialog = document.getElementById("softwareSyncDialog");
  const syncTitle = document.getElementById("softwareSyncTitle");
  const syncMessage = document.getElementById("softwareSyncMessage");
  const syncCancel = document.getElementById("softwareSyncCancel");
  let activeSync = null;

  const showSyncOverlay = (
    message,
    state = "loading",
    itemLabel = "Software",
  ) => {
    syncDialog?.classList.toggle("is-success", state === "success");
    syncDialog?.classList.toggle("is-error", state === "error");
    if (syncTitle) {
      syncTitle.textContent =
        state === "success"
          ? `${itemLabel} Loaded`
          : state === "error"
            ? "Sync Failed"
            : `Syncing ${itemLabel}`;
    }
    if (syncMessage) {
      syncMessage.textContent = message;
    }
    if (syncCancel) {
      syncCancel.textContent = state === "loading" ? "Cancel" : "Close";
      syncCancel.classList.toggle("btn-primary", state !== "loading");
      syncCancel.classList.toggle("btn-outline-secondary", state === "loading");
    }
    syncOverlay?.classList.remove("d-none");
  };

  const hideSyncOverlay = () => {
    syncOverlay?.classList.add("d-none");
  };

  syncCancel?.addEventListener("click", () => {
    if (activeSync) {
      activeSync.cancelled = true;
      activeSync.abortController?.abort();
    }
    hideSyncOverlay();
  });

  const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

  const escapeHtml = (value) =>
    String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  const softwareRecordLabel = (count) =>
    `${count.toLocaleString()} installed software record${count === 1 ? "" : "s"}`;
  const driverRecordLabel = (count) =>
    `${count.toLocaleString()} installed driver record${count === 1 ? "" : "s"}`;
  const softwareUpdateDate = (value) => {
    const text = String(value || "").trim();
    const parts = text.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/);
    if (!parts) {
      return text || "-";
    }
    const hour = Number(parts[2]);
    const displayHour = hour % 12 || 12;
    return `${parts[1]} ${String(displayHour).padStart(2, "0")}:${parts[3]} ${hour >= 12 ? "PM" : "AM"}`;
  };

  const renderSoftwarePanel = (modal, deviceId, software) => {
    if (!modal) return;

    const count = software.length;
    const panel =
      modal.querySelector(`#deviceSoftwarePane${deviceId}`) ||
      modal.querySelector(".modal-body");
    if (!panel) return;
    modal
      .querySelector("[data-software-tab-count]")
      ?.replaceChildren(document.createTextNode(count.toLocaleString()));

    if (count === 0) {
      panel.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-box-seam"></i>
                    <strong>No software records</strong>
                    <span>Installed software will appear after the agent uploads inventory.</span>
                </div>`;
      return;
    }

    const tableId = `softwareTable${deviceId}`;
    const rows = software
      .map(
        (item) => `
            <tr>
                <td data-label="Name">${escapeHtml(item.name || "-")}</td>
                <td data-label="Version">${escapeHtml(item.version || "-")}</td>
                <td data-label="Publisher">${escapeHtml(item.publisher || "-")}</td>
                <td data-label="Installed">${escapeHtml(item.installed_date || "-")}</td>
                <td data-label="Updated">${escapeHtml(item.last_updated_date || "-")}</td>
            </tr>`,
      )
      .join("");

    panel.innerHTML = `
            <div class="software-panel">
                <div class="software-toolbar">
                    <div class="software-search">
                        <i class="bi bi-search"></i>
                        <input class="form-control" type="search" data-software-search="#${tableId}" placeholder="Search software, publisher, version">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-software-clear aria-label="Clear software search"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <span class="software-count" data-software-count>${count.toLocaleString()} shown</span>
                </div>
                <div class="table-responsive software-table-wrap">
                    <table class="table align-middle software-table" id="${tableId}">
                        <thead><tr><th>Name</th><th>Version</th><th>Publisher</th><th>Installed</th><th>Updated</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                    <div class="software-empty-search text-secondary d-none">No software matches your search.</div>
                </div>
            </div>`;
  };

  const renderDriverPanel = (modal, deviceId, drivers) => {
    if (!modal) return;

    const count = drivers.length;
    const panel = modal.querySelector(`#deviceDriversPane${deviceId}`);
    if (!panel) return;
    modal
      .querySelector("[data-driver-tab-count]")
      ?.replaceChildren(document.createTextNode(count.toLocaleString()));

    if (count === 0) {
      panel.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-cpu"></i>
                    <strong>No driver records</strong>
                    <span>Installed drivers will appear after the agent uploads inventory.</span>
                </div>`;
      return;
    }

    const tableId = `driverTable${deviceId}`;
    const rows = drivers
      .map(
        (item) => `
            <tr>
                <td data-label="Device">${escapeHtml(item.device_name || "-")}</td>
                <td data-label="Version">${escapeHtml(item.version || "-")}</td>
                <td data-label="Provider">${escapeHtml(item.provider || item.manufacturer || "-")}</td>
                <td data-label="Class">${escapeHtml(item.device_class || "-")}</td>
                <td data-label="Installed">${escapeHtml(item.installed_date || "-")}</td>
                <td data-label="Updated">${escapeHtml(item.last_updated_date || "-")}</td>
            </tr>`,
      )
      .join("");

    panel.innerHTML = `
            <div class="software-panel driver-panel">
                <div class="software-toolbar">
                    <div class="software-search">
                        <i class="bi bi-search"></i>
                        <input class="form-control" type="search" data-software-search="#${tableId}" placeholder="Search device, provider, version">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-software-clear aria-label="Clear driver search"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <span class="software-count" data-software-count>${count.toLocaleString()} shown</span>
                </div>
                <div class="table-responsive software-table-wrap driver-table-wrap">
                    <table class="table align-middle software-table driver-table" id="${tableId}">
                        <thead><tr><th>Device</th><th>Version</th><th>Provider</th><th>Class</th><th>Installed</th><th>Updated</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                    <div class="software-empty-search text-secondary d-none">No drivers match your search.</div>
                </div>
            </div>`;
  };

  const softwareUpdateEmptyHtml = () => `
        <div class="empty-state">
            <i class="bi bi-clock-history"></i>
            <strong>No software updates</strong>
            <span>Version changes will appear here after future software inventory uploads.</span>
        </div>`;

  const softwareUpdatePanelHtml = (updateModal, updates) => {
    if (updates.length === 0) {
      return softwareUpdateEmptyHtml();
    }

    const canDelete = updateModal?.dataset.canDeleteSoftwareUpdate === "1";
    const deleteUrl = updateModal?.dataset.softwareUpdateDeleteUrl || "";
    const bulkFormId = `${updateModal?.id || "softwareUpdate"}BulkDelete`;
    const csrf = document.querySelector('input[name="_csrf"]')?.value || "";
    const rows = updates
      .map(
        (item) => `
            <tr data-software-update-id="${escapeHtml(item.id || "0")}">
                ${canDelete ? `<td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="${escapeHtml(item.id || "")}" form="${escapeHtml(bulkFormId)}" aria-label="Select software update record" data-bulk-select-item></td>` : ""}
                <td data-label="Software"><strong>${escapeHtml(item.software_name || "-")}</strong></td>
                <td data-label="Old Version">${escapeHtml(item.previous_version || "-")}</td>
                <td data-label="New Version">${escapeHtml(item.current_version || "-")}</td>
                <td data-label="Update Date">${escapeHtml(softwareUpdateDate(item.created_at))}</td>
                ${
                  canDelete
                    ? `
                    <td class="text-end" data-label="Actions">
                        <form method="post" action="${escapeHtml(deleteUrl)}" data-confirm="Delete this software update record?" data-software-update-delete-form>
                            <input type="hidden" name="_csrf" value="${escapeHtml(csrf)}">
                            <input type="hidden" name="id" value="${escapeHtml(item.id || "")}">
                            <button class="btn btn-sm btn-outline-danger software-update-delete-btn" type="submit" title="Delete record" aria-label="Delete software update record">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>`
                    : ""
                }
            </tr>`,
      )
      .join("");

    return `
            ${
              canDelete
                ? `
                <form id="${escapeHtml(bulkFormId)}" class="bulk-delete-toolbar" method="post" action="${escapeHtml(deleteUrl)}" data-confirm="Delete selected software update records?" data-software-update-bulk-delete-form>
                    <input type="hidden" name="_csrf" value="${escapeHtml(csrf)}">
                    <label class="bulk-select-all-label">
                        <input class="form-check-input" type="checkbox" aria-label="Select all software update records" data-bulk-select-all data-bulk-target="${escapeHtml(bulkFormId)}">
                        Select all
                    </label>
                    <button class="btn btn-sm btn-outline-danger" type="submit" disabled data-bulk-delete-submit>
                        <i class="bi bi-trash"></i> Delete selected
                    </button>
                </form>`
                : ""
            }
            <div class="table-responsive software-update-table-wrap">
                <table class="table align-middle software-update-table">
                    <thead><tr>${canDelete ? `<th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all software update records" data-bulk-select-all data-bulk-target="${escapeHtml(bulkFormId)}"></th>` : ""}<th>Software</th><th>Old Version</th><th>New Version</th><th>Update Date</th>${canDelete ? "<th>Actions</th>" : ""}</tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
  };

  const softwareUpdateSeenKey = (deviceId) =>
    `asset-software-update-seen-${deviceId}`;
  const softwareUpdateIds = (updateModal) =>
    [...(updateModal?.querySelectorAll("[data-software-update-id]") || [])]
      .map((row) => Number.parseInt(row.dataset.softwareUpdateId || "0", 10))
      .filter((id) => id > 0);

  const syncSoftwareUpdateTriggerState = (deviceId) => {
    const updateModal = document.getElementById(
      `deviceSoftwareUpdates${deviceId}`,
    );
    const trigger = document.querySelector(
      `[data-software-update-trigger][data-device-id="${deviceId}"]`,
    );
    if (!updateModal || !trigger) return;

    const ids = softwareUpdateIds(updateModal);
    const latestId = ids.length ? Math.max(...ids) : 0;
    const seenId = Number.parseInt(
      localStorage.getItem(softwareUpdateSeenKey(deviceId)) || "0",
      10,
    );
    const unreadCount = ids.filter((id) => id > seenId).length;
    const total = ids.length;

    trigger.dataset.latestSoftwareUpdateId = String(latestId);
    trigger.classList.toggle("is-alert", unreadCount > 0);
    trigger
      .querySelector("[data-software-update-count]")
      ?.replaceChildren(document.createTextNode(unreadCount.toLocaleString()));

    const updateTotal = updateModal.querySelector(
      "[data-software-update-total]",
    );
    if (updateTotal) {
      updateTotal.textContent = `${total.toLocaleString()} update${total === 1 ? "" : "s"}`;
    }
  };

  const markSoftwareUpdatesSeen = (trigger) => {
    const deviceId = trigger?.dataset.deviceId;
    const latestId = Number.parseInt(
      trigger?.dataset.latestSoftwareUpdateId || "0",
      10,
    );
    if (!deviceId || latestId <= 0) {
      return;
    }

    localStorage.setItem(softwareUpdateSeenKey(deviceId), String(latestId));
    syncSoftwareUpdateTriggerState(deviceId);
  };

  const renderSoftwareUpdates = (softwareModal, deviceId, updates) => {
    const updateModal = document.getElementById(
      `deviceSoftwareUpdates${deviceId}`,
    );
    const updateBody = updateModal?.querySelector(
      "[data-software-update-body]",
    );
    if (updateBody) {
      updateBody.innerHTML = softwareUpdatePanelHtml(updateModal, updates);
    }
    syncSoftwareUpdateTriggerState(deviceId);
  };

  const driverUpdateEmptyHtml = () => `
        <div class="empty-state">
            <i class="bi bi-clock-history"></i>
            <strong>No driver updates</strong>
            <span>Version changes will appear here after future driver inventory uploads.</span>
        </div>`;

  const driverUpdatePanelHtml = (updateModal, updates) => {
    if (updates.length === 0) {
      return driverUpdateEmptyHtml();
    }

    const canDelete = updateModal?.dataset.canDeleteDriverUpdate === "1";
    const deleteUrl = updateModal?.dataset.driverUpdateDeleteUrl || "";
    const bulkFormId = `${updateModal?.id || "driverUpdate"}BulkDelete`;
    const csrf = document.querySelector('input[name="_csrf"]')?.value || "";
    const rows = updates
      .map(
        (item) => `
            <tr data-driver-update-id="${escapeHtml(item.id || "0")}">
                ${canDelete ? `<td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="${escapeHtml(item.id || "")}" form="${escapeHtml(bulkFormId)}" aria-label="Select driver update record" data-bulk-select-item></td>` : ""}
                <td data-label="Driver"><strong>${escapeHtml(item.device_name || "-")}</strong></td>
                <td data-label="Old Version">${escapeHtml(item.previous_version || "-")}</td>
                <td data-label="New Version">${escapeHtml(item.current_version || "-")}</td>
                <td data-label="Update Date">${escapeHtml(softwareUpdateDate(item.created_at))}</td>
                ${
                  canDelete
                    ? `
                    <td class="text-end" data-label="Actions">
                        <form method="post" action="${escapeHtml(deleteUrl)}" data-confirm="Delete this driver update record?" data-driver-update-delete-form>
                            <input type="hidden" name="_csrf" value="${escapeHtml(csrf)}">
                            <input type="hidden" name="id" value="${escapeHtml(item.id || "")}">
                            <button class="btn btn-sm btn-outline-danger software-update-delete-btn" type="submit" title="Delete record" aria-label="Delete driver update record">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>`
                    : ""
                }
            </tr>`,
      )
      .join("");

    return `
            ${
              canDelete
                ? `
                <form id="${escapeHtml(bulkFormId)}" class="bulk-delete-toolbar" method="post" action="${escapeHtml(deleteUrl)}" data-confirm="Delete selected driver update records?" data-driver-update-bulk-delete-form>
                    <input type="hidden" name="_csrf" value="${escapeHtml(csrf)}">
                    <label class="bulk-select-all-label">
                        <input class="form-check-input" type="checkbox" aria-label="Select all driver update records" data-bulk-select-all data-bulk-target="${escapeHtml(bulkFormId)}">
                        Select all
                    </label>
                    <button class="btn btn-sm btn-outline-danger" type="submit" disabled data-bulk-delete-submit>
                        <i class="bi bi-trash"></i> Delete selected
                    </button>
                </form>`
                : ""
            }
            <div class="table-responsive software-update-table-wrap">
                <table class="table align-middle software-update-table">
                    <thead><tr>${canDelete ? `<th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all driver update records" data-bulk-select-all data-bulk-target="${escapeHtml(bulkFormId)}"></th>` : ""}<th>Driver</th><th>Old Version</th><th>New Version</th><th>Update Date</th>${canDelete ? "<th>Actions</th>" : ""}</tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
  };

  const driverUpdateSeenKey = (deviceId) =>
    `asset-driver-update-seen-${deviceId}`;
  const driverUpdateIds = (updateModal) =>
    [...(updateModal?.querySelectorAll("[data-driver-update-id]") || [])]
      .map((row) => Number.parseInt(row.dataset.driverUpdateId || "0", 10))
      .filter((id) => id > 0);

  const syncDriverUpdateTriggerState = (deviceId) => {
    const updateModal = document.getElementById(
      `deviceDriverUpdates${deviceId}`,
    );
    const trigger = document.querySelector(
      `[data-driver-update-trigger][data-device-id="${deviceId}"]`,
    );
    if (!updateModal || !trigger) return;

    const ids = driverUpdateIds(updateModal);
    const latestId = ids.length ? Math.max(...ids) : 0;
    const seenId = Number.parseInt(
      localStorage.getItem(driverUpdateSeenKey(deviceId)) || "0",
      10,
    );
    const unreadCount = ids.filter((id) => id > seenId).length;
    const total = ids.length;

    trigger.dataset.latestDriverUpdateId = String(latestId);
    trigger.classList.toggle("is-alert", unreadCount > 0);
    trigger
      .querySelector("[data-driver-update-count]")
      ?.replaceChildren(document.createTextNode(unreadCount.toLocaleString()));

    const updateTotal = updateModal.querySelector("[data-driver-update-total]");
    if (updateTotal) {
      updateTotal.textContent = `${total.toLocaleString()} update${total === 1 ? "" : "s"}`;
    }
  };

  const markDriverUpdatesSeen = (trigger) => {
    const deviceId = trigger?.dataset.deviceId;
    const latestId = Number.parseInt(
      trigger?.dataset.latestDriverUpdateId || "0",
      10,
    );
    if (!deviceId || latestId <= 0) {
      return;
    }

    localStorage.setItem(driverUpdateSeenKey(deviceId), String(latestId));
    syncDriverUpdateTriggerState(deviceId);
  };

  const renderDriverUpdates = (deviceId, updates) => {
    const updateModal = document.getElementById(
      `deviceDriverUpdates${deviceId}`,
    );
    const updateBody = updateModal?.querySelector("[data-driver-update-body]");
    if (updateBody) {
      updateBody.innerHTML = driverUpdatePanelHtml(updateModal, updates);
    }
    syncDriverUpdateTriggerState(deviceId);
  };

  const pollInventorySync = async (statusUrl, syncState, syncKind) => {
    const deadline = Date.now() + 120000;
    const itemLabel = syncKind === "drivers" ? "Driver" : "Software";
    while (!syncState.cancelled) {
      if (Date.now() > deadline) {
        throw new Error(
          `${itemLabel} sync did not finish. The device may be offline or the agent may not be running.`,
        );
      }

      const response = await fetch(statusUrl, {
        headers: { Accept: "application/json" },
        cache: "no-store",
        signal: syncState.abortController.signal,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(
          payload.error || `${itemLabel} sync status could not be checked.`,
        );
      }

      if (payload.status === "completed") {
        return payload;
      }

      if (syncMessage && payload.message) {
        syncMessage.textContent = payload.message;
      }
      await wait(2000);
    }

    throw new DOMException("Sync wait cancelled.", "AbortError");
  };

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-network-device-sync-form]");
    if (!form) return;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const originalHtml = button?.innerHTML || "Sync";
    const deviceId = new FormData(form).get("device_id");
    const modal = form.closest(".modal");
    const row = modal?.querySelector(`[data-network-device-row="${deviceId}"]`);
    const statusCell = row?.querySelector('[data-network-field="status"]');
    if (button) { button.disabled = true; button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Syncing'; }
    const progress = document.createElement("small");
    progress.className = "d-block text-primary mt-1";
    progress.textContent = "Syncing…";
    statusCell?.appendChild(progress);
    const syncState = { cancelled: false, abortController: new AbortController() };
    activeSync = syncState;
    progress.remove();
    showSyncOverlay("Starting network device sync...", "loading", "Network Device");
    try {
      const started = await fetch(form.action, { method: "POST", body: new FormData(form), headers: { Accept: "application/json" }, signal: syncState.abortController.signal });
      const startPayload = await started.json().catch(() => ({}));
      if (!started.ok || !startPayload.statusUrl) throw new Error(startPayload.error || "Network device sync could not start.");
      const deadline = Date.now() + 120000;
      let completed;
      while (Date.now() < deadline && !syncState.cancelled) {
        await wait(2000);
        const response = await fetch(startPayload.statusUrl, { headers: { Accept: "application/json" }, cache: "no-store", signal: syncState.abortController.signal });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || "Network device sync status could not be checked.");
        if (payload.status === "completed") { completed = payload.device; break; }
      }
      if (!completed) throw new Error("Sync did not finish. Check that collector agent 1.3.5 is running.");
      const setText = (field, value) => { const cell = row?.querySelector(`[data-network-field="${field}"]`); if (cell) cell.textContent = value; };
      setText("item", completed.item || "Network Device");
      setText("serial", completed.serial || "-");
      setText("firmware", completed.firmware || "Awaiting reading");
      const supplies = Array.isArray(completed.details?.supplies) ? completed.details.supplies : [];
      setText("supplies", supplies.length ? supplies.map((s) => `${s.name || "Cartridge"}: ${s.percent == null ? "Unavailable" : `${s.percent}%`}`).join(", ") : "Unavailable");
      if (statusCell) statusCell.innerHTML = completed.online ? '<span class="badge text-bg-success"><i class="bi bi-wifi"></i> Online</span>' : '<span class="badge text-bg-secondary"><i class="bi bi-wifi-off"></i> Offline</span>';
      showSyncOverlay("Network device information was refreshed successfully.", "success", "Network Device");
    } catch (error) {
      progress.textContent = error.message || "Sync failed.";
      progress.className = "d-block text-danger mt-1";
      showSyncOverlay(error.name === "AbortError" ? "Sync wait cancelled. The collector may still finish in the background." : (error.message || "Network device sync failed."), "error", "Network Device");
    } finally {
      if (activeSync === syncState) activeSync = null;
      if (button) { button.disabled = false; button.innerHTML = originalHtml; }
    }
  });

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-inventory-sync-form]");
    if (!form) return;

    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const originalHtml = button?.innerHTML;
    if (button) {
      button.disabled = true;
      button.innerHTML =
        '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Syncing';
    }
    const modal = form.closest(".modal");
    const deviceId = new FormData(form).get("device_id");
    const driverTab = modal?.querySelector('[id^="deviceDriversTab"].active');
    const syncKind = driverTab ? "drivers" : "software";
    const itemLabel = syncKind === "drivers" ? "Driver" : "Software";
    const requestUrl =
      syncKind === "drivers"
        ? form.dataset.driverSyncUrl || form.action
        : form.action;
    const syncState = {
      cancelled: false,
      abortController: new AbortController(),
    };
    activeSync = syncState;
    showSyncOverlay("Starting sync request...", "loading", itemLabel);

    try {
      const response = await fetch(requestUrl, {
        method: form.method || "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" },
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(payload.error || `${itemLabel} sync request failed.`);
      }
      showSyncOverlay(
        payload.message ||
          `Waiting for the endpoint agent to upload the latest ${syncKind === "drivers" ? "driver" : "software"} list.`,
        "loading",
        itemLabel,
      );
      const completed = await pollInventorySync(
        payload.statusUrl,
        syncState,
        syncKind,
      );
      if (syncKind === "drivers") {
        renderDriverPanel(modal, deviceId, completed.drivers || []);
        renderDriverUpdates(deviceId, completed.driverUpdates || []);
        showSyncOverlay(
          `Drivers loaded successfully. ${driverRecordLabel(completed.driverCount || 0)} are now displayed.`,
          "success",
          "Drivers",
        );
      } else {
        renderSoftwarePanel(modal, deviceId, completed.software || []);
        renderSoftwareUpdates(modal, deviceId, completed.softwareUpdates || []);
        showSyncOverlay(
          `Software loaded successfully. ${softwareRecordLabel(completed.softwareCount || 0)} are now displayed.`,
          "success",
          "Software",
        );
      }
    } catch (error) {
      if (error.name === "AbortError" || syncState.cancelled) {
        showSyncOverlay(
          "Sync wait cancelled. The endpoint may still finish in the background.",
          "error",
          itemLabel,
        );
      } else {
        showSyncOverlay(
          error.message ||
            `${itemLabel} sync failed. ${itemLabel} was not loaded.`,
          "error",
          itemLabel,
        );
      }
    } finally {
      if (activeSync === syncState) {
        activeSync = null;
      }
      if (button) {
        button.disabled = false;
        button.innerHTML = originalHtml;
      }
    }
  });

  document.querySelectorAll("[data-auto-show-modal]").forEach((modalEl) => {
    if (!window.bootstrap) return;
    const modal = new bootstrap.Modal(modalEl);
    modalEl.addEventListener(
      "shown.bs.modal",
      () => {
        modalEl
          .querySelector("[data-open-device-row]")
          ?.scrollIntoView({ block: "center", inline: "nearest" });
      },
      { once: true },
    );
    modal.show();
    modalEl.addEventListener(
      "hidden.bs.modal",
      () => {
        const fallback = modalEl.querySelector(
          ".modal-footer a[href], .modal-header a[href]",
        );
        if (fallback) {
          window.location.href = fallback.href;
        }
      },
      { once: true },
    );
  });

  const maintenanceSeenKey = (deviceId) => `asset-maintenance-seen-${deviceId}`;
  const markMaintenanceSeen = (button) => {
    const deviceId = button?.dataset.deviceId;
    const latestId = button?.dataset.latestMaintenanceId || "0";
    if (!deviceId || latestId === "0") return;

    localStorage.setItem(maintenanceSeenKey(deviceId), latestId);
    document.querySelectorAll("[data-maintenance-link]").forEach((link) => {
      if (
        link.dataset.deviceId === deviceId &&
        (link.dataset.latestMaintenanceId || "0") === latestId
      ) {
        link.classList.remove("is-alert");
      }
    });
  };

  document.querySelectorAll("[data-maintenance-link]").forEach((button) => {
    const deviceId = button.dataset.deviceId;
    const latestId = button.dataset.latestMaintenanceId || "0";
    if (!deviceId || latestId === "0") return;

    if (localStorage.getItem(maintenanceSeenKey(deviceId)) === latestId) {
      button.classList.remove("is-alert");
    }

    button.addEventListener("click", () => markMaintenanceSeen(button));
  });

  document
    .querySelectorAll("[data-software-update-trigger]")
    .forEach((button) => {
      syncSoftwareUpdateTriggerState(button.dataset.deviceId);
    });

  document
    .querySelectorAll("[data-driver-update-trigger]")
    .forEach((button) => {
      syncDriverUpdateTriggerState(button.dataset.deviceId);
    });

  const bulkItemsForForm = (form) =>
    form
      ? [...document.querySelectorAll("[data-bulk-select-item]")].filter(
          (input) => input.form === form && !input.disabled,
        )
      : [];

  const updateBulkDeleteState = (form) => {
    if (!form?.id) return;
    const items = bulkItemsForForm(form);
    const selected = items.filter((input) => input.checked);
    const submit = form.querySelector("[data-bulk-delete-submit]");
    const selectAllControls = [
      ...document.querySelectorAll(
        `[data-bulk-select-all][data-bulk-target="${form.id}"]`,
      ),
    ];

    if (submit) {
      const showBulkDelete = selected.length > 1;
      submit.hidden = !showBulkDelete;
      submit.disabled = !showBulkDelete;
      submit.innerHTML = showBulkDelete
        ? `<i class="bi bi-trash"></i> Delete selected (${selected.length.toLocaleString()})`
        : '<i class="bi bi-trash"></i> Delete selected';
    }
    selectAllControls.forEach((selectAll) => {
      selectAll.checked = items.length > 0 && selected.length === items.length;
      selectAll.indeterminate =
        selected.length > 0 && selected.length < items.length;
    });
  };

  document.addEventListener("change", (event) => {
    const selectAll = event.target.closest("[data-bulk-select-all]");
    if (selectAll) {
      const form = document.getElementById(selectAll.dataset.bulkTarget || "");
      bulkItemsForForm(form).forEach((input) => {
        input.checked = selectAll.checked;
      });
      updateBulkDeleteState(form);
      return;
    }

    const item = event.target.closest("[data-bulk-select-item]");
    if (item?.form) {
      updateBulkDeleteState(item.form);
    }
  });

  document
    .querySelectorAll(".bulk-delete-toolbar")
    .forEach(updateBulkDeleteState);

  const confirmDeleteForm = (form, event) => {
    if (event.defaultPrevented && form.dataset.confirmReady !== "1") {
      return false;
    }
    if (
      form.dataset.confirm &&
      form.dataset.confirmReady !== "1" &&
      form.dataset.confirmed !== "1"
    ) {
      event.preventDefault();
      if (deleteModal && deleteMessage && deleteButton) {
        pendingConfirmForm = form;
        if (deleteTitle) {
          deleteTitle.textContent = "Confirm Delete";
        }
        deleteMessage.textContent = form.dataset.confirm;
        deleteButton.innerHTML = '<i class="bi bi-trash"></i> Delete';
        deleteButton.classList.add("btn-danger");
        deleteButton.classList.remove("btn-warning");
        deleteModal.show();
        return false;
      }
      if (!confirm(form.dataset.confirm)) {
        return false;
      }
      form.dataset.confirmReady = "1";
      form.requestSubmit();
      return false;
    }
    delete form.dataset.confirmed;
    delete form.dataset.confirmReady;
    return true;
  };

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest(
      "[data-software-update-bulk-delete-form], [data-driver-update-bulk-delete-form]",
    );
    if (!form) return;
    if (!confirmDeleteForm(form, event)) {
      return;
    }

    const selected = bulkItemsForForm(form).filter((input) => input.checked);
    if (selected.length === 0) {
      event.preventDefault();
      updateBulkDeleteState(form);
      return;
    }

    event.preventDefault();
    const isDriver = form.matches("[data-driver-update-bulk-delete-form]");
    const rowAttribute = isDriver ? "driverUpdateId" : "softwareUpdateId";
    const rowSelector = isDriver
      ? "data-driver-update-id"
      : "data-software-update-id";
    const emptyHtml = isDriver
      ? driverUpdateEmptyHtml
      : softwareUpdateEmptyHtml;
    const updateModal = form.closest("[data-device-id]");
    const deviceId = updateModal?.dataset.deviceId;
    const button = form.querySelector("[data-bulk-delete-submit]");

    button?.setAttribute("disabled", "disabled");
    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" },
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(
          payload.error ||
            `Could not delete ${isDriver ? "driver" : "software"} update records.`,
        );
      }

      selected.forEach((input) => {
        const row =
          input.closest(`[${rowSelector}]`) ||
          updateModal?.querySelector(`[${rowSelector}="${input.value}"]`);
        row?.remove();
      });
      if (updateModal) {
        const remaining = [
          ...updateModal.querySelectorAll(`[${rowSelector}]`),
        ].filter(
          (row) => Number.parseInt(row.dataset[rowAttribute] || "0", 10) > 0,
        );
        if (remaining.length === 0) {
          const updateBody = updateModal.querySelector(
            isDriver
              ? "[data-driver-update-body]"
              : "[data-software-update-body]",
          );
          if (updateBody) {
            updateBody.innerHTML = emptyHtml();
          }
        }
      }
      updateBulkDeleteState(form);
      if (deviceId) {
        if (isDriver) {
          syncDriverUpdateTriggerState(deviceId);
        } else {
          syncSoftwareUpdateTriggerState(deviceId);
        }
      }
      showFlash(
        `${payload.deleted || selected.length} ${isDriver ? "driver" : "software"} update record${(payload.deleted || selected.length) === 1 ? "" : "s"} deleted.`,
        "success",
      );
    } catch (error) {
      showFlash(
        error.message ||
          `Could not delete ${isDriver ? "driver" : "software"} update records.`,
        "danger",
      );
      updateBulkDeleteState(form);
    } finally {
      button?.removeAttribute("disabled");
      updateBulkDeleteState(form);
    }
  });

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-software-update-delete-form]");
    if (!form) return;
    if (event.defaultPrevented && form.dataset.confirmReady !== "1") {
      return;
    }
    if (
      form.dataset.confirm &&
      form.dataset.confirmReady !== "1" &&
      form.dataset.confirmed !== "1"
    ) {
      event.preventDefault();
      if (deleteModal && deleteMessage && deleteButton) {
        pendingConfirmForm = form;
        if (deleteTitle) {
          deleteTitle.textContent = "Confirm Delete";
        }
        deleteMessage.textContent = form.dataset.confirm;
        deleteButton.innerHTML = '<i class="bi bi-trash"></i> Delete';
        deleteButton.classList.add("btn-danger");
        deleteButton.classList.remove("btn-warning");
        deleteModal.show();
        return;
      }
      if (!confirm(form.dataset.confirm)) {
        return;
      }
      form.dataset.confirmReady = "1";
      form.requestSubmit();
      return;
    }

    delete form.dataset.confirmed;
    delete form.dataset.confirmReady;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const row = form.closest("[data-software-update-id]");
    const updateModal = form.closest("[data-device-id]");
    const deviceId = updateModal?.dataset.deviceId;

    button?.setAttribute("disabled", "disabled");
    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        headers: {
          Accept: "application/json",
        },
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(
          payload.error || "Could not delete software update record.",
        );
      }

      row?.remove();
      const bulkForm = updateModal?.querySelector(
        "[data-software-update-bulk-delete-form]",
      );
      if (bulkForm) {
        updateBulkDeleteState(bulkForm);
      }
      if (updateModal && softwareUpdateIds(updateModal).length === 0) {
        const updateBody = updateModal.querySelector(
          "[data-software-update-body]",
        );
        if (updateBody) {
          updateBody.innerHTML = softwareUpdateEmptyHtml();
        }
      }
      if (deviceId) {
        syncSoftwareUpdateTriggerState(deviceId);
      }
    } catch (error) {
      showFlash(
        error.message || "Could not delete software update record.",
        "danger",
      );
    } finally {
      button?.removeAttribute("disabled");
    }
  });

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-driver-update-delete-form]");
    if (!form) return;
    if (event.defaultPrevented && form.dataset.confirmReady !== "1") {
      return;
    }
    if (
      form.dataset.confirm &&
      form.dataset.confirmReady !== "1" &&
      form.dataset.confirmed !== "1"
    ) {
      event.preventDefault();
      if (deleteModal && deleteMessage && deleteButton) {
        pendingConfirmForm = form;
        if (deleteTitle) {
          deleteTitle.textContent = "Confirm Delete";
        }
        deleteMessage.textContent = form.dataset.confirm;
        deleteButton.innerHTML = '<i class="bi bi-trash"></i> Delete';
        deleteButton.classList.add("btn-danger");
        deleteButton.classList.remove("btn-warning");
        deleteModal.show();
        return;
      }
      if (!confirm(form.dataset.confirm)) {
        return;
      }
      form.dataset.confirmReady = "1";
      form.requestSubmit();
      return;
    }

    delete form.dataset.confirmed;
    delete form.dataset.confirmReady;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const row = form.closest("[data-driver-update-id]");
    const updateModal = form.closest("[data-device-id]");
    const deviceId = updateModal?.dataset.deviceId;

    button?.setAttribute("disabled", "disabled");
    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" },
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(
          payload.error || "Could not delete driver update record.",
        );
      }

      row?.remove();
      const bulkForm = updateModal?.querySelector(
        "[data-driver-update-bulk-delete-form]",
      );
      if (bulkForm) {
        updateBulkDeleteState(bulkForm);
      }
      if (updateModal && driverUpdateIds(updateModal).length === 0) {
        const updateBody = updateModal.querySelector(
          "[data-driver-update-body]",
        );
        if (updateBody) {
          updateBody.innerHTML = driverUpdateEmptyHtml();
        }
      }
      if (deviceId) {
        syncDriverUpdateTriggerState(deviceId);
      }
    } catch (error) {
      showFlash(
        error.message || "Could not delete driver update record.",
        "danger",
      );
    } finally {
      button?.removeAttribute("disabled");
    }
  });

  const modalParents = new WeakMap();

  document.addEventListener("hidden.bs.modal", (event) => {
    if (!window.bootstrap) return;

    const childModalEl = event.target;
    if (!(childModalEl instanceof HTMLElement)) return;
    if (childModalEl.dataset.childModalOpening === "1") {
      delete childModalEl.dataset.childModalOpening;
      return;
    }

    const parentModalEl = modalParents.get(childModalEl);
    modalParents.delete(childModalEl);
    if (!parentModalEl || !document.body.contains(parentModalEl)) return;

    bootstrap.Modal.getOrCreateInstance(parentModalEl).show();
  });

  document.addEventListener(
    "click",
    (event) => {
      if (!window.bootstrap) return;

      const opener = event.target.closest("[data-child-modal-target]");
      if (!opener) return;

      const parentModalEl =
        opener.closest(".modal.show") ||
        (activeDeviceActions?.menu.contains(opener)
          ? activeDeviceActions.parentModal
          : null);
      if (!parentModalEl?.id) return;

      const childSelector = opener.dataset.childModalTarget;
      const childModalEl = childSelector
        ? document.querySelector(childSelector)
        : null;
      if (!childModalEl) return;

      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      closeDeviceActionsMenu();
      if (opener.matches("[data-maintenance-link]")) {
        markMaintenanceSeen(opener);
      }
      if (opener.matches("[data-software-update-trigger]")) {
        markSoftwareUpdatesSeen(opener);
      }
      if (opener.matches("[data-driver-update-trigger]")) {
        markDriverUpdatesSeen(opener);
      }

      const parentModal = bootstrap.Modal.getOrCreateInstance(parentModalEl);
      const childModal = bootstrap.Modal.getOrCreateInstance(childModalEl);
      modalParents.set(childModalEl, parentModalEl);
      parentModalEl.dataset.childModalOpening = "1";

      parentModalEl.addEventListener(
        "hidden.bs.modal",
        () => {
          childModal.show();
        },
        { once: true },
      );

      parentModal.hide();
    },
    true,
  );

  const syncChangeAssignmentOptions = (form) => {
    const office = form?.querySelector("[data-change-office-select]");
    const employee = form?.querySelector("[data-change-employee-select]");
    if (!employee) return;
    if (!office) {
      employee.dispatchEvent(new Event("change", { bubbles: true }));
      return;
    }

    const officeId = office.value;
    [...employee.options].forEach((option) => {
      const hidden =
        option.value !== "" && option.dataset.officeId !== officeId;
      option.hidden = hidden;
      option.disabled = hidden;
    });

    if (employee.selectedOptions[0]?.disabled) {
      employee.value = "";
    }
    employee.dispatchEvent(new Event("change", { bubbles: true }));
  };

  document.querySelectorAll("[data-change-assignment-form]").forEach((form) => {
    syncChangeAssignmentOptions(form);
    form
      .querySelector("[data-change-office-select]")
      ?.addEventListener("change", () => syncChangeAssignmentOptions(form));
    form
      .closest(".modal")
      ?.addEventListener("shown.bs.modal", () =>
        syncChangeAssignmentOptions(form),
      );
  });

  const syncDashboardFilterUsers = (form) => {
    const office = form?.querySelector("[data-dashboard-office-filter]");
    const user = form?.querySelector("[data-dashboard-user-filter]");
    if (!office || !user) return;
    const officeId = office.value;
    [...user.options].forEach((option) => {
      if (option.value === "") {
        option.hidden = false;
        option.disabled = false;
        return;
      }
      const visible =
        !officeId ||
        option.dataset.allOffices === "1" ||
        option.dataset.officeId === officeId;
      option.hidden = !visible;
      option.disabled = !visible;
    });
    if (user.selectedOptions[0]?.disabled) {
      user.value = "";
    }
  };

  document.querySelectorAll("[data-dashboard-filter-form]").forEach((form) => {
    syncDashboardFilterUsers(form);
    form
      .querySelector("[data-dashboard-office-filter]")
      ?.addEventListener("change", () => syncDashboardFilterUsers(form));
  });

  const assignmentSelectControls = new WeakMap();

  const assignmentOptionText = (option) => (option.textContent || "").trim();

  const closeAssignmentSearchControl = (control) => {
    const dropdownInstance = window.bootstrap?.Dropdown?.getOrCreateInstance(
      control.toggle,
    );
    if (dropdownInstance) {
      dropdownInstance.hide();
      return;
    }

    control.dropdown.querySelector(".dropdown-menu")?.classList.remove("show");
    control.toggle.classList.remove("show");
    control.toggle.setAttribute("aria-expanded", "false");
  };

  const renderAssignmentSearchOptions = (select) => {
    const control = assignmentSelectControls.get(select);
    if (!control) return;

    const query = control.search.value.trim().toLowerCase();
    control.options.innerHTML = "";
    const visible = [...select.options]
      .filter(
        (option) => option.value !== "" && !option.hidden && !option.disabled,
      )
      .filter(
        (option) =>
          !query ||
          (option.dataset.search || assignmentOptionText(option))
            .toLowerCase()
            .includes(query),
      );

    visible.forEach((option) => {
      const button = document.createElement("button");
      button.className = `assignment-search-option${option.selected ? " is-selected" : ""}`;
      button.type = "button";
      button.textContent = assignmentOptionText(option);
      button.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();

        // Select the exact option that produced this search result. Software
        // names can be duplicated across devices or versions, so assigning
        // select.value may otherwise select a hidden duplicate and get reset.
        [...select.options].forEach((candidate) => {
          candidate.selected = candidate === option;
        });
        select.dispatchEvent(new Event("change", { bubbles: true }));
        closeAssignmentSearchControl(control);
      });
      control.options.append(button);
    });

    if (!visible.length) {
      const empty = document.createElement("div");
      empty.className = "assignment-search-empty";
      empty.textContent = "No matching option";
      control.options.append(empty);
    }
  };

  const updateAssignmentSearchControl = (select) => {
    const control = assignmentSelectControls.get(select);
    if (!control) return;
    const selected = select.selectedOptions[0];
    control.label.textContent = selected?.value
      ? assignmentOptionText(selected)
      : assignmentOptionText(select.options[0]);
    renderAssignmentSearchOptions(select);
  };

  const filterAssignmentSelect = (select, accepts) => {
    if (!select) return;
    [...select.options].forEach((option) => {
      if (option.value === "") {
        option.hidden = false;
        option.disabled = false;
        return;
      }
      const visible = !accepts || accepts(option);
      option.hidden = !visible;
      option.disabled = !visible;
    });
    if (select.selectedOptions[0]?.disabled) {
      select.value = "";
    }
    updateAssignmentSearchControl(select);
  };

  const createAssignmentSearchControl = (select) => {
    if (assignmentSelectControls.has(select)) return;

    const dropdown = document.createElement("div");
    dropdown.className = "dropdown assignment-search-dropdown";
    dropdown.innerHTML = `
            <button class="btn btn-outline-secondary assignment-search-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <span></span>
            </button>
            <div class="dropdown-menu assignment-search-menu">
                <div class="assignment-search-head">
                    <input class="form-control" type="search" autocomplete="off" inputmode="search" aria-label="Search options">
                    <button class="btn btn-outline-secondary assignment-search-close" type="button" aria-label="Close search"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="assignment-search-options"></div>
            </div>
        `;
    select.after(dropdown);
    const control = {
      dropdown,
      toggle: dropdown.querySelector(".assignment-search-toggle"),
      label: dropdown.querySelector(".assignment-search-toggle span"),
      search: dropdown.querySelector("input"),
      close: dropdown.querySelector(".assignment-search-close"),
      options: dropdown.querySelector(".assignment-search-options"),
    };
    control.search.placeholder = select.dataset.searchPlaceholder || "Search";
    const refreshSearchResults = () => renderAssignmentSearchOptions(select);
    control.search.addEventListener("input", refreshSearchResults);
    control.search.addEventListener("search", refreshSearchResults);
    control.search.addEventListener("keyup", refreshSearchResults);
    control.close.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      control.search.value = "";
      renderAssignmentSearchOptions(select);
      closeAssignmentSearchControl(control);
      control.toggle.focus();
    });
    control.search.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      event.preventDefault();
      closeAssignmentSearchControl(control);
      control.toggle.focus();
    });
    select.addEventListener("change", () =>
      updateAssignmentSearchControl(select),
    );
    dropdown.addEventListener("shown.bs.dropdown", () => {
      control.search.value = "";
      renderAssignmentSearchOptions(select);
      control.search.focus();
    });
    dropdown.addEventListener("hidden.bs.dropdown", () => {
      control.search.value = "";
      renderAssignmentSearchOptions(select);
    });
    assignmentSelectControls.set(select, control);
    updateAssignmentSearchControl(select);
  };

  document
    .querySelectorAll("[data-searchable-assignment-select]")
    .forEach(createAssignmentSearchControl);

  const syncUpdateAssignmentForm = (form) => {
    const type = form?.querySelector("[data-assignment-type]");
    const office = form?.querySelector("[data-assignment-office]");
    const device = form?.querySelector("[data-assignment-device]");
    const user = form?.querySelector("[data-assignment-user]");
    const software = form?.querySelector("[data-assignment-software]");
    const softwareWrap = form?.querySelector("[data-assignment-software-wrap]");
    if (!type || !office || !device || !user || !software) return;

    const officeId = office.value;
    const deviceId = device.value;
    const needsSoftware = type.value === "software_update";
    softwareWrap.hidden = !needsSoftware;
    software.required = needsSoftware;

    filterAssignmentSelect(
      device,
      (option) => !officeId || option.dataset.officeId === officeId,
    );
    filterAssignmentSelect(
      user,
      (option) =>
        !officeId ||
        option.dataset.officeId === officeId ||
        option.dataset.allOffices === "1",
    );
    filterAssignmentSelect(
      software,
      (option) => !deviceId || option.dataset.deviceId === deviceId,
    );
    if (!needsSoftware) {
      software.value = "";
      updateAssignmentSearchControl(software);
    }
  };

  document.querySelectorAll("[data-update-assignment-form]").forEach((form) => {
    form
      .querySelectorAll("[data-searchable-assignment-select]")
      .forEach(createAssignmentSearchControl);
    syncUpdateAssignmentForm(form);
    form
      .querySelectorAll(
        "[data-assignment-type], [data-assignment-office], [data-assignment-device], [data-assignment-user], [data-assignment-software]",
      )
      .forEach((select) => {
        select.addEventListener("change", () => {
          updateAssignmentSearchControl(select);
          syncUpdateAssignmentForm(form);
        });
      });
    form
      .closest(".modal")
      ?.addEventListener("shown.bs.modal", () =>
        syncUpdateAssignmentForm(form),
      );
  });

  const editAssignmentForm = document.querySelector(
    "[data-update-assignment-edit-form]",
  );
  document
    .querySelectorAll("[data-edit-update-assignment]")
    .forEach((button) => {
      button.addEventListener("click", () => {
        if (!editAssignmentForm) return;

        editAssignmentForm.querySelector("[data-assignment-edit-id]").value =
          button.dataset.assignmentId || "";
        editAssignmentForm.querySelector("[data-assignment-type]").value =
          button.dataset.taskType || "";
        editAssignmentForm.querySelector("[data-assignment-office]").value =
          button.dataset.officeId || "";
        editAssignmentForm.querySelector("[data-assignment-device]").value =
          button.dataset.deviceId || "";
        editAssignmentForm.querySelector("[data-assignment-user]").value =
          button.dataset.userId || "";
        editAssignmentForm.querySelector("[data-assignment-software]").value =
          button.dataset.softwareName || "";
        editAssignmentForm.querySelector("[data-assignment-note]").value =
          button.dataset.note || "";
        syncUpdateAssignmentForm(editAssignmentForm);
        editAssignmentForm
          .querySelectorAll(
            "[data-assignment-office], [data-assignment-device], [data-assignment-user], [data-assignment-software]",
          )
          .forEach(updateAssignmentSearchControl);
      });
    });

  const syncOfficeScopedForm = (form) => {
    const office = form?.querySelector("[data-office-source-select]");
    if (!office) return;

    const officeId = office.value;
    form.querySelectorAll("[data-office-scoped-select]").forEach((select) => {
      filterAssignmentSelect(
        select,
        (option) => !officeId || option.dataset.officeId === officeId,
      );
    });
  };

  document.querySelectorAll("[data-office-scoped-form]").forEach((form) => {
    syncOfficeScopedForm(form);
    form
      .querySelector("[data-office-source-select]")
      ?.addEventListener("change", () => syncOfficeScopedForm(form));
    form
      .closest(".modal")
      ?.addEventListener("shown.bs.modal", () => syncOfficeScopedForm(form));
  });

  document.querySelectorAll(".data-table").forEach((table) => {
    const usesExternalSearch = table.dataset.externalSearch === "1";
    let instance = null;
    if (window.DataTable) {
      instance = new DataTable(table, {
        pageLength: 25,
        order: [],
        paging: !usesExternalSearch,
        info: !usesExternalSearch,
        lengthChange: !usesExternalSearch,
      });
    }

    if (usesExternalSearch && table.id) {
      const wrapper =
        document.getElementById(`${table.id}_wrapper`) ||
        table.closest(".dt-container");
      wrapper?.classList.add("has-external-search");
      document
        .querySelectorAll(`[data-table-search="#${table.id}"]`)
        .forEach((input) => {
          const runTableSearch = () => {
            if (instance) {
              instance.search(input.value).draw();
              return;
            }

            const query = input.value.trim().toLowerCase();
            table.querySelectorAll("tbody tr").forEach((row) => {
              row.hidden = query !== "" && !row.textContent.toLowerCase().includes(query);
            });
          };
          input.addEventListener("input", runTableSearch);
          input.addEventListener("search", runTableSearch);
        });
    }
  });

  document.addEventListener("click", (event) => {
    const clearButton = event.target.closest("[data-table-search-clear]");
    if (!clearButton) return;

    const input = clearButton
      .closest(".table-search")
      ?.querySelector("[data-table-search]");
    if (!input) return;

    input.value = "";
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.focus();
  });

  const filterSoftwareTable = (input) => {
    const table = document.querySelector(input.dataset.softwareSearch || "");
    if (!table) return;

    const panel = input.closest(".software-panel");
    const rows = [...table.querySelectorAll("tbody tr")].filter(
      (row) => !row.querySelector("[colspan]"),
    );
    const emptySearch = table
      .closest(".software-table-wrap")
      ?.querySelector(".software-empty-search");
    const count = panel?.querySelector("[data-software-count]");
    const query = input.value.trim().toLowerCase();
    let visibleRows = 0;

    rows.forEach((row) => {
      const matches = row.textContent.toLowerCase().includes(query);
      row.hidden = !matches;
      if (matches) {
        visibleRows += 1;
      }
    });

    if (count) {
      count.textContent = `${visibleRows.toLocaleString()} shown`;
    }
    emptySearch?.classList.toggle("d-none", query === "" || visibleRows > 0);
  };

  document
    .querySelectorAll("[data-software-search]")
    .forEach(filterSoftwareTable);

  document.addEventListener("input", (event) => {
    if (event.target.matches("[data-software-search]")) {
      filterSoftwareTable(event.target);
    }
  });

  document.addEventListener("click", (event) => {
    const clearButton = event.target.closest("[data-software-clear]");
    if (!clearButton) return;

    const input = clearButton
      .closest(".software-search")
      ?.querySelector("[data-software-search]");
    if (!input) return;

    input.value = "";
    filterSoftwareTable(input);
    input.focus();
  });

  const filterOfficeOptions = () => {
    const type = document.getElementById("officeType");
    const office = document.getElementById("officeId");
    if (!type || !office) return;
    [...office.options].forEach((option) => {
      option.hidden = option.value !== "" && option.dataset.type !== type.value;
    });
  };
  document
    .getElementById("officeType")
    ?.addEventListener("change", filterOfficeOptions);
  filterOfficeOptions();

  const chartColors = ["#0869ff"];
  const renderBar = (id, horizontal = false) => {
    const el = document.getElementById(id);
    if (!el || !window.Chart) return;
    new Chart(el, {
      type: "bar",
      data: {
        labels: JSON.parse(el.dataset.labels || "[]"),
        datasets: [
          {
            data: JSON.parse(el.dataset.values || "[]"),
            backgroundColor: horizontal
              ? [
                  "#0869ff",
                  "#12b85f",
                  "#ff7b18",
                  "#7b1dff",
                  "#f02655",
                  "#0ea5e9",
                ]
              : "#0869ff",
            borderRadius: horizontal ? 4 : 3,
            barPercentage: horizontal ? 0.58 : 0.5,
            categoryPercentage: horizontal ? 0.72 : 0.66,
          },
        ],
      },
      options: {
        indexAxis: horizontal ? "y" : "x",
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: "#070f2d",
            padding: 12,
            displayColors: false,
          },
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: {
              color: horizontal ? "#edf2f9" : "transparent",
              drawBorder: false,
            },
            ticks: {
              color: "#070f2d",
              precision: horizontal ? 0 : undefined,
              font: { size: 13, weight: "700" },
            },
            title: horizontal
              ? {
                  display: true,
                  text: el.dataset.axisTitle || "Activity Score",
                  color: "#425070",
                  font: { size: 12, weight: "700" },
                }
              : { display: false },
          },
          y: {
            beginAtZero: true,
            grid: {
              color: horizontal ? "transparent" : "#e6edf6",
              drawBorder: false,
            },
            ticks: {
              color: "#070f2d",
              autoSkip: !horizontal,
              padding: horizontal ? 10 : 0,
              precision: 0,
              font: { size: 13, weight: "700" },
            },
          },
        },
      },
    });
  };
  renderBar("deviceUpdateChart");

  document.querySelectorAll("[data-collector-settings-form]").forEach((form) => {
    const office = form.querySelector("[data-collector-office]");
    const computer = form.querySelector("[data-collector-computer]");
    if (!office || !computer) return;
    const filterCollectors = () => {
      const officeId = office.value;
      let available = 0;
      Array.from(computer.options).forEach((option, index) => {
        if (index === 0) return;
        const visible = officeId !== "" && option.dataset.officeId === officeId;
        option.hidden = !visible;
        option.disabled = !visible;
        if (visible) available += 1;
      });
      if (computer.selectedOptions[0]?.disabled) computer.value = "";
      computer.disabled = !officeId || available === 0;
    };
    office.addEventListener("change", filterCollectors);
    filterCollectors();
  });

  const officeDeviceChart = document.getElementById("devicesByOfficeChart");
  if (officeDeviceChart && window.Chart) {
    const officeTickLabel = (label) => {
      const text = String(label || "").trim();
      if (!window.matchMedia("(max-width: 1024px)").matches || text.length <= 16) {
        return text;
      }

      const words = text.split(/\s+/);
      const lines = [];
      words.forEach((word) => {
        const lastLine = lines[lines.length - 1] || "";
        if (!lastLine || `${lastLine} ${word}`.length > 16) {
          lines.push(word);
        } else {
          lines[lines.length - 1] = `${lastLine} ${word}`;
        }
      });
      return lines;
    };

    const officeSegmentValueLabels = {
      id: "officeSegmentValueLabels",
      afterDatasetsDraw(chart) {
        const { ctx } = chart;
        ctx.save();
        ctx.font = "800 14px Arial, sans-serif";
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";

        chart.data.datasets.forEach((dataset, datasetIndex) => {
          ctx.fillStyle = dataset.segmentLabelColor || "#ffffff";
          const meta = chart.getDatasetMeta(datasetIndex);
          if (meta.hidden) return;

          meta.data.forEach((bar, dataIndex) => {
            const value = Number(dataset.data[dataIndex] || 0);
            if (value <= 0) return;

            const position = bar.getCenterPoint();
            ctx.fillText(String(value), position.x, position.y);
          });
        });
        ctx.restore();
      },
    };

    const officeDeviceDistributionChart = new Chart(officeDeviceChart, {
      type: "bar",
      data: {
        labels: JSON.parse(officeDeviceChart.dataset.labels || "[]"),
        datasets: [
          {
            label: "Computer",
            data: JSON.parse(officeDeviceChart.dataset.online || "[]"),
            backgroundColor: "#FD5632",
            segmentLabelColor: "#ffffff",
            borderRadius: 5,
            borderSkipped: false,
          },
          {
            label: "Network Device",
            data: JSON.parse(officeDeviceChart.dataset.network || "[]"),
            backgroundColor: "#E3F0FF",
            segmentLabelColor: "#20364d",
            borderRadius: 5,
            borderSkipped: false,
          },
          {
            label: "Device Not Connect",
            data: JSON.parse(officeDeviceChart.dataset.disconnected || "[]"),
            backgroundColor: "#ADC2D7",
            segmentLabelColor: "#20364d",
            borderRadius: 5,
            borderSkipped: false,
          },
          {
            label: "Unassigned Device",
            data: JSON.parse(officeDeviceChart.dataset.unassigned || "[]"),
            backgroundColor: "#2C1E75",
            segmentLabelColor: "#ffffff",
            borderRadius: 5,
            borderSkipped: false,
          },
        ],
      },
      plugins: [officeSegmentValueLabels],
      options: {
        // Size from the real layout container so viewport changes and browser
        // zoom cannot leave the drawing wider than its visible panel.
        responsive: false,
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: "#071630",
            padding: 12,
            displayColors: true,
          },
        },
        scales: {
          x: {
            stacked: true,
            border: { display: false },
            grid: { color: "#edf2f2", drawBorder: false },
            ticks: {
              autoSkip: false,
              maxRotation: 0,
              minRotation: 0,
              color: "#071630",
              font: { size: 12, weight: "750" },
              callback(value) {
                return officeTickLabel(this.getLabelForValue(value));
              },
            },
          },
          y: {
            stacked: true,
            beginAtZero: true,
            border: { display: false },
            grid: { color: "#e7eeee", drawBorder: false },
            ticks: {
              precision: 0,
              color: "#53617e",
              font: { size: 12, weight: "700" },
            },
          },
        },
      },
    });

    const officeDeviceChartBody = officeDeviceChart.closest(".office-device-chart-body");
    const resizeOfficeDeviceChart = () => {
      if (!officeDeviceChartBody) return;

      const width = Math.max(1, Math.floor(officeDeviceChartBody.clientWidth));
      const height = Math.max(1, Math.floor(officeDeviceChartBody.clientHeight));
      officeDeviceDistributionChart.resize(width, height);
    };

    requestAnimationFrame(resizeOfficeDeviceChart);
    window.addEventListener("resize", resizeOfficeDeviceChart, { passive: true });

    if (officeDeviceChartBody && "ResizeObserver" in window) {
      const officeDeviceChartObserver = new ResizeObserver(() => {
        requestAnimationFrame(resizeOfficeDeviceChart);
      });
      officeDeviceChartObserver.observe(officeDeviceChartBody);
    }
  }
});
document.querySelectorAll("[data-password-toggle]").forEach((button) => {
  button.addEventListener("click", () => {
    const input = document.getElementById("loginPassword");
    if (!input) return;
    const isVisible = input.type === "text";
    input.type = isVisible ? "password" : "text";
    button.setAttribute(
      "aria-label",
      isVisible ? "Show password" : "Hide password",
    );
    button.setAttribute("aria-pressed", String(!isVisible));
    const icon = button.querySelector("i");
    if (icon) icon.className = isVisible ? "bi bi-eye-slash" : "bi bi-eye";
  });
});
