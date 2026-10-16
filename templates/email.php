<?php
/**
 * Șablonul HTML al emailurilor.
 *
 * @var string $subject
 * @var string $body_html
 * @var string $rows_html
 * @var string $button
 * @var string $studio
 * @var string $footer
 */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f3efe7;font-family:'Source Sans Pro',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3efe7;padding:32px 12px;">
	<tr>
		<td align="center">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden;">
				<tr>
					<td style="background:#1f3a17;padding:22px 32px;">
						<div style="font-family:'Fredoka One','Trebuchet MS',Arial,sans-serif;font-size:20px;letter-spacing:.06em;color:#ffffff;text-transform:uppercase;"><?php echo $studio; // phpcs:ignore -- deja escaped ?></div>
						<div style="margin-top:4px;font-size:13px;color:#cfab62;letter-spacing:.08em;text-transform:uppercase;">Ședințe foto de Crăciun</div>
					</td>
				</tr>
				<tr>
					<td style="padding:32px 32px 8px;">
						<?php echo $body_html; // phpcs:ignore -- construit cu esc_html ?>
					</td>
				</tr>
				<tr>
					<td style="padding:0 32px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf7f1;border:1px solid #ece4d4;border-radius:8px;">
							<tr><td style="padding:14px 20px;">
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><?php echo $rows_html; // phpcs:ignore ?></table>
							</td></tr>
						</table>
					</td>
				</tr>
				<tr>
					<td style="padding:24px 32px 8px;">
						<?php echo $button; // phpcs:ignore ?>
					</td>
				</tr>
				<tr>
					<td style="padding:18px 32px 26px;border-top:1px solid #f0ebe1;font-size:12px;line-height:1.5;color:#9a917f;">
						<?php echo $footer; // phpcs:ignore ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
