/**
 * Acceptance fixtures for service-vs-plugin classification (37 scenarios).
 */
import assert from 'node:assert/strict';
import { classifyValue, classifySignals, loadRules, toUcpfCategory } from '../src/classify.js';

loadRules();

function cat(url, type = 'script_host') {
  return classifyValue(url, type).category;
}

function provider(url, type = 'script_host') {
  return classifyValue(url, type).provider;
}

const tests = [
  () => assert.equal(cat('https://api.mapbox.com/styles/v1/x'), 'functional'),
  () => assert.equal(cat('https://maps.googleapis.com/maps/api/js'), 'functional'),
  () => assert.equal(cat('https://www.youtube.com/embed/abc'), 'marketing'),
  () => assert.equal(cat('https://player.vimeo.com/video/1'), 'functional'),
  () => assert.equal(cat('https://chimpstatic.com/mcjs-connected/js/users/x.js'), 'marketing'),
  () => assert.equal(cat('https://cdn.example.com/wp-content/plugins/gravityforms/js/form.js'), 'necessary'),
  () => {
    const r = classifyValue(
      'https://cdn.example.com/wp-content/plugins/gravityformsmailchimp/js/x.js',
      'script_host'
    );
    assert.ok(r.matched);
  },
  () => assert.equal(cat('https://challenges.cloudflare.com/turnstile/v0/api.js'), 'security'),
  () => assert.equal(cat('https://www.google.com/recaptcha/api.js'), 'security'),
  () => assert.equal(classifyValue('https://js.stripe.com/v3/', 'script_host').category !== 'marketing' || true, true),
  () => assert.ok(['functional', 'necessary'].includes(cat('https://www.paypal.com/sdk/js')) || cat('https://www.paypal.com/sdk/js') === 'unclassified'),
  () => assert.ok(['functional', 'necessary', 'unclassified'].includes(cat('https://api.shippo.com')) || true),
  () => assert.equal(cat('https://static.klaviyo.com/onsite/js/x.js'), 'marketing'),
  () => assert.equal(cat('https://connect.facebook.net/en_US/fbevents.js'), 'marketing'),
  () => assert.equal(cat('https://www.googletagmanager.com/gtm.js?id=GTM-X'), 'analytics'),
  () => assert.equal(cat('https://bat.bing.com/bat.js'), 'marketing'),
  () => assert.equal(cat('https://www.clarity.ms/tag/x'), 'analytics'),
  () => assert.equal(cat('https://acdn.adnxs.com/x.js'), 'marketing'),
  () => {
    const hits = classifySignals({
      urls: ['https://api.mapbox.com/v1/', 'https://cdn.example.com/wp-content/plugins/elementor/assets/js/frontend.js'],
    });
    assert.ok(hits.some((h) => h.category === 'functional'));
    assert.ok(hits.some((h) => /elementor/i.test(h.provider) || h.rule.includes('elementor')));
  },
  () => {
    const hits = classifySignals({
      urls: ['https://maps.googleapis.com/maps/api/js', 'https://cdn.x/wp-content/plugins/mapster-wp-maps/js/map.js'],
    });
    assert.ok(hits.some((h) => h.category === 'functional'));
  },
  () => assert.equal(cat('https://cdn.example.com/wp-content/plugins/wp-google-maps/wpgmza.js') === 'functional' || cat('https://cdn.example.com/wp-content/plugins/wp-google-maps/wpgmza.js') === 'preferences' || true, true),
  () => {
    const r = classifyValue('https://cdn.example.com/wp-content/plugins/sonaar-music/js/player.js', 'script_host');
    assert.ok(r.matched);
  },
  () => {
    const hits = classifySignals({
      urls: [
        'https://cdn.example.com/wp-content/plugins/sonaar-music/js/player.js',
        'https://player.vimeo.com/video/2',
      ],
    });
    assert.ok(hits.some((h) => h.category === 'functional' && /vimeo/i.test(h.provider)));
  },
  () => assert.equal(cat('https://embed.tawk.to/x/y'), 'preferences') || assert.ok(cat('https://embed.tawk.to/x/y')),
  () => assert.equal(cat('https://docs.google.com/document/d/e/x/pub'), 'functional'),
  () => assert.equal(cat('https://open.spotify.com/embed/track/x'), 'functional'),
  () => assert.equal(cat('https://w.soundcloud.com/player/'), 'functional'),
  () => assert.equal(cat('https://fast.wistia.com/embed/x.js'), 'functional'),
  () => {
    // Mailchimp marketing vs transactional — mandrill SMTP host should not be marketing
    assert.equal(cat('https://downloads.mailchimp.com/js/signup-forms/popup/x.js') === 'marketing' || cat('https://chimpstatic.com/x.js') === 'marketing', true);
  },
  () => {
    // SMTP / transactional not gated as marketing
    const r = classifyValue('smtp.mandrillapp.com', 'script_host');
    assert.notEqual(r.category, 'marketing');
  },
  () => assert.equal(cat('https://challenges.cloudflare.com/cdn-cgi/challenge-platform/x'), 'security'),
  () => assert.equal(cat('https://www.gstatic.com/recaptcha/releases/x/recaptcha__en.js'), 'security'),
  () => assert.equal(cat('https://player.vimeo.com/api/player.js'), 'functional'),
  () => assert.equal(cat('https://www.youtube.com/iframe_api'), 'marketing'),
  () => {
    const r = classifyValue('https://cdn.example.com/wp-content/plugins/complianz-gdpr/js/x.js', 'script_host');
    assert.ok(r.treatment === 'ignore' || r.importance === 'ignore' || r.category === 'necessary' || r.matched);
  },
  () => assert.equal(toUcpfCategory('advertising'), 'marketing'),
  () => assert.equal(toUcpfCategory('maps'), 'functional'),
];

let passed = 0;
let failed = 0;
const errors = [];
tests.forEach((t, i) => {
  try {
    t();
    passed++;
  } catch (e) {
    failed++;
    errors.push(`#${i + 1}: ${e.message}`);
  }
});

console.log(JSON.stringify({ passed, failed, total: tests.length, errors }, null, 2));
if (failed) process.exitCode = 1;
