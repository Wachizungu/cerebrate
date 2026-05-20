<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Individual $individual
 * @var \App\Model\Entity\EncryptionKey $key
 * @var \DateTimeInterface $expiresAt
 */
$expiresAt = $expiresAt ?? null;
$expiresLabel = $expiresAt instanceof \DateTimeInterface ? $expiresAt->format('Y-m-d H:i T') : 'unknown';
$expiresDate = $expiresAt instanceof \DateTimeInterface ? $expiresAt->format('Y-m-d') : 'an upcoming date';
$this->set('subject', sprintf('Your GPG key expires on %s', $expiresDate));
$individual = $individual ?? null;
$first = is_object($individual) ? ($individual->first_name ?? '') : '';
$last = is_object($individual) ? ($individual->last_name ?? '') : '';
$name = trim($first . ' ' . $last);
$greeting = $name !== '' ? $name : 'there';
?>
<p>Hello <?= h($greeting) ?>,</p>

<p>
    One of the GPG keys you have published on this Cerebrate instance is
    approaching its expiry date.
</p>

<table role="presentation" cellpadding="6" cellspacing="0" border="0"
       style="border:1px solid #e5e5e5;border-collapse:collapse;font-size:13px;">
    <tr>
        <td style="color:#666;">Key&nbsp;ID</td>
        <td><?= h(is_object($key ?? null) ? ($key->id ?? '?') : '?') ?></td>
    </tr>
    <tr>
        <td style="color:#666;">Expires&nbsp;on</td>
        <td><?= h($expiresLabel) ?></td>
    </tr>
</table>

<p>
    Please rotate or extend this key before it expires so that encrypted
    communications with you can continue uninterrupted.
</p>

<p style="color:#666;font-size:12px;">
    If you have already replaced this key on the instance, you can ignore
    this message.
</p>
