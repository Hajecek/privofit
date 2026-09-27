(() => {
  const configEl = document.getElementById("pay-config");
  const root = document.querySelector(".pay-checkout");
  if (!configEl || !root || !window.Stripe) return;

  const config = JSON.parse(configEl.textContent || "{}");
  const stripe = window.Stripe(config.publishableKey, { locale: "cs" });
  const feeEl = root.querySelector("[data-fee]");
  const feeLabelEl = root.querySelector("[data-fee-label]");
  const chargeEl = root.querySelector("[data-charge]");
  const errorEl = root.querySelector("[data-error]");
  const statusEl = root.querySelector("[data-status]");
  const button = root.querySelector("[data-submit]");
  const walletsEl = root.querySelector("[data-wallets]");
  const orEl = root.querySelector("[data-pay-or]");
  const quotes = config.quotes || {};
  let shownMinor = Number(config.chargeMinor || 0);
  let quoteReady = Promise.resolve();
  let busy = false;
  let leaving = false;

  const crowns = (value) =>
    Number(value).toLocaleString("cs-CZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " Kč";

  const showError = (message) => {
    if (!errorEl) return;
    errorEl.hidden = !message;
    errorEl.textContent = message || "";
  };

  const showStatus = (message) => {
    if (!statusEl) return;
    statusEl.hidden = !message;
    statusEl.textContent = message || "";
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
  let selectedKey = "apple";

  const markChoice = (minor) => {
    const buttons = [...root.querySelectorAll("[data-choice]")];
    const current = buttons.find((el) => el.getAttribute("data-choice") === selectedKey && Number(el.dataset.minor) === Number(minor));
    const match = current || buttons.find((el) => Number(el.dataset.minor) === Number(minor));
    if (!match) return;
    selectedKey = match.getAttribute("data-choice") || selectedKey;
    buttons.forEach((el) => el.classList.toggle("is-on", el === match));
  };

  const paint = (quote) => {
    shownMinor = Number(quote.chargeMinor || shownMinor);
    if (feeEl) feeEl.textContent = crowns(quote.fee);
    if (feeLabelEl && quote.label) feeLabelEl.textContent = quote.label;
    if (chargeEl) chargeEl.textContent = crowns(quote.charge);
    markChoice(shownMinor);
  };

  const walletHint = root.querySelector("[data-wallet-hint]");

  const applyMode = () => {
    if (!walletHint) return;
    walletHint.textContent = selectedKey === "google"
      ? "Nebo tlačítkem Google Pay."
      : "Nebo tlačítkem Apple Pay.";
  };

  const useQuote = async (quote) => {
    if (!quote) return;
    paint(quote);
    await elements.update({ amount: shownMinor });
  };

  root.querySelectorAll("[data-choice]").forEach((node) => {
    node.addEventListener("click", () => {
      const key = node.getAttribute("data-choice") || "";
      const quote = quotes[key];
      if (!quote || busy) return;
      selectedKey = key;
      markChoice(quote.chargeMinor);
      applyMode();
      quoteReady = useQuote(quote);
    });
  });
  applyMode();

  wallets.on("click", async (event) => {
    const key = event.expressPaymentType === "google_pay" ? "google" : "apple";
    const quote = quotes[key];
    if (quote && Number(quote.chargeMinor) !== shownMinor) {
      selectedKey = key;
      await useQuote(quote);
    }
    event.resolve({
      lineItems: [{ name: config.title || "PRIVOFIT", amount: shownMinor }],
    });
  });

  const paymentElement = elements.create("payment", {
    layout: "tabs",
    wallets: { applePay: "never", googlePay: "never" },
  });
  paymentElement.mount("#payment-element");

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

  const authorize = async () => {
    const submitted = await elements.submit();
    if (submitted.error) {
      showError(submitted.error.message || "Zkontroluj údaje karty.");
      return null;
    }
    const created = await stripe.createConfirmationToken({ elements });
    if (created.error || !created.confirmationToken) {
      showError(created.error?.message || "Kartu se nepodařilo načíst.");
      return null;
    }
    return post({
      platba: config.platba,
      confirmation_token: created.confirmationToken.id,
      shown_minor: shownMinor,
    });
  };

  const completePayment = async (options = {}) => {
    await quoteReady;
    let data = await authorize();
    if (!data) return false;
    if (!data.ready && options.keepAmount) {
      await useQuote(data);
      applyMode();
      showStatus("Poplatek se srovnal s kartou. Potvrď platbu znovu.");
      return false;
    }
    if (!data.ready) {
      showStatus("Probíhá platba. Na nic už neklikej.");
      await useQuote(data);
      data = await authorize();
      if (!data) return false;
    }
    if (!data.ready) {
      showStatus("");
      showError("Částku se nepodařilo sladit s kartou. Zkus to znovu.");
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
    showStatus("");
    try {
      const paid = await completePayment({ keepAmount: true });
      if (!paid) failWallet(event);
    } catch (error) {
      showError(error instanceof Error ? error.message : "Platbu se nepodařilo dokončit.");
      failWallet(event);
    } finally {
      busy = false;
      if (!leaving && button) button.disabled = false;
    }
  });

  const setPaying = (on) => {
    root.classList.toggle("is-paying", on);
    if (!button) return;
    button.disabled = on;
    button.classList.toggle("is-loading", on);
    button.textContent = on ? "Probíhá platba…" : "Zaplatit kartou";
  };

  button?.addEventListener("click", async () => {
    if (busy) return;
    busy = true;
    setPaying(true);
    showError("");
    showStatus("Probíhá platba. Na nic už neklikej.");
    try {
      const paid = await completePayment();
      if (!paid && !leaving) showStatus("");
    } catch (error) {
      showStatus("");
      showError(error instanceof Error ? error.message : "Platbu se nepodařilo dokončit.");
    } finally {
      busy = false;
      if (!leaving) setPaying(false);
    }
  });
})();
