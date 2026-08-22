(function () {
  'use strict';

  /* --- Hamburger / nav móvil --- */
  var ham = document.getElementById('nvx-hamburger-btn');
  var mobileNav = document.getElementById('nvx-mobile-nav');
  var closeBtn = document.getElementById('nvx-mobile-close');

  if (mobileNav && mobileNav instanceof HTMLDialogElement) {
    function openNav() {
      if (typeof mobileNav.showModal === 'function') {
        try { if (!mobileNav.open) mobileNav.showModal(); } catch (_e) { mobileNav.setAttribute('open', 'open'); }
      } else {
        mobileNav.setAttribute('open', 'open');
      }
      if (ham) {
        ham.setAttribute('aria-expanded', 'true');
        ham.setAttribute('aria-label', 'Cerrar menú');
      }
      document.body.style.overflow = 'hidden';
    }

    function closeNav() {
      if (typeof mobileNav.close === 'function') {
        mobileNav.close();
      } else {
        mobileNav.removeAttribute('open');
      }
      if (ham) {
        ham.setAttribute('aria-expanded', 'false');
        ham.setAttribute('aria-label', 'Abrir menú');
      }
      if (!document.body.classList.contains('nvx-valoracion-modal-open')) {
        document.body.style.overflow = '';
      }
    }

    if (ham) {
      ham.addEventListener('click', openNav);
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', closeNav);
    }
    mobileNav.addEventListener('cancel', closeNav);
  }

  /* --- Mobile nav accordion for submenus --- */
  function bindMobileNavAccordion() {
    var mobileNavList = document.querySelector('.nvx-mobile-nav__list');
    if (!mobileNavList) return;
    var parentItems = mobileNavList.querySelectorAll('.menu-item-has-children');
    parentItems.forEach(function (item) {
      if (item.querySelector('.nvx-mobile-nav__toggle')) return;
      var subMenu = item.querySelector('.sub-menu');
      if (!item.querySelector('a') || !subMenu) return;

      var toggle = document.createElement('button');
      toggle.className = 'nvx-mobile-nav__toggle';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Expandir submenú');
      toggle.setAttribute('type', 'button');
      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', !isExpanded);
        toggle.setAttribute('aria-label', isExpanded ? 'Expandir submenú' : 'Contraer submenú');
        subMenu.classList.toggle('is-expanded', !isExpanded);
      });
      item.appendChild(toggle);
    });
  }
  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(bindMobileNavAccordion, { timeout: 2000 });
  } else {
    window.setTimeout(bindMobileNavAccordion, 1);
  }

  /* FAQ: native <details>/<summary> (.nvx-faq / .nvx-brand-faq-*) — no JS. */

  /* --- Smooth scroll en anclas --- */
  var prefersReducedMotion = window.matchMedia(
    '(prefers-reduced-motion: reduce)'
  ).matches;

  var heroVideo = document.getElementById('nvx-home-hero-video');
  if (heroVideo && prefersReducedMotion) {
    heroVideo.pause();
    heroVideo.removeAttribute('autoplay');
  }

  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var href = a.getAttribute('href');
      if (!href || href === '#') return;
      var targetId = href.slice(1);
      var target = document.getElementById(targetId);
      if (target) {
        e.preventDefault();
        if (prefersReducedMotion) {
          target.scrollIntoView();
        } else {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        // Move keyboard/AT focus to the target (critical for the skip-link:
        // preventDefault() above stops the browser's native fragment-focus
        // behavior, so it must be restored manually here).
        if (typeof target.focus === 'function') {
          target.focus({ preventScroll: true });
        }
      }
    });
  });

  /* --- Google Maps: load iframe only after an explicit click --- */
  function bindLazyMaps(root) {
    var nodes = (root || document).querySelectorAll('[data-nvx-map-src]');
    nodes.forEach(function (el) {
      if (el.dataset.nvxMapBound === '1') return;
      el.dataset.nvxMapBound = '1';
      var button = el.querySelector('.nvx-map-embed__button');
      if (!button) return;
      button.addEventListener('click', function () {
        var src = el.dataset.nvxMapSrc || '';
        var title = el.dataset.nvxMapTitle || 'Google Maps';
        if (!src) return;
        var iframe = document.createElement('iframe');
        iframe.src = src; // NOSONAR - server-rendered attribute set by theme templates; not derived from user input
        iframe.title = title;
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
        el.replaceChildren(iframe);
      });
    });
  }
  bindLazyMaps(document);

  /* --- Complianz Accessible Name Sanitizer (WCAG 2.4.4 / 4.1.2) --- */
  function sanitizeComplianzAccessibleNames() {
    var banners = document.querySelectorAll(
      '.cmplz-cookiebanner, #cmplz-cookiebanner-container, .cmplz-banner, .cmplz-manage-consent-container'
    );
    if (!banners || banners.length === 0) return;

    banners.forEach(function (banner) {
      var links = banner.querySelectorAll('a, button, [role="button"]');
      links.forEach(function (el) {
        var text = (el.textContent || '').trim();
        var ariaLabel = el.getAttribute('aria-label') || '';
        var href = el.getAttribute('href') || '';

        if (
          text === '{title}' ||
          ariaLabel === '{title}' ||
          text.indexOf('{title}') !== -1 ||
          ariaLabel.indexOf('{title}') !== -1
        ) {
          var fallback = 'Política de cookies';
          if (href.indexOf('privacidad') !== -1 || href.indexOf('privacy') !== -1) {
            fallback = 'Política de privacidad';
          } else if (href.indexOf('aviso-legal') !== -1 || href.indexOf('legal') !== -1) {
            fallback = 'Aviso legal';
          }

          if (text === '{title}' || text.indexOf('{title}') !== -1) {
            el.textContent = text.replace(/\{title\}/g, fallback);
          }
          if (ariaLabel === '{title}' || ariaLabel.indexOf('{title}') !== -1) {
            el.setAttribute('aria-label', ariaLabel.replace(/\{title\}/g, fallback));
          }
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sanitizeComplianzAccessibleNames);
  } else {
    sanitizeComplianzAccessibleNames();
  }

  function watchComplianzBanner() {
    sanitizeComplianzAccessibleNames();
    if (typeof MutationObserver !== 'function') return;
    var root = document.querySelector(
      '#cmplz-cookiebanner-container, .cmplz-cookiebanner, .cmplz-manage-consent-container'
    );
    if (!root) return;
    var complianzObserver = new MutationObserver(function () {
      sanitizeComplianzAccessibleNames();
    });
    complianzObserver.observe(root, { childList: true, subtree: true });
  }
  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(watchComplianzBanner, { timeout: 3000 });
  } else {
    window.addEventListener('load', function () {
      window.setTimeout(watchComplianzBanner, 1);
    });
  }
})();
