# WooCommerce Subscriptions Renewal Logger

Determine why automatic subscription renewal payments aren't being processed despite using an automatic gateway.

To do this, the plugin logs important data around renewal events to WooCommerce log file prefixed with `'wcs-renewal-log-'`.

To view the log file:

1. Go to **WooCommerce > System Status > Logs** (i.e. `/wp-admin/admin.php?page=wc-status&tab=logs`)
1. Select the log file with the `'wcs-renewal-log-'` prefix

### Requirements

Requires WooCommmerce 3.0 or newer and Subscriptions 2.2.0 or newer.