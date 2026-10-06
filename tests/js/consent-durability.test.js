/**
 * Node self-test for universal consent durability pure logic.
 * Run: node tests/js/consent-durability.test.js
 *
 * Mirrors shouldReprompt / lifetime clamp / rehydrate rules used by public/js/consent.js
 * (kept small and dependency-free so CI / local can prove the contract without a browser).
 */

'use strict';

var failed = 0;

function assert( cond, msg ) {
	if ( ! cond ) {
		failed += 1;
		console.error( 'FAIL:', msg );
	} else {
		console.log( 'ok:', msg );
	}
}

function clampLifetimeSeconds( cookieLifetime ) {
	var maxAge = parseInt( cookieLifetime, 10 );
	if ( ! maxAge || maxAge < 86400 ) {
		maxAge = 180 * 86400;
	}
	return maxAge;
}

function shouldReprompt( cookie, config ) {
	config = config || {};
	if ( ! cookie ) {
		return true;
	}
	if ( ! cookie.state || cookie.state === 'unknown' ) {
		return true;
	}
	var exp = Number( cookie.expires || 0 );
	if ( exp && exp < Math.floor( Date.now() / 1000 ) ) {
		return true;
	}
	if (
		cookie.policy_version &&
		config.policyVersion &&
		String( cookie.policy_version ) !== String( config.policyVersion )
	) {
		return true;
	}
	if (
		cookie.version &&
		config.consentVersion &&
		String( cookie.version ) !== String( config.consentVersion )
	) {
		return true;
	}
	return false;
}

function isValidConsent( data, config ) {
	return ! shouldReprompt( data, config );
}

function pickFirstValidLayer( layers, config ) {
	for ( var i = 0; i < layers.length; i++ ) {
		if ( isValidConsent( layers[ i ], config ) ) {
			return layers[ i ];
		}
	}
	return null;
}

function rehydrateMissingCookie( layers, config ) {
	var cookie = layers.cookie || null;
	if ( isValidConsent( cookie, config ) ) {
		return { cookie: cookie, wrote: false };
	}
	var found = pickFirstValidLayer(
		[ layers.handoff, layers.localStorage, layers.sessionStorage, layers.idb, layers.bridge ],
		config
	);
	if ( ! found ) {
		return { cookie: null, wrote: false };
	}
	return { cookie: found, wrote: true };
}

// --- lifetime clamp ---
assert( clampLifetimeSeconds( 0 ) === 180 * 86400, 'lifetime 0 floors to 180d' );
assert( clampLifetimeSeconds( null ) === 180 * 86400, 'lifetime null floors to 180d' );
assert( clampLifetimeSeconds( 3600 ) === 180 * 86400, 'lifetime < 1d floors to 180d' );
assert( clampLifetimeSeconds( 90 * 86400 ) === 90 * 86400, 'lifetime 90d preserved' );

// --- validity ---
var now = Math.floor( Date.now() / 1000 );
var good = {
	state: 'accepted_all',
	expires: now + 86400,
	version: '1.0.0',
	policy_version: 'p1',
	categories: { necessary: true, analytics: true },
};
assert( isValidConsent( good, { consentVersion: '1.0.0', policyVersion: 'p1' } ), 'valid consent remembered' );
assert( shouldReprompt( null ), 'null → reprompt' );
assert( shouldReprompt( { state: 'unknown', expires: now + 86400 } ), 'unknown state → reprompt' );
assert(
	shouldReprompt( { state: 'accepted_all', expires: now - 10 } ),
	'expired → reprompt'
);
assert(
	shouldReprompt( good, { consentVersion: '2.0.0', policyVersion: 'p1' } ),
	'consent version mismatch → reprompt'
);
assert(
	shouldReprompt( good, { consentVersion: '1.0.0', policyVersion: 'p2' } ),
	'policy version mismatch → reprompt'
);

// --- rehydrate from backup when cookie missing ---
var fromBackup = rehydrateMissingCookie(
	{
		cookie: null,
		localStorage: good,
		sessionStorage: null,
		idb: null,
		bridge: null,
		handoff: null,
	},
	{ consentVersion: '1.0.0', policyVersion: 'p1' }
);
assert( fromBackup.wrote === true && fromBackup.cookie === good, 'rehydrate from localStorage' );

var fromIdb = rehydrateMissingCookie(
	{
		cookie: null,
		localStorage: null,
		sessionStorage: null,
		idb: good,
		bridge: null,
		handoff: null,
	},
	{ consentVersion: '1.0.0', policyVersion: 'p1' }
);
assert( fromIdb.wrote === true && fromIdb.cookie === good, 'rehydrate from IndexedDB tertiary' );

var fromHandoff = rehydrateMissingCookie(
	{
		cookie: null,
		localStorage: null,
		sessionStorage: null,
		idb: null,
		bridge: null,
		handoff: good,
	},
	{ consentVersion: '1.0.0', policyVersion: 'p1' }
);
assert( fromHandoff.wrote === true && fromHandoff.cookie === good, 'rehydrate from URL handoff first' );

var cookieWins = rehydrateMissingCookie(
	{
		cookie: good,
		localStorage: { state: 'rejected_all', expires: now + 86400 },
		sessionStorage: null,
		idb: null,
		bridge: null,
		handoff: null,
	},
	{ consentVersion: '1.0.0', policyVersion: 'p1' }
);
assert( cookieWins.wrote === false && cookieWins.cookie === good, 'valid cookie skips rewrite' );

var expiredBackup = rehydrateMissingCookie(
	{
		cookie: null,
		localStorage: { state: 'accepted_all', expires: now - 5 },
		sessionStorage: null,
		idb: null,
		bridge: null,
		handoff: null,
	},
	{ consentVersion: '1.0.0', policyVersion: 'p1' }
);
assert( expiredBackup.cookie === null && expiredBackup.wrote === false, 'expired backup does not rehydrate' );

if ( failed ) {
	console.error( '\n' + failed + ' assertion(s) failed' );
	process.exit( 1 );
}
console.log( '\nAll consent durability assertions passed.' );
process.exit( 0 );
