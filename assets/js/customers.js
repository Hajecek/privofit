(() => {
  const modal = document.querySelector("[data-cust-modal]");
  if (!modal) return;

  const titleEl = modal.querySelector("[data-cust-title]");
  const bodyEl = modal.querySelector("[data-cust-body]");
  const eyebrowEl = modal.querySelector("[data-cust-eyebrow]");
  const confirmBtn = modal.querySelector("[data-cust-confirm]");
  const blockForm = document.querySelector('[data-cust-form="block"]');
  const unblockForm = document.querySelector('[data-cust-form="unblock"]');
  const deleteForm = document.querySelector('[data-cust-form="delete"]');
  let pending = null;

  const copy = {
    block: {
      eyebrow: "Blokace",
      title: "Zablokovat účet?",
      body: (name) =>
        `Účet ${name} se nebude moct přihlásit ani rezervovat. Kdykoli zase odblokuješ přepínačem.`,
      confirm: "Zablokovat",
      danger: true,
    },
    unblock: {
      eyebrow: "Odblokování",
      title: "Odblokovat účet?",
      body: (name) => `Účet ${name} znovu získá přístup k přihlášení a rezervacím.`,
      confirm: "Odblokovat",
      danger: false,
    },
    delete: {
      eyebrow: "Smazání",
      title: "Trvale smazat účet?",
      body: (name) =>
        `Účet ${name} se soft-smaže a už se neobjeví v seznamu. Tuhle akci nejde snadno vrátit.`,
      confirm: "Smazat",
      danger: true,
    },
    "revoke-membership": {
      eyebrow: "Členství",
      title: "Odebrat členství?",
      body: (name) =>
        `Tarif ${name} se zruší a zákazník s ním už nebude moct rezervovat zdarma.`,
      confirm: "Odebrat",
      danger: true,
    },
    "reset-membership": {
      eyebrow: "Historie",
      title: "Resetovat historii členství?",
      body: (name) =>
        `U zákazníka ${name} se smaže celá historie členství včetně aktivních tarifů. Tohle nejde vrátit.`,
      confirm: "Resetovat",
      danger: true,
    },
    "role-admin": {
      eyebrow: "Role",
      title: "Udělat administrátora?",
      body: (name) =>
        `${name} získá přístup do správy studia, zákazníků a nastavení.`,
      confirm: "Nastavit admina",
      danger: true,
    },
    "role-user": {
      eyebrow: "Role",
      title: "Odebrat admin roli?",
      body: (name) =>
        `${name} zůstane jen jako běžný zákazník bez přístupu do správy.`,
      confirm: "Nastavit zákazníka",
      danger: true,
    },
    "status-pending": {
      eyebrow: "Stav",
      title: "Nastavit stav Čeká?",
      body: (name) =>
        `Účet ${name} bude čekat na aktivaci a nebude mít plný přístup.`,
      confirm: "Nastavit Čeká",
      danger: false,
    },
    "status-active": {
      eyebrow: "Stav",
      title: "Aktivovat účet?",
      body: (name) => `Účet ${name} bude aktivní a připravený k používání.`,
      confirm: "Aktivovat",
      danger: false,
    },
    "interest-delete": {
      eyebrow: "Zájem",
      title: "Smazat e-mail ze zájmu?",
      body: (name) =>
        `${name} se ze seznamu zájmu odstraní. Kdykoli se může znovu přihlásit formulářem.`,
      confirm: "Smazat",
      danger: true,
    },
    "cancel-reservation": {
      eyebrow: "Rezervace",
      title: "Zrušit rezervaci?",
      body: (name) =>
        `Termín ${name} se uvolní a zákazník dostane e-mail. Komentář uvidí u zrušené rezervace. Zaplacená částka i vstup z členství se vrací.`,
      confirm: "Zrušit rezervaci",
      danger: true,
    },
  };

  const reasonWrap = modal.querySelector("[data-cust-reason-wrap]");
  const reasonInput = modal.querySelector("[data-cust-reason]");
  const needsReason = (kind) => kind === "block" || kind === "delete" || kind === "cancel-reservation";

  const close = () => {
    if (pending?.revert) pending.revert();
    modal.hidden = true;
    pending = null;
    if (confirmBtn) confirmBtn.disabled = false;
    if (reasonInput) reasonInput.value = "";
    if (reasonWrap) reasonWrap.hidden = true;
  };

  const open = (kind, opts = {}) => {
    const cfg = copy[kind];
    if (!cfg) return;
    const name = opts.name || "tohoto zákazníka";
    if (titleEl) titleEl.textContent = cfg.title;
    if (bodyEl) bodyEl.textContent = cfg.body(name);
    if (eyebrowEl) eyebrowEl.textContent = cfg.eyebrow;
    if (confirmBtn) {
      confirmBtn.textContent = cfg.confirm;
      confirmBtn.className = cfg.danger ? "btn btn-danger" : "btn btn-primary";
    }
    if (reasonWrap) reasonWrap.hidden = !needsReason(kind);
    const reasonLabel = reasonWrap?.querySelector("label");
    if (reasonLabel) {
      reasonLabel.textContent = kind === "cancel-reservation" ? "Komentář pro zákazníka" : "Zpráva pro uživatele";
    }
    if (reasonInput) {
      reasonInput.value = "";
      reasonInput.maxLength = kind === "cancel-reservation" ? 255 : 2000;
      reasonInput.placeholder =
        kind === "delete"
          ? "Volitelné — např. důvod smazání účtu."
          : kind === "cancel-reservation"
            ? "Volitelné — proč se termín ruší. Zákazník to uvidí."
            : "Volitelné — důvod blokace uvidí uživatel při odhlášení.";
    }
    pending = {
      kind,
      form: opts.form || null,
      revert: opts.revert || null,
    };
    modal.hidden = false;
    if (needsReason(kind) && reasonInput) reasonInput.focus();
    else modal.querySelector("[data-cust-close]")?.focus();
  };

  const setSwitch = (el, on) => {
    el.classList.toggle("is-on", on);
    el.setAttribute("aria-checked", on ? "true" : "false");
    const label = el.querySelector("[data-cust-switch-label]");
    if (label) label.textContent = on ? "Blokováno" : "Aktivní";
  };

  document.querySelectorAll("[data-cust-switch]").forEach((sw) => {
    sw.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (sw.disabled) return;
      const blocked = sw.getAttribute("aria-checked") === "true";
      const name = sw.getAttribute("data-name") || "tohoto zákazníka";
      const redirect = sw.getAttribute("data-redirect") || "";

      if (!blocked) {
        setSwitch(sw, true);
        if (blockForm) {
          const action = sw.getAttribute("data-block");
          if (action) blockForm.setAttribute("action", action);
          const redirectInput = blockForm.querySelector('[name="redirect"]');
          if (redirectInput) redirectInput.value = redirect;
        }
        open("block", {
          name,
          form: blockForm,
          revert: () => setSwitch(sw, false),
        });
        return;
      }

      setSwitch(sw, false);
      if (unblockForm) {
        const action = sw.getAttribute("data-unblock");
        if (action) unblockForm.setAttribute("action", action);
        const redirectInput = unblockForm.querySelector('[name="redirect"]');
        if (redirectInput) redirectInput.value = redirect;
      }
      open("unblock", {
        name,
        form: unblockForm,
        revert: () => setSwitch(sw, true),
      });
    });
  });

  const setSeg = (seg, target) => {
    if (!seg) return;
    const thumb = seg.querySelector("[data-cust-seg-thumb]");
    const right = target === "admin" || target === "active";
    seg.classList.toggle("is-on", right);
    if (thumb) {
      thumb.classList.toggle("is-right", right);
      thumb.classList.toggle("is-left", !right);
    }
    seg.querySelectorAll(".cust-seg-btn").forEach((btn) => {
      const active = btn.getAttribute("data-cust-seg-target") === target;
      btn.classList.toggle("is-active", active);
    });
  };

  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-cust-open]");
    if (!button) return;
    event.preventDefault();
      event.stopPropagation();
      if (button.disabled) return;
      const kind = button.getAttribute("data-cust-open") || "";
      const target = button.getAttribute("data-cust-seg-target") || "";
      const seg = button.closest("[data-cust-seg]");
      if (seg && target && seg.getAttribute("data-current") === target) return;

      const action = button.getAttribute("data-action");
      const form = document.querySelector(`[data-cust-form="${kind}"]`) || (kind === "delete" ? deleteForm : null);
      if (!form) return;
      if (action) form.setAttribute("action", action);
      const membershipInput = form.querySelector("[data-cust-membership-id]");
      if (membershipInput) {
        membershipInput.value = button.getAttribute("data-membership-id") || "";
      }

      const previous = seg?.getAttribute("data-current") || "";
      if (seg && target) setSeg(seg, target);

      open(kind, {
        name: button.getAttribute("data-name") || "tohoto zákazníka",
        form,
        revert: seg && target
          ? () => setSeg(seg, previous)
          : null,
      });
  });

  modal.querySelectorAll("[data-cust-close]").forEach((el) => {
    el.addEventListener("click", close);
  });

  confirmBtn?.addEventListener("click", () => {
    if (!pending?.form) return;
    const form = pending.form;
    const reasonField = form.querySelector("[data-cust-reason-field]");
    if (reasonField && reasonInput) {
      reasonField.value = reasonInput.value.trim();
    }
    pending.revert = null;
    confirmBtn.disabled = true;
    form.submit();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hidden) close();
  });

  document.querySelectorAll(".cust-row[data-href]").forEach((row) => {
    const go = () => {
      const href = row.getAttribute("data-href");
      if (href) window.location.href = href;
    };
    row.addEventListener("click", (event) => {
      if (event.target.closest("[data-stop]")) return;
      go();
    });
    row.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        go();
      }
    });
  });
})();
