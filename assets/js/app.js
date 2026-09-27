(() => {
  if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    const skip = ".toast, .cancel-modal, .pay-modal, .adash, [hidden]";
    const stamp = (nodes) => {
      nodes.forEach((el) => {
        if (!el.matches(skip)) el.classList.add("page-rise");
      });
    };
    const main = document.querySelector(".app-main");
    if (main) {
      stamp([...main.children]);
      main.querySelectorAll(":scope > .adash").forEach((shell) => stamp([...shell.children]));
    }
  }

  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  document.querySelectorAll('form').forEach((form) => {
    if (token && !form.querySelector('input[name="_csrf"]')) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = '_csrf';
      input.value = token;
      form.appendChild(input);
    }
  });

  const picker = document.querySelector("[data-avatar-picker]");
  const file = picker?.querySelector("[data-avatar-input]") || document.querySelector("[data-avatar-input]");
  const preview = picker?.querySelector("[data-avatar-preview]") || document.querySelector("[data-avatar-preview]");
  const save = picker?.querySelector("[data-avatar-save]");
  const errorEl = picker?.querySelector("[data-avatar-error]");
  const showPickerError = (message) => {
    if (!errorEl) return;
    errorEl.hidden = !message;
    errorEl.textContent = message || "";
  };
  if (file && preview) {
    file.addEventListener("change", () => {
      const chosen = file.files?.[0];
      showPickerError("");
      if (!chosen) {
        if (save) save.hidden = true;
        return;
      }
      const type = (chosen.type || "").toLowerCase();
      const allowed = /^image\/(jpeg|jpg|pjpeg|png|webp)$/.test(type)
        || (type === "" && /\.(jpe?g|png|webp)$/i.test(chosen.name || ""));
      if (!allowed) {
        showPickerError("Povolené formáty jsou JPEG, PNG a WebP.");
        file.value = "";
        if (save) save.hidden = true;
        return;
      }
      if (chosen.size > 5 * 1024 * 1024) {
        showPickerError("Obrázek je větší než 5 MB.");
        file.value = "";
        if (save) save.hidden = true;
        return;
      }
      const applyUrl = (url) => {
        const previewImg = preview.querySelector?.("[data-avatar-preview-img]") || (preview.tagName === "IMG" ? preview : null);
        const initials = preview.querySelector?.("[data-avatar-initials]");
        if (previewImg && previewImg !== preview) {
          previewImg.src = url;
          previewImg.hidden = false;
          preview.classList.add("has-photo");
          if (initials) initials.hidden = true;
        } else if (preview.tagName === "IMG") {
          preview.src = url;
        } else {
          preview.style.backgroundImage = "url('" + url + "')";
          preview.style.backgroundSize = "cover";
          preview.style.backgroundPosition = "center";
        }
        if (save) save.hidden = false;
      };
      applyUrl(URL.createObjectURL(chosen));
      const reader = new FileReader();
      reader.onload = () => applyUrl(String(reader.result || ""));
      reader.readAsDataURL(chosen);
    });
  }

  const toggle = document.querySelector('.menu-toggle');
  const menu = document.querySelector('.side-menu');
  const backdrop = document.querySelector('.side-backdrop');
  if (toggle && menu) {
    const setOpen = (open) => {
      menu.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Zavřít menu' : 'Otevřít menu');
      document.body.classList.toggle('menu-open', open);
      if (backdrop) {
        backdrop.classList.toggle('show', open);
      }
    };
    const closeBtn = document.querySelector('.menu-close');
    const collapseBtn = document.querySelector('.side-collapse');
    const desktop = window.matchMedia('(min-width: 981px)');
    const storageKey = 'privofit-side-collapsed';
    const applyCollapsed = () => {
      const collapsed = desktop.matches && localStorage.getItem(storageKey) === '1';
      document.body.classList.toggle('side-collapsed', collapsed);
      if (collapseBtn) {
        collapseBtn.setAttribute('aria-expanded', String(!collapsed));
        const label = collapseBtn.querySelector('[data-collapse-label]');
        if (label) label.textContent = collapsed ? 'Otevřít menu' : 'Sbalit menu';
      }
    };
    applyCollapsed();
    desktop.addEventListener('change', applyCollapsed);
    collapseBtn?.addEventListener('click', () => {
      const next = localStorage.getItem(storageKey) !== '1';
      localStorage.setItem(storageKey, next ? '1' : '0');
      applyCollapsed();
    });
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    closeBtn?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));
    menu.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setOpen(false);
    });
  }

  document.querySelectorAll("[data-copy]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const value = btn.getAttribute("data-copy") || "";
      try {
        await navigator.clipboard.writeText(value);
        const previous = btn.textContent;
        btn.textContent = "Zkopírováno";
        window.setTimeout(() => {
          btn.textContent = previous;
        }, 1600);
      } catch {
        btn.textContent = "Zkopíruj ručně";
      }
    });
  });

  const doorNav = document.querySelector("[data-door-nav]");
  const setDoorNav = (open) => {
    if (!doorNav) return;
    doorNav.classList.toggle("is-door-open", open);
    doorNav.classList.toggle("is-door-closed", !open);
    doorNav.title = open ? "Dveře, otevřeno" : "Dveře, zavřeno";
    doorNav.querySelector("[data-door-lock-open]")?.toggleAttribute("hidden", !open);
    doorNav.querySelector("[data-door-lock-closed]")?.toggleAttribute("hidden", open);
    doorNav.querySelector("[data-door-mark-open]")?.toggleAttribute("hidden", !open);
    doorNav.querySelector("[data-door-mark-closed]")?.toggleAttribute("hidden", open);
  };
  window.privofitDoorNav = setDoorNav;
  const paintDashDoor = (door, testMode) => {
    const card = document.querySelector("[data-dash-door]");
    if (!card || !door) return;
    const known = door.known !== false;
    const open = known && !!door.open;
    card.classList.toggle("is-open", open);
    card.classList.toggle("is-closed", known && !open);
    const state = card.querySelector("[data-dash-door-state]");
    if (state) state.textContent = !known ? "Neznámý stav" : (open ? "Otevřeno" : "Zavřeno");
    const heroDoor = document.querySelector("[data-hero-door]");
    const heroDoorState = document.querySelector("[data-hero-door-state]");
    if (heroDoor) {
      heroDoor.classList.toggle("is-open", open);
      heroDoor.classList.toggle("is-closed", known && !open);
    }
    if (heroDoorState) heroDoorState.textContent = !known ? "Neznámý" : (open ? "Otevřeno" : "Zavřeno");
    const online = !!door.online;
    const onlineStat = card.querySelector("[data-dash-online]");
    if (onlineStat) {
      onlineStat.classList.toggle("is-on", online);
      onlineStat.classList.toggle("is-off", !online);
    }
    const onlineLabel = card.querySelector("[data-dash-online-label]");
    const onlineSub = card.querySelector("[data-dash-online-sub]");
    if (onlineLabel) onlineLabel.textContent = online ? "Online" : "Offline";
    if (onlineSub) onlineSub.textContent = online ? "Zámek odpovídá" : "Zámek teď neodpovídá";
    if (door.battery !== null && door.battery !== undefined) {
      const percent = Math.max(0, Math.min(100, Number(door.battery)));
      const low = !!door.battery_low || percent <= 15;
      const stat = card.querySelector("[data-dash-battery-stat]");
      const bat = card.querySelector("[data-dash-bat]");
      const fill = card.querySelector("[data-dash-fill]");
      const label = card.querySelector("[data-dash-battery]");
      const sub = card.querySelector("[data-dash-battery-sub]");
      if (stat) stat.classList.toggle("is-low", low);
      if (bat) bat.classList.toggle("is-low", low);
      if (fill) fill.style.width = percent + "%";
      if (label) label.textContent = percent + "%";
      if (sub) sub.textContent = low ? "Dochází, vyměň článek" : "Nabití je v pořádku";
      document.querySelectorAll("[data-dash-battery]").forEach((node) => {
        node.textContent = percent + "%";
      });
      document.querySelectorAll("[data-dash-fill]").forEach((node) => {
        node.style.width = percent + "%";
      });
      document.querySelectorAll("[data-dash-bat]").forEach((node) => node.classList.toggle("is-low", low));
      document.querySelector("[data-hero-battery]")?.classList.toggle("is-low", low);
      const batteryNote = document.querySelector("[data-notify-battery]");
      const batteryMeta = document.querySelector("[data-notify-battery-meta]");
      if (batteryNote) batteryNote.hidden = !low;
      if (batteryMeta) batteryMeta.textContent = "Zbývá " + percent + " %.";
    }
    const offlineNote = document.querySelector("[data-notify-offline]");
    if (offlineNote) offlineNote.hidden = online;
    if (typeof window.privofitNotifyCount === "function") window.privofitNotifyCount();
    if (typeof testMode === "boolean") {
      const mode = card.querySelector("[data-dash-mode]");
      const modeLabel = card.querySelector("[data-dash-mode-label]");
      const modeSub = card.querySelector("[data-dash-mode-sub]");
      if (mode) {
        mode.classList.toggle("is-test", testMode);
        mode.classList.toggle("is-live", !testMode);
      }
      if (modeLabel) modeLabel.textContent = testMode ? "Test" : "Ostrý";
      if (modeSub) modeSub.textContent = testMode ? "Fyzické dveře se nepohnou" : "Příkaz jde rovnou na zámek";
    }
  };
  const doorNavUrl = doorNav?.getAttribute("data-door-nav") || "";
  const refreshDoorNav = async () => {
    if (!doorNavUrl || document.hidden) return;
    try {
      const response = await fetch(doorNavUrl, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store",
      });
      if (!response.ok) return;
      const json = await response.json();
      const doors = json && json.data && json.data.doors;
      if (!Array.isArray(doors) || doors.length === 0) return;
      const wanted = doorNav.getAttribute("data-door-nav-id");
      const door = doors.find((item) => String(item.id) === wanted) || doors.find((item) => item.active) || doors[0];
      if (!door || door.known === false) return;
      setDoorNav(!!door.open);
      paintDashDoor(door, json.data && json.data.test_mode);
    } catch {
      /* další odečet to zkusí znovu */
    }
  };
  if (doorNavUrl) {
    refreshDoorNav();
    window.setInterval(refreshDoorNav, 12000);
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) refreshDoorNav();
    });
  }

  const hide = (node) => {
    if (!node || node.classList.contains("is-out")) return;
    node.classList.add("is-out");
    window.setTimeout(() => node.remove(), 300);
  };
  document.querySelectorAll("[data-toast]").forEach((toast) => {
    toast.querySelector("[data-toast-close]")?.addEventListener("click", () => hide(toast));
    window.setTimeout(() => hide(toast), 3600);
  });

  const presenceUrl = document.body.dataset.presence;
  const signedOutUrl = document.body.dataset.signedOut;
  if (presenceUrl && signedOutUrl) {
    let checking = false;
    const ping = async () => {
      if (checking || document.visibilityState === "hidden") return;
      checking = true;
      try {
        const response = await fetch(presenceUrl, {
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
          credentials: "same-origin",
          cache: "no-store",
        });
        if (response.status === 401) window.location.assign(signedOutUrl);
      } catch {
        // Po probuzení notebooku síť chvíli nemusí být nahoře.
      } finally {
        checking = false;
      }
    };
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") ping();
    });
    window.addEventListener("focus", ping);
    window.addEventListener("online", ping);
    window.addEventListener("pageshow", (event) => {
      if (event.persisted) ping();
    });
    window.setInterval(ping, 12000);
  }

  const ensureViewFx = () => {
    let fx = document.querySelector("[data-view-fx]");
    if (fx) return fx;
    fx = document.createElement("div");
    fx.className = "view-fx";
    fx.setAttribute("data-view-fx", "");
    fx.hidden = true;
    fx.innerHTML = `
      <div class="view-fx-shade" aria-hidden="true"></div>
      <p class="view-fx-label" data-view-fx-label></p>
    `;
    document.body.appendChild(fx);
    return fx;
  };

  const playViewEnter = () => {
    const pending = sessionStorage.getItem("privofit-view-switch");
    if (!pending) return;
    sessionStorage.removeItem("privofit-view-switch");
    const fx = ensureViewFx();
    const label = fx.querySelector("[data-view-fx-label]");
    fx.dataset.mode = pending;
    if (label) label.textContent = pending === "admin" ? "Admin" : "Uživatel";
    fx.hidden = false;
    fx.classList.add("is-cover");
    document.body.classList.add("is-view-fx");
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        fx.classList.add("is-reveal");
        fx.classList.remove("is-cover");
      });
    });
    window.setTimeout(() => {
      fx.hidden = true;
      fx.classList.remove("is-reveal", "is-cover");
      document.body.classList.remove("is-view-fx");
    }, 700);
  };

  playViewEnter();

  const viewMode = document.querySelector("[data-view-mode]");
  if (viewMode) {
    const thumb = viewMode.querySelector("[data-view-thumb]");
    const current = viewMode.getAttribute("data-current") || "user";
    viewMode.querySelectorAll("[data-view-form]").forEach((form) => {
      form.addEventListener("submit", (event) => {
        const target = form.getAttribute("data-view-form") || "";
        if (!target || target === current) {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        if (viewMode.classList.contains("is-busy")) return;
        viewMode.classList.add("is-busy");

        if (thumb) {
          thumb.classList.toggle("is-admin", target === "admin");
          thumb.classList.toggle("is-user", target === "user");
        }
        viewMode.querySelectorAll(".view-mode-btn").forEach((btn) => {
          const active = btn.getAttribute("data-view-target") === target;
          btn.classList.toggle("is-active", active);
          btn.setAttribute("aria-pressed", active ? "true" : "false");
        });

        const fx = ensureViewFx();
        const label = fx.querySelector("[data-view-fx-label]");
        fx.dataset.mode = target;
        if (label) label.textContent = target === "admin" ? "Admin" : "Uživatel";
        fx.hidden = false;
        fx.classList.remove("is-reveal", "is-cover");
        document.body.classList.add("is-view-fx", "is-view-switching");
        requestAnimationFrame(() => {
          requestAnimationFrame(() => fx.classList.add("is-cover"));
        });
        sessionStorage.setItem("privofit-view-switch", target);
        window.setTimeout(() => form.submit(), 480);
      });
    });
  }
})();
