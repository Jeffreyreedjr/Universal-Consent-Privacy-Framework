# QA Checklist

Default theme: **classic**. Local-first; remote registry **off**.

## Banner (public)

- [ ] Banner shows for new visitor (**classic** + Plus Jakarta Sans)
- [ ] Reject All and Accept All same visual tier (`.ucpf-btn--primary-tier`)
- [ ] ESC rejects optional cookies
- [ ] Logo appears when Logo URL is set
- [ ] Powered-by respects toggle
- [ ] `:focus-visible` rings on buttons, toggles, FAB
- [ ] Hover / active states distinct from default
- [ ] `prefers-reduced-motion: reduce` disables GSAP entrance
- [ ] Accept/Reject still works if GSAP fails to load (boot + consent.js)

## Admin shell

- [ ] All UCPF screens show dark sidebar nav with current page `aria-current`
- [ ] Keyboard: Tab reaches nav links and primary actions; focus ring visible
- [ ] Dashboard React mount loads (or “Loading…” then content)
- [ ] Health list statuses announced (badge `aria-label`)
- [ ] Scanner chips use `aria-pressed` + hover/active styles
- [ ] Wizard nav buttons keyboard operable

## Branding / privacy

- [ ] `wp-content/ucpf-brand.php` renames product in shell brand label
- [ ] Remote registry default off
- [ ] No phone-home on fresh install
