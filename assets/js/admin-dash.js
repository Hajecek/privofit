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

  const parseMoney = (value) => {
    const digits = String(value || "").replace(/[^\d-]/g, "");
    return Number(digits) || 0;
  };
  const formatMoney = (value) => {
    const n = Math.round(Number(value) || 0);
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, "\u00a0") + " Kč";
  };
  const reducedMotion = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const moneyFrames = new WeakMap();
  const countMoney = (node, nextText, duration = 780) => {
    if (!node || nextText == null || nextText === "") return;
    const label = String(nextText);
    const to = parseMoney(label);
    const from = parseMoney(node.textContent);
    const pending = moneyFrames.get(node);
    if (pending) cancelAnimationFrame(pending);
    if (from === to || reducedMotion()) {
      node.textContent = label;
      node.classList.remove("is-recount");
      return;
    }
    node.classList.remove("is-recount");
    void node.offsetWidth;
    node.classList.add("is-recount");
    const start = performance.now();
    const step = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      if (t < 1) {
        node.textContent = formatMoney(from + (to - from) * eased);
        moneyFrames.set(node, requestAnimationFrame(step));
        return;
      }
      node.textContent = label;
      node.classList.remove("is-recount");
      moneyFrames.delete(node);
    };
    moneyFrames.set(node, requestAnimationFrame(step));
  };

  const income = document.querySelector("[data-chart-total]");
  if (income) {
    const target = income.getAttribute("data-count-to") || income.textContent.trim();
    if (!income.hasAttribute("data-count-to") && target && !reducedMotion() && parseMoney(target) > 0) {
      income.textContent = formatMoney(0);
    }
    countMoney(income, target, 1100);
  }

  const fillLegend = (list, segments, withAmount) => {
    if (!list) return;
    const previous = new Map();
    if (withAmount) {
      list.querySelectorAll("li").forEach((item) => {
        const name = item.querySelector("span")?.textContent || "";
        const amount = item.querySelector("strong");
        if (name && amount) previous.set(name, amount.textContent);
      });
    }
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
        const next = String(segment.amount_label || "");
        amount.textContent = previous.get(label.textContent) || formatMoney(0);
        item.append(label, document.createTextNode(" "), amount);
        countMoney(amount, next);
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

  const scheduleState = (value) => (["live", "next", "pay", "past"].includes(value) ? value : "next");

  const scheduleTip = (row, hidden = false) => {
    const tip = document.createElement("span");
    tip.className = "sched-tip";
    if (hidden) tip.setAttribute("aria-hidden", "true");
    const avatarUrl = safeUrl(row.avatar);
    if (avatarUrl) {
      const img = document.createElement("img");
      img.className = "sched-tip-avatar";
      img.alt = "";
      img.src = avatarUrl;
      tip.append(img);
    }
    const copy = document.createElement("span");
    copy.className = "sched-tip-copy";
    const name = document.createElement("strong");
    name.textContent = String(row.name || "Zákazník");
    copy.append(name);
    const username = String(row.username || "").trim();
    if (username !== "") {
      const user = document.createElement("span");
      user.className = "sched-tip-user";
      user.textContent = "@" + username;
      copy.append(user);
    }
    const when = document.createElement("span");
    when.className = "sched-tip-when";
    when.textContent = String(row.time || "") + "–" + String(row.end || "") + (row.badge ? " · " + String(row.badge) : "");
    copy.append(when);
    const meta = String(row.meta || "");
    if (meta !== "") {
      const detail = document.createElement("span");
      detail.className = "sched-tip-meta";
      detail.textContent = meta;
      copy.append(detail);
    }
    tip.append(copy);
    return tip;
  };

  const scheduleAvatar = (row) => {
    const avatarUrl = safeUrl(row.avatar);
    if (!avatarUrl) return null;
    const img = document.createElement("img");
    img.className = "sched-avatar";
    img.alt = "";
    img.src = avatarUrl;
    return img;
  };

  const scheduleRow = (row) => {
    const state = scheduleState(row.state || (row.live ? "live" : row.past ? "past" : "next"));
    const item = document.createElement("li");
    item.className = "sched-row is-" + state;
    const avatar = scheduleAvatar(row);
    if (avatar) item.append(avatar);
    const time = document.createElement("time");
    const start = document.createElement("strong");
    start.textContent = String(row.time || "");
    const end = document.createElement("span");
    end.textContent = String(row.end || "");
    time.append(start, end);
    const copy = document.createElement("div");
    copy.className = "sched-copy";
    const href = safeUrl(row.href);
    const who = href ? document.createElement("a") : document.createElement("strong");
    if (href) who.setAttribute("href", href);
    who.textContent = String(row.name || "Zákazník");
    const meta = document.createElement("span");
    meta.textContent = String(row.meta || "");
    copy.append(who, meta);
    const side = document.createElement("div");
    side.className = "sched-side";
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
    item.append(time, copy, scheduleTip(row, true), side);
    return item;
  };

  const renderSchedule = (schedule) => {
    const body = document.querySelector("[data-dash-schedule-body]");
    if (!body) return;
    const modal = document.querySelector("[data-cust-modal]");
    if (modal && !modal.hidden) return;
    const pastOpen = body.querySelector(".sched-done")?.open === true;
    body.replaceChildren();
    const items = Array.isArray(schedule?.items) ? schedule.items : [];
    if (items.length === 0) {
      const empty = document.createElement("p");
      empty.className = "adash-empty";
      empty.textContent = String(schedule?.empty || "Dnes žádné rezervace.");
      body.append(empty);
      return;
    }
    const active = items.filter((row) => scheduleState(row.state || (row.past ? "past" : "next")) !== "past");
    const past = items.filter((row) => scheduleState(row.state || (row.past ? "past" : "next")) === "past");
    const wrap = document.createElement("div");
    wrap.className = "sched";
    const counts = schedule?.counts && typeof schedule.counts === "object" ? schedule.counts : {};
    const stats = document.createElement("div");
    stats.className = "sched-stats";
    [
      ["live", "teď", "live"],
      ["next", "čeká", "next"],
      ["pay", "platba", "pay"],
      ["past", "hotovo", "past"],
    ].forEach(([key, label, tone]) => {
      const count = Number(counts[key]) || 0;
      if (count < 1) return;
      const stat = document.createElement("span");
      stat.className = "sched-stat is-" + tone;
      const strong = document.createElement("strong");
      strong.textContent = String(count);
      stat.append(strong, document.createTextNode(" " + label));
      stats.append(stat);
    });
    wrap.append(stats);
    const windowData = schedule?.window && typeof schedule.window === "object" ? schedule.window : {};
    const rail = document.createElement("div");
    rail.className = "sched-rail";
    const labels = document.createElement("div");
    labels.className = "sched-rail-labels";
    labels.setAttribute("aria-hidden", "true");
    (Array.isArray(windowData.ticks) ? windowData.ticks : []).forEach((tick) => {
      const mark = document.createElement("span");
      const edge = String(tick.edge || "");
      if (edge === "start") mark.className = "is-start";
      if (edge === "end") mark.className = "is-end";
      const left = Math.max(0, Math.min(100, Number(tick.left) || 0));
      mark.style.left = left + "%";
      mark.textContent = String(tick.label || "");
      labels.append(mark);
    });
    const track = document.createElement("div");
    track.className = "sched-rail-track";
    const nowLeft = windowData.now !== null && windowData.now !== undefined && windowData.now !== ""
      ? Math.max(0, Math.min(100, Number(windowData.now) || 0))
      : null;
    if (nowLeft !== null) {
      const elapsed = document.createElement("i");
      elapsed.className = "sched-rail-elapsed";
      elapsed.style.width = nowLeft + "%";
      track.append(elapsed);
    }
    (Array.isArray(windowData.ticks) ? windowData.ticks : []).forEach((tick) => {
      const hair = document.createElement("i");
      const edge = String(tick.edge || "");
      hair.className = "sched-tick" + (edge === "start" ? " is-start" : edge === "end" ? " is-end" : "");
      hair.style.left = Math.max(0, Math.min(100, Number(tick.left) || 0)) + "%";
      track.append(hair);
    });
    items.forEach((row) => {
      const left = Math.max(0, Math.min(100, Number(row.left) || 0));
      const width = Math.max(1.4, Math.min(100 - left, Number(row.width) || 1.4));
      const center = left + width / 2;
      const align = center < 22 ? "start" : center > 78 ? "end" : "mid";
      const href = safeUrl(row.href);
      const block = href ? document.createElement("a") : document.createElement("span");
      block.className = "sched-mark is-" + scheduleState(row.state || (row.live ? "live" : row.past ? "past" : "next")) + " is-tip-" + align;
      block.style.left = left + "%";
      block.style.width = width + "%";
      if (href) block.setAttribute("href", href);
      else block.tabIndex = 0;
      const label = [String(row.name || "Zákazník"), String(row.time || "") + "–" + String(row.end || "")].filter(Boolean).join(", ");
      block.setAttribute("aria-label", label);
      block.append(scheduleTip(row));
      track.append(block);
    });
    if (nowLeft !== null) {
      const now = document.createElement("i");
      now.className = "sched-now";
      now.style.left = nowLeft + "%";
      track.append(now);
    }
    rail.append(labels, track);
    wrap.append(rail);
    if (active.length > 0) {
      const list = document.createElement("ul");
      list.className = "sched-list";
      active.forEach((row) => list.append(scheduleRow(row)));
      wrap.append(list);
    }
    if (past.length > 0) {
      const done = document.createElement("details");
      done.className = "sched-done";
      done.open = pastOpen || active.length === 0;
      const summary = document.createElement("summary");
      summary.append(document.createTextNode("Hotovo "));
      const count = document.createElement("span");
      count.textContent = String(past.length);
      summary.append(count);
      const list = document.createElement("ul");
      list.className = "sched-list is-done";
      past.forEach((row) => list.append(scheduleRow(row)));
      done.append(summary, list);
      wrap.append(done);
    }
    body.append(wrap);
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

  let donutFrame = 0;
  const morphDonut = (fromSegments, toSegments) => {
    if (!Charts || !donut) {
      donutSegments = toSegments;
      return;
    }
    const token = ++donutFrame;
    const from = new Map((fromSegments || []).map((segment) => [segment.key, Number(segment.amount) || 0]));
    const snap = () => {
      donutSegments = toSegments;
      Charts.drawDonut(donut, donutBreakdown, donutSegments);
    };
    if (reducedMotion()) {
      snap();
      return;
    }
    const start = performance.now();
    const step = (now) => {
      if (token !== donutFrame) return;
      const t = Math.min(1, (now - start) / 780);
      const eased = 1 - Math.pow(1 - t, 3);
      donutSegments = toSegments.map((segment) => ({
        ...segment,
        amount: (from.get(segment.key) || 0) + ((Number(segment.amount) || 0) - (from.get(segment.key) || 0)) * eased,
      }));
      Charts.drawDonut(donut, donutBreakdown, donutSegments);
      if (t < 1) requestAnimationFrame(step);
      else snap();
    };
    requestAnimationFrame(step);
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
    countMoney(total, money.total);
    countMoney(today, money.today);
    countMoney(yesterday, money.yesterday);
    if (count) count.textContent = String(Number(money.count) || 0);
    const customers = document.querySelector("[data-link-customers]");
    const interest = document.querySelector("[data-link-interest]");
    if (customers) customers.textContent = String(Number(kpis.customers) || 0) + " účtů";
    if (interest) interest.textContent = String(Number(kpis.interest) || 0) + " leadů";
    const nextChart = JSON.stringify(data.chart || {});
    if (Charts && data.chart && nextChart !== chartKey) {
      chartKey = nextChart;
      const previousSegments = donutSegments;
      const nextSegments = data.chart.segments || [];
      donutBreakdown = data.chart.breakdown || {};
      chartHandle?.update(data.chart.days || [], {
        segments: nextSegments,
        tipEl: tip,
        onSelect: onSelectDay,
      });
      morphDonut(previousSegments, nextSegments);
      const legend = root?.querySelector("[data-chart-legend]");
      fillLegend(legend, nextSegments, false);
      fillLegend(document.querySelector("[data-chart-donut-legend]"), nextSegments, true);
      if (legend) {
        const kind = root?.querySelector("[data-chart-kind].is-on")?.getAttribute("data-chart-kind") || "line";
        legend.hidden = kind === "curve" || legend.children.length === 0;
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
