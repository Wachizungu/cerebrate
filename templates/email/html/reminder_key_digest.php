<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Individual $individual
 * @var array<int, array{key: \App\Model\Entity\EncryptionKey, expiry: \DateTimeInterface, expired: bool, threshold: int}> $items
 */
$items = $items ?? [];
$count = count($items);
if ($count === 1) {
    $only = $items[0];
    $onlyExpiry = $only['expiry'] ?? null;
    $onlyDate = $onlyExpiry instanceof \DateTimeInterface ? $onlyExpiry->format('Y-m-d') : 'an upcoming date';
    $subject = !empty($only['expired'])
        ? sprintf('Your GPG key expired on %s', $onlyDate)
        : sprintf('Your GPG key expires on %s', $onlyDate);
} else {
    $subject = sprintf('GPG key reminders (%d keys)', $count);
}
$this->set('subject', $subject);
$individual = $individual ?? null;
$first = is_object($individual) ? ($individual->first_name ?? '') : '';
$last = is_object($individual) ? ($individual->last_name ?? '') : '';
$name = trim($first . ' ' . $last);
$greeting = $name !== '' ? $name : 'there';
?>
<p>Hello <?= h($greeting) ?>,</p>

<p>
    The following GPG key(s) you have published on this Cerebrate instance
    need your attention:
</p>

<table role="presentation" cellpadding="6" cellspacing="0" border="0"
       style="border:1px solid #e5e5e5;border-collapse:collapse;font-size:13px;">
    <tr>
        <th align="left" style="color:#666;border-bottom:1px solid #e5e5e5;">Key&nbsp;ID</th>
        <th align="left" style="color:#666;border-bottom:1px solid #e5e5e5;">Status</th>
        <th align="left" style="color:#666;border-bottom:1px solid #e5e5e5;">Date</th>
    </tr>
<?php foreach ($items as $item):
    $key = $item['key'] ?? null;
    $id = is_object($key) ? ($key->id ?? '?') : '?';
    $expiry = $item['expiry'] ?? null;
    $label = $expiry instanceof \DateTimeInterface ? $expiry->format('Y-m-d H:i T') : 'unknown';
    $expired = !empty($item['expired']);
    $status = $expired ? 'Expired' : 'Expires';
    $statusColor = $expired ? '#b00020' : '#8a6d00';
?>
    <tr>
        <td><?= h($id) ?></td>
        <td style="color:<?= $statusColor ?>;font-weight:bold;"><?= h($status) ?></td>
        <td><?= h($label) ?></td>
    </tr>
<?php endforeach; ?>
</table>

<p>
    Please rotate or extend the keys listed above before they expire. For any
    that have already expired, publish a replacement key and remove the old one
    as soon as you can, so that encrypted communications with you can continue
    uninterrupted.
</p>

<p style="color:#666;font-size:12px;">
    If you have already taken care of some of these, you can ignore the
    relevant rows above.
</p>
</content>
