(() => {
	'use strict';

	const editor = document.querySelector( '[data-uccm-banner-editor]' );
	const preview = document.querySelector( '[data-uccm-banner-preview]' );

	if ( ! editor || ! preview ) {
		return;
	}

	const properties = {
		banner_surface_color: '--uccm-preview-surface',
		banner_text_color: '--uccm-preview-ink',
		banner_muted_color: '--uccm-preview-muted',
		banner_button_color: '--uccm-preview-accent',
		banner_button_text_color: '--uccm-preview-button-text',
	};

	function updatePreview( field ) {
		const name = field.dataset.uccmStyleField;

		if ( properties[ name ] ) {
			preview.style.setProperty( properties[ name ], field.value );

			const value = field.parentElement.querySelector( 'code' );

			if ( value ) {
				value.textContent = field.value;
			}
			return;
		}

		if ( 'banner_corner_radius' === field.name.replace( /^uccm\[|\]$/g, '' ) ) {
			preview.style.setProperty( '--uccm-preview-radius', `${ field.value }px` );
			return;
		}

		if ( 'banner_font' === name ) {
			preview.dataset.font = field.value;
		} else if ( 'banner_position' === name ) {
			preview.dataset.position = field.value;
		} else if ( 'icon_position' === name ) {
			preview.dataset.iconPosition = field.value;
		}
	}

	editor.querySelectorAll( '[data-uccm-style-field], input[name="uccm[banner_corner_radius]"]' ).forEach( ( field ) => {
		field.addEventListener( 'input', () => updatePreview( field ) );
		field.addEventListener( 'change', () => updatePreview( field ) );
	} );
})();
