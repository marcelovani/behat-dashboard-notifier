Behat Dashboard Notifier Extension
=========================
This Behat extension integrates with [Behat Notifier](https://github.com/marcelovani/behat-notifier)
to allow sending payload of Behat notifications to a [Automation Dashboard](https://github.com/marcelovani/behat-automation-dashboard).

Installation
------------

Install by adding to your `composer.json`:

```bash
composer require --dev marcelovani/behat-dashboard-notifier
```

Configuration
-------------

Enable the extension in `behat.yml` like this:

The configuration goes in the `Marcelovani\Behat\Notifier` extension, under `notifiers`

```yml
default:
  extensions:
    Marcelovani\Behat\Notifier:
      notifiers:
        Marcelovani\Behat\Notifier\Dashboard\DashboardNotifier:
          endpoint: 'https://www.foo.bar'

```

Extending
-------------

It is possible to extend this class by implementing your own class and listing it
on the `notifiers` list instead of the default class.

Todo
-------------
- Use Guzzle instead of php curl
- Add example Features and Unit tests
- Add Github actions
- List package on https://packagist.org/
