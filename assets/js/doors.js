(function () {
  const panels = document.querySelectorAll("[data-door-id]");
  if (!panels.length) return;

  const hideToast = (node) => {
    if (!node || node.classList.contains("is-out")) return;
    node.classList.add("is-out");
    window.setTimeout(() => node.remove(), 300);
  };

  const toast = (text, error) => {
    document.querySelectorAll("[data-door-toast]").forEach((node) => node.remove());
    const node = document.createElement("div");
    node.className = error ? "toast toast-error" : "toast toast-success";
    node.setAttribute("role", error ? "alert" : "status");
    node.dataset.toast = "";
    node.dataset.doorToast = "";
    const icon = document.createElement("span");
    icon.className = "toast-icon";
    icon.setAttribute("aria-hidden", "true");
    icon.innerHTML = error
      ? '<svg viewBox="0 0 24 24" fill="none"><path d="M12 8v5.5M12 16.5h.01M12 3.5l9.2 16H2.8L12 3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none"><path d="M20 7 10.2 17 4 11.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const copy = document.createElement("div");
    copy.className = "toast-copy";
    const label = document.createElement("span");
    label.className = "toast-label";
    label.textContent = error ? "Chyba" : "Hotovo";
    const message = document.createElement("p");
    message.textContent = text;
    copy.append(label, message);
    const close = document.createElement("button");
    close.type = "button";
    close.className = "toast-close";
    close.setAttribute("aria-label", "Zavřít hlášku");
    close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    close.addEventListener("click", () => hideToast(node));
    const progress = document.createElement("span");
    progress.className = "toast-progress";
    progress.setAttribute("aria-hidden", "true");
    node.append(icon, copy, close, progress);
    document.body.append(node);
    window.setTimeout(() => hideToast(node), 3600);
  };

  const paint = (panel, door, moveControls) => {
    if (!door) return;
    const open = !!door.open;
    const nav = document.querySelector("[data-door-nav]");
    if (nav && String(door.id) === nav.getAttribute("data-door-nav-id") && typeof window.privofitDoorNav === "function") {
      window.privofitDoorNav(open);
    }
    panel.classList.toggle("is-open", open);
    panel.classList.toggle("is-closed", !open);

    if (moveControls) {
      panel.dataset.syncing = "1";
      panel.querySelectorAll("[data-door-input]").forEach((input) => {
        input.checked = input.value === (open ? "open" : "close");
      });
      panel.dataset.syncing = "";
    }

    const online = !!door.online;
    const onlineStat = panel.querySelector("[data-door-online]");
    if (onlineStat) {
      onlineStat.classList.toggle("is-on", online);
      onlineStat.classList.toggle("is-off", !online);
    }
    const onlineLabel = panel.querySelector("[data-door-online-label]");
    const onlineSub = panel.querySelector("[data-door-online-sub]");
    if (onlineLabel) onlineLabel.textContent = online ? "Online" : "Offline";
    if (onlineSub) onlineSub.textContent = online ? "Zámek odpovídá" : "Zámek teď neodpovídá";

    if (door.battery === null || door.battery === undefined) return;
    const percent = Math.max(0, Math.min(100, Number(door.battery)));
    const low = !!door.battery_low || percent <= 15;
    const stat = panel.querySelector("[data-door-battery-stat]");
    const bat = panel.querySelector("[data-door-bat]");
    const fill = panel.querySelector("[data-door-fill]");
    const label = panel.querySelector("[data-door-battery]");
    const sub = panel.querySelector("[data-door-battery-sub]");
    if (stat) stat.classList.toggle("is-low", low);
    if (bat) bat.classList.toggle("is-low", low);
    if (fill) fill.style.width = percent + "%";
    if (label) label.textContent = percent + "%";
    if (sub) sub.textContent = low ? "Dochází, vyměň článek" : "Nabití je v pořádku";
  };

  const statusUrl = panels[0].getAttribute("data-door-status") || "";

  const refresh = async () => {
    if (!statusUrl || document.hidden) return;
    try {
      const response = await fetch(statusUrl, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      if (!response.ok) return;
      const json = await response.json();
      const doors = json && json.data && json.data.doors;
      if (!Array.isArray(doors)) return;
      doors.forEach((door) => {
        const panel = document.querySelector('[data-door-id="' + door.id + '"]');
        if (!panel || panel.dataset.sending === "1") return;
        paint(panel, door, true);
      });
    } catch (error) {
      /* další odečet to zkusí znovu */
    }
  };

  panels.forEach((panel) => {
    const form = panel.querySelector("[data-door-form]");
    if (!form) return;
    form.classList.add("is-live");
    form.addEventListener("submit", (event) => {
      if (form.classList.contains("is-live")) event.preventDefault();
    });
    form.querySelectorAll("[data-door-input]").forEach((input) => {
      input.addEventListener("change", async () => {
        if (panel.dataset.syncing === "1" || panel.dataset.sending === "1" || input.disabled) return;
        const previous = input.value === "open" ? "close" : "open";
        panel.dataset.sending = "1";
        form.classList.add("is-sending");
        try {
          const response = await fetch(form.action, {
            method: "POST",
            body: new FormData(form),
            headers: { Accept: "application/json" },
            credentials: "same-origin",
          });
          const json = await response.json();
          if (!response.ok || !json.success) {
            throw new Error((json && json.message) || "Příkaz se nepodařilo odeslat.");
          }
          paint(panel, json.data && json.data.door, true);
          toast(json.message || (input.value === "open" ? "Dveře byly otevřeny." : "Dveře byly zavřeny."), false);
        } catch (error) {
          panel.dataset.syncing = "1";
          form.querySelectorAll("[data-door-input]").forEach((item) => {
            item.checked = item.value === previous;
          });
          panel.dataset.syncing = "";
          toast(error instanceof Error ? error.message : "Příkaz se nepodařilo odeslat.", true);
        } finally {
          panel.dataset.sending = "";
          form.classList.remove("is-sending");
        }
      });
    });
  });

  window.setInterval(refresh, 12000);
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) refresh();
  });
})();
