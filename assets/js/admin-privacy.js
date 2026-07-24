( function () {
	'use strict';

	var trustHeaders = document.querySelector( '[data-uccm-trust-proxy-headers]' );
	var proxySettings = document.querySelector( '[data-uccm-trusted-proxies-settings]' );

	if ( ! trustHeaders || ! proxySettings ) {
		return;
	}

	var proxyAddresses = proxySettings.querySelector( 'textarea' );

	if ( ! proxyAddresses ) {
		return;
	}

	function syncProxySettings() {
		var enabled = trustHeaders.checked;

		proxySettings.hidden = ! enabled;
		proxyAddresses.disabled = ! enabled;
		proxyAddresses.setAttribute( 'aria-disabled', enabled ? 'false' : 'true' );
		trustHeaders.setAttribute( 'aria-expanded', enabled ? 'true' : 'false' );
	}

	trustHeaders.addEventListener( 'change', syncProxySettings );
	syncProxySettings();
}() );
