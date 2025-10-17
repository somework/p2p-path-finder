# Local development without ext-bcmath

Composer enforces the `ext-bcmath` requirement during installation. When the native extension is missing you have two options:

1. Install the extension on your platform before running `composer install`.
2. Temporarily require [`symfony/polyfill-bcmath`](https://github.com/symfony/polyfill-bcmath) to emulate the functions during local development.

The polyfill provides correctness but not the same performance characteristics as the PHP extension. Keep it out of production and CI environments so their behaviour mirrors real-world deployments.
