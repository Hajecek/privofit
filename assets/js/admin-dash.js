(() => {
  const notifyBtn = document.querySelector("[data-notify-open]");
  const notifyPanel = document.querySelector("[data-notify]");
  const readKey = "privofit-notify-read";
  const readMap = () => {
    try {
      const parsed = JSON.parse(localStorage.getItem(readKey) || "{}");
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch {
      return {};
    }
  };
  const applyRead = () => {
    if (!notifyPanel) return;
    const map = readMap();
    notifyPanel.querySelectorAll("[data-notify-id]").forEach((item) => {
      const id = item.getAttribute("data-notify-id") || "";
      const read = id !== "" && !!map[id];
      item.classList.toggle("is-read", read);
      item.classList.toggle("is-unread", !read);
      const seen = item.querySelector("[data-notify-seen]");
      if (seen) {
        seen.setAttribute("aria-pressed", read ? "true" : "false");
        seen.setAttribute("aria-label", read ? "Označit jako neviděné" : "Označit jako viděné");
      }
    });
    notifyCount();
  };
  const toggleRead = (id) => {
    if (!id) return;
    const map = readMap();
    if (map[id]) delete map[id];
    else map[id] = 1;
    localStorage.setItem(readKey, JSON.stringify(map));
    applyRead();
  };
  const notifyCount = () => {
    if (!notifyPanel) return;
    const unread = notifyPanel.querySelectorAll(".adash-notes > li.is-unread:not([hidden])").length;
    const visible = notifyPanel.querySelectorAll(".adash-notes > li:not([hidden])").length;
    const badge = document.querySelector("[data-notify-count]");
    const empty = notifyPanel.querySelector("[data-notify-empty]");
    if (badge) {
      badge.hidden = unread === 0;
      badge.textContent = String(unread);
    }
    if (empty) empty.hidden = visible !== 0;
  };
  window.privofitNotifyCount = notifyCount;
  if (notifyPanel) {
    applyRead();
    notifyPanel.addEventListener("click", (event) => {
      const seen = event.target.closest("[data-notify-seen]");
      if (!seen || !notifyPanel.contains(seen)) return;
      event.preventDefault();
      event.stopPropagation();
      const note = seen.closest("[data-notify-id]");
      toggleRead(note?.getAttribute("data-notify-id") || "");
    });
  }
  if (notifyBtn && notifyPanel) {
    const setOpen = (open) => {
      notifyPanel.hidden = !open;
      notifyBtn.setAttribute("aria-expanded", open ? "true" : "false");
    };
    notifyBtn.addEventListener("click", (event) => {
      event.stopPropagation();
      setOpen(notifyPanel.hidden);
    });
    document.addEventListener("click", (event) => {
      if (notifyPanel.hidden) return;
      if (notifyPanel.contains(event.target) || notifyBtn.contains(event.target)) return;
      setOpen(false);
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") setOpen(false);
    });
  }

  const Charts = window.PrivofitCharts;
  if (!Charts) return;

  const root = document.querySelector("[data-dash-chart]");
  if (!root) return;

  const payload = Charts.parsePayload(root);
  if (!payload) return;

  const line = root.querySelector("[data-dash-line]");
  const donut = root.querySelector("[data-dash-donut]");
  const tip = root.querySelector("[data-chart-tip]");
  const dayUrl = root.getAttribute("data-day-url") || "";

  if (line) {
    Charts.mountSeriesChart(root, line, payload.days || [], {
      segments: payload.segments || [],
      tipEl: tip,
      onSelect: (day) => {
        if (!day?.date || !dayUrl) return;
        window.location.href = dayUrl + encodeURIComponent(day.date);
      },
    });
  }

  if (donut) {
    const segments = payload.segments || [];
    const paintDonut = () => Charts.drawDonut(donut, payload.breakdown || {}, segments);
    paintDonut();
    window.addEventListener("resize", paintDonut);
  }
})();
