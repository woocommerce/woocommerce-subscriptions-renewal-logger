# WooCommerce Subscriptions Renewal Logger

Determine why automatic subscription renewal payments aren't being processed despite using an automatic gateway.

To do this, the plugin logs important data around renewal events to WooCommerce log file prefixed with `'wcs-renewal-log-'`.

To view the log file:

1. Go to **WooCommerce > System Status > Logs** (i.e. `/wp-admin/admin.php?page=wc-status&tab=logs`)
1. Select the log file with the `'wcs-renewal-log-'` prefix

### Requirements

Requires WooCommmerce 3.0 or newer and Subscriptions 2.3.0 or newer.

### Caveat

This plugin is a diagnostic tool that should be used only for active monitoring on a site. Do you leave it active indefinitely on a site, especially not a live site or a site with a large number of subscriptions.

For best results, enable the plugin for 5-10 minutes at a time. Then disbale it and review the logs.
