const defaults = require( '@wordpress/scripts/config/webpack.config' );

// With --experimental-modules, wp-scripts 37 exports an ARRAY of two configs:
// [0] classic script build (block editor scripts + admin), [1] script-module
// build (view.js modules for the Interactivity API). Only WordPress scripts
// (editor/admin) may live in [0]; module entries in [1] may import ONLY
// script modules. The admin React screen is a classic script -> config [0].
const adminEntry = { 'admin/index': './src/admin/index.tsx' };

const configs = ( Array.isArray( defaults ) ? defaults : [ defaults ] ).map(
	( base, index ) => {
		const baseEntry =
			typeof base.entry === 'function' ? base.entry() : base.entry;
		const extra = index === 0 ? adminEntry : {};
		return {
			...base,
			entry: {
				...baseEntry,
				...extra,
			},
		};
	}
);

module.exports = Array.isArray( defaults ) ? configs : configs[ 0 ];
