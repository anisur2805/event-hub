const defaults = require( '@wordpress/scripts/config/webpack.config' );

// With --experimental-modules, wp-scripts 37 exports an ARRAY of two configs:
// [0] classic script build (block editor scripts + admin), [1] script-module
// build (view.js modules for the Interactivity API). Only WordPress scripts
// (editor/admin) may live in [0]; module entries in [1] may import ONLY
// script modules. The admin React screen is a classic script -> config [0].
const adminEntry = { 'admin/index': './src/admin/index.tsx' };

// Shared front-end stylesheet for all Event Hub blocks (one handle, one set
// of rules; WP dedupes when several blocks appear on the same page). Built
// in config [0] so the classic build emits it (the module build only accepts
// script-module imports).
const cssEntry = { 'css/event-hub': './src/styles/blocks.scss' };

const configs = ( Array.isArray( defaults ) ? defaults : [ defaults ] ).map(
	( base, index ) => {
		const baseEntry =
			typeof base.entry === 'function' ? base.entry() : base.entry;
		const extra = index === 0 ? { ...adminEntry, ...cssEntry } : {};
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
