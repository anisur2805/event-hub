import { defineConfig, devices } from '@playwright/test';

// wp-env serves the test install on localhost:8888 by default.
const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8888';

export default defineConfig( {
	testDir: '.',
	timeout: 30_000,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } },
	],
	webServer: process.env.E2E_NO_SERVER
		? undefined
		: {
				command: '../../node_modules/.bin/wp-env start',
				url: 'http://localhost:8888',
				reuseExistingServer: true,
				timeout: 120_000,
			},
} );
