const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/browser',
	fullyParallel: false,
	reporter: 'line',
	use: {
		baseURL: 'https://example.test',
	},
	projects: [
		{
			name: 'desktop',
			use: { ...devices['Desktop Chrome'] },
		},
		{
			name: 'mobile',
			use: { ...devices['Pixel 7'] },
		},
		{
			name: 'mobile-safari',
			testMatch: /consent\.spec\.js/,
			use: { ...devices['iPhone 13'] },
		},
	],
} );
