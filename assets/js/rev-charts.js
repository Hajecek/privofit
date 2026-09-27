(() => {
  const formatMoney = (value) => {
    const n = Math.round(Number(value) || 0);
    return n.toLocaleString("cs-CZ") + " Kč";
  };

  const parsePayload = (node) => {
    if (!node) return null;
    const raw = node.getAttribute("data-chart");
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  };

    const fitCanvas = (canvas) => {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(1, Math.floor(rect.width));
    const h = Math.max(1, Math.floor(rect.height));
    const nextW = Math.max(1, Math.floor(w * ratio));
    const nextH = Math.max(1, Math.floor(h * ratio));
    if (canvas.width !== nextW || canvas.height !== nextH) {
      const sx = window.scrollX;
      const sy = window.scrollY;
      canvas.width = nextW;
      canvas.height = nextH;
      if (window.scrollX !== sx || window.scrollY !== sy) window.scrollTo(sx, sy);
    }
    const ctx = canvas.getContext("2d");
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    return { ctx, w, h };
  };

  const reduceMotion = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const easeOut = (t) => 1 - Math.pow(1 - t, 3);
  const introPlayed = new WeakSet();

  const SERIES = [
    { key: "reservations", label: "Vstupné", color: "#c6f21a" },
    { key: "memberships", label: "Členství", color: "#6ec8ff" },
    { key: "other", label: "Ostatní", color: "#9aa49c" },
  ];

  const esc = (value) =>
    String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");

  const safeColor = (color) => (/^#[0-9a-fA-F]{6}$/.test(String(color || "")) ? String(color) : "#9aa49c");

  const partsFor = (day, segments) => {
    const source = Array.isArray(segments) && segments.length ? segments : SERIES;
    const bag = day && day.parts && typeof day.parts === "object" ? day.parts : null;
    const parts = source
      .map((series) => ({
        key: series.key,
        label: series.label,
        color: safeColor(series.color),
        value: bag ? Number(bag[series.key]) || 0 : Number(day[series.key]) || 0,
      }))
      .filter((part) => part.value > 0);
    if (parts.length === 0 && (Number(day.amount) || 0) > 0) {
      return [{ key: "amount", label: "Celkem", color: "#c6f21a", value: Number(day.amount) || 0 }];
    }
    return parts;
  };

  const dayTotal = (day, segments) => {
    const split = partsFor(day, segments).reduce((sum, part) => sum + part.value, 0);
    return Math.max(Number(day.amount) || 0, split);
  };

  const tipMarkup = (day, segments) => {
    const parts = partsFor(day, segments).filter((part) => part.key !== "amount");
    let html =
      "<strong>" +
      esc(formatMoney(day.amount)) +
      "</strong><span>" +
      esc(day.label || day.date || "") +
      " · " +
      (Number(day.count) || 0) +
      " plat.</span>";
    parts.forEach((part) => {
      html +=
        '<span class="chart-tip-row"><i style="background:' +
        part.color +
        '"></i>' +
        esc(part.label) +
        " " +
        esc(formatMoney(part.value)) +
        "</span>";
    });
    return html;
  };

  const drawGrid = (ctx, w, pad, plotH) => {
    ctx.strokeStyle = "rgba(232, 240, 228, 0.08)";
    ctx.lineWidth = 1;
    for (let i = 0; i < 4; i++) {
      const y = pad.t + (plotH * i) / 3;
      ctx.beginPath();
      ctx.moveTo(pad.l, y);
      ctx.lineTo(w - pad.r, y);
      ctx.stroke();
    }
  };

  const drawDayLabels = (ctx, days, pad, step, h) => {
    const labelEvery = days.length > 20 ? 5 : days.length > 10 ? 2 : 1;
    ctx.fillStyle = "rgba(232, 240, 228, 0.45)";
    ctx.font = "600 11px Figtree, sans-serif";
    ctx.textAlign = "center";
    const indexes = [];
    days.forEach((_, i) => {
      if (i % labelEvery === 0 || i === days.length - 1) indexes.push(i);
    });
    if (indexes.length >= 2) {
      const last = indexes[indexes.length - 1];
      const prev = indexes[indexes.length - 2];
      const lastX = pad.l + step * last + step / 2;
      const prevX = pad.l + step * prev + step / 2;
      if (lastX - prevX < 28) indexes.splice(indexes.length - 2, 1);
    }
    indexes.forEach((i) => {
      const x = pad.l + step * i + step / 2;
      ctx.fillText(String(days[i].label || ""), x, h - 8);
    });
  };

  const bindPlot = (canvas, state, opts) => {
    const tip = opts.tipEl || null;
    const onSelect = typeof opts.onSelect === "function" ? opts.onSelect : null;

    const findIndex = (clientX) => {
      const rect = canvas.getBoundingClientRect();
      const x = clientX - rect.left;
      for (let i = 0; i < state.bars.length; i++) {
        if (x >= state.bars[i].left && x < state.bars[i].right) return i;
      }
      return -1;
    };

    const showTip = (i, clientX, clientY) => {
      if (!tip || i < 0 || !state.bars[i]) {
        if (tip) tip.hidden = true;
        return;
      }
      tip.hidden = false;
      tip.innerHTML = tipMarkup(state.bars[i].day, opts.segments);
      const parent = tip.offsetParent || document.body;
      const prect = parent.getBoundingClientRect();
      const tipW = tip.offsetWidth || 168;
      tip.style.left = Math.min(prect.width - tipW - 8, Math.max(8, clientX - prect.left - tipW / 2)) + "px";
      tip.style.top = Math.max(8, clientY - prect.top - tip.offsetHeight - 14) + "px";
    };

    const onMove = (event) => {
      const i = findIndex(event.clientX);
      if (i !== state.hover) {
        state.hover = i;
        state.paint();
      }
      showTip(i, event.clientX, event.clientY);
    };

    const onLeave = () => {
      state.hover = -1;
      state.paint();
      if (tip) tip.hidden = true;
    };

    const onClick = (event) => {
      const i = findIndex(event.clientX);
      if (i >= 0 && state.bars[i] && onSelect) onSelect(state.bars[i].day);
    };

    const onResize = () => state.paint();

    canvas.addEventListener("pointermove", onMove);
    canvas.addEventListener("pointerleave", onLeave);
    canvas.addEventListener("click", onClick);
    window.addEventListener("resize", onResize);

    return {
      destroy() {
        canvas.removeEventListener("pointermove", onMove);
        canvas.removeEventListener("pointerleave", onLeave);
        canvas.removeEventListener("click", onClick);
        window.removeEventListener("resize", onResize);
        if (tip) tip.hidden = true;
      },
    };
  };

  const wantsMotion = (_canvas, _kind, opts) => opts.animate !== false && !reduceMotion();

  const playIntro = (canvas, render, ms) => {
    if (reduceMotion() || introPlayed.has(canvas)) {
      render(1);
      return;
    }
    introPlayed.add(canvas);
    const start = performance.now();
    const tick = (now) => {
      const t = Math.min(1, (now - start) / ms);
      render(easeOut(t));
      if (t < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  const drawLineChart = (canvas, days, opts = {}) => {
    if (!canvas || !Array.isArray(days) || days.length === 0) return null;
    const accent = opts.accent || "#c6f21a";
    const soft = opts.soft || "rgba(198, 242, 26, 0.16)";

    const state = { hover: -1, bars: [], paint: () => {} };
    let reveal = wantsMotion(canvas, "line", opts) ? 0 : 1;
    let binding = null;
    let alive = true;

    const paint = () => {
      const { ctx, w, h } = fitCanvas(canvas);
      ctx.clearRect(0, 0, w, h);

      const pad = { t: 18, r: 12, b: 28, l: 8 };
      const plotW = w - pad.l - pad.r;
      const plotH = h - pad.t - pad.b;
      const max = Math.max(...days.map((day) => dayTotal(day, opts.segments)), 1);
      const step = plotW / Math.max(days.length, 1);
      const progress = reveal;
      state.bars = [];

      drawGrid(ctx, w, pad, plotH);

      const points = days.map((day, i) => {
        const amount = dayTotal(day, opts.segments);
        const x = pad.l + step * i + step / 2;
        const y = pad.t + plotH - (amount / max) * plotH;
        state.bars.push({ i, x, y, left: pad.l + step * i, right: pad.l + step * (i + 1), day });
        return { x, y };
      });

      ctx.save();
      ctx.beginPath();
      ctx.rect(0, 0, pad.l + plotW * progress, h);
      ctx.clip();

      const grad = ctx.createLinearGradient(0, pad.t, 0, pad.t + plotH);
      grad.addColorStop(0, soft);
      grad.addColorStop(1, "rgba(198, 242, 26, 0)");
      ctx.beginPath();
      points.forEach((p, i) => {
        if (i === 0) ctx.moveTo(p.x, p.y);
        else ctx.lineTo(p.x, p.y);
      });
      ctx.lineTo(points[points.length - 1].x, pad.t + plotH);
      ctx.lineTo(points[0].x, pad.t + plotH);
      ctx.closePath();
      ctx.fillStyle = grad;
      ctx.fill();

      ctx.beginPath();
      points.forEach((p, i) => {
        if (i === 0) ctx.moveTo(p.x, p.y);
        else ctx.lineTo(p.x, p.y);
      });
      ctx.strokeStyle = accent;
      ctx.lineWidth = 2.25;
      ctx.lineJoin = "round";
      ctx.lineCap = "round";
      ctx.stroke();
      ctx.restore();

      drawDayLabels(ctx, days, pad, step, h);

      if (state.hover >= 0 && state.bars[state.hover]) {
        const b = state.bars[state.hover];
        ctx.strokeStyle = "rgba(232, 240, 228, 0.2)";
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(b.x, pad.t);
        ctx.lineTo(b.x, pad.t + plotH);
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(b.x, b.y, 5, 0, Math.PI * 2);
        ctx.fillStyle = accent;
        ctx.fill();
        ctx.strokeStyle = "#0b1210";
        ctx.lineWidth = 2;
        ctx.stroke();
      }
    };

    state.paint = paint;
    binding = bindPlot(canvas, state, opts);

    if (reveal >= 1) {
      paint();
    } else {
      const start = performance.now();
      const tick = (now) => {
        if (!alive) return;
        const t = Math.min(1, (now - start) / 920);
        reveal = easeOut(t);
        paint();
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }

    return {
      redraw: paint,
      destroy: () => {
        alive = false;
        if (binding) binding.destroy();
      },
    };
  };

  const drawBarChart = (canvas, days, opts = {}) => {
    if (!canvas || !Array.isArray(days) || days.length === 0) return null;
    const state = { hover: -1, bars: [], paint: () => {} };
    let reveal = wantsMotion(canvas, "bar", opts) ? 0 : 1;
    let binding = null;
    let alive = true;

    const paint = () => {
      const { ctx, w, h } = fitCanvas(canvas);
      ctx.clearRect(0, 0, w, h);

      const pad = { t: 18, r: 12, b: 28, l: 8 };
      const plotW = w - pad.l - pad.r;
      const plotH = h - pad.t - pad.b;
      const max = Math.max(...days.map((d) => dayTotal(d, opts.segments)), 1);
      const step = plotW / Math.max(days.length, 1);
      const progress = reveal;
      const gap = Math.min(10, step * 0.34);
      const barW = Math.max(3, step - gap);
      state.bars = [];

      drawGrid(ctx, w, pad, plotH);

      days.forEach((day, i) => {
        const left = pad.l + step * i;
        const x = left + (step - barW) / 2;
        const parts = partsFor(day, opts.segments);
        const sum = parts.reduce((total, part) => total + part.value, 0);
        const totalH = (sum / max) * plotH * progress;
        const base = pad.t + plotH;
        const top = base - totalH;
        state.bars.push({ i, x, y: top, left, right: left + step, day });

        if (state.hover === i) {
          ctx.fillStyle = "rgba(232, 240, 228, 0.06)";
          ctx.fillRect(left, pad.t, step, plotH);
        }
        if (totalH < 0.5) return;

        const radius = Math.min(5, barW / 2, totalH / 2);
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(x, base);
        ctx.lineTo(x, top + radius);
        ctx.quadraticCurveTo(x, top, x + radius, top);
        ctx.lineTo(x + barW - radius, top);
        ctx.quadraticCurveTo(x + barW, top, x + barW, top + radius);
        ctx.lineTo(x + barW, base);
        ctx.closePath();
        ctx.clip();

        let cursor = base;
        parts.forEach((part, idx) => {
          const segH = (part.value / sum) * totalH;
          const gap = idx > 0 ? 1.5 : 0;
          cursor -= segH;
          ctx.fillStyle = part.color;
          ctx.fillRect(x - 1, cursor, barW + 2, Math.max(0, segH - gap));
        });
        ctx.restore();
      });

      drawDayLabels(ctx, days, pad, step, h);
    };

    state.paint = paint;
    binding = bindPlot(canvas, state, opts);

    if (reveal >= 1) {
      paint();
    } else {
      const start = performance.now();
      const tick = (now) => {
        if (!alive) return;
        const t = Math.min(1, (now - start) / 700);
        reveal = easeOut(t);
        paint();
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }

    return {
      redraw: paint,
      destroy: () => {
        alive = false;
        if (binding) binding.destroy();
      },
    };
  };

  const drawAreaChart = (canvas, days, opts = {}) => {
    if (!canvas || !Array.isArray(days) || days.length === 0) return null;
    const state = { hover: -1, bars: [], paint: () => {} };
    let reveal = wantsMotion(canvas, "area", opts) ? 0 : 1;
    let binding = null;
    let alive = true;
    const segments = Array.isArray(opts.segments) ? opts.segments.filter((segment) => segment && segment.key) : [];

    const paint = () => {
      const { ctx, w, h } = fitCanvas(canvas);
      ctx.clearRect(0, 0, w, h);

      const pad = { t: 18, r: 12, b: 28, l: 8 };
      const plotW = w - pad.l - pad.r;
      const plotH = h - pad.t - pad.b;
      const max = Math.max(...days.map((day) => dayTotal(day, opts.segments)), 1);
      const step = plotW / Math.max(days.length, 1);
      const progress = reveal;
      const xAt = (i) => pad.l + step * i + step / 2;
      const yAt = (value) => pad.t + plotH - (Math.max(0, value) / max) * plotH;
      const base = pad.t + plotH;
      state.bars = [];

      drawGrid(ctx, w, pad, plotH);

      const bands = (segments.length ? segments : [{ key: "amount", color: "#c6f21a" }]).map((segment) => ({
        color: safeColor(segment.color || "#c6f21a"),
        values: days.map((day) => {
          if (!segments.length) return dayTotal(day, opts.segments);
          const found = partsFor(day, opts.segments).find((part) => part.key === segment.key);
          return found ? found.value : 0;
        }),
      }));

      const running = days.map(() => 0);
      const stacked = bands.map((band) => {
        const lower = [];
        const upper = [];
        band.values.forEach((value, i) => {
          const from = running[i];
          const to = from + value;
          running[i] = to;
          lower.push(from);
          upper.push(to);
        });
        return { color: band.color, values: band.values, lower, upper };
      });

      days.forEach((day, i) => {
        const x = xAt(i);
        state.bars.push({ i, x, y: yAt(running[i]), left: pad.l + step * i, right: pad.l + step * (i + 1), day });
      });

      ctx.save();
      ctx.beginPath();
      ctx.rect(0, 0, pad.l + plotW * progress, h);
      ctx.clip();

      stacked.forEach((band) => {
        ctx.beginPath();
        band.upper.forEach((value, i) => {
          const x = xAt(i);
          const y = yAt(value);
          if (i === 0) ctx.moveTo(x, y);
          else ctx.lineTo(x, y);
        });
        for (let i = band.lower.length - 1; i >= 0; i--) {
          ctx.lineTo(xAt(i), band.lower[i] <= 0 ? base : yAt(band.lower[i]));
        }
        ctx.closePath();
        ctx.fillStyle = band.color;
        ctx.globalAlpha = 0.82;
        ctx.fill();
        ctx.globalAlpha = 1;
        ctx.beginPath();
        band.upper.forEach((value, i) => {
          const x = xAt(i);
          const y = yAt(value);
          if (i === 0) ctx.moveTo(x, y);
          else ctx.lineTo(x, y);
        });
        ctx.strokeStyle = band.color;
        ctx.lineWidth = 1.75;
        ctx.lineJoin = "round";
        ctx.lineCap = "round";
        ctx.stroke();
      });
      ctx.restore();

      drawDayLabels(ctx, days, pad, step, h);

      if (state.hover >= 0 && state.bars[state.hover]) {
        const b = state.bars[state.hover];
        ctx.strokeStyle = "rgba(232, 240, 228, 0.2)";
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(b.x, pad.t);
        ctx.lineTo(b.x, base);
        ctx.stroke();
        stacked.forEach((band) => {
          if ((band.values[state.hover] || 0) <= 0) return;
          ctx.beginPath();
          ctx.arc(b.x, yAt(band.upper[state.hover]), 4, 0, Math.PI * 2);
          ctx.fillStyle = band.color;
          ctx.fill();
          ctx.strokeStyle = "#0b1210";
          ctx.lineWidth = 2;
          ctx.stroke();
        });
      }
    };

    state.paint = paint;
    binding = bindPlot(canvas, state, opts);

    if (reveal >= 1) {
      paint();
    } else {
      const start = performance.now();
      const tick = (now) => {
        if (!alive) return;
        const t = Math.min(1, (now - start) / 860);
        reveal = easeOut(t);
        paint();
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }

    return {
      redraw: paint,
      destroy: () => {
        alive = false;
        if (binding) binding.destroy();
      },
    };
  };

  const mountSeriesChart = (root, canvas, days, opts = {}) => {
    if (!root || !canvas) return null;
    const buttons = Array.from(root.querySelectorAll("[data-chart-kind]"));
    const legend = root.querySelector("[data-chart-legend]");
    const hint = root.querySelector("[data-chart-hint]");
    const storageKey = "privofit.chartKind";
    let kind = "line";
    let handle = null;

    try {
      const saved = localStorage.getItem(storageKey);
      if (saved === "bar" || saved === "line" || saved === "area") kind = saved;
    } catch {
      kind = "line";
    }

    const applyChrome = () => {
      buttons.forEach((btn) => {
        const on = btn.getAttribute("data-chart-kind") === kind;
        btn.classList.toggle("is-on", on);
        btn.setAttribute("aria-selected", on ? "true" : "false");
      });
      if (legend) legend.hidden = kind === "line" || legend.children.length === 0;
      if (hint) {
        const hintName = kind === "bar" ? "data-hint-bar" : kind === "area" ? "data-hint-area" : "data-hint-line";
        const next = hint.getAttribute(hintName);
        if (next) hint.textContent = next;
      }
      const aria = { line: "Vývoj tržeb", area: "Plošný graf tržeb", bar: "Sloupcový graf tržeb" };
      canvas.setAttribute("aria-label", aria[kind] || aria.line);
    };

    const draw = () => {
      if (handle && handle.destroy) handle.destroy();
      const painters = { line: drawLineChart, area: drawAreaChart, bar: drawBarChart };
      handle = (painters[kind] || drawLineChart)(canvas, days, opts);
    };

    const setKind = (next) => {
      const resolved = next === "bar" || next === "area" ? next : "line";
      if (resolved === kind && handle) return;
      kind = resolved;
      applyChrome();
      draw();
      try {
        localStorage.setItem(storageKey, kind);
      } catch {
        /* úložiště nemusí být dostupné */
      }
    };

    buttons.forEach((btn) => {
      btn.addEventListener("mousedown", (event) => {
        if (event.button === 0) event.preventDefault();
      });
      btn.addEventListener("click", () => setKind(btn.getAttribute("data-chart-kind")));
    });

    const startX = window.scrollX;
    const startY = window.scrollY;
    let userMoved = false;
    const markUser = (event) => {
      if (event.isTrusted) userMoved = true;
    };
    window.addEventListener("wheel", markUser, { passive: true });
    window.addEventListener("touchmove", markUser, { passive: true });
    const started = performance.now();
    const holdScroll = () => {
      const drift = Math.abs(window.scrollY - startY) + Math.abs(window.scrollX - startX);
      if (!userMoved && drift > 0 && drift < 160) {
        const root = document.documentElement;
        const prev = root.style.scrollBehavior;
        root.style.scrollBehavior = "auto";
        window.scrollTo(startX, startY);
        root.style.scrollBehavior = prev;
      }
      if (!userMoved && performance.now() - started < 800) requestAnimationFrame(holdScroll);
      else {
        window.removeEventListener("wheel", markUser);
        window.removeEventListener("touchmove", markUser);
      }
    };

    applyChrome();
    draw();
    requestAnimationFrame(holdScroll);
    return { setKind };
  };

  const drawDonut = (canvas, breakdown, segments) => {
    if (!canvas) return;
    const parts = (Array.isArray(segments) && segments.length
      ? segments.map((series) => ({
          key: series.key,
          label: series.label,
          color: safeColor(series.color),
          value: Number(series.amount) || 0,
        }))
      : [
          { key: "reservations", label: "Vstupné", color: "#c6f21a", value: Number(breakdown && breakdown.reservations) || 0 },
          { key: "memberships", label: "Členství", color: "#6ec8ff", value: Number(breakdown && breakdown.memberships) || 0 },
          { key: "other", label: "Ostatní", color: "#9aa49c", value: Number(breakdown && breakdown.other) || 0 },
        ]
    ).filter((part) => part.value > 0);
    const total = parts.reduce((sum, p) => sum + p.value, 0) || 1;

    const render = (progress) => {
      const { ctx, w, h } = fitCanvas(canvas);
      ctx.clearRect(0, 0, w, h);
      const cx = w / 2;
      const cy = h / 2;
      const r = Math.min(w, h) * 0.38;
      const inner = r * 0.58;

      if (parts.length === 0) {
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.strokeStyle = "rgba(232, 240, 228, 0.12)";
        ctx.lineWidth = r - inner;
        ctx.stroke();
        return;
      }

      const limit = Math.max(0, Math.min(1, progress)) * Math.PI * 2;
      let angle = -Math.PI / 2;
      let drawn = 0;
      parts.forEach((part) => {
        const slice = (part.value / total) * Math.PI * 2;
        const start = drawn;
        drawn += slice;
        const visible = Math.min(drawn, limit) - start;
        if (visible <= 0.001) return;
        const a0 = angle;
        const a1 = angle + visible;
        ctx.beginPath();
        ctx.arc(cx, cy, r, a0, a1);
        ctx.arc(cx, cy, inner, a1, a0, true);
        ctx.closePath();
        ctx.fillStyle = part.color;
        ctx.fill();
        angle += slice;
      });
    };

    playIntro(canvas, render, 780);
  };

  window.PrivofitCharts = { drawLineChart, drawAreaChart, drawBarChart, drawDonut, mountSeriesChart, formatMoney, parsePayload };
})();
