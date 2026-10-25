/** @type {import('jest').Config} */
module.exports = {
	testEnvironment: 'jsdom',
	roots: [ '<rootDir>/tests/jest' ],
	transform: {
		'^.+\\.[jt]sx?$': [
			'babel-jest',
			{ presets: [ [ '@babel/preset-env', { targets: { node: 'current' } } ], [ '@babel/preset-react', { runtime: 'automatic' } ], [ '@babel/preset-typescript' ] ] },
		],
	},
	moduleNameMapper: {
		'\\.(css|scss)$': '<rootDir>/tests/jest/__mocks__/style-mock.js',
	},
};
