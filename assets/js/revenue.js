(() => {
  const Charts = window.PrivofitCharts;

  const chartRoot = document.querySelector("[data-rev-chart]");
  if (Charts && chartRoot) {
    const payload = Charts.parsePayload(chartRoot);
    if (payload) {
      const line = chartRoot.querySelector("[data-rev-line]");
      const donut = chartRoot.querySelector("[data-rev-donut]");
      const tip = chartRoot.querySelector("[data-chart-tip]");
      const dayUrl = chartRoot.getAttribute("data-day-url") || "";

      if (line) {
        Charts.mountSeriesChart(chartRoot, line, payload.days || [], {
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
    }
  }

  const modal = document.querySelector("[data-rev-modal]");
  const openBtn = document.querySelector("[data-rev-open]");
  if (!modal || !openBtn) return;

  const fromInput = modal.querySelector('input[name="od"]');
  const toInput = modal.querySelector('input[name="do"]');
  const calRoot = modal.querySelector("[data-cal]");
  const calTitle = modal.querySelector("[data-cal-title]");
  const calGrid = modal.querySelector("[data-cal-grid]");
  const calHint = modal.querySelector("[data-cal-hint]");
  const submitBtn = modal.querySelector('[type="submit"]');
  const monthNames = [
    "Leden", "Únor", "Březen", "Duben", "Květen", "Červen",
    "Červenec", "Srpen", "Září", "Říjen", "Listopad", "Prosinec",
  ];
  const weekDays = ["Po", "Út", "St", "Čt", "Pá", "So", "Ne"];

  let viewYear;
  let viewMonth;
  let start = fromInput?.value || "";
  let end = toInput?.value || "";
  let picking = start && end ? "done" : start ? "end" : "start";

  const toIso = (y, m, d) => {
    const mm = String(m + 1).padStart(2, "0");
    const dd = String(d).padStart(2, "0");
    return y + "-" + mm + "-" + dd;
  };

  const parseIso = (iso) => {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return null;
    const [y, m, d] = iso.split("-").map(Number);
    return new Date(y, m - 1, d);
  };

  const formatCs = (iso) => {
    const d = parseIso(iso);
    if (!d) return "—";
    return d.getDate() + ". " + (d.getMonth() + 1) + ". " + d.getFullYear();
  };

  const syncInputs = () => {
    if (fromInput) fromInput.value = start || "";
    if (toInput) toInput.value = end || "";
    if (submitBtn) submitBtn.disabled = !(start && end);
    if (calHint) {
      if (!start) {
        calHint.textContent = "Klikni na počáteční den.";
      } else if (!end) {
        calHint.textContent = "Začátek " + formatCs(start) + " · klikni na konec období.";
      } else {
        calHint.textContent = formatCs(start) + " – " + formatCs(end);
      }
    }
  };

  const inRange = (iso) => {
    if (!start || !end) return false;
    const a = start < end ? start : end;
    const b = start < end ? end : start;
    return iso >= a && iso <= b;
  };

  const render = () => {
    if (!calGrid || !calTitle) return;
    calTitle.textContent = monthNames[viewMonth] + " " + viewYear;
    calGrid.innerHTML = "";

    weekDays.forEach((label) => {
      const el = document.createElement("span");
      el.className = "rev-cal-dow";
      el.textContent = label;
      calGrid.appendChild(el);
    });

    const first = new Date(viewYear, viewMonth, 1);
    let startDow = first.getDay() - 1;
    if (startDow < 0) startDow = 6;
    const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
    const todayIso = (() => {
      const n = new Date();
      return toIso(n.getFullYear(), n.getMonth(), n.getDate());
    })();

    for (let i = 0; i < startDow; i++) {
      const empty = document.createElement("span");
      empty.className = "rev-cal-day is-empty";
      calGrid.appendChild(empty);
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const iso = toIso(viewYear, viewMonth, day);
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "rev-cal-day";
      btn.textContent = String(day);
      btn.setAttribute("data-date", iso);
      if (iso === todayIso) btn.classList.add("is-today");
      if (iso === start || iso === end) btn.classList.add("is-edge");
      if (inRange(iso)) btn.classList.add("is-in");
      if (start && end && iso === (start < end ? start : end)) btn.classList.add("is-start");
      if (start && end && iso === (start < end ? end : start)) btn.classList.add("is-end");
      btn.addEventListener("click", () => pick(iso));
      calGrid.appendChild(btn);
    }
    syncInputs();
  };

  const pick = (iso) => {
    if (picking === "start" || picking === "done") {
      start = iso;
      end = "";
      picking = "end";
    } else {
      end = iso;
      if (end < start) {
        const tmp = start;
        start = end;
        end = tmp;
      }
      picking = "done";
    }
    render();
  };

  const close = () => {
    modal.hidden = true;
    openBtn.focus();
  };

  const open = () => {
    const seed = parseIso(start) || new Date();
    viewYear = seed.getFullYear();
    viewMonth = seed.getMonth();
    picking = start && end ? "done" : start ? "end" : "start";
    render();
    modal.hidden = false;
  };

  openBtn.addEventListener("click", (event) => {
    event.preventDefault();
    open();
  });

  modal.querySelectorAll("[data-rev-close]").forEach((node) => {
    node.addEventListener("click", close);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hidden) close();
  });

  modal.querySelector("[data-cal-prev]")?.addEventListener("click", () => {
    viewMonth -= 1;
    if (viewMonth < 0) {
      viewMonth = 11;
      viewYear -= 1;
    }
    render();
  });

  modal.querySelector("[data-cal-next]")?.addEventListener("click", () => {
    viewMonth += 1;
    if (viewMonth > 11) {
      viewMonth = 0;
      viewYear += 1;
    }
    render();
  });

  modal.querySelector("[data-cal-reset]")?.addEventListener("click", () => {
    start = "";
    end = "";
    picking = "start";
    render();
  });

  modal.querySelector("form")?.addEventListener("submit", (event) => {
    if (!start || !end) {
      event.preventDefault();
      return;
    }
    if (fromInput) fromInput.value = start;
    if (toInput) toInput.value = end;
  });

  syncInputs();
})();
