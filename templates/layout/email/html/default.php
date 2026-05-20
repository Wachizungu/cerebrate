<?php
/**
 * @var \App\View\AppView $this
 */
$title = $this->fetch('title') ?: 'Cerebrate';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($title) ?></title>
</head>
<body style="margin:0;padding:0;font-family:Helvetica,Arial,sans-serif;color:#222;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="max-width:640px;margin:0 auto;">
        <tr>
            <td style="padding:16px 20px;border-bottom:1px solid #e5e5e5;font-size:14px;font-weight:bold;color:#333;">
                Cerebrate
            </td>
        </tr>
        <tr>
            <td style="padding:20px;font-size:14px;line-height:1.5;">
                <?= $this->fetch('content') ?>
            </td>
        </tr>
    </table>
</body>
</html>
