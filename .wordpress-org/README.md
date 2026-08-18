Directory assets for the wordpress.org plugin page.

Everything in this folder is published to the SVN `assets/` directory by
.github/workflows/deploy.yml on a tag push, and appears publicly on
https://wordpress.org/plugins/auditra/ — treat it as public.

Current set:
  banner-772x250.png    standard banner
  banner-1544x500.png   retina banner; the master. Derive the 772 from this
                        one rather than laying it out separately, so both
                        displays get identical framing.
  icon-128x128.png      standard icon
  icon-256x256.png      retina icon
  screenshot-1..3.png   1544px wide, matching the captions in readme.txt

Screenshot captions live in readme.txt and are read from the readme in the
stable tag, not from trunk — so changing a caption needs a release, while
replacing an image does not.

To update artwork without cutting a release, use
10up/action-wordpress-plugin-asset-update, or commit into SVN assets/ directly.
