const APP_BASE = document.documentElement.dataset.base || "/";
const page = document.body.dataset.page || "";
const path =
  "/" +
  location.pathname
    .slice(APP_BASE.length)
    .replace(/index\.html$/, "")
    .replace(/\/+$/, "");
const isRegister = page === "register" || path === "/registrace";
const isLogin = page === "login" || path === "/prihlaseni";
const isMfa = document.body.dataset.mfa === "1";
const year = document.getElementById("year");
if (year) year.textContent = new Date().getFullYear();
const menu = document.querySelector(".menu-toggle");
const nav = document.querySelector(".header nav");
if (menu && nav) {
  menu.addEventListener("click", () => {
    const open = menu.getAttribute("aria-expanded") !== "true";
    menu.setAttribute("aria-expanded", String(open));
    menu.setAttribute("aria-label", open ? "Zavřít menu" : "Otevřít menu");
    nav.classList.toggle("open", open);
  });
  nav.querySelectorAll("a").forEach((a) =>
    a.addEventListener("click", () => {
      nav.classList.remove("open");
      menu.setAttribute("aria-expanded", "false");
    }),
  );
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      nav.classList.remove("open");
      menu.setAttribute("aria-expanded", "false");
    }
  });
}
if (isRegister) {
  document.body.classList.add("standalone-registration");
}
if (isLogin) {
  document.body.classList.add("standalone-login");
  if (!isMfa) {
    const eyebrow = document.querySelector(".auth-story .eyebrow");
    const heading = document.querySelector(".auth-story h1");
    const copy = document.getElementById("auth-story-copy");
    const caption = document.querySelector(".auth-card-caption");
    const model = document.getElementById("auth-3d");
    if (eyebrow) eyebrow.textContent = "TVŮJ PROSTOR NA TEBE ČEKÁ";
    if (heading) heading.innerHTML = "Tvůj klíč.<br><span>Tvůj prostor.</span>";
    if (copy) copy.textContent = "Vítej zpátky. Odemkni si čas jen pro sebe.";
    if (caption) caption.textContent = "Tvůj přístup k vlastnímu tempu. Tažením otoč klíč.";
    if (model) {
      model.setAttribute("role", "button");
      model.setAttribute(
        "aria-label",
        "3D členský klíč PRIVOFIT. Tažením nebo šipkami ho otočíš. Kliknutím ho obrátíš.",
      );
    }
  }
}
if (isLogin || isRegister) {
  const homeEl = document.getElementById("home");
  const authEl = document.getElementById("auth");
  if (homeEl) homeEl.hidden = true;
  if (authEl) authEl.hidden = false;
  document.title = (isRegister ? "Registrace" : "Přihlášení") + " | PRIVOFIT";
  const activeTab = document.getElementById(isRegister ? "register-tab" : "login-tab");
  if (activeTab) {
    activeTab.classList.add("active");
    activeTab.setAttribute("aria-current", "page");
  }
  const form = document.getElementById("auth-form");
  if (!form) {
    // stránka bez auth formuláře
  } else {
  const identity = document.getElementById("identity-step");
  const security = document.getElementById("security-step");
  const avatarStep = document.getElementById("avatar-step");
  const review = document.getElementById("review-step");
  const fields = [identity, security, avatarStep, review].filter(Boolean);
  const lastStep = fields.length - 1;
  const password = document.getElementById("password");
  const confirm = document.getElementById("confirm-password");
  const submit = document.getElementById("submit-button");
  const back = document.getElementById("step-back");
  const status = document.getElementById("form-status");
  let step = 0;
  if (isLogin && !isMfa) {
    const identifier = form.querySelector('[name="email"], [name="identifier"]');
    if (identifier) {
      identifier.type = "text";
      identifier.name = "identifier";
      identifier.autocomplete = "username";
      identifier.placeholder = "E-mail nebo uživatelské jméno";
      identifier.spellcheck = false;
      identifier.setAttribute("autocapitalize", "none");
    }
    const identifierLabel = document.getElementById("login-identifier-label");
    if (identifierLabel) identifierLabel.textContent = "E-mail nebo uživatelské jméno";
    const dividerSpan = document.querySelector("#auth-divider span");
    if (dividerSpan) dividerSpan.textContent = "nebo pomocí účtu";
    password.removeAttribute("minlength");
  }
  document.querySelectorAll("[data-provider]").forEach((button) =>
    button.addEventListener("click", (event) => {
      if (button.tagName === "A") return;
      event.preventDefault();
      const message = document.getElementById("social-status");
      if (!message) return;
      message.hidden = false;
      message.textContent =
        "Připojení k účtu " +
        button.dataset.provider +
        " zatím není aktivní. Toto je ukázka rozhraní; žádné údaje se neodesílají.";
    }),
  );
  document.getElementById("registration-progress").hidden = !isRegister;
  document.getElementById("confirm-field").hidden = !isRegister;
  document.getElementById("password-hint").hidden = !isRegister;
  password.autocomplete = isRegister ? "new-password" : "current-password";
  const authBottom = document.getElementById("auth-bottom");
  if (authBottom) {
    if (isMfa) {
      authBottom.hidden = true;
    } else {
      authBottom.hidden = false;
      authBottom.innerHTML = isRegister
        ? 'Už máš svůj účet? <a href="' + APP_BASE + 'prihlaseni">Přihlas se</a>'
        : 'Ještě nemáš účet? <a href="' + APP_BASE + 'registrace">Začni tady</a>';
    }
  }
  const input = (name) => form.querySelector('[name="' + name + '"]');
  const avatarInput = form.querySelector("[data-avatar-input]");
  const avatarPreview = form.querySelector("[data-avatar-preview]");
  const avatarPreviewImg = form.querySelector("[data-avatar-preview-img]");
  const avatarInitials = form.querySelector("[data-avatar-initials]");
  const avatarClear = form.querySelector("[data-avatar-clear]");
  const avatarError = form.querySelector("[data-avatar-error]");
  const avatarFace = form.querySelector(".avatar-picker-face");
  let avatarObjectUrl = "";
  const initialsFromName = () =>
    ((input("first_name")?.value[0] || "") + (input("last_name")?.value[0] || "")).toUpperCase() || "P";
  const isAllowedAvatar = (file) => {
    const type = (file.type || "").toLowerCase();
    if (type === "image/heic" || type === "image/heif") return false;
    if (/^image\/(jpeg|jpg|pjpeg|png|webp)$/.test(type)) return true;
    return type === "" && /\.(jpe?g|png|webp)$/i.test(file.name || "");
  };
  const showAvatarError = (message) => {
    if (!avatarError) return;
    avatarError.hidden = !message;
    avatarError.textContent = message || "";
  };
  const clearAvatarPreview = () => {
    if (avatarObjectUrl.startsWith("blob:")) URL.revokeObjectURL(avatarObjectUrl);
    avatarObjectUrl = "";
    if (avatarPreviewImg) {
      avatarPreviewImg.removeAttribute("src");
      avatarPreviewImg.hidden = true;
    }
    if (avatarPreview) avatarPreview.style.backgroundImage = "";
    if (avatarInitials) {
      avatarInitials.hidden = false;
      avatarInitials.textContent = initialsFromName();
    }
    if (avatarClear) avatarClear.hidden = true;
    avatarFace?.classList.remove("has-photo");
  };
  const setAvatarPreview = (file) => {
    if (!file || !avatarPreviewImg) {
      clearAvatarPreview();
      return;
    }
    if (avatarObjectUrl.startsWith("blob:")) URL.revokeObjectURL(avatarObjectUrl);
    avatarObjectUrl = URL.createObjectURL(file);
    avatarPreviewImg.src = avatarObjectUrl;
    avatarPreviewImg.hidden = false;
    if (avatarPreview) avatarPreview.style.backgroundImage = "url('" + avatarObjectUrl + "')";
    if (avatarInitials) avatarInitials.hidden = true;
    if (avatarClear) avatarClear.hidden = false;
    avatarFace?.classList.add("has-photo");
    const reader = new FileReader();
    reader.onload = () => {
      const dataUrl = String(reader.result || "");
      if (!dataUrl) return;
      avatarPreviewImg.src = dataUrl;
      if (avatarPreview) avatarPreview.style.backgroundImage = "url('" + dataUrl + "')";
    };
    reader.readAsDataURL(file);
  };
  const applyAvatarFile = (file) => {
    showAvatarError("");
    if (!file || !avatarInput) return;
    if (!isAllowedAvatar(file)) {
      showAvatarError("Povolené formáty jsou JPEG, PNG a WebP.");
      avatarInput.value = "";
      clearAvatarPreview();
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      showAvatarError("Obrázek je větší než 5 MB.");
      avatarInput.value = "";
      clearAvatarPreview();
      return;
    }
    if (file !== avatarInput.files?.[0]) {
      const data = new DataTransfer();
      data.items.add(file);
      avatarInput.files = data.files;
    }
    setAvatarPreview(file);
  };
  avatarInput?.addEventListener("change", () => applyAvatarFile(avatarInput.files?.[0] || null));
  avatarClear?.addEventListener("click", () => {
    if (avatarInput) avatarInput.value = "";
    showAvatarError("");
    setAvatarPreview(null);
  });
  ["dragenter", "dragover"].forEach((type) =>
    avatarFace?.addEventListener(type, (event) => {
      event.preventDefault();
      avatarFace.classList.add("is-drop");
    }),
  );
  ["dragleave", "drop"].forEach((type) =>
    avatarFace?.addEventListener(type, (event) => {
      event.preventDefault();
      avatarFace.classList.remove("is-drop");
    }),
  );
  avatarFace?.addEventListener("drop", (event) => {
    const file = event.dataTransfer?.files?.[0];
    if (file) applyAvatarFile(file);
  });
  const syncReviewAvatar = () => {
    const reviewAvatar = document.getElementById("review-avatar");
    if (!reviewAvatar) return;
    const initials = initialsFromName();
    const reviewImg = reviewAvatar.querySelector("img");
    const reviewInitials = reviewAvatar.querySelector("[data-review-initials]");
    const hasPhoto = Boolean(avatarPreviewImg && !avatarPreviewImg.hidden && avatarPreviewImg.src);
    reviewAvatar.classList.toggle("has-photo", hasPhoto);
    if (hasPhoto && reviewImg) {
      reviewImg.src = avatarPreviewImg.src;
      reviewImg.hidden = false;
      if (reviewInitials) reviewInitials.hidden = true;
    } else {
      if (reviewImg) reviewImg.hidden = true;
      if (reviewInitials) {
        reviewInitials.hidden = false;
        reviewInitials.textContent = initials;
      } else {
        reviewAvatar.textContent = initials;
      }
    }
  };
  const syncCard = () =>
    window.dispatchEvent(
      new CustomEvent("privofit-registration", {
        detail: {
          step,
          name: (input("first_name")?.value || "").trim(),
          complete: false,
        },
      }),
    );
  function showStep(next, focus = false) {
    step = next;
    const socialAuth = document.getElementById("social-auth");
    const authDivider = document.getElementById("auth-divider");
    const socialStatus = document.getElementById("social-status");
    if (socialAuth) socialAuth.hidden = isRegister && step !== 0;
    if (authDivider) authDivider.hidden = isRegister && step !== 0;
    if (socialStatus) socialStatus.hidden = true;
    fields.forEach((field, i) => {
      field.hidden = isRegister ? i !== step : i !== 1;
      field.disabled = field.hidden;
    });
    confirm.disabled = !isRegister;
    const titles = [
      "Začni u sebe.",
      "Tvůj účet. Tvůj klíč.",
      "Ukaž se.",
      "Všechno připravené.",
    ];
    const subtitles = [
      "Nejdřív se trochu poznáme.",
      "Vytvoř si přístup do svého prostoru.",
      "Přidej profilovou fotku, nebo to nech na později.",
      "Ještě rychlá kontrola a můžeš pokračovat.",
    ];
    document.getElementById("auth-title").textContent = isRegister
      ? titles[step]
      : isMfa
        ? "Ověření přihlášení"
        : "Pojďme na to.";
    document.getElementById("auth-subtitle").textContent = isRegister
      ? subtitles[step]
      : isMfa
        ? "Zadej kód z autentizační aplikace."
        : "Přihlas se do svého prostoru.";
    document.getElementById("step-count").textContent =
      "KROK 0" + (step + 1) + " / 0" + (lastStep + 1);
    document.getElementById("step-name").textContent = [
      "O tobě",
      "Přístup",
      "Fotka",
      "Kontrola",
    ][step];
    document
      .querySelectorAll(".step-bars i")
      .forEach((bar, i) => bar.classList.toggle("active", i <= step));
    back.hidden = !isRegister || step === 0;
    submit.disabled = false;
    submit.innerHTML =
      (isRegister
        ? step < lastStep
          ? "Pokračovat"
          : "Vytvořit účet"
        : isMfa
          ? "Ověřit"
          : "Přihlásit se") + " <span>↗</span>";
    const demoNote = form.querySelector(".demo-note");
    if (demoNote) demoNote.hidden = isRegister && fields[step] !== security;
    if (status && !status.dataset.keep) status.hidden = true;
    if (isRegister && fields[step] === avatarStep && avatarInitials) {
      if (!avatarPreviewImg || avatarPreviewImg.hidden) {
        avatarInitials.textContent = initialsFromName();
      }
    }
    if (isRegister && step === lastStep) {
      const name =
        input("first_name").value.trim() + " " + input("last_name").value.trim();
      document.getElementById("review-name").textContent = name;
      document.getElementById("review-username").textContent =
        "@" + input("username").value.trim();
      document.getElementById("review-email").textContent =
        input("email").value;
      syncReviewAvatar();
    }
    syncCard();
    if (focus)
      document.getElementById("auth-title").focus({ preventScroll: true });
  }
  function validateCurrent() {
    const active = isRegister ? fields[step] : security;
    if (isLogin) {
      const identifierInput = input("identifier");
      if (identifierInput) identifierInput.value = identifierInput.value.trim();
    }
    if (isRegister && step === 0)
      active
        .querySelectorAll("input")
        .forEach((el) => (el.value = el.value.trim()));
    confirm.setCustomValidity(
      isRegister && step === 1 && password.value !== confirm.value
        ? "Hesla se neshodují."
        : "",
    );
    return Array.from(active.querySelectorAll("input")).every(
      (el) => el.disabled || el.reportValidity(),
    );
  }
  back.addEventListener("click", () => showStep(Math.max(0, step - 1), true));
  password.addEventListener("input", () => confirm.setCustomValidity(""));
  confirm.addEventListener("input", () => confirm.setCustomValidity(""));
  input("first_name")?.addEventListener("input", () => {
    if (avatarInitials && (!avatarPreviewImg || avatarPreviewImg.hidden)) {
      avatarInitials.textContent = initialsFromName();
    }
    syncCard();
  });
  input("last_name")?.addEventListener("input", () => {
    if (avatarInitials && (!avatarPreviewImg || avatarPreviewImg.hidden)) {
      avatarInitials.textContent = initialsFromName();
    }
  });
  document.getElementById("show-password")?.addEventListener("click", (e) => {
    const reveal = password.type === "password";
    password.type = reveal ? "text" : "password";
    e.currentTarget.textContent = reveal ? "Skrýt" : "Zobrazit";
    e.currentTarget.setAttribute(
      "aria-label",
      reveal ? "Skrýt heslo" : "Zobrazit heslo",
    );
  });
  const totpInput = form.querySelector('[name="totp"]');
  if (totpInput) {
    totpInput.addEventListener("input", () => {
      const digits = totpInput.value.replace(/\D/g, "").slice(0, 6);
      if (totpInput.value !== digits) totpInput.value = digits;
    });
  }
  form.addEventListener("submit", (e) => {
    if (isLogin && security) security.disabled = false;
    if (totpInput) totpInput.value = totpInput.value.replace(/\D/g, "").slice(0, 6);
    if (!validateCurrent()) {
      e.preventDefault();
      return;
    }
    if (isRegister && step < lastStep) {
      e.preventDefault();
      showStep(step + 1, true);
      return;
    }
    if (isRegister) {
      fields.forEach((field) => {
        field.disabled = false;
      });
    }
  });
  showStep(0);
  }
}

// Progressive motion: content stays readable if animations are unavailable.
const reduceMotion = matchMedia("(prefers-reduced-motion: reduce)");
let motionPaused = reduceMotion.matches;
const motionToggle = document.getElementById("motion-toggle");
function updateMotion() {
  document.body.classList.toggle("motion-paused", motionPaused);
  if (motionToggle) {
    motionToggle.textContent = motionPaused ? "▷" : "Ⅱ";
    motionToggle.setAttribute(
      "aria-label",
      motionPaused ? "Spustit animace" : "Pozastavit animace",
    );
    motionToggle.setAttribute("aria-pressed", String(motionPaused));
  }
  window.dispatchEvent(
    new CustomEvent("privofit-motion", { detail: { paused: motionPaused } }),
  );
}
if (motionToggle)
  motionToggle.addEventListener("click", () => {
    motionPaused = !motionPaused;
    updateMotion();
  });
reduceMotion.addEventListener("change", (e) => {
  motionPaused = e.matches;
  updateMotion();
});
updateMotion();
if ("IntersectionObserver" in window) {
  document.body.classList.add("motion-ready");
  const observer = new IntersectionObserver(
    (entries) =>
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("visible");
          observer.unobserve(entry.target);
        }
      }),
    { threshold: 0.08 },
  );
  document.querySelectorAll(".reveal").forEach((el) => observer.observe(el));
}
let scrollQueued = false;
function scrollPaint() {
  scrollQueued = false;
  const max = document.documentElement.scrollHeight - innerHeight;
  const progress = document.querySelector(".scroll-progress");
  if (progress) {
    progress.style.transform = `scaleX(${max > 0 ? scrollY / max : 0})`;
  }
  const photo = document.querySelector(".space-photo");
  if (photo && !motionPaused && !isLogin && !isRegister) {
    const r = photo.getBoundingClientRect();
    if (r.top < innerHeight && r.bottom > 0)
      photo.style.setProperty(
        "--photo-shift",
        `${Math.max(-35, Math.min(35, (innerHeight / 2 - r.top - r.height / 2) * 0.08))}px`,
      );
  }
}
addEventListener(
  "scroll",
  () => {
    if (!scrollQueued) {
      scrollQueued = true;
      requestAnimationFrame(scrollPaint);
    }
  },
  { passive: true },
);
scrollPaint();
if (matchMedia("(hover: hover) and (pointer: fine)").matches) {
  document.querySelectorAll(".tilt-card").forEach((card) => {
    card.addEventListener("pointermove", (e) => {
      if (motionPaused) return;
      const r = card.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5,
        y = (e.clientY - r.top) / r.height - 0.5;
      card.style.transform = `perspective(850px) rotateX(${-y * 5}deg) rotateY(${x * 5}deg)`;
    });
    card.addEventListener("pointerleave", () => {
      card.style.transform = "";
    });
  });
  document.querySelectorAll(".magnetic").forEach((button) => {
    button.addEventListener("pointermove", (e) => {
      if (motionPaused) return;
      const r = button.getBoundingClientRect();
      button.style.transform = `translate(${(e.clientX - r.left - r.width / 2) * 0.08}px,${(e.clientY - r.top - r.height / 2) * 0.12}px)`;
    });
    button.addEventListener(
      "pointerleave",
      () => (button.style.transform = ""),
    );
  });
}

(() => {
  const hide = (node) => {
    if (!node || node.classList.contains("is-out")) return;
    node.classList.add("is-out");
    window.setTimeout(() => node.remove(), 300);
  };
  document.querySelectorAll("[data-toast]").forEach((toast) => {
    toast.querySelector("[data-toast-close]")?.addEventListener("click", () => hide(toast));
    window.setTimeout(() => hide(toast), 3600);
  });
})();
