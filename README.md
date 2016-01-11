# rate-limiter-middleware

[![Latest Stable Version](https://poser.pugx.org/aporat/rate-limiter-middleware/version.png)](https://packagist.org/packages/aporat/rate-limiter-middleware)
[![Composer Downloads](https://poser.pugx.org/aporat/rate-limiter-middleware/d/total.png)](https://packagist.org/packages/aporat/rate-limiter-middleware)
[![Build Status](https://travis-ci.org/aporat/rate-limiter-middleware.png?branch=master)](https://travis-ci.org/aporat/rate-limiter-middleware)
[![Code Coverage](https://scrutinizer-ci.com/g/aporat/rate-limiter-middleware/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/aporat/rate-limiter-middleware/?branch=master)
[![License](https://poser.pugx.org/aporat/rate-limiter-middleware/license.svg)](https://packagist.org/packages/aporat/rate-limiter-middleware)

## Installation

It's recommended that you use [Composer](https://getcomposer.org/) to install this package.

```bash
$ composer require aporat/rate-limiter-middleware
```

This will install the package and all required dependencies.

## Usage

Include the default middleware. By default, requests are rate limited per hour, minute and second.

```php
<?php
use RateLimiter\Middleware as RateLimiterMiddleware;

$redis_settings = [
    'host' => '127.0.0.1',
    'port' => '6379'
  ];

$middlware = new RateLimiterMiddleware([], $redis_settings);
$app->add($middlware);
```




