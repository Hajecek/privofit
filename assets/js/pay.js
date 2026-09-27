(() => {
  const configEl = document.getElementById("pay-config");
  const root = document.querySelector(".pay-checkout");
  if (!configEl || !root || !window.Stripe) return;

  const config = JSON.parse(configEl.textContent || "{}");
  const stripe = window.Stripe(config.publishableKey, { locale: "cs" });
  const feeEl = root.querySelector("[data-fee]");
  const feeLabelEl = root.querySelector("[data-fee-label]");
  const chargeEl = root.querySelector("[data-charge]");
  const noteEl = root.querySelector("[data-note]");
  const errorEl = root.querySelector("[data-error]");
  const button = root.querySelector("[data-submit]");
  const walletsEl = root.querySelector("[data-wallets]");
  const orEl = root.querySelector("[data-pay-or]");
  let shownMinor = Number(config.chargeMinor || 0);
  let busy = false;
  let leaving = false;

  const crowns = (value) =>
    Number(value).toLocaleString("cs-CZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " Kč";

  const showError = (message) => {
    if (!errorEl) return;
    errorEl.hidden = !message;
    errorEl.textContent = message || "";
  };

  const elements = stripe.elements({
    mode: "payment",
    amount: shownMinor,
    currency: "czk",
    locale: "cs",
    appearance: {
      theme: "night",
      variables: {
        colorPrimary: "#c6f21a",
        colorBackground: "#101714",
        colorText: "#e8f0e4",
        colorDanger: "#ff8b8b",
        borderRadius: "12px",
        fontFamily: "Figtree, sans-serif",
      },
    },
  });

  const wallets = elements.create("expressCheckout", {
    buttonHeight: 48,
    billingAddressRequired: false,
    buttonTheme: { applePay: "white", googlePay: "white" },
    layout: { maxColumns: 1, maxRows: 2 },
    paymentMethodOrder: ["applePay", "googlePay"],
    paymentMethods: {
      applePay: "always",
      googlePay: "auto",
      link: "never",
      paypal: "never",
      amazonPay: "never",
      klarna: "never",
    },
  });
  wallets.mount("#express-checkout");
  wallets.on("availablepaymentmethodschange", ({ paymentMethods }) => {
    const available = Boolean(paymentMethods && (paymentMethods.applePay || paymentMethods.googlePay));
    walletsEl?.classList.toggle("is-ready", available);
    if (orEl) orEl.hidden = !available;
  });
  wallets.on("click", (event) => {
    event.resolve({
      lineItems: [{ name: config.title || "PRIVOFIT", amount: shownMinor }],
    });
  });

  elements.create("payment", {
    layout: "tabs",
    wallets: { applePay: "never", googlePay: "never" },
  }).mount("#payment-element");

  const applyQuote = (quote) => {
    shownMinor = Number(quote.chargeMinor || shownMinor);
    elements.update({ amount: shownMinor });
    if (feeEl) feeEl.textContent = crowns(quote.fee);
    if (feeLabelEl) feeLabelEl.textContent = "Poplatek · " + (quote.label || "karta");
    if (chargeEl) chargeEl.textContent = crowns(quote.charge);
    if (button) button.textContent = "Zaplatit " + crowns(quote.charge);
    if (noteEl) {
      noteEl.hidden = false;
      noteEl.textContent = quote.assumed
        ? "Link neposlal zemi karty. Poplatek je proto jako u zahraniční karty, ať na účtu zůstane celá cena. Zkontroluj částku a zaplať znovu."
        : "Tahle karta má jiný poplatek. Zkontroluj částku a zaplať znovu.";
    }
  };

  const post = async (body) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const response = await fetch(config.confirmUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-TOKEN": token,
      },
      body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.success === false) {
      throw new Error(payload.message || "Platbu se nepodařilo dokončit.");
    }
    return payload.data || {};
  };

  const completePayment = async () => {
    const submitted = await elements.submit();
    if (submitted.error) {
      showError(submitted.error.message || "Zkontroluj údaje karty.");
      return false;
    }
    const created = await stripe.createConfirmationToken({ elements });
    if (created.error || !created.confirmationToken) {
      showError(created.error?.message || "Kartu se nepodařilo načíst.");
      return false;
    }
    const data = await post({
      platba: config.platba,
      confirmation_token: created.confirmationToken.id,
      shown_minor: shownMinor,
    });
    if (!data.ready) {
      applyQuote(data);
      return false;
    }
    if (data.status === "requires_action" && data.clientSecret) {
      const next = stripe.handleNextAction
        ? await stripe.handleNextAction({ clientSecret: data.clientSecret })
        : await stripe.confirmCardPayment(data.clientSecret);
      if (next.error) {
        showError(next.error.message || "Ověření karty se nedokončilo.");
        return false;
      }
    }
    const url = new URL(data.returnUrl, window.location.origin);
    if (data.intentId) url.searchParams.set("payment_intent", data.intentId);
    leaving = true;
    window.location.assign(url.toString());
    return true;
  };

  const failWallet = (event) => {
    if (typeof event.paymentFailed === "function") {
      event.paymentFailed({ reason: "fail" });
    }
  };

  wallets.on("confirm", async (event) => {
    if (busy) {
      failWallet(event);
      return;
    }
    busy = true;
    if (button) button.disabled = true;
    showError("");
    try {
      const paid = await completePayment();
      if (!paid) failWallet(event);
    } catch (error) {
      showError(error instanceof Error ? error.message : "Platbu se nepodařilo dokončit.");
      failWallet(event);
    } finally {
      busy = false;
      if (!leaving && button) button.disabled = false;
    }
  });

  button?.addEventListener("click", async () => {
    if (busy) return;
    busy = true;
    button.disabled = true;
    showError("");
    try {
      await completePayment();
    } catch (error) {
      showError(error instanceof Error ? error.message : "Platbu se nepodařilo dokončit.");
    } finally {
      busy = false;
      if (!leaving && button) button.disabled = false;
    }
  });
})();
