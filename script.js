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

  // ============ Floating Sign Up Panel ============
  const signUpTrigger = document.getElementById('signUpTrigger');
  const signupPanel = document.getElementById('signupPanel');
  const signupBackdrop = document.getElementById('signupBackdrop');
  const signupClose = document.getElementById('signupClose');
  const signupPasswordToggle = document.getElementById('signupPasswordToggle');
  const signupPassword = document.getElementById('signupPassword');
  const signupForm = document.getElementById('signupForm');
  const switchToSignup = document.getElementById('switchToSignup');
  const switchToSignin = document.getElementById('switchToSignin');

  const openSignup = (e) => {
    if (e) e.preventDefault();
    signupPanel.classList.add('active');
    signupBackdrop.classList.add('active');
    setTimeout(() => {
      const firstNameField = document.getElementById('signupFirstName');
      if (firstNameField) firstNameField.focus();
    }, 250);
  };

  const closeSignup = () => {
    signupPanel.classList.remove('active');
    signupBackdrop.classList.remove('active');
  };

  if (signUpTrigger) signUpTrigger.addEventListener('click', openSignup);
  if (signupClose) signupClose.addEventListener('click', closeSignup);
  if (signupBackdrop) signupBackdrop.addEventListener('click', closeSignup);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && signupPanel.classList.contains('active')) {
      closeSignup();
    }
  });

  // Show / hide password (Sign Up form)
  if (signupPasswordToggle && signupPassword) {
    signupPasswordToggle.addEventListener('click', () => {
      const isHidden = signupPassword.type === 'password';
      signupPassword.type = isHidden ? 'text' : 'password';
      signupPasswordToggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  }

  // Prevent an actual page submit for this demo form
  if (signupForm) {
    signupForm.addEventListener('submit', (e) => {
      e.preventDefault();
      // Hook up real account-creation logic here.
    });
  }

  // Swap between the two panels without closing-then-reopening awkwardly
  if (switchToSignup) {
    switchToSignup.addEventListener('click', (e) => {
      e.preventDefault();
      closeSignin();
      openSignup();
    });
  }

  if (switchToSignin) {
    switchToSignin.addEventListener('click', (e) => {
      e.preventDefault();
      closeSignup();
      openSignin();
    });
  }
});