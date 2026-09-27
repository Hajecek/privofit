<div class="pay-modal" data-pay-modal hidden>
    <div class="pay-modal-backdrop" data-pay-close></div>
    <section class="pay-modal-panel" role="dialog" aria-modal="true" aria-labelledby="pay-modal-title">
        <button type="button" class="pay-modal-x" data-pay-close aria-label="Zavřít">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="pay-modal-top">
            <div class="pay-plates">
                <div class="pay-plate" data-pay-plate><span data-pay-hero-mark></span></div>
                <div class="pay-plate is-sub" data-pay-brand-plate hidden><span data-pay-brand-mark></span></div>
            </div>
            <h2 id="pay-modal-title" data-pay-title>Platba</h2>
            <p class="pay-modal-card" data-pay-card hidden></p>
            <div class="pay-pills">
                <span class="pay-pill" data-pay-status hidden></span>
                <span class="pay-pill is-test" data-pay-test hidden>Testovací platba</span>
            </div>
        </div>
        <div class="pay-money" data-pay-money hidden>
            <div class="pay-money-card" data-pay-charged-card hidden>
                <span>Zákazník zaplatil</span>
                <strong data-pay-charged></strong>
            </div>
            <div class="pay-money-card is-net" data-pay-net-card hidden>
                <span>Čistě nám</span>
                <strong data-pay-net></strong>
            </div>
        </div>
        <dl class="pay-sheet" data-pay-rows hidden></dl>
        <div class="pay-modal-foot">
            <a class="btn pay-modal-receipt" data-pay-receipt hidden target="_blank" rel="noopener">Otevřít účtenku</a>
        </div>
    </section>
</div>
