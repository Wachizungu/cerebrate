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
Hello <?= $greeting ?>,

One of the GPG keys you have published on this Cerebrate instance is
approaching its expiry date.

  Key ID:     <?= is_object($key ?? null) ? ($key->id ?? '?') : '?' ?>

  Expires on: <?= $expiresLabel ?>

Please rotate or extend this key before it expires so that encrypted
communications with you can continue uninterrupted.

If you have already replaced this key on the instance, you can ignore
this message.

-- Cerebrate
