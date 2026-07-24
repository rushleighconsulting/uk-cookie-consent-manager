( function () {
	'use strict';

	var editor = document.querySelector( '[data-uccm-rule-editor]' );
	var config = window.UCCMBlockingEditor || {};

	if ( ! editor ) {
		return;
	}

	var form = editor.closest( 'form' );
	var list = editor.querySelector( '[data-uccm-rule-list]' );
	var template = editor.querySelector( '[data-uccm-rule-template]' );
	var addButton = editor.querySelector( '[data-uccm-add-rule]' );
	var emptyMessage = editor.querySelector( '[data-uccm-empty]' );
	var jsonView = editor.querySelector( '[data-uccm-rules-json]' );
	var nextIndex = Date.now();

	function field( rule, name ) {
		return rule.querySelector( '[data-uccm-field="' + name + '"]' );
	}

	function cleanId( value ) {
		return String( value || '' ).trim().toLowerCase().replace( /[^a-z0-9_-]/g, '' );
	}

	function updateLegend( rule ) {
		var title = field( rule, 'title' ).value.trim();
		var id = field( rule, 'id' ).value.trim();
		rule.querySelector( '[data-uccm-rule-legend]' ).textContent =
			title || id || config.newRule || 'New rule';
	}

	function updateResourceFields( rule ) {
		var type = field( rule, 'type' ).value;
		var handle = field( rule, 'handle' );
		handle.disabled = 'script' !== type;
		handle.closest( 'p' ).hidden = 'script' !== type;
	}

	function rulesObject() {
		var rules = {};

		list.querySelectorAll( '[data-uccm-rule]' ).forEach( function ( rule ) {
			var id = cleanId( field( rule, 'id' ).value );

			if ( ! id ) {
				return;
			}

			rules[ id ] = {
				type: field( rule, 'type' ).value,
				category: field( rule, 'category' ).value,
				handle: field( rule, 'handle' ).disabled ? '' : field( rule, 'handle' ).value.trim(),
				source: field( rule, 'source' ).value.trim(),
				title: field( rule, 'title' ).value.trim()
			};
		} );

		return rules;
	}

	function sync() {
		list.querySelectorAll( '[data-uccm-rule]' ).forEach( function ( rule ) {
			updateLegend( rule );
			updateResourceFields( rule );
		} );
		emptyMessage.hidden = 0 < list.querySelectorAll( '[data-uccm-rule]' ).length;
		jsonView.value = JSON.stringify( rulesObject(), null, 2 );
	}

	function addRule() {
		var index = String( nextIndex++ );
		var html = template.innerHTML.split( '__INDEX__' ).join( index );
		var holder = document.createElement( 'div' );
		holder.innerHTML = html.trim();
		var rule = holder.firstElementChild;
		list.appendChild( rule );
		sync();
		field( rule, 'id' ).focus();
	}

	function validate() {
		var valid = true;
		var ids = {};

		list.querySelectorAll( '[data-uccm-rule]' ).forEach( function ( rule ) {
			var idField = field( rule, 'id' );
			var typeField = field( rule, 'type' );
			var handleField = field( rule, 'handle' );
			var sourceField = field( rule, 'source' );
			var id = cleanId( idField.value );
			var source = sourceField.value.trim();

			idField.setCustomValidity( '' );
			handleField.setCustomValidity( '' );
			sourceField.setCustomValidity( '' );

			if ( id && ids[ id ] ) {
				idField.setCustomValidity( config.duplicateId || 'Each Rule ID must be unique.' );
				valid = false;
			}
			ids[ id ] = true;

			if ( 'script' === typeField.value && ! handleField.value.trim() && ! source ) {
				handleField.setCustomValidity( config.handleOrSource || 'Enter a WordPress handle or an HTTPS source.' );
				valid = false;
			}

			if ( 'script' !== typeField.value && ! source ) {
				sourceField.setCustomValidity( config.httpsSource || 'Enter a complete HTTPS source.' );
				valid = false;
			}

			if ( source ) {
				try {
					if ( 'https:' !== new URL( source ).protocol ) {
						throw new Error( 'insecure' );
					}
				} catch ( error ) {
					sourceField.setCustomValidity( config.httpsSource || 'Enter a complete HTTPS source.' );
					valid = false;
				}
			}
		} );

		return valid && form.checkValidity();
	}

	addButton.addEventListener( 'click', addRule );

	list.addEventListener( 'click', function ( event ) {
		var remove = event.target.closest( '[data-uccm-remove-rule]' );

		if ( remove ) {
			remove.closest( '[data-uccm-rule]' ).remove();
			sync();
		}
	} );

	list.addEventListener( 'input', sync );
	list.addEventListener( 'change', sync );

	form.addEventListener( 'submit', function ( event ) {
		sync();

		if ( ! validate() ) {
			event.preventDefault();
			form.reportValidity();
		}
	} );

	sync();
}() );
