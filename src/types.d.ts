/**
 * tsconfig `paths` companion: the block-editor package (19.0.0) ships
 * complete build-types/ declarations but its package.json exports map lacks
 * a "types" condition, so moduleResolution "bundler" resolves the .mjs entry
 * and every import degrades to implicit any. Mapping the bare specifier in
 * tsconfig.json makes TS load the package's own published declarations —
 * no shadow API surface maintained here. (Kept as a .d.ts so it lives with
 * the sources it unblocks; the mapping itself is in tsconfig.json.)
 */

/**
 * Ambient declaration for the WordPress CORE script-module external
 * `@wordpress/interactivity-router`. It is never an npm dependency here —
 * dependency-extraction resolves the bare specifier to the core script
 * module (webpack external, verified in the build output). The module's
 * only public surface this plugin consumes is `actions.navigate()`: the
 * core client-side navigation contract (fetches the URL, re-renders every
 * `data-wp-router-region` found in the response). Arrow-property style per
 * the project ESLint rules.
 */
declare module '@wordpress/interactivity-router' {
	export const actions: {
		navigate: ( url: string ) => Promise< void >;
	};
}
