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

  // ============ Floating Sign In Panel ============
  const signInTrigger = document.getElementById('signInTrigger');
  const signinPanel = document.getElementById('signinPanel');
  const signinBackdrop = document.getElementById('signinBackdrop');
  const signinClose = document.getElementById('signinClose');
  const passwordToggle = document.getElementById('passwordToggle');
  const signinPassword = document.getElementById('signinPassword');
  const signinForm = document.getElementById('signinForm');

  const openSignin = (e) => {
    if (e) e.preventDefault();
    signinPanel.classList.add('active');
    signinBackdrop.classList.add('active');
    // Focus the email field for keyboard users, once the panel is visible.
    setTimeout(() => {
      const emailField = document.getElementById('signinEmail');
      if (emailField) emailField.focus();
    }, 250);
  };

  const closeSignin = () => {
    signinPanel.classList.remove('active');
    signinBackdrop.classList.remove('active');
  };

  if (signInTrigger) signInTrigger.addEventListener('click', openSignin);
  if (signinClose) signinClose.addEventListener('click', closeSignin);
  if (signinBackdrop) signinBackdrop.addEventListener('click', closeSignin);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && signinPanel.classList.contains('active')) {
      closeSignin();
    }
  });

  // Show / hide password
  if (passwordToggle && signinPassword) {
    passwordToggle.addEventListener('click', () => {
      const isHidden = signinPassword.type === 'password';
      signinPassword.type = isHidden ? 'text' : 'password';
      passwordToggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  }

  // Prevent an actual page submit for this demo form
  if (signinForm) {
    signinForm.addEventListener('submit', (e) => {
      e.preventDefault();
      // Hook up real authentication here.
    });
  }
});