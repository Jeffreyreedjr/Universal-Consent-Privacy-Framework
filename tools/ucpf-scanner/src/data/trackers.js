/**
 * Known third-party tracker host fragments (technical tagging only).
 * Inspired by common GDPR scanner tracker lists — not exhaustive.
 */

export const TRACKER_HOST_HINTS = [
  { match: 'google-analytics.com', provider: 'Google Analytics', category: 'analytics' },
  { match: 'googletagmanager.com', provider: 'Google Tag Manager', category: 'analytics' },
  { match: 'googleadservices.com', provider: 'Google Ads', category: 'marketing' },
  { match: 'doubleclick.net', provider: 'Google Ads', category: 'marketing' },
  { match: 'facebook.net', provider: 'Meta Pixel', category: 'marketing' },
  { match: 'facebook.com', provider: 'Meta', category: 'marketing' },
  { match: 'connect.facebook', provider: 'Meta Pixel', category: 'marketing' },
  { match: 'hotjar.com', provider: 'Hotjar', category: 'analytics' },
  { match: 'clarity.ms', provider: 'Microsoft Clarity', category: 'analytics' },
  { match: 'linkedin.com', provider: 'LinkedIn Insight', category: 'marketing' },
  { match: 'licdn.com', provider: 'LinkedIn', category: 'marketing' },
  { match: 'tiktok.com', provider: 'TikTok Pixel', category: 'marketing' },
  { match: 'snapchat.com', provider: 'Snap Pixel', category: 'marketing' },
  { match: 'segment.com', provider: 'Segment', category: 'analytics' },
  { match: 'segment.io', provider: 'Segment', category: 'analytics' },
  { match: 'mixpanel.com', provider: 'Mixpanel', category: 'analytics' },
  { match: 'amplitude.com', provider: 'Amplitude', category: 'analytics' },
  { match: 'hubspot.com', provider: 'HubSpot', category: 'marketing' },
  { match: 'hs-scripts.com', provider: 'HubSpot', category: 'marketing' },
  { match: 'hs-analytics', provider: 'HubSpot', category: 'marketing' },
  { match: 'klaviyo.com', provider: 'Klaviyo', category: 'marketing' },
  { match: 'mailchimp.com', provider: 'Mailchimp', category: 'marketing' },
  { match: 'list-manage.com', provider: 'Mailchimp', category: 'marketing' },
  { match: 'cloudflareinsights.com', provider: 'Cloudflare Web Analytics', category: 'analytics' },
  { match: 'hcaptcha.com', provider: 'hCaptcha', category: 'security' },
  { match: 'challenges.cloudflare.com', provider: 'Cloudflare Turnstile', category: 'necessary' },
  { match: 'fonts.googleapis.com', provider: 'Google Fonts', category: 'preferences' },
  { match: 'fonts.gstatic.com', provider: 'Google Fonts', category: 'preferences' },
  { match: 'typekit.net', provider: 'Adobe Fonts', category: 'preferences' },
  { match: 'userway.org', provider: 'UserWay', category: 'preferences' },
  { match: 'jotform.com', provider: 'Jotform', category: 'preferences' },
  { match: 'jotfor.ms', provider: 'Jotform', category: 'preferences' },
  { match: 'newrelic.com', provider: 'New Relic', category: 'analytics' },
  { match: 'nr-data.net', provider: 'New Relic', category: 'analytics' },
  { match: 'sentry.io', provider: 'Sentry', category: 'analytics' },
  { match: 'youtube.com', provider: 'YouTube', category: 'preferences' },
  { match: 'ytimg.com', provider: 'YouTube', category: 'preferences' },
  { match: 'vimeo.com', provider: 'Vimeo', category: 'preferences' },
  { match: 'twitter.com', provider: 'X / Twitter', category: 'marketing' },
  { match: 'ads-twitter.com', provider: 'X Ads', category: 'marketing' },
  { match: 'bing.com', provider: 'Microsoft Advertising', category: 'marketing' },
  { match: 'bat.bing.com', provider: 'Microsoft UET', category: 'marketing' },
  { match: 'pinimg.com', provider: 'Pinterest', category: 'marketing' },
  { match: 'pinterest.com', provider: 'Pinterest', category: 'marketing' },
  { match: 'criteo.com', provider: 'Criteo', category: 'marketing' },
  { match: 'taboola.com', provider: 'Taboola', category: 'marketing' },
  { match: 'outbrain.com', provider: 'Outbrain', category: 'marketing' },
];

/**
 * @param {string} hostOrUrl
 * @returns {{ provider: string, category: string }|null}
 */
export function matchTrackerHost(hostOrUrl) {
  const raw = String(hostOrUrl || '').toLowerCase();
  if (!raw) return null;
  for (const row of TRACKER_HOST_HINTS) {
    if (raw.includes(row.match)) {
      return { provider: row.provider, category: row.category };
    }
  }
  return null;
}
