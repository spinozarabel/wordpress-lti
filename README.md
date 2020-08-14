# wordpress-lti

This *LTI Connector for WordPress* is a WordPress plugin which allows the multisite versions of WordPress to act as an IMS LTI tool provider so that its blogs can be linked directly from a course in a VLE. LTI support can be found in most VLEs thereby enabling WordPress to be integrated with ease.

Visit the wiki for [documentation](https://github.com/celtic-project/wordpress-lti/wiki).  A zip file of the latest release of this connector can be found on the [OSCELOT project page](https://github.com/OSCELOT/wordpress-lti).

## Authors

* Simon Booth, University of Stirling, UK
* Stephen P Vickers

## Licence

[Creative Commons GNU General Public Licence Version 3](LICENSE)

## Modifications

* Blog creation is removed. This is a means for SSO from Moodle to Wordpress multi-site.
* User is directed to an existing blog which matches the calling activity name
* Moodle API API is used to get user data from Moodle instance
* Cashfree API is used to get Virtual Account information from payment vendor
* Data is shuffled around from Moodle and cashfree to user's wordpress user meta
for use in payments using WooCommerce and other custom plugins
