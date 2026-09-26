(() => {
  const modal = document.querySelector("[data-pay-modal]");

  const marks = {
    apple_pay: {
      icon: '<svg viewBox="0 0 16 20" aria-hidden="true"><path fill="#111" d="M13.1 10.6c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9s-1.8-.8-3-.8c-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.3 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7 2-1.1 2.7-2.2c.9-1.2 1.2-2.4 1.2-2.5-.1 0-2.3-.9-2.3-3.9zM11.1 3.8c.6-.8 1.1-1.9.9-3-1 .1-2.1.7-2.8 1.5-.6.7-1.2 1.8-.9 2.9 1.1.1 2.1-.5 2.8-1.4z"/></svg>',
      lockup: '<svg viewBox="0 0 92 28" aria-hidden="true"><path fill="#111" d="M16.2 8.4c-.8.9-2 1.6-3.2 1.5-.1-1.3.5-2.6 1.2-3.4.8-.9 2.1-1.6 3.2-1.7.1 1.3-.4 2.6-1.2 3.6zM18.4 10.2c-1.8-.1-3.3 1-4.1 1s-2.2-1-3.6-1c-1.8 0-3.5 1.1-4.4 2.7-1.9 3.3-.5 8.1 1.3 10.8.9 1.3 2 2.7 3.4 2.7 1.3 0 1.8-.9 3.4-.9s2 .9 3.4.9 2.3-1.3 3.2-2.6c1-1.4 1.4-2.8 1.4-2.9-.1 0-2.7-1-2.7-4.1 0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.5-2.1z"/><text x="34" y="20" fill="#111" font-family="system-ui, sans-serif" font-size="16" font-weight="600">Pay</text></svg>',
    },
    google_pay: {
      icon: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.6a4.8 4.8 0 0 1-2.1 3.2v2.6h3.4c2-1.8 3.1-4.5 3.1-7.6z"/><path fill="#34A853" d="M12 23c2.8 0 5.2-.9 6.9-2.5l-3.4-2.6c-.9.6-2.1 1-3.5 1-2.7 0-5-1.8-5.8-4.3H2.7v2.7A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M6.2 14.6A6.6 6.6 0 0 1 5.8 12c0-.9.2-1.8.4-2.6V6.7H2.7A11 11 0 0 0 1 12c0 1.8.4 3.5 1.2 5l4-2.4z"/><path fill="#EA4335" d="M12 5.4c1.5 0 2.9.5 4 1.5l3-3A11 11 0 0 0 2.7 6.7l3.5 2.7C7 7.2 9.3 5.4 12 5.4z"/></svg>',
      lockup: '<svg viewBox="0 0 92 28" aria-hidden="true"><path fill="#4285F4" d="M21.5 14.2c0-.6-.1-1.2-.2-1.7H12v3.2h5.3a4.1 4.1 0 0 1-1.8 2.7v2.2h2.9c1.7-1.6 2.7-3.9 2.1-6.4z"/><path fill="#34A853" d="M12 23.2c2.4 0 4.4-.8 5.9-2.1l-2.9-2.2c-.8.5-1.8.9-3 .9-2.3 0-4.2-1.5-4.9-3.6H4.1v2.3a9.2 9.2 0 0 0 7.9 5z"/><path fill="#FBBC05" d="M7.1 16.2a5.5 5.5 0 0 1 0-3.5V10.4H4.1a9.2 9.2 0 0 0 0 8.2l3-2.4z"/><path fill="#EA4335" d="M12 8.6c1.3 0 2.5.5 3.4 1.3l2.6-2.6A9.2 9.2 0 0 0 4.1 10.4l3 2.3c.7-2.1 2.6-4.1 4.9-4.1z"/><text x="30" y="19" fill="#3C4043" font-family="system-ui, sans-serif" font-size="15" font-weight="600">Pay</text></svg>',
    },
    mastercard: {
      icon: '<svg viewBox="0 0 36 22" aria-hidden="true"><circle cx="13" cy="11" r="8" fill="#EB001B"/><circle cx="23" cy="11" r="8" fill="#F79E1B"/><path fill="#FF5F00" d="M18 4.6a8 8 0 0 1 0 12.8 8 8 0 0 1 0-12.8z"/></svg>',
      lockup: '<svg viewBox="0 0 36 22" aria-hidden="true"><circle cx="13" cy="11" r="8" fill="#EB001B"/><circle cx="23" cy="11" r="8" fill="#F79E1B"/><path fill="#FF5F00" d="M18 4.6a8 8 0 0 1 0 12.8 8 8 0 0 1 0-12.8z"/></svg>',
    },
    amex: {
      icon: '<span class="pay-word is-amex">AMEX</span>',
      lockup: '<span class="pay-word is-amex is-lockup">AMEX</span>',
    },
    visa: {
      icon: '<span class="pay-word is-visa">VISA</span>',
      lockup: '<span class="pay-word is-visa is-lockup">VISA</span>',
    },
    link: {
      icon: '<span class="pay-word is-link">link</span>',
      lockup: '<span class="pay-word is-link is-lockup">link</span>',
    },
    paypal: {
      icon: '<span class="pay-word is-paypal">P</span>',
      lockup: '<span class="pay-word is-paypal is-lockup">Pay<span>Pal</span></span>',
    },
    klarna: {
      icon: '<span class="pay-word is-klarna">K</span>',
      lockup: '<span class="pay-word is-klarna is-lockup">Klarna</span>',
    },
    revolut_pay: {
      icon: '<span class="pay-word is-revolut">R</span>',
      lockup: '<span class="pay-word is-revolut is-lockup">Revolut</span>',
    },
    sepa_debit: {
      icon: '<span class="pay-word is-sepa">SEPA</span>',
      lockup: '<span class="pay-word is-sepa is-lockup">SEPA</span>',
    },
    bancontact: {
      icon: '<span class="pay-word is-bancontact">B</span>',
      lockup: '<span class="pay-word is-bancontact is-lockup">Bancontact</span>',
    },
    ideal: {
      icon: '<span class="pay-word is-ideal">i</span>',
      lockup: '<span class="pay-word is-ideal is-lockup">iDEAL</span>',
    },
    card: {
      icon: '<svg viewBox="0 0 24 16" aria-hidden="true"><rect x="1" y="1" width="22" height="14" rx="2" fill="none" stroke="#111" stroke-width="1.6"/><path stroke="#111" stroke-width="1.6" d="M1 5h22"/></svg>',
      lockup: '<svg viewBox="0 0 24 16" aria-hidden="true"><rect x="1" y="1" width="22" height="14" rx="2" fill="none" stroke="#111" stroke-width="1.6"/><path stroke="#111" stroke-width="1.6" d="M1 5h22"/></svg>',
    },
  };

  const paint = (el, kind, mode) => {
    if (!el) return;
    const safe = Object.prototype.hasOwnProperty.call(marks, kind) ? kind : "card";
    el.dataset.kind = safe;
    el.innerHTML = marks[safe][mode] || marks[safe].icon;
  };

  document.querySelectorAll("[data-pay-mark]").forEach((el) => {
    paint(el, el.getAttribute("data-pay-mark") || "", "icon");
  });

  if (!modal) return;

  const titleEl = modal.querySelector("[data-pay-title]");
  const heroEl = modal.querySelector("[data-pay-hero-mark]");
  const plateEl = modal.querySelector("[data-pay-plate]");
  const brandPlate = modal.querySelector("[data-pay-brand-plate]");
  const brandEl = modal.querySelector("[data-pay-brand-mark]");
  const cardEl = modal.querySelector("[data-pay-card]");
  const statusEl = modal.querySelector("[data-pay-status]");
  const testEl = modal.querySelector("[data-pay-test]");
  const moneyEl = modal.querySelector("[data-pay-money]");
  const chargedCard = modal.querySelector("[data-pay-charged-card]");
  const chargedEl = modal.querySelector("[data-pay-charged]");
  const netCard = modal.querySelector("[data-pay-net-card]");
  const netEl = modal.querySelector("[data-pay-net]");
  const rowsEl = modal.querySelector("[data-pay-rows]");
  const receiptEl = modal.querySelector("[data-pay-receipt]");
  let lastFocus = null;

  const close = () => {
    modal.hidden = true;
    lastFocus?.focus();
    lastFocus = null;
  };

  const showText = (el, value) => {
    const text = typeof value === "string" ? value.trim() : "";
    el.hidden = text === "";
    el.textContent = text;
    return text !== "";
  };

  const open = (button) => {
    let data;
    try {
      data = JSON.parse(button.getAttribute("data-pay-detail") || "");
    } catch {
      return;
    }
    if (!data || typeof data !== "object") return;

    titleEl.textContent = typeof data.title === "string" && data.title !== "" ? data.title : "Platba";
    const mark = typeof data.mark === "string" ? data.mark : "card";
    paint(heroEl, mark, "lockup");
    plateEl.dataset.kind = Object.prototype.hasOwnProperty.call(marks, mark) ? mark : "card";

    const brand = typeof data.brand === "string" ? data.brand : "";
    if (brand && brand !== mark && Object.prototype.hasOwnProperty.call(marks, brand)) {
      paint(brandEl, brand, "icon");
      brandPlate.hidden = false;
      brandPlate.dataset.kind = brand;
    } else {
      brandPlate.hidden = true;
      brandEl.replaceChildren();
    }

    showText(cardEl, data.card);
    const hasStatus = showText(statusEl, data.status);
    statusEl.className = "pay-pill" + (hasStatus ? " is-" + (data.tone === "ok" || data.tone === "bad" || data.tone === "warn" ? data.tone : "muted") : "");
    testEl.hidden = !data.test;

    const charged = typeof data.charged === "string" ? data.charged : "";
    const net = typeof data.net === "string" ? data.net : "";
    chargedCard.hidden = charged === "";
    chargedEl.textContent = charged;
    netCard.hidden = net === "";
    netEl.textContent = net;
    moneyEl.hidden = charged === "" && net === "";

    rowsEl.replaceChildren();
    let rowCount = 0;
    (Array.isArray(data.rows) ? data.rows : []).forEach((row) => {
      if (!row || typeof row.label !== "string" || typeof row.value !== "string" || row.value === "") return;
      const label = document.createElement("dt");
      label.textContent = row.label;
      const value = document.createElement("dd");
      value.textContent = row.value;
      rowsEl.append(label, value);
      rowCount += 1;
    });
    rowsEl.hidden = rowCount === 0;

    const receipt = typeof data.receipt === "string" && data.receipt.startsWith("https://") ? data.receipt : "";
    if (receipt) {
      receiptEl.href = receipt;
      receiptEl.hidden = false;
    } else {
      receiptEl.hidden = true;
      receiptEl.removeAttribute("href");
    }

    lastFocus = button;
    modal.hidden = false;
    modal.querySelector(".pay-modal-x")?.focus();
  };

  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-pay-open]");
    if (button) {
      open(button);
      return;
    }
    if (!modal.hidden && event.target.closest("[data-pay-close]")) close();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hidden) close();
  });
})();
