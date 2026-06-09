<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Individual $individual
 * @var \App\Model\Entity\EncryptionKey $key
 * @var \DateTimeInterface $expiredAt
 */
$expiredAt = $expiredAt ?? null;
$expiredLabel = $expiredAt instanceof \DateTimeInterface ? $expiredAt->format('Y-m-d H:i T') : 'unknown';
$expiredDate = $expiredAt instanceof \DateTimeInterface ? $expiredAt->format('Y-m-d') : 'a recent date';
$this->set('subject', sprintf('Your GPG key expired on %s', $expiredDate));
$individual = $individual ?? null;
$first = is_object($individual) ? ($individual->first_name ?? '') : '';
$last = is_object($individual) ? ($individual->last_name ?? '') : '';
$name = trim($first . ' ' . $last);
$greeting = $name !== '' ? $name : 'there';
?>
<p>Hello <?= h($greeting) ?>,</p>

<p>
    One of the GPG keys you have published on this Cerebrate instance has
    <strong>already expired</strong>.
</p>

<table role="presentation" cellpadding="6" cellspacing="0" border="0"
       style="border:1px solid #e5e5e5;border-collapse:collapse;font-size:13px;">
    <tr>
        <td style="color:#666;">Key&nbsp;ID</td>
        <td><?= h(is_object($key ?? null) ? ($key->id ?? '?') : '?') ?></td>
    </tr>
    <tr>
        <td style="color:#666;">Expired&nbsp;on</td>
        <td><?= h($expiredLabel) ?></td>
    </tr>
</table>

<p>
    While this key remains on the instance other users may still try to
    use it. Please publish a replacement key and remove the expired one
    as soon as you can.
</p>

<p style="color:#666;font-size:12px;">
    If you have already taken care of this, you can ignore this message.
</p>
