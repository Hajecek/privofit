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
  const root = document.querySelector("[data-dash-chart]");
  const payload = Charts && root ? Charts.parsePayload(root) : null;
  const line = root?.querySelector("[data-dash-line]");
  const donut = root?.querySelector("[data-dash-donut]");
  const tip = root?.querySelector("[data-chart-tip]");
  const dayUrl = root?.getAttribute("data-day-url") || "";
  let chartHandle = null;
  let donutBreakdown = payload?.breakdown || {};
  let donutSegments = payload?.segments || [];
  let chartKey = payload ? JSON.stringify(payload) : "";

  const onSelectDay = (day) => {
    if (!day?.date || !dayUrl) return;
    window.location.href = dayUrl + encodeURIComponent(day.date);
  };

  if (Charts && root && payload && line) {
    chartHandle = Charts.mountSeriesChart(root, line, payload.days || [], {
      segments: donutSegments,
      tipEl: tip,
      onSelect: onSelectDay,
    });
  }

  if (Charts && donut) {
    const paintDonut = () => Charts.drawDonut(donut, donutBreakdown, donutSegments);
    paintDonut();
    window.addEventListener("resize", paintDonut);
  }

  const dash = document.querySelector("[data-dash-root]");
  const liveUrl = dash?.getAttribute("data-dash-url") || "";
  if (!dash || !liveUrl) return;

  const safeColor = (color) => (/^#[0-9a-fA-F]{6}$/.test(String(color || "")) ? String(color) : "#9aa49c");
  const safeUrl = (value) => {
    const raw = String(value || "");
    if (raw.startsWith("/") && !raw.startsWith("//")) return raw;
    try {
      const parsed = new URL(raw, window.location.origin);
      if (parsed.origin === window.location.origin) return parsed.pathname + parsed.search + parsed.hash;
    } catch {
      return "";
    }
    return "";
  };
  const badgeClass = (value) => {
    const allowed = ["badge-warn", "badge-muted", "badge-done", "badge-ok"];
    return allowed.includes(value) ? value : "badge-muted";
  };
  const kindClass = (value) => (/^is-[a-z]+$/.test(String(value || "")) ? String(value) : "");
  const toneClass = (value) => (value === "bad" || value === "warn" ? "is-" + value : "");

  const fillLegend = (list, segments, withAmount) => {
    if (!list) return;
    list.replaceChildren();
    (Array.isArray(segments) ? segments : []).forEach((segment) => {
      const item = document.createElement("li");
      const swatch = document.createElement("i");
      swatch.style.background = safeColor(segment.color);
      item.append(swatch);
      if (withAmount) {
        const label = document.createElement("span");
        label.textContent = String(segment.label || "");
        const amount = document.createElement("strong");
        amount.textContent = String(segment.amount_label || "");
        item.append(label, document.createTextNode(" "), amount);
      } else {
        item.append(document.createTextNode(String(segment.label || "")));
      }
      list.append(item);
    });
  };

  const goIcon = () => {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-width", "1.8");
    svg.setAttribute("aria-hidden", "true");
    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", "M9 6l6 6-6 6");
    svg.append(path);
    const wrap = document.createElement("span");
    wrap.className = "adash-note-go";
    wrap.setAttribute("aria-hidden", "true");
    wrap.append(svg);
    return wrap;
  };

  const renderSchedule = (schedule) => {
    const body = document.querySelector("[data-dash-schedule-body]");
    const count = document.querySelector("[data-dash-schedule-count]");
    if (count) count.textContent = String(Number(schedule?.count) || 0);
    if (!body) return;
    const modal = document.querySelector("[data-cust-modal]");
    if (modal && !modal.hidden) return;
    body.replaceChildren();
    if (schedule?.now && typeof schedule.now === "object") {
      const now = document.createElement("div");
      now.className = "adash-now";
      const eyebrow = document.createElement("span");
      eyebrow.className = "eyebrow";
      eyebrow.textContent = "PRÁVĚ TEĎ";
      const name = document.createElement("strong");
      name.textContent = String(schedule.now.name || "Zákazník");
      const slot = document.createElement("span");
      slot.textContent = String(schedule.now.slot || "");
      now.append(eyebrow, name, slot);
      body.append(now);
    }
    const items = Array.isArray(schedule?.items) ? schedule.items : [];
    if (items.length === 0) {
      const empty = document.createElement("p");
      empty.className = "adash-empty";
      empty.textContent = String(schedule?.empty || "Dnes žádné rezervace.");
      body.append(empty);
      return;
    }
    const list = document.createElement("ul");
    list.className = "adash-timeline";
    items.forEach((row) => {
      const item = document.createElement("li");
      item.className = "adash-tl" + (row.live ? " is-live" : row.past ? " is-past" : "");
      const time = document.createElement("time");
      time.textContent = String(row.time || "");
      const end = document.createElement("span");
      end.textContent = "–" + String(row.end || "");
      time.append(end);
      const copy = document.createElement("div");
      const href = safeUrl(row.href);
      const who = href ? document.createElement("a") : document.createElement("strong");
      if (href) who.setAttribute("href", href);
      who.textContent = String(row.name || "Zákazník");
      const meta = document.createElement("span");
      meta.textContent = String(row.meta || "");
      copy.append(who, meta);
      const side = document.createElement("div");
      side.className = "adash-tl-side";
      const badge = document.createElement("span");
      badge.className = "badge " + badgeClass(row.badge_class);
      badge.textContent = String(row.badge || "");
      side.append(badge);
      const cancelUrl = safeUrl(row.cancel_url);
      if (cancelUrl) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn btn-danger btn-sm";
        button.setAttribute("data-cust-open", "cancel-reservation");
        button.setAttribute("data-name", String(row.cancel_name || row.name || ""));
        button.setAttribute("data-action", cancelUrl);
        button.textContent = "Zrušit";
        side.append(button);
      }
      item.append(time, copy, side);
      list.append(item);
    });
    body.append(list);
  };

  const renderNotices = (notes) => {
    const list = document.querySelector(".adash-notes");
    if (!list) return;
    list.querySelectorAll("[data-notify-dynamic]").forEach((node) => node.remove());
    (Array.isArray(notes) ? notes : []).forEach((note) => {
      const tone = toneClass(note.tone);
      const item = document.createElement("li");
      item.className = "adash-note is-unread" + (tone ? " " + tone : "");
      item.setAttribute("data-notify-dynamic", "");
      item.setAttribute("data-notify-id", String(note.key || ""));
      const seen = document.createElement("button");
      seen.type = "button";
      seen.className = "adash-note-seen";
      seen.setAttribute("data-notify-seen", "");
      seen.setAttribute("aria-pressed", "false");
      seen.setAttribute("aria-label", "Označit jako viděné");
      const href = safeUrl(note.href);
      const link = href ? document.createElement("a") : document.createElement("div");
      link.className = "adash-note-link";
      if (href) link.setAttribute("href", href);
      const avatarUrl = safeUrl(note.avatar);
      if (avatarUrl) {
        const img = document.createElement("img");
        img.className = "adash-note-avatar";
        img.alt = "";
        img.src = avatarUrl;
        link.append(img);
      } else {
        const mark = document.createElement("span");
        mark.className = "adash-note-mark";
        mark.setAttribute("aria-hidden", "true");
        mark.textContent = String(note.initials || "P").slice(0, 2);
        link.append(mark);
      }
      const body = document.createElement("span");
      body.className = "adash-note-body";
      const top = document.createElement("span");
      top.className = "adash-note-top";
      const title = document.createElement("strong");
      title.textContent = String(note.title || "");
      const meta = document.createElement("span");
      meta.className = "adash-note-meta";
      const kind = document.createElement("span");
      const extra = kindClass(note.kind_class);
      kind.className = "adash-note-kind" + (extra ? " " + extra : "");
      kind.textContent = String(note.kind || "");
      const when = document.createElement("time");
      when.textContent = String(note.when || "");
      meta.append(kind, when);
      top.append(title, meta);
      const text = document.createElement("span");
      text.className = "adash-note-text";
      text.textContent = String(note.text || "");
      body.append(top, text);
      link.append(body);
      if (href) link.append(goIcon());
      item.append(seen, link);
      list.append(item);
    });
    applyRead();
  };

  const applyLive = (data) => {
    if (!data || typeof data !== "object") return;
    const clock = document.querySelector("[data-dash-clock]");
    if (clock && data.clock) clock.textContent = String(data.clock);
    const occupancy = data.occupancy || {};
    const live = document.querySelector("[data-dash-occupancy]");
    if (live) {
      live.classList.toggle("is-busy", !!occupancy.occupied);
      live.classList.toggle("is-free", !occupancy.occupied);
    }
    const status = document.querySelector("[data-dash-status]");
    const detail = document.querySelector("[data-dash-detail]");
    if (status && occupancy.status) status.textContent = String(occupancy.status);
    if (detail) detail.textContent = String(occupancy.detail || "");
    const kpis = data.kpis || {};
    Object.keys(kpis).forEach((key) => {
      const card = document.querySelector('[data-kpi="' + key + '"]');
      const strong = card?.querySelector("strong");
      if (strong) strong.textContent = String(Number(kpis[key]) || 0);
      if (key === "failed_access" && card) card.classList.toggle("is-alert", Number(kpis[key]) > 0);
    });
    const money = data.money || {};
    const total = document.querySelector("[data-chart-total]");
    const today = document.querySelector("[data-rev-today]");
    const yesterday = document.querySelector("[data-rev-yesterday]");
    const count = document.querySelector("[data-rev-count]");
    if (total && money.total) total.textContent = String(money.total);
    if (today && money.today) today.textContent = String(money.today);
    if (yesterday && money.yesterday) yesterday.textContent = String(money.yesterday);
    if (count) count.textContent = String(Number(money.count) || 0);
    const customers = document.querySelector("[data-link-customers]");
    const interest = document.querySelector("[data-link-interest]");
    if (customers) customers.textContent = String(Number(kpis.customers) || 0) + " účtů";
    if (interest) interest.textContent = String(Number(kpis.interest) || 0) + " leadů";
    const nextChart = JSON.stringify(data.chart || {});
    if (Charts && data.chart && nextChart !== chartKey) {
      chartKey = nextChart;
      donutSegments = data.chart.segments || [];
      donutBreakdown = data.chart.breakdown || {};
      chartHandle?.update(data.chart.days || [], {
        segments: donutSegments,
        tipEl: tip,
        onSelect: onSelectDay,
      });
      if (donut) Charts.drawDonut(donut, donutBreakdown, donutSegments);
      const legend = root?.querySelector("[data-chart-legend]");
      fillLegend(legend, donutSegments, false);
      fillLegend(document.querySelector("[data-chart-donut-legend]"), donutSegments, true);
      if (legend) {
        const kind = root?.querySelector("[data-chart-kind].is-on")?.getAttribute("data-chart-kind") || "line";
        legend.hidden = kind === "line" || legend.children.length === 0;
      }
    }
    renderSchedule(data.schedule || {});
    renderNotices(data.notices || []);
    if (typeof data.rev === "string" && data.rev !== "") dash.setAttribute("data-dash-rev", data.rev);
  };

  let checking = false;
  let rev = dash.getAttribute("data-dash-rev") || "";
  const refresh = async () => {
    if (checking || document.visibilityState === "hidden") return;
    checking = true;
    try {
      const response = await fetch(liveUrl, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
        cache: "no-store",
      });
      if (response.status === 401) {
        window.location.reload();
        return;
      }
      if (!response.ok) return;
      const body = await response.json();
      const data = body && body.data ? body.data : body;
      if (!data || typeof data !== "object") return;
      const clock = document.querySelector("[data-dash-clock]");
      if (clock && data.clock) clock.textContent = String(data.clock);
      if (typeof data.rev === "string" && data.rev === rev) return;
      rev = typeof data.rev === "string" ? data.rev : rev;
      applyLive(data);
    } catch {
      /* další odečet to zkusí znovu */
    } finally {
      checking = false;
    }
  };

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") refresh();
  });
  window.addEventListener("focus", refresh);
  window.addEventListener("online", refresh);
  window.setInterval(refresh, 8000);
})();
