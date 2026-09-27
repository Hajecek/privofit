<?php
/** @var array<string, mixed> $payment */
$payment = is_array($payment ?? null) ? $payment : [];
$factsMode = ($factsMode ?? 'columns') === 'stack' ? 'stack' : 'columns';
$facts = json_decode((string) ($payment['stripe_details'] ?? ''), true);
$facts = is_array($facts) ? $facts : [];
$methodNames = [
    'card' => 'Karta',
    'link' => 'Link',
    'apple_pay' => 'Apple Pay',
    'google_pay' => 'Google Pay',
    'sepa_debit' => 'SEPA',
    'bancontact' => 'Bancontact',
    'ideal' => 'iDEAL',
    'klarna' => 'Klarna',
    'paypal' => 'PayPal',
    'revolut_pay' => 'Revolut Pay',
];
$wallet = (string) ($facts['wallet'] ?? '');
$methodKey = $wallet !== '' ? $wallet : (string) ($facts['method'] ?? '');
$methodLabel = $methodNames[$methodKey] ?? ($methodKey !== '' ? $methodKey : '');
$brandKey = strtolower((string) ($facts['brand'] ?? ''));
$brand = $brandKey !== '' ? mb_convert_case($brandKey, MB_CASE_TITLE, 'UTF-8') : '';
$fundingNames = ['credit' => 'kreditní', 'debit' => 'debetní', 'prepaid' => 'předplacená'];
$funding = $fundingNames[(string) ($facts['funding'] ?? '')] ?? '';
$country = strtoupper((string) ($facts['country'] ?? ''));
$cardBits = array_filter([
    $brand,
    ($facts['last4'] ?? '') !== '' ? '•••• ' . $facts['last4'] : '',
    $funding,
    $brand !== '' || ($facts['last4'] ?? '') !== '' ? $country : '',
]);
$statusBits = [];
if (!empty($facts['refunded'])) {
    $statusBits[] = 'Vráceno';
} elseif (!empty($facts['disputed'])) {
    $statusBits[] = 'Reklamace';
} elseif (($facts['charge_status'] ?? '') === 'succeeded' || !empty($facts['paid'])) {
    $statusBits[] = 'Zaplaceno';
} elseif (($facts['charge_status'] ?? '') !== '') {
    $statusBits[] = (string) $facts['charge_status'];
}
if (($facts['balance_status'] ?? '') === 'available') {
    $statusBits[] = 'připsáno';
}
$risk = (string) ($facts['risk_level'] ?? '');
if ($risk === 'elevated') {
    $statusBits[] = 'riziko zvýšené';
} elseif ($risk === 'highest') {
    $statusBits[] = 'riziko vysoké';
}
if (($facts['network_status'] ?? '') === 'approved_by_network') {
    $statusBits[] = 'schváleno sítí';
}
$providerLabel = (string) ($payment['provider'] ?? '—');
if ($providerLabel === '' && !empty($payment['membership_id'])) {
    $providerLabel = 'členství';
}
$providerHtml = '<div class="pay-fact"><strong>' . e($providerLabel !== '' ? $providerLabel : '—') . '</strong>';
if ($facts !== [] && empty($facts['missing']) && empty($facts['livemode'])) {
    $providerHtml .= ' <span class="badge badge-muted">Test</span>';
}
$providerHtml .= '</div>';
$methodHtml = '<div class="pay-fact">';
if ($methodLabel !== '') {
    $methodHtml .= '<strong>' . e($methodLabel) . '</strong>';
} elseif ($factsMode === 'columns') {
    $methodHtml .= '<span class="muted">—</span>';
}
if ($cardBits !== []) {
    $methodHtml .= '<div class="muted">' . e(implode(' · ', $cardBits)) . '</div>';
}
$methodHtml .= '</div>';
if ($factsMode !== 'columns' && $methodLabel === '' && $cardBits === []) {
    $methodHtml = '';
}
$statusHtml = '<div class="pay-fact">';
if (!empty($facts['missing'])) {
    $statusHtml .= '<span class="muted">Ve Stripe už není</span>';
} elseif ($statusBits !== []) {
    $statusHtml .= '<div>' . e(implode(' · ', $statusBits)) . '</div>';
} elseif ($factsMode === 'columns' || (string) ($payment['status'] ?? '') !== '') {
    $statusHtml .= '<span class="muted">' . e((string) ($payment['status'] ?? '—')) . '</span>';
}
if ((float) ($facts['fee'] ?? 0) > 0) {
    $statusHtml .= '<div class="muted">za platbu kartou ' . e(number_format((float) $facts['fee'], 2, ',', ' ')) . ' Kč</div>';
}
if ((float) ($facts['net'] ?? 0) > 0) {
    $statusHtml .= '<div class="muted">čistě ' . e(number_format((float) $facts['net'], 2, ',', ' ')) . ' Kč</div>';
}
if ((float) ($facts['amount_refunded'] ?? 0) > 0) {
    $statusHtml .= '<div class="muted">vráceno ' . e(number_format((float) $facts['amount_refunded'], 2, ',', ' ')) . ' Kč</div>';
}
if (($facts['receipt_url'] ?? '') !== '') {
    $statusHtml .= '<a href="' . e((string) $facts['receipt_url']) . '" target="_blank" rel="noopener">Účtenka</a>';
}
$statusHtml .= '</div>';
$summary = $methodLabel !== '' ? $methodLabel : ($providerLabel !== '' ? $providerLabel : 'Platba');
if ($wallet === '' && $brand !== '' && ($facts['method'] ?? '') === 'card') {
    $summary = $brand;
}
$summaryMeta = ($facts['last4'] ?? '') !== ''
    ? trim(($wallet !== '' ? $brand . ' ' : '') . '•••• ' . $facts['last4'])
    : '';
$mark = $wallet !== '' ? $wallet : ($brandKey !== '' ? $brandKey : ($methodKey !== '' ? $methodKey : 'card'));
$alert = '';
$pill = '';
$tone = 'muted';
if (!empty($facts['missing'])) {
    $alert = 'Není ve Stripe';
    $pill = 'Ve Stripe už není';
    $tone = 'bad';
} elseif (!empty($facts['refunded'])) {
    $alert = 'Vráceno';
    $pill = 'Vráceno';
    $tone = 'bad';
} elseif (!empty($facts['disputed'])) {
    $alert = 'Reklamace';
    $pill = 'Reklamace';
    $tone = 'bad';
} elseif (($facts['charge_status'] ?? '') === 'succeeded' || !empty($facts['paid'])) {
    $pill = 'Zaplaceno';
    $tone = 'ok';
} elseif (($facts['charge_status'] ?? '') !== '') {
    $pill = (string) $facts['charge_status'];
    $tone = 'warn';
} elseif ((string) ($payment['status'] ?? '') !== '') {
    $pill = (string) $payment['status'];
}
$money = static function (mixed $amount): string {
    return (float) $amount > 0 ? number_format((float) $amount, 2, ',', ' ') . ' Kč' : '';
};
$detailRows = [];
$addDetail = static function (string $label, string $value) use (&$detailRows): void {
    $value = trim($value);
    if ($value !== '') {
        $detailRows[] = ['label' => $label, 'value' => $value];
    }
};
$addDetail('Poskytovatel', $providerLabel);
if ($country !== '' && $brand === '' && ($facts['last4'] ?? '') === '') {
    $addDetail('Země', $country);
}
$progress = array_values(array_filter(
    $statusBits,
    static fn (string $bit): bool => $bit !== $pill && $bit !== 'Zaplaceno'
));
$addDetail('Průběh', implode(' · ', $progress));
$addDetail('Za platbu kartou', $money($facts['fee'] ?? 0));
$addDetail('Vráceno', $money($facts['amount_refunded'] ?? 0));
$receipt = (string) ($facts['receipt_url'] ?? '');
if (!str_starts_with($receipt, 'https://')) {
    $receipt = '';
}
$payload = json_encode([
    'title' => $summary,
    'mark' => $mark,
    'brand' => $wallet !== '' ? $brandKey : '',
    'card' => implode(' · ', array_filter($cardBits)),
    'status' => $pill,
    'tone' => $tone,
    'test' => $facts !== [] && empty($facts['missing']) && empty($facts['livemode']),
    'charged' => $money($facts['charged'] ?? 0),
    'net' => $money($facts['net'] ?? 0),
    'rows' => $detailRows,
    'receipt' => $receipt,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<?php if ($factsMode === 'columns'): ?>
<td class="pay-facts"><?= $providerHtml ?></td>
<td class="pay-facts"><?= $methodHtml ?></td>
<td class="pay-facts"><?= $statusHtml ?></td>
<?php else: ?>
<button type="button" class="pay-chip" data-pay-open data-pay-detail="<?= e((string) $payload) ?>">
    <span class="pay-chip-mark" data-pay-mark="<?= e($mark) ?>"></span>
    <span class="pay-chip-main"><?= e($summary) ?></span>
    <?php if ($summaryMeta !== ''): ?>
        <span class="pay-chip-meta"><?= e($summaryMeta) ?></span>
    <?php endif; ?>
    <?php if ($alert !== ''): ?>
        <span class="badge badge-bad"><?= e($alert) ?></span>
    <?php endif; ?>
</button>
<?php endif; ?>
