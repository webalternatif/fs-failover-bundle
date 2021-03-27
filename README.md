# WebfFsFailoverBundle

This bundle allows creating failover
[Flysystem](https://github.com/thephpleague/flysystem) adapters and provides
tooling to keep underlying storages synchronized.

:warning: **The development is in very early stage, do not use this in 
production unless you know what you do.**

## Installation

Make sure Composer is installed globally, as explained in the
[installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Applications that use Symfony Flex

Open a command console, enter your project directory and execute:

```console
$ composer require webalternatif/fs-failover-bundle
```

### Applications that don't use Symfony Flex

#### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the following
command to download the latest stable version of this bundle:

```console
$ composer require webalternatif/fs-failover-bundle
```

#### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles in the
`config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Webf\FsFailoverBundle\WebfFsFailoverBundle::class => ['all' => true],
];
```
