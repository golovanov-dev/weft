/**
 * Progressive-enhancement contact form: posts via fetch, asks for JSON, shows inline status, marks
 * the fields the server rejected, and (when enabled) fetches a fresh reCAPTCHA v3 token right
 * before submit. Without JS the form is a normal POST and the server redirects back to the page.
 */
export function initContactForm() {
  const form = document.querySelector('.contact-form');
  if (!form) return;

  const status = form.querySelector('.form-status');
  const siteKey = document.body.dataset.recaptchaSitekey || '';
  const useRecaptcha = form.dataset.recaptcha === '1' && siteKey !== '';
  const action = form.dataset.recaptchaAction || 'submit';

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setStatus('');
    markInvalid([]);

    if (useRecaptcha && window.grecaptcha) {
      try {
        const token = await new Promise((resolve, reject) => {
          window.grecaptcha.ready(() => {
            window.grecaptcha.execute(siteKey, { action }).then(resolve, reject);
          });
        });
        form.querySelector('input[name="recaptcha_token"]').value = token;
      } catch {
        setStatus('Captcha failed to load. Please retry.', 'error');
        return;
      }
    }

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: new FormData(form),
      });
      const data = await res.json().catch(() => ({}));

      if (res.ok && data.success) {
        form.reset();
        setStatus(data.message || 'Sent.', 'success');
      } else {
        const errors = Array.isArray(data.errors) ? data.errors : [];
        markInvalid(errors);
        const text = [data.error, ...errors.map((e) => e.text)].filter(Boolean).join(' ');
        setStatus(text || 'Something went wrong. Please try again.', 'error');
      }
    } catch {
      setStatus('Network error. Please try again.', 'error');
    } finally {
      button.disabled = false;
    }
  });

  function markInvalid(errors) {
    form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    errors.forEach(({ name }) => form.elements.namedItem(name)?.setAttribute('aria-invalid', 'true'));
    if (errors.length) form.elements.namedItem(errors[0].name)?.focus();
  }

  function setStatus(text, kind = '') {
    if (!status) return;
    status.textContent = text;
    status.className = 'form-status' + (kind ? ' is-' + kind : '');
  }
}
