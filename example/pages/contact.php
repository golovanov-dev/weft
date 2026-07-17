<?php

use Golovanov\Weft\Security\Captcha;
use Golovanov\Weft\Security\Csrf;
use Golovanov\Weft\Seo\Seo;

Seo::set([
    'title' => t('contact.meta.title'),
    'description' => t('contact.meta.description'),
]);

$types = config('app.contact.request_types', []);
?>

<section class="page-hero">
  <div class="wrap">
    <p class="eyebrow"><?= t('contact.eyebrow') ?></p>
    <h1><?= t('contact.title') ?></h1>
    <p class="lead"><?= t('contact.lead') ?></p>
  </div>
</section>

<section class="contact">
  <div class="wrap wrap-narrow">
    <form class="contact-form" method="post" action="<?= e(api_url('contact')) ?>"
          data-recaptcha-action="contact"<?= Captcha::enabled() ? ' data-recaptcha="1"' : '' ?>>
      <?= Csrf::field() ?>

      <!-- Honeypot: bots fill it, humans never see it. Server treats a filled value as spam. -->
      <div class="hp" aria-hidden="true">
        <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <div class="field">
        <label for="name"><?= t('contact.form.name') ?> *</label>
        <input id="name" name="name" required maxlength="120">
      </div>

      <div class="field">
        <label for="email"><?= t('contact.form.email') ?> *</label>
        <input id="email" name="email" type="email" required maxlength="160">
      </div>

      <div class="field">
        <label for="type"><?= t('contact.form.type') ?> *</label>
        <select id="type" name="type" required>
          <?php foreach ($types as $type): ?>
            <option value="<?= e($type) ?>"><?= t('contact.types.' . $type) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="message"><?= t('contact.form.message') ?> *</label>
        <textarea id="message" name="message" rows="6" required maxlength="4000"></textarea>
      </div>

      <div class="field">
        <label for="project_url"><?= t('contact.form.project_url') ?></label>
        <input id="project_url" name="project_url" type="url" maxlength="300">
      </div>

      <input type="hidden" name="recaptcha_token" value="">
      <button class="btn btn-primary" type="submit"><?= t('contact.form.submit') ?></button>
      <p class="form-status" role="status" aria-live="polite"></p>
    </form>
  </div>
</section>
