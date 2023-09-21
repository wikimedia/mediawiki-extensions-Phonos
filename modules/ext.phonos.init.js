( function () {
	'use strict';

	function phonosClickHandler( event ) {
		event.preventDefault();
		mw.loader.using( 'ext.phonos' ).then( function () {
			const buttonElement = event.target.closest( '.ext-phonos-PhonosButton' );
			OO.ui.infuse( buttonElement )
				.emit( 'click' );
			buttonElement.removeEventListener( 'click', phonosClickHandler );
		} );
	}

	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		$content.find( '.ext-phonos-PhonosButton' ).each( function () {
			this.addEventListener( 'click', phonosClickHandler );
		} );
	} );

}() );
