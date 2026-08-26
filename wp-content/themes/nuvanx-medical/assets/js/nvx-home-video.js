(function () {
  'use strict';

  function startHomeVideo() {
    var video = document.getElementById('nvx-home-hero-video');
    if (!video) return;

    // A11y guard: respect user preferences for reduced motion
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) {
      var wrapper = video.closest('.nvx-home-video-frame');
      if (wrapper) wrapper.classList.add('is-video-poster');
      video.pause();
      return;
    }

    video.muted = true;
    video.playsInline = true;
    video.setAttribute('muted', '');
    video.setAttribute('playsinline', '');

    var frame = video.closest('.nvx-home-video-frame');
    if (frame) frame.classList.add('is-video-mounted');

    function tryPlay() {
      var p = video.play();
      if (p && typeof p.catch === 'function') {
        p.catch(function () {
          if (frame) frame.classList.add('is-video-poster');
        });
      }
    }

    function initVideo() {
      if (video.readyState >= 2) {
        tryPlay();
      } else {
        video.addEventListener('loadeddata', tryPlay, { once: true });
        video.load();
      }
    }

    if (typeof IntersectionObserver === 'function') {
      var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (entry.isIntersecting) {
            initVideo();
          } else {
            video.pause();
          }
        });
      }, { rootMargin: '200px' });
      observer.observe(video);
    } else {
      initVideo();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startHomeVideo);
  } else {
    startHomeVideo();
  }
})();
