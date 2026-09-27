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

  const formatCount = (value) => Math.round(Number(value) || 0).toLocaleString("cs-CZ");

  const tipMarkup = (day, segments, opts = {}) => {
    const countMode = opts.format === "count";
    const format = countMode ? formatCount : formatMoney;
    const parts = partsFor(day, segments).filter((part) => part.key !== "amount");
    const headline = countMode
      ? parts.reduce((sum, part) => sum + part.value, 0) || Number(day.amount) || 0
      : day.amount;
    let html = "<strong>" + esc(format(headline)) + "</strong><span>" + esc(day.label || day.date || "");
    if (!countMode) {
      html += " · " + (Number(day.count) || 0) + " plat.";
    }
    html += "</span>";
    if (!countMode || parts.length > 1) {
      parts.forEach((part) => {
        html +=
          '<span class="chart-tip-row"><i style="background:' +
          part.color +
          '"></i>' +
          esc(part.label) +
          " " +
          esc(format(part.value)) +
          "</span>";
      });
    }
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

    const findIndex = (clientX, clientY) => {
      if (typeof state.hit === "function") return state.hit(clientX, clientY);
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
      tip.innerHTML = state.bars[i].tip || tipMarkup(state.bars[i].day, opts.segments, opts);
      const parent = tip.offsetParent || document.body;
      const prect = parent.getBoundingClientRect();
      const tipW = tip.offsetWidth || 168;
      tip.style.left = Math.min(prect.width - tipW - 8, Math.max(8, clientX - prect.left - tipW / 2)) + "px";
      tip.style.top = Math.max(8, clientY - prect.top - tip.offsetHeight - 14) + "px";
    };

    const onMove = (event) => {
      const i = findIndex(event.clientX, event.clientY);
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
      const i = findIndex(event.clientX, event.clientY);
      const hit = i >= 0 ? state.bars[i] : null;
      if (hit && hit.day && hit.selectable !== false && onSelect) onSelect(hit.day);
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

  const monotoneTangents = (values) => {
    const n = values.length;
    const slope = new Array(Math.max(0, n - 1));
    const tangent = new Array(n).fill(0);
    for (let i = 0; i < n - 1; i++) slope[i] = values[i + 1] - values[i];
    if (n < 2) return tangent;
    tangent[0] = slope[0];
    tangent[n - 1] = slope[n - 2];
    for (let i = 1; i < n - 1; i++) {
      tangent[i] = slope[i - 1] * slope[i] <= 0 ? 0 : (slope[i - 1] + slope[i]) / 2;
    }
    for (let i = 0; i < n - 1; i++) {
      if (slope[i] === 0) {
        tangent[i] = 0;
        tangent[i + 1] = 0;
        continue;
      }
      const a = tangent[i] / slope[i];
      const b = tangent[i + 1] / slope[i];
      const hypot = a * a + b * b;
      if (hypot > 9) {
        const scale = 3 / Math.sqrt(hypot);
        tangent[i] = scale * a * slope[i];
        tangent[i + 1] = scale * b * slope[i];
      }
    }
    return tangent;
  };

  const strokeCurve = (ctx, points, tangents) => {
    if (points.length === 0) return;
    ctx.moveTo(points[0].x, points[0].y);
    for (let i = 0; i < points.length - 1; i++) {
      const from = points[i];
      const to = points[i + 1];
      const span = (to.x - from.x) / 3;
      ctx.bezierCurveTo(
        from.x + span,
        from.y + tangents[i] / 3,
        to.x - span,
        to.y - tangents[i + 1] / 3,
        to.x,
        to.y
      );
    }
  };

  const drawCurveChart = (canvas, days, opts = {}) => {
    if (!canvas || !Array.isArray(days) || days.length === 0) return null;
    const accent = opts.accent || "#c6f21a";
    const soft = opts.soft || "rgba(198, 242, 26, 0.2)";
    const state = { hover: -1, bars: [], paint: () => {} };
    let reveal = wantsMotion(canvas, "curve", opts) ? 0 : 1;
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
      const base = pad.t + plotH;
      state.bars = [];

      drawGrid(ctx, w, pad, plotH);

      const points = days.map((day, i) => {
        const amount = dayTotal(day, opts.segments);
        const x = pad.l + step * i + step / 2;
        const y = pad.t + plotH - (amount / max) * plotH;
        state.bars.push({ i, x, y, left: pad.l + step * i, right: pad.l + step * (i + 1), day });
        return { x, y, amount };
      });
      const tangents = monotoneTangents(points.map((point) => point.amount)).map((slope) => (-slope / max) * plotH);

      ctx.save();
      ctx.beginPath();
      ctx.rect(0, 0, pad.l + plotW * reveal, h);
      ctx.clip();

      const grad = ctx.createLinearGradient(0, pad.t, 0, base);
      grad.addColorStop(0, soft);
      grad.addColorStop(1, "rgba(198, 242, 26, 0)");
      ctx.beginPath();
      strokeCurve(ctx, points, tangents);
      ctx.lineTo(points[points.length - 1].x, base);
      ctx.lineTo(points[0].x, base);
      ctx.closePath();
      ctx.fillStyle = grad;
      ctx.fill();

      ctx.beginPath();
      strokeCurve(ctx, points, tangents);
      ctx.strokeStyle = accent;
      ctx.lineWidth = 2.4;
      ctx.lineJoin = "round";
      ctx.lineCap = "round";
      ctx.stroke();
      ctx.restore();

      drawDayLabels(ctx, days, pad, step, h);

      if (state.hover >= 0 && state.bars[state.hover]) {
        const hit = state.bars[state.hover];
        ctx.strokeStyle = "rgba(232, 240, 228, 0.2)";
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(hit.x, pad.t);
        ctx.lineTo(hit.x, base);
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(hit.x, hit.y, 5, 0, Math.PI * 2);
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

  const ringParts = (days, segments) => {
    const source = Array.isArray(segments) && segments.length ? segments : SERIES;
    const totals = source.map((series) => ({
      key: series.key,
      label: series.label || "",
      color: safeColor(series.color),
      value: 0,
    }));
    (Array.isArray(days) ? days : []).forEach((day) => {
      partsFor(day, segments).forEach((part) => {
        const slot = totals.find((item) => item.key === part.key);
        if (slot) slot.value += part.value;
      });
    });
    if (totals.every((item) => item.value <= 0)) {
      source.forEach((series, index) => {
        if (totals[index]) totals[index].value = Number(series.amount) || 0;
      });
    }
    return totals.filter((item) => item.value > 0);
  };

  const drawRingChart = (canvas, days, opts = {}) => {
    if (!canvas) return null;
    const countMode = opts.format === "count";
    const format = countMode ? formatCount : formatMoney;
    const state = { hover: -1, bars: [], paint: () => {}, hit: () => -1 };
    let reveal = wantsMotion(canvas, "ring", opts) ? 0 : 1;
    let binding = null;
    let alive = true;

    const paint = () => {
      const { ctx, w, h } = fitCanvas(canvas);
      ctx.clearRect(0, 0, w, h);
      const parts = ringParts(days, opts.segments);
      const total = parts.reduce((sum, part) => sum + part.value, 0);
      const cx = w / 2;
      const cy = h / 2;
      const outer = Math.min(w, h) * 0.38;
      const inner = outer * 0.62;
      const tau = Math.PI * 2;
      const gap = parts.length > 1 ? 0.04 : 0;
      state.bars = [];
      let cursor = 0;
      parts.forEach((part, index) => {
        const share = total > 0 ? part.value / total : 0;
        const sweep = share * tau;
        const start = cursor;
        const end = cursor + Math.max(0, sweep - gap);
        cursor += sweep;
        const pct = total > 0 ? Math.round(share * 100) : 0;
        state.bars.push({
          i: index,
          a0: start,
          a1: end,
          selectable: false,
          tip: "<strong>" + esc(format(part.value)) + "</strong><span>" + esc(part.label) + " · " + pct + " %</span>",
        });
      });
      state.hit = (clientX, clientY) => {
        const rect = canvas.getBoundingClientRect();
        const x = clientX - rect.left - cx;
        const y = clientY - rect.top - cy;
        const dist = Math.hypot(x, y);
        if (dist < inner * 0.92 || dist > outer * 1.14) return -1;
        let angle = Math.atan2(y, x) + Math.PI / 2;
        angle = ((angle % tau) + tau) % tau;
        for (let i = 0; i < state.bars.length; i++) {
          if (angle >= state.bars[i].a0 && angle <= state.bars[i].a1 + 0.02) return i;
        }
        return -1;
      };

      if (parts.length === 0) {
        ctx.beginPath();
        ctx.arc(cx, cy, (outer + inner) / 2, 0, tau);
        ctx.strokeStyle = "rgba(232, 240, 228, 0.14)";
        ctx.lineWidth = outer - inner;
        ctx.stroke();
        return;
      }

      const limit = Math.max(0, Math.min(1, reveal)) * tau;
      parts.forEach((part, index) => {
        const slice = state.bars[index];
        const visibleEnd = Math.min(slice.a1, limit);
        if (visibleEnd <= slice.a0) return;
        const hover = state.hover === index;
        const mid = -Math.PI / 2 + (slice.a0 + slice.a1) / 2;
        const push = hover ? 7 : 0;
        const ox = Math.cos(mid) * push;
        const oy = Math.sin(mid) * push;
        const a0 = -Math.PI / 2 + slice.a0;
        const a1 = -Math.PI / 2 + visibleEnd;
        ctx.beginPath();
        ctx.arc(cx + ox, cy + oy, hover ? outer + 3 : outer, a0, a1);
        ctx.arc(cx + ox, cy + oy, inner, a1, a0, true);
        ctx.closePath();
        ctx.fillStyle = part.color;
        ctx.fill();
      });

      ctx.fillStyle = "#e8f0e4";
      ctx.font = "800 " + Math.max(15, Math.round(inner * 0.32)) + "px Syne, sans-serif";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText(format(total), cx, cy - 8);
      ctx.fillStyle = "rgba(232, 240, 228, 0.55)";
      ctx.font = "700 11px Figtree, sans-serif";
      ctx.fillText("celkem", cx, cy + 16);
    };

    state.paint = paint;
    binding = bindPlot(canvas, state, opts);

    if (reveal >= 1) {
      paint();
    } else {
      const start = performance.now();
      const tick = (now) => {
        if (!alive) return;
        const t = Math.min(1, (now - start) / 780);
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

  const chartKinds = ["curve", "area", "bar", "ring"];
  const normalizeKind = (value) => {
    if (value === "line") return "curve";
    return chartKinds.includes(value) ? value : "curve";
  };

  const mountSeriesChart = (root, canvas, initialDays, initialOpts = {}) => {
    if (!root || !canvas) return null;
    const buttons = Array.from(root.querySelectorAll("[data-chart-kind]"));
    const legend = root.querySelector("[data-chart-legend]");
    const hint = root.querySelector("[data-chart-hint]");
    const storageKey = initialOpts.storageKey || "privofit.chartKind";
    let kind = normalizeKind(initialOpts.kind);
    let handle = null;
    let days = initialDays;
    let opts = initialOpts;

    try {
      const saved = localStorage.getItem(storageKey);
      if (saved) kind = normalizeKind(saved);
    } catch {
      kind = "curve";
    }

    const applyChrome = () => {
      buttons.forEach((btn) => {
        const on = btn.getAttribute("data-chart-kind") === kind;
        btn.classList.toggle("is-on", on);
        btn.setAttribute("aria-selected", on ? "true" : "false");
      });
      if (legend) legend.hidden = kind === "curve" || legend.children.length === 0;
      if (hint) {
        const hintName = {
          bar: "data-hint-bar",
          area: "data-hint-area",
          curve: "data-hint-curve",
          ring: "data-hint-ring",
        }[kind] || "data-hint-curve";
        const next = hint.getAttribute(hintName);
        if (next) hint.textContent = next;
      }
      const aria = opts.subject
        ? {
            curve: "Křivka " + opts.subject,
            ring: "Kruhový graf " + opts.subject,
            area: "Plošný graf " + opts.subject,
            bar: "Sloupcový graf " + opts.subject,
          }
        : { curve: "Křivka tržeb", ring: "Kruhový graf tržeb", area: "Plošný graf tržeb", bar: "Sloupcový graf tržeb" };
      canvas.setAttribute("aria-label", aria[kind] || aria.curve);
    };

    const draw = (animate) => {
      if (handle && handle.destroy) handle.destroy();
      const painters = { curve: drawCurveChart, ring: drawRingChart, area: drawAreaChart, bar: drawBarChart };
      handle = (painters[kind] || drawCurveChart)(canvas, days, { ...opts, animate });
    };

    const setKind = (next) => {
      const resolved = normalizeKind(next);
      if (resolved === kind && handle) return;
      kind = resolved;
      applyChrome();
      draw(opts.animate !== false);
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
    draw(opts.animate !== false);
    requestAnimationFrame(holdScroll);
    let morphToken = 0;
    const lerpDay = (from, to, t) => {
      const mix = (a, b) => (Number(a) || 0) + ((Number(b) || 0) - (Number(a) || 0)) * t;
      const fromParts = from && from.parts && typeof from.parts === "object" ? from.parts : {};
      const toParts = to && to.parts && typeof to.parts === "object" ? to.parts : {};
      const parts = {};
      new Set([...Object.keys(fromParts), ...Object.keys(toParts)]).forEach((key) => {
        parts[key] = mix(fromParts[key], toParts[key]);
      });
      return {
        ...to,
        amount: mix(from && from.amount, to && to.amount),
        reservations: mix(from && from.reservations, to && to.reservations),
        memberships: mix(from && from.memberships, to && to.memberships),
        other: mix(from && from.other, to && to.other),
        count: Math.round(mix(from && from.count, to && to.count)),
        parts,
      };
    };

    return {
      setKind,
      update(nextDays, nextOpts = {}) {
        const from = (Array.isArray(days) ? days : []).map((day) => ({
          ...day,
          parts: { ...(day.parts || {}) },
        }));
        const to = Array.isArray(nextDays) ? nextDays : [];
        opts = { ...opts, ...nextOpts, animate: false };
        const token = ++morphToken;
        if (reduceMotion() || from.length === 0 || from.length !== to.length) {
          days = to;
          applyChrome();
          draw(false);
          return;
        }
        days = from.map((day) => ({ ...day, parts: { ...(day.parts || {}) } }));
        applyChrome();
        draw(false);
        const start = performance.now();
        const step = (now) => {
          if (token !== morphToken) return;
          const t = Math.min(1, (now - start) / 780);
          const eased = 1 - Math.pow(1 - t, 3);
          const done = t >= 1;
          to.forEach((day, index) => {
            days[index] = done ? day : lerpDay(from[index], day, eased);
          });
          if (handle && handle.redraw) handle.redraw();
          if (!done) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      },
    };
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

  window.PrivofitCharts = { drawLineChart, drawCurveChart, drawRingChart, drawAreaChart, drawBarChart, drawDonut, mountSeriesChart, formatMoney, parsePayload };
})();
