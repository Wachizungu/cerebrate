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
Hello <?= $greeting ?>,

One of the GPG keys you have published on this Cerebrate instance has
already expired.

  Key ID:      <?= is_object($key ?? null) ? ($key->id ?? '?') : '?' ?>

  Expired on:  <?= $expiredLabel ?>

While this key remains on the instance other users may still try to use
it. Please publish a replacement key and remove the expired one as soon
as you can.

If you have already taken care of this, you can ignore this message.

-- Cerebrate
