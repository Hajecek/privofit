(() => {
  const root = document.querySelector("[data-booker]");
  if (!root) return;

  const MONTHS = ["leden", "únor", "březen", "duben", "květen", "červen", "červenec", "srpen", "září", "říjen", "listopad", "prosinec"];
  const MONTHS_GEN = ["ledna", "února", "března", "dubna", "května", "června", "července", "srpna", "září", "října", "listopadu", "prosince"];
  const DAYS = ["neděle", "pondělí", "úterý", "středa", "čtvrtek", "pátek", "sobota"];

  const payload = JSON.parse(root.getAttribute("data-payload") || "{}");
  const hoursEl = root.querySelector("[data-hour-list]");
  const emptyEl = root.querySelector("[data-hours-empty]");
  const closedEl = root.querySelector("[data-hours-closed]");
  const closedTitleEl = root.querySelector("[data-closed-title]");
  const closedTextEl = root.querySelector("[data-closed-text]");
  const hoursCard = root.querySelector(".booker-hours");
  const loadingEl = root.querySelector("[data-hours-loading]");
  const hintEl = root.querySelector("[data-hours-hint]");
  const dateLabelEl = root.querySelector("[data-date-label]");
  const form = root.querySelector("[data-book-form]");
  const startInput = form?.querySelector('[name="start"]');
  const durationInput = form?.querySelector('[name="duration"]');
  const guestsInput = form?.querySelector('[name="guests"]');
  const bar = document.querySelector("[data-bar]");
  const barTime = bar?.querySelector("[data-bar-time]");
  const barMeta = bar?.querySelector("[data-bar-meta]");
  const confirmBtn = bar?.querySelector("[data-confirm]");
  const payBtn = bar?.querySelector("[data-pay]");
  const payInput = form?.querySelector('[name="pay"]');
  const guestCountEl = bar?.querySelector("[data-guest-count]");
  const calendar = root.querySelector("[data-calendar]");
  const calModal = root.querySelector("[data-cal-modal]");
  const openCalBtns = [...root.querySelectorAll("[data-open-cal]")];
  const openCalBtn = openCalBtns[0] || null;
  const calGrid = calendar?.querySelector("[data-cal-grid]");
  const calTitle = calendar?.querySelector("[data-cal-title]");

  const state = {
    date: payload.date,
    today: payload.today,
    availability: payload.availability || {},
    selected: [],
    hours: 1,
    guests: 1,
    calYear: Number((payload.date || "").slice(0, 4)) || new Date().getFullYear(),
    calMonth: Number((payload.date || "").slice(5, 7)) || (new Date().getMonth() + 1),
    calDays: [],
    calCache: new Map(),
    error: "",
  };

  const step = () => Number(state.availability.duration_step_minutes || 60);
  const minMinutes = () => Number(state.availability.min_minutes || 60);
  const maxMinutes = () => Number(state.availability.max_minutes || 1440);
  const maxHours = () => Math.max(1, Math.floor(maxMinutes() / step()));
  const maxPersons = () => Number(state.availability.max_persons || 2);
  const hourly = () => Number(state.availability.hourly_price || 150);
  const hourlyTwo = () => Number(state.availability.hourly_price_two || 200);
  const rate = () => (state.guests >= 2 ? hourlyTwo() : hourly());
  const buffer = () => Number(state.availability.buffer_minutes || 15);
  const feeBps = Number(payload.stripeFee?.basisPoints ?? 150);
  const feeFixedMinor = Number(payload.stripeFee?.fixedMinor ?? 650);
  const processorFee = (amountMinor) => {
    const product = amountMinor * feeBps;
    const percent = Math.floor(product / 10000) + (product % 10000 >= 5000 ? 1 : 0);
    return percent + feeFixedMinor;
  };
  const cover = (netCrowns) => {
    const netMinor = Math.max(0, Math.round(Number(netCrowns) * 100));
    if (netMinor === 0 || (feeBps <= 0 && feeFixedMinor <= 0)) {
      return { netMinor, feeMinor: 0, chargeMinor: netMinor };
    }
    let charge = netMinor + feeFixedMinor;
    if (feeBps > 0) {
      const denom = 10000 - feeBps;
      const numer = (netMinor + feeFixedMinor) * 10000;
      charge = Math.floor((numer + denom - 1) / denom);
    }
    const netOf = (amountMinor) => amountMinor - processorFee(amountMinor);
    while (charge > netMinor && netOf(charge - 1) >= netMinor) charge -= 1;
    while (netOf(charge) < netMinor) charge += 1;
    return { netMinor, feeMinor: charge - netMinor, chargeMinor: charge };
  };
  const moneyMinor = (minor) => {
    const whole = minor % 100 === 0;
    const formatted = (minor / 100).toLocaleString("cs-CZ", {
      minimumFractionDigits: whole ? 0 : 2,
      maximumFractionDigits: whole ? 0 : 2,
    });
    return formatted + "\u00a0Kč";
  };

  const pad = (n) => String(n).padStart(2, "0");
  const money = (n) => Math.round(Number(n) || 0).toLocaleString("cs-CZ") + "\u00a0Kč";
  const blocksWord = (count) => {
    if (count === 1) return "1 okénko";
    if (count >= 2 && count <= 4) return count + " okénka";
    return count + " okének";
  };
  const parseDate = (value) => {
    const [y, m, d] = String(value || "").split("-").map((part) => parseInt(part, 10));
    return new Date(y, (m || 1) - 1, d || 1);
  };
  const isoDate = (date) => date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
  const addDays = (value, days) => {
    const date = parseDate(value);
    date.setDate(date.getDate() + days);
    return isoDate(date);
  };
  const addMinutesToTime = (time, minutes) => {
    const [h, m] = String(time).split(":").map((part) => parseInt(part, 10));
    const total = h * 60 + m + minutes;
    return pad(Math.floor((total % (24 * 60)) / 60)) + ":" + pad(total % 60);
  };
  const dateLabel = (value) => {
    const date = parseDate(value);
    return DAYS[date.getDay()] + " " + date.getDate() + ". " + MONTHS_GEN[date.getMonth()];
  };
  const maxBookable = () => addDays(state.today, 56);
  const roomParam = () => {
    const id = root.getAttribute("data-room") || "";
    return id ? "&room=" + encodeURIComponent(id) : "";
  };

  const membershipCovers = !!payload.membership_covers;
  const unlimitedMembership = membershipCovers && payload.entries_remaining === null;
  const entriesLeft = unlimitedMembership ? Number.POSITIVE_INFINITY : Number(payload.entries_remaining || 0);
  const canCover = (count) => membershipCovers && entriesLeft >= count;
  const deductPhrase = (count) => {
    if (count === 1) return "odečte se 1 vstup";
    if (count >= 2 && count <= 4) return "odečtou se " + count + " vstupy";
    return "odečte se " + count + " vstupů";
  };
  const remainPhrase = (count) => {
    if (count === 1) return "zbývá 1 vstup";
    if (count >= 2 && count <= 4) return "zbývají " + count + " vstupy";
    return "zbývá " + count + " vstupů";
  };
  const availableFor = (slot) => (slot.available_for || []).map((item) => Number(item));
  const bookableRows = () => (state.availability.slots || []).filter((slot) => slot.kind !== "buffer");
  const blockMinutes = () => step() + buffer();
  const occupyMinutes = (hours) => (hours || state.hours) * blockMinutes();
  const occupyEnd = () => {
    const first = state.selected[0];
    if (!first) return "";
    return addMinutesToTime(first.start, occupyMinutes());
  };
  const minutes = (time) => timeToMinutes(time);
  const inRange = (row) => {
    const first = state.selected[0];
    if (!first || row.kind === "buffer") return false;
    const start = minutes(row.start);
    return start >= minutes(first.start) && start < minutes(occupyEnd());
  };
  const hoursCovering = (row) => {
    const first = state.selected[0];
    if (!first || !row || row.kind === "buffer") return 0;
    const delta = minutes(row.start) - minutes(first.start);
    const block = blockMinutes();
    if (delta <= 0 || block < 1 || delta % block !== 0) return 0;
    return delta / block + 1;
  };
  const extendHours = (row) => {
    const target = hoursCovering(row);
    const first = state.selected[0];
    if (!first || target <= state.hours) return 0;
    if (target > maxHours()) return 0;
    if (!availableFor(first).includes(target * step())) return 0;
    return target;
  };
  const maxSelectable = () => {
    const first = state.selected[0];
    if (!first) return 1;
    let max = 1;
    availableFor(first).forEach((minutesValue) => {
      const hours = minutesValue / step();
      if (hours > max && hours <= maxHours()) max = hours;
    });
    return max;
  };

  const setSelection = (rows, hours) => {
    state.selected = rows;
    state.hours = hours || (rows.length || 1);
    updateForm();
    renderHours();
    renderBar();
  };

  const clickHour = (row) => {
    if (row.kind === "buffer") return;
    const first = state.selected[0];
    if (!first) {
      if (!row.available) return;
      setSelection([row], 1);
      return;
    }
    if (row.start === first.start) {
      setSelection(state.hours === 1 ? [] : [first], 1);
      return;
    }
    const extra = extendHours(row);
    if (extra > 0) {
      setSelection([first], extra);
      return;
    }
    if (inRange(row)) {
      return;
    }
    if (!row.available) return;
    setSelection([row], 1);
  };

  const timeToMinutes = (time) => {
    const [h, m] = String(time).split(":").map((part) => parseInt(part, 10));
    return h * 60 + m;
  };

  const setHours = (count) => {
    const first = state.selected[0];
    if (!first) return;
    const next = Math.max(1, Math.min(count, maxSelectable()));
    if (!availableFor(first).includes(next * step())) return;
    setSelection([first], next);
  };

  const updateForm = () => {
    const first = state.selected[0];
    const duration = (first ? state.hours : 1) * step();
    if (startInput) startInput.value = first ? state.date + " " + first.start : "";
    if (durationInput) durationInput.value = String(duration || minMinutes());
    if (guestsInput) guestsInput.value = String(state.guests);
    const ready = !!first;
    if (confirmBtn) confirmBtn.disabled = !ready;
    if (payBtn) payBtn.disabled = !ready;
  };

  const hourState = (row) => {
    if (row.kind === "buffer") return "buffer";
    if (row.mine || row.kind === "mine") return "mine";
    if (inRange(row)) return "selected";
    if (extendHours(row) > 0) return "add";
    if (row.past || row.kind === "past") return "past";
    if (!row.available || row.kind === "busy") return "busy";
    return "free";
  };

  const hourMeta = (row, kind) => {
    if (kind === "mine") return "Tvoje";
    if (kind === "past") return "Už bylo";
    if (kind === "busy") return "Obsazeno";
    if (kind === "selected") return "Vybrané";
    if (kind === "add") return extendHours(row) === state.hours + 1 ? "Přidat okénko" : "Až sem";
    if (kind === "buffer") return "Úklid";
    return "Volné";
  };

  const hourButton = (row) => {
    const kind = hourState(row);
    if (kind === "buffer") return "";
    const selected = kind === "selected";
    const disabled = kind === "busy" || kind === "past" || kind === "mine";
    return (
      '<button type="button" class="hour-row is-' + kind + '"' +
      ' data-hour-start="' + row.start + '"' +
      (disabled ? " disabled" : "") +
      ' aria-pressed="' + (selected ? "true" : "false") + '">' +
      '<span class="hour-time">' + row.start + "<small>" + row.end + "</small></span>" +
      '<span class="hour-meta">' + hourMeta(row, kind) + (kind === "free" || kind === "selected" || kind === "add" ? " · " + money(rate()) : "") + "</span>" +
      "</button>"
    );
  };

  const periodOf = (start) => {
    const hour = parseInt(String(start || "0").slice(0, 2), 10) || 0;
    if (hour < 12) return "morning";
    if (hour < 17) return "afternoon";
    return "evening";
  };

  const renderHours = () => {
    const closed = !!state.availability.closed;
    const loading = !!(loadingEl && !loadingEl.hidden);
    const rows = closed || loading ? [] : (state.availability.slots || []);
    const free = rows.filter((row) => row.available).length;
    const isToday = state.date === state.today;
    if (closedEl) closedEl.hidden = !closed;
    if (hoursCard) hoursCard.classList.toggle("is-closed-day", closed);
    if (closed && closedTitleEl) {
      closedTitleEl.textContent = isToday
        ? "Bohužel je dnes zavřeno"
        : "Bohužel je v tento den zavřeno";
    }
    if (closed && closedTextEl) {
      closedTextEl.textContent = isToday
        ? "Dnes studio neotevírá. Vyber jiný den a rezervuj si volný termín."
        : "Studio " + dateLabel(state.date) + " neotevírá. Zkus jiný den v kalendáři.";
    }
    if (emptyEl) {
      emptyEl.hidden = closed || loading || rows.length > 0;
      emptyEl.textContent = state.error || "Pro tento den teď není volná hodina.";
    }
    if (hoursEl) {
      hoursEl.hidden = closed || loading || rows.length === 0;
      const groups = { morning: [], afternoon: [], evening: [] };
      rows.forEach((row) => {
        if (hourState(row) === "buffer") return;
        groups[periodOf(row.start)].push(row);
      });
      const columns = [
        ["morning", "Dopoledne", groups.morning],
        ["afternoon", "Odpoledne", groups.afternoon],
        ["evening", "Večer", groups.evening],
      ];
      hoursEl.innerHTML = columns.map(([key, title, items]) => {
        const body = items.length
          ? items.map(hourButton).join("")
          : '<p class="hour-col-empty">Žádný termín</p>';
        return (
          '<div class="hour-col" data-period="' + key + '">' +
          "<h3>" + title + "</h3>" +
          '<div class="hour-col-list">' + body + "</div>" +
          "</div>"
        );
      }).join("");
    }
    if (dateLabelEl) dateLabelEl.textContent = dateLabel(state.date);
    if (hintEl) {
      if (closed) {
        hintEl.textContent = isToday ? "Dnes je zavřeno." : "Tento den je zavřeno.";
      } else if (loading) {
        hintEl.textContent = "Načítám volné hodiny…";
      } else {
        hintEl.textContent = free
          ? "Každý blok je 1 h 15 min. Okének za sebou můžeš vybrat víc, klidně na celý volný den."
          : "Na tenhle den už volný blok nezbývá.";
      }
    }
  };

  const renderBar = () => {
    const on = state.selected.length > 0;
    if (!bar) return;
    bar.hidden = !on;
    bar.classList.toggle("is-on", on);
    if (!on) return;
    const first = state.selected[0];
    const end = occupyEnd();
    const count = state.hours;
    const price = rate() * count;
    const priced = cover(price);
    const priceLabel = priced.feeMinor > 0
      ? money(price) + " + poplatek evropské karty " + moneyMinor(priced.feeMinor)
      : money(price);
    const covered = canCover(count);
    if (barTime) barTime.textContent = first.start + "–" + end;
    if (barMeta) {
      if (covered && unlimitedMembership) {
        barMeta.textContent = blocksWord(count) + " · neomezené členství, nebo " + priceLabel;
      } else if (covered) {
        barMeta.textContent = blocksWord(count) + " · " + deductPhrase(count) + ", nebo " + priceLabel;
      } else if (membershipCovers) {
        barMeta.textContent = blocksWord(count) + " · " + priceLabel + " · na členství " + remainPhrase(entriesLeft);
      } else {
        barMeta.textContent = blocksWord(count) + " · " + priceLabel;
      }
    }
    if (guestCountEl) guestCountEl.textContent = String(state.guests);
    if (confirmBtn) {
      confirmBtn.hidden = !covered;
      confirmBtn.textContent = "Rezervovat";
    }
    if (payBtn) {
      payBtn.hidden = false;
      payBtn.textContent = "Zaplatit " + moneyMinor(priced.chargeMinor);
      payBtn.classList.toggle("btn-primary", !covered);
      payBtn.classList.toggle("btn-secondary", covered);
    }
    const maxCount = maxSelectable();
    const countEl = bar.querySelector("[data-hours-count]");
    const minus = bar.querySelector("[data-hours-minus]");
    const plus = bar.querySelector("[data-hours-plus]");
    const all = bar.querySelector("[data-hours-all]");
    if (countEl) countEl.textContent = blocksWord(count);
    if (minus) minus.disabled = count <= 1;
    if (plus) plus.disabled = count >= maxCount;
    if (all) {
      all.disabled = maxCount <= 1 || count >= maxCount;
      all.classList.toggle("is-on", maxCount > 1 && count >= maxCount);
    }
  };

  const setLoading = (loading) => {
    if (loadingEl) loadingEl.hidden = !loading;
    if (hoursEl) hoursEl.hidden = loading || !!state.availability.closed;
  };

  const fetchJson = async (url) => {
    const response = await fetch(url, {
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });
    if (response.status === 401) {
      window.location.assign(document.body.dataset.signedOut || "/odhlaseno");
      throw new Error("Odhlášeno");
    }
    const body = await response.json();
    if (!response.ok || !body.success) {
      throw new Error(body.message || "Nepodařilo se načíst data.");
    }
    return body.data;
  };

  const loadDay = async (date, pushUrl) => {
    state.date = date;
    state.selected = [];
    state.hours = 1;
    state.error = "";
    renderCalendar();
    setLoading(true);
    renderHours();
    try {
      const data = await fetchJson(root.getAttribute("data-availability-url") + "?date=" + encodeURIComponent(date) + roomParam());
      state.availability = data;
      state.guests = Math.min(Math.max(1, state.guests), maxPersons());
      if (pushUrl !== false) {
        const page = root.getAttribute("data-page-url") || "";
        history.replaceState({}, "", page + "?date=" + encodeURIComponent(date) + roomParam());
      }
    } catch (error) {
      state.availability = { closed: false, slots: [] };
      state.error = error.message || "Hodiny se nepodařilo načíst.";
    } finally {
      setLoading(false);
      updateForm();
      renderHours();
      renderBar();
      renderCalendar();
    }
  };

  const isCalOpen = () => !!(calModal && !calModal.hidden);

  const openCalendar = async () => {
    if (!calModal) return;
    calModal.hidden = false;
    document.body.classList.add("cal-open");
    if (openCalBtn) openCalBtn.setAttribute("aria-expanded", "true");
    await loadCalendar();
    const selected = calGrid?.querySelector(".cal-day.is-selected:not(:disabled)") || calendar?.querySelector(".cal-modal-close");
    selected?.focus();
  };

  const closeCalendar = () => {
    if (!calModal || calModal.hidden) return;
    calModal.hidden = true;
    document.body.classList.remove("cal-open");
    if (openCalBtn) {
      openCalBtn.setAttribute("aria-expanded", "false");
      openCalBtn.focus();
    }
  };

  const loadCalendar = async () => {
    const key = state.calYear + "-" + state.calMonth;
    if (state.calCache.has(key)) {
      state.calDays = state.calCache.get(key);
      renderCalendar();
      return;
    }
    state.calDays = [];
    renderCalendar();
    try {
      const data = await fetchJson(root.getAttribute("data-calendar-url") + "?year=" + state.calYear + "&month=" + state.calMonth + roomParam());
      state.calDays = data.days || [];
      state.calCache.set(key, state.calDays);
    } catch {
      state.calDays = [];
    }
    renderCalendar();
  };

  const renderCalendar = () => {
    if (calTitle) calTitle.textContent = MONTHS[state.calMonth - 1] + " " + state.calYear;
    if (!calGrid) return;
    const first = new Date(state.calYear, state.calMonth - 1, 1);
    const startPad = (first.getDay() + 6) % 7;
    const lastDate = new Date(state.calYear, state.calMonth, 0).getDate();
    const byDate = new Map((state.calDays || []).map((day) => [day.date, day]));
    const maxDate = maxBookable();
    let html = "";
    for (let i = 0; i < startPad; i += 1) html += '<span class="cal-day is-pad"></span>';
    for (let day = 1; day <= lastDate; day += 1) {
      const value = state.calYear + "-" + pad(state.calMonth) + "-" + pad(day);
      const info = byDate.get(value) || { closed: false, free: 0 };
      const past = value < state.today;
      const future = value > maxDate;
      const selected = value === state.date;
      const today = value === state.today;
      const disabled = past || future;
      const classes = [
        "cal-day",
        selected ? "is-selected" : "",
        today ? "is-today" : "",
        info.closed ? "is-closed" : "",
        info.free > 0 && !disabled ? "is-free" : "",
        info.mine ? "is-mine" : "",
        disabled ? "is-disabled" : "",
      ].filter(Boolean).join(" ");
      const label = info.mine ? day + ", tvoje rezervace" : String(day);
      html += '<button type="button" class="' + classes + '" data-cal-day="' + value + '" aria-label="' + label + '"' + (disabled ? " disabled" : "") + ">" + day + "</button>";
    }
    calGrid.innerHTML = html;
  };

  hoursEl?.addEventListener("click", (event) => {
    const btn = event.target.closest("[data-hour-start]");
    if (!btn || btn.disabled) return;
    const start = btn.getAttribute("data-hour-start");
    const row = bookableRows().find((item) => item.start === start);
    if (row) clickHour(row);
  });

  openCalBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      if (isCalOpen()) closeCalendar();
      else openCalendar();
    });
  });
  calModal?.querySelectorAll("[data-cal-close]")?.forEach((btn) => {
    btn.addEventListener("click", closeCalendar);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && isCalOpen()) closeCalendar();
  });
  calendar?.querySelector("[data-cal-prev]")?.addEventListener("click", () => {
    state.calMonth -= 1;
    if (state.calMonth < 1) {
      state.calMonth = 12;
      state.calYear -= 1;
    }
    loadCalendar();
  });
  calendar?.querySelector("[data-cal-next]")?.addEventListener("click", () => {
    state.calMonth += 1;
    if (state.calMonth > 12) {
      state.calMonth = 1;
      state.calYear += 1;
    }
    loadCalendar();
  });
  calGrid?.addEventListener("click", (event) => {
    const btn = event.target.closest("[data-cal-day]");
    if (!btn || btn.disabled) return;
    const date = btn.getAttribute("data-cal-day");
    closeCalendar();
    loadDay(date);
  });

  bar?.querySelector("[data-clear]")?.addEventListener("click", () => setSelection([], 1));
  bar?.querySelector("[data-hours-minus]")?.addEventListener("click", () => setHours(state.hours - 1));
  bar?.querySelector("[data-hours-plus]")?.addEventListener("click", () => setHours(state.hours + 1));
  bar?.querySelector("[data-hours-all]")?.addEventListener("click", () => setHours(maxSelectable()));
  bar?.querySelector("[data-guest-minus]")?.addEventListener("click", () => {
    state.guests = Math.max(1, state.guests - 1);
    updateForm();
    renderHours();
    renderBar();
  });
  bar?.querySelector("[data-guest-plus]")?.addEventListener("click", () => {
    state.guests = Math.min(maxPersons(), state.guests + 1);
    updateForm();
    renderHours();
    renderBar();
  });
  form?.addEventListener("submit", async (event) => {
    if (!startInput?.value) {
      event.preventDefault();
      return;
    }
    event.preventDefault();
    const paying = event.submitter ? event.submitter.hasAttribute("data-pay") : !canCover(state.hours);
    if (payInput) payInput.value = paying ? "1" : "0";
    if (confirmBtn) confirmBtn.disabled = true;
    if (payBtn) payBtn.disabled = true;
    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      });
      if (response.status === 401) {
        window.location.assign(document.body.dataset.signedOut || "/odhlaseno");
        return;
      }
      const raw = await response.text();
      let body;
      try {
        body = JSON.parse(raw);
      } catch {
        throw new Error("Rezervaci se nepodařilo dokončit.");
      }
      const checkout = body.data?.checkout_url;
      const redirect = body.data?.redirect;
      if (checkout) {
        window.location.assign(checkout);
        return;
      }
      if (body.success && redirect) {
        window.location.assign(redirect);
        return;
      }
      throw new Error(body.message || "Rezervaci se nepodařilo dokončit.");
    } catch (error) {
      if (barMeta) barMeta.textContent = error.message || "Rezervaci se nepodařilo dokončit.";
      updateForm();
    }
  });

  updateForm();
  renderHours();
  renderBar();
})();
