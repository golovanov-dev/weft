import './styles/main.css';
import { initContactForm } from './contact.js';

// Entry point bundled by Vite. Keep JS minimal — the site is content-first.
document.addEventListener('DOMContentLoaded', () => {
  initContactForm();
});
