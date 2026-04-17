# WooCommerce Subscriptions Renewal Logger

Determine why automatic subscription renewal payments aren't being processed despite using an automatic gateway.

To do this, the plugin logs important data around renewal events to WooCommerce log file prefixed with `'wcs-renewal-log'`.

To view the log file:

1. Go to **WooCommerce > System Status > Logs** (i.e. `/wp-admin/admin.php?page=wc-status&tab=logs`)
1. Select the log file with the `'wcs-renewal-log'` prefix

### Requirements

Requires WordPress 6.0 or newer, PHP 7.4 or newer, and WooCommerce Subscriptions 2.3 or newer.

Compatible with HPOS (High-Performance Order Storage).

### Caveat

This plugin is a diagnostic tool that should be used only for active monitoring on a site. Do not leave it active indefinitely on a site, especially not on a live site or a site with a large number of subscriptions.

For best results, enable the plugin for 5-10 minutes at a time. Then disable it and review the logs.
