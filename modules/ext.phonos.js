( () => {
	const PhonosButton = require( './PhonosButton.js' );

	function init( $content ) {
		$content.find( '.ext-phonos' ).each( function () {
			const $span = $( this );
			const button = new PhonosButton( {
				ipa: $span.data( 'phonos-ipa' ),
				text: $span.data( 'phonos-text' ),
				lang: $span.data( 'phonos-lang' ),
				file: $span.data( 'phonos-file' ),
				errorMsg: $span.data( 'phonos-error' ),
				src: $span.data( 'phonos-src' )
			} );
			$span.replaceWith( button.$element );
		} );
	}

	mw.hook( 'wikipage.content' ).add( init );
} )();
