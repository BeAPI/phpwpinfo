phpwpinfo
=========

phpwpinfo provides an equivalent to the phpinfo() function that reports WordPress Requirements information about the PHP/MySQL/Apache environment, and offers suggestions for improvement. 

This tool allows you to quickly test environment server where you want to install WordPress.
The default credentials for display the result are :

* Login : wordpress
* Password : wordpress

This script can, if your server allow it, delete itself.

It tests various elements such as :
	
* PHP & MySQL Version
* Apache modules
* PHP Extensions
* PHP Configuration
* MySQL Configuration
* Mail server feature

It also allows you to quickly view phpinfo () and MySQL variables.

Finally, it allows (if you server allows it) to quickly install :

* [Adminer](http://www.adminer.org/en/)
* [PHPsecinfo](http://phpsec.org/projects/phpsecinfo/)
* [Latest version of WordPress (US)](http://wordpress.org/)

This script is writted in full PHP and use Bootstrap for HTML/CSS/JS provided by CDN (http://www.bootstrapcdn.com/)

License: GPL v2

### Changelog:

* Version 1.1
    * Implement recommended/required for each config/module
    * Improve test config value
* Version 1.0
    * Initial release