/**
 * tsconfig `paths` companion: the block-editor package (19.0.0) ships
 * complete build-types/ declarations but its package.json exports map lacks
 * a "types" condition, so moduleResolution "bundler" resolves the .mjs entry
 * and every import degrades to implicit any. Mapping the bare specifier in
 * tsconfig.json makes TS load the package's own published declarations —
 * no shadow API surface maintained here. (Kept as a .d.ts so it lives with
 * the sources it unblocks; the mapping itself is in tsconfig.json.)
 */
