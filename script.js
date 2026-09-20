// ============================================
// RepairSync AI — Landing Page Interactions
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  const navbar = document.getElementById('navbar');
  const navToggle = document.getElementById('navToggle');
  const navLinks = document.querySelector('.nav-links');

  // Floating navbar: stays fixed, just adds a stronger shadow once the
  // page has been scrolled so it visually "lifts" off the content.
  const onScroll = () => {
    if (window.scrollY > 12) {
      navbar.classList.add('is-scrolled');
    } else {
      navbar.classList.remove('is-scrolled');
    }
  };

  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Mobile menu toggle
  if (navToggle) {
    navToggle.addEventListener('click', () => {
      navLinks.classList.toggle('nav-links--open');
    });
  }
});