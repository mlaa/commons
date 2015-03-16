<?php

/*
  Override e-mail FROM address globally across network.
  ---
  Use GLOBAL_SMTP_FROM constant if set; otherwise use the multisite admin's
  e-mail address.
*/

add_filter('wp_mail_from', 'override_mail_from_address_globally');

function override_mail_from_address_globally ($old_from_address) {
  return ( defined('GLOBAL_SMTP_FROM') ) ? GLOBAL_SMTP_FROM : get_site_option('admin_email', 'wordpress@localhost', true);
}


// Make WordPress set the MAIL FROM envelope header. This is useful so that the
// the local MTA will use the supplied FROM address when relaying the message.
// http://www.slashslash.de/2013/04/properly-sending-mail-via-eximsendmail-from-wordpress-and-others/

add_action('phpmailer_init', 'mail_add_sender');

function mail_add_sender(&$phpmailer) {
  $phpmailer->Sender = $phpmailer->From;
}

?>
