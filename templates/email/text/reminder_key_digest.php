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
Hello <?= $greeting ?>,

The following GPG key(s) you have published on this Cerebrate instance
need your attention:

<?php foreach ($items as $item):
    $key = $item['key'] ?? null;
    $id = is_object($key) ? ($key->id ?? '?') : '?';
    $expiry = $item['expiry'] ?? null;
    $label = $expiry instanceof \DateTimeInterface ? $expiry->format('Y-m-d H:i T') : 'unknown';
    $status = !empty($item['expired']) ? 'EXPIRED on  ' : 'expires on  ';
?>
  Key <?= $id ?>: <?= $status . $label ?>

<?php endforeach; ?>
Please rotate or extend the keys listed above before they expire. For any
that have already expired, publish a replacement key and remove the old one
as soon as you can, so that encrypted communications with you can continue
uninterrupted.

If you have already taken care of some of these, you can ignore the
relevant lines above.

-- Cerebrate
</content>
