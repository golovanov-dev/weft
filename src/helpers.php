<?php

declare(strict_types=1);

/**
 * Global template helpers.
 *
 * Thin wrappers over the engine's static services so views stay readable:
 *   <?= e($value) ?>              escape for HTML output
 *   <?= t('home.title') ?>        translate
 *   <a href="<?= url('/about') ?>">   locale-aware link
 *   <?= seo() ?>                  render the full <head> SEO block
 */

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Security\Csrf;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\Support\Sanitize;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return Sanitize::output($value);
    }
}

if (!function_exists('t')) {
    /** @param array<string, scalar> $vars */
    function t(string $key, array $vars = []): string
    {
        return I18n::t($key, $vars);
    }
}

if (!function_exists('url')) {
    function url(string $path = '/', ?string $locale = null): string
    {
        return Url::link($path, $locale);
    }
}

if (!function_exists('asset')) {
    function asset(string $path = '/'): string
    {
        return Url::asset($path);
    }
}

if (!function_exists('api_url')) {
    function api_url(string $path = '/'): string
    {
        return Url::api($path);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('locale')) {
    function locale(): string
    {
        return Locale::current();
    }
}

if (!function_exists('seo')) {
    /** Render the SEO <head> block, optionally setting page values first. */
    function seo(array $attrs = []): string
    {
        if ($attrs !== []) {
            Seo::set($attrs);
        }
        return Seo::render();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('is_active')) {
    function is_active(string $route, string $class = 'active'): string
    {
        return Url::isActive($route) ? $class : '';
    }
}
