( function () {
	function init( $content ) {
		$content.find( '.ext-phonos' ).each( function () {
			const $span = $( this );
			// On first click, add the audio player.
			$span.one( 'click', function () {
				( new mw.Api() ).get( {
					action: 'phonos',
					ipa: $span.data( 'phonos-ipa' ),
					text: $span.data( 'phonos-text' ),
					lang: $span.data( 'phonos-lang' )
				} ).done( function ( response ) {
					// @TODO Make proper UI. For now, just append an audio player.
					const $audio = $( '<audio>' );
					$audio.attr( 'src', 'data:audio/wav;base64,' + response.phonos.audioData );
					$audio.prop( 'controls', true );
					$audio.prop( 'autoplay', true );
					$span.after( $audio );
				} );
			} );
		} );
	}

	mw.hook( 'wikipage.content' ).add( init );
}() );
