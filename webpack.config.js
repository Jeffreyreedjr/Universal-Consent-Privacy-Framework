const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * Bundle React into admin/build so we do not depend on wp.element handles.
 */
module.exports = {
	...defaultConfig,
	externals: {},
};
