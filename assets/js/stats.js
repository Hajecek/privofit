(() => {
  const Charts = window.PrivofitCharts;
  if (!Charts) return;

  const mount = (root, extra) => {
    if (!root) return;
    const payload = Charts.parsePayload(root);
    const canvas = root.querySelector("[data-stats-line]");
    if (!payload || !canvas) return;
    const tip = root.querySelector("[data-chart-tip]");
    const dayUrl = root.getAttribute("data-day-url") || "";
    Charts.mountSeriesChart(root, canvas, payload.days || [], {
      segments: payload.segments || [],
      tipEl: tip,
      format: extra.format,
      subject: extra.subject,
      kind: extra.kind,
      storageKey: extra.storageKey,
      onSelect: (day) => {
        if (!day || !day.date || !dayUrl) return;
        window.location.href = dayUrl + encodeURIComponent(day.date);
      },
    });
  };

  mount(document.querySelector("[data-stats-visits]"), {
    format: "count",
    subject: "návštěvnosti",
    kind: "bar",
    storageKey: "privofit.chartKind.visits",
  });
  mount(document.querySelector("[data-stats-money]"), {
    format: "money",
    subject: "tržeb",
    kind: "line",
    storageKey: "privofit.chartKind",
  });

  const moneyRoot = document.querySelector("[data-stats-money]");
  const donut = document.querySelector("[data-stats-donut]");
  if (moneyRoot && donut) {
    const payload = Charts.parsePayload(moneyRoot) || {};
    const paint = () => Charts.drawDonut(donut, payload.breakdown || {}, payload.segments || []);
    paint();
    window.addEventListener("resize", paint);
  }
})();
