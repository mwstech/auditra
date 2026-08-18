Directory assets for the wordpress.org plugin page (banners, icon, screenshots).
Produced by hand, not committed as placeholders.

Required files:
banner-772x250.png, banner-1544x500.png, icon-128x128.png, icon-256x256.png,
screenshot-1.png .. screenshot-3.png (contents described in readme.txt).

STATUS: empty on purpose. The previous set carried the AuditPress wordmark and
was removed before the 1.0.0 release so the deploy could not publish the
rejected name onto the public page. They are recoverable from git history
(the commit that removed them) if any layout is worth reusing.

Anything dropped in here is published to the plugin page by
.github/workflows/deploy.yml on the next tag push. To update artwork WITHOUT
cutting a release, use 10up/action-wordpress-plugin-asset-update instead, or
commit straight into the SVN assets/ directory.
