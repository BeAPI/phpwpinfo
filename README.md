<img src="https://github.com/BeAPI/phpwpinfo/raw/master/.github/phpwpinfo.png" width="200">

# phpwpinfo

phpwpinfo provides an equivalent to the `phpinfo()` function but with more WordPress requirements [details](#what-) about the PHP/MySQL/Apache environment and also offers suggestions for improvement.
 
This tool allows you to quickly test environment server where you want to install WordPress.
It is written in full PHP and use Bootstrap for HTML/CSS/JS purpose, provided by [CDN](http://www.bootstrapcdn.com).

# How ?

## Installation

> **Note**
> 
> You'll need the .htaccess file included for the PHP-FPM/FastCGI implementations

### Total

Just run :

```git clone https://github.com/BeAPI/phpwpinfo.git```

### Partial

Talking about the main file ([phpwpinfo.php](https://github.com/BeAPI/phpwpinfo/blob/master/phpwpinfo.php)), copy it's content, download the [raw](https://raw.githubusercontent.com/BeAPI/phpwpinfo/master/phpwpinfo.php) file or even better "wget" it directly on your server :

```wget https://raw.githubusercontent.com/BeAPI/phpwpinfo/master/phpwpinfo.php```

## Usage

Then simply reach the my-site.com/phpwpinfo.php url on your site to get the results, which are protected with the following credentials :
* Login : wordpress
* Password : wordpress

# What ? 

## 1. Self deletion

This tool can, if your server allow it, delete itself.

## 2. What it checks for

It tests various elements such as :
* PHP & MySQL Version
* Apache modules
* PHP Extensions
* PHP Configuration
* MySQL Configuration
* Mail server feature

## 3. Display phpinfo and more

It also allows you to quickly view `phpinfo()` and MySQL variables.

## 4. Quick install

Finally, it allows (if you server allows it) to quickly install :
* [Adminer](http://www.adminer.org/en/)
* [Latest version of WordPress (US)](http://wordpress.org/)

## Contributing

Please refer to the [contributing guidelines](.github/CONTRIBUTING.md) to increase the chance of your pull request to be merged and/or receive the best support for your issue.

### Issues & features request / proposal

If you identify any errors or have an idea for improving this tool, feel free to open an [issue](../../issues/new). Please provide as much info as needed in order to help us resolving / approve your request.

# Who ?

<a href="https://beapi.fr">![Be API Github Banner](.github/banner-github.png)</a>

Created by [Be API](https://beapi.fr), the French WordPress leader agency since 2009. Based in Paris, we are more than 30 people and always [hiring](https://beapi.workable.com) some fun and talented guys. So we will be pleased to work with you.

This tool is only maintained, which means we do not guarantee some free support. Consider reporting an [issue](#issues--features-request--proposal) and be patient. 

If you really like what we do or want to thank us for our quick work, feel free to [donate](https://www.paypal.me/BeAPI) as much as you want / can, even 1€ is a great gift for buying cofee :)

## License

phpwpinfo is licensed under the [GPLv3 or later](LICENSE.md).
