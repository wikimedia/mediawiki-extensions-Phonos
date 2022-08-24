( () => {

	// Define the class as a global so that OOUI infuse() can access it.
	mw.Phonos = {
		PhonosButton: require( './PhonosButton.js' )
	};

	function init( $content ) {
		$content.find( '.ext-phonos' ).each( function () {
			OO.ui.infuse( $( this ) );
		} );
	}

	mw.hook( 'wikipage.content' ).add( init );
} )();
